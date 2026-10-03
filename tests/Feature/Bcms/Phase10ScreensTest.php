<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\IncidentSeverity;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Incident;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanActivation;
use App\Models\Bcms\PlanSection;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\Incidents\PirService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 * The Phase 10 screens: the Inertia component each route renders and every
 * prop the screen actually reads, including every `*_url` — mirrors
 * `Phase9ScreensTest`'s own discipline for this module. Every `*_url` is
 * compared against `route()` itself, never a hand-built string.
 */
class Phase10ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $officer;

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

        $this->officer = $this->user('officer@khb.test', [
            'bcms.incident.view', 'bcms.incident.declare', 'bcms.incident.manage', 'bcms.incident.notify',
            'bcms.plan.activate', 'bcms.report.export', 'bcms.dr.view', 'bcms.dr.manage', 'bcms.dr.test.record',
            'bcms.aar.manage', 'bcms.aar.approve', 'bcms.finding.manage', 'bcms.alert.compose',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  1. Incidents index */
    /* ================================================================== */

    #[Test]
    public function the_incidents_index_renders_its_component_and_declare_url(): void
    {
        $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/Index', false)
                ->has('incidents.data', 1)
                ->where('declare_url', route('bcms.incidents.declare-form'))
            );
    }

    /**
     * Gate 2 review #1 defect 9: `Incident` uses `ScopedToOrgHierarchy`, and
     * the index read an org filter alone — a business-unit-scoped incident
     * was visible to every user in the tenant, not only its own unit.
     */
    #[Test]
    public function the_incidents_index_excludes_another_business_units_incident(): void
    {
        // business_unit_id left null — organisation-level, visible to a user
        // with no unit assignment (the officer, in this fixture).
        $visible = $this->declareIncident();

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS-IDX', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $hidden = $this->declareIncident();
        $hidden->update(['business_unit_id' => $lagos->id]);

        $this->actingAs($this->officer)->get(route('bcms.incidents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/Index', false)
                ->has('incidents.data', 1)
                ->where('incidents.data.0.reference', $visible->reference)
            );
    }

    /* ================================================================== */
    /*  2. Declare */
    /* ================================================================== */

    #[Test]
    public function the_declare_form_renders_its_component_and_store_url(): void
    {
        $this->actingAs($this->officer)->get(route('bcms.incidents.declare-form'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/Declare', false)
                ->has('severity_bands')
                ->has('activation_levels')
                ->where('store_url', route('bcms.incidents.store'))
            );
    }

    /**
     * Gate 1 re-gate defect 9: the declare form's plan picker listed every
     * approved plan in the tenant, ignoring ADR 0017 — offering one a user
     * cannot see is the same cross-unit leak `visibleTo()` closes everywhere
     * else this module lists a plan.
     */
    #[Test]
    public function the_declare_forms_plan_picker_excludes_a_plan_the_officer_cannot_see(): void
    {
        $visible = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Org-level plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS-DECL', 'name' => 'Lagos', 'is_active' => true,
        ]);
        Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Lagos-only plan',
            'status' => 'approved', 'version' => '1', 'content' => [], 'business_unit_id' => $lagos->id,
        ]);

        $this->actingAs($this->officer)->get(route('bcms.incidents.declare-form'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plans', 1)
                ->where('plans.0.id', $visible->getKey())
            );
    }

    #[Test]
    public function declaring_with_an_activate_plan_id_the_officer_cannot_see_is_refused(): void
    {
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS-DECL2', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Lagos-only plan 2',
            'status' => 'approved', 'version' => '1', 'content' => [], 'business_unit_id' => $lagos->id,
        ]);

        $this->actingAs($this->officer)->post(route('bcms.incidents.store'), [
            'title' => 'Test', 'severity' => 'sev3', 'detected_at' => now()->toIso8601String(),
            'activate_plan_id' => $plan->getKey(),
        ])->assertSessionHasErrors('activate_plan_id');

        $this->assertSame(0, Incident::query()->count());
    }

    #[Test]
    public function declaring_with_an_activate_plan_id_without_plan_activate_permission_is_refused(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'A plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $declareOnly = $this->user('declare-only@khb.test', ['bcms.incident.declare']);

        $this->actingAs($declareOnly)->post(route('bcms.incidents.store'), [
            'title' => 'Test', 'severity' => 'sev3', 'detected_at' => now()->toIso8601String(),
            'activate_plan_id' => $plan->getKey(),
        ])->assertSessionHasErrors('activate_plan_id');

        $this->assertSame(0, Incident::query()->count());
    }

    /* ================================================================== */
    /*  3. Crisis room */
    /* ================================================================== */

    #[Test]
    public function the_crisis_room_renders_its_component_and_every_server_built_action_url(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.crisis-room', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/CrisisRoom', false)
                ->where('incident.uuid', $incident->uuid)
                ->where('can.manage', true)
                ->has('countdown_tiles')
                ->where('reportability.cbn', 'unknown')
                ->where('reportability.personal_data', 'unknown')
                ->where('urls.log_store', route('bcms.incidents.log.store', $incident))
                ->where('urls.tasks_store', route('bcms.incidents.tasks.store', $incident))
                ->where('urls.classify', route('bcms.incidents.classify', $incident))
                ->where('urls.regrade', route('bcms.incidents.regrade', $incident))
                ->where('urls.notifications', route('bcms.incidents.notifications.index', $incident))
                ->where('urls.stand_down', route('bcms.incidents.stand-down-form', $incident))
                ->where('urls.review_start', route('bcms.incidents.review.start', $incident))
                ->where('urls.live_metrics', route('bcms.incidents.live-metrics', $incident))
                ->where('urls.alerts_store', route('bcms.alerts.store'))
            );
    }

    #[Test]
    public function the_crisis_room_two_countdown_tiles_are_independent_never_one_merged_tick(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);
        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->classify($incident, $this->officer, 'ndpc');

        $this->actingAs($this->officer)->get(route('bcms.incidents.crisis-room', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/CrisisRoom', false)
                ->has('countdown_tiles', 2)
                ->where('countdown_tiles.0.regulator', 'cbn')
                ->where('countdown_tiles.1.regulator', 'ndpc')
            );
    }

    #[Test]
    public function the_review_show_url_appears_once_a_pir_draft_exists(): void
    {
        $incident = $this->closedIncident();
        $aar = app(PirService::class)->ensureDraftFor($incident);

        $this->actingAs($this->officer)->get(route('bcms.incidents.crisis-room', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('review.exists', true)
                ->where('review.show_url', route('bcms.incidents.review.show', $incident))
                ->where('review.final', false)
            );

        $this->assertNotNull($aar->incident_id);
    }

    /* ================================================================== */
    /*  3b. Live metrics — the crisis room's 5-second poll (Gate 2 review #1
     *      defect 10). A FIXED, SMALL SHAPE, never the whole crisisRoom()
     *      payload, and a constant query count regardless of log size. */
    /* ================================================================== */

    #[Test]
    public function live_metrics_returns_exactly_the_contracted_shape(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $response = $this->actingAs($this->officer)
            ->getJson(route('bcms.incidents.live-metrics', $incident))
            ->assertOk()
            ->assertJsonStructure([
                'metrics' => ['open_tasks', 'decisions_logged'],
                'countdown_tiles',
                'counts' => [
                    'entries', 'tasks_open', 'tasks_done', 'plans_active',
                    'roll_call_responded', 'roll_call_expected',
                ],
                'latest_entry_id', 'latest_entry_at', 'status',
            ]);

        // Deliberately absent — the frontend already holds these from the
        // initial render (crisisRoom()); shipping them every 5 seconds made
        // the poll's cost grow with the incident's own history.
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['countdown_tiles', 'counts', 'latest_entry_at', 'latest_entry_id', 'metrics', 'status'], $keys);
    }

    #[Test]
    public function live_metrics_runs_a_constant_number_of_queries_regardless_of_log_size(): void
    {
        // Advisory A1 (Gate 1 re-gate): frozen so nothing time-dependent
        // (`approachingDueQuery()`'s window edge, `latest_entry_at`) can
        // shift a query's plan or a comparison's outcome between the two
        // measurements below.
        Carbon::setTestNow(now());

        $incident = $this->declareIncident();

        // One untimed warm-up call first: Spatie's permission cache and
        // other first-request-only costs would otherwise land inside
        // whichever measurement runs first and make this test compare
        // "cold" against "warm" rather than 5 log entries against 50.
        $this->actingAs($this->officer)->getJson(route('bcms.incidents.live-metrics', $incident))->assertOk();

        for ($i = 0; $i < 5; $i++) {
            app(IncidentService::class)->log($incident, $this->officer, [
                'entry_type' => 'communication', 'content' => "Update {$i}.",
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->officer)->getJson(route('bcms.incidents.live-metrics', $incident))->assertOk();
        // Advisory A1: count only queries against `bcms_*` tables — the
        // endpoint's own read footprint — so an incidental session/cache
        // read the framework or Spatie's permission check happens to run
        // (neither `bcms_*`, neither this endpoint's concern) cannot flip
        // this test between two otherwise-identical runs.
        $queriesAtFive = $this->countBcmsQueries();
        // Logging stays ON but is flushed AND disabled here — otherwise the
        // 45 `log()` calls below (themselves several queries each) would be
        // captured into the log too, inflating the second measurement with
        // writes the endpoint itself never performs.
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 45; $i++) {
            app(IncidentService::class)->log($incident, $this->officer, [
                'entry_type' => 'communication', 'content' => "Update {$i}.",
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->officer)->getJson(route('bcms.incidents.live-metrics', $incident))->assertOk();
        $queriesAtFifty = $this->countBcmsQueries();
        DB::disableQueryLog();

        Carbon::setTestNow();

        $this->assertSame(
            $queriesAtFive,
            $queriesAtFifty,
            "Expected the same query count at 5 and 50 log entries; got {$queriesAtFive} then {$queriesAtFifty}."
        );
    }

    /** Advisory A1: only the `bcms_*` reads `live_metrics()` itself runs. */
    private function countBcmsQueries(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'bcms_'))
            ->count();
    }

    #[Test]
    public function live_metrics_counts_the_roll_call_and_plan_activations_linked_to_this_incident(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Roll-call plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->declareIncident();

        PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Test.',
        ]);

        $alert = Alert::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'title' => 'Roll call', 'message' => 'Report your status.', 'severity' => 'advisory',
            'is_simulation' => false, 'audience_rule' => [], 'channels' => ['sms'], 'status' => 'dispatched',
            'dispatched_at' => now(), 'currency' => 'NGN',
        ]);

        $contactOne = \App\Models\Bcms\Contact::query()->create([
            'organization_id' => $this->organization->id, 'full_name' => 'Contact One',
        ]);
        $contactTwo = \App\Models\Bcms\Contact::query()->create([
            'organization_id' => $this->organization->id, 'full_name' => 'Contact Two',
        ]);

        AlertRecipient::query()->create([
            'organization_id' => $this->organization->id, 'alert_id' => $alert->getKey(),
            'contact_id' => $contactOne->getKey(), 'resolved_channels' => ['sms'],
            'status' => 'delivered', 'acknowledged_at' => now(),
        ]);
        AlertRecipient::query()->create([
            'organization_id' => $this->organization->id, 'alert_id' => $alert->getKey(),
            'contact_id' => $contactTwo->getKey(), 'resolved_channels' => ['sms'],
            'status' => 'delivered',
        ]);

        $this->actingAs($this->officer)->getJson(route('bcms.incidents.live-metrics', $incident))
            ->assertOk()
            ->assertJson(fn ($json) => $json
                ->where('counts.plans_active', 1)
                ->where('counts.roll_call_expected', 2)
                ->where('counts.roll_call_responded', 1)
                ->etc()
            );
    }

    /* ================================================================== */
    /*  4. Notification log */
    /* ================================================================== */

    #[Test]
    public function the_notification_log_renders_its_component_and_every_action_url(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $this->actingAs($this->officer)->get(route('bcms.incidents.notifications.index', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/NotificationLog', false)
                ->has('obligations', 1)
                ->where('obligations.0.is_open', true)
                ->where('record_url', route('bcms.incidents.notifications.store', $incident))
                ->where('classify_url', route('bcms.incidents.notifications.classify', $incident))
                ->where('export_url', route('bcms.incidents.notifications.export', $incident))
                ->where('crisis_room_url', route('bcms.incidents.crisis-room', $incident))
            );
    }

    #[Test]
    public function recording_a_submission_is_a_register_entry_never_a_transmission(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $this->actingAs($this->officer)->post(route('bcms.incidents.notifications.store', $incident), [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-01',
        ])->assertRedirect();

        $this->actingAs($this->officer)->get(route('bcms.incidents.notifications.index', $incident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('obligations.0.is_open', false)
                ->where('obligations.0.submissions.0.reference', 'CBN-ACK-01')
            );
    }

    /**
     * Gate 2 review #1 defect 8: the notification export is regulatory
     * evidence about a specific incident; `bcms.report.export` alone must
     * not be enough to read it.
     */
    #[Test]
    public function the_notification_export_requires_both_report_export_and_incident_view(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $exportOnly = $this->user('export-only-notif@khb.test', ['bcms.report.export']);

        $this->actingAs($exportOnly)->get(route('bcms.incidents.notifications.export', $incident))
            ->assertForbidden();

        $this->actingAs($this->officer)->get(route('bcms.incidents.notifications.export', $incident))
            ->assertOk();
    }

    /**
     * QA gate re-audit, 2026-09-23. ADR 0020 Amendment 2 rule 2: "Withdrawal
     * requires `bcms.incident.notify`, the same authority that records a
     * submission … it is not a lower authority than deciding to [notify]."
     * `bcms.incident.manage` is the BC Coordinator's day-to-day grant —
     * `RiskPermissionCatalog`'s own comment names "recording a regulatory
     * notification" as one of six grants deliberately withheld from that
     * role and reserved for the CRO (`bcms.incident.notify`). But
     * `routes/web.php`'s `incidents/{incident}/classify` — the route
     * `answerReportability('no', …)` posts to from the crisis room, which
     * `IncidentController::classify()` turns into
     * `NotificationService::reassessNotReportable()` → `withdraw()` — is
     * gated only on `permission:bcms.incident.manage`
     * (`ClassifyBcmsIncidentRequest::authorize()`), so a BC Coordinator who
     * holds `manage` but not `notify` can withdraw a live regulatory
     * obligation today. This is the gap; it fails until the "no" branch (or
     * `NotificationService::withdraw()`'s one caller) is additionally gated
     * on `bcms.incident.notify`.
     */
    #[Test]
    public function withdrawing_an_obligation_requires_incident_notify_not_only_incident_manage(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $manageOnly = $this->user('manage-only@khb.test', ['bcms.incident.view', 'bcms.incident.manage']);

        $this->actingAs($manageOnly)->post(route('bcms.incidents.classify', $incident), [
            'question' => 'cbn', 'answer' => 'no', 'reason' => 'Reassessed: not in fact reportable.',
        ])->assertForbidden();
    }

    #[Test]
    public function withdrawing_an_obligation_succeeds_for_a_holder_of_both_manage_and_notify(): void
    {
        $incident = $this->declareIncident();
        $row = app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        // The officer fixture already holds both `bcms.incident.manage` and
        // `bcms.incident.notify` (setUp) — the CRO-shaped combination ADR
        // 0020 Amendment 2 rule 2 asks for.
        $this->actingAs($this->officer)->post(route('bcms.incidents.classify', $incident), [
            'question' => 'cbn', 'answer' => 'no', 'reason' => 'Reassessed: not in fact reportable.',
        ])->assertRedirect();

        $row->refresh();
        $this->assertNotNull($row->withdrawn_at);
    }

    /**
     * The "no, nothing was ever open" path stays on `.manage` alone — it
     * only writes a decision-log entry, the same authority declaring and
     * regrading an incident already needs.
     */
    #[Test]
    public function answering_no_with_nothing_open_yet_stays_on_manage_alone(): void
    {
        $incident = $this->declareIncident();
        $manageOnly = $this->user('manage-only-2@khb.test', ['bcms.incident.view', 'bcms.incident.manage']);

        $this->actingAs($manageOnly)->post(route('bcms.incidents.classify', $incident), [
            'question' => 'cbn', 'answer' => 'no', 'reason' => 'Never reportable in the first place.',
        ])->assertRedirect();

        $this->assertSame(0, $incident->notifications()->count());
    }

    /**
     * Gate 1 re-gate defect 3: storeLog, storeTask, classify and regrade
     * must all refuse server-side on a closed incident
     * (`crisis-room.md` §3, `incident-stand-down.md` "Already closed").
     */
    #[Test]
    public function every_mutating_incident_action_is_refused_on_a_closed_incident(): void
    {
        $incident = $this->closedIncident();
        $tasksBefore = $incident->tasks()->count();
        $entriesBefore = $incident->entries()->count();
        $severityBefore = $incident->severity;

        $this->actingAs($this->officer)->post(route('bcms.incidents.log.store', $incident), [
            'entry_type' => 'situation_report', 'content' => 'Too late.',
        ])->assertRedirect();
        $this->assertSame($entriesBefore, $incident->entries()->count());

        $this->actingAs($this->officer)->post(route('bcms.incidents.tasks.store', $incident), [
            'title' => 'Too late.',
        ])->assertRedirect();
        $this->assertSame($tasksBefore, $incident->tasks()->count());

        $this->actingAs($this->officer)->post(route('bcms.incidents.classify', $incident), [
            'question' => 'cbn', 'answer' => 'unknown',
        ])->assertRedirect();
        $this->assertSame(0, $incident->notifications()->count());

        $this->actingAs($this->officer)->post(route('bcms.incidents.regrade', $incident), [
            'severity' => 'sev1', 'reason' => 'Should be refused after close.',
        ])->assertRedirect();
        $this->assertSame($severityBefore, $incident->fresh()->severity);
    }

    /* ================================================================== */
    /*  5. Stand down */
    /* ================================================================== */

    #[Test]
    public function the_stand_down_screen_renders_its_component_and_submit_url(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.stand-down-form', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/StandDown', false)
                ->has('checklist')
                ->where('submit_url', route('bcms.incidents.stand-down', $incident))
                ->where('crisis_room_url', route('bcms.incidents.crisis-room', $incident))
                ->where('notifications_url', route('bcms.incidents.notifications.index', $incident))
            );
    }

    /**
     * A-iv: `can_deactivate_plans` reflects whether the CURRENT user holds
     * `bcms.plan.activate` — the exact permission `standDown()` itself gates
     * deactivating an unmentioned activation on (GAP 4) — so the screen can
     * show the "left blank, it is deactivated" hint only where it is true.
     */
    #[Test]
    public function the_stand_down_screen_carries_whether_the_actor_may_deactivate_plans(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.stand-down-form', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_deactivate_plans', true));

        $manageOnly = $this->user('manage-only-standdown@khb.test', ['bcms.incident.view', 'bcms.incident.manage']);

        $this->actingAs($manageOnly)->get(route('bcms.incidents.stand-down-form', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_deactivate_plans', false));
    }

    /**
     * `IncidentPresenter::standDown()`'s checklist carries a `blocks_submit`
     * boolean per item — the screen's submit gate, deliberately NOT the same
     * as `met` (code-reviewer gate 2, D1). `plans` is `false` because
     * `IncidentService::standDown()` settles every plan disposition before
     * it evaluates this checklist for the submit gate — blocking on it made
     * Gap 4 unreachable from the screen. `all_clear` is `false` because it
     * is satisfied only by the very submission it would be gating.
     */
    #[Test]
    public function the_stand_down_checklist_marks_only_plans_and_all_clear_as_non_blocking(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.stand-down-form', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('checklist.0.key', 'tasks')
                ->where('checklist.0.blocks_submit', true)
                ->where('checklist.1.key', 'notifications')
                ->where('checklist.1.blocks_submit', true)
                ->where('checklist.2.key', 'reportability')
                ->where('checklist.2.blocks_submit', true)
                ->where('checklist.3.key', 'all_clear')
                ->where('checklist.3.blocks_submit', false)
                ->where('checklist.4.key', 'plans')
                ->where('checklist.4.blocks_submit', false)
            );
    }

    /**
     * ADR 0020 Amendment 4 (Gate 2 review #1 defect 6, second half) — through
     * the HTTP route, not just the service.
     */
    #[Test]
    public function stand_down_over_http_keeps_a_plan_active_and_closes_the_incident(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'HTTP kept-active plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $activation = PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);

        $this->actingAs($this->officer)->get(route('bcms.incidents.stand-down-form', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('checklist.4.key', 'plans')
                ->where('checklist.4.met', false)
                ->where('checklist.4.blocks_submit', false)
                ->has('plan_activations', 1)
            );

        $this->actingAs($this->officer)->post(route('bcms.incidents.stand-down', $incident), [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
            'plans_remaining_active' => [
                $activation->getKey() => 'The relocated team has not yet returned.',
            ],
        ])->assertRedirect(route('bcms.incidents.crisis-room', $incident));

        $incident->refresh();
        $this->assertSame('closed', $incident->status->value);
        $activation->refresh();
        $this->assertNull($activation->deactivated_at);
        $this->assertNotNull($activation->kept_active_entry_id);
    }

    /**
     * Gap 4 — "leave blank to deactivate" over HTTP: a plan activation whose
     * text box the officer left untouched (no key at all in
     * `plans_remaining_active`) is deactivated as part of the same
     * stand-down, not left open forever.
     */
    #[Test]
    public function stand_down_over_http_deactivates_a_plan_left_blank(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'HTTP deactivated plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $activation = PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);

        // No `plans_remaining_active` key at all for this activation.
        $this->actingAs($this->officer)->post(route('bcms.incidents.stand-down', $incident), [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ])->assertRedirect(route('bcms.incidents.crisis-room', $incident));

        $incident->refresh();
        $this->assertSame('closed', $incident->status->value);
        $activation->refresh();
        $this->assertNotNull($activation->deactivated_at);
        $this->assertNull($activation->kept_active_entry_id);
    }

    /** Gap 4's refusal half, over HTTP: `bcms.plan.activate` is required to deactivate one. */
    #[Test]
    public function stand_down_over_http_is_refused_when_the_actor_cannot_activate_plans(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'HTTP named plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);

        $manageOnly = $this->user('manage-only@khb.test', ['bcms.incident.view', 'bcms.incident.manage']);

        $this->actingAs($manageOnly)->post(route('bcms.incidents.stand-down', $incident), [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertStringContainsString('HTTP named plan', session('error'));
        $incident->refresh();
        $this->assertNotSame('closed', $incident->status->value);
    }

    /** Gap 4 — `dispatch_all_clear` is a validation error, not a silent no-op. */
    #[Test]
    public function stand_down_over_http_refuses_dispatch_all_clear(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $this->actingAs($this->officer)->post(route('bcms.incidents.stand-down', $incident), [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.', 'dispatch_all_clear' => true,
        ])->assertSessionHasErrors('dispatch_all_clear');
    }

    /**
     * QA re-gate D1 — a direct POST must be refused server-side while a
     * `blocks_submit: true` checklist item is unmet, exactly as if the
     * disabled submit button on `StandDown.jsx` had never existed. An open
     * task is `blocks_submit: true` (`IncidentService::standDownChecklist()`)
     * — the button would be disabled, but a client that skips the JS (or a
     * script hitting the route directly) must still be refused, and the
     * incident must not close.
     */
    #[Test]
    public function stand_down_over_http_is_refused_with_an_open_task_and_the_incident_stays_open(): void
    {
        $incident = $this->declareIncident();
        app(IncidentService::class)->addTask($incident, ['title' => 'Check the backup site']);
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $this->actingAs($this->officer)->post(route('bcms.incidents.stand-down', $incident), [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ])->assertRedirect()->assertSessionHas('error');

        $incident->refresh();
        $this->assertNotSame('closed', $incident->status->value);
        $this->assertNull($incident->closed_at);
    }

    /* ================================================================== */
    /*  6. Post-incident review — a delta on the AAR builder */
    /* ================================================================== */

    #[Test]
    public function the_review_screen_renders_its_own_component_not_the_exercise_aar_screen(): void
    {
        $incident = $this->closedIncidentWithPlan();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        app(PirService::class)->refreshPlanSections($aar);

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/Review', false)
                ->where('incident.uuid', $incident->uuid)
                ->where('aar.uuid', $aar->uuid)
                ->where('aar.status', 'draft')
                ->where('aar.iso_clause_ref', 'iso22320.incident_response')
                ->has('plan_sections', 1)
                ->has('conditions', 8)
                ->where('ai.available', false)
                ->where('can.manage', true)
                ->where('urls.update', route('bcms.aars.update', $aar))
                ->where('urls.finalise', route('bcms.incidents.review.finalise', $incident))
                ->where('urls.reopen', route('bcms.aars.reopen', $aar))
                ->where('urls.distribute', route('bcms.aars.distribute', $aar))
                // Gate 1 re-gate defect 6: `ai_draft` is deliberately absent —
                // that action runs exercise-AI synthesis, which bypassed
                // `post_incident_learning` (off by default) for a PIR.
                ->missing('urls.ai_draft')
                ->where('urls.export', route('bcms.incidents.review.export', $incident))
                ->where('urls.raise_finding', route('bcms.findings.store'))
                ->where('urls.crisis_room', route('bcms.incidents.crisis-room', $incident))
            );
    }

    /**
     * A7 (code review #3 advisory): `timeline.entries` eager-loads
     * `loggedBy` — the same N+1 shape defect 8 already closed elsewhere on
     * this presenter. Proven the same way that fix was: the query count
     * against a review with ONE decision logger must equal the count
     * against one with several DISTINCT loggers, not scale with them —
     * plus `logged_by` correctness (several distinct names, not the same
     * one repeated by an eager-load bug).
     */
    #[Test]
    public function the_review_timeline_eager_loads_logged_by_and_the_query_count_does_not_scale_with_distinct_loggers(): void
    {
        $one = $this->closedIncidentWithPlan();
        $oneAar = app(PirService::class)->ensureDraftFor($one);
        app(PirService::class)->refreshPlanSections($oneAar);
        app(IncidentService::class)->log($one, $this->officer, [
            'entry_type' => 'decision', 'content' => 'Decision 0.',
            'options_considered' => 'Options.', 'rationale' => 'Rationale.',
        ]);

        DB::enableQueryLog();
        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $one))->assertOk();
        $queriesAtOneLogger = $this->countBcmsQueries();
        DB::flushQueryLog();
        DB::disableQueryLog();

        $many = $this->closedIncidentWithPlan();
        $manyAar = app(PirService::class)->ensureDraftFor($many);
        app(PirService::class)->refreshPlanSections($manyAar);
        $loggers = collect(range(1, 3))->map(fn ($i) => $this->user("logger{$i}@khb.test", []));

        foreach ($loggers as $i => $logger) {
            app(IncidentService::class)->log($many, $logger, [
                'entry_type' => 'decision', 'content' => "Decision {$i}.",
                'options_considered' => 'Options.', 'rationale' => 'Rationale.',
            ]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $many))->assertOk();
        $queriesAtThreeLoggers = $this->countBcmsQueries();
        DB::disableQueryLog();

        // `closedIncidentWithPlan()` itself already logs 4 decision/
        // escalation entries (declaration, two reassessments, stand-down's
        // all-clear) before this test's own 3, all within the same test
        // run — `logged_at` ties are not given a stable secondary order, so
        // this checks EVERY distinct logger's name is present rather than
        // asserting a specific index.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('timeline.entries', 7)
            ->where('timeline.entries', function ($entries) use ($loggers) {
                $names = collect($entries)->pluck('logged_by')->all();
                foreach ($loggers as $logger) {
                    $this->assertContains($logger->name, $names, "Expected {$logger->name} among logged_by names.");
                }

                return true;
            })
        );

        $this->assertSame(
            $queriesAtOneLogger,
            $queriesAtThreeLoggers,
            "Expected the same query count with 1 and 3 distinct loggers; got {$queriesAtOneLogger} then {$queriesAtThreeLoggers}."
        );
    }

    /**
     * A4 (code review #3 advisory): `can.approve` must reflect the
     * separation-of-duties rule `PirService::finalise()` enforces, not only
     * the raw permission conjunction — `$this->officer` holds both
     * `bcms.aar.manage`/`.approve` but declared this incident, so it must
     * see no Finalise control at all rather than one `finalise()` would
     * then refuse.
     */
    #[Test]
    public function can_approve_reflects_the_separation_of_duties_rule_not_only_the_permission(): void
    {
        $incident = $this->closedIncidentWithPlan();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        app(PirService::class)->refreshPlanSections($aar);

        // $this->officer both declared this incident and holds `bcms.aar.
        // approve` — barred by the separation-of-duties rule regardless.
        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.manage', true)
                ->where('can.approve', false)
                ->where('approver_barred_reason', fn ($reason) => is_string($reason) && $reason !== '')
            );

        $independent = $this->independentApprover();
        $this->actingAs($independent)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.approve', true)
                ->where('approver_barred_reason', null)
            );
    }

    #[Test]
    public function a_user_holding_only_the_exercise_aar_permission_cannot_open_the_pir(): void
    {
        $incident = $this->closedIncident();
        app(PirService::class)->ensureDraftFor($incident);

        $exerciseOnly = $this->user('facilitator@khb.test', ['bcms.exercise.view', 'bcms.aar.manage']);

        $this->actingAs($exerciseOnly)->get(route('bcms.incidents.review.show', $incident))
            ->assertForbidden();
    }

    /**
     * Advisory A11 (Gate 1 re-gate): `AarController::show()` (the shared
     * `bcms.aars.show` route) redirects a PIR to the dedicated
     * `bcms.incidents.review.show` screen rather than rendering the
     * exercise-shaped `Bcms/Exercises/Aar` component against it.
     */
    #[Test]
    public function opening_a_pir_through_the_shared_aar_route_redirects_to_the_dedicated_review_screen(): void
    {
        $incident = $this->closedIncident();
        $pir = app(PirService::class)->ensureDraftFor($incident);

        $viewer = $this->user('aar-route-viewer@khb.test', ['bcms.exercise.view', 'bcms.incident.manage']);

        $this->actingAs($viewer)->get(route('bcms.aars.show', $pir))
            ->assertRedirect(route('bcms.incidents.review.show', $incident));
    }

    #[Test]
    public function visiting_the_review_before_a_pir_exists_redirects_to_the_crisis_room(): void
    {
        $incident = $this->closedIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertRedirect(route('bcms.incidents.crisis-room', $incident));
    }

    #[Test]
    public function the_review_finalises_through_its_own_incident_scoped_route_stamping_iso22320(): void
    {
        $incident = $this->closedIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated to the crisis lead.',
        ]);

        // `$this->officer` declared this incident and logged its opening
        // decision — the defect 5 separation-of-duties rule refuses it as
        // the sole finaliser, so a distinct approver signs.
        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 0,
        ])->assertRedirect(route('bcms.incidents.review.show', $incident));

        $aar->refresh();
        $this->assertSame('final', $aar->status);
        $this->assertSame('iso22320.incident_response', $aar->iso_clause_ref);
    }

    /**
     * Gap 3 — condition 7 used to need `time_to_detect_minutes`,
     * `time_to_declare_minutes` and `time_to_activate_minutes` typed by hand
     * into `quantitative_results.metrics` (every other test in this file
     * still does that, via a direct model `update()`). This one proves the
     * whole route works with NO such write anywhere: `IncidentReviewController
     * ::start()` computes them, `PirService::finalise()` refreshes them
     * again immediately before the gate, and the officer only ever PATCHes
     * the narrative fields over HTTP.
     */
    #[Test]
    public function the_review_finalises_over_http_with_no_manual_metric_patch(): void
    {
        $incident = $this->closedIncidentWithPlan();

        // `start()` is the route a facilitator actually uses — not
        // `PirService::ensureDraftFor()` called directly, which every other
        // fixture in this file does.
        $this->actingAs($this->officer)
            ->post(route('bcms.incidents.review.start', $incident))
            ->assertRedirect(route('bcms.incidents.review.show', $incident));

        $aar = $incident->fresh()->reviews()->first();
        $this->assertNotNull($aar);

        // Computed at `start()`, before any human has touched the review.
        $this->assertArrayHasKey('time_to_declare_minutes', $aar->quantitative_results['metrics'] ?? []);
        $this->assertContains(
            'time_to_detect_minutes',
            collect($aar->quantitative_results['not_measured'] ?? [])->pluck('metric')->all(),
        );

        $planSections = collect((array) $aar->quantitative_results['plan_sections']);

        // Only the narrative and the one plan section's verdict are PATCHed
        // — never `quantitative_results.metrics` or `.not_measured`.
        $this->actingAs($this->officer)->patch(route('bcms.aars.update', $aar), [
            'summary' => 'The core banking outage was contained within the hour.',
            'what_worked' => 'The response plan held throughout.',
            'what_failed' => 'Nothing material.',
            'quantitative_results' => [
                'plan_sections' => $planSections->map(fn (array $s) => [
                    ...$s, 'verdict' => 'held',
                ])->values()->all(),
            ],
        ])->assertRedirect();

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 0,
        ])->assertRedirect(route('bcms.incidents.review.show', $incident));

        $aar->refresh();
        $this->assertSame('final', $aar->status);
        $metrics = (array) $aar->quantitative_results['metrics'];
        $this->assertArrayHasKey('time_to_declare_minutes', $metrics);
        $this->assertArrayHasKey('time_to_activate_minutes', $metrics, 'The fixture activated a real plan, so this must be computed, not not-measured.');
        $this->assertArrayNotHasKey('time_to_detect_minutes', $metrics, 'This one is never computed — see PirService::refreshMetrics().');
    }

    /**
     * Gate 1 re-gate defect 7 (contract pin): the finalise POST carries a
     * REQUIRED top-level `realised_loss_minor`; the review payload ships it
     * back as `aar.realised_loss_minor` (null until confirmed) and the
     * declaration estimate stays on `incident.estimated_impact_minor` — the
     * two are never the same figure and never share a key.
     */
    #[Test]
    public function finalising_over_http_with_a_confirmed_loss_carries_it_on_aar_not_incident(): void
    {
        $incident = $this->closedIncident();
        // The declaration estimate — deliberately different from the
        // confirmed figure, to prove the two never collapse into one.
        $incident->update(['estimated_impact_minor' => 900_000_00, 'currency' => 'NGN']);

        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated to the crisis lead.',
        ]);

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 250_000_00,
        ])->assertRedirect();

        $this->assertSame(1, \App\Models\LossEvent::query()->count());

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aar.realised_loss_minor', 250_000_00)
                ->where('incident.estimated_impact_minor', 900_000_00)
            );
    }

    /**
     * A3 (code review #3 advisory): `ErmBridge` is deliberately tolerant of
     * a failed mirror — the review write itself must succeed regardless —
     * but that must not mean the officer is told nothing. A real
     * `LossEventService::report()` failure (mocked here) is caught, logged,
     * AND surfaced as a `warning` flash on the same finalise redirect that
     * still carries `success`.
     */
    #[Test]
    public function a_failed_erm_mirror_is_surfaced_as_a_warning_not_silence(): void
    {
        $this->mock(\App\Services\LossEvents\LossEventService::class, function ($mock) {
            $mock->shouldReceive('report')->andThrow(new \RuntimeException('ERM database unreachable.'));
        });

        $incident = $this->closedIncident();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated to the crisis lead.',
        ]);

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 250_000_00,
        ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        // The review itself still finalised — the mirror failure does not
        // roll back the PIR write.
        $this->assertSame('final', $aar->fresh()->status);
        $this->assertSame(0, \App\Models\LossEvent::query()->count());
        $this->assertNull($incident->fresh()->erm_loss_event_id);
    }

    #[Test]
    public function finalising_without_realised_loss_minor_is_refused_by_validation(): void
    {
        $incident = $this->closedIncident();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [])
            ->assertSessionHasErrors('realised_loss_minor');

        $this->assertSame('draft', $aar->fresh()->status);
    }

    /**
     * A user with no ERM permission at all — `bcms.incident.view` only —
     * still receives the confirmed figure (the same convention `Findings/
     * Index.jsx` follows for `erm_issue_id`: a plain fact, never a
     * cross-module link that could 403/404 for someone without loss-register
     * access — nothing on this payload is an ERM URL at all).
     */
    #[Test]
    public function the_confirmed_loss_on_the_review_payload_is_not_gated_by_any_erm_permission(): void
    {
        $incident = $this->closedIncident();
        $incident->update(['estimated_impact_minor' => 300_000_00, 'currency' => 'NGN']);

        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated to the crisis lead.',
        ]);
        app(PirService::class)->finalise($aar->fresh(), $this->independentApprover(), 150_000_00);

        $viewOnly = $this->user('erm-none@khb.test', ['bcms.incident.view']);

        $this->actingAs($viewOnly)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aar.realised_loss_minor', 150_000_00)
            );
    }

    /**
     * Gate 2 review #1 defect 7: `IncidentReviewController::show()` called
     * `PirService::refreshPlanSections()` on every read with no status guard,
     * and that method `forceFill()->saveQuietly()`'d the result — a READ
     * silently rewrote a signed-off report's plan-section verdicts, and did
     * it through `saveQuietly()`, bypassing the audit trail too.
     */
    #[Test]
    public function opening_a_finalised_review_does_not_rewrite_its_frozen_plan_sections(): void
    {
        $incident = $this->closedIncidentWithPlan();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        app(PirService::class)->refreshPlanSections($aar);

        $qr = (array) $aar->quantitative_results;
        $qr['plan_sections'][0]['verdict'] = 'held';
        $qr['metrics'] = ['time_to_detect_minutes' => 1, 'time_to_declare_minutes' => 1, 'time_to_activate_minutes' => 1];
        $aar->update([
            'summary' => 'S', 'what_worked' => 'W', 'what_failed' => 'F', 'quantitative_results' => $qr,
        ]);
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated.',
        ]);

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 0,
        ])->assertRedirect();

        $aar->refresh();
        $this->assertSame('final', $aar->status);
        $frozenSections = $aar->quantitative_results['plan_sections'];
        $this->assertCount(1, $frozenSections);

        // A new section is added to the SAME plan after finalisation — if
        // `show()` re-derived `plan_sections` on this read, the frozen
        // snapshot would silently pick it up.
        $planId = PlanSection::query()->findOrFail($frozenSections[0]['section_id'])->plan_id;
        PlanSection::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $planId,
            'section_key' => 'recovery-new', 'title' => 'Added after finalisation', 'sort_order' => 2,
        ]);

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))->assertOk();

        $aar->refresh();
        $this->assertSame($frozenSections, $aar->quantitative_results['plan_sections']);
    }

    /**
     * `urls.raise_finding` is present only for someone who may raise a
     * finding, and only while the review is still open to amend — omitted
     * entirely otherwise (never a key carrying `null`), the live-demo gap
     * this covers: a PIR with no way to raise the finding a "did not hold"
     * section needs to finalise.
     */
    #[Test]
    public function raise_finding_url_is_present_only_for_a_finding_manage_holder_on_a_draft_pir(): void
    {
        $incident = $this->closedIncidentWithPlan();
        $aar = app(PirService::class)->ensureDraftFor($incident);
        app(PirService::class)->refreshPlanSections($aar);

        // $this->officer holds bcms.finding.manage (setUp) and the PIR is
        // still a draft.
        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('urls.raise_finding', route('bcms.findings.store'))
            );

        // Holds every other PIR-viewing permission but not bcms.finding.manage.
        $noFindingManage = $this->user('no-finding-manage@khb.test', [
            'bcms.incident.view', 'bcms.incident.manage', 'bcms.aar.manage', 'bcms.aar.approve',
        ]);
        $this->actingAs($noFindingManage)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('urls.raise_finding'));

        // A finalised review is frozen — the control that would let someone
        // "fix" condition 6 after the fact is gone once there is nothing
        // left to fix it for.
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'escalation', 'content' => 'Escalated.',
        ]);
        $qr = (array) $aar->quantitative_results;
        $qr['plan_sections'][0]['verdict'] = 'held';
        $qr['metrics'] = ['time_to_detect_minutes' => 1, 'time_to_declare_minutes' => 1, 'time_to_activate_minutes' => 1];
        $aar->update(['summary' => 'S', 'what_worked' => 'W', 'what_failed' => 'F', 'quantitative_results' => $qr]);

        $approver = $this->independentApprover();
        $this->actingAs($approver)->post(route('bcms.incidents.review.finalise', $incident), [
            'realised_loss_minor' => 0,
        ])->assertRedirect();

        $this->assertSame('final', $aar->fresh()->status);

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('urls.raise_finding'));
    }

    /**
     * Gate 2 review #1 defect 8: a PIR can carry personal data about staff
     * and customers — `bcms.report.export` alone must not be enough to read
     * its export.
     */
    #[Test]
    public function the_review_export_requires_both_report_export_and_incident_view(): void
    {
        $incident = $this->closedIncident();
        app(PirService::class)->ensureDraftFor($incident);

        $exportOnly = $this->user('export-only-review@khb.test', ['bcms.report.export']);

        $this->actingAs($exportOnly)->get(route('bcms.incidents.review.export', $incident))
            ->assertForbidden();

        $this->actingAs($this->officer)->get(route('bcms.incidents.review.export', $incident))
            ->assertOk();
    }

    /* ================================================================== */
    /*  7. DR system register */
    /* ================================================================== */

    #[Test]
    public function the_dr_register_renders_its_component_and_store_url(): void
    {
        DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'Core Banking DR']);

        $this->actingAs($this->officer)->get(route('bcms.dr-systems.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Dr/Systems/Index', false)
                ->has('systems', 1)
                ->where('systems.0.tests_url', fn ($url) => str_contains($url, '/dr-systems/'))
                ->where('can.manage', true)
                ->where('store_url', route('bcms.dr-systems.store'))
            );
    }

    /* ================================================================== */
    /*  8. DR test history and show */
    /* ================================================================== */

    #[Test]
    public function the_test_history_screen_renders_its_component_and_every_action_url(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'Payments DR']);

        $this->actingAs($this->officer)->get(route('bcms.dr-systems.tests.index', $system))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Dr/Tests/Index', false)
                ->where('system.uuid', $system->uuid)
                ->where('can.record', true)
                ->where('store_url', route('bcms.dr-systems.tests.store', $system))
                ->where('register_url', route('bcms.dr-systems.index'))
            );
    }

    #[Test]
    public function the_test_show_screen_renders_its_component_and_ships_a_server_built_url_not_a_numeric_id(): void
    {
        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Payments DR',
            'rto_target_hours' => 1, 'rpo_target_minutes' => 15,
        ]);
        $test = DrTest::query()->create([
            'organization_id' => $this->organization->id, 'dr_system_id' => $system->getKey(),
            'test_type' => 'failover', 'test_date' => now()->toDateString(),
            'rto_actual_minutes' => 90, 'rpo_actual_minutes' => 5, 'met_objectives' => false,
        ]);

        $this->actingAs($this->officer)->get(route('bcms.dr-tests.show', $test))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Dr/Tests/Show', false)
                ->where('test.id', $test->getKey())
                ->where('can.raise_finding', true)
                ->where('confirm_url', route('bcms.dr-tests.confirm-objectives', $test))
                ->where('raise_finding_url', route('bcms.findings.store'))
                ->where('tests_index_url', route('bcms.dr-systems.tests.index', $system))
            );
    }

    /* ================================================================== */
    /*  9. DR invocation record (dr-failover-failback-record.md, ADR 0020) */
    /* ================================================================== */

    #[Test]
    public function the_dr_invocation_screen_renders_its_component_and_every_server_built_url(): void
    {
        [$incident, $system] = $this->incidentWithDrInvocation();

        $this->actingAs($this->officer)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/DrInvocation', false)
                ->where('incident.uuid', $incident->uuid)
                ->where('has_invocation', true)
                ->where('pir.exists', false)
                ->has('systems', 1)
                ->where('systems.0.uuid', $system->uuid)
                ->where('systems.0.dr_system_url', route('bcms.dr-systems.tests.index', $system))
                ->where('systems.0.authorisation.activation_reason', 'Sev2 declared.')
                ->where('incident_url', route('bcms.incidents.crisis-room', $incident))
                ->where('notification_log_url', route('bcms.incidents.notifications.index', $incident))
            );
    }

    /**
     * A7 (code review #3 advisory): the timeline match against a system's
     * name is case-insensitive — an officer typing a decision-log entry
     * mid-incident does not reliably match the system's stored case.
     */
    #[Test]
    public function the_dr_invocation_timeline_matches_a_system_name_case_insensitively(): void
    {
        [$incident, $system] = $this->incidentWithDrInvocation();
        $this->assertSame('Core Banking DR', $system->name);

        // `communication`, not `decision`: a decision entry's content is
        // appended with "Options considered: .../Rationale: ..." by
        // `IncidentService::log()`, which would make an exact-match
        // assertion about the fixed string below.
        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => 'communication', 'content' => 'CORE BANKING DR failed over to the DR site.',
        ]);

        $this->actingAs($this->officer)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('systems.0.timeline', 1)
                ->where('systems.0.timeline.0.content', 'CORE BANKING DR failed over to the DR site.')
            );
    }

    #[Test]
    public function an_incident_with_no_plan_activation_shows_the_no_invocation_state(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Incidents/DrInvocation', false)
                ->where('has_invocation', false)
                ->has('systems', 0)
            );
    }

    #[Test]
    public function a_user_holding_only_incident_view_cannot_open_the_dr_invocation_record(): void
    {
        [$incident] = $this->incidentWithDrInvocation();

        $incidentOnly = $this->user('incident-only@khb.test', ['bcms.incident.view']);

        $this->actingAs($incidentOnly)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertForbidden();
    }

    #[Test]
    public function a_user_holding_only_dr_view_cannot_open_the_dr_invocation_record(): void
    {
        [$incident] = $this->incidentWithDrInvocation();

        $drOnly = $this->user('dr-only@khb.test', ['bcms.dr.view']);

        $this->actingAs($drOnly)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertForbidden();
    }

    /**
     * Coordinator follow-up: `quantitative_results.dr_invocations.*.failback.
     * at` is the one DrInvocation datetime with no Eloquent cast — written
     * through `bcms.aars.update` (`AarService::update()`), read back through
     * `IncidentPresenter::drInvocation()`. A non-UTC offset must normalise on
     * the way in and come back out as ISO 8601 with an offset, the same
     * instant either way.
     */
    #[Test]
    public function a_dr_invocation_failback_time_with_a_non_utc_offset_normalises_on_write_and_read(): void
    {
        [$incident, $system] = $this->incidentWithDrInvocation();
        $aar = app(PirService::class)->ensureDraftFor($incident);

        $trueInstant = Carbon::parse('2026-01-15T10:30:00+05:00')->utc();

        $this->actingAs($this->officer)->patch(route('bcms.aars.update', $aar), [
            'quantitative_results' => [
                'dr_invocations' => [
                    $system->uuid => [
                        'failback' => [
                            'occurred' => true,
                            'at' => '2026-01-15T10:30:00+05:00',
                            'data_lost_note' => null,
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        $stored = $aar->fresh()->quantitative_results['dr_invocations'][$system->uuid]['failback']['at'];
        $this->assertTrue($trueInstant->equalTo(Carbon::parse($stored)));

        $this->actingAs($this->officer)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('systems.0.failback.occurred', true)
                ->where('systems.0.failback.at', fn ($at) => $trueInstant->equalTo(Carbon::parse($at)))
            );
    }

    /* ================================================================== */
    /*  10. Plan activation and alert compose carry incident_id from the
     *      crisis room (Gate 2 review #1 defect 13) */
    /* ================================================================== */

    #[Test]
    public function activating_a_plan_from_the_crisis_room_stores_the_incident_id(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Crisis-room plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Activated from the crisis room.',
            'incident_id' => $incident->uuid,
        ])->assertRedirect();

        $activation = PlanActivation::query()->where('plan_id', $plan->getKey())->firstOrFail();
        $this->assertSame($incident->getKey(), $activation->incident_id);
    }

    /**
     * A5 (code review #3 advisory): a closed incident does not gain a new
     * plan activation any more than it gains a new manual log entry, task
     * or regrade.
     */
    #[Test]
    public function activating_a_plan_against_a_closed_incident_is_refused(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Late plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->closedIncident();

        $this->actingAs($this->officer)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Too late.',
            'incident_id' => $incident->uuid,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, PlanActivation::query()->where('plan_id', $plan->getKey())->count());
    }

    /**
     * A13 (Gate 1 re-gate): activating a plan and ATTRIBUTING it to an
     * incident is an incident-management act, not only a plan-library one —
     * `bcms.plan.activate` alone is not enough once `incident_id` is present.
     */
    #[Test]
    public function activating_a_plan_with_an_incident_id_requires_incident_manage_too(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Crisis-room plan 3',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->declareIncident();

        $activateOnly = $this->user('activate-only@khb.test', ['bcms.plan.activate']);

        $this->actingAs($activateOnly)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Should be refused.',
            'incident_id' => $incident->uuid,
        ])->assertForbidden();

        $this->assertSame(0, PlanActivation::query()->where('plan_id', $plan->getKey())->count());

        // The same holder, with no `incident_id`, activates from the plan
        // library exactly as before — `.plan.activate` alone is still
        // sufficient for that.
        $this->actingAs($activateOnly)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Activated from the plan library.',
        ])->assertRedirect();
        $this->assertSame(1, PlanActivation::query()->where('plan_id', $plan->getKey())->count());
    }

    #[Test]
    public function activating_a_plan_with_an_incident_uuid_the_user_cannot_see_is_refused(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Crisis-room plan 2',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->declareIncident();
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS-ACT', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $incident->update(['business_unit_id' => $lagos->id]);

        $this->actingAs($this->officer)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Should not resolve.',
            'incident_id' => $incident->uuid,
        ])->assertNotFound();

        $this->assertSame(0, PlanActivation::query()->where('plan_id', $plan->getKey())->count());
    }

    #[Test]
    public function composing_an_alert_from_the_crisis_room_stores_the_incident_id(): void
    {
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->post(route('bcms.alerts.store'), [
            'title' => 'SitRep 1', 'message' => 'Update from the crisis room.', 'severity' => 'advisory',
            'channels' => ['sms'],
            // `org_node`, not the fictional `business_unit` leaf this fixture used
            // to send before `StoreBcmsAlertRequest` validated the shape
            // (`ValidAudienceRule`, ADR 0024 item B) — `AudienceResolver::byOrgNode()`
            // resolves `org_node` ids against `business_units` already.
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'incident_id' => $incident->uuid,
        ])->assertRedirect();

        $alert = Alert::query()->where('incident_id', $incident->getKey())->firstOrFail();
        $this->assertSame('SitRep 1', $alert->title);
    }

    #[Test]
    public function composing_an_alert_with_an_incident_uuid_the_user_cannot_see_is_refused(): void
    {
        $incident = $this->declareIncident();
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS-ALERT', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $incident->update(['business_unit_id' => $lagos->id]);

        $this->actingAs($this->officer)->post(route('bcms.alerts.store'), [
            'title' => 'Should not resolve', 'message' => 'x', 'severity' => 'advisory',
            'incident_id' => $incident->uuid,
        ])->assertNotFound();

        $this->assertSame(0, Alert::query()->where('incident_id', $incident->getKey())->count());
    }

    #[Test]
    public function the_crisis_room_payload_lists_the_linked_plan_activation_and_alert(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Linked plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $incident = $this->declareIncident();

        $this->actingAs($this->officer)->post(route('bcms.plans.activate', $plan), [
            'reason' => 'Activated from the crisis room.', 'incident_id' => $incident->uuid,
        ])->assertRedirect();

        $this->actingAs($this->officer)->post(route('bcms.alerts.store'), [
            'title' => 'Linked alert', 'message' => 'x', 'severity' => 'advisory',
            'channels' => ['sms'],
            // `org_node`, not the fictional `business_unit` leaf this fixture used
            // to send before `StoreBcmsAlertRequest` validated the shape
            // (`ValidAudienceRule`, ADR 0024 item B) — `AudienceResolver::byOrgNode()`
            // resolves `org_node` ids against `business_units` already.
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'incident_id' => $incident->uuid,
        ])->assertRedirect();

        $this->actingAs($this->officer)->get(route('bcms.incidents.crisis-room', $incident))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plan_activations', 1)
                ->where('plan_activations.0.plan', 'Linked plan')
                ->has('alerts', 1)
                ->where('alerts.0.title', 'Linked alert')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function declareIncident(IncidentSeverity $severity = IncidentSeverity::Sev2): Incident
    {
        return app(IncidentService::class)->declare([
            'title' => 'A test incident', 'severity' => $severity->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->officer);
    }

    private function closedIncident(): Incident
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        return app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'The situation is resolved; normal operations have resumed.',
            'reason' => 'Root cause fixed and verified.',
        ]);
    }

    /** @return array{0: Incident, 1: DrSystem} */
    private function incidentWithDrInvocation(): array
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Core Banking Failover Runbook',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Core Banking DR',
            'failover_runbook_plan_id' => $plan->getKey(),
        ]);

        $incident = $this->declareIncident();

        PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Sev2 declared.',
        ]);

        return [$incident, $system];
    }

    private function closedIncidentWithPlan(): Incident
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Kano BCP',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        PlanSection::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'section_key' => 'response', 'title' => 'Initial response procedure', 'sort_order' => 1,
        ]);

        $incident = $this->declareIncident();
        // Deactivated up front so the stand-down gate's "every activated plan
        // is deactivated or explicitly kept active" condition is already met —
        // this fixture only needs the plan SECTION for the PIR's "plan versus
        // actual" delta, not the plan-disposition flow itself, which
        // `Phase10IncidentTest` already covers.
        PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'deactivated_at' => now(), 'activation_reason' => 'Sev2 declared.',
        ]);

        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        return app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'The situation is resolved; normal operations have resumed.',
            'reason' => 'Root cause fixed and verified.',
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')), 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('phase10screens-'.Str::slug($email), 'web');
            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * A user who never declared the incident and never logged a decision on
     * it — the one who CAN finalise its PIR under the defect 5 separation-
     * of-duties rule. `$this->officer` cannot: `declareIncident()` both
     * names it `declared_by` and has it log the declaration's own `decision`
     * entry.
     */
    private function independentApprover(): User
    {
        return $this->user('independent-approver-'.Str::random(8).'@khb.test', [
            'bcms.incident.manage', 'bcms.aar.approve', 'bcms.incident.view',
        ]);
    }
}
