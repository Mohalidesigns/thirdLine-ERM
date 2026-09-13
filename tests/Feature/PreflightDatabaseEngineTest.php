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
 * warns when the running engine differs from `Preflight::EXPECTED_DB_ENGINE`,
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
    public function the_expected_engine_matches_the_image_ci_actually_runs(): void
    {
        $workflow = base_path('.github/workflows/ci.yml');

        $this->assertFileExists($workflow, 'The CI workflow is gone; this guard has nothing to compare against.');

        $yaml = file_get_contents($workflow);

        $this->assertMatchesRegularExpression(
            '/image:\s*\S+/',
            $yaml,
            'No service image found in ci.yml, so the pin cannot be checked.'
        );

        preg_match('/image:\s*(\S+)/', $yaml, $m);
        $image = strtolower($m[1]);

        $engineInCi = str_contains($image, 'mariadb') ? 'MariaDB'
            : (str_contains($image, 'mysql') ? 'MySQL'
            : (str_contains($image, 'postgres') ? 'PostgreSQL' : $image));

        $this->assertSame(
            Preflight::EXPECTED_DB_ENGINE,
            $engineInCi,
            sprintf(
                "Preflight expects [%s] but ci.yml runs [%s].\n".
                'These must move together: preflight warns operators when the running engine differs from the pin, '.
                'so a stale pin makes it warn about the wrong thing — or stay silent when it should not.',
                Preflight::EXPECTED_DB_ENGINE,
                $engineInCi
            )
        );
    }
}
