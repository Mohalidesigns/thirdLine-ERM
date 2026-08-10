<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Exercises the WP-01 TASK 1 backfill against legacy-shaped rows.
 *
 * RefreshDatabase runs migrations against an empty database, so the backfill
 * never touches a row during the rest of the suite — every branch in it would
 * otherwise ship untested and fail for the first time in production, against
 * real data. This inserts rows in the pre-consolidation shape (duplicate
 * column populated, canonical column empty), re-runs the migration, and
 * asserts what moved.
 *
 * Re-running is safe: the migration only issues UPDATEs guarded on the
 * canonical column being empty.
 */
class CanonicalColumnBackfillTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_10_110002_backfill_canonical_columns.php');
        $migration->up();
    }

    #[Test]
    public function it_copies_the_duplicate_onto_an_empty_canonical_column(): void
    {
        // title and description are NOT NULL, so an unpopulated canonical
        // column is the empty string rather than NULL — which is exactly why
        // the migration treats '' as empty.
        $id = $this->insertLegacyLossEvent([
            'title' => '',
            'event_title' => 'Legacy title',
            'description' => '',
            'event_description' => 'Legacy description',
            'initial_root_cause' => null,
            'root_cause_summary' => 'Legacy root cause',
        ]);

        $this->runBackfill();

        $row = DB::table('loss_events')->find($id);
        $this->assertSame('Legacy title', $row->title);
        $this->assertSame('Legacy description', $row->description);
        $this->assertSame('Legacy root cause', $row->initial_root_cause);
    }

    #[Test]
    public function it_leaves_a_populated_canonical_column_alone(): void
    {
        // The canonical column is authoritative. A row written by the new code
        // must not be overwritten by whatever the old column happens to hold.
        $id = $this->insertLegacyLossEvent([
            'title' => 'Canonical title',
            'event_title' => 'Stale duplicate',
        ]);

        $this->runBackfill();

        $this->assertSame('Canonical title', DB::table('loss_events')->find($id)->title);
    }

    #[Test]
    public function it_converts_naira_to_kobo(): void
    {
        $id = $this->insertLegacyLossEvent([
            'gross_loss_amount_kobo' => 0,
            'insurance_recovery_kobo' => 0,
            'other_recovery_kobo' => 0,
            'gross_loss_amount' => 1_234_567.89,
            'insurance_recovery' => 200_000.50,
            'recovery_amount' => 1_000.00,
        ]);

        $this->runBackfill();

        $row = DB::table('loss_events')->find($id);
        $this->assertSame(123_456_789, (int) $row->gross_loss_amount_kobo);
        $this->assertSame(20_000_050, (int) $row->insurance_recovery_kobo);
        $this->assertSame(100_000, (int) $row->other_recovery_kobo);
    }

    #[Test]
    public function it_does_not_overwrite_a_kobo_amount_that_is_already_set(): void
    {
        $id = $this->insertLegacyLossEvent([
            'gross_loss_amount_kobo' => 999_000_000,
            'gross_loss_amount' => 1_000.00,
        ]);

        $this->runBackfill();

        $this->assertSame(999_000_000, (int) DB::table('loss_events')->find($id)->gross_loss_amount_kobo);
    }

    #[Test]
    public function it_upper_cases_the_classification_columns(): void
    {
        // This is the bit that makes historic fraud events start alerting:
        // RegulatoryThresholdService matches INTERNAL_FRAUD, not internal_fraud.
        $id = $this->insertLegacyLossEvent([
            'basel_l1_category' => 'internal_fraud',
            'basel_l2_category' => 'unauthorised_activity',
            'cbn_risk_category' => 'technology_risk',
            'event_severity' => 'major',
            'current_status' => 'reported',
        ]);

        $this->runBackfill();

        $row = DB::table('loss_events')->find($id);
        $this->assertSame('INTERNAL_FRAUD', $row->basel_l1_category);
        $this->assertSame('UNAUTHORISED_ACTIVITY', $row->basel_l2_category);
        $this->assertSame('TECHNOLOGY_RISK', $row->cbn_risk_category);
        $this->assertSame('MAJOR', $row->event_severity);
        $this->assertSame('REPORTED', $row->current_status);
    }

    #[Test]
    public function a_zero_progress_percentage_is_backfilled_rather_than_treated_as_empty(): void
    {
        // 0 is a real progress figure. The migration's "empty" test is
        // NULL-or-'' precisely so that a genuine zero survives.
        $risk = $this->makeRisk();

        $id = DB::table('treatment_plans')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'strategy' => 'mitigate',
            'action_title' => 'Canonical action',
            'action_description' => 'Body',
            'owner_id' => $this->actor->id,
            'target_date' => '2026-12-31',
            'progress_pct' => 0,
            'progress_percentage' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame(0, (int) DB::table('treatment_plans')->find($id)->progress_pct);
    }

    #[Test]
    public function free_text_contributory_factors_are_wrapped_in_a_json_array(): void
    {
        $event = $this->makeLossEvent();

        $id = DB::table('loss_event_rca')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'loss_event_id' => $event->id,
            'rca_status' => 'IN_PROGRESS',
            'methodology' => '5_WHYS',
            'root_cause_statement' => 'Dual control not enforced',
            'contributory_factors' => null,
            'contributing_factors_text' => 'Understaffed back office',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame(
            ['Understaffed back office'],
            json_decode(DB::table('loss_event_rca')->find($id)->contributory_factors, true)
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Inserts through the query builder rather than the model, so the
     * deprecated columns can still be populated the way the old code did.
     */
    private function insertLegacyLossEvent(array $attributes): int
    {
        static $n = 0;
        $n++;

        return DB::table('loss_events')->insertGetId(array_merge([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'event_reference' => 'LE-LEGACY-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => 'Placeholder',
            'description' => 'Placeholder',
            'date_of_loss' => '2026-01-05',
            'date_discovered' => '2026-01-06',
            'date_reported' => '2026-01-07',
            'basel_l1_category' => 'EXECUTION_DELIVERY',
            'basel_l2_category' => 'TRANSACTION_CAPTURE',
            'cbn_risk_category' => 'PROCESS_RISK',
            'gross_loss_amount_kobo' => 0,
            'insurance_recovery_kobo' => 0,
            'other_recovery_kobo' => 0,
            'loss_category' => 'actual_loss',
            'event_severity' => 'MODERATE',
            'current_status' => 'NEW',
            'created_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
