<?php

namespace Tests\Feature\Console;

use App\Models\Bcms\ReminderSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Set A defect 4 (Gate 1 retrospective), the first half: `BcmsWatchdog`
 * (formerly) looked its problem organisation back up by `where('name', ...)`
 * — a column with no unique index — to send its alert. Two similarly-named
 * banks (not unusual in Nigerian banking group structures, per the code
 * comment this fix left behind) meant a stalled-reminder alert for one tenant
 * could be delivered to another tenant's administrators.
 *
 * The fix threads `organization_id` through instead. This test proves it by
 * making the mistake possible — two organisations sharing a name — and
 * checking the RIGHT one's administrator is told and the other one's is not.
 */
class BcmsWatchdogTenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('features.bcms', true);
    }

    /**
     * MUTATION: change `alertAdministrators()` back to
     * `Organization::query()->where('name', $problem['organization'])->first()`
     * and this test's second assertion fails — the healthy tenant's admin is
     * notified about the stalled tenant's problem, because the lookup
     * resolves to whichever "Heritage Microfinance Bank" the query finds
     * first.
     */
    #[Test]
    public function a_stalled_tenant_alerts_its_own_administrator_not_a_similarly_named_tenants(): void
    {
        // The healthy tenant is created FIRST and so holds the lower id — a
        // name lookup with no `orderBy` returns whichever row the storage
        // engine hands back first, which in practice is insertion order. The
        // whole point of the id-based fix is that it must not matter which
        // one was created first; creating the healthy one first is what makes
        // this test able to catch a regression back to a name lookup instead
        // of accidentally resolving correctly by luck of the row order.
        $healthy = Organization::create([
            'name' => 'Heritage Microfinance Bank', 'short_name' => 'HMB2',
            'institution_type' => 'microfinance_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $stalled = Organization::create([
            'name' => 'Heritage Microfinance Bank', 'short_name' => 'HMB1',
            'institution_type' => 'microfinance_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($stalled->id);
        $stalledAdmin = $this->adminFor($stalled, 'stalled-admin@hmb.test');
        ReminderSchedule::factory()->create([
            'organization_id' => $stalled->id,
            'status' => 'pending',
            'send_at' => now()->subHours(2),
        ]);
        TenantContext::clear();

        TenantContext::set($healthy->id);
        $healthyAdmin = $this->adminFor($healthy, 'healthy-admin@hmb.test');
        TenantContext::clear();

        $this->artisan('bcms:watchdog')->assertFailed();

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $stalled->id,
            'user_id' => $stalledAdmin->id,
            'type' => 'bcms.watchdog.stalled',
        ]);

        $this->assertSame(
            0,
            DB::table('notifications_log')
                ->where('organization_id', $healthy->id)
                ->where('user_id', $healthyAdmin->id)
                ->count(),
            "The healthy tenant's administrator was notified about the OTHER, similarly-named tenant's stalled reminders."
        );
    }

    private function adminFor(Organization $organization, string $email): User
    {
        $user = User::create([
            'organization_id' => $organization->id, 'name' => 'BC Admin',
            'email' => $email, 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $role = Role::findOrCreate('watchdog-admin-'.$organization->id, 'web');
        $role->givePermissionTo(Permission::findOrCreate('bcms.admin', 'web'));
        $user->assignRole($role);

        return $user->refresh();
    }
}
