<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Services\Tprm\Scoring\InherentRiskCalculator;
use App\Services\Tprm\Scoring\KnockoutEngine;
use App\Services\Tprm\Scoring\Ruleset;
use App\Support\Tprm\RuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The knockout rules of TRD §7.3, and AC-02.
 *
 * A knockout sets a FLOOR and never reduces a tier. Both halves are tested,
 * because the second is the one an implementation gets wrong: "set the tier to
 * the knockout's tier" passes every test of the first half and silently
 * downgrades a Critical-scoring engagement the first time a High-floor rule
 * fires against it.
 */
class KnockoutEngineTest extends TestCase
{
    private KnockoutEngine $engine;

    private Ruleset $ruleset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new KnockoutEngine(new RuleEvaluator);
        $this->ruleset = Ruleset::shipped();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-02 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ac02_a_score_of_31_with_core_banking_connectivity_tiers_critical(): void
    {
        // AC-02, verbatim: "An engagement scoring 31 (Moderate) on the
        // weighted model but with core-banking connectivity is tiered
        // Critical, with KO-CORE-CONN shown and cited."
        $fromScore = $this->ruleset->tierForScore(31.0);
        $this->assertSame(RiskTier::Moderate, $fromScore, 'A weighted 31 must be Moderate before knockouts.');

        $result = $this->engine->evaluate(
            $this->context(['engagement.has_core_banking_connection' => true]),
            $this->ruleset
        );

        $final = $this->engine->finalTier($fromScore, $result->floor);

        $this->assertSame(RiskTier::Critical, $final);

        $codes = array_map(fn ($k) => $k->code, $result->fired);
        $this->assertContains('KO-CORE-CONN', $codes);

        // "shown AND CITED" — the citation is half the acceptance criterion.
        $fired = collect($result->fired)->firstWhere('code', 'KO-CORE-CONN');
        $this->assertSame('CBN Cyber Framework App. II §1.4, App. III §1.3', $fired->citation);
        $this->assertNotSame('', $fired->name);
    }

    #[Test]
    public function ac02_lowering_the_weighted_score_further_does_not_lower_the_tier(): void
    {
        // The second half of AC-02, and the half that catches a knockout
        // implemented as an assignment rather than a floor.
        $context = $this->context(['engagement.has_core_banking_connection' => true]);
        $result = $this->engine->evaluate($context, $this->ruleset);

        foreach ([31.0, 20.0, 5.0, 0.0] as $score) {
            $this->assertSame(
                RiskTier::Critical,
                $this->engine->finalTier($this->ruleset->tierForScore($score), $result->floor),
                "A weighted score of {$score} with core-banking connectivity must still be Critical."
            );
        }
    }

    #[Test]
    public function ac02_holds_end_to_end_from_the_questionnaire_answers(): void
    {
        // The same criterion driven from Appendix A answers rather than from a
        // hand-set score, so that the calculator and the engine are shown to
        // agree — a weighted 31 is reachable from real answers, and those same
        // answers fire the knockout.
        $answers = [
            'A1' => 'internal', 'A2' => '1k_100k', 'A3' => 'domestic',
            'A5' => 'privileged',                       // core banking connectivity
            'A7' => 'standard', 'A8' => 'over_72h',
            'A10' => ['cbn_cyber'], 'A12' => 'many', 'A13' => 'under_1m', 'A14' => 'under_10m',
        ];

        $inherent = (new InherentRiskCalculator)->calculate($answers, $this->ruleset);

        $context = $this->context(array_merge(
            ['answer.A5' => 'privileged'],
            ['engagement.has_privileged_access' => true],
        ));

        $knockouts = $this->engine->evaluate($context, $this->ruleset);
        $final = $this->engine->finalTier($inherent->tier, $knockouts->floor);

        $this->assertSame(RiskTier::Critical, $final);
        $this->assertContains('KO-CORE-CONN', array_map(fn ($k) => $k->code, $knockouts->fired));
    }

    /* ------------------------------------------------------------------ */
    /*  Floor semantics */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_high_floor_knockout_never_lowers_a_critical_score(): void
    {
        // KO-PRIV floors at High. An engagement already scoring Critical stays
        // Critical — the failure mode a naive implementation introduces.
        $result = $this->engine->evaluate(
            $this->context(['engagement.has_privileged_access' => true]),
            $this->ruleset
        );

        $this->assertSame(RiskTier::High, $result->floor);
        $this->assertSame(
            RiskTier::Critical,
            $this->engine->finalTier(RiskTier::Critical, $result->floor)
        );
    }

    #[Test]
    public function the_highest_floor_among_several_fired_rules_wins(): void
    {
        $result = $this->engine->evaluate($this->context([
            'engagement.has_privileged_access' => true,     // KO-PRIV, High
            'answer.A17' => true,                            // KO-CHD, Critical
        ]), $this->ruleset);

        $codes = array_map(fn ($k) => $k->code, $result->fired);
        $this->assertContains('KO-PRIV', $codes);
        $this->assertContains('KO-CHD', $codes);
        $this->assertSame(RiskTier::Critical, $result->floor);
    }

    #[Test]
    public function no_fired_rule_leaves_the_computed_tier_untouched(): void
    {
        $result = $this->engine->evaluate($this->context(), $this->ruleset);

        $this->assertSame([], $result->fired);
        $this->assertNull($result->floor);
        $this->assertSame(RiskTier::Low, $this->engine->finalTier(RiskTier::Low, null));
    }

    #[Test]
    public function a_manual_override_is_a_floor_like_a_knockout(): void
    {
        // FR-TIER-04. The risk function may decide a vendor deserves MORE
        // scrutiny than the model says; deciding it deserves less is a change
        // to the ruleset, not an override.
        $this->assertSame(
            RiskTier::Critical,
            $this->engine->finalTier(RiskTier::Low, null, RiskTier::Critical)
        );

        $this->assertSame(
            RiskTier::Critical,
            $this->engine->finalTier(RiskTier::Critical, null, RiskTier::Low)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Each rule fires on its own condition */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_shipped_knockout_can_fire_and_carries_a_citation(): void
    {
        // A rule whose condition can never be satisfied is a control that does
        // not exist. Each is fired individually here, which also proves every
        // fact it names is one the registry admits.
        $cases = [
            'KO-CORE-CONN' => ['engagement.has_core_banking_connection' => true],
            'KO-CIF' => ['engagement.max_function_criticality' => 'critical', 'engagement.min_function_rto_hours' => 2],
            'KO-CHD' => ['engagement.pci_in_scope' => true],
            'KO-PII-XB' => [
                'engagement.processes_personal_data' => true,
                'engagement.cross_border' => true,
                'engagement.transfer_basis' => 'none',
            ],
            'KO-REGACT' => ['engagement.type' => 'agency'],
            'KO-SOLE' => ['engagement.substitutability' => 'sole', 'engagement.max_function_criticality' => 'critical'],
            'KO-SANCTION' => ['third_party.has_true_match' => true],
            'KO-PRIV' => ['engagement.has_privileged_access' => true],
            'KO-PII-VOL' => ['answer.A2' => 'over_1m'],
            'KO-NOCONTRACT' => ['engagement.status' => 'active', 'engagement.has_executed_contract' => false],
        ];

        $this->assertCount(count($cases), $this->ruleset->knockouts, 'The shipped ruleset should define ten knockouts.');

        foreach ($cases as $code => $facts) {
            $result = $this->engine->evaluate($this->context($facts), $this->ruleset);
            $fired = collect($result->fired)->firstWhere('code', $code);

            $this->assertNotNull($fired, "{$code} did not fire on its own condition.");
            $this->assertNotSame('', $fired->citation, "{$code} fired with no citation.");
            $this->assertNotSame('', $fired->name, "{$code} fired with no name.");
        }
    }

    #[Test]
    public function a_recorded_transfer_basis_stops_the_cross_border_knockout(): void
    {
        // The difference between a lawful cross-border transfer and an
        // unlawful one is one column, and it is the whole rule.
        $withBasis = $this->engine->evaluate($this->context([
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => true,
            'engagement.transfer_basis' => 'scc',
        ]), $this->ruleset);

        $this->assertNotContains('KO-PII-XB', array_map(fn ($k) => $k->code, $withBasis->fired));
    }

    #[Test]
    public function only_the_sanctions_knockout_suspends(): void
    {
        // AC-08 hangs off this flag: a confirmed match suspends every
        // engagement with the third party. No other rule may do that.
        $sanctions = $this->engine->evaluate(
            $this->context(['third_party.has_true_match' => true]),
            $this->ruleset
        );
        $this->assertTrue($sanctions->suspendsEngagements());

        $coreBanking = $this->engine->evaluate(
            $this->context(['engagement.has_core_banking_connection' => true]),
            $this->ruleset
        );
        $this->assertFalse($coreBanking->suspendsEngagements());
    }

    /* ------------------------------------------------------------------ */
    /*  Missing facts */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_rule_that_could_not_be_evaluated_is_reported_not_silently_skipped(): void
    {
        // The most expensive failure available in this module is a knockout
        // that quietly never fires. An empty context cannot resolve most of
        // these rules, and the engine has to say so.
        $result = $this->engine->evaluate([], $this->ruleset);

        $this->assertSame([], $result->fired);
        $this->assertNotEmpty($result->unresolvedFacts);
        $this->assertStringContainsString('KO-', $result->unresolvedFacts[0]);
    }

    #[Test]
    public function a_full_context_resolves_every_rule_without_complaint(): void
    {
        // The counterpart: when the caller supplies everything the rules need,
        // nothing is reported unresolved. This is what proves the context
        // TieringService builds is actually complete.
        $result = $this->engine->evaluate($this->context(), $this->ruleset);

        $this->assertSame([], $result->unresolvedFacts);
    }

    /* ------------------------------------------------------------------ */

    /**
     * A context in which every fact the shipped rules name is present and
     * benign, so a test can flip exactly one thing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'answer.A2' => 'under_1k',
            'answer.A5' => 'read_only',
            'answer.A11' => false,
            'answer.A17' => false,
            'engagement.type' => 'ict_service',
            'engagement.status' => 'draft',
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => false,
            'engagement.cross_border' => false,
            'engagement.transfer_basis' => null,
            'engagement.substitutability' => 'many',
            'engagement.max_function_criticality' => 'standard',
            'engagement.min_function_rto_hours' => 72,
            'engagement.has_core_banking_connection' => false,
            'engagement.has_privileged_access' => false,
            'engagement.has_executed_contract' => true,
            'third_party.has_true_match' => false,
        ], $overrides);
    }
}
