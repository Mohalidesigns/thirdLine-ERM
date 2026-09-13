<?php

namespace Tests\Feature\Llm;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AC 26, phase-11a-ai-contract.md §8.26 / ADR 0015 §6e.
 *
 * Two blunt, mechanical guards, in `LlmGatewayGuardTest`'s own shape:
 *
 *   (a) `PromptRegistry::renderKey()` discards the truncation fact by
 *       construction (it returns only the `text` half of `renderWithMeta()`'s
 *       array). The set of files allowed to call it is an asserted-count
 *       allowlist — today exactly `app/Services/Tprm/Extraction/LlmClient.php`
 *       — so growth AND shrinkage both fail.
 *   (b) Every file that calls `renderWithMeta()` must also contain the
 *       literal string `document_truncated` — the grep that would have
 *       failed on `ClauseAnalyzer`'s first render call, per ADR 0015 §6e.
 *
 * Plus the wording rule §7.5 always governed, extended by §2.6.3 to the two
 * artefacts outside AC 21's own grep (`Show.jsx` for extractions): neither
 * `resources/js/Pages/Tprm/Contracts/Show.jsx` nor
 * `resources/views/reports/pdf/tprm-clause-gap.blade.php` may say "read in
 * full" or "the model read", in any state.
 */
class RenderKeyDiscardAllowlistTest extends TestCase
{
    private const ALLOWED_RENDER_KEY_CALLERS = [
        'app/Services/Tprm/Extraction/LlmClient.php',
    ];

    #[Test]
    public function renderkey_is_called_from_exactly_the_one_file_that_genuinely_does_not_need_the_truncation_fact(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder('app') as $path) {
            $relative = $this->relative($path);

            // PromptRegistry.php defines renderKey() (and renderKey() calling
            // renderWithMeta() internally, which is the definition, not a
            // caller of it).
            if ($relative === 'app/Services/Tprm/Extraction/PromptRegistry.php') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($path));

            if (str_contains($code, '->renderKey(')) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);
        $expected = self::ALLOWED_RENDER_KEY_CALLERS;
        sort($expected);

        $this->assertSame(
            $expected,
            $offenders,
            'renderKey() discards the truncation fact renderWithMeta() reports (ADR 0015 §6e). '
            .'Its callers must be exactly '.implode(', ', $expected).'. Found: '.implode(', ', $offenders)
        );
    }

    #[Test]
    public function the_render_key_allowlist_holds_exactly_one_entry(): void
    {
        $this->assertCount(
            1,
            self::ALLOWED_RENDER_KEY_CALLERS,
            'ADR 0015 §6e: renderKey() callers must be exactly one file. Growth is a new caller '
            .'silently discarding the truncation fact; shrinkage is a real fix that should be reflected here.'
        );
    }

    #[Test]
    public function every_file_calling_render_with_meta_also_names_document_truncated(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder('app') as $path) {
            $relative = $this->relative($path);
            $code = $this->stripComments((string) file_get_contents($path));

            if (! str_contains($code, '->renderWithMeta(')) {
                continue;
            }

            $raw = (string) file_get_contents($path);

            if (! str_contains($raw, 'document_truncated')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A file calls PromptRegistry::renderWithMeta() but never mentions document_truncated — '.
            'exactly the shape of the defect that shipped with ClauseAnalyzer\'s first render call '.
            '(ADR 0015 §6e): '.implode(', ', $offenders)
        );
    }

    #[Test]
    public function the_contracts_screen_never_claims_the_model_read_the_document_in_full(): void
    {
        $source = $this->stripJsComments((string) file_get_contents(
            base_path('resources/js/Pages/Tprm/Contracts/Show.jsx')
        ));

        $this->assertStringNotContainsStringIgnoringCase('read in full', $source);
        $this->assertStringNotContainsStringIgnoringCase('the model read', $source);
    }

    #[Test]
    public function the_clause_gap_pdf_never_claims_the_model_read_the_document_in_full(): void
    {
        $source = (string) file_get_contents(
            base_path('resources/views/reports/pdf/tprm-clause-gap.blade.php')
        );

        $this->assertStringNotContainsStringIgnoringCase('read in full', $source);
        $this->assertStringNotContainsStringIgnoringCase('the model read', $source);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $relativeDir): array
    {
        $dir = base_path($relativeDir);

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $absolutePath): string
    {
        return ltrim(str_replace(base_path(), '', $absolutePath), '/');
    }

    private function stripComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function stripJsComments(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
        $source = preg_replace('#//[^\n]*#', '', $source) ?? $source;

        return $source;
    }
}
