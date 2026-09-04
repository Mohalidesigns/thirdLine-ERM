<?php

namespace Tests\Feature\Periods;

use App\Models\MeasureThreshold;
use App\Models\Period;
use App\Models\User;
use App\Policies\MeasureThresholdPolicy;
use App\Policies\PeriodPolicy;
use App\Services\PeriodService;
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

/**
 * The reporting calendar and the re-baselining queue on Inertia
 * (migration Phase 4.2).
 */
class PeriodAndThresholdPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach ([
            'period.view', 'period.close', 'period.reopen',
            'threshold.view', 'threshold.manage', 'threshold.rebaseline_approve',
            'dashboard.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('branch-manager');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
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

    private function openPeriod(): Period
    {
        app(PeriodService::class)->ensureCalendar($this->organization->id);

        return Period::where('organization_id', $this->organization->id)
            ->where('type', 'quarter')
            ->where('is_closed', false)
            ->orderBy('start_date')
            ->firstOrFail();
    }

    /* ------------------------------------------------------------------ */
    /*  Wiring */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(PeriodPolicy::class, Gate::getPolicyFor(Period::class));
        $this->assertInstanceOf(MeasureThresholdPolicy::class, Gate::getPolicyFor(MeasureThreshold::class));
    }

    #[Test]
    public function both_routes_are_registered_as_ported(): void
    {
        $this->assertTrue(Ported::isRoute('risk.periods.index'));
        $this->assertTrue(Ported::isRoute('risk.thresholds.rebaseline'));
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/periods'));
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/thresholds'));
    }

    /* ------------------------------------------------------------------ */
    /*  Calendar */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_calendar_renders_with_its_periods(): void
    {
        $this->openPeriod();

        $this->actingAs($this->actor)
            ->get(route('risk.periods.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Periods/Index')
                ->where('type', 'quarter')
                ->has('calendar.name')
                ->has('periods.data')
                ->where('periods.data.0.canClose', true)
                ->where('periods.data.0.canReopen', true));
    }

    /**
     * The Blade table drew a "selected" badge from `$selectedPeriod`, which the
     * controller never passed — so it never rendered. It comes from the session
     * now, which is what "selected" means everywhere else.
     */
    #[Test]
    public function the_selected_period_is_marked(): void
    {
        $this->openPeriod();

        // Whatever the calendar's first row is — it lists newest first and
        // pages at 24, so picking a period by query risks one that is not on
        // this page.
        $first = collect(
            $this->actingAs($this->actor)->get(route('risk.periods.index'))->assertOk()
                ->viewData('page')['props']['periods']['data']
        )->first();

        $this->assertFalse($first['isSelected'], 'nothing is selected to begin with');

        $this->actingAs($this->actor)->get(route('risk.periods.select', [
            'period' => $first['id'],
            'redirect' => '/risk/periods',
        ]));

        $props = $this->actingAs($this->actor)
            ->get(route('risk.periods.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $selected = collect($props['periods']['data'])->firstWhere('isSelected', true);

        $this->assertNotNull($selected, 'a period is marked as selected');
        $this->assertSame($first['id'], $selected['id']);
    }

    #[Test]
    public function closing_a_period_locks_it(): void
    {
        $period = $this->openPeriod();

        $this->actingAs($this->actor)
            ->post(route('risk.periods.close', $period))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $period->fresh()->is_closed);
    }

    #[Test]
    public function closing_an_already_closed_period_says_so_rather_than_forbidding_it(): void
    {
        $period = $this->openPeriod();

        $this->actingAs($this->actor)->post(route('risk.periods.close', $period));

        $this->actingAs($this->actor)
            ->post(route('risk.periods.close', $period))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** Reopening is the one operation that can move a published number. */
    #[Test]
    public function reopening_requires_a_reason_of_substance(): void
    {
        $period = $this->openPeriod();
        $this->actingAs($this->actor)->post(route('risk.periods.close', $period));

        $this->actingAs($this->actor)
            ->post(route('risk.periods.reopen', $period), [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->actor)
            ->post(route('risk.periods.reopen', $period), ['reason' => 'too short'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue((bool) $period->fresh()->is_closed);

        $this->actingAs($this->actor)
            ->post(route('risk.periods.reopen', $period), ['reason' => 'Restating Q1 after the audit adjustment.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse((bool) $period->fresh()->is_closed);
    }

    /**
     * period.close does not imply period.reopen, and the seeder deliberately
     * gives the standard risk-manager role the first and not the second.
     */
    #[Test]
    public function closing_and_reopening_are_separate_permissions(): void
    {
        $period = $this->openPeriod();
        $closer = $this->userWith(['period.view', 'period.close'], 'closer@example.test');

        $this->assertTrue($closer->can('close', $period));
        $this->assertFalse($closer->can('reopen', $period));

        $this->actingAs($closer)->post(route('risk.periods.close', $period))->assertRedirect();

        $this->actingAs($closer)
            ->post(route('risk.periods.reopen', $period), ['reason' => 'Restating after the audit adjustment.'])
            ->assertForbidden();
    }

    #[Test]
    public function a_user_without_period_view_cannot_read_the_calendar(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        $this->actingAs($nobody)->get(route('risk.periods.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Re-baselining queue */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_rebaseline_queue_renders_empty_without_drift(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.thresholds.rebaseline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Thresholds/Rebaseline')
                ->has('pending', 0)
                ->has('history', 0)
                ->where('canDecide', true));
    }

    #[Test]
    public function a_reader_cannot_decide(): void
    {
        $reader = $this->userWith(['threshold.view'], 'reader@example.test');

        $this->actingAs($reader)
            ->get(route('risk.thresholds.rebaseline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canDecide', false));
    }

    #[Test]
    public function a_user_without_threshold_view_is_refused(): void
    {
        $nobody = $this->userWith([], 'nobody2@example.test');

        $this->actingAs($nobody)->get(route('risk.thresholds.rebaseline'))->assertForbidden();
    }

    /**
     * The approval abilities are class-level on MeasureThreshold rather than
     * instance-level on ApprovalRequest: the latter would land on
     * ApprovalRequestPolicy, which asks for `approval.act` and knows nothing
     * about threshold.rebaseline_approve.
     */
    #[Test]
    public function deciding_asks_for_the_rebaseline_permission(): void
    {
        $reader = $this->userWith(['threshold.view'], 'reader2@example.test');
        $approver = $this->userWith(['threshold.view', 'threshold.rebaseline_approve'], 'approver@example.test');

        $this->assertFalse($reader->can('rebaselineApprove', MeasureThreshold::class));
        $this->assertTrue($approver->can('rebaselineApprove', MeasureThreshold::class));
    }
}
