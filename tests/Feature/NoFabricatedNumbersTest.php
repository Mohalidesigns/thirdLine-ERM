<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mirrors scripts/check-no-rng.sh inside the test suite.
 *
 * The shell script is what CI runs; this is what a developer trips over before
 * they push. Both exist because the failure mode they guard against — a number
 * shown to a bank that nothing computed — is not something code review reliably
 * catches once the call site is buried in a 400-line service.
 */
class NoFabricatedNumbersTest extends TestCase
{
    /**
     * Directories whose output reaches a user.
     */
    private const SEARCH_PATHS = [
        'app/Http/Controllers',
        'app/Services',
        'app/Jobs',
        'resources/views',
    ];

    /**
     * Files permitted to call an RNG, and why.
     */
    private const ALLOWLIST = [
        // Poisson and Box-Muller variates. Seeded through Random\Randomizer so
        // a VaR figure stays reproducible; the seed is stored on the run.
        'app/Services/MonteCarloService.php',
    ];

    private const PATTERN = '/\b(mt_rand|rand|random_int|shuffle|str_shuffle|array_rand|uniqid)\s*\(/';

    #[Test]
    public function no_random_number_generator_appears_in_user_facing_code(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }

            foreach (file($file) as $number => $line) {
                if (! preg_match(self::PATTERN, $line)) {
                    continue;
                }

                // A comment explaining why an RNG is absent is not a call.
                if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d — %s', $relative, $number + 1, trim($line));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Random number generation found in code that produces user-facing figures.\n\n"
            .implode("\n", $offenders)
            ."\n\nIf a number is not computed from real data, it does not ship. A simulation "
            .'engine that genuinely needs an RNG must use a seedable generator, persist its '
            .'seed, and be added to the allowlist in this test and in scripts/check-no-rng.sh.'
        );
    }

    #[Test]
    public function the_allowlisted_simulation_engine_uses_a_seedable_generator(): void
    {
        // Allowlisting a file is only defensible while that file remains
        // reproducible. If MonteCarloService ever drops back to a global
        // mt_rand() stream, the allowlist entry becomes a hole.
        $source = file_get_contents(base_path('app/Services/MonteCarloService.php'));

        $this->assertStringContainsString(
            'Random\Randomizer',
            $source,
            'The allowlisted simulation engine must use an explicitly seeded Randomizer.'
        );
        $this->assertStringNotContainsString(
            'mt_rand(',
            preg_replace('/\/\*.*?\*\//s', '', $source),
            'The allowlisted simulation engine must not call the global mt_rand() stream.'
        );
    }

    #[Test]
    public function no_hardcoded_model_performance_metrics_remain(): void
    {
        // The Predictive screen used to publish accuracy 87.3, precision 84.1,
        // recall 89.7, f1 86.8 and auc_roc 0.912 — all constants, none measured.
        // There is no model behind that screen now, so none of these keys
        // should exist anywhere in the application code.
        $banned = ['auc_roc', 'model_version', 'training_samples', 'f1_score'];
        $offenders = [];

        foreach ($this->phpFilesUnder(['app', 'resources/views']) as $file) {
            $contents = file_get_contents($file);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            foreach ($banned as $key) {
                if (str_contains($contents, $key)) {
                    $offenders[] = "{$relative} references '{$key}'";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Hardcoded model-performance metrics found.\n\n".implode("\n", $offenders)
        );
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
