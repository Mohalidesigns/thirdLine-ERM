<?php

namespace Tests\Unit\Tprm\Ai;

use App\Services\Tprm\Extraction\PromptRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0015 §6b — document text is capped, and truncation is DECLARED, never
 * silent.
 */
class PromptRegistryTruncationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tprm_prompts.test_prompt', [
            'version' => 'test_prompt.v1',
            'system' => 'system prompt',
            'instructions' => 'instructions',
            'max_document_chars' => 50,
            'low_trust_fields' => ['some_field'],
        ]);
    }

    #[Test]
    public function a_document_under_the_cap_is_not_truncated(): void
    {
        $registry = new PromptRegistry;

        $result = $registry->renderWithMeta('test_prompt', 'short document');

        $this->assertNull($result['document_truncated']);
        $this->assertStringContainsString('short document', $result['text']);
    }

    #[Test]
    public function a_document_over_the_cap_is_truncated_and_the_fact_is_declared(): void
    {
        $registry = new PromptRegistry;

        $paragraph1 = str_repeat('a', 30);
        $paragraph2 = str_repeat('b', 30);
        $document = $paragraph1."\n\n".$paragraph2;

        $result = $registry->renderWithMeta('test_prompt', $document);

        $this->assertNotNull($result['document_truncated']);
        $this->assertSame(50, $result['document_truncated']['cap']);
        $this->assertSame(mb_strlen($document), $result['document_truncated']['original_length']);

        // Cut at the paragraph boundary, not mid-word: the second paragraph
        // (all "b"s) must not appear at all in the rendered text.
        $this->assertStringContainsString($paragraph1, $result['text']);
        $this->assertStringNotContainsString('bbb', $result['text']);
    }

    #[Test]
    public function low_trust_fields_travel_with_the_render(): void
    {
        $registry = new PromptRegistry;

        $result = $registry->renderWithMeta('test_prompt', 'text');

        $this->assertSame(['some_field'], $result['low_trust_fields']);
    }

    #[Test]
    public function render_key_still_returns_a_plain_string_for_callers_that_do_not_need_meta(): void
    {
        $registry = new PromptRegistry;

        $text = $registry->renderKey('test_prompt', 'text');

        $this->assertStringContainsString('text', $text);
    }

    #[Test]
    public function a_document_with_no_line_breaks_within_the_cap_is_hard_cut_at_the_cap(): void
    {
        $registry = new PromptRegistry;

        $document = str_repeat('x', 200);

        $result = $registry->renderWithMeta('test_prompt', $document);

        $this->assertNotNull($result['document_truncated']);
        $this->assertSame(50, $result['document_truncated']['cap']);
        $this->assertSame(200, $result['document_truncated']['original_length']);
        // Exactly 50 "x"s, no more — a hard cut at the cap with no break to
        // find, and no run of 51 in the rendered text.
        $this->assertStringNotContainsString(str_repeat('x', 51), $result['text']);
        $this->assertStringContainsString(str_repeat('x', 50), $result['text']);
    }
}
