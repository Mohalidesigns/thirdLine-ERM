<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Finding;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\CheckInService;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Reminders\ReadinessService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The six Phase 9 screens: asserts the Inertia component each route renders
 * and every prop the screen actually reads, including every `*_url` — a
 * screen that reads a prop the controller never sent, or a route that has
 * quietly renamed its component, fails here rather than in a browser.
 * Modelled on `Phase11ScreensTest`'s own discipline for this module.
 *
 * EVERY `*_url` IS COMPARED AGAINST `route()` ITSELF, never a hand-built
 * string — the property `ModuleActionUrlRouteKeyTest` polices from the JSX
 * side, this file polices from the controller side.
 */
class Phase9ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $facilitator;

    private User $owner;

    private User $evaluator;

    private ExerciseProgramme $programme;

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

        $this->owner = $this->user('Owner', 'owner@khb.test', []);
        $this->facilitator = $this->user('Facilitator', 'facilitator@khb.test', [
            'bcms.exercise.facilitate', 'bcms.exercise.view', 'bcms.readiness.override',
            'bcms.finding.manage', 'bcms.report.export', 'bcms.aar.manage', 'bcms.aar.approve',
            'rcsa_scope.all_units',
        ]);
        $this->evaluator = $this->user('Evaluator', 'evaluator@khb.test', [
            'bcms.exercise.evaluate', 'bcms.exercise.view', 'rcsa_scope.all_units',
        ]);

        $this->programme = app(ExerciseProgrammeService::class)->create(
            (int) now()->year, 'Exercise programme', [], $this->owner->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  1. The execution workspace */
    /* ================================================================== */

    #[Test]
    public function the_workspace_renders_its_component_and_every_server_built_action_url(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);

        $this->actingAs($this->facilitator)->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Workspace', false)
                ->where('is_simulation', true)
                ->where('occurrence.definition_name', $definition->name)
                ->has('timeline')
                ->has('injects')
                ->has('attendance')
                ->has('evidence')
                ->where('can.facilitate', true)
                ->where('urls.readiness', route('bcms.occurrences.readiness', $occurrence))
                ->where('urls.complete', route('bcms.occurrences.complete', $occurrence))
                ->where('urls.timeline_store', route('bcms.occurrences.timeline.store', $occurrence))
                ->where('urls.check_in', route('bcms.occurrences.check-in', $occurrence))
                ->where('urls.check_in_poster', route('bcms.occurrences.check-in-poster', $occurrence))
                ->where('urls.live_metrics', route('bcms.occurrences.live-metrics', $occurrence))
                ->where('urls.score', route('bcms.occurrences.score.show', $occurrence))
                ->where('urls.evidence_upload', route('bcms.evidence.store', $occurrence))
            );
    }

    #[Test]
    public function the_workspace_404s_before_the_exercise_has_started(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);

        $this->actingAs($this->facilitator)->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertNotFound();
    }

    /**
     * Gate 2 review #2, blocking finding A: without this, criterion 3 (a
     * participant checks in by QR or short code) is unmeetable — nothing
     * ever showed a participant's own credential to the facilitator who
     * would relay it. `short_code`/`check_in_url` now ride on every
     * not-checked-in row for a facilitator, and the URL is proven live, not
     * just present: a GET resolves the real `CheckIn` screen and a POST to
     * it actually checks that participant in.
     */
    #[Test]
    public function a_facilitator_sees_the_check_in_credential_for_every_not_checked_in_participant_and_the_url_resolves(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);
        $alice = $this->user('Alice', 'alice-credential@khb.test', []);
        $this->contactFor($alice, $occurrence);

        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $alice->getKey())->sole();
        $checkIn = app(CheckInService::class);

        $response = $this->actingAs($this->facilitator)->get(route('bcms.occurrences.workspace', $occurrence));
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Bcms/Exercises/Workspace', false)
            ->where('can.facilitate', true)
            ->where('attendance.not_checked_in.0.short_code', $checkIn->shortCodeFor($participant))
            ->where('attendance.not_checked_in.0.check_in_url', route('bcms.check-in.show', $checkIn->tokenFor($participant)))
        );

        $checkInUrl = $response->viewData('page')['props']['attendance']['not_checked_in'][0]['check_in_url'];

        $this->get($checkInUrl)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckIn', false)
                ->where('ok', true)
            );

        $this->post($checkInUrl)->assertRedirect();
        $this->assertNotNull($participant->refresh()->checked_in_at);

        // Advisory 5 (Gate 2 review #3): the POST above still ran while
        // this test client was authenticated as the facilitator — it
        // proves the URL is live, but not that a genuinely RELAYED link
        // (the whole point of a credential the facilitator hands to
        // someone else) works with no session at all. A second
        // participant, checked in with no `actingAs()` in effect, proves
        // that half.
        $bob = $this->user('Bob', 'bob-credential@khb.test', []);
        $this->contactFor($bob, $occurrence);
        $bobParticipant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $bob->getKey())->sole();

        $workspaceAfterAlice = $this->actingAs($this->facilitator)
            ->get(route('bcms.occurrences.workspace', $occurrence));
        $bobRow = collect($workspaceAfterAlice->viewData('page')['props']['attendance']['not_checked_in'])
            ->firstWhere('id', $bobParticipant->getKey());
        $this->assertNotNull($bobRow, 'Bob is missing from attendance.not_checked_in after Alice checked in.');
        $bobCheckInUrl = $bobRow['check_in_url'];

        Auth::logout();
        $this->flushSession();
        $this->assertGuest();

        $this->post($bobCheckInUrl)->assertRedirect();
        $this->assertNotNull($bobParticipant->refresh()->checked_in_at);
    }

    /**
     * The other half of the same fix: the credential must never reach a
     * viewer who can only `bcms.exercise.view` — the whole point of gating
     * on `can.facilitate` is that this credential is a check-in bypass for
     * whoever holds it.
     */
    #[Test]
    public function a_viewer_without_facilitate_never_sees_the_check_in_credential(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);
        $alice = $this->user('Alice', 'alice-viewer-guard@khb.test', []);
        $this->contactFor($alice, $occurrence);

        $viewer = $this->user('Viewer', 'viewer-only@khb.test', ['bcms.exercise.view', 'rcsa_scope.all_units']);

        $this->actingAs($viewer)->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Workspace', false)
                ->where('can.facilitate', false)
                // Advisory 5 (Gate 2 review #3): without this, the two
                // `missing()` assertions below pass vacuously if
                // `not_checked_in` were ever empty — this pins it to
                // exactly the one participant (Alice) the test set up, so
                // the missing-key assertions are checked against a row
                // that actually exists.
                ->has('attendance.not_checked_in', 1)
                ->missing('attendance.not_checked_in.0.short_code')
                ->missing('attendance.not_checked_in.0.check_in_url')
            );
    }

    /* ================================================================== */
    /*  2. Observer scoring */
    /* ================================================================== */

    #[Test]
    public function the_score_screen_renders_its_component_and_store_urls(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);

        $this->actingAs($this->evaluator)->get(route('bcms.occurrences.score.show', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Score', false)
                ->where('closed', false)
                ->has('objectives')
                ->where('store_url', route('bcms.occurrences.scores.store', $occurrence))
                ->where('workspace_url', route('bcms.occurrences.workspace', $occurrence))
                ->where('evidence_upload_url', route('bcms.evidence.store', $occurrence))
            );
    }

    #[Test]
    public function a_facilitator_without_the_evaluate_permission_is_refused_the_score_screen(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);

        $this->actingAs($this->facilitator)->get(route('bcms.occurrences.score.show', $occurrence))
            ->assertForbidden();
    }

    /* ================================================================== */
    /*  3. QR / SMS check-in */
    /* ================================================================== */

    #[Test]
    public function the_unauthenticated_check_in_page_renders_with_its_own_post_url(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);
        $alice = $this->user('Alice', 'alice@khb.test', []);
        $this->contactFor($alice, $occurrence);

        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->firstOrFail();
        $token = app(CheckInService::class)->tokenFor($participant);

        TenantContext::clear();

        $this->get(route('bcms.check-in.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckIn', false)
                ->where('ok', true)
                ->where('headline', 'THIS IS AN EXERCISE')
                ->where('check_in_url', route('bcms.check-in.store', $token))
            );
    }

    #[Test]
    public function an_unrecognised_token_never_reveals_which_exercise_it_might_belong_to(): void
    {
        $this->get(route('bcms.check-in.show', 'not-a-real-token'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckIn', false)
                ->where('ok', false)
                ->where('headline', 'This check-in link is not recognised.')
            );
    }

    #[Test]
    public function the_check_in_poster_ships_no_participant_tokens_or_short_codes(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);
        $alice = $this->user('Alice', 'alice2@khb.test', []);
        $this->contactFor($alice, $occurrence);

        $this->actingAs($this->facilitator)->get(route('bcms.occurrences.check-in-poster', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckInPoster', false)
                ->where('occurrence.title', $definition->name)
                ->where('expected', 1)
                ->where('checked_in', 0)
                ->missing('participants')
                // Regression for the finding A fix above: the same session
                // that put `short_code`/`check_in_url` on the authenticated
                // workspace's `attendance.not_checked_in[]` rows must never
                // put the equivalent onto this unauthenticated poster.
                ->missing('attendance')
                ->missing('short_code')
                ->missing('check_in_url')
                ->where('code_form_url', route('bcms.check-in.code'))
                ->where('live_metrics_url', route('bcms.occurrences.live-metrics', $occurrence))
            );
    }

    #[Test]
    public function the_short_code_form_renders_and_a_wrong_code_returns_the_same_component_with_an_error(): void
    {
        $this->get(route('bcms.check-in.code'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckInCode', false)
                ->where('code_form_url', route('bcms.check-in.code.store'))
            );

        $this->post(route('bcms.check-in.code.store'), ['code' => 'NOTREAL1'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckInCode', false)
                ->where('error', 'That code was not recognised. Check the digits and try again, or ask the marshal.')
                ->where('code_form_url', route('bcms.check-in.code.store'))
            );
    }

    /* ================================================================== */
    /*  4. The after-action report builder */
    /* ================================================================== */

    #[Test]
    public function the_aar_screen_renders_its_component_and_every_action_url(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->startedOccurrence($definition, 1);

        foreach (array_keys($definition->refresh()->objectives) as $index) {
            $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence), [
                'objective_index' => $index, 'score' => 4, 'commentary' => null,
            ])->assertRedirect();
        }

        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.complete', $occurrence), [
            'outcome' => 'pass_with_findings',
        ])->assertRedirect();

        $aar = $occurrence->refresh()->aar;
        $this->assertNotNull($aar);

        $this->actingAs($this->facilitator)->post(route('bcms.findings.store'), [
            'source' => 'aar', 'aar_id' => $aar->getKey(),
            'classification' => 'improvement', 'description' => 'A radio failed during the drill.',
        ])->assertRedirect();
        $finding = Finding::query()->where('aar_id', $aar->getKey())->sole();

        $this->actingAs($this->facilitator)->get(route('bcms.aars.show', $aar))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Aar', false)
                ->where('aar.id', $aar->getKey())
                ->where('aar.uuid', $aar->uuid)
                ->where('aar.status', 'draft')
                ->has('conditions')
                ->has('timeline.entries')
                ->where('findings.0.store_action_url', route('bcms.actions.store', $finding))
                ->has('options.users')
                ->where('can.manage', true)
                ->where('can.approve', true)
                ->where('urls.update', route('bcms.aars.update', $aar))
                ->where('urls.finalise', route('bcms.aars.finalise', $aar))
                ->where('urls.reopen', route('bcms.aars.reopen', $aar))
                ->where('urls.distribute', route('bcms.aars.distribute', $aar))
                ->where('urls.ai_draft', route('bcms.aars.ai-draft', $aar))
                ->where('urls.export', route('bcms.occurrences.aar.export', $occurrence))
                ->where('urls.raise_finding', route('bcms.findings.store'))
                ->where('urls.workspace', route('bcms.occurrences.workspace', $occurrence))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'business_unit_id' => $this->unit->id ?? null,
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('phase9-'.Str::slug($email), 'web');
            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            $user->assignRole($role);
        }

        return $user;
    }

    private function contactFor(User $user, ExerciseOccurrence $occurrence): Contact
    {
        $contact = Contact::query()->firstOrCreate(
            ['organization_id' => $this->organization->id, 'employee_id' => 'E-'.$user->id],
            [
                'user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
                'mobile_primary' => '+23480000'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                'business_unit_id' => $this->unit->id, 'source' => 'manual', 'is_active' => true,
            ]
        );

        ExerciseParticipant::query()->updateOrCreate(
            ['occurrence_id' => $occurrence->getKey(), 'user_id' => $user->id],
            [
                'organization_id' => $this->organization->id, 'contact_id' => $contact->getKey(),
                'role' => 'participant', 'business_unit_id' => $this->unit->id, 'invitation_status' => 'pending',
            ]
        );

        return $contact;
    }

    /** @param array<string, mixed> $attributes */
    private function definition(string $typeCode, array $attributes = []): \App\Models\Bcms\ExerciseDefinition
    {
        $type = ExerciseType::query()->where('code', $typeCode)->sole();

        return app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            $typeCode.' '.Str::random(4),
            array_merge([
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->owner->id,
                'facilitator_id' => $this->facilitator->id,
                'status' => 'active',
                'readiness_gating' => false,
            ], $attributes),
            $this->owner->id,
        );
    }

    private function occurrence(\App\Models\Bcms\ExerciseDefinition $definition, int $sequence): ExerciseOccurrence
    {
        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => $sequence,
            'scheduled_date' => now()->addDays(3 * $sequence)->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);

        app(ReadinessService::class)->materialise($occurrence);

        return $occurrence->refresh();
    }

    private function startedOccurrence(\App\Models\Bcms\ExerciseDefinition $definition, int $sequence): ExerciseOccurrence
    {
        $occurrence = $this->occurrence($definition, $sequence);

        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence))->assertRedirect();

        return $occurrence->refresh();
    }
}
