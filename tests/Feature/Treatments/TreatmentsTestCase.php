<?php

namespace Tests\Feature\Treatments;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Shared fixtures for the Phase 3.5 treatment tests: one tenant with a risk
 * and a plan against it, and a second tenant with one of each, so a
 * cross-tenant id is always available to a Form Request assertion.
 */
abstract class TreatmentsTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected Risk $risk;

    protected TreatmentPlan $plan;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected Risk $foreignRisk;

    private int $planSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach ([
            'treatment.view', 'treatment.create', 'treatment.edit',
            'treatment.delete', 'treatment.approve', 'risk.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        foreach (['branch-manager', 'risk-manager', 'chief-risk-officer'] as $role) {
            Role::findOrCreate($role);
        }

        $this->risk = $this->makeRisk(['risk_owner_id' => $this->actor->id]);
        $this->plan = $this->makePlan();

        TenantContext::bypass(function () {
            $this->otherOrg = Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $this->otherActor = User::create([
                'name' => 'Other Officer',
                'email' => 'other-officer@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);

            $category = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            $this->foreignRisk = Risk::create([
                'organization_id' => $this->otherOrg->id,
                'category_id' => $category->id,
                'risk_code' => 'RK-FOREIGN',
                'title' => 'Theirs',
                'description' => 'Another bank.',
                'status' => 'active',
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makePlan(array $attributes = []): TreatmentPlan
    {
        $n = ++$this->planSequence;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->risk->id,
            'treatment_code' => 'TP-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'strategy' => 'mitigate',
            'action_title' => 'Plan '.$n,
            'action_description' => 'Fixture plan '.$n,
            'owner_id' => $this->actor->id,
            'priority' => 'high',
            'status' => 'not_started',
            'target_date' => now()->addDays(30)->toDateString(),
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /**
     * Roles are assigned at creation: Spatie caches the loaded role relation,
     * so a role added afterwards is invisible until the model is refreshed.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     */
    protected function userWith(array $permissions, string $email = 'scoped@example.test', array $roles = ['branch-manager']): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($roles as $role) {
            Role::findOrCreate($role);
        }

        $user->syncRoles($roles);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * A valid create payload, in the FORM's field names — which are the
     * 200038 names, not the canonical columns.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPlan(array $overrides = []): array
    {
        return array_merge([
            'risk_id' => $this->risk->id,
            'treatment_title' => 'Tighten wire transfer limits',
            'treatment_description' => 'Reduce the single-transaction ceiling and add a second approver.',
            'treatment_type' => 'mitigate',
            'treatment_owner_id' => $this->actor->id,
            'priority' => 'high',
            'target_completion_date' => now()->addMonths(3)->toDateString(),
            'estimated_cost' => 250000,
        ], $overrides);
    }
}
