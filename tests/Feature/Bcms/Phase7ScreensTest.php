<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ContactSource;
use App\Jobs\Bcms\DispatchAlertChunkJob;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
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
 * The Phase 7 screens, at the props boundary (development standard §10).
 *
 * THE CONSOLE'S PAYLOAD IS THE FEATURE. It is operated under stress by somebody
 * who has not used it in six months, so every answer it needs — which channels
 * would really send, what a dispatch costs, whether a second signature is
 * required — is resolved on the server and shipped whole. What is asserted here
 * is that those answers are present and honest.
 */
class Phase7ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

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
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS',
            'name' => 'Operations', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_console_says_which_channels_would_actually_send(): void
    {
        $this->actingAs($this->userWith(['bcms.alert.view', 'bcms.alert.compose']))
            ->get(route('bcms.emns.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Emns/Index')
                ->has('templates')
                ->has('channels', 8)
                ->has('severities', 5)
                // Every channel is a Phase 0 mock until an operator supplies
                // credentials, and the screen must say so. A crisis manager who
                // believes an SMS went out when nothing was dispatched is the
                // worst failure this module can have.
                ->where('any_channel_mocked', true)
                ->has('dual_approval.severity')
                ->where('can.compose', true)
                ->where('can.dispatch', false)
            );
    }

    #[Test]
    public function the_alert_screen_carries_the_approval_state_before_dispatch(): void
    {
        $alert = $this->alert(AlertSeverity::Critical);

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.alerts.show', $alert))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Emns/Alert')
                ->where('alert.requires_dual_approval', true)
                ->where('alert.is_dispatchable', false)
                ->where('roll_call', null)
                ->has('mocked_channels')
            );
    }

    #[Test]
    public function the_dashboard_appears_once_the_alert_has_been_dispatched(): void
    {
        $this->contact('Amina');
        $this->contact('Chidi');

        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.alerts.show', $alert->refresh()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Emns/Alert')
                ->where('roll_call.total', 2)
                ->where('roll_call.unaccounted_for', 2)
                ->has('roll_call.by_department')
                ->has('roll_call.funnel')
            );
    }

    #[Test]
    public function the_live_endpoint_matches_the_alert_screens_own_props(): void
    {
        $this->contact('Amina');
        $this->contact('Chidi');

        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $response = $this->actingAs($this->userWith(['bcms.alert.view']))
            ->getJson(route('bcms.alerts.live', $alert->refresh()));

        $response->assertOk();
        $response->assertJsonPath('alert.id', $alert->id);
        $response->assertJsonPath('roll_call.total', 2);
        $response->assertJsonPath('roll_call.unaccounted_for', 2);
        $this->assertArrayHasKey('by_department', $response->json('roll_call'));
    }

    #[Test]
    public function the_live_endpoint_needs_the_alert_view_permission(): void
    {
        $alert = $this->alert(AlertSeverity::Urgent);

        $this->actingAs($this->userWith([], 'nobody-live@khb.test'))
            ->getJson(route('bcms.alerts.live', $alert))
            ->assertForbidden();
    }

    #[Test]
    public function the_live_endpoint_does_not_reach_another_tenants_alert(): void
    {
        $foreign = $this->foreignAlert();

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->getJson(route('bcms.alerts.live', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_roll_call_endpoint_separates_silence_from_unreachable(): void
    {
        $this->contact('Amina');
        $this->contact('Chidi');

        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $recipient = \App\Models\Bcms\AlertRecipient::query()
            ->where('alert_id', $alert->getKey())
            ->whereHas('contact', fn ($q) => $q->where('full_name', 'Amina'))
            ->sole();

        app(\App\Services\Bcms\Emns\RollCallService::class)->record($recipient, 'safe', null, 'in_app');

        $response = $this->actingAs($this->userWith(['bcms.alert.view']))
            ->getJson(route('bcms.alerts.roll-call', $alert->refresh()));

        $response->assertOk();
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('safe', 1);
        $response->assertJsonPath('silent', 1);
        $response->assertJsonPath('unaccounted_for', 1);
    }

    #[Test]
    public function the_roll_call_endpoint_needs_the_alert_view_permission(): void
    {
        $alert = $this->alert(AlertSeverity::Urgent);

        $this->actingAs($this->userWith([], 'nobody-rollcall@khb.test'))
            ->getJson(route('bcms.alerts.roll-call', $alert))
            ->assertForbidden();
    }

    #[Test]
    public function the_roll_call_endpoint_does_not_reach_another_tenants_alert(): void
    {
        $foreign = $this->foreignAlert();

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->getJson(route('bcms.alerts.roll-call', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_template_library_leads_with_the_language_coverage_gap(): void
    {
        AlertTemplate::query()->create([
            'organization_id' => $this->organization->id,
            'code' => 'FLOOD', 'name' => 'Flooding', 'locale' => 'en',
            'body' => 'The ground floor is flooding. Move to the second floor.',
            'severity' => 'urgent', 'is_active' => true,
        ]);

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.alert-templates.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Emns/Templates')
                ->has('coverage.scenarios')
                ->has('coverage.locales', 5)
                ->has('coverage.note')
                // A scenario authored only in English is honestly 1 of 5, not
                // a green tick. A bank that believes it has five-language cover
                // and does not will find out during an evacuation.
                ->where(
                    'coverage.scenarios',
                    fn ($scenarios) => collect($scenarios)->firstWhere('code', 'FLOOD')['locales']['ha']['state'] === 'not_authored',
                )
            );
    }

    #[Test]
    public function the_provider_screen_shows_a_dash_rather_than_a_green_tick_for_an_unused_gateway(): void
    {
        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.providers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Emns/Providers')
                // No traffic yet, so no delivery rates at all — never a
                // flattering 100%.
                ->has('providers', 0)
                ->where('spend.total_minor', null)
                ->has('channels', 8)
            );
    }

    #[Test]
    public function composing_dispatching_and_approving_are_three_different_authorities(): void
    {
        $this->contact('Amina');
        $alert = $this->alert(AlertSeverity::Advisory);

        $composer = $this->userWith(['bcms.alert.view', 'bcms.alert.compose'], 'composer@khb.test');

        // A composer may not dispatch. The permission split is the control that
        // stops one person putting a sentence on twelve thousand phones.
        $this->actingAs($composer)
            ->post(route('bcms.alerts.dispatch', $alert))
            ->assertForbidden();

        $this->actingAs($this->userWith(['bcms.alert.view'], 'reader@khb.test'))
            ->post(route('bcms.alerts.store'), ['title' => 'X', 'severity' => 'advisory'])
            ->assertForbidden();
    }

    /**
     * Defect 5 (Gate 1): the `alerts.escalate-live` route is what makes
     * `AlertController::dispatchAlert()`'s `bcms.alert.life_safety` branch
     * reachable at all — before it existed, nothing could set
     * `is_simulation = false` on an exercise-linked alert, so that branch was
     * dead code. The service-level rule (unconditional dual approval once
     * escalated) is tested in `Phase7EmnsTest`; this is the permission gate.
     */
    #[Test]
    public function the_escalate_live_route_needs_the_life_safety_permission(): void
    {
        $alert = $this->exerciseAlert(AlertSeverity::Advisory);

        $this->actingAs($this->userWith(['bcms.alert.view', 'bcms.alert.dispatch'], 'no-life-safety@khb.test'))
            ->post(route('bcms.alerts.escalate-live', $alert))
            ->assertForbidden();

        $this->assertTrue((bool) $alert->refresh()->is_simulation, 'Refused: still a simulation.');
    }

    #[Test]
    public function escalating_over_the_route_flips_the_flag_but_still_needs_dual_approval_before_dispatch(): void
    {
        $alert = $this->exerciseAlert(AlertSeverity::Advisory);

        $this->actingAs($this->userWith(['bcms.alert.view', 'bcms.alert.life_safety'], 'escalator@khb.test'))
            ->post(route('bcms.alerts.escalate-live', $alert))
            ->assertRedirect();

        $this->assertFalse((bool) $alert->refresh()->is_simulation);
        $this->assertTrue(app(AlertService::class)->requiresDualApproval($alert->refresh()));

        // Escalating does not dispatch anything by itself: one signature is
        // still not dual approval. `life_safety` is required on `dispatch()`
        // too now that the alert is exercise-linked and no longer a
        // simulation, so the dispatcher here needs it to reach that far.
        $dispatcher = $this->userWith(
            ['bcms.alert.view', 'bcms.alert.dispatch', 'bcms.alert.life_safety'],
            'dispatcher@khb.test',
        );

        $this->actingAs($dispatcher)
            ->post(route('bcms.alerts.dispatch', $alert))
            ->assertSessionHasErrors('dispatch');
    }

    #[Test]
    public function the_evidence_export_streams_a_csv_an_examiner_can_read(): void
    {
        $this->contact('Amina');
        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $response = $this->actingAs($this->userWith(['bcms.report.export']))
            ->get(route('bcms.alerts.evidence', $alert->refresh()));

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString('Business continuity — emergency notification evidence', $body);
        $this->assertStringContainsString('All times are UTC.', $body);
        $this->assertStringContainsString('Provider message id', $body);
        $this->assertStringContainsString('Amina', $body);
    }

    /**
     * GATE 2 DEFECT 7, PERMANENT REGRESSION TESTS — the route-level half of
     * the two-tier evidence export. `bcms.report.export` alone is the floor
     * and gets the redacted pack, never a 403; both permissions together get
     * the full pack; neither gets refused outright. `EvidenceExport`'s own
     * mechanics (columns/rows/preamble/filename) are covered in
     * `Phase7EmnsTest::the_redacted_pack_omits_address_and_response_text_and_says_so_on_its_face`
     * — this is the permission wiring in `AlertController::evidence()`.
     *
     * One-line change that would make this fail: `Gate::allows('bcms.contact.export')`
     * hard-coded to `true` (or the export permission check removed) in
     * `AlertController::evidence()`.
     */
    #[Test]
    public function report_export_alone_streams_the_redacted_pack_never_a_403(): void
    {
        $recipient = $this->contact('Amina');
        $recipient->update(['mobile_primary' => '+2348000009999']);
        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);
        $this->dispatchAll($alert);

        $response = $this->actingAs($this->userWith(['bcms.report.export']))
            ->get(route('bcms.alerts.evidence', $alert->refresh()));

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString(
            'REDACTED', $body,
            'The withholding must be declared on the pack\'s own face.',
        );
        $this->assertStringNotContainsString('+2348000009999', $body);

        $headerLine = collect(explode("\n", $body))->first(fn ($line) => str_contains($line, 'Alert reference'));
        $this->assertNotNull($headerLine);
        $this->assertNotContains('Address', str_getcsv((string) $headerLine));
        $this->assertNotContains('Response text', str_getcsv((string) $headerLine));

        $this->assertStringContainsString(
            'evidence-redacted.csv',
            $response->headers->get('content-disposition') ?? '',
        );
    }

    #[Test]
    public function both_permissions_together_stream_the_full_unredacted_pack(): void
    {
        $recipient = $this->contact('Amina');
        $recipient->update(['mobile_primary' => '+2348000009999']);
        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);
        $this->dispatchAll($alert);

        $response = $this->actingAs($this->userWith(['bcms.report.export', 'bcms.contact.export']))
            ->get(route('bcms.alerts.evidence', $alert->refresh()));

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringNotContainsString('REDACTED', $body);
        $this->assertStringContainsString('+2348000009999', $body);

        $this->assertStringContainsString(
            'bcms-alert-'.$alert->uuid.'-evidence.csv',
            $response->headers->get('content-disposition') ?? '',
        );
        $this->assertStringNotContainsString(
            'evidence-redacted.csv',
            $response->headers->get('content-disposition') ?? '',
        );
    }

    #[Test]
    public function neither_permission_is_refused_the_export_outright(): void
    {
        $this->contact('Amina');
        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.alerts.evidence', $alert->refresh()))
            ->assertForbidden();
    }

    /**
     * `bcms.contact.export` alone, without `bcms.report.export`, is still
     * refused — the floor is the export permission, not the contact one.
     */
    #[Test]
    public function contact_export_alone_without_report_export_is_still_refused(): void
    {
        $this->contact('Amina');
        $alert = $this->alert(AlertSeverity::Urgent);
        app(AlertService::class)->release($alert, $this->admin()->id);

        $this->actingAs($this->userWith(['bcms.contact.export']))
            ->get(route('bcms.alerts.evidence', $alert->refresh()))
            ->assertForbidden();
    }

    #[Test]
    public function another_tenants_alert_is_not_reachable(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = Alert::query()->create([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => 'urgent', 'status' => 'draft', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => 1],
        ]);
        TenantContext::set($this->organization->id);

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->get(route('bcms.alerts.show', $foreign))
            ->assertNotFound();
    }

    /**
     * Defect 4 (Gate 1): `Alert` had a cross-tenant test for `show`,
     * `live.json` and `roll-call`; `AlertRecipient` (the `respond` route) and
     * `AlertTemplate` (`update`, `activate`) did not. Both use
     * `BelongsToOrganization`, so the mechanism is probably sound — but
     * "probably" is what these tests exist to remove.
     */
    #[Test]
    public function the_respond_route_does_not_reach_another_tenants_recipient(): void
    {
        [$foreignAlert, $foreignRecipient] = $this->foreignAlertWithRecipient();

        $this->actingAs($this->userWith(['bcms.alert.view']))
            ->post(route('bcms.alerts.respond', [$foreignAlert, $foreignRecipient]), ['response' => 'safe'])
            ->assertNotFound();
    }

    #[Test]
    public function the_template_update_route_does_not_reach_another_tenants_template(): void
    {
        $foreign = $this->foreignTemplate();

        $this->actingAs($this->userWith(['bcms.alert.template.manage']))
            ->patch(route('bcms.alert-templates.update', $foreign), ['body' => 'Rewritten.'])
            ->assertNotFound();
    }

    #[Test]
    public function the_template_activate_route_does_not_reach_another_tenants_template(): void
    {
        $foreign = $this->foreignTemplate();

        $this->actingAs($this->userWith(['bcms.alert.template.manage']))
            ->post(route('bcms.alert-templates.activate', $foreign))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */

    /** Run every queued chunk inline, so a delivery row (and its Address) exists. */
    private function dispatchAll(Alert $alert): void
    {
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($ids, 200) as $chunk) {
            (new DispatchAlertChunkJob((int) $alert->getKey(), (int) $alert->organization_id, $chunk))
                ->handle(app(AlertDispatcher::class));
        }
    }

    private function alert(AlertSeverity $severity): Alert
    {
        return app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Test alert',
            'message' => 'Please respond.',
            'severity' => $severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->admin()->id);
    }

    /** An exercise-linked alert — defaults to simulation, per criterion 5. */
    private function exerciseAlert(AlertSeverity $severity): Alert
    {
        return app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Test exercise alert',
            'message' => 'Please respond.',
            'severity' => $severity->value,
            'channels' => ['sms'],
            'occurrence_id' => $this->occurrence(),
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->admin()->id);
    }

    private function occurrence(): int
    {
        $programme = \App\Models\Bcms\ExerciseProgramme::query()->create([
            'organization_id' => $this->organization->id,
            'year' => (int) now()->year,
            'name' => 'Programme',
            'status' => 'draft',
        ]);

        $type = \App\Models\Bcms\ExerciseType::query()->first();

        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type?->getKey(),
            'name' => 'Evacuation drill',
            'frequency_per_year' => 1,
        ]);

        return (int) \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => \App\Enums\Bcms\OccurrenceStatus::Planned->value,
        ])->getKey();
    }

    /** An alert belonging to a different tenant entirely. */
    private function foreignAlert(): Alert
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = Alert::query()->create([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => 'urgent', 'status' => 'draft', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => 1],
        ]);
        TenantContext::set($this->organization->id);

        return $foreign;
    }

    /**
     * A dispatched alert and its recipient row, both belonging to a
     * different tenant entirely.
     *
     * @return array{0: Alert, 1: \App\Models\Bcms\AlertRecipient}
     */
    private function foreignAlertWithRecipient(): array
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $foreignAlert = Alert::query()->create([
            'organization_id' => $other->id, 'title' => 'Theirs', 'message' => 'x',
            'severity' => 'urgent', 'status' => 'dispatching', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => 1],
        ]);

        $foreignContact = Contact::query()->create([
            'organization_id' => $other->id,
            'source' => ContactSource::Manual->value,
            'full_name' => 'Theirs',
            'employee_id' => 'OB-1',
            'email' => 'theirs@ob.test',
            'mobile_primary' => '+2348009990000',
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now(),
            'is_active' => true,
        ]);

        $foreignRecipient = \App\Models\Bcms\AlertRecipient::query()->create([
            'organization_id' => $other->id,
            'alert_id' => $foreignAlert->getKey(),
            'contact_id' => $foreignContact->getKey(),
            'resolved_channels' => ['sms'],
            'contact_name_snapshot' => $foreignContact->full_name,
            'status' => \App\Enums\Bcms\RecipientStatus::Queued->value,
        ]);

        TenantContext::set($this->organization->id);

        return [$foreignAlert, $foreignRecipient];
    }

    /** A template belonging to a different tenant entirely (not a global one). */
    private function foreignTemplate(): AlertTemplate
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $foreign = AlertTemplate::query()->create([
            'organization_id' => $other->id,
            'code' => 'THEIRS', 'name' => 'Theirs', 'locale' => 'en',
            'body' => 'This belongs to another tenant.',
            'severity' => 'urgent', 'is_active' => true, 'is_system_default' => false,
        ]);

        TenantContext::set($this->organization->id);

        return $foreign;
    }

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'S-'.$n,
            'business_unit_id' => $this->unit->id,
            'email' => 'screen'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->userWith([
            'bcms.alert.view', 'bcms.alert.compose', 'bcms.alert.dispatch',
            'bcms.alert.approve', 'bcms.report.export',
        ], 'admin@khb.test');
    }

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
}
