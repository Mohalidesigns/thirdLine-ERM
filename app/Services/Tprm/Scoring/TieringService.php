<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\Ruleset as RulesetModel;
use App\Models\Tprm\ScoreRun;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates tiering: compute → apply knockouts → apply override → persist a
 * version and a score run → update the engagement.
 *
 * The only impure part of the scoring path. The calculators decide the
 * numbers; this decides what is written down, and the order it writes in
 * matters:
 *
 *   1. A NEW `tp_inherent_assessments` VERSION, with the previous one's
 *      `is_current` cleared rather than deleted. FR-TIER-08 asks for every
 *      recomputation to be a version with a diff, and "why was this vendor
 *      Moderate in March" is answerable only while March's answers, weights
 *      and ruleset version all still exist.
 *
 *   2. A `tp_score_runs` ROW, immutable, carrying the full input snapshot and
 *      the engine and ruleset versions. This is what the "Why this score"
 *      panel renders, and what makes two users see identical derivations
 *      (AC-15) — they read one stored explanation rather than each triggering
 *      a fresh computation.
 *
 *   3. The ENGAGEMENT'S denormalised columns, last, so that a failure earlier
 *      in the transaction cannot leave a tier on the register with no run
 *      behind it to explain it.
 *
 * All three in one transaction, because a tier without its derivation is worse
 * than no tier: it is a number on a board pack that nobody can defend.
 */
class TieringService
{
    public function __construct(
        private readonly InherentRiskCalculator $calculator,
        private readonly KnockoutEngine $knockouts,
        private readonly EngagementContext $context,
    ) {}

    /**
     * Score an engagement from a set of Appendix A answers and persist the
     * result.
     *
     * @param  array<string, mixed>  $answers
     */
    public function tier(Engagement $engagement, array $answers, ?int $assessedBy = null, string $runType = 'inherent'): TieringOutcome
    {
        $ruleset = RulesetModel::currentValue($engagement->organization_id);
        $context = $this->context->build($engagement, $answers);

        $inherent = $this->calculator->calculate($answers, $ruleset, [
            'max_function_criticality' => $context['engagement.max_function_criticality'] ?? null,
            'supervisory_access_impeded' => $this->supervisoryAccessImpeded($answers),
            'processing_country' => $answers['A4'] ?? null,
        ]);

        $knockouts = $this->knockouts->evaluate($context, $ruleset);

        // An override that has expired is not applied. FR-TIER-04 requires the
        // computed tier to be restored when it lapses, and reading the column
        // without checking the date is how an exception becomes permanent.
        $overrideFloor = $this->activeOverride($engagement);

        $effectiveTier = $this->knockouts->finalTier($inherent->tier, $knockouts->floor, $overrideFloor);

        $outcome = new TieringOutcome(
            inherent: $inherent,
            knockouts: $knockouts,
            overrideFloor: $overrideFloor,
            effectiveTier: $effectiveTier,
            rulesetVersion: $ruleset->version,
        );

        DB::transaction(function () use ($engagement, $answers, $inherent, $knockouts, $effectiveTier, $ruleset, $assessedBy, $runType, $outcome): void {
            $version = (int) InherentAssessment::query()
                ->where('engagement_id', $engagement->getKey())
                ->max('version') + 1;

            InherentAssessment::query()
                ->where('engagement_id', $engagement->getKey())
                ->update(['is_current' => false]);

            InherentAssessment::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'version' => $version,
                'ruleset_version' => $ruleset->version,
                'answers' => $answers,
                'factor_scores' => $inherent->factorScores(),
                'weights' => $inherent->weights(),
                'raw_score' => round($inherent->score, 2),
                'knockouts_fired' => $knockouts->toArray(),
                'resulting_tier' => $effectiveTier->value,
                'assessed_by' => $assessedBy,
                'assessed_at' => now(),
                'is_current' => true,
            ]);

            ScoreRun::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'run_type' => $runType,
                'ruleset_version' => $ruleset->version,
                'inputs' => ['answers' => $answers, 'weights' => $inherent->weights()],
                'ir' => round($inherent->score, 2),
                // AC, EC and the residual terms arrive in Phase 5. Null here is
                // "not yet computed", which is a different statement from zero
                // and must not be rendered as a score of nought.
                'explanation' => $outcome->explanation(),
                'triggered_by' => $runType,
            ]);

            $engagement->forceFill([
                'inherent_score' => round($inherent->score, 2),
                'inherent_tier' => $inherent->tier,
                'effective_tier' => $effectiveTier,
                'supports_critical_function' => in_array(
                    $engagement->businessFunctions->max('criticality'),
                    ['critical', 'important'],
                    true
                ),
            ])->save();
        });

        return $outcome;
    }

    /**
     * Compute a tier and its full derivation WITHOUT writing anything.
     *
     * This is the intake form's live preview (FR-INT-02) and the sandbox
     * simulator's engine (FR-TIER-09). It runs exactly the same calculators
     * over exactly the same context as `tier()` — deliberately, because a
     * preview that used a simplified path would show a requester one tier and
     * then persist another, which is worse than no preview.
     *
     * Takes an optional ruleset so the sandbox can apply a DRAFT to the live
     * portfolio and show the migration before anything is published.
     *
     * @param  array<string, mixed>  $answers
     */
    public function preview(Engagement $engagement, array $answers, ?Ruleset $ruleset = null): TieringOutcome
    {
        $ruleset ??= RulesetModel::currentValue($engagement->organization_id);
        $context = $this->context->build($engagement, $answers);

        $inherent = $this->calculator->calculate($answers, $ruleset, [
            'max_function_criticality' => $context['engagement.max_function_criticality'] ?? null,
            'supervisory_access_impeded' => $this->supervisoryAccessImpeded($answers),
            'processing_country' => $answers['A4'] ?? null,
        ]);

        $knockouts = $this->knockouts->evaluate($context, $ruleset);
        $overrideFloor = $this->activeOverride($engagement);

        return new TieringOutcome(
            inherent: $inherent,
            knockouts: $knockouts,
            overrideFloor: $overrideFloor,
            effectiveTier: $this->knockouts->finalTier($inherent->tier, $knockouts->floor, $overrideFloor),
            rulesetVersion: $ruleset->version,
        );
    }

    /**
     * The override floor, if one is in force today.
     *
     * An override with no expiry date is treated as in force. The Form Request
     * requires an expiry, so a null here means a record written before that
     * rule existed rather than a deliberate perpetual exception — and silently
     * ignoring it would quietly lower a tier somebody deliberately raised.
     */
    private function activeOverride(Engagement $engagement): ?RiskTier
    {
        $override = $engagement->tier_override;

        if ($override === null) {
            return null;
        }

        $expiry = $engagement->tier_override_expires_at;

        return ($expiry === null || ! $expiry->isPast()) ? $override : null;
    }

    /**
     * Whether the processing jurisdiction is recorded as impeding supervisory
     * access — the +0.2 GEO adjustment in TRD §7.2.
     *
     * Reads the country risk reference data seeded in Phase 0. A country not
     * in the table is NOT treated as impeded: the adjustment needs positive
     * evidence, and defaulting to "impeded" would penalise every vendor in a
     * country nobody has assessed yet.
     *
     * @param  array<string, mixed>  $answers
     */
    private function supervisoryAccessImpeded(array $answers): bool
    {
        $country = $answers['A4'] ?? null;

        if (! is_string($country) || $country === '') {
            return false;
        }

        return DB::table('tp_country_risk')
            ->where('country_code', $country)
            ->value('supervisory_access_impeded') === 1;
    }
}
