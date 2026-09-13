<?php

namespace Tests\Feature\Tprm;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AC 23, phase-11a-ai-contract.md §8.23 / ADR 0015 §10 — a merge condition
 * on the 11a re-gate, not a fourth blocking defect on this pass, but still
 * verified rather than taken on trust.
 *
 * `docs/compliance/ndpa-register-platform.md` must exist and carry the
 * `llm_usage_events` entry the ADR requires, including the "holds no
 * prompt, no response, no document text" statement. And
 * `docs/compliance/ndpa-register.md` — BCMS's own register, mid-remediation
 * under a separate gate — must carry no `llm_usage_events` entry: a platform
 * table does not belong in a module's register, and this is what keeps a
 * future edit from folding the two together informally.
 */
class NdpaPlatformRegisterTest extends TestCase
{
    #[Test]
    public function the_platform_register_exists_and_documents_llm_usage_events(): void
    {
        $path = base_path('docs/compliance/ndpa-register-platform.md');

        $this->assertFileExists($path, 'ADR 0015 §10 requires a platform-scoped NDPA register documenting llm_usage_events.');

        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('llm_usage_events', $content);

        // The first question anyone asks of an "AI usage log" — ADR 0015 §10
        // item 2: "no prompt text, no model response, no document text."
        $this->assertStringContainsString('no prompt text', $content);
        $this->assertStringContainsString('no model response', $content);
        $this->assertMatchesRegularExpression(
            '/not one character of a vendor\s+document/',
            $content,
            'The register must state that not one character of a vendor document is held.'
        );

        // Retention — ADR 0015 §10 item 3.
        $this->assertStringContainsString('24 months', $content);
        $this->assertStringContainsString('PruneLlmUsageEvents', $content);

        // Residency / §41 precondition — ADR 0015 §10 item 4.
        $this->assertStringContainsString('§41', $content);
    }

    #[Test]
    public function the_bcms_register_carries_no_llm_usage_events_entry(): void
    {
        $path = base_path('docs/compliance/ndpa-register.md');

        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            'llm_usage_events',
            $content,
            'docs/compliance/ndpa-register.md is titled and scoped to BCMS (ADR 0015 §10): a platform '
            .'table does not belong in a module\'s register, and it must not be folded in here informally '
            .'while that register is mid-remediation under a separate gate.'
        );
    }
}
