<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WP-01 TASK 3 — 50 genuinely concurrent generate() calls must produce 50
 * distinct codes.
 *
 * The rest of the suite runs against an in-memory SQLite database in a single
 * process, where "concurrent" cannot mean anything. This test therefore builds
 * a real database file and drives it from several OS processes at once, so
 * that two workers genuinely can read the same next_value at the same moment.
 *
 * What this proves, and what it does not. SQLite ignores FOR UPDATE
 * (SQLiteGrammar::compileLock() returns an empty string), so the lockForUpdate()
 * in ReferenceCodeService does nothing here. The serialising lock in this test
 * is the transaction plus SQLite's single-writer lock, taken at BEGIN under
 * IMMEDIATE. The test therefore proves: no duplicate and no skipped numbers
 * when allocators are serialised by a writer lock. It does NOT prove MariaDB
 * row-lock semantics; that would need a MariaDB multi-process variant.
 *
 * It is deliberately the only test in the suite that forks processes — it is
 * slow, and it is worth it precisely once, for the invariant that a duplicate
 * reference code on a CBN filing is not recoverable.
 */
class ReferenceCodeConcurrencyTest extends TestCase
{
    private const WORKERS = 5;

    private const CODES_PER_WORKER = 10;

    /**
     * The only shape a worker may print: PREFIX-YEAR-NNNN, as generate() is
     * called by the worker script ('LE', four digits).
     */
    private const CODE_PATTERN = '/^LE-\d{4}-\d{4}$/';

    private string $databasePath;

    private string $workerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/reference-concurrency-'.getmypid().'.sqlite');
        $this->workerPath = storage_path('framework/testing/reference-worker-'.getmypid().'.php');

        File::ensureDirectoryExists(dirname($this->databasePath));
        File::put($this->databasePath, '');
    }

    protected function tearDown(): void
    {
        // WAL leaves -wal and -shm sidecars next to the database file.
        File::delete([
            $this->databasePath,
            $this->databasePath.'-wal',
            $this->databasePath.'-shm',
            $this->workerPath,
        ]);

        parent::tearDown();
    }

    #[Test]
    public function fifty_concurrent_generate_calls_produce_fifty_distinct_codes(): void
    {
        $this->migrateWorkerDatabase();
        $organizationId = $this->seedOrganization();

        File::put($this->workerPath, $this->workerScript());

        $processes = [];
        $pipes = [];

        // A wall-clock instant a couple of seconds out, comfortably after the
        // slowest worker will have finished booting. Every worker sleeps until
        // it, so the first generate() calls land together.
        $barrier = (int) ((microtime(true) + 3.0) * 1_000_000);

        // Started before any is read, so they genuinely overlap. Reading each
        // to completion in turn would serialise them and prove nothing.
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $processes[$worker] = proc_open(
                sprintf(
                    '%s %s %s %d %d %d',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($this->workerPath),
                    escapeshellarg($this->databasePath),
                    $organizationId,
                    self::CODES_PER_WORKER,
                    $barrier
                ),
                $descriptor,
                $pipes[$worker],
                base_path()
            );

            $this->assertIsResource($processes[$worker], "worker {$worker} failed to start");
        }

        $codes = [];

        foreach ($processes as $worker => $process) {
            $stdout = stream_get_contents($pipes[$worker][1]);
            $stderr = stream_get_contents($pipes[$worker][2]);
            fclose($pipes[$worker][1]);
            fclose($pipes[$worker][2]);

            $exitCode = proc_close($process);

            // A worker that failed is reported as a failed worker, with
            // everything it said. Never counted: an exception message printed
            // to STDOUT is not a reference code, and a line count that happens
            // to be off by two says nothing about what actually went wrong.
            $report = "worker {$worker} (exit {$exitCode})\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";

            $this->assertSame(0, $exitCode, "worker {$worker} exited non-zero.\n{$report}");
            $this->assertSame('', trim($stderr), "worker {$worker} wrote to STDERR.\n{$report}");

            $emitted = $stdout === '' ? [] : explode("\n", rtrim($stdout, "\n"));

            foreach ($emitted as $line) {
                $this->assertMatchesRegularExpression(
                    self::CODE_PATTERN,
                    $line,
                    "worker {$worker} emitted a line that is not a reference code: [{$line}].\n{$report}"
                );
            }

            $this->assertCount(
                self::CODES_PER_WORKER,
                $emitted,
                "worker {$worker} emitted ".count($emitted).' codes, expected '.self::CODES_PER_WORKER.".\n{$report}"
            );

            $codes = array_merge($codes, $emitted);
        }

        $expected = self::WORKERS * self::CODES_PER_WORKER;

        $this->assertCount($expected, $codes);
        $this->assertCount(
            $expected,
            array_unique($codes),
            'concurrent workers issued the same reference code more than once: '
            .implode(', ', array_keys(array_filter(array_count_values($codes), fn ($n) => $n > 1)))
        );

        // No gaps either: a counter that skipped numbers would hide a lost
        // allocation rather than a duplicated one, and both are wrong.
        $numbers = array_map(fn (string $code) => (int) substr($code, strrpos($code, '-') + 1), $codes);
        sort($numbers);
        $this->assertSame(range(1, $expected), $numbers);
    }

    private function migrateWorkerDatabase(): void
    {
        $this->runArtisan('migrate --force');

        // WAL once, here — switching journal mode takes an exclusive lock, so
        // five workers all trying it on startup would leave four of them dead
        // before they generated anything. WAL lets the readers proceed while
        // one writer holds the counter, which is what makes the contention
        // this test exists to create actually reach the row lock.
        $previous = $this->swapConnection();
        \Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL');
        $this->restoreConnection($previous);
    }

    private function seedOrganization(): int
    {
        $previous = $this->swapConnection();

        $organizationId = Organization::create([
            'name' => 'Concurrency Bank PLC',
            'short_name' => 'CONC',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ])->id;

        $this->restoreConnection($previous);

        return $organizationId;
    }

    private function runArtisan(string $command): void
    {
        $full = sprintf(
            'DB_CONNECTION=sqlite DB_DATABASE=%s %s artisan %s 2>&1',
            escapeshellarg($this->databasePath),
            escapeshellarg(PHP_BINARY),
            $command
        );

        exec($full, $output, $exitCode);

        $this->assertSame(0, $exitCode, "artisan {$command} failed:\n".implode("\n", $output));
    }

    /**
     * Point the running app at the worker database long enough to seed it.
     *
     * @return array{string, mixed}
     */
    private function swapConnection(): array
    {
        $previous = [config('database.default'), config('database.connections.sqlite.database')];

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        app('db')->purge('sqlite');

        return $previous;
    }

    private function restoreConnection(array $previous): void
    {
        [$default, $database] = $previous;

        config([
            'database.default' => $default,
            'database.connections.sqlite.database' => $database,
        ]);
        app('db')->purge('sqlite');
    }

    /**
     * A standalone worker: boot the framework, generate N codes, print them.
     *
     * Written out rather than kept as a fixture file so it cannot drift from
     * the arguments this test passes it, and so it is obvious that nothing
     * here ships to production.
     */
    private function workerScript(): string
    {
        return <<<'PHP'
<?php

[$script, $database, $organizationId, $count, $barrier] = $argv;

// All three, in this order. Laravel's env repository reads $_SERVER before
// $_ENV before putenv(), and a CLI process inherits PHPUnit's DB_DATABASE
// (":memory:") into $_SERVER — so setting only $_ENV leaves the worker
// pointed at an in-memory database that has no tables in it.
putenv("DB_CONNECTION=sqlite");
putenv("DB_DATABASE={$database}");
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// SQLite serialises writers, so a worker that arrives while another holds the
// write lock must WAIT, as it would on the row lock MariaDB gives us. Two
// settings, both needed:
//
//  - busy_timeout: how long a blocked connection waits for the lock.
//  - transaction_mode IMMEDIATE: take the write lock at BEGIN. Laravel's default
//    is DEFERRED, so the service's transaction starts as a READER (the SELECT),
//    then tries to upgrade to a writer (the UPDATE/INSERT). In WAL mode, if
//    another connection committed in between, that upgrade fails at once with
//    SQLITE_BUSY ("database is locked") and busy_timeout is never consulted,
//    because waiting could not help: the snapshot is already stale. That is the
//    CI failure this replaces — an insert into reference_sequences that died
//    after the service's short retry budget ran out on a slow runner. With
//    IMMEDIATE the contention happens at BEGIN, where busy_timeout does apply,
//    and the SELECT ... UPDATE pair then runs as the SQLite analogue of MariaDB's
//    SELECT ... FOR UPDATE: one allocator at a time, the others queued.
//
// Set through Laravel's own connection options so every connection the worker
// opens gets them, not just whichever one happened to run a PRAGMA.
config([
    'database.connections.sqlite.busy_timeout' => 10000,
    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
]);
Illuminate\Support\Facades\DB::purge('sqlite');

// Line the workers up on a common start, so they contend on the counter
// instead of politely queueing behind one another's framework boot.
usleep(max(0, (int) $barrier - (int) (microtime(true) * 1_000_000)));

for ($i = 0; $i < (int) $count; $i++) {
    echo App\Services\ReferenceCodeService::generate(
        'loss_events',
        'event_reference',
        'LE',
        4,
        (int) $organizationId
    ), "\n";
}
PHP;
    }
}
