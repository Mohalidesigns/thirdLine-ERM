<?php

namespace Tests\Feature;

use App\Models\AuditTrailIsAppendOnly;
use App\Models\Organization;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantFixture;
use Tests\TestCase;

class AuditTrailIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Audit Bank PLC',
            'short_name' => 'AUDIT',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'name' => 'Auditor',
            'email' => 'auditor@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Characterisation: existing behaviour must not regress */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function recording_a_change_still_captures_the_same_fields_as_before(): void
    {
        $this->actingAs($this->user);

        $risk = $this->risk();
        AuditTrailService::record($risk, 'update', 'title', 'Old title', 'New title', 'corrected wording');

        $row = RiskAuditTrail::query()->latest('id')->first();

        $this->assertSame($this->org->id, $row->organization_id);
        // Was 'Risk' (class_basename). WP-01 TASK 2 moved every entity_type
        // column onto the morph aliases in App\Support\MorphTypes — the old
        // value was the bug, not the contract: Risk::auditTrail() has always
        // filtered on 'risk', so nothing this service wrote was ever visible
        // through the relationship.
        $this->assertSame('risk', $row->entity_type);
        $this->assertSame($risk->id, $row->entity_id);
        $this->assertSame('update', $row->action_type);
        $this->assertSame('title', $row->field_changed);
        $this->assertSame('Old title', $row->old_value);
        $this->assertSame('New title', $row->new_value);
        $this->assertSame($this->user->id, $row->changed_by);
        $this->assertSame('corrected wording', $row->change_reason);
        $this->assertNotNull($row->changed_at);
    }

    #[Test]
    public function record_changes_writes_one_row_per_changed_field(): void
    {
        $this->actingAs($this->user);

        $risk = $this->risk();
        $original = $risk->getOriginal();

        $risk->update(['title' => 'Renamed', 'status' => 'closed']);
        AuditTrailService::recordChanges($risk, $original);

        $fields = RiskAuditTrail::query()->where('entity_id', $risk->id)
            ->where('action_type', 'update')->pluck('field_changed')->all();

        $this->assertContains('title', $fields);
        $this->assertContains('status', $fields);
    }

    #[Test]
    public function a_system_change_records_a_null_actor_rather_than_user_one(): void
    {
        // Nobody authenticated: the old code attributed this to user id 1.
        $row = $this->append('create');

        $this->assertNull($row->changed_by);
    }

    /* ------------------------------------------------------------------ */
    /*  Chain */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_row_is_sealed_on_insert_and_links_to_its_predecessor(): void
    {
        $first = $this->append('create');
        $second = $this->append('update');

        $this->assertNotNull($first->hash);
        $this->assertNull($first->previous_hash, 'the first row in an organization opens the chain');
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertNotSame($first->hash, $second->hash);
    }

    #[Test]
    public function chains_are_independent_per_organization(): void
    {
        $other = Organization::create([
            'name' => 'Second Bank PLC',
            'short_name' => 'SECOND',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $mine = $this->append('create');

        TenantContext::set($other->id);
        $theirs = $this->append('create', $other->id);

        $this->assertNull($theirs->previous_hash, "another tenant's row must not extend this chain");
        $this->assertNotSame($mine->hash, $theirs->hash);
    }

    #[Test]
    public function audit_verify_reports_an_unbroken_chain(): void
    {
        foreach (range(1, 5) as $i) {
            $this->append('update');
        }

        $this->artisan('audit:verify')
            ->expectsOutputToContain('chain intact')
            ->assertSuccessful();
    }

    #[Test]
    public function audit_verify_detects_an_edited_row(): void
    {
        $this->append('create');
        $target = $this->append('update');
        $this->append('update');

        $this->tamper(fn () => DB::table('risk_audit_trail')
            ->where('id', $target->id)
            ->update(['new_value' => 'quietly rewritten']));

        $this->artisan('audit:verify')->assertFailed();

        $report = $this->verifyReport();
        $this->assertSame(1, $report['breaks']);
        $this->assertSame('CONTENT', $report['organizations'][0]['breaks'][0]['type']);
    }

    #[Test]
    public function audit_verify_detects_a_removed_row(): void
    {
        $this->append('create');
        $target = $this->append('update');
        $this->append('update');

        $this->tamper(fn () => DB::table('risk_audit_trail')->where('id', $target->id)->delete());

        $report = $this->verifyReport();

        $this->assertSame(1, $report['breaks']);
        $this->assertSame('LINK', $report['organizations'][0]['breaks'][0]['type']);
    }

    /* ------------------------------------------------------------------ */
    /*  Immutability */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function updating_an_audit_row_through_eloquent_throws(): void
    {
        $row = $this->append('create');

        $this->expectException(AuditTrailIsAppendOnly::class);

        $row->update(['new_value' => 'tampered']);
    }

    #[Test]
    public function deleting_an_audit_row_through_eloquent_throws(): void
    {
        $row = $this->append('create');

        $this->expectException(AuditTrailIsAppendOnly::class);

        $row->delete();
    }

    #[Test]
    public function the_database_refuses_a_raw_update(): void
    {
        // The model guard is bypassed by the query builder; the trigger is not.
        $row = $this->append('create');

        $this->expectException(QueryException::class);

        DB::table('risk_audit_trail')->where('id', $row->id)->update(['new_value' => 'tampered']);
    }

    #[Test]
    public function the_database_refuses_a_raw_delete(): void
    {
        $row = $this->append('create');

        $this->expectException(QueryException::class);

        DB::table('risk_audit_trail')->where('id', $row->id)->delete();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function risk()
    {
        $fixture = new TenantFixture;
        $id = $fixture->make('risks', $this->org->id);

        return \App\Models\Risk::find($id);
    }

    private function append(string $action, ?int $organizationId = null): RiskAuditTrail
    {
        return RiskAuditTrail::create([
            'organization_id' => $organizationId ?? $this->org->id,
            'entity_type' => 'Risk',
            'entity_id' => 1,
            'action_type' => $action,
            'field_changed' => 'title',
            'old_value' => 'before',
            'new_value' => 'after',
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * Simulate an attacker with database access by dropping the triggers,
     * making the change, and putting them back.
     */
    private function tamper(callable $callback): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS risk_audit_trail_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS risk_audit_trail_no_delete');

        $callback();

        // Re-arm using the driver's own syntax. This used to emit SQLite's
        // RAISE(ABORT, ...) unconditionally, so the whole class errored when
        // the suite ran against MySQL — on the very driver production uses.
        $message = 'risk_audit_trail is append-only';

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => collect([
                "CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ])->each(fn (string $sql) => DB::unprepared($sql)),

            'pgsql' => collect([
                'CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
                'CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
            ])->each(fn (string $sql) => DB::unprepared($sql)),

            default => collect([
                "CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                "CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            ])->each(fn (string $sql) => DB::unprepared($sql)),
        };
    }

    /** @return array<string, mixed> */
    private function verifyReport(): array
    {
        \Illuminate\Support\Facades\Artisan::call('audit:verify', ['--json' => true]);

        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertIsArray($report, 'audit:verify --json did not emit a JSON report');

        return $report;
    }
}
