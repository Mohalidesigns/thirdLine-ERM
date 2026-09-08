<?php

namespace Tests\Feature\Bcms;

use App\Models\Organization;
use App\Models\User;
use App\Presenters\NavPresenter;
use App\Support\Bcms\ModuleSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate G0 criterion 3 — "the BCMS module appears in navigation for permitted
 * roles only; a user without `bcms.view` gets 403 on every route" — plus the
 * two behaviours that criterion does not state and that matter as much: the
 * whole module is invisible when the feature flag is off, and the settings
 * screen actually persists.
 *
 * Standard §10 is blunt that no JavaScript runs in this suite, so these
 * assertions stop at the props boundary. They prove the server hands the page
 * what it needs and gates who reaches it; `npm run build` and a browser are the
 * only checks on the other side of that line.
 */
class ModuleShellTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test'): User
    {
        $user = User::create([
            'name' => 'BC Coordinator', 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-tester-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        return $user;
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_whole_module_is_404_when_the_feature_flag_is_off(): void
    {
        // 404, not 403. A disabled surface must be indistinguishable from one
        // that does not exist — otherwise the flag advertises what is coming.
        config()->set('features.bcms', false);

        $user = $this->userWith(['bcms.view', 'bcms.admin']);

        $this->actingAs($user)->get(route('bcms.home'))->assertNotFound();
        $this->actingAs($user)->get(route('bcms.settings.index'))->assertNotFound();

        foreach (ModuleSections::all() as $section) {
            $this->actingAs($user)
                ->get(route('bcms.'.$section['key'].'.index'))
                ->assertNotFound();
        }
    }

    #[Test]
    public function a_user_without_bcms_view_is_forbidden_on_every_route(): void
    {
        $user = $this->userWith([]);

        $this->actingAs($user)->get(route('bcms.home'))->assertForbidden();
        $this->actingAs($user)->get(route('bcms.settings.index'))->assertForbidden();

        foreach (ModuleSections::all() as $section) {
            $this->actingAs($user)
                ->get(route('bcms.'.$section['key'].'.index'))
                ->assertForbidden();
        }
    }

    #[Test]
    public function a_guest_is_redirected_rather_than_shown_the_module(): void
    {
        $this->get(route('bcms.home'))->assertRedirect();
    }

    #[Test]
    public function the_home_screen_renders_for_a_permitted_user(): void
    {
        $user = $this->userWith(['bcms.view']);

        $this->actingAs($user)
            ->get(route('bcms.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Home')
                ->has('counts')
                ->has('sections')
                ->has('mock_channels')
            );
    }

    #[Test]
    public function an_empty_plan_register_reports_no_currency_rate_rather_than_a_hundred_per_cent(): void
    {
        $user = $this->userWith(['bcms.view']);

        // Development standard §5: a rate over nothing is undefined, not zero
        // and certainly not 100. This is the assertion that stops a green
        // "all plans current" tile appearing over an empty register.
        $this->actingAs($user)
            ->get(route('bcms.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plans_current_rate', null));
    }

    #[Test]
    public function the_home_screen_says_which_notification_channels_are_still_mocks(): void
    {
        $user = $this->userWith(['bcms.view']);

        // A crisis manager who believes an SMS went out because a tick
        // appeared, when the Phase 0 mock recorded it and dispatched nothing,
        // is the worst failure this module could have. At G0 every channel is
        // a mock and the first screen says so.
        $this->actingAs($user)
            ->get(route('bcms.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mock_channels', 8));
    }

    #[Test]
    public function each_section_is_gated_by_its_own_permission_and_not_by_a_blanket_view(): void
    {
        // A user who may see the calendar but not the contact roster gets the
        // calendar. This split is far easier to get right now than to retrofit
        // over twelve screens.
        $user = $this->userWith(['bcms.view', 'bcms.exercise.view']);

        $this->actingAs($user)->get(route('bcms.calendar.index'))->assertOk();
        $this->actingAs($user)->get(route('bcms.emns.index'))->assertForbidden();
        $this->actingAs($user)->get(route('bcms.it-dr.index'))->assertForbidden();
    }

    #[Test]
    public function a_section_screen_names_the_phase_that_delivers_it(): void
    {
        // `calendar` was the example here until Phase 4 replaced it with the
        // real screen. `call-trees` is Phase 6's and is still a shell — the
        // example has to be a section that has not landed, or this asserts
        // nothing.
        $user = $this->userWith(['bcms.view', 'bcms.calltree.view']);

        // A blank screen is indistinguishable from a broken one.
        $this->actingAs($user)
            ->get(route('bcms.call-trees.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Section')
                ->where('section.key', 'call-trees')
                ->has('section.phase')
                ->has('section.lands')
                ->has('section.clause')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Navigation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_navigation_entry_appears_only_for_a_permitted_user(): void
    {
        $withPermission = $this->userWith(['bcms.view'], 'yes@khb.test');
        $without = $this->userWith([], 'no@khb.test');

        $this->assertTrue($this->navHasBcms($withPermission));
        $this->assertFalse($this->navHasBcms($without));
    }

    #[Test]
    public function the_navigation_entry_disappears_when_the_feature_flag_is_off(): void
    {
        config()->set('features.bcms', false);

        $this->assertFalse($this->navHasBcms($this->userWith(['bcms.view'])));
    }

    #[Test]
    public function every_navigation_item_points_at_a_route_that_exists(): void
    {
        // The failure this prevents is a menu item pointing at a route nobody
        // registered — a 500 on a customer's screen, found by a customer. Both
        // halves come from ModuleSections, so this asserts the wiring rather
        // than a hand-typed list.
        $user = $this->userWith(array_merge(
            ['bcms.view', 'bcms.admin', 'bcms.plan.view'],
            array_column(ModuleSections::all(), 'permission')
        ));

        $section = $this->bcmsNavSection($user);

        $this->assertNotNull($section, 'The BCMS navigation section is missing for a fully permitted user.');
        $this->assertCount(16, $section['items'], 'Home, thirteen sub-modules, the BC policy and settings.');

        foreach ($section['items'] as $item) {
            $this->assertArrayHasKey('url', $item, "Nav item '{$item['label']}' resolved to no URL.");
            $this->assertNotEmpty($item['url'], "Nav item '{$item['label']}' resolved to an empty URL.");
        }
    }

    private function navHasBcms(User $user): bool
    {
        return $this->bcmsNavSection($user) !== null;
    }

    /** @return array<string, mixed>|null */
    private function bcmsNavSection(User $user): ?array
    {
        $this->actingAs($user);

        foreach (app(NavPresenter::class)->for($user)['sections'] as $section) {
            if (($section['key'] ?? null) === 'bcms') {
                return $section;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Settings — criterion 4, through the HTTP path */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function settings_save_and_read_back(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.admin']);

        $this->actingAs($user)->put(route('bcms.settings.update'), $this->validSettings([
            'default_lead_time_days' => 14,
            'reminder_send_time' => '06:45',
        ]))->assertRedirect();

        $this->assertDatabaseHas('bcms_settings', [
            'organization_id' => $this->organization->id,
            'default_lead_time_days' => 14,
        ]);

        $this->actingAs($user)
            ->get(route('bcms.settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Settings')
                ->where('settings.default_lead_time_days', 14)
                ->where('settings.reminder_send_time', '06:45')
            );
    }

    #[Test]
    public function a_life_safety_channel_set_with_no_offline_channel_is_refused(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.admin']);

        // Blueprint §7.2 and §14: the network is the first thing to fail. A
        // life-safety set of email and Teams stops working in exactly the
        // situation it exists for.
        $this->actingAs($user)->put(route('bcms.settings.update'), $this->validSettings([
            'life_safety_channel_set' => ['email', 'teams'],
        ]))->assertSessionHasErrors('life_safety_channel_set');
    }

    #[Test]
    public function quiet_hours_must_be_given_as_a_pair_or_not_at_all(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.admin']);

        // A start with no end is a window with no close, which the deferral
        // logic would read as never or as always depending on which comparison
        // somebody wrote.
        $this->actingAs($user)->put(route('bcms.settings.update'), $this->validSettings([
            'quiet_hours_start' => '22:00',
        ]))->assertSessionHasErrors('quiet_hours_end');
    }

    #[Test]
    public function escalation_must_happen_before_the_exercise(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.admin']);

        // Zero would mean "escalate on the day", which is not an escalation.
        $this->actingAs($user)->put(route('bcms.settings.update'), $this->validSettings([
            'escalation_day_offset' => 0,
        ]))->assertSessionHasErrors('escalation_day_offset');
    }

    #[Test]
    public function a_user_without_bcms_admin_cannot_save_settings(): void
    {
        $user = $this->userWith(['bcms.view']);

        $this->actingAs($user)
            ->put(route('bcms.settings.update'), $this->validSettings())
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'timezone' => 'Africa/Lagos',
            'default_lead_time_days' => 10,
            'reminder_send_time' => '07:30',
            'default_reminder_mode' => 'digest',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'escalation_day_offset' => -2,
            'default_channel_set' => ['email', 'sms'],
            'life_safety_channel_set' => ['sms', 'voice'],
            'ai_enabled' => false,
            'exercise_simulation_default' => true,
            'require_dual_approval_for_live' => true,
            'alert_currency' => 'NGN',
            'contact_verification_days' => 180,
        ], $overrides);
    }
}
