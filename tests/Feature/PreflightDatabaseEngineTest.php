<?php

namespace Tests\Feature;

use App\Console\Commands\Preflight;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `app:preflight` must report the database engine, and its idea of the expected
 * engine must not drift from the pipeline's.
 *
 * The check exists because nothing in a Laravel configuration identifies the
 * engine: `DB_CONNECTION=mysql` names the PDO driver, and MariaDB and MySQL
 * share it. Panels are no better — XAMPP's says "MySQL" over a MariaDB server.
 *
 * Two things are tested here, and the second is the one with teeth. The check
 * warns when the running engine is not in `Preflight::EXPECTED_DB_ENGINES`,
 * so that constant is load-bearing; a constant kept in step with CI by nothing
 * but a comment is the same defect one level up from the one being caught.
 */
class PreflightDatabaseEngineTest extends TestCase
{
    #[Test]
    public function it_reports_the_engine_the_server_actually_is(): void
    {
        $driver = DB::connection()->getDriverName();

        $expectedEngine = match ($driver) {
            'sqlite' => 'SQLite',
            'pgsql' => 'PostgreSQL',
            default => str_contains(strtolower((string) DB::selectOne('select version() as v')->v), 'mariadb')
                ? 'MariaDB'
                : 'MySQL',
        };

        $this->artisan('app:preflight', ['--allow-local' => true])
            ->expectsOutputToContain($expectedEngine);
    }

    #[Test]
    public function it_does_not_fail_merely_because_the_driver_is_not_the_pinned_one(): void
    {
        // The first cut of this check ran `select version()` unconditionally.
        // SQLite has no such function, so preflight FAILED on every SQLite
        // deployment while its own comment claimed it failed "only when the
        // engine cannot be determined at all". SQLite is entirely
        // determinable — the query was simply wrong for it.
        //
        // A wrong engine is a WARNING: which engine an estate runs is not a
        // preflight check's decision. Only an undeterminable one is a failure.
        $this->artisan('app:preflight', ['--allow-local' => true])
            ->doesntExpectOutputToContain('could not read the version');
    }

    #[Test]
    public function the_expected_engines_match_the_images_ci_actually_runs(): void
    {
        $workflow = base_path('.github/workflows/ci.yml');

        $this->assertFileExists($workflow, 'The CI workflow is gone; this guard has nothing to compare against.');

        $yaml = file_get_contents($workflow);

        // EVERY literal image, not the first one. CI runs a matrix — the
        // service reads `${{ matrix.image }}` and the literals live in the
        // matrix entries — and a first-match read here would certify one leg
        // and say nothing about the other. Expressions are skipped: they name
        // a matrix value, not an engine.
        preg_match_all('/image:\s*(\S+)/', $yaml, $m);
        $images = array_values(array_filter(
            array_map('strtolower', $m[1]),
            fn (string $image) => ! str_starts_with($image, '$'),
        ));

        $this->assertNotEmpty($images, 'No literal service image found in ci.yml, so the pin cannot be checked.');

        $enginesInCi = array_values(array_unique(array_map(
            fn (string $image) => str_contains($image, 'mariadb') ? 'MariaDB'
                : (str_contains($image, 'mysql') ? 'MySQL'
                : (str_contains($image, 'postgres') ? 'PostgreSQL' : $image)),
            $images,
        )));
        sort($enginesInCi);

        $expected = Preflight::EXPECTED_DB_ENGINES;
        sort($expected);

        $this->assertSame(
            $expected,
            $enginesInCi,
            sprintf(
                "Preflight expects [%s] but ci.yml runs [%s].\n".
                'These must move together: preflight warns operators when the running engine is not one CI runs, '.
                'so a stale list makes it warn about the wrong thing — or stay silent when it should not.',
                implode(', ', $expected),
                implode(', ', $enginesInCi)
            )
        );
    }
}
