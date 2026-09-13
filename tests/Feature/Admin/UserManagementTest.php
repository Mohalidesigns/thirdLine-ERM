<?php

namespace Tests\Feature\Admin;

use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * User administration — the escalation the create form used to allow.
 *
 * `roles` was `required|array|min:1` with NO rule on its elements, and the
 * controller passed the array straight to `syncRoles()`. `super-admin` is a
 * seeded role that `Gate::before` answers every ability for, so a caller
 * holding nothing but `admin.users` could post it — to a new account, or to
 * their own, through the edit form — and be running the platform. That is the
 * first test below, and it is the reason this file exists.
 *
 * The rest pin the two quieter defects (a bare `exists:business_units,id`, and
 * `staff_id` unique across every institution on the installation) and the
 * tenant boundary that was already sound, so a later refactor cannot remove it
 * without a failure.
 */
class UserManagementTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $businessUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('admin.users');
        Role::findOrCreate('risk-analyst');
        Role::findOrCreate(UserPolicy::SUPER_ADMIN);

        $this->actor->givePermissionTo('admin.users');

        $this->businessUnit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'name' => 'Retail Banking',
            'code' => 'RB',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Officer',
            'email' => 'new-officer@example.test',
            'staff_id' => 'STF-001',
            'job_title' => 'Analyst',
            'department' => 'Risk',
            'phone' => '08000000000',
            'business_unit_id' => $this->businessUnit->id,
            'roles' => ['risk-analyst'],
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /*  The escalation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function admin_users_alone_could_mint_a_super_admin(): void
    {
        $response = $this->actingAs($this->actor)
            ->post(route('admin.users.store'), $this->payload([
                'roles' => ['risk-analyst', UserPolicy::SUPER_ADMIN],
            ]));

        $response->assertSessionHasErrors('roles.1');
        $this->assertDatabaseMissing('users', ['email' => 'new-officer@example.test']);
    }

    #[Test]
    public function an_administrator_could_promote_themselves_through_the_edit_form(): void
    {
        $response = $this->actingAs($this->actor)
            ->put(route('admin.users.update', $this->actor), $this->payload([
                'email' => $this->actor->email,
                'roles' => [UserPolicy::SUPER_ADMIN],
            ]));

        $response->assertSessionHasErrors('roles');
        $this->assertFalse($this->actor->fresh()->hasRole(UserPolicy::SUPER_ADMIN));
    }

    #[Test]
    public function a_super_admin_may_still_grant_the_role(): void
    {
        $this->actor->assignRole(UserPolicy::SUPER_ADMIN);

        $this->actingAs($this->actor)
            ->post(route('admin.users.store'), $this->payload([
                'roles' => [UserPolicy::SUPER_ADMIN],
            ]))
            ->assertSessionHasNoErrors();

        $created = User::where('email', 'new-officer@example.test')->firstOrFail();
        $this->assertTrue($created->hasRole(UserPolicy::SUPER_ADMIN));
    }

    #[Test]
    public function the_form_offers_exactly_the_roles_the_validator_accepts(): void
    {
        $this->actingAs($this->actor)
            ->get(route('admin.users.create'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Create')
                ->where('roles', fn ($roles) => ! collect($roles)->contains(UserPolicy::SUPER_ADMIN))
            );

        $this->actor->assignRole(UserPolicy::SUPER_ADMIN);

        $this->actingAs($this->actor)
            ->get(route('admin.users.create'))
            ->assertInertia(fn ($page) => $page
                ->where('roles', fn ($roles) => collect($roles)->contains(UserPolicy::SUPER_ADMIN))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The tenant-bound lists */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_cannot_be_posted_into_another_institutions_business_unit(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignUnit = BusinessUnit::withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'name' => 'Foreign Treasury',
            'code' => 'FT',
        ]);

        $this->actingAs($this->actor)
            ->post(route('admin.users.store'), $this->payload([
                'business_unit_id' => $foreignUnit->id,
            ]))
            ->assertSessionHasErrors('business_unit_id');

        $this->assertDatabaseMissing('users', ['email' => 'new-officer@example.test']);
    }

    #[Test]
    public function two_institutions_may_both_employ_staff_number_001(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB2',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        User::withoutGlobalScopes()->create([
            'name' => 'Their Officer',
            'email' => 'their-officer@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $foreign->id,
            'staff_id' => 'STF-001',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor)
            ->post(route('admin.users.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'new-officer@example.test',
            'staff_id' => 'STF-001',
            'organization_id' => $this->organization->id,
        ]);
    }

    #[Test]
    public function staff_numbers_still_collide_inside_one_institution(): void
    {
        User::create([
            'name' => 'Existing Officer',
            'email' => 'existing@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'staff_id' => 'STF-001',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor)
            ->post(route('admin.users.store'), $this->payload())
            ->assertSessionHasErrors('staff_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Self-action, and the boundary that was already sound */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_administrator_cannot_deactivate_or_reset_their_own_account(): void
    {
        $this->actingAs($this->actor)
            ->patch(route('admin.users.toggle-active', $this->actor))
            ->assertForbidden();

        $this->actingAs($this->actor)
            ->post(route('admin.users.reset-password', $this->actor))
            ->assertForbidden();

        $this->assertTrue($this->actor->fresh()->is_active);
    }

    #[Test]
    public function the_profile_offers_no_control_the_policy_would_refuse(): void
    {
        $this->actingAs($this->actor)
            ->get(route('admin.users.show', $this->actor))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Show')
                ->where('isSelf', true)
                ->where('canManage', true)
                ->where('canDeactivate', false)
                ->where('canResetPassword', false)
            );
    }

    #[Test]
    public function another_institutions_account_is_not_reachable(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB3',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $theirs = User::withoutGlobalScopes()->create([
            'name' => 'Their Officer',
            'email' => 'theirs@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $foreign->id,
            'is_active' => true,
        ]);

        // 404 rather than 403: BelongsToOrganization scopes the binding, so the
        // record is not merely forbidden — from this tenant it does not exist.
        $this->actingAs($this->actor)->get(route('admin.users.show', $theirs))->assertNotFound();
        $this->actingAs($this->actor)->get(route('admin.users.edit', $theirs))->assertNotFound();
    }
}
