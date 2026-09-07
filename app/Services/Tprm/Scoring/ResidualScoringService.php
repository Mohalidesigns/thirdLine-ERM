<?php

namespace App\Services\Tprm\Scoring;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\ScoreRun;
use App\Models\Tprm\Sla;
use App\Models\Tprm\TierPolicy;
use App\Services\Tprm\Contracts\SlaService;
use App\Services\Tprm\Integration\ErmBridge;
use Illuminate\Support\Facades\DB;

/**
 * Everything the pure calculators cannot do for themselves: read the database,
 * ask the clock, and write the run.
 *
 * THE BOUNDARY IS THE POINT. `ResidualRiskCalculator` and
 * `DataConfidenceCalculator` take values and return values; this class turns
 * an engagement into those values and stores the answer. Keeping the queries
 * here means the arithmetic can be tested against the TRD's worked examples
 * with plain numbers, and it means there is one reviewable place where the
 * question "which findings count" is answered.
 *
 * A RECOMPUTATION WRITES A NEW RUN AND NEVER EDITS AN OLD ONE — TRD §7.9.
 * Scores are not recomputed retrospectively: a ruleset change produces a new
 * run and a reportable diff. That is what makes AC-15 possible, because the
 * panel renders the STORED explanation rather than recomputing on read, so two
 * users looking at the same score see the same derivation.
 *
 * THE ENGAGEMENT'S OWN COLUMNS ARE UPDATED TOO, and that duplication is
 * deliberate: TRD §8.10 wants `residual_score` on the row so a register of
 * five thousand engagements does not pay for the derivation, while the run
 * holds the explanation. The register reads the column; the panel reads the
 * run; neither recomputes.
 */
class ResidualScoringService
{
    public function __construct(
        private readonly ResidualRiskCalculator $calculator,
        private readonly DataConfidenceCalculator $confidence,
        private readonly ScoreExplainer $explainer,
    ) {}

    /**
     * Recompute an engagement's residual score and record the run.
     */
    public function score(Engagement $engagement, string $trigger = 'manual', string $runType = 'residual'): ScoreRun
    {
        $engagement->loadMissing(['thirdParty']);

        $assessment = $this->latestScoredAssessment($engagement);
        $inputs = $this->inputsFor($engagement, $assessment);
        $result = $this->calculator->calculate($inputs);
        $confidence = $this->dataConfidence($engagement, $assessment);

        $run = DB::transaction(function () use ($engagement, $inputs, $result, $confidence, $trigger, $runType, $assessment) {
            $run = ScoreRun::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'run_type' => $runType,
                'engine_version' => (string) config('tprm.engine_version'),
                'ruleset_version' => $this->rulesetVersion($engagement),
                'inputs' => $inputs->toArray() + ['data_confidence' => $confidence->toArray()],
                'ir' => round($result->ir, 2),
                'ac' => round($result->ac, 3),
                'ec' => round($result->ec, 3),
                'm' => round($result->m, 3),
                'fu' => round($result->fu, 2),
                'su' => round($result->su, 2),
                'rr' => round($result->rr, 2),
                'band' => $result->band->value,
                'dc' => round($confidence->dc, 3),
                'explanation' => $this->explainer->explain($engagement, $result, $confidence, $assessment),
                'triggered_by' => $trigger,
                'created_at' => now(),
            ]);

            // Denormalised for the register. Written from the same result the
            // run stored, in the same transaction, so the column and the run
            // cannot disagree.
            $engagement->forceFill([
                'residual_score' => round($result->rr, 2),
                'residual_band' => $result->band->value,
                'data_confidence' => round($confidence->dc, 3),
            ])->save();

            return $run;
        });

        // TRD §15, after the transaction: the mirrored ERM risk carries the
        // new scores. Outside, and swallowing its own failure, for the reason
        // the finding mirror gives — a scoring run must not be lost because
        // the risk register rejected an update.
        try {
            app(ErmBridge::class)->mirrorEngagementRisk($engagement->refresh());
        } catch (\Throwable $exception) {
            logger()->error('TPRM engagement could not be mirrored to the ERM risk register', [
                'engagement_id' => $engagement->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $run;
    }

    /**
     * The inputs, assembled from the engagement's current state.
     */
    public function inputsFor(Engagement $engagement, ?Assessment $assessment = null): ResidualInputs
    {
        $assessment ??= $this->latestScoredAssessment($engagement);

        return ResidualInputs::fromConfig(
            inherentScore: (float) ($engagement->inherent_score ?? 0),
            // No validated assessment means no assurance. NOT a default of 1.0
            // and not a skipped mitigation: a vendor nobody has assessed has
            // demonstrated nothing, and M = 0 leaves the inherent score
            // standing, which is the honest position.
            ac: (float) ($assessment->ac ?? 0),
            ec: (float) ($assessment->ec ?? 0),
            findings: $this->findingContributions($engagement),
            signals: $this->signalContributions($engagement),
        );
    }

    /**
     * The findings that enter FU, with their multiplier decisions already made.
     *
     * Decided HERE rather than inside the calculator, so the score panel and
     * the findings board read one definition of "overdue" — two readings of
     * the same word is how a board pack and a working list end up disagreeing
     * about the same finding.
     *
     * @return list<FindingContribution>
     */
    public function findingContributions(Engagement $engagement): array
    {
        $multiple = (int) config('tprm.scoring.findings_uplift.overdue_threshold_multiple', 2);

        return Finding::query()
            ->where('engagement_id', $engagement->getKey())
            ->scoring()
            ->with('acceptance')
            ->get()
            ->map(fn (Finding $finding) => new FindingContribution(
                severity: $finding->severity->value,
                withinSlaWithAcceptedPlan: $finding->isWithinSlaWithAcceptedPlan(),
                overdueBeyondThreshold: $finding->isOverdueBeyond($multiple),
                // The acceptance's own expiry, not the finding's status: an
                // expired acceptance is an open finding again, and it should
                // count at full weight before anybody re-approves it.
                riskAccepted: $finding->isRiskAccepted(),
                reference: $finding->reference,
                title: $finding->title,
                id: $finding->getKey(),
            ))
            ->values()
            ->all();
    }

    /**
     * The signals that enter SU.
     *
     * SPLIT BY ORIGIN, AND THE SPLIT IS DELIBERATE.
     *
     *   INTERNALLY DERIVABLE signals — expired evidence, an overdue
     *   assessment, repeated SLA breaches — are computed LIVE from the
     *   register's own state. They are always current, they cost one query
     *   each, and deriving them here means a score is right the moment
     *   something changes rather than the morning after the sweep runs.
     *
     *   EXTERNALLY OBSERVED signals — a confirmed sanctions match, a breach, a
     *   ratings drop, a regulatory action — can only be read from
     *   `tp_monitoring_signals`, because nothing in the register knows them.
     *
     * The two sets are disjoint by construction: `SignalType::isInternallyDerived()`
     * decides which side a type falls on, so a type cannot be counted twice
     * however it arrived.
     *
     * @return list<SignalContribution>
     */
    public function signalContributions(Engagement $engagement): array
    {
        $signals = $this->observedSignals($engagement);

        foreach ($this->expiredEvidence($engagement) as $document) {
            $signals[] = new SignalContribution(
                type: 'expired_mandatory_evidence',
                label: sprintf(
                    '%s expired on %s',
                    $document->title,
                    $document->valid_to?->toDateString() ?? 'an unrecorded date',
                ),
                id: $document->getKey(),
                observedAt: $document->valid_to?->toDateString(),
            );
        }

        if ($engagement->next_assessment_due !== null
            && $engagement->next_assessment_due->isBefore(now()->startOfDay())) {
            $signals[] = new SignalContribution(
                type: 'overdue_assessment',
                label: sprintf(
                    'The assessment was due on %s, %d days ago',
                    $engagement->next_assessment_due->toDateString(),
                    (int) $engagement->next_assessment_due->diffInDays(now()),
                ),
                observedAt: $engagement->next_assessment_due->toDateString(),
            );
        }

        foreach ($this->repeatedSlaBreaches($engagement) as $sla) {
            $signals[] = new SignalContribution(
                type: 'sla_breach_3_periods',
                label: sprintf('%s has breached its target for 3 or more consecutive periods', $sla->metric_name),
                id: $sla->getKey(),
            );
        }

        return $signals;
    }

    /**
     * Signals nothing in the register could derive — read from the monitoring
     * stream.
     *
     * Only signal types flagged as NOT internally derived, so a stored
     * `evidence_expired` written by last night's sweep cannot be counted on
     * top of the live derivation above.
     *
     * A MUTED ALERT DOES NOT MUTE A SIGNAL. Muting silences the console; it
     * does not make a confirmed breach stop having happened, and a score that
     * fell because somebody silenced an alert would be the most damaging
     * feature in the module.
     *
     * @return list<SignalContribution>
     */
    private function observedSignals(Engagement $engagement): array
    {
        $externalTypes = array_values(array_filter(
            array_map(fn (\App\Enums\Tprm\SignalType $type) => $type->value, \App\Enums\Tprm\SignalType::cases()),
            fn (string $value) => ! \App\Enums\Tprm\SignalType::from($value)->isInternallyDerived(),
        ));

        return \App\Models\Tprm\MonitoringSignal::query()
            ->where(fn ($query) => $query
                ->where('engagement_id', $engagement->getKey())
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('engagement_id')
                    ->where('third_party_id', $engagement->third_party_id)))
            ->whereIn('signal_type', $externalTypes)
            // A twelve-month window: TRD §7.5 scopes the breach penalty to
            // "confirmed breach ≤12 months", and an observation older than
            // that is history rather than a current condition.
            ->where('observed_at', '>=', now()->subMonths(12))
            ->orderByDesc('observed_at')
            ->get()
            ->map(fn (\App\Models\Tprm\MonitoringSignal $signal) => new SignalContribution(
                type: $this->penaltyKeyFor($signal->signal_type),
                label: $signal->title,
                id: $signal->getKey(),
                observedAt: $signal->observed_at?->toDateString(),
            ))
            ->values()
            ->all();
    }

    /**
     * The config key a signal type's penalty is stored under.
     *
     * The enum's names and TRD §7.5's penalty table use different vocabulary —
     * `data_breach` against `confirmed_breach_12m` — and the mapping lives
     * here rather than in the calculator, which knows only about the penalty
     * table's keys.
     */
    private function penaltyKeyFor(\App\Enums\Tprm\SignalType $type): string
    {
        return match ($type) {
            \App\Enums\Tprm\SignalType::SanctionsMatch => 'sanctions_true_match',
            \App\Enums\Tprm\SignalType::DataBreach => 'confirmed_breach_12m',
            \App\Enums\Tprm\SignalType::CyberRatingChange => 'cyber_rating_band_drop',
            \App\Enums\Tprm\SignalType::FinancialDistress => 'financial_distress',
            \App\Enums\Tprm\SignalType::RegulatoryAction => 'regulatory_action',
            default => $type->value,
        };
    }

    /**
     * Documents past their expiry against this engagement or its third party.
     *
     * Superseded ones are excluded: a replaced certificate that has since
     * expired is not a gap, it is history, and counting it would penalise a
     * vendor for having renewed on time.
     *
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function expiredEvidence(Engagement $engagement)
    {
        return Document::query()
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_ENGAGEMENT)
                    ->where('owner_id', $engagement->getKey()))
                ->orWhere(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_THIRD_PARTY)
                    ->where('owner_id', $engagement->third_party_id)))
            ->expired()
            ->whereHas('documentType', fn ($query) => $query->where('is_assurance_evidence', true))
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Sla>
     */
    private function repeatedSlaBreaches(Engagement $engagement)
    {
        $service = app(SlaService::class);

        return Sla::query()
            ->where('engagement_id', $engagement->getKey())
            ->active()
            ->get()
            ->filter(fn (Sla $sla) => $service->consecutiveBreaches($sla) >= 3)
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Data confidence */
    /* ------------------------------------------------------------------ */

    public function dataConfidence(Engagement $engagement, ?Assessment $assessment = null): DataConfidenceResult
    {
        $assessment ??= $this->latestScoredAssessment($engagement);
        $policy = $this->tierPolicy($engagement);

        /** @var array<string, mixed> $config */
        $config = config('tprm.scoring.data_confidence');

        return $this->confidence->calculate(
            ages: [
                'assessment_age' => $this->ageInDays($assessment?->validated_at),
                'evidence_age' => $this->newestEvidenceAge($engagement),
                'screening_age' => $this->ageInDays($engagement->thirdParty?->last_screened_at),
                // Phase 6 brings the monitoring stream. Null here means "never
                // monitored", which scores the floor — the honest answer for a
                // deployment where monitoring is not switched on, and one that
                // starts improving the moment it is.
                'monitoring_recency' => null,
            ],
            intervals: [
                'assessment_age' => ($policy->assessment_frequency_months ?? 12) * 30,
                'evidence_age' => 365,
                'screening_age' => ($policy->screening_frequency_months ?? 12) * 30,
                'monitoring_recency' => 30,
            ],
            weights: array_map('floatval', $config['weights']),
            floor: (float) $config['floor'],
            decayMultiple: (int) $config['decay_multiple'],
        );
    }

    private function newestEvidenceAge(Engagement $engagement): ?int
    {
        $newest = Document::query()
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_ENGAGEMENT)
                    ->where('owner_id', $engagement->getKey()))
                ->orWhere(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_THIRD_PARTY)
                    ->where('owner_id', $engagement->third_party_id)))
            ->where('is_superseded', false)
            ->orderByDesc('created_at')
            ->value('created_at');

        return $this->ageInDays($newest === null ? null : \Illuminate\Support\Carbon::parse($newest));
    }

    private function ageInDays(?\DateTimeInterface $at): ?int
    {
        return $at === null ? null : (int) \Illuminate\Support\Carbon::parse($at)->diffInDays(now());
    }

    /* ------------------------------------------------------------------ */

    private function latestScoredAssessment(Engagement $engagement): ?Assessment
    {
        return Assessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->whereIn('status', ['validated', 'scored', 'closed'])
            ->whereNotNull('ac')
            ->orderByDesc('validated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function tierPolicy(Engagement $engagement): ?TierPolicy
    {
        $tier = $engagement->effectiveTier();

        return $tier === null
            ? null
            : TierPolicy::query()->where('tier', $tier->value)->first();
    }

    /**
     * The ruleset version this score was computed under.
     *
     * Read from the engagement's current inherent assessment rather than from
     * the active ruleset, so a residual score names the ruleset its inherent
     * score came from — otherwise a ruleset published this morning would
     * appear to have produced an IR computed last year.
     */
    private function rulesetVersion(Engagement $engagement): string
    {
        return (string) (\App\Models\Tprm\InherentAssessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('is_current', true)
            ->value('ruleset_version') ?? 'unversioned');
    }
}
