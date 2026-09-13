<?php

namespace Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AC 18 (half one), phase-11a-ai-contract.md §8.18 / ADR 0015 §6d deviation 9.
 *
 * No array under `config/services.php` -> `llm.budgets` may declare a
 * `num_ctx` key, on this file as it stands today or on any budget shape
 * added later. `Risk\AiToolsController::budget()` spreads a resolved budget
 * array wholesale into a direct `LlmService->json()` call, so a `num_ctx`
 * key placed on ANY budget — not just `narrative` — would reach that
 * ungoverned ERM caller and silently pin its context window.
 *
 * Reads the file directly rather than through `config()`, so this guard
 * cannot be satisfied by a test-only `config()->set()` override elsewhere in
 * the suite masking a real leak in the shipped file.
 */
class ServicesLlmBudgetsNoContextWindowTest extends TestCase
{
    #[Test]
    public function no_budget_in_the_shipped_config_file_declares_num_ctx(): void
    {
        $services = require base_path('config/services.php');

        $budgets = (array) ($services['llm']['budgets'] ?? []);

        $this->assertNotEmpty($budgets, 'Expected at least one declared budget to check.');

        $offenders = [];

        foreach ($budgets as $shape => $budget) {
            if (is_array($budget) && array_key_exists('num_ctx', $budget)) {
                $offenders[] = $shape;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A budget declared num_ctx: '.implode(', ', $offenders).'. ADR 0015 §6d deviation 9: '
            .'a budget array is spread wholesale into a direct LlmService call by '
            .'Risk\\AiToolsController, so any num_ctx placed on a budget leaks the declared '
            .'window to ERM\'s ungoverned callers. The window belongs ONLY in config(\'llm.context.num_ctx\').'
        );
    }
}
