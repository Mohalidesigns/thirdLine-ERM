<?php

namespace App\Services;

use App\Models\Measure;
use App\Models\Organization;
use App\Models\Period;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-04 TASK 4 — puts a date on a risk score.
 *
 * Until now an approved assessment overwrote the score columns on `risks` and
 * the previous number ceased to exist anywhere a query could reach. The score
 * was therefore always "now", which makes a trend impossible, makes a board
 * pack unreproducible, and makes "was this risk already High when we signed the
 * facility" a question answerable only by reading the audit trail by hand.
 *
 * The denormalised columns on `risks` STAY. They are the current value, they
 * render the register list in one query, and nothing about this class changes
 * how they are written — it adds the period-stamped copy alongside.
 */
class RiskMeasureRecorder
{
    /** The grain assessments are stamped at unless the organisation says otherwise. */
    public const DEFAULT_PERIOD_TYPE = 'quarter';

    public function __construct(
        private MeasureService $measures,
        private PeriodService $periods,
        private RiskScoringService $scoring,
    ) {}

    /**
     * Write every risk measure implied by an approved assessment.
     *
     * @param  array{force?:bool, source?:string}  $options
     * @return int the number of values written
     */
    public function recordAssessment(RiskAssessment $assessment, ?Risk $risk = null, array $options = []): int
    {
        $risk = $risk ?? $assessment->risk;

        if ($risk === null) {
            return 0;
        }

        $organizationId = $risk->organization_id ?? $assessment->organization_id;

        if ($organizationId === null) {
            return 0;
        }

        $period = $this->periodFor($assessment, $organizationId);

        return $this->recordValues($risk, $period, $this->valuesFrom($assessment, $risk), $organizationId, $options);
    }

    /**
     * The period an assessment belongs to.
     *
     * assessment_date, not the approval timestamp: an assessment carried out in
     * March and approved in April is March's picture of the risk, and stamping
     * it into April would put two quarters' assessments in one quarter for any
     * organisation whose review cycle runs past quarter end.
     */
    public function periodFor(RiskAssessment $assessment, int $organizationId): Period
    {
        $date = $assessment->assessment_date
            ? CarbonImmutable::parse($assessment->assessment_date)
            : CarbonImmutable::parse($assessment->created_at ?? now());

        return $this->periods->resolve($date, $this->periodType($organizationId), $organizationId);
    }

    /**
     * The period grain assessments are stamped at, from organisation settings.
     */
    public function periodType(?int $organizationId = null): string
    {
        $organizationId = $organizationId ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return self::DEFAULT_PERIOD_TYPE;
        }

        $settings = Organization::query()->whereKey($organizationId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;
        $configured = is_array($settings) ? ($settings['assessment_period_type'] ?? null) : null;

        return in_array($configured, Period::TYPE_ORDER, true) ? $configured : self::DEFAULT_PERIOD_TYPE;
    }

    /**
     * The nine risk measures, as measure code => value, omitting any the
     * assessment does not carry.
     *
     * Nothing here is invented. A measure with no source number is absent from
     * the return, and therefore absent from the fact table, which is what makes
     * a gap in a trend line honest rather than a zero.
     *
     * @return array<string, array{value: float, currency: ?string}>
     */
    public function valuesFrom(RiskAssessment $assessment, Risk $risk): array
    {
        $values = [];

        $put = function (string $code, mixed $value, ?string $currency = null) use (&$values) {
            if ($value === null || $value === '') {
                return;
            }

            $values[$code] = ['value' => (float) $value, 'currency' => $currency];
        };

        $put(MeasureCatalog::RISK_INHERENT_LIKELIHOOD, $assessment->likelihood_score);
        $put(MeasureCatalog::RISK_INHERENT_IMPACT, $assessment->impact_score);
        $put(MeasureCatalog::RISK_INHERENT_SCORE, $assessment->overall_score);
        $put(MeasureCatalog::RISK_RESIDUAL_LIKELIHOOD, $assessment->residual_likelihood);
        $put(MeasureCatalog::RISK_RESIDUAL_IMPACT, $assessment->residual_impact);
        $put(MeasureCatalog::RISK_RESIDUAL_SCORE, $assessment->residual_score);
        $put(MeasureCatalog::RISK_CONTROL_EFFECTIVENESS, $risk->control_effectiveness_pct);

        // financial_exposure_ngn is held in naira; the measure is in kobo,
        // because money is stored in minor units.
        if ($risk->financial_exposure_ngn !== null) {
            $put(
                MeasureCatalog::RISK_FINANCIAL_EXPOSURE,
                CurrencyService::toMinor((float) $risk->financial_exposure_ngn, 'NGN'),
                'NGN'
            );
        }

        $target = $this->targetScoreFor($risk);

        if ($target !== null) {
            $put(MeasureCatalog::RISK_TARGET_SCORE, $target);
        }

        return $values;
    }

    /**
     * The risk's target score.
     *
     * `risks` carries target_RATING (a label) and no target score column, so
     * an explicit numeric target in the metadata bag is preferred and the label
     * is only used as a fallback — resolved to the LOWEST score that still
     * earns that rating under the organisation's own configured bands, which is
     * the weakest claim the label supports. Returns null when the risk carries
     * neither, rather than inventing a target.
     */
    public function targetScoreFor(Risk $risk): ?float
    {
        $metadata = $risk->metadata ?? [];

        if (isset($metadata['target_score']) && is_numeric($metadata['target_score'])) {
            return (float) $metadata['target_score'];
        }

        if (empty($risk->target_rating)) {
            return null;
        }

        $wanted = strtolower((string) $risk->target_rating);

        // Walk the configured band function rather than hard-coding its
        // boundaries, so an organisation that re-bands its matrix gets the
        // right answer from the same code.
        for ($score = 1; $score <= 25; $score++) {
            if (strtolower($this->scoring->calculateRating($score)) === $wanted) {
                return (float) $score;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{value: float, currency: ?string}>  $values
     */
    private function recordValues(Risk $risk, Period $period, array $values, int $organizationId, array $options): int
    {
        $written = 0;

        foreach ($values as $code => $entry) {
            $measure = $this->measures->measure($code, $organizationId);

            if ($measure === null) {
                // The catalogue is installed by migration and by
                // MeasureCatalog::install(); a missing definition means a
                // tenant provisioned after both. Install on demand rather than
                // dropping the value on the floor.
                $measure = MeasureCatalog::install($organizationId)[$code] ?? null;
            }

            if (! $measure instanceof Measure) {
                Log::warning('WP-04: no measure definition for '.$code, ['organization_id' => $organizationId]);

                continue;
            }

            try {
                $this->measures->record($measure, $risk, $period, $entry['value'], [
                    'organization_id' => $organizationId,
                    'scenario' => 'actual',
                    'currency_code' => $entry['currency'],
                    'source' => $options['source'] ?? 'calculated',
                    'force' => $options['force'] ?? false,
                    // Risk scores have no RAG bands of their own by default —
                    // the register's own rating column is that story — so
                    // breach detection would have nothing to detect.
                    'detect_breach' => false,
                ]);

                $written++;
            } catch (\Throwable $exception) {
                // A closed period, or a risk with no graph identity. Never fail
                // the approval that triggered this: the assessment is the
                // record of account, the measure copy is the index.
                Log::warning('WP-04: could not period-stamp '.$code, [
                    'risk_id' => $risk->id,
                    'period_id' => $period->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $written;
    }
}
