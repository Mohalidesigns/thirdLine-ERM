<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0029 section 3b: preflight refuses a deploy whose database session is not
 * UTC. This is the check that matters on every FUTURE deploy: a config cache
 * that predates the pin would read every TIMESTAMP through the server's zone.
 */
class PreflightDatabaseTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::statement("SET time_zone = '+00:00'");

        parent::tearDown();
    }

    /** @return array{0: int, 1: string} exit code and the check's row */
    private function preflightRow(): array
    {
        $exit = Artisan::call('app:preflight', ['--allow-local' => true]);

        $row = collect(explode("\n", Artisan::output()))
            ->first(fn (string $line) => str_contains($line, 'Database time zone'));

        $this->assertNotNull($row, 'Preflight no longer reports a "Database time zone" check.');

        return [$exit, $row];
    }

    #[Test]
    public function a_utc_session_with_the_conversion_recorded_passes(): void
    {
        DB::statement("SET time_zone = '+00:00'");

        [, $row] = $this->preflightRow();

        $this->assertStringContainsString('PASS', $row);
        $this->assertStringContainsString('session +00:00', $row);
    }

    #[Test]
    public function any_other_session_zone_fails_and_the_command_exits_non_zero(): void
    {
        DB::statement("SET time_zone = '+01:00'");

        [$exit, $row] = $this->preflightRow();

        $this->assertStringContainsString('FAIL', $row);
        $this->assertStringContainsString('+01:00', $row);
        $this->assertNotSame(0, $exit);
    }
}
