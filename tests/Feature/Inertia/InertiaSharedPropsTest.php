<?php

namespace Tests\Feature\Inertia;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 0 — the props every Inertia page receives.
 *
 * Two invariants matter on a multi-tenant risk platform: nothing about the
 * signed-in user beyond id/name/email reaches the page HTML (the User model
 * carries MFA secrets and lockout state), and nothing about another
 * organisation does either.
 */
class InertiaSharedPropsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('my.view');
        $this->actor->givePermissionTo('my.view');
    }

    #[Test]
    public function auth_user_is_id_name_email_and_nothing_else(): void
    {
        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('My/Index')
                ->where('auth.user.id', $this->actor->id)
                ->where('auth.user.name', $this->actor->name)
                ->where('auth.user.email', $this->actor->email)
                ->has('auth.user', 3)
                ->has('auth.roles')
                ->has('auth.permissions')
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('my.view'))
            );
    }

    #[Test]
    public function tenant_is_the_acting_users_organisation(): void
    {
        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.id', $this->organization->id)
                ->where('tenant.name', $this->organization->name)
                ->where('tenant.code', $this->organization->short_name)
                ->has('tenant', 3)
            );
    }

    #[Test]
    public function a_user_from_another_organisation_never_sees_this_ones_tenant_or_notifications(): void
    {
        // Three unread notifications for the org-A actor.
        foreach (range(1, 3) as $i) {
            DB::table('notifications_log')->insert($this->notificationRow($this->actor->id, "A{$i}"));
        }

        $orgB = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $userB = User::create([
            'name' => 'Other Officer',
            'email' => 'other-officer@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $userB->givePermissionTo('my.view');

        DB::table('notifications_log')->insert($this->notificationRow($userB->id, 'B1'));

        $this->actingAs($userB)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.id', $orgB->id)
                ->where('tenant.name', 'Other Bank PLC')
                ->where('unreadNotifications', 1)
                ->where('auth.user.id', $userB->id)
            );

        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.id', $this->organization->id)
                ->where('unreadNotifications', 3)
            );
    }

    #[Test]
    public function license_is_null_when_unlicensed_and_does_not_throw(): void
    {
        $this->assertFalse(config('licensing.enforce_valid'), 'The suite runs with LICENSE_ENFORCE_VALID=false (phpunit.xml).');

        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('license', null));
    }

    #[Test]
    public function features_and_period_and_navigation_are_shared(): void
    {
        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->has('features.ai_intelligence')
                ->has('features.mfa_totp')
                ->has('period')
                ->has('navigation.primary')
                ->has('navigation.sections')
                ->has('flash')
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationRow(int $userId, string $suffix): array
    {
        // The row App\Services\NotificationService writes.
        return [
            'organization_id' => User::query()->withoutGlobalScopes()->find($userId)->organization_id,
            'user_id' => $userId,
            'channel' => 'database',
            'type' => 'test',
            'subject' => 'Notification '.$suffix,
            'body' => 'Fixture '.$suffix,
            'status' => 'sent',
            'notification_category' => 'system',
            'priority' => 'normal',
            'metadata' => json_encode([]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
