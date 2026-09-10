<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Support\Tprm\RuleEvaluator;

/**
 * The knockout rules of TRD §7.3 — pure, evaluated through the Phase 0 DSL.
 *
 * A KNOCKOUT SETS A FLOOR AND NEVER REDUCES A TIER. That single sentence is
 * the whole semantics, and AC-02 tests both halves of it: an engagement
 * scoring 31 on the weighted model with core-banking connectivity is Critical,
 * and lowering the weighted score further does not lower the tier. Anything
 * that treats a knockout as "set the tier to X" gets the second half wrong the
 * first time a Critical-scoring engagement fires a High-floor knockout.
 *
 * Every rule that fires is returned with its name and citation for display.
 * A rule that CANNOT be evaluated — because a fact it needs is not in the
 * context — is reported through `unresolvedFacts` rather than silently not
 * firing. A knockout that quietly never fires is the most expensive possible
 * failure in this module: a core-banking vendor tiered Moderate, with nothing
 * on any screen to say why.
 */
class KnockoutEngine
{
    public function __construct(private readonly RuleEvaluator $evaluator) {}

    /**
     * @param  array<string, mixed>  $context  flat, dot-keyed facts including `answer.*`
     */
    public function evaluate(array $context, Ruleset $ruleset): KnockoutResult
    {
        $fired = [];
        $floor = null;
        $unresolved = [];

        foreach ($ruleset->knockouts as $rule) {
            $condition = $rule['condition'] ?? null;

            if (! is_array($condition)) {
                continue;
            }

            $matched = $this->evaluator->evaluate($condition, $context);

            foreach ($this->evaluator->unresolvedFacts() as $fact) {
                $unresolved[] = ($rule['code'] ?? '?').': '.$fact;
            }

            if (! $matched) {
                continue;
            }

            $knockoutFloor = RiskTier::tryFrom((string) ($rule['floor'] ?? '')) ?? RiskTier::Low;

            $fired[] = new FiredKnockout(
                code: (string) ($rule['code'] ?? ''),
                name: (string) ($rule['name'] ?? ''),
                floor: $knockoutFloor,
                citation: (string) ($rule['citation'] ?? ''),
                suspends: (bool) ($rule['suspends'] ?? false),
            );

            // The floor is the HIGHEST floor among fired rules. Two rules
            // firing at High and Critical floor the engagement at Critical.
            $floor = $knockoutFloor->max($floor);
        }

        return new KnockoutResult(
            fired: $fired,
            floor: $floor,
            unresolvedFacts: array_values(array_unique($unresolved)),
        );
    }

    /**
     * The final tier — TRD §7.3's
     * `max(tier_from_score, highest_knockout_floor, manual_override_floor)`.
     *
     * Declared here, once, so that no caller reimplements the max and gets the
     * direction wrong. An override is a floor exactly as a knockout is: the
     * risk function may decide a vendor deserves MORE scrutiny than the model
     * says, and lowering a computed tier is not an override, it is a change to
     * the ruleset.
     */
    public function finalTier(RiskTier $fromScore, ?RiskTier $knockoutFloor, ?RiskTier $overrideFloor = null): RiskTier
    {
        return $fromScore->max($knockoutFloor)->max($overrideFloor);
    }
}
