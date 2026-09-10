<?php

namespace Tests\Feature\Grid;

use App\Models\Issue;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the issues register on the shared data grid
 * (App\Grids\Definitions\IssuesGrid).
 */
class IssuesGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('issue.view');
        $this->actor->givePermissionTo('issue.view');

        $this->actingAs($this->actor);
    }

    private function makeIssue(array $attributes = []): Issue
    {
        static $sequence = 0;
        $sequence++;

        return Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'issue_reference' => sprintf('ISS-TEST-%04d', $sequence),
            'title' => "Issue {$sequence}",
            'description' => "Fixture issue {$sequence}",
            'issue_source' => 'INTERNAL_AUDIT',
            'issue_category' => 'OPERATIONAL',
            'priority' => 'high',
            'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => now()->addDays(30),
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    #[Test]
    public function the_issues_index_page_renders_a_seeded_row(): void
    {
        $this->makeIssue(['title' => 'Stale firewall rulebase']);

        $this->get(route('risk.issues.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Issues/Index')
                ->where('grid.name', 'issues')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Stale firewall rulebase'));
    }

    #[Test]
    public function search_narrows_the_grid_server_side(): void
    {
        $this->makeIssue(['title' => 'Stale firewall rulebase']);
        $this->makeIssue(['title' => 'Missing KYC files']);

        $this->get(route('risk.issues.index'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.issues.index', ['search' => 'firewall']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Stale firewall rulebase'));
    }

    #[Test]
    public function another_organizations_issues_never_render(): void
    {
        $this->makeIssue(['title' => 'Our own issue']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        Issue::create([
            'organization_id' => $otherOrg->id,
            'issue_reference' => 'ISS-FOREIGN-0001',
            'title' => 'Their foreign issue',
            'description' => 'Belongs to another tenant',
            'issue_source' => 'INTERNAL_AUDIT',
            'issue_category' => 'OPERATIONAL',
            'priority' => 'high',
            'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => now()->addDays(30),
            'created_by' => $this->actor->id,
        ]);

        $this->get(route('risk.issues.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our own issue'));
    }
}
