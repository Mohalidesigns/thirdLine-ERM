<?php

namespace Tests\Feature\Rcsa;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Fixtures for the RCSA v2 universe tests: one tenant with two business units
 * and a process tree, and a second tenant with a unit of its own so that a
 * cross-tenant id is always available to a Form Request assertion.
 *
 * THE FEATURE FLAG IS ON FOR THESE TESTS. It defaults to off, and
 * `UniverseFeatureFlagTest` is the one that asserts the default; every other
 * test here is about behaviour behind the flag and would otherwise assert 404
 * over and over.
 */
abstract class UniverseTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $retail;

    protected BusinessUnit $treasury;

    protected BusinessProcess $onboarding;

    protected BusinessProcess $kyc;

    protected Organization $otherOrg;

    protected BusinessUnit $foreignUnit;

    /** @var list<string> */
    protected const ALL_PERMISSIONS = [
        'rcsa_universe.view',
        'rcsa_universe.create',
        'rcsa_universe.update',
        'rcsa_universe.delete',
        'rcsa_universe.publish',
        'rcsa_universe.import',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.rcsa_v2', true);

        $this->bootDomainFixtures();
        $this->grant(self::ALL_PERMISSIONS);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->treasury = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'TREAS',
            'name' => 'Treasury',
            'is_active' => true,
        ]);

        $this->onboarding = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'code' => 'ONBOARD',
            'name' => 'Customer Onboarding',
            'is_active' => true,
        ]);

        $this->kyc = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'parent_id' => $this->onboarding->id,
            'code' => 'KYC',
            'name' => 'KYC Verification',
            'is_active' => true,
        ]);

        TenantContext::bypass(function () {
            $this->otherOrg = Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $this->foreignUnit = BusinessUnit::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'FOREIGN',
                'name' => 'Foreign Operations',
                'is_active' => true,
            ]);
        });
    }

    /** @param  list<string>  $permissions */
    protected function grant(array $permissions, ?User $user = null): void
    {
        $user ??= $this->actor;

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();
    }

    /**
     * A user of this tenant holding exactly the permissions given.
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => 'scoped-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->grant($permissions, $user);

        return $user;
    }

    /** A valid create payload, overridable field by field. */
    protected function riskPayload(array $overrides = []): array
    {
        return array_merge([
            'business_unit_id' => $this->retail->id,
            'process_id' => $this->onboarding->id,
            'sub_process_id' => $this->kyc->id,
            'potential_risk' => 'Customer accounts are opened without complete KYC documentation.',
            'risk_driver' => 'Manual document checks under branch queue pressure.',
            'risk_category' => 'Compliance/Regulatory',
            'controls' => [
                [
                    'description' => 'Dual review of account opening packs before activation.',
                    'control_type' => 'detective',
                    'frequency' => 'daily',
                    'is_key' => true,
                ],
            ],
        ], $overrides);
    }

    protected function makeRisk(array $overrides = []): RcsaRegisterRisk
    {
        $attributes = array_merge([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'process_id' => $this->onboarding->id,
            'risk_no' => 'RETAIL-R'.(RcsaRegisterRisk::withTrashed()->count() + 1),
            'potential_risk' => 'A risk statement long enough to pass validation checks.',
            'risk_category' => 'Operational',
            'status' => RcsaRegisterRisk::DRAFT,
        ], $overrides);

        return RcsaRegisterRisk::create($attributes);
    }
}
