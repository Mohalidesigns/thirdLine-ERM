<?php

namespace Tests\Support;

use App\Models\ApprovalRequest;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\ThirdParty;
use App\Services\ApprovalService;
use App\Services\AuditTrailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0029's "old history": rows written while the session zone was NOT UTC.
 *
 * CI's servers are UTC, so a test that needs the production shape creates it:
 * `SET time_zone = '+02:00'` before any fixture, written at 10:00 UTC. A
 * TIMESTAMP then stores 08:00Z while a DATETIME stores the literal 10:00.
 * An injected fixed offset cannot exercise DST; per-row DST handling under
 * SYSTEM is engine behaviour, rehearsed by hand (implementation step 7).
 *
 * Uses CreatesDomainFixtures' organisation, actor and makeRisk().
 */
trait BuildsOffsetZoneWorld
{
    /** The migration under test, loaded the CanonicalColumnBackfillTest way. */
    protected function zoneMigration(): object
    {
        return require database_path('migrations/2026_10_16_100001_run_every_session_in_utc_and_convert_timestamp_values.php');
    }

    /** @return array{risk: Risk, vendor: ThirdParty, approval: ApprovalRequest} */
    protected function buildOffsetZoneWorld(): array
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        DB::statement("SET time_zone = '+02:00'");

        $this->bootDomainFixtures();
        $this->actingAs($this->actor);

        $risk = $this->makeRisk();
        AuditTrailService::record($risk, 'update', 'title', 'Old', 'New', 'zone fixture');

        $vendor = ThirdParty::create(['legal_name' => 'Zone Vendor', 'slug' => 'zone-vendor', 'entity_type' => 'company']);
        $vendor->update(['trading_name' => 'Zone Vendor Ltd']);

        $approval = app(ApprovalService::class)->requestApproval($risk, 'zone_fixture');

        $this->assertGreaterThan(0, RiskAuditTrail::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, AuditLog::query()->withoutGlobalScopes()->count());

        return compact('risk', 'vendor', 'approval');
    }

    protected function useUtcSession(): void
    {
        DB::statement("SET time_zone = '+00:00'");
    }

    /** Every raw value the world's assertions compare, read under the CURRENT session zone. */
    protected function rawSnapshot(): array
    {
        return [
            'org.created_at' => DB::table('organizations')->value('created_at'),
            'users.created_at' => DB::table('users')->value('created_at'),
            'tp.created_at' => DB::table('tp_audit_logs')->orderBy('id')->value('created_at'),
            'approval.requested_at' => DB::table('approval_requests')->value('requested_at'),
            'audit.changed_at' => DB::table('risk_audit_trail')->orderBy('id')->value('changed_at'),
        ];
    }

    protected function chainsIntact(): bool
    {
        $erm = RiskAuditTrail::query()->withoutGlobalScopes()->get()
            ->every(fn (RiskAuditTrail $r) => $r->hash === $r->expectedHash());

        $tprm = AuditLog::query()->withoutGlobalScopes()->get()->every(fn (AuditLog $r) => $r->isIntact());

        return $erm && $tprm;
    }
}
