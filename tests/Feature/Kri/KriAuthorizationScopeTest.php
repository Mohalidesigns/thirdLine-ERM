<?php

namespace Tests\Feature\Kri;

use App\Authorization\RiskPermissionCatalog;
use App\Enums\KriContactRole;
use App\Models\KriReturn;
use App\Models\KriReturnNotice;
use App\Models\User;
use App\Presenters\NavPresenter;
use App\Services\MyResponsibilitiesService;
use App\Support\Kri\KriDepartmentScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 sections 10, 14, 15 and A1.1: who sees and does what, and the tenant
 * wall around all of it. Everything is asserted through the real routes.
 */
class KriAuthorizationScopeTest extends KriCycleTestCase
{
    private KriReturn $return;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptKri();
        $this->return = $this->makeReturn();
    }

    private function assignTo(User $user, \App\Models\BusinessUnit $unit, bool $descendants = false): void
    {
        DB::table('business_unit_user')->insert([
            'organization_id' => $unit->organization_id, 'user_id' => $user->id, 'business_unit_id' => $unit->id,
            'includes_descendants' => $descendants, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  A1.1: the read-only return, for every contact */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_team_lead_with_no_kri_permission_reaches_the_read_only_return_through_the_reminder_link(): void
    {
        $lead = $this->makeStaff('Tola Lead');
        $this->makeContact($this->unit, $lead, KriContactRole::TeamLead);

        $this->actingAs($lead)->get(route('risk.kri.returns.show', $this->return))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/MyReturn')
                ->where('readOnly', true)
                ->where('departments', [])
                ->where('urls', ['draft' => null, 'submit' => null, 'reopen' => null])
                ->where('return.can_save', false)
                ->where('return.can_submit', false)
                ->where('return.lock_version', 0)
                ->where('lines.0.kri.url', null, 'no link to a KRI the viewer may not open')
                ->where('lines.0.kri.direction', 'higher_worse'));
    }

    #[Test]
    public function every_contact_role_may_view_but_only_a_contact_of_that_exact_department(): void
    {
        foreach ([KriContactRole::RiskChampion, KriContactRole::TeamLead, KriContactRole::UnitHead, KriContactRole::GroupHead] as $role) {
            $user = $this->makeStaff($role->label());
            $this->makeContact($this->unit, $user, $role);
            $this->actingAs($user)->get(route('risk.kri.returns.show', $this->return))->assertOk();
            $this->actingAs($user)->getJson(route('risk.kri.returns.notices', $this->return))->assertOk()->assertJsonStructure(['notices']);
        }

        $other = $this->makeUnit('Operations', 'OPS');
        $elsewhere = $this->makeStaff('Ops Lead');
        $this->makeContact($other, $elsewhere, KriContactRole::TeamLead);
        $stranger = $this->makeStaff('Stranger');

        foreach ([$elsewhere, $stranger] as $user) {
            $this->actingAs($user)->get(route('risk.kri.returns.show', $this->return))->assertForbidden();
            $this->actingAs($user)->getJson(route('risk.kri.returns.notices', $this->return))->assertForbidden();
        }
    }

    #[Test]
    public function a_contact_of_the_parent_unit_is_not_a_contact_of_its_child(): void
    {
        $child = $this->makeUnit('Treasury Desk', 'TRS-D', null, $this->unit->id);
        $this->deptKri($child);
        $childReturn = $this->makeReturn($child);
        $head = $this->makeStaff('Parent Head');
        $this->makeContact($this->unit, $head, KriContactRole::UnitHead);

        $this->actingAs($head)->get(route('risk.kri.returns.show', $childReturn))->assertForbidden();
    }

    #[Test]
    public function a_non_contact_needs_kri_view_and_scope_or_the_all_departments_permission(): void
    {
        $viewerOnly = $this->makeStaff('Viewer', ['kri.view']);
        $this->actingAs($viewerOnly)->get(route('risk.kri.returns.show', $this->return))->assertForbidden();

        $other = $this->makeUnit('Operations', 'OPS');
        $scopedElsewhere = $this->makeStaff('Ops Viewer', ['kri.view']);
        $this->assignTo($scopedElsewhere, $other);
        $this->actingAs($scopedElsewhere)->get(route('risk.kri.returns.show', $this->return))->assertForbidden();

        $scopedHere = $this->makeStaff('Treasury Viewer', ['kri.view']);
        $this->assignTo($scopedHere, $this->unit);
        $this->actingAs($scopedHere)->get(route('risk.kri.returns.show', $this->return))->assertOk();

        $all = $this->makeStaff('Everything', ['kri.all_departments']);
        $this->actingAs($all)->get(route('risk.kri.returns.show', $this->return))->assertOk();
    }

    #[Test]
    public function a_viewer_with_kri_view_sees_draft_values_marked_in_the_props(): void
    {
        $line = $this->lineFor($this->return, \App\Models\KeyRiskIndicator::query()->where('business_unit_id', $this->unit->id)->firstOrFail());
        $line->forceFill(['value' => 7])->save();
        $viewer = $this->makeStaff('All Viewer', ['kri.view', 'kri.all_departments']);

        $this->actingAs($viewer)->get(route('risk.kri.returns.show', $this->return))
            ->assertInertia(fn (Assert $page) => $page
                ->where('readOnly', true)
                ->where('lines.0.value', fn ($v) => (float) $v === 7.0)
                ->where('return.status', 'draft'));
    }

    #[Test]
    public function another_tenants_return_is_a_404_not_a_403(): void
    {
        $theirs = $this->makeReturn($this->foreignDepartment(), [], syncLines: false);
        $everything = $this->makeStaff('Everything', ['kri.view', 'kri.all_departments', 'kri.send_reminder']);

        $this->actingAs($everything)->get(route('risk.kri.returns.show', $theirs))->assertNotFound();
        $this->actingAs($everything)->getJson(route('risk.kri.returns.notices', $theirs))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Champions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_champion_sees_only_their_own_departments_on_complete_my_kris(): void
    {
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $ops = $this->makeUnit('Operations', 'OPS');
        $risk = $this->makeUnit('Risk Office', 'RSK');
        $this->makeContact($this->unit, $champion);
        $this->makeContact($ops, $champion);

        $this->actingAs($champion)->get(route('risk.kri.my-return'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/MyReturn')
                ->where('readOnly', false)
                ->where('departments', fn ($d) => collect($d)->pluck('name')->sort()->values()->all() === ['Operations', 'Treasury'])
                ->where('department.name', 'Operations', 'the default is the first department by name'));

        $this->actingAs($champion)->get(route('risk.kri.my-return', ['department' => $risk->id]))->assertForbidden();
        $this->actingAs($champion)->get(route('risk.kri.my-return', ['department' => $this->unit->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('department.name', 'Treasury')->where('return.can_submit', true)->where('return.id', $this->return->id));
    }

    #[Test]
    public function a_user_who_is_a_champion_of_nothing_gets_the_not_configured_state(): void
    {
        $user = $this->makeStaff('Not Champion', ['kri.submit_return']);

        $this->actingAs($user)->get(route('risk.kri.my-return'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'not_configured')->where('lines', []));
    }

    #[Test]
    public function a_champion_of_a_parent_is_not_a_champion_of_its_children(): void
    {
        $child = $this->makeUnit('Treasury Desk', 'TRS-D', null, $this->unit->id);
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);

        $this->assertSame([$this->unit->id], app(KriDepartmentScope::class)->championUnitIdsFor($champion));
        $this->actingAs($champion)->get(route('risk.kri.my-return', ['department' => $child->id]))->assertForbidden();
    }

    #[Test]
    public function a_department_with_no_return_yet_shows_the_not_open_state_with_the_expected_date(): void
    {
        $ops = $this->makeUnit('Operations', 'OPS');
        $champion = $this->makeStaff('Ops Champion', ['kri.submit_return']);
        $this->makeContact($ops, $champion);

        $this->actingAs($champion)->get(route('risk.kri.my-return'))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'not_open')->where('return', null));
    }

    #[Test]
    public function a_champion_gets_403_on_the_edit_kri_route_and_the_update_endpoint(): void
    {
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);
        $kri = \App\Models\KeyRiskIndicator::query()->where('business_unit_id', $this->unit->id)->firstOrFail();

        $this->actingAs($champion)->get(route('risk.kri.edit', $kri))->assertForbidden();
        $this->actingAs($champion)->putJson(route('risk.kri.update', $kri), ['kri_name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($champion)->deleteJson(route('risk.kri.destroy', $kri))->assertForbidden();
        $this->actingAs($champion)->putJson(route('risk.kri.thresholds.update'), [])->assertForbidden();

        $this->assertNotSame('Hijacked', $kri->fresh()->name);
    }

    #[Test]
    public function the_catalog_grants_a_champion_role_the_submit_permission_and_nothing_that_edits_a_kri(): void
    {
        $catalog = new RiskPermissionCatalog;

        $owner = $catalog->permissionsFor('risk-owner');
        $this->assertContains('kri.submit_return', $owner);
        $this->assertNotContains('kri.edit', $owner);
        $this->assertNotContains('kri.send_reminder', $owner);

        foreach (['risk-manager', 'chief-risk-officer'] as $role) {
            $perms = $catalog->permissionsFor($role);
            foreach (['kri.submit_return', 'kri.send_reminder', 'kri.manage_department_contacts', 'kri.all_departments', 'holiday.manage'] as $p) {
                $this->assertContains($p, $perms, "{$role} holds {$p}");
            }
        }

        $this->assertContains('kri.all_departments', $catalog->permissionsFor('risk-analyst'));
        $this->assertNotContains('kri.send_reminder', $catalog->permissionsFor('risk-analyst'));

        foreach (['kri.submit_return', 'kri.send_reminder', 'kri.manage_department_contacts', 'kri.all_departments', 'holiday.manage'] as $p) {
            $this->assertTrue($catalog->has($p));
            $this->assertGreaterThan(40, strlen($catalog->all()[$p]), "{$p} describes the authority it grants");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Reminders */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function kri_send_reminder_is_enforced_on_the_endpoint(): void
    {
        Mail::fake();
        $viewer = $this->makeStaff('Viewer', ['kri.view', 'kri.all_departments']);

        $this->actingAs($viewer)->postJson(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]])->assertForbidden();

        $this->assertSame(0, KriReturnNotice::query()->count());
    }

    #[Test]
    public function the_reminder_post_flashes_the_results_and_the_next_page_receives_them(): void
    {
        Mail::fake();
        $officer = $this->makeStaff('Officer', ['kri.view', 'kri.send_reminder', 'kri.all_departments']);
        $page = route('risk.kri.returns.index');

        $this->actingAs($officer)->from($page)
            ->post(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]])
            ->assertRedirect($page)
            ->assertSessionHas('reminderResults', [[
                'return_id' => $this->return->id, 'department' => 'Treasury', 'outcome' => 'queued', 'tier' => 1,
            ]])
            ->assertSessionHas('success');

        $this->assertSame(1, KriReturnNotice::query()->where('kind', 'reminder')->count());

        // The next page carries them, then they are gone.
        $this->get($page)->assertInertia(fn (Assert $p) => $p
            ->where('flash.reminderResults.0.outcome', 'queued')
            ->where('flash.reminderResults.0.tier', 1)
            ->where('flash.reminderResults.0.department', 'Treasury')
            ->where('flash.success', fn ($s) => $s !== null));
        $this->get($page)->assertInertia(fn (Assert $p) => $p->where('flash.reminderResults', null));
    }

    #[Test]
    public function a_second_reminder_the_same_day_is_reported_as_already_reminded_not_an_error(): void
    {
        Mail::fake();
        $officer = $this->makeStaff('Officer', ['kri.view', 'kri.send_reminder', 'kri.all_departments']);
        $post = fn () => $this->actingAs($officer)->post(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]]);

        $post();
        $post()->assertSessionHas('reminderResults.0.outcome', 'already_reminded_today')->assertSessionHas('warning');

        $this->assertSame(1, KriReturnNotice::query()->where('kind', 'reminder')->count());
    }

    #[Test]
    public function the_reminder_body_is_validated_and_tenant_bound(): void
    {
        $officer = $this->makeStaff('Officer', ['kri.send_reminder', 'kri.all_departments']);
        $theirs = $this->makeReturn($this->foreignDepartment(), [], syncLines: false);
        $post = fn (array $body) => $this->actingAs($officer)->postJson(route('risk.kri.returns.remind'), $body);

        $post([])->assertStatus(422)->assertJsonValidationErrors(['return_ids']);
        $post(['return_ids' => []])->assertStatus(422);
        $post(['return_ids' => [$theirs->id]])->assertStatus(422)->assertJsonValidationErrors(['return_ids.0']);
        $post(['return_ids' => [$this->return->id, $this->return->id]])->assertStatus(422)->assertJsonValidationErrors(['return_ids.0']);
        $post(['return_ids' => range(1, 51)])->assertStatus(422)->assertJsonValidationErrors(['return_ids']);

        $this->assertSame(0, KriReturnNotice::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_reminder_endpoint_is_rate_limited_per_user_and_the_limiter_is_registered(): void
    {
        Mail::fake();
        $this->assertNotNull(RateLimiter::limiter('kri-reminders'), 'registered at boot so it survives route:cache');

        $officer = $this->makeStaff('Officer', ['kri.send_reminder', 'kri.all_departments']);
        $middleware = Route::getRoutes()->getByName('risk.kri.returns.remind')->gatherMiddleware();
        $this->assertContains('throttle:kri-reminders', $middleware);

        for ($i = 1; $i <= 20; $i++) {
            $this->actingAs($officer)->post(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]])->assertRedirect();
        }

        $this->actingAs($officer)->postJson(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]])->assertStatus(429);

        // Per user: somebody else is not locked out by it.
        $other = $this->makeStaff('Other Officer', ['kri.send_reminder', 'kri.all_departments']);
        $this->actingAs($other)->postJson(route('risk.kri.returns.remind'), ['return_ids' => [$this->return->id]])->assertRedirect();
    }

    /* ------------------------------------------------------------------ */
    /*  Library and drill-down scope */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_library_and_the_drill_down_show_a_scoped_user_only_their_departments(): void
    {
        $ops = $this->makeUnit('Operations', 'OPS');
        $this->deptKri($ops);
        $opsReturn = $this->makeReturn($ops);
        \App\Models\KriCycleRun::create([
            'organization_id' => $this->organization->id, 'period_id' => $this->return->period_id, 'run_on' => '2026-11-02',
            'trigger' => 'schedule', 'forced' => false, 'status' => 'completed', 'started_at' => now(),
        ]);

        $scoped = $this->makeStaff('Ops Viewer', ['kri.view']);
        $this->assignTo($scoped, $ops);

        $this->actingAs($scoped)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments', fn ($d) => collect($d)->pluck('name')->all() === ['Operations']));

        $this->actingAs($scoped)->get(route('risk.kri.returns.index', ['filter' => 'all']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', fn ($rows) => collect($rows)->pluck('return_id')->all() === [$opsReturn->id])
                ->where('summary.denominator', 1));

        $this->actingAs($scoped)->get(route('risk.kri.departments.show', $this->unit))->assertForbidden();
        $this->actingAs($scoped)->get(route('risk.kri.departments.show', $ops))->assertOk();
        $this->actingAs($scoped)->get(route('risk.kri.departments.unassigned'))->assertOk();
    }

    #[Test]
    public function an_unassigned_user_gets_an_empty_library_and_a_sentence_saying_why_never_everything(): void
    {
        $nobody = $this->makeStaff('Unassigned Viewer', ['kri.view']);

        $this->actingAs($nobody)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments', [])->where('scopeNote', fn ($n) => is_string($n) && $n !== ''));

        $this->assertSame([], app(KriDepartmentScope::class)->unitIdsFor($nobody), 'an empty list means nothing, never everything');
        $this->assertNull(app(KriDepartmentScope::class)->unitIdsFor(null), 'console and queue are bounded by the tenant, not by a user');
    }

    #[Test]
    public function a_holder_of_all_departments_sees_every_department(): void
    {
        $this->makeUnit('Operations', 'OPS');
        $all = $this->makeStaff('Everything', ['kri.view', 'kri.all_departments']);

        $this->actingAs($all)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments', fn ($d) => collect($d)->pluck('name')->sort()->values()->all() === ['Operations', 'Treasury']));
    }

    #[Test]
    public function a_user_without_kri_view_is_refused_every_library_and_drill_down_route(): void
    {
        $nobody = $this->makeStaff('Nobody');

        foreach ([
            route('risk.kri.index'), route('risk.kri.departments.show', $this->unit), route('risk.kri.departments.unassigned'),
            route('risk.kri.returns.index'), route('risk.kri.dashboard'),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function another_tenants_department_is_a_404(): void
    {
        $theirs = $this->foreignDepartment();
        $all = $this->makeStaff('Everything', ['kri.view', 'kri.all_departments']);

        $this->actingAs($all)->get(route('risk.kri.departments.show', $theirs))->assertNotFound();
    }

    #[Test]
    public function the_drill_down_never_lists_another_tenants_returns(): void
    {
        $theirUnit = $this->foreignDepartment();
        $this->deptKri($theirUnit, ['organization_id' => $this->otherOrg->id, 'risk_id' => $this->foreignRisk->id]);
        $theirs = $this->makeReturn($theirUnit);
        \App\Models\KriCycleRun::create([
            'organization_id' => $this->organization->id, 'period_id' => $this->return->period_id, 'run_on' => '2026-11-02',
            'trigger' => 'schedule', 'forced' => false, 'status' => 'completed', 'started_at' => now(),
        ]);
        $all = $this->makeStaff('Everything', ['kri.view', 'kri.all_departments']);

        $this->actingAs($all)->get(route('risk.kri.returns.index', ['filter' => 'all']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', fn ($rows) => ! collect($rows)->pluck('return_id')->contains($theirs->id)
                && collect($rows)->pluck('return_id')->contains($this->return->id)));
    }

    /* ------------------------------------------------------------------ */
    /*  Navigation, routes and "My responsibilities" */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function navRoutes(User $user): array
    {
        $nav = app(NavPresenter::class)->for($user);

        return collect($nav['sections'])->flatMap(fn ($s) => collect($s['items'])->pluck('route'))->all();
    }

    #[Test]
    public function complete_my_kris_is_shown_only_to_a_configured_champion_who_holds_the_permission(): void
    {
        $permissionOnly = $this->makeStaff('Permission Only', ['kri.submit_return']);
        $this->assertNotContains('risk.kri.my-return', $this->navRoutes($permissionOnly), 'a role with the permission but no department is not shown a dead end');

        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);
        $this->assertContains('risk.kri.my-return', $this->navRoutes($champion));

        $contactWithoutPermission = $this->makeStaff('Contact Only');
        $this->makeContact($this->unit, $contactWithoutPermission);
        $this->assertNotContains('risk.kri.my-return', $this->navRoutes($contactWithoutPermission));
    }

    #[Test]
    public function the_contacts_and_holidays_entries_follow_their_permissions_and_the_group_covers_the_holiday_prefix(): void
    {
        $this->assertContains('risk.kri.contacts.index', $this->navRoutes($this->makeStaff('Contacts', ['kri.manage_department_contacts'])));
        $this->assertContains('risk.holidays.index', $this->navRoutes($this->makeStaff('Holidays', ['holiday.manage'])));
        $this->assertNotContains('risk.holidays.index', $this->navRoutes($this->makeStaff('Nobody')));

        $group = collect(NavPresenter::sections())->firstWhere('key', 'kri_monitoring');
        $this->assertSame(['/risk/kri', '/risk/settings/holidays'], $group['prefixes']);
    }

    #[Test]
    public function literal_kri_routes_win_over_the_kri_id_wildcard(): void
    {
        foreach ([
            '/risk/kri/my-return' => 'risk.kri.my-return',
            '/risk/kri/my-return/1/2' => 'risk.kri.my-return',
            '/risk/kri/returns' => 'risk.kri.returns.index',
            '/risk/kri/returns/1' => 'risk.kri.returns.show',
            '/risk/kri/returns/1/notices' => 'risk.kri.returns.notices',
            '/risk/kri/settings/contacts' => 'risk.kri.contacts.index',
            '/risk/kri/departments/unassigned' => 'risk.kri.departments.unassigned',
            '/risk/kri/departments/3' => 'risk.kri.departments.show',
            '/risk/settings/holidays' => 'risk.holidays.index',
        ] as $uri => $name) {
            $this->assertSame($name, Route::getRoutes()->match(Request::create($uri, 'GET'))->getName(), $uri);
        }
    }

    #[Test]
    public function every_adr_route_exists_with_the_documented_guard(): void
    {
        $expected = [
            'risk.kri.departments.unassigned' => 'permission:kri.view',
            'risk.kri.departments.show' => 'permission:kri.view',
            'risk.kri.returns.index' => 'permission:kri.view',
            'risk.kri.returns.remind' => 'permission:kri.send_reminder',
            'risk.kri.returns.show' => 'can:view,return',
            'risk.kri.returns.notices' => 'can:view,return',
            'risk.kri.my-return' => 'permission:kri.submit_return',
            'risk.kri.my-return.draft' => 'permission:kri.submit_return',
            'risk.kri.my-return.submit' => 'permission:kri.submit_return',
            'risk.kri.my-return.reopen' => 'permission:kri.submit_return',
            'risk.kri.contacts.index' => 'permission:kri.manage_department_contacts',
            'risk.kri.contacts.users' => 'permission:kri.manage_department_contacts',
            'risk.kri.contacts.store' => 'permission:kri.manage_department_contacts',
            'risk.kri.contacts.destroy' => 'permission:kri.manage_department_contacts',
            'risk.holidays.index' => 'permission:holiday.manage',
            'risk.holidays.store' => 'permission:holiday.manage',
            'risk.holidays.destroy' => 'permission:holiday.manage',
        ];

        foreach ($expected as $name => $guard) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} is not registered");
            $this->assertContains($guard, $route->gatherMiddleware(), $name);
        }

        $this->assertStringStartsWith('risk/kri/returns/{return}', Route::getRoutes()->getByName('risk.kri.returns.show')->uri());
    }

    #[Test]
    public function a_champion_sees_their_open_return_in_my_responsibilities(): void
    {
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);

        $result = app(MyResponsibilitiesService::class)->for($champion);

        $items = $result['sections']['kri_returns'];
        $this->assertCount(1, $items);
        $this->assertSame('kri_return', $items[0]['type']);
        $this->assertSame(route('risk.kri.my-return', ['department' => $this->unit->id, 'period' => $this->return->period_id]), $items[0]['url']);
        $this->assertNull($items[0]['badge']);

        $this->freezeAt('2026-11-12 06:00:00');
        $this->assertSame('Overdue', app(MyResponsibilitiesService::class)->for($champion)['sections']['kri_returns'][0]['badge']);

        $this->return->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        $this->assertCount(0, app(MyResponsibilitiesService::class)->for($champion)['sections']['kri_returns'], 'a submitted return leaves the queue');

        $this->assertCount(0, app(MyResponsibilitiesService::class)->for($this->makeStaff('Someone Else'))['sections']['kri_returns']);
    }
}
