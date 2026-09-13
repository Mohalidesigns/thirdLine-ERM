<?php

namespace Tests\Unit\Tprm\Ai;

use App\Services\Tprm\Extraction\PromptRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AC 20, phase-11a-ai-contract.md §4.3 / §8.20 — tightened to EQUALITY, not
 * "at or below".
 *
 * Re-derives every `max_document_chars` in the SHIPPED `config/tprm_prompts.php`
 * from `config('llm.context.num_ctx')`, the prompt's own budget (via
 * `LlmClient::budgetFor()`'s mapping), 3.5 chars/token and the prompt's
 * measured overhead, and asserts each literal EQUALS the derived value
 * floored to the nearest hundred.
 *
 * An inequality ("at or below") would pass silently over a literal that is
 * quietly smaller than its own formula for no recorded reason — exactly the
 * arithmetic slip that shipped `soc2` at 3,500 in the first draft (corrected
 * to 3,700, phase-11a-ai-contract.md §4.3). Equality is what makes that class
 * of mistake fail the build instead of just this one instance of it.
 *
 * Reads the file directly (`require base_path(...)`), not through `config()`,
 * so a test-only override elsewhere in the suite cannot mask a real drift in
 * the shipped file.
 */
class MaxDocumentCharsFormulaTest extends TestCase
{
    private const CHARS_PER_TOKEN = 3.5;

    // The two delimiter lines plus the blank-line joins `renderWithMeta()`
    // inserts between the instructions, the delimiters and the document —
    // ADR 0015 §6d / contract §4.3: "120 covers the two delimiter lines and
    // the blank lines renderWithMeta() joins with."
    private const FIXED_OVERHEAD = 120;

    /**
     * `LlmClient::budgetFor()`'s mapping, restated here rather than invoked
     * through the class, because this guard must be able to derive a cap for
     * a prompt with NO caller yet (a new prompt key with no extractor wired)
     * without constructing the full service graph — the whole point of a
     * config-level guard is that it runs on the config alone.
     */
    private const BUDGET_FOR_PROMPT = [
        'board_narrative' => 'narrative',
    ];

    #[Test]
    public function every_max_document_chars_literal_equals_its_derivation(): void
    {
        $numCtx = (int) (require base_path('config/llm.php'))['context']['num_ctx'];
        $budgets = (require base_path('config/services.php'))['llm']['budgets'];
        $prompts = require base_path('config/tprm_prompts.php');

        $this->assertNotEmpty($prompts, 'Expected at least one prompt to check.');

        $mismatches = [];

        foreach ($prompts as $key => $prompt) {
            $budgetKey = self::BUDGET_FOR_PROMPT[$key] ?? 'extraction';
            $maxTokens = (int) $budgets[$budgetKey]['max_tokens'];

            $overhead = mb_strlen($prompt['system'])
                + mb_strlen($prompt['instructions'])
                + mb_strlen($this->vendorDataFooter())
                + self::FIXED_OVERHEAD;

            $derived = floor((($numCtx - $maxTokens) * self::CHARS_PER_TOKEN) - $overhead);
            $flooredToHundred = (int) floor($derived / 100) * 100;

            $stored = $prompt['max_document_chars'] ?? null;

            if ($stored !== $flooredToHundred) {
                $mismatches[] = sprintf(
                    '%s: stored=%s derived=%d (overhead=%d, budget=%s/%d)',
                    $key,
                    $stored === null ? 'MISSING' : $stored,
                    $flooredToHundred,
                    $overhead,
                    $budgetKey,
                    $maxTokens
                );
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            'max_document_chars must EQUAL floor((num_ctx - budget.max_tokens) * 3.5) - overhead, floored to the '.
            "nearest hundred — not \"at or below\". Mismatches:\n".implode("\n", $mismatches)
        );
    }

    /**
     * The literal, exact text `PromptRegistry::renderWithMeta()` appends
     * after the document — read from `PromptRegistry::VENDOR_DATA_FOOTER`
     * rather than retyped here, so this test and the registry cannot
     * silently disagree about what the footer says.
     */
    private function vendorDataFooter(): string
    {
        return PromptRegistry::VENDOR_DATA_FOOTER;
    }
}
