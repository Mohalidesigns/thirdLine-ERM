<?php

namespace Tests\Feature\Grid;

use App\Models\ApprovalRequest;
use App\Models\EmergingRisk;
use App\Models\GeneratedReport;
use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the five administrative / long-tail registers migrated onto the
 * shared data grid:
 *
 *   admin_users        App\Grids\Definitions\AdminUsersGrid
 *   circulars          App\Grids\Definitions\RegulatoryCircularsGrid
 *   emerging_risks     App\Grids\Definitions\EmergingRisksGrid
 *   reports_library    App\Grids\Definitions\ReportsLibraryGrid
 *   approvals_history  App\Grids\Definitions\ApprovalsHistoryGrid
 *
 * Two properties per grid, because they are the two a definition can get
 * wrong without anybody noticing until production: the page actually renders
 * (a definition with a mistyped relation or column throws only when it is
 * loaded), and the base query is tenant-scoped (the engine trusts the
 * definition for that and nothing downstream re-checks it).
 *
 * The emerging risk register additionally gets its converted row-forms
 * exercised: "mark reviewed" and delete used to be per-row POSTs behind route
 * middleware and are now bulk actions that must re-check their own permission.
 */
class AdminMiscGridsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $permissions = [
            'admin.users',
            'regulatory.view', 'regulatory.manage',
            'risk.view', 'risk.create', 'risk.edit', 'risk.delete',
            'report.view',
            'approval.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo($permissions);

        $this->actingAs($this->actor);
    }

    /* ------------------------------------------------------------ fixtures */

    private function makeUser(string $name, int $organizationId, array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'organization_id' => $organizationId,
            'name' => $name,
            'email' => "grid-user-{$sequence}@example.test",
            'password' => Hash::make('secret-password'),
            'staff_id' => sprintf('STF-%03d', $sequence),
            'is_active' => true,
        ], $attributes));
    }

    private function makeCircular(string $title, int $organizationId, array $attributes = []): RegulatoryCircular
    {
        static $sequence = 0;
        $sequence++;

        return RegulatoryCircular::create(array_merge([
            'organization_id' => $organizationId,
            'regulator' => 'CBN',
            'circular_ref' => sprintf('CBN/BSD/%03d', $sequence),
            'title' => $title,
            'date_issued' => now()->subDays(10)->toDateString(),
            'impact_level' => 'high',
            'compliance_status' => 'partially_compliant',
        ], $attributes));
    }

    private function makeEmergingRisk(string $title, int $organizationId, array $attributes = []): EmergingRisk
    {
        static $sequence = 0;
        $sequence++;

        return EmergingRisk::create(array_merge([
            'organization_id' => $organizationId,
            'reference' => sprintf('EMR-%04d', $sequence),
            'title' => $title,
            'horizon' => '6-12m',
            'velocity_score' => 4,
            'proximity_score' => 3,
            'potential_impact' => 'High',
            'status' => 'monitoring',
        ], $attributes));
    }

    private function makeReport(string $name, int $organizationId, array $attributes = []): GeneratedReport
    {
        return GeneratedReport::create(array_merge([
            'organization_id' => $organizationId,
            'name' => $name,
            'report_type' => 'board_pack',
            'scope' => 'risks',
            'status' => 'completed',
            'format' => 'pdf',
            'version' => 1,
            'period_as_at' => now()->subMonth()->toDateString(),
        ], $attributes));
    }

    private function makeApproval(string $entityType, int $organizationId, array $attributes = []): ApprovalRequest
    {
        static $sequence = 0;
        $sequence++;

        return ApprovalRequest::create(array_merge([
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'entity_id' => $sequence,
            'action' => 'update',
            'status' => 'approved',
            'requested_at' => now()->subDays(3),
            'reviewed_at' => now()->subDay(),
            'comments' => "Signed off by committee for {$entityType}.",
        ], $attributes));
    }

    /* --------------------------------------------------------- admin_users */

    #[Test]
    public function the_user_administration_page_renders_a_seeded_user(): void
    {
        $this->makeUser('Adaeze Okonkwo', $this->organization->id);

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management')
            ->assertSee('Adaeze Okonkwo');
    }

    #[Test]
    public function another_organizations_users_never_render(): void
    {
        $this->makeUser('Adaeze Okonkwo', $this->organization->id);
        $this->makeUser('Foreign Administrator', $this->otherOrg->id);

        Livewire::test('data-grid', ['grid' => 'admin_users'])
            ->assertSee('Adaeze Okonkwo')
            ->assertDontSee('Foreign Administrator');
    }

    #[Test]
    public function the_role_filter_uses_the_spatie_scope(): void
    {
        $auditor = $this->makeUser('Role Filtered User', $this->organization->id);
        $this->makeUser('Unroled User', $this->organization->id);

        Role::findOrCreate('compliance-officer');
        $auditor->assignRole('compliance-officer');

        Livewire::test('data-grid', ['grid' => 'admin_users'])
            ->assertSee('Unroled User')
            ->set('filters.role', 'compliance-officer')
            ->assertSee('Role Filtered User')
            ->assertDontSee('Unroled User');
    }

    /* ----------------------------------------------------------- circulars */

    #[Test]
    public function the_circular_register_renders_a_seeded_circular(): void
    {
        $this->makeCircular('Revised capital adequacy guidance', $this->organization->id);

        $this->get(route('risk.regulatory.circulars'))
            ->assertOk()
            ->assertSee('Regulatory Circulars')
            ->assertSee('Revised capital adequacy guidance');
    }

    #[Test]
    public function another_organizations_circulars_never_render(): void
    {
        $this->makeCircular('Our capital guidance', $this->organization->id);
        $this->makeCircular('Their capital guidance', $this->otherOrg->id);

        Livewire::test('data-grid', ['grid' => 'circulars'])
            ->assertSee('Our capital guidance')
            ->assertDontSee('Their capital guidance');
    }

    /* ------------------------------------------------------ emerging_risks */

    #[Test]
    public function the_emerging_risk_register_renders_a_seeded_entry(): void
    {
        $this->makeEmergingRisk('Quantum decryption of stored records', $this->organization->id);

        $this->get(route('risk.emerging.index'))
            ->assertOk()
            ->assertSee('Emerging Risk Register')
            ->assertSee('Quantum decryption of stored records');
    }

    #[Test]
    public function another_organizations_emerging_risks_never_render(): void
    {
        $this->makeEmergingRisk('Our horizon entry', $this->organization->id);
        $this->makeEmergingRisk('Their horizon entry', $this->otherOrg->id);

        Livewire::test('data-grid', ['grid' => 'emerging_risks'])
            ->assertSee('Our horizon entry')
            ->assertDontSee('Their horizon entry');
    }

    #[Test]
    public function marking_reviewed_in_bulk_stamps_todays_date(): void
    {
        $entry = $this->makeEmergingRisk('Unreviewed horizon entry', $this->organization->id);
        $this->assertNull($entry->last_reviewed_at);

        Livewire::test('data-grid', ['grid' => 'emerging_risks'])
            ->set('selected', [(string) $entry->id])
            ->call('runBulk', 'mark_reviewed');

        $this->assertSame(
            now()->toDateString(),
            $entry->fresh()->last_reviewed_at?->toDateString()
        );
    }

    #[Test]
    public function bulk_delete_soft_deletes_and_requires_the_delete_permission(): void
    {
        $entry = $this->makeEmergingRisk('Retired horizon entry', $this->organization->id);

        $viewer = $this->makeUser('Horizon Viewer', $this->organization->id);
        $viewer->givePermissionTo('risk.view');

        Livewire::actingAs($viewer)
            ->test('data-grid', ['grid' => 'emerging_risks'])
            ->set('selected', [(string) $entry->id])
            ->call('runBulk', 'delete')
            ->assertStatus(403);

        $this->assertNull($entry->fresh()->deleted_at);

        // Livewire::actingAs is sticky, so the actor has to be named again.
        Livewire::actingAs($this->actor)
            ->test('data-grid', ['grid' => 'emerging_risks'])
            ->set('selected', [(string) $entry->id])
            ->call('runBulk', 'delete');

        $this->assertSoftDeleted('emerging_risks', ['id' => $entry->id]);
    }

    /* ----------------------------------------------------- reports_library */

    #[Test]
    public function the_report_library_renders_a_seeded_report(): void
    {
        $this->makeReport('March board pack', $this->organization->id);

        $this->get(route('risk.reports.library'))
            ->assertOk()
            ->assertSee('Report Library')
            ->assertSee('March board pack');
    }

    #[Test]
    public function another_organizations_reports_never_render(): void
    {
        $this->makeReport('Our board pack', $this->organization->id);
        $this->makeReport('Their board pack', $this->otherOrg->id);

        Livewire::test('data-grid', ['grid' => 'reports_library'])
            ->assertSee('Our board pack')
            ->assertDontSee('Their board pack');
    }

    /* --------------------------------------------------- approvals_history */

    #[Test]
    public function the_approval_history_page_renders_a_decided_request(): void
    {
        $this->makeApproval('treatment_plan', $this->organization->id);

        $this->get(route('risk.approvals.history'))
            ->assertOk()
            ->assertSee('Approval History')
            ->assertSee('treatment_plan');
    }

    #[Test]
    public function another_organizations_approvals_never_render_and_pending_stays_out_of_history(): void
    {
        $this->makeApproval('risk', $this->organization->id);
        $this->makeApproval('loss_event', $this->otherOrg->id);

        // History is the decided record, never the pending queue.
        $this->makeApproval('control_test', $this->organization->id, [
            'status' => 'pending',
            'reviewed_at' => null,
        ]);

        // The entity cell reads "{alias} #{id}"; the bare alias also appears as
        // a value in the entity-type filter's option list, so the assertions
        // have to name the cell's shape rather than the alias alone.
        Livewire::test('data-grid', ['grid' => 'approvals_history'])
            ->assertSee('risk #')
            ->assertDontSee('loss_event #')
            ->assertDontSee('control_test #');
    }
}
