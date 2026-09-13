<?php

namespace Tests\Feature;

use App\Services\ReferenceCodeService;
use App\Support\ReferenceCodeAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-01 TASK 3 acceptance.
 */
class ReferenceCodeServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 09:00:00');
        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Format and sequencing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function it_issues_padded_sequential_codes(): void
    {
        $this->assertSame('LE-2026-0001', $this->generate());
        $this->assertSame('LE-2026-0002', $this->generate());
        $this->assertSame('LE-2026-0003', $this->generate());
    }

    #[Test]
    public function fifty_consecutive_calls_produce_fifty_distinct_codes(): void
    {
        $codes = [];

        for ($i = 0; $i < 50; $i++) {
            $codes[] = $this->generate();
        }

        $this->assertCount(50, array_unique($codes), 'a code was issued twice');
        $this->assertSame('LE-2026-0001', $codes[0]);
        $this->assertSame('LE-2026-0050', $codes[49], 'the sequence must have no gaps');
    }

    #[Test]
    public function nested_generation_inside_an_open_transaction_still_yields_distinct_codes(): void
    {
        // The creation paths call generate() from inside DB::transaction(), so
        // the service has to behave when it is not the outermost transaction.
        $codes = DB::transaction(function () {
            return [$this->generate(), $this->generate(), $this->generate()];
        });

        $this->assertSame(['LE-2026-0001', 'LE-2026-0002', 'LE-2026-0003'], $codes);
    }

    #[Test]
    public function the_counter_is_held_with_a_row_lock(): void
    {
        // The lock is what makes concurrent generation safe, and it is
        // invisible in the result — so assert on the emitted SQL.
        //
        // SQLite's grammar compiles FOR UPDATE away entirely (it serialises
        // writes at the database level, so there is nothing to emit), which
        // means this assertion can only be made on a driver that has row
        // locks. On SQLite the guarantee rests on the sequencing tests above.
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped("FOR UPDATE is not emitted on the [{$driver}] driver; run this suite against MySQL to assert it.");
        }

        $this->generate();

        DB::enableQueryLog();
        $this->generate();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $selects = array_filter(
            array_column($log, 'query'),
            fn (string $q) => str_contains($q, 'reference_sequences') && str_starts_with($q, 'select')
        );

        $this->assertNotEmpty($selects);
        $this->assertStringContainsStringIgnoringCase(
            'for update',
            implode(' ', $selects),
            'the sequence row must be selected FOR UPDATE'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Past 9999 — the lexicographic bug */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_counter_keeps_climbing_past_four_digits(): void
    {
        // The old implementation ordered a string column, so 'LE-2026-9999'
        // sorted above 'LE-2026-10000' and the counter walked backwards,
        // re-issuing codes it had already handed out.
        DB::table('reference_sequences')->insert([
            'organization_id' => $this->organization->id,
            'sequence_key' => 'loss_events.event_reference',
            'prefix' => 'LE',
            'period' => 2026,
            'next_value' => 9999,
            'padding' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('LE-2026-9999', $this->generate());
        $this->assertSame('LE-2026-10000', $this->generate());
        $this->assertSame('LE-2026-10001', $this->generate());
    }

    /* ------------------------------------------------------------------ */
    /*  Per-organization numbering */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_organization_has_its_own_sequence(): void
    {
        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->assertSame('LE-2026-0001', $this->generate());
        $this->assertSame('LE-2026-0002', $this->generate());

        // A second tenant starts at 1 — it neither continues nor advances the
        // first tenant's numbering, which used to leak activity volume.
        $this->assertSame('LE-2026-0001', $this->generate($other->id));
        $this->assertSame('LE-2026-0003', $this->generate());
    }

    #[Test]
    public function separate_prefixes_and_columns_get_separate_counters(): void
    {
        $this->assertSame('LE-2026-0001', $this->generate());
        $this->assertSame('ISS-2026-0001', ReferenceCodeService::generate('issues', 'issue_reference', 'ISS', 4, $this->organization->id));
        $this->assertSame('LE-2026-0002', $this->generate());
    }

    #[Test]
    public function the_sequence_restarts_in_a_new_year(): void
    {
        $this->assertSame('LE-2026-0001', $this->generate());

        Carbon::setTestNow('2027-01-04 09:00:00');

        $this->assertSame('LE-2027-0001', $this->generate());
    }

    /* ------------------------------------------------------------------ */
    /*  Migrating off the legacy scheme */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_first_code_continues_above_whatever_the_legacy_scheme_issued(): void
    {
        // Rows written before reference_sequences existed. The counter must
        // not restart at 1 and re-issue a code already on a filing.
        $this->makeLossEvent(['event_reference' => 'LE-2026-0007']);
        $this->makeLossEvent(['event_reference' => 'LE-2026-0011']);

        $this->assertSame('LE-2026-0012', $this->generate());
    }

    #[Test]
    public function the_legacy_seed_is_compared_numerically_not_lexicographically(): void
    {
        $this->makeLossEvent(['event_reference' => 'LE-2026-9999']);
        $this->makeLossEvent(['event_reference' => 'LE-2026-10000']);

        $this->assertSame('LE-2026-10001', $this->generate());
    }

    #[Test]
    public function the_legacy_seed_ignores_another_organizations_codes(): void
    {
        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTH2',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        DB::table('loss_events')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $other->id,
            'event_reference' => 'LE-2026-0500',
            'title' => 'Other tenant event',
            'description' => 'x',
            'date_of_loss' => '2026-01-01',
            'date_discovered' => '2026-01-02',
            'date_reported' => '2026-01-03',
            'basel_l1_category' => 'EXECUTION_DELIVERY',
            'basel_l2_category' => 'TRANSACTION_CAPTURE',
            'cbn_risk_category' => 'PROCESS_RISK',
            'loss_category' => 'actual_loss',
            'event_severity' => 'MODERATE',
            'current_status' => 'NEW',
            'created_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('LE-2026-0001', $this->generate());
    }

    /* ------------------------------------------------------------------ */
    /*  Collision audit */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_audit_reports_nothing_for_clean_data(): void
    {
        $this->makeLossEvent(['event_reference' => 'LE-2026-0001']);
        $this->makeTreatmentPlan('TP-2026-0001');

        $this->assertSame([], ReferenceCodeAudit::all());
        $this->artisan('reference-codes:audit')->assertSuccessful();
    }

    #[Test]
    public function the_audit_finds_a_code_used_twice_in_one_organization(): void
    {
        // treatment_plans.treatment_code has no unique index of any kind —
        // migration 200038 declared one but its addColumns() helper only ever
        // applied `nullable` and `default`, so the constraint was never
        // created. That is precisely the state the audit exists to inspect,
        // and it is why it must run before an index is added.
        $first = $this->makeTreatmentPlan('TP-2026-0001');
        $this->makeTreatmentPlan('TP-2026-0001');

        $collisions = ReferenceCodeAudit::collisions('treatment_plans', 'treatment_code');

        $this->assertCount(1, $collisions);
        $this->assertSame('TP-2026-0001', $collisions[0]['code']);
        $this->assertSame(2, $collisions[0]['occurrences']);
        $this->assertContains($first, $collisions[0]['ids']);

        $this->assertFalse(ReferenceCodeAudit::canEnforceUnique('treatment_plans', 'treatment_code'));
        $this->artisan('reference-codes:audit')->assertFailed();
    }

    /* ------------------------------------------------------------------ */

    private function generate(?int $organizationId = null): string
    {
        return ReferenceCodeService::generate(
            'loss_events',
            'event_reference',
            'LE',
            4,
            $organizationId ?? $this->organization->id
        );
    }

    private function makeTreatmentPlan(string $code): int
    {
        return DB::table('treatment_plans')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'treatment_code' => $code,
            'strategy' => 'mitigate',
            'action_title' => 'Action',
            'action_description' => 'Body',
            'owner_id' => $this->actor->id,
            'target_date' => '2026-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
