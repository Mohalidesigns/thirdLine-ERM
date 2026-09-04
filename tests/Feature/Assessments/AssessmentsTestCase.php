<?php

namespace Tests\Feature\Assessments;

use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\ScoringProfile;
use App\Models\User;
use App\Support\RiskCalculationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 3.3 assessment tests: one tenant with a risk
 * carrying two mapped controls, and a second tenant with a risk, a user and a
 * KRI, so a cross-tenant id is always available to a Form Request assertion.
 */
abstract class AssessmentsTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected Risk $risk;

    protected Control $preventive;

    protected Control $detective;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected Risk $foreignRisk;

    protected KeyRiskIndicator $foreignKri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        foreach (['assessment.view', 'assessment.create', 'assessment.submit', 'assessment.approve', 'assessment.reject', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('branch-manager');
        Role::findOrCreate('risk-manager');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);

        $this->risk = $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $this->preventive = $this->makeControl(['control_type' => 'preventive', 'effectiveness_rating' => 'effective']);
        $this->detective = $this->makeControl(['control_type' => 'detective', 'effectiveness_rating' => 'partially_effective']);

        $this->attachControl($this->risk, $this->preventive, weight: 2.0, isKey: true);
        $this->attachControl($this->risk, $this->detective, weight: 1.0);

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
                'description' => 'A risk belonging to another bank.',
                'status' => 'active',
                'created_by' => $this->otherActor->id,
            ]);

            $this->foreignKri = KeyRiskIndicator::create($this->kriAttributes([
                'organization_id' => $this->otherOrg->id,
                'kri_code' => 'KRI-FOREIGN',
                'name' => 'Theirs',
            ]));
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * Roles are assigned here rather than by the caller afterwards: Spatie
     * caches the loaded role relation, so a role added to a model that has
     * already answered hasAnyRole() is invisible until it is refreshed.
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
     * The columns `key_risk_indicators` requires, defaulted to values that
     * mean nothing in particular.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function kriAttributes(array $attributes = []): array
    {
        return array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-0001',
            'name' => 'Fixture indicator',
            'description' => 'Fixture indicator.',
            'metric_formula' => 'count(events)',
            'data_source' => 'manual',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'count',
            'status' => 'active',
        ], $attributes);
    }

    protected function makeAssessment(array $attributes = []): RiskAssessment
    {
        return RiskAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => '2026-06-30',
            'assessor_id' => $this->actor->id,
            'status' => 'draft',
            'likelihood_score' => 4,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
        ], $attributes));
    }

    /**
     * A complete, valid chain — the payload the form posts.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validChain(array $overrides = []): array
    {
        return array_merge([
            'risk_id' => $this->risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => '2026-06-30',
            'likelihood' => 4,
            'impact_financial' => 5,
            'impact_operational' => 3,
            'controls' => [
                $this->preventive->id => [
                    'design_effectiveness' => 'effective',
                    'operating_effectiveness' => 'mostly_effective',
                ],
                $this->detective->id => [
                    'design_effectiveness' => 'partially_effective',
                    'operating_effectiveness' => 'partially_effective',
                ],
            ],
            'rationale' => 'Quarterly reassessment against the current control set.',
            'action' => 'draft',
        ], $overrides);
    }
}
