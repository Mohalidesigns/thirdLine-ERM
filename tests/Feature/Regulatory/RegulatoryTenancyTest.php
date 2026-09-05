<?php

namespace Tests\Feature\Regulatory;

use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\RiskTaxonomy;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Every foreign key on the regulatory module (migration Phase 5.3).
 *
 * NOT ONE OF THEM HAD A VALIDATION RULE. The controller validated the scalar
 * fields and then wrote the ids straight from the request body:
 *
 *   - `responsible_id` on a deadline — the officer the calendar names as
 *     accountable for a CBN return;
 *   - `assigned_to` on a circular — the person answerable for the response;
 *   - `affected_risk_ids` on a circular — a json array, so each element needs
 *     its own rule; a rule on the array says nothing about what is in it;
 *   - `parent_id` on a taxonomy node — the tree it is grafted onto.
 *
 * Each could therefore be pointed at another institution's row on the same
 * installation. The Form Requests carry tenant-bound Rule::exists now, which is
 * the shape every other module in this programme uses; these tests are what
 * says so.
 */
class RegulatoryTenancyTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrg;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['regulatory.view', 'regulatory.manage', 'regulatory.file'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['regulatory.view', 'regulatory.manage', 'regulatory.file']);

        $this->otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->outsider = User::create([
            'name' => 'Their Officer',
            'email' => 'outsider@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->otherOrg->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_deadline_cannot_name_another_tenants_officer(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-deadline'), [
                'regulator' => 'CBN',
                'report_type' => 'ORMS return',
                'title' => 'Quarterly ORMS',
                'deadline_date' => now()->addMonth()->toDateString(),
                'frequency' => 'quarterly',
                'responsible_id' => $this->outsider->id,
            ])
            ->assertSessionHasErrors('responsible_id');

        $this->assertSame(0, RegulatoryDeadline::count());
    }

    /** And a frequency the enum column cannot hold is refused, not stored. */
    #[Test]
    public function a_deadline_frequency_outside_the_column_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-deadline'), [
                'regulator' => 'CBN',
                'report_type' => 'ORMS return',
                'title' => 'Quarterly ORMS',
                'deadline_date' => now()->addMonth()->toDateString(),
                'frequency' => 'fortnightly',
            ])
            ->assertSessionHasErrors('frequency');

        $this->assertSame(0, RegulatoryDeadline::count());
    }

    #[Test]
    public function a_circular_cannot_be_assigned_to_another_tenants_user(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-circular'), array_merge($this->circularPayload(), [
                'assigned_to' => $this->outsider->id,
            ]))
            ->assertSessionHasErrors('assigned_to');

        $this->assertSame(0, RegulatoryCircular::count());
    }

    /**
     * Each element of the json array, not just the array.
     */
    #[Test]
    public function a_circular_cannot_name_another_tenants_risk(): void
    {
        $foreignRisk = TenantContext::bypass(function () {
            $category = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            return Risk::create([
                'organization_id' => $this->otherOrg->id,
                'risk_code' => 'RK-THEIRS',
                'title' => 'Their risk',
                'description' => 'A risk on another institution\'s register.',
                'category_id' => $category->id,
                'status' => 'active',
                'created_by' => $this->outsider->id,
            ]);
        });

        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-circular'), array_merge($this->circularPayload(), [
                'affected_risk_ids' => [$foreignRisk->id],
            ]))
            ->assertSessionHasErrors('affected_risk_ids.0');

        $this->assertSame(0, RegulatoryCircular::count());
    }

    /** The same field, used properly, round-trips. */
    #[Test]
    public function a_circular_records_the_risks_it_affects(): void
    {
        $ours = $this->makeRisk(['title' => 'Our risk']);

        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-circular'), array_merge($this->circularPayload(), [
                'affected_risk_ids' => [$ours->id],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $circular = RegulatoryCircular::firstOrFail();

        $this->assertSame([$ours->id], $circular->affected_risk_ids);
        $this->assertSame('not_assessed', $circular->compliance_status);
    }

    #[Test]
    public function a_taxonomy_node_cannot_be_parented_under_another_tenants_tree(): void
    {
        $foreignNode = TenantContext::bypass(fn () => RiskTaxonomy::create([
            'organization_id' => $this->otherOrg->id,
            'name' => 'Their root',
            'depth' => 0,
        ]));

        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-taxonomy'), [
                'name' => 'Grafted',
                'parent_id' => $foreignNode->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame(0, RiskTaxonomy::count());
    }

    /** Depth follows the parent, as the tree renderer expects. */
    #[Test]
    public function a_child_node_takes_its_parents_depth_plus_one(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-taxonomy'), ['name' => 'Root', 'framework' => 'Basel III'])
            ->assertSessionHasNoErrors();

        $root = RiskTaxonomy::firstOrFail();

        $this->actingAs($this->actor)
            ->post(route('risk.regulatory.store-taxonomy'), ['name' => 'Child', 'parent_id' => $root->id])
            ->assertSessionHasNoErrors();

        $child = RiskTaxonomy::where('name', 'Child')->firstOrFail();

        $this->assertSame(0, (int) $root->depth);
        $this->assertSame(1, (int) $child->depth);
    }

    /** Another tenant's circular is out of reach on every route that binds one. */
    #[Test]
    public function another_tenants_circular_is_out_of_reach(): void
    {
        $foreign = TenantContext::bypass(fn () => RegulatoryCircular::create([
            'organization_id' => $this->otherOrg->id,
            'regulator' => 'CBN',
            'circular_ref' => 'CIR-THEIRS',
            'title' => 'Theirs',
            'date_issued' => now()->subMonth(),
        ]));

        // The OrganizationScope means route-model binding never resolves it,
        // so this is a 404 rather than the policy's 403 — the record does not
        // exist for this tenant.
        $this->actingAs($this->actor)->get(route('risk.regulatory.show-circular', $foreign))->assertNotFound();
        $this->actingAs($this->actor)
            ->patch(route('risk.regulatory.update-compliance', $foreign), ['compliance_status' => 'compliant'])
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function circularPayload(): array
    {
        return [
            'regulator' => 'CBN',
            'circular_ref' => 'CIR-2026-001',
            'title' => 'Operational risk capital guidance',
            'date_issued' => now()->subMonth()->toDateString(),
        ];
    }
}
