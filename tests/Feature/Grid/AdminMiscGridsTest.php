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
use Inertia\Testing\AssertableInertia as Assert;
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

    /** The text of one cell across every presented row. */
    private static function column($rows, string $key): array
    {
        return collect($rows)->map(fn ($row) => $row['cells'][$key]['text'] ?? null)->all();
    }

    /* --------------------------------------------------------- admin_users */

    #[Test]
    public function the_user_administration_page_renders_a_seeded_user(): void
    {
        $this->makeUser('Adaeze Okonkwo', $this->organization->id);

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->where('totalUsers', 2)
                ->where('grid.name', 'admin_users')
                ->where('grid.rows.data', fn ($rows) => in_array('Adaeze Okonkwo', self::column($rows, 'name'), true)));
    }

    #[Test]
    public function another_organizations_users_never_render(): void
    {
        $this->makeUser('Adaeze Okonkwo', $this->organization->id);
        $this->makeUser('Foreign Administrator', $this->otherOrg->id);

        $this->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.rows.data', function ($rows) {
                    $names = self::column($rows, 'name');

                    return in_array('Adaeze Okonkwo', $names, true) && ! in_array('Foreign Administrator', $names, true);
                }));
    }

    #[Test]
    public function the_role_filter_uses_the_spatie_scope(): void
    {
        $auditor = $this->makeUser('Role Filtered User', $this->organization->id);
        $this->makeUser('Unroled User', $this->organization->id);

        Role::findOrCreate('compliance-officer');
        $auditor->assignRole('compliance-officer');

        $this->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.rows.data', fn ($rows) => in_array('Unroled User', self::column($rows, 'name'), true)));

        $this->get(route('admin.users.index', ['filters' => ['role' => 'compliance-officer']]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.filters.role', 'compliance-officer')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Role Filtered User'));
    }

    /* ----------------------------------------------------------- circulars */

    #[Test]
    public function the_circular_register_renders_a_seeded_circular(): void
    {
        $this->makeCircular('Revised capital adequacy guidance', $this->organization->id);

        $this->get(route('risk.regulatory.circulars'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Regulatory/Circulars')
                ->where('grid.name', 'circulars')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Revised capital adequacy guidance'));
    }

    #[Test]
    public function another_organizations_circulars_never_render(): void
    {
        $this->makeCircular('Our capital guidance', $this->organization->id);
        $this->makeCircular('Their capital guidance', $this->otherOrg->id);

        $this->get(route('risk.regulatory.circulars'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our capital guidance'));
    }

    /* ------------------------------------------------------ emerging_risks */

    #[Test]
    public function the_emerging_risk_register_renders_a_seeded_entry(): void
    {
        $this->makeEmergingRisk('Quantum decryption of stored records', $this->organization->id);

        $this->get(route('risk.emerging.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emerging/Index')
                ->where('grid.name', 'emerging_risks')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Quantum decryption of stored records'));
    }

    #[Test]
    public function another_organizations_emerging_risks_never_render(): void
    {
        $this->makeEmergingRisk('Our horizon entry', $this->organization->id);
        $this->makeEmergingRisk('Their horizon entry', $this->otherOrg->id);

        $this->get(route('risk.emerging.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our horizon entry'));
    }

    #[Test]
    public function marking_reviewed_in_bulk_stamps_todays_date(): void
    {
        $entry = $this->makeEmergingRisk('Unreviewed horizon entry', $this->organization->id);
        $this->assertNull($entry->last_reviewed_at);

        $this->post(route('risk.grids.bulk', ['emerging_risks', 'mark_reviewed']), ['ids' => [(string) $entry->id]])
            ->assertRedirect();

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

        $this->actingAs($viewer)
            ->post(route('risk.grids.bulk', ['emerging_risks', 'delete']), ['ids' => [(string) $entry->id]])
            ->assertForbidden();

        $this->assertNull($entry->fresh()->deleted_at);

        $this->actingAs($this->actor)
            ->post(route('risk.grids.bulk', ['emerging_risks', 'delete']), ['ids' => [(string) $entry->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('emerging_risks', ['id' => $entry->id]);
    }

    /* ----------------------------------------------------- reports_library */

    #[Test]
    public function the_report_library_renders_a_seeded_report(): void
    {
        $this->makeReport('March board pack', $this->organization->id);

        $this->get(route('risk.reports.library'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Library')
                ->has('types')
                ->where('grid.name', 'reports_library')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'March board pack'));
    }

    #[Test]
    public function another_organizations_reports_never_render(): void
    {
        $this->makeReport('Our board pack', $this->organization->id);
        $this->makeReport('Their board pack', $this->otherOrg->id);

        $this->get(route('risk.reports.library'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Our board pack'));
    }

    /* --------------------------------------------------- approvals_history */

    #[Test]
    public function the_approval_history_page_renders_a_decided_request(): void
    {
        $approval = $this->makeApproval('treatment_plan', $this->organization->id);

        $this->get(route('risk.approvals.history'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Approvals/History')
                ->where('grid.name', 'approvals_history')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity.text', 'treatment_plan #'.$approval->entity_id));
    }

    #[Test]
    public function another_organizations_approvals_never_render_and_pending_stays_out_of_history(): void
    {
        $mine = $this->makeApproval('risk', $this->organization->id);
        $this->makeApproval('loss_event', $this->otherOrg->id);

        // History is the decided record, never the pending queue.
        $this->makeApproval('control_test', $this->organization->id, [
            'status' => 'pending',
            'reviewed_at' => null,
        ]);

        $this->get(route('risk.approvals.history'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.entity.text', 'risk #'.$mine->entity_id));
    }
}
