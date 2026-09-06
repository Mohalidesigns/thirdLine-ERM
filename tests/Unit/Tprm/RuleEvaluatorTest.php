<?php

namespace Tests\Unit\Tprm;

use App\Support\Tprm\FactRegistry;
use App\Support\Tprm\RuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules DSL is the regulatory logic — a knockout deciding an engagement is
 * Critical, a clause deciding it may not go live — so it is tested here rather
 * than through the screens that happen to call it.
 *
 * Extends PHPUnit's TestCase, not Laravel's: the evaluator touches no
 * container, no config and no database, and a test that boots the framework to
 * prove that is a test that would still pass if it stopped being true.
 */
class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleEvaluator;
    }

    /* ------------------------------------------------------------------ */
    /*  Empty and malformed rules                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_null_rule_is_true(): void
    {
        // "No visibility rule" means "always visible". An author who left the
        // field blank did not mean "never show this question".
        $this->assertTrue($this->evaluator->evaluate(null, []));
    }

    #[Test]
    public function an_empty_rule_is_true(): void
    {
        $this->assertTrue($this->evaluator->evaluate([], []));
    }

    #[Test]
    public function a_malformed_node_is_false_and_does_not_throw(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['nonsense' => 1], []));
    }

    #[Test]
    public function an_empty_all_is_true_and_an_empty_any_is_false(): void
    {
        // Vacuous truth for `all`; unsatisfiable for `any`. The asymmetry
        // matters: a rule whose `any` branch is emptied by an editing mistake
        // must stop matching, not start matching everything.
        $this->assertTrue($this->evaluator->evaluate(['all' => []], []));
        $this->assertFalse($this->evaluator->evaluate(['any' => []], []));
    }

    #[Test]
    public function a_not_wrapping_a_non_node_is_false(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['not' => 'oops'], []));
    }

    /* ------------------------------------------------------------------ */
    /*  Operators                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $context
     */
    #[Test]
    #[DataProvider('operatorCases')]
    public function operators_behave(string $case, array $rule, array $context, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->evaluator->evaluate($rule, $context),
            $case
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function operatorCases(): array
    {
        $leaf = fn (string $fact, string $op, mixed $value) => ['fact' => $fact, 'op' => $op, 'value' => $value];

        return [
            'eq matches a string' => ['eq string', $leaf('engagement.transfer_basis', 'eq', 'scc'), ['engagement.transfer_basis' => 'scc'], true],
            'eq rejects a different string' => ['eq mismatch', $leaf('engagement.transfer_basis', 'eq', 'scc'), ['engagement.transfer_basis' => 'bcr'], false],
            'ne inverts eq' => ['ne', $leaf('engagement.transfer_basis', 'ne', 'scc'), ['engagement.transfer_basis' => 'bcr'], true],

            'in finds a member' => ['in hit', $leaf('engagement.transfer_basis', 'in', ['bcr', 'scc']), ['engagement.transfer_basis' => 'scc'], true],
            'in misses' => ['in miss', $leaf('engagement.transfer_basis', 'in', ['bcr', 'scc']), ['engagement.transfer_basis' => 'none'], false],
            'not_in inverts in' => ['not_in', $leaf('engagement.transfer_basis', 'not_in', ['bcr', 'scc']), ['engagement.transfer_basis' => 'none'], true],

            // `in` reads both ways: the fact may be the list on a multi-select.
            'in searches a list-valued fact' => ['in reversed', $leaf('answer.Q-DATA-07', 'in', 'encryption'), ['answer.Q-DATA-07' => ['encryption', 'tokenisation']], true],

            'gt compares numbers' => ['gt', $leaf('engagement.inherent_score', 'gt', 50), ['engagement.inherent_score' => 88], true],
            'gt is strict' => ['gt strict', $leaf('engagement.inherent_score', 'gt', 88), ['engagement.inherent_score' => 88], false],
            'gte includes the edge' => ['gte', $leaf('engagement.inherent_score', 'gte', 88), ['engagement.inherent_score' => 88], true],
            'lt compares numbers' => ['lt', $leaf('engagement.time_to_replace_months', 'lt', 6), ['engagement.time_to_replace_months' => 3], true],
            'lte includes the edge' => ['lte', $leaf('engagement.time_to_replace_months', 'lte', 6), ['engagement.time_to_replace_months' => 6], true],

            // Ordered comparison is numbers only. Alphabetical tier ordering
            // would say "critical" < "high", which is the wrong answer stated
            // confidently — a false is the safer wrong answer here.
            'gt refuses non-numeric operands' => ['gt non-numeric', $leaf('engagement.effective_tier', 'gt', 'high'), ['engagement.effective_tier' => 'critical'], false],

            'contains finds a substring' => ['contains substring', $leaf('document.scope_text', 'contains', 'managed service'), ['document.scope_text' => 'Covers the Managed Service platform'], true],
            'contains is case-insensitive' => ['contains case', $leaf('document.scope_text', 'contains', 'MANAGED'), ['document.scope_text' => 'managed service'], true],
            'contains finds a list member' => ['contains list', $leaf('engagement.data_categories', 'contains', 'biometric'), ['engagement.data_categories' => ['financial', 'biometric']], true],
            'contains misses' => ['contains miss', $leaf('engagement.data_categories', 'contains', 'health'), ['engagement.data_categories' => ['financial']], false],
            'contains of an empty needle is false' => ['contains empty', $leaf('document.scope_text', 'contains', ''), ['document.scope_text' => 'anything'], false],

            'exists is true for a set value' => ['exists set', $leaf('engagement.transfer_basis', 'exists', null), ['engagement.transfer_basis' => 'scc'], true],
            'exists is false for a null value' => ['exists null', $leaf('engagement.transfer_basis', 'exists', null), ['engagement.transfer_basis' => null], false],
            'exists is false for an absent fact' => ['exists absent', $leaf('engagement.transfer_basis', 'exists', null), [], false],

            'empty is true for null' => ['empty null', $leaf('engagement.transfer_basis', 'empty', null), ['engagement.transfer_basis' => null], true],
            'empty is true for an empty string' => ['empty string', $leaf('engagement.transfer_basis', 'empty', null), ['engagement.transfer_basis' => ''], true],
            'empty is true for an empty array' => ['empty array', $leaf('engagement.data_categories', 'empty', null), ['engagement.data_categories' => []], true],
            'empty is true for an absent fact' => ['empty absent', $leaf('engagement.transfer_basis', 'empty', null), [], true],
            'empty is false for a value' => ['empty set', $leaf('engagement.transfer_basis', 'empty', null), ['engagement.transfer_basis' => 'scc'], false],

            'an unknown operator is false' => ['unknown op', $leaf('engagement.inherent_score', 'approximately', 88), ['engagement.inherent_score' => 88], false],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Boolean coercion                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The same boolean fact arrives as a real bool from a model cast, as 1
     * from a raw query, as "1" from a form post and as "true" from a JSON
     * column. A rule authored once has to match all four or every boolean rule
     * in the module becomes a coin toss decided by its data's provenance.
     */
    #[Test]
    #[DataProvider('truthyValues')]
    public function boolean_facts_coerce(mixed $stored): void
    {
        $rule = ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.cross_border' => $stored]));
    }

    /** @return array<string, array{mixed}> */
    public static function truthyValues(): array
    {
        return [
            'bool true' => [true],
            'int one' => [1],
            'string one' => ['1'],
            'string true' => ['true'],
        ];
    }

    #[Test]
    #[DataProvider('falsyValues')]
    public function false_facts_coerce(mixed $stored): void
    {
        $rule = ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => false];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.cross_border' => $stored]));
    }

    /** @return array<string, array{mixed}> */
    public static function falsyValues(): array
    {
        return [
            'bool false' => [false],
            'int zero' => [0],
            'string zero' => ['0'],
            'string false' => ['false'],
        ];
    }

    #[Test]
    public function numeric_strings_compare_as_numbers(): void
    {
        $rule = ['fact' => 'engagement.inherent_score', 'op' => 'eq', 'value' => 88];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.inherent_score' => '88.0']));
    }

    #[Test]
    public function a_non_numeric_string_does_not_equal_zero(): void
    {
        // PHP's `==` said "abc" == 0 for years. A regulatory rule engine is
        // not where anyone should find out which version of that is live.
        $rule = ['fact' => 'engagement.effective_tier', 'op' => 'eq', 'value' => 0];

        $this->assertFalse($this->evaluator->evaluate($rule, ['engagement.effective_tier' => 'critical']));
    }

    /* ------------------------------------------------------------------ */
    /*  Missing facts                                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_missing_fact_is_false_and_never_throws(): void
    {
        $rule = ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true];

        $this->assertFalse($this->evaluator->evaluate($rule, []));
    }

    #[Test]
    public function a_missing_fact_under_not_is_true(): void
    {
        // The consequence of "missing is false" propagating through negation.
        // Stated as a test because it is surprising, and because a rule author
        // reading `not(pci_in_scope)` on an engagement that has never been
        // asked about PCI is entitled to know which way it falls.
        $rule = ['not' => ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true]];

        $this->assertTrue($this->evaluator->evaluate($rule, []));
    }

    #[Test]
    public function missing_facts_are_reported_rather_than_swallowed(): void
    {
        $rule = ['all' => [
            ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
            ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
        ]];

        // `all` short-circuits, so only the first miss is seen — which is
        // correct: the evaluator reports what it actually needed.
        $this->evaluator->evaluate($rule, []);

        $this->assertSame(['engagement.pci_in_scope'], $this->evaluator->unresolvedFacts());
    }

    #[Test]
    public function unresolved_facts_reset_between_evaluations(): void
    {
        $this->evaluator->evaluate(['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true], []);
        $this->evaluator->evaluate(['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true], ['engagement.cross_border' => true]);

        $this->assertSame([], $this->evaluator->unresolvedFacts());
    }

    #[Test]
    public function a_null_valued_fact_is_present_not_missing(): void
    {
        // KO-PII-XB fires on transfer_basis being ABSENT AS A DECISION — the
        // engagement was asked and has no lawful basis. That is a present null,
        // not an unresolved fact, and conflating the two would make the
        // knockout unreportable.
        $rule = ['fact' => 'engagement.transfer_basis', 'op' => 'empty', 'value' => null];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.transfer_basis' => null]));
        $this->assertSame([], $this->evaluator->unresolvedFacts());
    }

    /* ------------------------------------------------------------------ */
    /*  Composition and nesting                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function all_requires_every_child(): void
    {
        $rule = ['all' => [
            ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
            ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
        ]];

        $this->assertTrue($this->evaluator->evaluate($rule, [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => true,
        ]));

        $this->assertFalse($this->evaluator->evaluate($rule, [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => false,
        ]));
    }

    #[Test]
    public function any_requires_one_child(): void
    {
        $rule = ['any' => [
            ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
            ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
        ]];

        $this->assertTrue($this->evaluator->evaluate($rule, [
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => true,
        ]));

        $this->assertFalse($this->evaluator->evaluate($rule, [
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => false,
        ]));
    }

    #[Test]
    public function not_inverts(): void
    {
        $rule = ['not' => ['fact' => 'engagement.transfer_basis', 'op' => 'in', 'value' => ['bcr', 'scc', 'certification']]];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.transfer_basis' => 'none']));
        $this->assertFalse($this->evaluator->evaluate($rule, ['engagement.transfer_basis' => 'scc']));
    }

    #[Test]
    public function the_trd_example_rule_evaluates_both_ways(): void
    {
        // Verbatim from TRD §9.2 — the worked example in the specification,
        // pinned so that a change to the evaluator that breaks the document's
        // own illustration cannot pass.
        $rule = ['all' => [
            ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
            ['any' => [
                ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
                ['fact' => 'answer.Q-DATA-07', 'op' => 'in', 'value' => ['yes', 'partial']],
            ]],
            ['not' => ['fact' => 'engagement.transfer_basis', 'op' => 'in', 'value' => ['bcr', 'scc', 'certification']]],
        ]];

        // Personal data, cross-border, no recorded lawful basis: KO-PII-XB.
        $this->assertTrue($this->evaluator->evaluate($rule, [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => true,
            'engagement.transfer_basis' => 'none',
        ]));

        // The same engagement once standard contractual clauses are recorded.
        $this->assertFalse($this->evaluator->evaluate($rule, [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => true,
            'engagement.transfer_basis' => 'scc',
        ]));

        // Not cross-border, but the questionnaire says data leaves Nigeria.
        $this->assertTrue($this->evaluator->evaluate($rule, [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => false,
            'answer.Q-DATA-07' => 'partial',
            'engagement.transfer_basis' => 'none',
        ]));
    }

    #[Test]
    public function deep_nesting_evaluates_correctly(): void
    {
        $rule = ['all' => [
            ['any' => [
                ['all' => [
                    ['fact' => 'engagement.cloud_model', 'op' => 'ne', 'value' => 'none'],
                    ['not' => ['fact' => 'engagement.data_location_at_rest', 'op' => 'eq', 'value' => 'NG']],
                ]],
                ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
            ]],
            ['fact' => 'engagement.effective_tier', 'op' => 'in', 'value' => ['high', 'critical']],
        ]];

        $this->assertTrue($this->evaluator->evaluate($rule, [
            'engagement.cloud_model' => 'saas',
            'engagement.data_location_at_rest' => 'IE',
            'engagement.pci_in_scope' => false,
            'engagement.effective_tier' => 'critical',
        ]));

        // Same engagement, data resident in Nigeria and not PCI: the inner
        // `any` fails, so the whole rule does.
        $this->assertFalse($this->evaluator->evaluate($rule, [
            'engagement.cloud_model' => 'saas',
            'engagement.data_location_at_rest' => 'NG',
            'engagement.pci_in_scope' => false,
            'engagement.effective_tier' => 'critical',
        ]));
    }

    #[Test]
    public function non_array_children_are_ignored_rather_than_fatal(): void
    {
        $rule = ['all' => [
            ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
            'a stray string',
        ]];

        $this->assertTrue($this->evaluator->evaluate($rule, ['engagement.cross_border' => true]));
    }

    /* ------------------------------------------------------------------ */
    /*  The fact registry                                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_registry_admits_whitelisted_facts_and_answers_only(): void
    {
        $this->assertTrue(FactRegistry::allows('engagement.cross_border'));
        $this->assertTrue(FactRegistry::allows('third_party.status'));
        $this->assertTrue(FactRegistry::allows('answer.Q-DATA-07'));

        $this->assertFalse(FactRegistry::allows('answer.'));
        $this->assertFalse(FactRegistry::allows('engagement.made_up_column'));
    }

    #[Test]
    public function the_registry_admits_no_secret_bearing_fact(): void
    {
        // The whitelist exists so a stored, exportable rule cannot name a
        // credential. Asserted rather than trusted, because the failure mode
        // is a rule builder quietly offering `credentials` in a dropdown.
        foreach (['password', 'mfa_secret', 'credentials', 'token_hash', 'remember_token'] as $secret) {
            foreach (FactRegistry::names() as $fact) {
                $this->assertStringNotContainsString(
                    $secret,
                    $fact,
                    "The fact registry exposes something named after `{$secret}`."
                );
            }
        }
    }

    #[Test]
    public function facts_used_by_walks_the_whole_tree(): void
    {
        $rule = ['all' => [
            ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
            ['any' => [
                ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
                ['fact' => 'answer.Q-DATA-07', 'op' => 'in', 'value' => ['yes']],
            ]],
            ['not' => ['fact' => 'engagement.transfer_basis', 'op' => 'in', 'value' => ['scc']]],
        ]];

        $this->assertSame([
            'engagement.processes_personal_data',
            'engagement.cross_border',
            'answer.Q-DATA-07',
            'engagement.transfer_basis',
        ], FactRegistry::factsUsedBy($rule));
    }

    #[Test]
    public function extract_keeps_a_null_whitelisted_fact_as_present(): void
    {
        $context = FactRegistry::extract(
            ['cross_border' => true, 'transfer_basis' => null],
            ['engagement.cross_border' => 'cross_border', 'engagement.transfer_basis' => 'transfer_basis']
        );

        $this->assertTrue($context['engagement.cross_border']);
        $this->assertArrayHasKey('engagement.transfer_basis', $context);
        $this->assertNull($context['engagement.transfer_basis']);
    }

    #[Test]
    public function extract_reads_objects_and_arrays_identically(): void
    {
        // One code path for a unit test's array and production's Eloquent
        // model. Two paths is how a calculator passes its tests and misreads
        // a model.
        $map = ['engagement.cross_border' => 'cross_border'];
        $object = new class
        {
            public bool $cross_border = true;
        };

        $this->assertSame(
            FactRegistry::extract(['cross_border' => true], $map),
            FactRegistry::extract($object, $map)
        );
    }

    #[Test]
    public function answers_are_namespaced(): void
    {
        $this->assertSame(
            ['answer.Q-DATA-07' => 'yes', 'answer.Q-SEC-01' => ['a', 'b']],
            FactRegistry::answers(['Q-DATA-07' => 'yes', 'Q-SEC-01' => ['a', 'b']])
        );
    }
}
