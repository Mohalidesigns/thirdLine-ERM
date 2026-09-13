<?php

namespace Tests\Unit\Tprm\Ai;

use App\Services\Tprm\Extraction\PromptRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * AC 25, phase-11a-ai-contract.md §8.25 / ADR 0015 §6e change 2.
 *
 * `PromptRegistry::renderWithMeta()` must REFUSE a configured prompt whose
 * `max_document_chars` is absent, null, zero or negative, naming the key —
 * rather than falling back to `truncate()`'s own `$cap <= 0` branch, which
 * silently sends the whole document and reports `document_truncated` as
 * null. That is the exact false negative ADR 0015 §6d exists to prevent,
 * reachable here by omission rather than by argument. AC 20's guard test
 * only re-derives the caps that ARE present in the shipped config; this is
 * the property-of-the-code half for a prompt that ships with none at all.
 */
class PromptRegistryMissingCapRefusalTest extends TestCase
{
    #[Test]
    public function an_absent_cap_is_refused_not_sent_uncapped(): void
    {
        config()->set('tprm_prompts.no_cap_prompt', [
            'version' => 'no_cap_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
            // max_document_chars deliberately absent.
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Prompt 'no_cap_prompt' has no usable max_document_chars");

        (new PromptRegistry)->renderWithMeta('no_cap_prompt', 'any document text');
    }

    #[Test]
    public function a_null_cap_is_refused(): void
    {
        config()->set('tprm_prompts.null_cap_prompt', [
            'version' => 'null_cap_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
            'max_document_chars' => null,
        ]);

        $this->expectException(RuntimeException::class);

        (new PromptRegistry)->renderWithMeta('null_cap_prompt', 'any document text');
    }

    #[Test]
    public function a_zero_cap_is_refused(): void
    {
        config()->set('tprm_prompts.zero_cap_prompt', [
            'version' => 'zero_cap_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
            'max_document_chars' => 0,
        ]);

        $this->expectException(RuntimeException::class);

        (new PromptRegistry)->renderWithMeta('zero_cap_prompt', 'any document text');
    }

    #[Test]
    public function a_negative_cap_is_refused(): void
    {
        config()->set('tprm_prompts.negative_cap_prompt', [
            'version' => 'negative_cap_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
            'max_document_chars' => -100,
        ]);

        $this->expectException(RuntimeException::class);

        (new PromptRegistry)->renderWithMeta('negative_cap_prompt', 'any document text');
    }

    #[Test]
    public function renderkey_inherits_the_same_refusal_since_it_only_wraps_renderwithmeta(): void
    {
        config()->set('tprm_prompts.no_cap_prompt', [
            'version' => 'no_cap_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
        ]);

        $this->expectException(RuntimeException::class);

        (new PromptRegistry)->renderKey('no_cap_prompt', 'any document text');
    }

    #[Test]
    public function every_prompt_actually_shipped_in_config_has_a_usable_cap(): void
    {
        // The property this AC protects, proven against the REAL shipped
        // file rather than only against a synthetic fixture: every prompt
        // config/tprm_prompts.php ships today must render without throwing.
        $registry = new PromptRegistry;
        $prompts = require base_path('config/tprm_prompts.php');

        foreach (array_keys($prompts) as $key) {
            $result = $registry->renderWithMeta($key, 'some document text');
            $this->assertArrayHasKey('text', $result, "Prompt '{$key}' rendered no text.");
        }
    }
}
