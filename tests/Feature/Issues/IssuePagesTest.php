<?php

namespace Tests\Feature\Issues;

use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Policies\IssuePolicy;
use App\Support\Migration\Ported;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/** The six ported issue screens and their policy (migration Phase 4.4). */
class IssuePagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private Issue $issue;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'issue.view', 'issue.create', 'issue.edit',
            'issue.escalate', 'issue.close', 'issue.delete',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('branch-manager');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->issue = $this->makeIssue();

        TenantContext::bypass(function () {
            $this->otherOrg = Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeIssue(array $attributes = []): Issue
    {
        static $n = 0;
        $n++;

        return Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'issue_reference' => sprintf('ISS-%04d', $n),
            'title' => "Issue {$n}",
            'description' => "Fixture issue {$n}",
            'issue_source' => 'audit',
            'issue_category' => 'Control Weakness',
            'issue_status' => 'OPEN',
            'priority' => 'high',
            'responsible_owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->syncRoles(['branch-manager']);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validIssue(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Manual journals are not reviewed',
            'description' => 'Journals above the threshold are posted without a second pair of eyes.',
            'issue_source' => 'audit',
            'issue_category' => 'Control Weakness',
            'priority' => 'high',
            'business_unit_id' => $this->unit->id,
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => now()->addMonths(2)->toDateString(),
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /*  Wiring */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_policy_is_discovered_and_the_routes_are_ported(): void
    {
        $this->assertInstanceOf(IssuePolicy::class, Gate::getPolicyFor(Issue::class));

        foreach ([
            'risk.issues.dashboard', 'risk.issues.create', 'risk.issues.show',
            'risk.issues.edit', 'risk.issues.ageing', 'risk.issues.closure',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/issues'));
    }

    #[Test]
    public function every_screen_renders(): void
    {
        foreach ([
            ['risk.issues.dashboard', [], 'Issues/Dashboard'],
            ['risk.issues.create', [], 'Issues/Create'],
            ['risk.issues.ageing', [], 'Issues/Ageing'],
            ['risk.issues.closure', [], 'Issues/Closure'],
            ['risk.issues.show', [$this->issue], 'Issues/Show'],
            ['risk.issues.edit', [$this->issue], 'Issues/Edit'],
        ] as [$name, $params, $component]) {
            $this->actingAs($this->actor)
                ->get(route($name, $params))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    #[Test]
    public function a_user_without_issue_view_is_refused(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        foreach (['risk.issues.dashboard', 'risk.issues.ageing', 'risk.issues.closure'] as $route) {
            $this->actingAs($nobody)->get(route($route))->assertForbidden();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The category defect */
    /* ------------------------------------------------------------------ */

    /**
     * The create and edit forms are rendered FROM the Issue object type, as
     * the Blade page's <x-dynamic-form> was. Writing the fields out by hand
     * would drop every field a tenant added through the builder while the
     * controller went on saving them.
     */
    #[Test]
    public function the_forms_are_rendered_from_the_object_type(): void
    {
        foreach ([
            ['risk.issues.create', []],
            ['risk.issues.edit', [$this->issue]],
        ] as [$name, $params]) {
            $props = $this->actingAs($this->actor)->get(route($name, $params))->assertOk()->inertiaProps();

            $codes = collect($props['schema']['sections'])
                ->flatMap(fn (array $section) => array_column($section['fields'], 'code'))
                ->all();

            $this->assertContains('title', $codes, $name);
            $this->assertContains('issue_source', $codes, $name);
            $this->assertContains('remediation_due_date', $codes, $name);
        }
    }

    /**
     * `recommended_action` is omitted on edit, as it was: the recommendation is
     * what the finding said, not something the owner revises while remediating.
     */
    #[Test]
    public function the_edit_form_omits_the_recommendation(): void
    {
        $props = $this->actingAs($this->actor)
            ->get(route('risk.issues.edit', $this->issue))
            ->assertOk()
            ->inertiaProps();

        $codes = collect($props['schema']['sections'])
            ->flatMap(fn (array $section) => array_column($section['fields'], 'code'))
            ->all();

        $this->assertNotContains('recommended_action', $codes);
    }

    /**
     * `issue_category` is `string(50)` NOT NULL with no default, and was
     * validated as `nullable`. Raising an issue without one hit a NOT NULL
     * violation — a 500, not a validation message.
     */
    #[Test]
    public function raising_an_issue_without_a_category_is_a_validation_error_not_a_crash(): void
    {
        $payload = $this->validIssue();
        unset($payload['issue_category']);

        $this->actingAs($this->actor)
            ->post(route('risk.issues.store'), $payload)
            ->assertSessionHasErrors('issue_category');

        $this->assertSame(1, Issue::count(), 'nothing was written');
    }

    #[Test]
    public function raising_an_issue_writes_it(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.issues.store'), $this->validIssue())
            ->assertRedirect();

        $issue = Issue::where('title', 'Manual journals are not reviewed')->firstOrFail();

        $this->assertSame('Control Weakness', $issue->issue_category);
        $this->assertSame('OPEN', $issue->issue_status);
        $this->assertSame(0, (int) $issue->current_escalation_level);
        $this->assertNotNull($issue->issue_reference);
    }

    #[Test]
    public function creating_rejects_another_tenants_business_unit(): void
    {
        $foreignUnit = TenantContext::bypass(fn () => BusinessUnit::create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'THEIRS',
            'name' => 'Their Unit',
            'is_active' => true,
        ]), 'test fixture');

        $this->actingAs($this->actor)
            ->post(route('risk.issues.store'), $this->validIssue(['business_unit_id' => $foreignUnit->id]))
            ->assertSessionHasErrors('business_unit_id');
    }

    #[Test]
    public function no_form_request_uses_the_untenanted_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Issues/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (["'exists:", '"exists:'] as $quoted) {
                $this->assertStringNotContainsString($quoted, $source, basename($file));
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The overdue flag */
    /* ------------------------------------------------------------------ */

    /**
     * The show page has always read `$issue->is_overdue`, which was neither a
     * column nor an accessor — so the overdue banner, badge and red due-date
     * never rendered once, on any issue, however late.
     */
    #[Test]
    public function an_overdue_issue_now_says_so_on_its_own_page(): void
    {
        $late = $this->makeIssue([
            'issue_status' => 'IN_PROGRESS',
            'remediation_due_date' => now()->subDays(9),
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.issues.show', $late))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('issue.isOverdue', true)
                ->where('issue.daysOverdue', 9));
    }

    #[Test]
    public function a_settled_issue_is_never_overdue_however_old(): void
    {
        $closed = $this->makeIssue([
            'issue_status' => 'CLOSED',
            'remediation_due_date' => now()->subYear(),
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.issues.show', $closed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('issue.isOverdue', false));
    }

    #[Test]
    public function an_issue_with_no_due_date_is_not_overdue(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.issues.show', $this->issue))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('issue.isOverdue', false));
    }

    /* ------------------------------------------------------------------ */
    /*  Policy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_ability_asks_for_its_own_permission(): void
    {
        foreach ([
            'view' => 'issue.view',
            'update' => 'issue.edit',
            'delete' => 'issue.delete',
            'close' => 'issue.close',
            'escalate' => 'issue.escalate',
        ] as $ability => $permission) {
            $holder = $this->userWith([$permission], "holds-{$ability}@example.test");
            $lacking = $this->userWith(['issue.view'], "lacks-{$ability}@example.test");

            $this->assertTrue($holder->can($ability, $this->issue), "{$ability} with {$permission}");

            if ($permission !== 'issue.view') {
                $this->assertFalse($lacking->can($ability, $this->issue), "{$ability} without {$permission}");
            }
        }
    }

    /**
     * Closing is not editing, and deleting is not closing. The old destroy()
     * checked `issue.close` while its route required `issue.delete` and its
     * message said "delete issues".
     */
    #[Test]
    public function closing_editing_and_deleting_are_separate(): void
    {
        $editor = $this->userWith(['issue.view', 'issue.edit'], 'editor@example.test');
        $closer = $this->userWith(['issue.view', 'issue.close'], 'closer@example.test');

        $this->assertTrue($editor->can('update', $this->issue));
        $this->assertFalse($editor->can('close', $this->issue));
        $this->assertFalse($editor->can('delete', $this->issue));

        $this->assertTrue($closer->can('close', $this->issue));
        $this->assertFalse($closer->can('update', $this->issue));
        $this->assertFalse($closer->can('delete', $this->issue));
    }

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $foreign = TenantContext::bypass(function () {
            $unit = BusinessUnit::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'THEIRS2',
                'name' => 'Their Unit',
                'is_active' => true,
            ]);

            return Issue::create([
                'organization_id' => $this->otherOrg->id,
                'business_unit_id' => $unit->id,
                'issue_reference' => 'ISS-FOREIGN',
                'title' => 'Theirs',
                'description' => 'Another bank.',
                'issue_source' => 'audit',
                'issue_category' => 'Other',
                'issue_status' => 'OPEN',
                'priority' => 'high',
                'created_by' => $this->actor->id,
            ]);
        }, 'test fixture');

        foreach (['view', 'update', 'delete', 'close', 'escalate', 'recordProgress'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $foreign), $ability);
        }
    }
}
