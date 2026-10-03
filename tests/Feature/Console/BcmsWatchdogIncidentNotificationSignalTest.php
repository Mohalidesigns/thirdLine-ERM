<?php

namespace Tests\Feature\Console;

use App\Models\Bcms\Incident;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 10 (ADR 0020 §2 consequences, `phase-10-incident-clause-map.md`
 * §6 point 12): `BcmsWatchdog` gained an overdue-regulatory-notification
 * signal, and nothing in `tests/Feature/Console/` exercised it before this —
 * neither `BcmsWatchdogAuditSignalTest` nor `BcmsWatchdogTenantResolutionTest`
 * mentions a notification, an overdue clock or a regulator at all. A
 * scheduled command with an untested branch is this repository's most
 * reliable source of code that has never executed (the standing QA check),
 * and this is exactly that branch: it is also the one the module leans on
 * hardest, because "nobody may ever look at a closed incident's notification
 * log again" is the whole reason the watchdog carries this signal at all.
 */
class BcmsWatchdogIncidentNotificationSignalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Watchdog Notification Bank', 'short_name' => 'WNB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
    }

    #[Test]
    public function a_healthy_notification_path_with_no_open_obligations_is_silent(): void
    {
        $this->artisan('bcms:watchdog')
            ->doesntExpectOutputToContain('regulatory notification(s) overdue')
            ->assertSuccessful();
    }

    #[Test]
    public function an_overdue_regulatory_notification_fails_the_run_and_names_the_organization(): void
    {
        $this->declareIncidentWithOverdueNotification();

        $this->artisan('bcms:watchdog')
            ->expectsOutputToContain('regulatory notification(s) overdue')
            ->assertFailed();
    }

    #[Test]
    public function an_overdue_regulatory_notification_alerts_every_holder_of_bcms_incident_notify(): void
    {
        TenantContext::set($this->organization->id);
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $notifyHolder = $this->userWithPermission('notify-holder@wnb.test', $unit, 'bcms.incident.notify');
        $bystander = $this->userWithPermission('bystander@wnb.test', $unit, 'bcms.incident.view');

        $this->declareIncidentWithOverdueNotification($unit);
        TenantContext::clear();

        $this->artisan('bcms:watchdog')->assertFailed();

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $this->organization->id,
            'user_id' => $notifyHolder->getKey(),
            'type' => 'bcms.watchdog.notification_overdue',
        ]);

        $this->assertDatabaseMissing('notifications_log', [
            'organization_id' => $this->organization->id,
            'user_id' => $bystander->getKey(),
            'type' => 'bcms.watchdog.notification_overdue',
        ]);
    }

    private function declareIncidentWithOverdueNotification(?BusinessUnit $unit = null): Incident
    {
        TenantContext::set($this->organization->id);

        $unit ??= BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS-'.Str::random(4), 'name' => 'Operations', 'is_active' => true,
        ]);

        $incident = Incident::query()->create([
            'organization_id' => $this->organization->id,
            'reference' => 'INC-'.Str::random(6),
            'title' => 'A cyber incident with an overdue CBN clock',
            'incident_type' => 'cyber',
            'severity' => 'sev1',
            'business_unit_id' => $unit->id,
            'detected_at' => now()->subHours(30),
            'declared_at' => now()->subHours(29),
            'declared_by' => null,
            'status' => 'open',
            'activation_level' => 'full',
            'is_reportable' => true,
        ]);

        DB::table('bcms_incident_notifications')->insert([
            'organization_id' => $this->organization->id,
            'incident_id' => $incident->getKey(),
            'regulator' => 'cbn',
            'basis_clause_ref' => 'cbn.rcf.incident_response',
            'kind' => 'initial',
            'sequence' => 1,
            'awareness_at' => now()->subHours(30),
            'due_at' => now()->subHours(6), // overdue
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::clear();

        return $incident;
    }

    private function userWithPermission(string $email, BusinessUnit $unit, string $permission): User
    {
        $user = User::query()->create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'business_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('watchdog-notif-'.md5($email), 'web');
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);

        return $user;
    }
}
