<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\IcsFeedBuilder;
use App\Services\Bcms\Exercises\OccurrenceGenerator;
use App\Support\Bcms\ModuleSections;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 4 screens: that they render, that they are gated, and that the ICS
 * feed's signature is the credential it claims to be.
 *
 * THE FEED'S TENANT TEST IS THE IMPORTANT ONE HERE. That route is outside
 * `auth` by necessity — Outlook sends no cookie — and `OrganizationScope` is
 * INERT when no tenant is resolved. Without the controller setting the tenant
 * from the bound user, every organisation's exercises would be in every feed,
 * and nothing would look wrong.
 */
class Phase4ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private ExerciseProgramme $programme;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->year = (int) now()->addYear()->year;

        $this->programme = app(ExerciseProgrammeService::class)->create(
            $this->year, 'Exercise programme '.$this->year, [], $this->author()->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_calendar_section_replaced_its_shell_and_kept_its_route_name(): void
    {
        $section = ModuleSections::find('calendar');

        $this->assertNotNull($section);
        $this->assertTrue($section['live'], 'The calendar section is still declared as a shell.');

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.calendar.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Bcms/Calendar/Index'));
    }

    #[Test]
    public function the_year_grid_carries_twelve_months_and_the_blackout_summary(): void
    {
        $definition = $this->definition('DRFAILOVER', 4);
        app(OccurrenceGenerator::class)->generate($definition);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.calendar.index', ['view' => 'year', 'year' => $this->year]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'year')
                ->has('year_grid.months', 12)
                ->where('year_grid.count', 4)
                ->where('year_grid.unscheduled', 0)
                // The screen says what the generator had to work with, so an
                // empty-looking February is explicable.
                ->has('year_grid.blackouts.working_days')
                ->has('year_grid.blackouts.unresolved')
                ->has('status_colours')
                ->where('can.manage', false)
            );
    }

    #[Test]
    public function each_view_sends_only_its_own_shape(): void
    {
        $definition = $this->definition('DRFAILOVER', 2);
        app(OccurrenceGenerator::class)->generate($definition);

        $user = $this->userWith(['bcms.exercise.view']);

        // The year grid needs twelve aggregates; the month needs rows. Sending
        // both on every request would push a year of occurrences through the
        // wire to draw a heat map of twelve numbers — and the NFR is 1.5s.
        $this->actingAs($user)
            ->get(route('bcms.calendar.index', ['view' => 'year', 'year' => $this->year]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('year_grid')->missing('occurrences')->missing('gantt'));

        $this->actingAs($user)
            ->get(route('bcms.calendar.index', ['view' => 'agenda', 'year' => $this->year,
                'from' => $this->year.'-01-01', 'to' => $this->year.'-12-31']))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('occurrences', 2)->missing('year_grid'));

        $this->actingAs($user)
            ->get(route('bcms.calendar.index', ['view' => 'gantt', 'year' => $this->year]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('gantt', 1)->missing('year_grid'));

        $this->actingAs($user)
            ->get(route('bcms.calendar.index', ['view' => 'compliance', 'year' => $this->year]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('compliance')->missing('year_grid'));
    }

    #[Test]
    public function the_programme_dashboard_carries_delivery_coverage_and_the_computed_gaps(): void
    {
        $definition = $this->definition('DRFAILOVER', 4);
        app(OccurrenceGenerator::class)->generate($definition);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.exercise-programmes.show', $this->programme))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Programme')
                ->where('programme.year', $this->year)
                ->has('summary.completion_rate')
                ->has('definitions', 1)
                ->has('coverage')
                // The advisor's evidence is computed and is present with AI off.
                ->has('advisor.gaps.headline')
                ->where('advisor.available', false)
                ->has('advisor.reason')
                ->has('exercise_types')
            );
    }

    #[Test]
    public function the_wizards_preview_is_json_and_writes_nothing(): void
    {
        $type = ExerciseType::query()->where('code', 'DRFAILOVER')->sole();

        $response = $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.manage']))
            ->postJson(route('bcms.exercise-definitions.preview', $this->programme), [
                'exercise_type_id' => $type->id,
                'frequency_per_year' => 4,
                'distribution_mode' => 'even',
                'duration_minutes' => 480,
                'lead_time_days' => 10,
                'daily_reminder_enabled' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('log.placed', 4)
            ->assertJsonPath('log.needs_scheduling', 0)
            ->assertJsonStructure(['log', 'occurrences', 'audience_size', 'notification_estimate', 'ladder_warnings']);

        // Previewing an unsaved definition must not create one.
        $this->assertSame(0, ExerciseDefinition::query()->count());
    }

    #[Test]
    public function an_unannounced_exercise_with_a_countdown_is_refused_with_the_reason(): void
    {
        $type = ExerciseType::query()->where('code', 'CALLTREE')->sole();

        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.manage']))
            ->post(route('bcms.exercise-definitions.store', $this->programme), [
                'exercise_type_id' => $type->id,
                'name' => 'Surprise call tree test',
                'frequency_per_year' => 4,
                'distribution_mode' => 'even',
                'duration_minutes' => 90,
                'lead_time_days' => 2,
                'min_notice_days' => 1,
                'unannounced' => true,
                'daily_reminder_enabled' => true,
            ])
            ->assertSessionHasErrors('unannounced');
    }

    #[Test]
    public function a_month_specific_definition_that_names_no_months_is_refused(): void
    {
        $type = ExerciseType::query()->where('code', 'WALKTHRU')->sole();

        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.manage']))
            ->post(route('bcms.exercise-definitions.store', $this->programme), [
                'exercise_type_id' => $type->id,
                'name' => 'Walkthrough',
                'frequency_per_year' => 1,
                'distribution_mode' => 'month_specific',
                'duration_minutes' => 120,
                'lead_time_days' => 10,
                'min_notice_days' => 3,
            ])
            ->assertSessionHasErrors('preferred_window');
    }

    #[Test]
    public function rescheduling_from_the_calendar_needs_a_justification(): void
    {
        $definition = $this->definition('TABLETOP', 1);
        app(OccurrenceGenerator::class)->generate($definition);
        $occurrence = $definition->occurrences()->sole();

        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.schedule']))
            ->post(route('bcms.occurrences.reschedule', $occurrence), [
                'scheduled_date' => $occurrence->scheduled_date->copy()->addDays(10)->toDateString(),
                'justification' => 'too short',
            ])
            ->assertSessionHasErrors('justification');
    }

    #[Test]
    public function moving_an_exercise_needs_the_scheduling_grant_not_the_managing_one(): void
    {
        $definition = $this->definition('TABLETOP', 1);
        app(OccurrenceGenerator::class)->generate($definition);
        $occurrence = $definition->occurrences()->sole();

        // Designing the programme and moving a booked exercise are different
        // acts, and in practice different people do them.
        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.manage'], 'designer@khb.test'))
            ->post(route('bcms.occurrences.reschedule', $occurrence), [
                'scheduled_date' => $occurrence->scheduled_date->copy()->addDays(10)->toDateString(),
                'justification' => 'The facilitator is on leave that week.',
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  The ICS feed */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_ics_feed_is_served_to_an_unauthenticated_client_with_a_valid_signature(): void
    {
        $author = $this->author();
        $definition = $this->definition('TABLETOP', 1, ['facilitator_id' => $author->id]);
        app(OccurrenceGenerator::class)->generate($definition);
        $definition->occurrences()->sole()->update(['facilitator_id' => $author->id]);

        $url = app(IcsFeedBuilder::class)->urlFor($author);

        // The builder's URL has to actually BE the named route, signed — not
        // some other path that happens to work today. Asserted against
        // `route()` directly rather than trusted because the request below
        // succeeds.
        $this->assertSame(
            route('bcms.calendar.ics', ['user' => $author->id], false),
            parse_url($url, PHP_URL_PATH),
        );

        // No `actingAs`: Outlook and Google send no cookie, which is the whole
        // reason this route is outside `auth`.
        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
    }

    #[Test]
    public function a_tampered_feed_url_is_refused(): void
    {
        $author = $this->author();
        $other = $this->userWith([], 'other@khb.test');

        $url = app(IcsFeedBuilder::class)->urlFor($author);

        // Swapping the user id in a signed URL is the obvious attack, and the
        // signature is what stops it.
        $this->get(str_replace('/'.$author->id.'/', '/'.$other->id.'/', $url))->assertForbidden();
        $this->get(explode('?', $url)[0])->assertForbidden();
    }

    #[Test]
    public function the_feed_never_carries_another_tenants_exercises(): void
    {
        $author = $this->author();
        $mine = $this->definition('TABLETOP', 1, ['facilitator_id' => $author->id], 'My tabletop');
        app(OccurrenceGenerator::class)->generate($mine);
        $mine->occurrences()->sole()->update(['facilitator_id' => $author->id]);

        // A second tenant with an exercise the first user facilitates by id.
        // `OrganizationScope` is INERT with no tenant resolved, and this route
        // has no session for `ResolveTenant` to work from — so without the
        // controller setting the tenant explicitly, this exercise would appear
        // in the feed.
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($other->id);

        $otherProgramme = app(ExerciseProgrammeService::class)->create($this->year, 'Theirs');
        $otherType = ExerciseType::query()->where('code', 'TABLETOP')->first();

        $theirs = app(ExerciseDefinitionService::class)->create(
            $otherProgramme, $otherType, 'Their secret tabletop',
            ['frequency_per_year' => 1, 'status' => 'active', 'facilitator_id' => $author->id],
        );
        app(OccurrenceGenerator::class)->generate($theirs);
        $theirs->occurrences()->sole()->update(['facilitator_id' => $author->id]);

        TenantContext::set($this->organization->id);

        $body = $this->get(app(IcsFeedBuilder::class)->urlFor($author))->getContent();

        $this->assertStringContainsString('My tabletop', $body);
        $this->assertStringNotContainsString('Their secret tabletop', $body);
    }

    #[Test]
    public function a_disabled_account_stops_receiving_its_feed(): void
    {
        $author = $this->author();
        $url = app(IcsFeedBuilder::class)->urlFor($author);

        $this->get($url)->assertOk();

        // A signature stays valid until the key rotates, and a subscription
        // keeps fetching long after somebody has left.
        $author->update(['is_active' => false]);

        $this->get($url)->assertNotFound();
    }

    /**
     * A DISTINCT branch from the disabled-account check above: an account
     * with no organisation at all (a platform-level login with nothing to set
     * `TenantContext` from) must not be able to pull anybody's feed.
     */
    #[Test]
    public function an_account_with_no_organisation_cannot_pull_a_feed(): void
    {
        $author = $this->author();
        $url = app(IcsFeedBuilder::class)->urlFor($author);

        $author->update(['organization_id' => null]);

        $this->get($url)->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Gating */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_phase_four_screen_is_behind_a_permission(): void
    {
        $nobody = $this->userWith([], 'nobody@khb.test');

        foreach ([
            route('bcms.calendar.index'),
            route('bcms.exercise-programmes.index'),
            route('bcms.exercise-programmes.show', $this->programme),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function the_whole_phase_is_behind_the_feature_flag(): void
    {
        config()->set('features.bcms', false);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.calendar.index'))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test'): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        DB::table('business_unit_user')->updateOrInsert(
            ['user_id' => $user->id, 'business_unit_id' => $this->unit->id],
            ['organization_id' => $this->organization->id, 'includes_descendants' => true,
                'created_at' => now(), 'updated_at' => now()],
        );

        return $user->refresh();
    }

    private function author(): User
    {
        return $this->userWith([], 'programme-author@khb.test');
    }

    /** @param array<string, mixed> $attributes */
    private function definition(string $code, int $frequency, array $attributes = [], ?string $name = null): ExerciseDefinition
    {
        $type = ExerciseType::query()->where('code', $code)->sole();

        return app(ExerciseDefinitionService::class)->create(
            $this->programme, $type, $name ?? $type->name,
            array_merge([
                'frequency_per_year' => $frequency,
                'status' => 'active',
                'business_unit_id' => $this->unit->id,
            ], $attributes),
            $this->author()->id,
        );
    }
}
