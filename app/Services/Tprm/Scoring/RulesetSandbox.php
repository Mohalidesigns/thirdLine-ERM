<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;

/**
 * The sandbox simulator — FR-TIER-09.
 *
 * "Apply a draft ruleset to the current portfolio in memory and show the
 * before/after tier migration table before publishing."
 *
 * THIS IS THE CONTROL ON THE RULESET EDITOR, and without it the editor should
 * not exist. Changing the DATA weight from 25 to 30 is a change whose effect
 * on five thousand vendors nobody can see until it has already happened —
 * every score recomputed under rules nobody tested, with the old numbers still
 * sitting in last quarter's board pack. The simulator makes that visible for
 * the cost of one query and no writes.
 *
 * IT WRITES NOTHING. Not a score run, not an assessment version, not a
 * denormalised tier. The whole value is that a risk officer can try six
 * weightings before choosing one, and an editor whose experiments leave
 * traces is one people stop experimenting with.
 *
 * It replays each engagement's STORED ANSWERS through the draft ruleset. That
 * is what makes the comparison honest: the same inputs, different rules, so
 * every difference in the table is attributable to the edit rather than to
 * data that moved in between.
 */
class RulesetSandbox
{
    public function __construct(
        private readonly InherentRiskCalculator $calculator,
        private readonly KnockoutEngine $knockouts,
        private readonly EngagementContext $context,
    ) {}

    /**
     * Replay the portfolio under a draft ruleset.
     *
     * @param  int|null  $limit  cap the portfolio scanned; null for all of it
     * @return array<string, mixed>
     */
    public function simulate(Ruleset $draft, ?int $limit = null): array
    {
        $assessments = InherentAssessment::query()
            ->with(['engagement.thirdParty:id,legal_name', 'engagement.businessFunctions'])
            ->where('is_current', true)
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        $movements = [];
        $matrix = [];
        $unchanged = 0;
        $raised = 0;
        $lowered = 0;

        foreach ($assessments as $assessment) {
            $engagement = $assessment->engagement;

            if ($engagement === null) {
                continue;
            }

            $before = $engagement->effective_tier;
            $after = $this->tierUnder($draft, $engagement, (array) $assessment->answers);

            $key = ($before === null ? 'none' : $before->value).'→'.$after->value;
            $matrix[$key] = ($matrix[$key] ?? 0) + 1;

            if ($before === $after) {
                $unchanged++;

                continue;
            }

            $direction = $before === null || $after->rank() > $before->rank() ? 'raised' : 'lowered';
            $direction === 'raised' ? $raised++ : $lowered++;

            $movements[] = [
                'engagement_id' => $engagement->getKey(),
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'third_party' => $engagement->thirdParty?->legal_name,
                'before' => $before?->value,
                'before_label' => $before?->label(),
                'after' => $after->value,
                'after_label' => $after->label(),
                'direction' => $direction,
            ];
        }

        // Biggest moves first — a Low becoming Critical is the row that decides
        // whether the change ships.
        usort($movements, function (array $a, array $b) {
            $magnitude = fn (array $m) => abs(
                (RiskTier::tryFrom((string) $m['after'])?->rank() ?? 0)
                - (RiskTier::tryFrom((string) $m['before'])?->rank() ?? 0)
            );

            return $magnitude($b) <=> $magnitude($a);
        });

        return [
            'ruleset_version' => $draft->version,
            'assessed' => $assessments->count(),
            'unchanged' => $unchanged,
            'raised' => $raised,
            'lowered' => $lowered,
            'distribution' => $this->distribution($draft, $assessments),
            'matrix' => $matrix,
            // Capped for the response; the counts above cover the whole run.
            'movements' => array_slice($movements, 0, 100),
            'truncated' => count($movements) > 100,
        ];
    }

    /**
     * Tier counts before and after, for the two donuts beside the table.
     *
     * @param  \Illuminate\Support\Collection<int, InherentAssessment>  $assessments
     * @return array{before: array<string, int>, after: array<string, int>}
     */
    private function distribution(Ruleset $draft, $assessments): array
    {
        $empty = array_fill_keys(RiskTier::values(), 0);
        $before = $empty;
        $after = $empty;

        foreach ($assessments as $assessment) {
            $engagement = $assessment->engagement;

            if ($engagement === null) {
                continue;
            }

            if ($engagement->effective_tier !== null) {
                $before[$engagement->effective_tier->value]++;
            }

            $after[$this->tierUnder($draft, $engagement, (array) $assessment->answers)->value]++;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function tierUnder(Ruleset $draft, Engagement $engagement, array $answers): RiskTier
    {
        $context = $this->context->build($engagement, $answers);

        $inherent = $this->calculator->calculate($answers, $draft, [
            'max_function_criticality' => $context['engagement.max_function_criticality'] ?? null,
        ]);

        $knockouts = $this->knockouts->evaluate($context, $draft);

        // The manual override is deliberately NOT applied. The simulator
        // answers "what would the MODEL say", and folding in per-vendor
        // exceptions would hide exactly the movement the risk officer is
        // trying to see.
        return $this->knockouts->finalTier($inherent->tier, $knockouts->floor);
    }
}
