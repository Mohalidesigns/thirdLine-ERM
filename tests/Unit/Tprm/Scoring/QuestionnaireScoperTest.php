<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Services\Tprm\Assessment\QuestionnaireScoper;
use App\Support\Tprm\RuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FR-ASM-03 — "scoping must be provable: the system records why each question
 * was or was not asked".
 *
 * The trace is what a supervisor reads when it asks what happened to the other
 * hundred and sixty questions, so the tests are mostly about the trace rather
 * than the question list.
 */
class QuestionnaireScoperTest extends TestCase
{
    private QuestionnaireScoper $scoper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoper = new QuestionnaireScoper(new RuleEvaluator);
    }

    #[Test]
    public function a_question_with_no_rule_is_always_asked(): void
    {
        $result = $this->scoper->scope($this->template(), []);

        $this->assertTrue($result->includes('GEN-01'));
        $this->assertSame('always', $result->trace['GEN-01']['decided_by']);
    }

    #[Test]
    public function a_question_rule_includes_and_excludes_on_the_engagement(): void
    {
        $withPci = $this->scoper->scope($this->template(), ['engagement.pci_in_scope' => true]);
        $withoutPci = $this->scoper->scope($this->template(), ['engagement.pci_in_scope' => false]);

        $this->assertTrue($withPci->includes('PCI-Q1'));
        $this->assertFalse($withoutPci->includes('PCI-Q1'));
        $this->assertSame('question_rule', $withoutPci->trace['PCI-Q1']['decided_by']);
    }

    #[Test]
    public function a_section_rule_short_circuits_its_questions_with_one_reason(): void
    {
        // Fifteen identical question-level entries is a wall a reader skims;
        // "the section did not apply" is a sentence they understand.
        $result = $this->scoper->scope($this->template(), ['engagement.processes_personal_data' => false]);

        foreach (['DP-Q1', 'DP-Q2'] as $code) {
            $this->assertFalse($result->includes($code));
            $this->assertSame('section_rule', $result->trace[$code]['decided_by']);
            $this->assertStringContainsString('does not apply', $result->trace[$code]['reason']);
        }
    }

    #[Test]
    public function a_visible_section_still_lets_its_question_rules_decide(): void
    {
        $result = $this->scoper->scope($this->template(), [
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => false,
        ]);

        $this->assertTrue($result->includes('DP-Q1'));
        // DP-Q2 is the cross-border one.
        $this->assertFalse($result->includes('DP-Q2'));
        $this->assertSame('question_rule', $result->trace['DP-Q2']['decided_by']);
    }

    #[Test]
    public function an_unresolvable_rule_asks_the_question_and_says_why(): void
    {
        // Excluding on missing data is how a questionnaire quietly stops
        // asking about the thing nobody filled in — which is the thing most
        // worth asking about.
        $result = $this->scoper->scope($this->template(), []);

        $this->assertTrue($result->includes('PCI-Q1'));
        $this->assertSame('unresolved_fact', $result->trace['PCI-Q1']['decided_by']);
        $this->assertStringContainsString('has not recorded', $result->trace['PCI-Q1']['reason']);
        $this->assertNotEmpty($result->unresolvedFacts);
    }

    #[Test]
    public function the_trace_covers_every_question_in_the_template_not_only_the_included_ones(): void
    {
        // The excluded half is the only half anybody will ever query.
        $result = $this->scoper->scope($this->template(), [
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => false,
        ]);

        $this->assertSame(5, $result->totalCount());
        $this->assertSame(2, $result->includedCount());
        $this->assertSame(3, $result->excludedCount());
        $this->assertCount(5, $result->trace);
    }

    #[Test]
    public function exclusions_are_grouped_by_reason_for_the_explanation_panel(): void
    {
        $result = $this->scoper->scope($this->template(), [
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => false,
        ]);

        $grouped = $result->exclusionsByReason();

        $this->assertCount(2, $grouped);
        $sectionReason = collect($grouped)->keys()->first(fn (string $r) => str_contains($r, 'does not apply'));
        $this->assertCount(2, $grouped[$sectionReason]);
    }

    #[Test]
    public function a_low_tier_engagement_gets_a_materially_smaller_set_than_a_critical_one(): void
    {
        // The phase's own acceptance: a Low-tier SaaS engagement scoped against
        // a pack receives a materially smaller question set than a Critical
        // one, and the trace explains every exclusion.
        $low = $this->scoper->scope($this->template(), [
            'engagement.pci_in_scope' => false,
            'engagement.processes_personal_data' => false,
            'engagement.cross_border' => false,
        ]);

        $critical = $this->scoper->scope($this->template(), [
            'engagement.pci_in_scope' => true,
            'engagement.processes_personal_data' => true,
            'engagement.cross_border' => true,
        ]);

        $this->assertLessThan($critical->includedCount(), $low->includedCount());
        $this->assertSame(5, $critical->includedCount());
        $this->assertSame(2, $low->includedCount());

        // Every exclusion is explained.
        foreach ($low->trace as $code => $entry) {
            $this->assertNotSame('', $entry['reason'], "{$code} was traced with no reason.");
        }
    }

    #[Test]
    public function the_trace_keeps_the_rule_that_decided_so_it_can_be_re_read_later(): void
    {
        $result = $this->scoper->scope($this->template(), ['engagement.pci_in_scope' => false]);

        $this->assertSame(
            ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
            $result->trace['PCI-Q1']['rule']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function template(): array
    {
        return [
            [
                'code' => 'general',
                'visibility_rule' => null,
                'questions' => [
                    ['code' => 'GEN-01'],
                    ['code' => 'GEN-02'],
                ],
            ],
            [
                'code' => 'pci',
                'visibility_rule' => null,
                'questions' => [
                    ['code' => 'PCI-Q1', 'visibility_rule' => ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true]],
                ],
            ],
            [
                'code' => 'data_protection',
                'visibility_rule' => ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
                'questions' => [
                    ['code' => 'DP-Q1'],
                    ['code' => 'DP-Q2', 'visibility_rule' => ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true]],
                ],
            ],
        ];
    }
}
