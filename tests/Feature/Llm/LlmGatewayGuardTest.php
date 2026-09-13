<?php

namespace Tests\Feature\Llm;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two structural guards phase-11a-ai-contract.md §2.1 and ADR 0015 §1
 * both name explicitly, and that everything else in this phase depends on:
 *
 *   1. `App\Services\Llm\` never imports `App\Models\Tprm`, `App\Enums\Tprm`,
 *      `App\Models\Bcms` or `App\Enums\Bcms`. This is what lets the platform
 *      gateway answer a tenant-specific question (`availability()`) without
 *      becoming "TPRM with a misleading path" — ADR 0015 §1.
 *   2. No class outside `App\Services\Llm\` references
 *      `App\Services\LlmService`, except the two module clients
 *      (`Tprm\Extraction\LlmClient`, `Bcms\Ai\BcmsLlmClient`). This is what
 *      makes AC-16 enforceable: a caller cannot reach a model except through
 *      one of exactly three doors.
 *   3. `app/Services/Ai/` is never created — ADR 0015 §1, stated so plainly
 *      it gets its own assertion rather than living only in a comment.
 */
class LlmGatewayGuardTest extends TestCase
{
    private const ALLOWED_LLM_SERVICE_CALLERS = [
        'app/Services/Tprm/Extraction/LlmClient.php',
        'app/Services/Bcms/Ai/BcmsLlmClient.php',

        /*
         * PRE-EXISTING, OUT OF PHASE 11A'S STATED SCOPE, NOT A NEW EXCEPTION.
         * ERM's own AI tooling (Phase 11 items 2 and 3 — AssessmentScopingAssistant,
         * NarrativeGenerator, licensing entitlements) is explicitly left untouched by
         * this phase; phase-11a-ai-contract.md §9 names BoardNarrativeWriter as the
         * ONE re-pointed TPRM caller and says nothing about ERM's. Moving these three
         * onto the gateway is real work belonging to whichever phase does consolidate
         * ERM's AI surface — flagged in the Phase 11a handoff for the architect to
         * confirm, rather than silently rewritten here or silently grandfathered
         * forever. This list must shrink, not grow: a fourth direct caller appearing
         * anywhere else is the guard doing its job.
         */
        'app/Http/Controllers/Risk/AiToolsController.php',
        'app/Console/Commands/WarmAiCache.php',
        'app/Console/Commands/WarmLlm.php',
    ];

    #[Test]
    public function the_platform_llm_namespace_imports_no_tprm_or_bcms_class(): void
    {
        $forbidden = ['App\\Models\\Tprm', 'App\\Enums\\Tprm', 'App\\Models\\Bcms', 'App\\Enums\\Bcms'];
        $offenders = [];

        foreach ($this->phpFilesUnder('app/Services/Llm') as $path) {
            // Comments stripped first: this rule's OWN explanatory docblocks
            // name the forbidden namespaces in prose, and a naive substring
            // search would fail every file that documents the rule it obeys.
            $code = $this->stripComments((string) file_get_contents($path));

            foreach ($forbidden as $namespace) {
                if (str_contains($code, $namespace)) {
                    $offenders[] = "{$path} references {$namespace}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    #[Test]
    public function nothing_outside_the_two_module_clients_references_llm_service(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder('app') as $path) {
            $relative = $this->relative($path);

            if (in_array($relative, self::ALLOWED_LLM_SERVICE_CALLERS, true)) {
                continue;
            }

            // LlmService.php itself, and the platform gateway namespace, are
            // exempt as the DEFINITION and the intended sole caller.
            if ($relative === 'app/Services/LlmService.php' || str_starts_with($relative, 'app/Services/Llm/')) {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($path));

            if (preg_match('/\bApp\\\\Services\\\\LlmService\b/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'Found a caller of App\\Services\\LlmService outside the gateway and its two module clients: '.implode(', ', $offenders));
    }

    #[Test]
    public function app_services_ai_directory_does_not_exist(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Services/Ai'), 'ADR 0015 §1: this directory must never be created.');
    }

    /**
     * AC 22, phase-11a-ai-contract.md §8.22 / ADR 0015 §1 amendment.
     *
     * The list must hold exactly five entries — the two governed module
     * clients plus the three named, grandfathered ERM callers — and BOTH
     * directions of drift fail: growth (a fourth direct caller appearing
     * somewhere else, which the guard above would also catch, but not with
     * a message naming the ADR) and shrinkage (an entry quietly removed
     * without the corresponding ADR update this criterion exists to force
     * into the same commit).
     */
    #[Test]
    public function the_grandfathered_caller_allowlist_holds_exactly_five_entries(): void
    {
        $this->assertCount(
            5,
            self::ALLOWED_LLM_SERVICE_CALLERS,
            'ALLOWED_LLM_SERVICE_CALLERS must hold exactly the two governed module clients '
            .'(Tprm\\Extraction\\LlmClient, Bcms\\Ai\\BcmsLlmClient) plus the three ERM callers '
            .'named and costed in ADR 0015 §1\'s amendment (Risk\\AiToolsController, '
            .'WarmAiCache, WarmLlm). Growth is a new ungoverned caller reaching a model outside '
            .'the gateway; shrinkage is a real fix that must update ADR 0015 §1 in the same commit '
            .'— Phase 11 item 2\'s gate does not pass with this count still at 5.'
        );
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
}
