<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the one rule `tests/TestCase::tearDown()` says exists and, until now,
 * did not: no unguarded call anywhere in `app/` mutates a process-global PHP
 * setting.
 *
 * WHY THIS MATTERS ON THIS SUITE SPECIFICALLY. `php artisan test` runs every
 * test in ONE PHP process. `max_execution_time` is not per-request under the
 * CLI SAPI the way it is under FPM — it is a single countdown shared by
 * everything that runs afterwards. `Risk\AiToolsController` used to call a
 * bare `set_time_limit(120)` in seven actions; one test that posted to one of
 * them and waited ~33 seconds on a real model was enough to leave every
 * later, unrelated test racing a 120-second deadline restarted from that
 * moment, and the suite died of "Maximum execution time exceeded" nowhere
 * near the code that caused it. `TestCase::tearDown()` now resets the limit
 * after every test as a NET, but a net is not a fix — it bounds the blast
 * radius to one test rather than preventing the mutation, and it is silent by
 * design, which is exactly why it must not be the only thing standing between
 * this defect and the next controller that reaches for `set_time_limit()`.
 *
 * THE ONE PERMITTED CALL IS GUARDED, NOT BARE. `AiToolsController::
 * raiseRequestTimeLimit()` reads `ini_get('max_execution_time')` first and
 * only raises the limit where a finite one already exists — under the CLI
 * SAPI, where it is 0 (unlimited), the guard's `$current > 0` is false and
 * the call never fires. That is the shape this test requires of anything on
 * the allowlist: read the current value, act only conditionally on it, never
 * call `set_time_limit()` or `ini_set('max_execution_time', ...)` unconditionally.
 */
class ProcessGlobalMutationGuardTest extends TestCase
{
    private const SEARCH_PATHS = ['app'];

    /**
     * Files permitted to touch `max_execution_time`, and why.
     */
    private const ALLOWLIST = [
        // Guarded, not bare (see class docblock): raises the limit only where
        // `ini_get('max_execution_time')` reports a finite, smaller one — the
        // CLI SAPI's 0 (unlimited) leaves the guard's condition false and the
        // mutation never fires, which is what makes it safe for `php artisan
        // test`'s single shared process.
        'app/Http/Controllers/Risk/AiToolsController.php',
    ];

    private const MUTATION_PATTERN = '/\b(set_time_limit\s*\(|ini_set\s*\(\s*[\'"]max_execution_time[\'"])/';

    #[Test]
    public function no_unguarded_file_mutates_the_process_global_execution_time_limit(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = $this->relativePath($file);

            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }

            foreach ($this->codeLines($file) as $number => $line) {
                if (preg_match(self::MUTATION_PATTERN, $line)) {
                    $offenders[] = sprintf('%s:%d — %s', $relative, $number, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A process-global execution time limit is mutated outside the one guarded, allowlisted site.\n\n"
            .implode("\n", $offenders)
            ."\n\n`php artisan test` runs the whole suite in one PHP process, so `max_execution_time` is "
            .'shared rather than per-request: a bare set_time_limit() in a controller introduces a deadline '
            .'restarted from that moment and inherited by every test that runs afterwards, which killed '
            .'unrelated tests with "Maximum execution time exceeded" the first time this shipped. Read the '
            .'current value with ini_get() and act on it conditionally, the way '
            .'AiToolsController::raiseRequestTimeLimit() does, or add the file to this test\'s allowlist with '
            .'the same justification and raise the count in the_allowlist_has_not_grown().'
        );
    }

    /**
     * Meta-test, mirroring NoFabricatedNumbersTest and RouteAuthorizationTest:
     * growing the allowlist is a deliberate act that has to be justified in
     * the same commit, not a way to make a true positive go quiet.
     */
    #[Test]
    public function the_allowlist_has_not_grown(): void
    {
        $this->assertCount(
            1,
            self::ALLOWLIST,
            'A file was added to the process-global execution-time allowlist. The bar is that the '
            .'mutation is GUARDED — conditional on the current, real value read with ini_get() — never '
            .'a bare set_time_limit() or ini_set() that fires unconditionally.'
        );
    }

    /**
     * File lines, 1-indexed, with comment-only lines removed — the docblock
     * that explains this very defect quotes `set_time_limit(120)` verbatim,
     * and scanning raw content would flag the explanation as the thing it
     * explains.
     *
     * @return array<int, string>
     */
    private function codeLines(string $file): array
    {
        $lines = [];

        foreach (file($file) as $index => $line) {
            if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line)) {
                continue;
            }

            $lines[$index + 1] = $line;
        }

        return $lines;
    }

    private function relativePath(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFilesUnder(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
