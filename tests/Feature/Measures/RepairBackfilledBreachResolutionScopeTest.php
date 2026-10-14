<?php

namespace Tests\Feature\Measures;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0027 §2, the parts the main repair test leaves in prose: every tenant's
 * rows are repaired against THEIR OWN period's end date (no tenant context is
 * needed or consulted: the migration runs with none), and the deploy log line
 * carries counts and nothing personal.
 */
class RepairBackfilledBreachResolutionScopeTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_05_100001_repair_backfilled_kri_breaches_resolved_before_they_breached.php';

    private TenantFixture $fixture;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
        $this->fixture = new TenantFixture;
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function row(int $orgId, int $periodId, string $breachedAt, string $resolvedAt): int
    {
        $n = ++$this->seq;

        return DB::table('measure_breaches')->insertGetId([
            'organization_id' => $orgId,
            'measure_id' => $this->fixture->make('measures', $orgId, ['code' => 'SC-'.$n]),
            'object_id' => $this->fixture->make('objects', $orgId),
            'period_id' => $periodId,
            'breached_at' => $breachedAt,
            'band_to' => 'red',
            'value' => 12,
            'severity' => 'high',
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
            'note' => (require database_path(self::MIGRATION))::NOTE,
            'created_at' => '2026-10-04 09:00:00',
            'updated_at' => '2026-10-04 09:00:00',
        ]);
    }

    #[Test]
    public function each_tenants_rows_are_repaired_against_their_own_period_with_no_tenant_resolved(): void
    {
        $orgA = $this->organization->id;
        $feb = $this->month('2026-02-10')->id;
        $a = $this->row($orgA, $feb, '2026-02-28 15:00:00', '2026-02-28 00:00:00');

        $this->bootDomainFixtures('Second Bank PLC');
        $orgB = $this->organization->id;
        $dec = $this->month('2026-12-10')->id;
        $b = $this->row($orgB, $dec, '2026-12-31 15:00:00', '2026-12-31 00:00:00');

        TenantContext::clear();

        (require database_path(self::MIGRATION))->up();

        $this->assertSame('2026-02-28 23:59:59', DB::table('measure_breaches')->where('id', $a)->value('resolved_at'));
        $this->assertSame('2026-12-31 23:59:59', DB::table('measure_breaches')->where('id', $b)->value('resolved_at'));
    }

    #[Test]
    public function it_logs_counts_only_and_a_second_run_repairs_nothing(): void
    {
        $period = $this->month('2026-08-15')->id;
        $this->row($this->organization->id, $period, '2026-08-31 15:00:00', '2026-08-31 00:00:00');
        $this->row($this->organization->id, $period, '2026-08-03 10:00:00', '2026-08-30 12:00:00');

        $logged = [];
        Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });
        Log::shouldReceive('debug', 'warning', 'error', 'notice')->zeroOrMoreTimes();

        (require database_path(self::MIGRATION))->up();
        (require database_path(self::MIGRATION))->up();

        $adr = array_values(array_filter($logged, fn ($l) => str_starts_with((string) $l[0], 'ADR 0027 repair')));
        $this->assertCount(2, $adr);
        $this->assertSame(
            ['candidates' => 2, 'repaired' => 1, 'skipped_touched' => 0, 'skipped_not_period_midnight' => 1, 'negative_remaining' => 0],
            $adr[0][1],
        );
        $this->assertSame(0, $adr[1][1]['repaired']);
        foreach ($adr as [, $context]) {
            foreach ($context as $value) {
                $this->assertIsInt($value, 'The log carries counts, never row data.');
            }
        }
    }
}
