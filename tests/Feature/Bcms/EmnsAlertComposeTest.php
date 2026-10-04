<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Contact;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\EmnsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0024 — the compose path an operator actually uses: request validation
 * for `template_variables`, an audience the store request can now accept
 * (item B), a clear message for the crisis room's stray `severity=high`
 * (item C), an occurrence for a simulation (item D), and the demo seed
 * (item E).
 */
class EmnsAlertComposeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $operator;

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

        $this->operator = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Crisis Manager',
            'email' => 'crisis@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $this->actingAs($this->operator);
        $this->operator->givePermissionTo(Permission::findOrCreate('bcms.alert.compose', 'web'));
        $this->operator->givePermissionTo(Permission::findOrCreate('bcms.alert.dispatch', 'web'));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  ADR 0024 §3.1 — StoreBcmsAlertRequest.
    /* ================================================================== */

    #[Test]
    public function composing_evacuate_with_a_complete_variable_set_over_http_succeeds(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park, Block B'],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $alert = Alert::query()->where('title', 'Evacuate now')->firstOrFail();
        $this->assertSame(['assembly_point' => 'Rear car park, Block B'], $alert->template_variables);
    }

    #[Test]
    public function an_undeclared_variable_key_is_refused(): void
    {
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'template_variables' => [
                'assembly_point' => 'Rear car park',
                // Not declared by EVACUATE, and a derived variable besides —
                // an operator must not be able to override it this way.
                'site_name' => 'Somewhere else entirely',
            ],
        ]);

        $response->assertSessionHasErrors('template_variables.site_name');
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function a_value_containing_double_braces_is_refused(): void
    {
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'template_variables' => ['assembly_point' => 'Car park {{incident_reference}}'],
        ]);

        $response->assertSessionHasErrors('template_variables.assembly_point');
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function a_value_over_max_length_is_refused(): void
    {
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'template_variables' => ['assembly_point' => str_repeat('x', 81)], // max is 80
        ]);

        $response->assertSessionHasErrors('template_variables.assembly_point');
    }

    #[Test]
    public function an_optional_variable_left_empty_is_accepted_and_stored_as_an_explicit_empty_string(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $template = AlertTemplate::query()->where('code', 'ALLCLEAR')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'All clear',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['additional_instructions' => ''],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $alert = Alert::query()->where('title', 'All clear')->firstOrFail();
        $this->assertSame('', $alert->template_variables['additional_instructions']);
    }

    #[Test]
    public function a_required_variable_left_missing_is_refused(): void
    {
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('template_variables.assembly_point');
    }

    #[Test]
    public function a_template_needing_a_per_recipient_variable_is_refused_at_compose(): void
    {
        $template = AlertTemplate::query()->where('code', 'CONTACTVERIFY')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Confirm your details',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('template_id');
        $this->assertStringContainsString(
            'per-person details',
            (string) session('errors')->get('template_id')[0],
        );
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function another_tenants_template_id_is_rejected(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Trust MFB', 'short_name' => 'LTM',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($other->id);
        $otherTemplate = AlertTemplate::query()->create([
            'organization_id' => $other->id, 'code' => 'CUSTOM-OTHER', 'name' => 'Other bank template',
            'locale' => 'en', 'body' => 'x', 'severity' => 'advisory', 'is_active' => true,
        ]);
        TenantContext::set($this->organization->id);

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x',
            'message' => 'x',
            'template_id' => $otherTemplate->getKey(),
            'severity' => 'advisory',
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('template_id');
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function another_tenants_occurrence_id_is_rejected(): void
    {
        $other = Organization::create([
            'name' => 'Lagos Trust MFB 2', 'short_name' => 'LTM2',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($other->id);
        $occurrenceId = $this->occurrenceFor($other);
        TenantContext::set($this->organization->id);

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x', 'message' => 'x',
            'severity' => 'advisory',
            'channels' => ['sms'],
            'occurrence_id' => $occurrenceId,
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('occurrence_id');
        $this->assertSame(0, Alert::query()->count());
    }

    /* ================================================================== */
    /*  ITEM B — audience_rule the store request now accepts.
    /* ================================================================== */

    #[Test]
    public function an_org_node_audience_is_accepted_estimated_and_dispatched_to_real_recipients(): void
    {
        $this->contact('Amina');
        $this->contact('Chidi');

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Routine notice',
            'message' => 'Please read the attached notice.',
            'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertRedirect();
        $alert = Alert::query()->where('title', 'Routine notice')->firstOrFail();

        $estimate = app(AlertService::class)->estimate($alert);
        $this->assertSame(2, $estimate['recipients']);

        app(AlertService::class)->release($alert, $this->operator->id);
        $this->assertSame(2, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    #[Test]
    public function a_malformed_audience_rule_is_refused_by_the_request_not_a_500(): void
    {
        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x', 'message' => 'x', 'severity' => 'advisory', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'not_a_real_leaf_type'],
        ]);

        $response->assertSessionHasErrors('audience_rule');
        $this->assertSame(0, Alert::query()->count());
    }

    /* ================================================================== */
    /*  ITEM C — the crisis room's stray severity="high".
    /* ================================================================== */

    #[Test]
    public function an_invalid_severity_value_gets_a_clear_named_message(): void
    {
        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'SitRep 1', 'message' => 'Update.', 'severity' => 'high', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('severity');
        $message = (string) session('errors')->get('severity')[0];
        $this->assertStringContainsString('informational', $message);
        $this->assertStringContainsString('advisory', $message);
        $this->assertStringContainsString('life_safety', $message);
    }

    /* ================================================================== */
    /*  ITEM D — occurrence_id from the composer, simulation by default.
    /* ================================================================== */

    #[Test]
    public function composing_with_an_occurrence_id_defaults_to_simulation(): void
    {
        $occurrenceId = $this->occurrenceFor($this->organization);

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuation drill', 'message' => 'This is a drill.',
            'severity' => AlertSeverity::LifeSafety->value, 'channels' => ['sms'],
            'occurrence_id' => $occurrenceId,
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertRedirect();
        $alert = Alert::query()->where('title', 'Evacuation drill')->firstOrFail();

        $this->assertSame($occurrenceId, $alert->occurrence_id);
        $this->assertTrue((bool) $alert->is_simulation, 'Standing rule 5: exercise-linked alerts default to simulation.');
    }

    /* ================================================================== */
    /*  End to end — EVACUATE, approved where required, dispatched as a
    /*  simulation, the mock SMS body carries the operator's own words.
    /* ================================================================== */

    #[Test]
    public function evacuate_composed_approved_and_dispatched_carries_the_operators_assembly_point(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $this->contact('Amina');
        $this->contact('Chidi')->update(['site_id' => $site->id]);
        Contact::query()->update(['site_id' => $site->id]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $store = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park, Block B'],
        ]);
        $store->assertRedirect();

        $alert = Alert::query()->where('title', 'Evacuate now')->firstOrFail();
        // A SIMULATION NEVER NEEDS APPROVAL (criterion 5) — this test is
        // about the render/dispatch path, not the dual-approval gate,
        // which `EmnsAlertComposeTest`'s sibling files already cover.
        $alert->forceFill(['is_simulation' => true])->save();

        $dispatch = $this->post(route('bcms.alerts.dispatch', $alert));
        $dispatch->assertRedirect();

        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())->pluck('id')->all();
        (new \App\Jobs\Bcms\DispatchAlertChunkJob(
            (int) $alert->getKey(), (int) $alert->organization_id, array_map('intval', $ids),
        ))->handle(app(AlertDispatcher::class));

        $delivery = NotificationDelivery::query()
            ->where('alert_id', $alert->getKey())->where('channel', ChannelKey::Sms->value)
            ->firstOrFail();

        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertStringContainsString('Rear car park, Block B', (string) $delivery->raw_response['would_have_sent']);
        $this->assertStringNotContainsString('{{', (string) $delivery->raw_response['would_have_sent']);

        // CODE REVIEW BLOCKING ITEM, THE OTHER CASE — an alert composed WITH
        // an explicit channel list must record `channels_source: 'alert'`,
        // not `'tenant_default'`, and the audit `channels` must match what
        // was explicitly asked for.
        $audit = \App\Models\Bcms\AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $alert->getKey())
            ->where('event', 'alert.dispatched')
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(['sms'], $audit->after['channels']);
        $this->assertSame(['sms'], $audit->after['channels_requested']);
        $this->assertSame('alert', $audit->after['channels_source']);
    }

    /* ================================================================== */
    /*  ITEM E — the demo seed.
    /* ================================================================== */

    #[Test]
    public function the_demo_evacuation_drill_seeds_a_dispatched_simulation_not_a_stuck_draft(): void
    {
        $hq = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Head Office']);

        for ($i = 0; $i < 10; $i++) {
            $this->contact('Demo Person '.$i)->update(['site_id' => $hq->id]);
        }

        (new EmnsDemoSeeder)->run($this->organization);

        $alert = Alert::query()->where('title', 'Head office evacuation drill')->first();
        $this->assertNotNull($alert, 'The demo seeder must produce the evacuation drill alert.');

        $this->assertSame('dispatched', $alert->status, 'Must not be left as a stuck, unsendable draft.');
        $this->assertGreaterThan(0, $alert->recipient_count);
        $this->assertSame(['assembly_point' => 'Rear car park, Block B'], $alert->template_variables);

        // The renderer must actually be able to produce the SMS with no
        // unfilled placeholder — the concrete proof the seed is sendable.
        $message = app(\App\Services\Bcms\Emns\TemplateRenderer::class)
            ->render($alert, ChannelKey::Sms, 'en');
        $this->assertStringNotContainsString('{{', $message->body);
        $this->assertStringContainsString('Rear car park, Block B', $message->body);
    }

    /* ================================================================== */
    /*  CODE REVIEW BLOCKING DEFECT 1, LIFE-SAFETY — a stored operator
    /*  variable naming a DERIVED variable must never shadow it.
    /* ================================================================== */

    /**
     * GATE 2 DEFECT, LIFE-SAFETY SEVERITY, PERMANENT REGRESSION TEST. QA
     * proved this through `AlertService::compose()` directly — the path
     * every seeder uses, which `StoreBcmsAlertRequest`'s own refusal never
     * reaches: a stored `template_variables` row holding `site_name`
     * rendered `EVACUATE` as "EVACUATE THE WRONG BUILDING NOW". Two
     * defences, tested separately: `compose()` refuses the key outright
     * (this test), and even if a row somehow held one anyway, the merge
     * order in `TemplateRenderer::variablesFor()` makes the derived value
     * win structurally (the next test, which bypasses `compose()`'s guard
     * with a direct `forceFill()` to prove the SECOND defence on its own).
     */
    #[Test]
    public function direct_compose_with_a_derived_variable_key_is_refused(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $this->assertThrows(
            fn () => app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'Evacuate now', 'message' => 'x',
                'template_id' => $template->getKey(), 'severity' => $template->severity->value,
                'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
                'template_variables' => [
                    'assembly_point' => 'Rear car park',
                    // A DERIVED variable, never operator-entered — this is
                    // exactly the shape that shipped once.
                    'site_name' => 'The Wrong Building',
                ],
            ], $this->operator->id),
            \InvalidArgumentException::class,
            'site_name',
        );

        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function a_stored_derived_key_that_bypasses_compose_still_loses_to_the_real_derived_value(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park'],
        ], $this->operator->id);

        // No route or service method can produce this — `compose()`'s own
        // guard (the test above) refuses it, and there is no edit route
        // (write once). This forces the shape directly to prove the SECOND,
        // structural defence: the merge order itself, independent of
        // whether the write-time guard ever runs.
        $alert->forceFill(['template_variables' => [
            'assembly_point' => 'Rear car park', 'site_name' => 'THE WRONG BUILDING',
        ]])->save();

        $message = app(\App\Services\Bcms\Emns\TemplateRenderer::class)
            ->render($alert->refresh(), ChannelKey::Sms, 'en');

        $this->assertStringContainsString('Lagos HQ', $message->body);
        $this->assertStringNotContainsString('WRONG BUILDING', $message->body);
    }

    /* ================================================================== */
    /*  CODE REVIEW DEFECT 2 — an empty message must 422, never 500.
    /* ================================================================== */

    #[Test]
    public function composing_with_no_message_returns_a_422_naming_the_field(): void
    {
        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x', 'severity' => 'advisory', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('message');
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function direct_compose_with_no_message_defaults_to_the_templates_name_rather_than_500ing(): void
    {
        $template = AlertTemplate::query()->where('code', 'ROLLCALL')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Are you safe?',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertSame($template->name, $alert->message);
    }

    /* ================================================================== */
    /*  CODE REVIEW DEFECT 3 — typographic punctuation in a derived site
    /*  name must not silently move an otherwise-GSM-7 SMS to UCS-2.
    /* ================================================================== */

    #[Test]
    public function evacuate_with_an_em_dash_site_name_stays_gsm7_and_fits_two_segments(): void
    {
        $site = Site::create([
            'organization_id' => $this->organization->id, 'code' => 'HQ',
            // The exact shape QA found in the browser: an em dash in a real
            // site name, pasted from wherever site names get typed.
            'name' => 'Kano Heritage Bank — Head Office, Kano',
        ]);
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park, Block B'],
        ], $this->operator->id);

        $message = app(\App\Services\Bcms\Emns\TemplateRenderer::class)
            ->render($alert, ChannelKey::Sms, 'en');

        $this->assertTrue(
            \App\Services\Bcms\Notification\Channels\SmsSegmenter::isGsm7($message->body),
            'An em dash from a site name must not push an otherwise-ASCII SMS to UCS-2.',
        );
        $this->assertLessThanOrEqual(2, \App\Services\Bcms\Notification\Channels\SmsSegmenter::segments($message->body));
        $this->assertStringContainsString('Kano Heritage Bank - Head Office, Kano', $message->body);
        $this->assertStringNotContainsString("\u{2014}", $message->body, 'The em dash itself must not reach the wire.');
    }

    /* ================================================================== */
    /*  CODE REVIEW ADVISORIES.
    /* ================================================================== */

    /** Advisory A — a soft-deleted template must 422 at store, not silently draft-and-die. */
    #[Test]
    public function a_soft_deleted_template_id_is_rejected_at_store(): void
    {
        $template = AlertTemplate::query()->where('code', 'ROLLCALL')->where('locale', 'en')->firstOrFail();
        $template->delete();
        $this->assertTrue($template->trashed());

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x', 'message' => 'x', 'template_id' => $template->getKey(),
            'severity' => 'advisory', 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('template_id');
        $this->assertSame(0, Alert::query()->count());
    }

    /** Advisory A — a soft-deleted occurrence must 422 at store too. */
    #[Test]
    public function a_soft_deleted_occurrence_id_is_rejected_at_store(): void
    {
        $occurrenceId = $this->occurrenceFor($this->organization);
        \App\Models\Bcms\ExerciseOccurrence::query()->findOrFail($occurrenceId)->delete();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'x', 'message' => 'x', 'severity' => 'advisory', 'channels' => ['sms'],
            'occurrence_id' => $occurrenceId,
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('occurrence_id');
        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * Advisory B — a numeric key (a submitted list rather than an object,
     * e.g. `template_variables[0]=x`) must be refused, not silently
     * ignored.
     */
    #[Test]
    public function a_numeric_template_variable_key_is_refused_not_skipped(): void
    {
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Evacuate now', 'message' => 'x', 'template_id' => $template->getKey(),
            'severity' => $template->severity->value, 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            // A bare list — decodes to `[0 => 'Rear car park']`, a numeric
            // key, and still misses the required `assembly_point` string
            // key besides.
            'template_variables' => ['Rear car park'],
        ]);

        $response->assertSessionHasErrors('template_variables.0');
        $this->assertSame(0, Alert::query()->count());
    }

    /** Advisory B — the same rule, direct `compose()` call. */
    #[Test]
    public function direct_compose_with_a_numeric_template_variable_key_is_refused(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQNUM', 'name' => 'Lagos HQ']);
        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $this->assertThrows(
            fn () => app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'Evacuate now', 'message' => 'x',
                'template_id' => $template->getKey(), 'severity' => $template->severity->value,
                'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
                'template_variables' => ['Rear car park'],
            ], $this->operator->id),
            \InvalidArgumentException::class,
        );

        $this->assertSame(0, Alert::query()->count());
    }

    /**
     * Advisory F, PERMANENT REGRESSION TEST. The crisis room tells an
     * operator "This incident is flagged as an exercise; the alert stays a
     * simulation" for any incident with `is_exercise = true` — this is what
     * makes that true.
     */
    #[Test]
    public function an_alert_linked_to_an_exercise_incident_defaults_to_simulation(): void
    {
        // `IncidentService::declare()` always writes `is_exercise = false`
        // (a declared incident is never marked as an exercise through that
        // path — Phase10IncidentTest pins it) — this is set directly, the
        // way the exercise engine itself does it for a drill-linked record.
        $incident = app(\App\Services\Bcms\Incidents\IncidentService::class)->declare([
            'title' => 'Evacuation drill sitrep',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev3->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $incident->forceFill(['is_exercise' => true])->save();
        $this->assertTrue((bool) $incident->refresh()->is_exercise, 'Sanity.');

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'SitRep', 'message' => 'Update from the drill.',
            'incident_id' => $incident->getKey(), 'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertTrue(
            (bool) $alert->is_simulation,
            'An alert linked to an exercise-flagged incident must default to simulation, the same way occurrence_id does.',
        );
    }

    /** Advisory F control — a non-exercise incident does not force it. */
    #[Test]
    public function an_alert_linked_to_a_real_incident_does_not_default_to_simulation(): void
    {
        $incident = app(\App\Services\Bcms\Incidents\IncidentService::class)->declare([
            'title' => 'Real fire',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev1->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $this->assertFalse((bool) $incident->refresh()->is_exercise, 'Sanity.');

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'SitRep', 'message' => 'Update.',
            'incident_id' => $incident->getKey(), 'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertFalse((bool) $alert->is_simulation);
    }

    /**
     * Advisory H. Chose FILTERING (upcoming, i.e. `scheduled_date >= today`,
     * plus `in_progress` regardless of date) over an "upcoming-first, then
     * the rest" ORDER BY — a past, never-run `planned` occurrence is a
     * missed drill, not one an operator composing a simulation should be
     * offered at all, so leaving it out entirely is more honest than
     * merely de-prioritising it.
     */
    #[Test]
    public function occurrence_options_excludes_a_past_backlog_and_keeps_in_progress_and_upcoming(): void
    {
        $pastBacklog = $this->occurrenceFor($this->organization, now()->subDays(30)->toDateString(), 'planned', 'Old missed drill');
        $inProgress = $this->occurrenceFor($this->organization, now()->subDays(1)->toDateString(), 'in_progress', 'Running now');
        $upcoming = $this->occurrenceFor($this->organization, now()->addDays(3)->toDateString(), 'planned', 'Coming up');

        $options = app(\App\Presenters\Bcms\EmnsPresenter::class)->occurrenceOptions();
        $ids = array_column($options, 'id');

        $this->assertNotContains($pastBacklog, $ids, 'A past, never-run planned occurrence must not push out upcoming drills.');
        $this->assertContains($inProgress, $ids, 'An in-progress occurrence must stay offered even if its scheduled date has passed.');
        $this->assertContains($upcoming, $ids);
    }

    /* ================================================================== */
    /*  DEMO 500 — the crisis room's roll-call/SitRep/stakeholder composers
    /*  send no `channels` key at all. `bcms_alerts.channels` is `json NOT
    /*  NULL` with no default, so an omitted or null `channels` must not
    /*  reach `Alert::query()->create()` as a missing/null value.
    /* ================================================================== */

    #[Test]
    public function composing_a_roll_call_with_no_channels_key_stores_an_empty_array_not_a_500(): void
    {
        $incident = app(\App\Services\Bcms\Incidents\IncidentService::class)->declare([
            'title' => 'DC power failure',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev1->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);

        $tree = \App\Models\Bcms\CallTree::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Exactly the crisis room `AlertComposer`'s POST body: no `channels`
        // key at all.
        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'CMT roll-call: DC power failure',
            'message' => 'Please confirm your status.',
            'severity' => AlertSeverity::Critical->value,
            'incident_id' => $incident->uuid,
            'audience_rule' => ['type' => 'call_tree', 'id' => $tree->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $alert = Alert::query()->where('title', 'CMT roll-call: DC power failure')->firstOrFail();
        $this->assertSame([], $alert->channels);

        // Dispatch-time fallback still applies: an alert composed without
        // channels picks up the tenant's default set at `estimate()`, not
        // the (nonexistent) defaults baked in at compose time.
        $estimate = app(AlertService::class)->estimate($alert);
        $this->assertSame(
            array_map(fn (ChannelKey $c) => $c->value, app(\App\Services\Bcms\BcmsSettings::class)->defaultChannels($this->organization->id)),
            $estimate['channels'],
        );
    }

    #[Test]
    public function composing_with_an_explicit_null_channels_stores_an_empty_array_not_a_500(): void
    {
        $incident = app(\App\Services\Bcms\Incidents\IncidentService::class)->declare([
            'title' => 'DC power failure 2',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev1->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);

        $tree = \App\Models\Bcms\CallTree::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'CMT roll-call: DC power failure 2',
            'message' => 'Please confirm your status.',
            'severity' => AlertSeverity::Critical->value,
            'channels' => null,
            'incident_id' => $incident->uuid,
            'audience_rule' => ['type' => 'call_tree', 'id' => $tree->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $alert = Alert::query()->where('title', 'CMT roll-call: DC power failure 2')->firstOrFail();
        $this->assertSame([], $alert->channels);

        $estimate = app(AlertService::class)->estimate($alert);
        $this->assertSame(
            array_map(fn (ChannelKey $c) => $c->value, app(\App\Services\Bcms\BcmsSettings::class)->defaultChannels($this->organization->id)),
            $estimate['channels'],
        );
    }

    /**
     * QA GATE 4 — END TO END. `channels []` stored at compose (the fix under
     * test) must still reach real people: `channelKeys()`'s dispatch-time
     * fallback to `BcmsSettings::defaultChannels()` is exercised all the way
     * through to `NotificationDelivery` rows, not merely asserted on the
     * `estimate()` payload the way the two tests above do. No channel
     * adapter is swapped — `ChannelRegistry::for()` already falls back to
     * the mock email/SMS adapters with no credentials configured (the same
     * behaviour the live demo ran against), so this exercises the exact
     * path a real, uncredentialled deployment takes.
     */
    #[Test]
    public function dispatching_an_alert_stored_with_empty_channels_reaches_real_recipients_on_the_tenant_defaults(): void
    {
        $amina = $this->contact('Amina');
        $chidi = $this->contact('Chidi');

        $tree = \App\Models\Bcms\CallTree::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        foreach ([$amina, $chidi] as $member) {
            \App\Models\Bcms\CallTreeNode::query()->create([
                'organization_id' => $this->organization->id, 'call_tree_id' => $tree->id,
                'tier' => 1, 'contact_id' => $member->id,
            ]);
        }

        // Exactly the crisis room `AlertComposer`'s POST body: no `channels`
        // key at all. Advisory severity and two recipients trip neither the
        // severity nor the headcount dual-approval threshold, so this stays
        // about the channels fallback, not the approval gate.
        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'CMT roll-call: reaches real recipients',
            'message' => 'Please confirm your status.',
            'severity' => AlertSeverity::Advisory->value,
            'audience_rule' => ['type' => 'call_tree', 'id' => $tree->id],
        ]);
        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $alert = Alert::query()->where('title', 'CMT roll-call: reaches real recipients')->firstOrFail();
        $this->assertSame([], $alert->channels, 'Sanity: stored empty, per the fix under test.');
        $this->assertFalse(app(AlertService::class)->requiresDualApproval($alert), 'Sanity: not the approval gate.');

        app(AlertService::class)->release($alert, $this->operator->id);

        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        (new \App\Jobs\Bcms\DispatchAlertChunkJob(
            (int) $alert->getKey(), (int) $alert->organization_id, $ids,
        ))->handle(app(AlertDispatcher::class));

        $deliveries = NotificationDelivery::query()->where('alert_id', $alert->getKey())->get();

        $expectedChannels = array_map(
            fn (ChannelKey $c) => $c->value,
            app(\App\Services\Bcms\BcmsSettings::class)->defaultChannels($this->organization->id),
        );

        $this->assertNotEmpty(
            $deliveries,
            'An alert stored with an empty channels list must still dispatch, on the tenant default channels.',
        );

        foreach ($deliveries as $delivery) {
            $this->assertContains(
                $delivery->channel->value,
                $expectedChannels,
                'Every delivery must be on a tenant default channel — none was named at compose.',
            );
            $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        }

        // Both contacts, verified on email and mobile by the `contact()`
        // helper, reached on every default channel — the live demo's own
        // shape (email delivered; SMS depends on a verified mobile number,
        // which these contacts have).
        $this->assertCount(count($expectedChannels) * 2, $deliveries);

        $recipients = AlertRecipient::query()->where('alert_id', $alert->getKey())->get();
        $this->assertCount(2, $recipients);
        $this->assertTrue($recipients->every(fn (AlertRecipient $r) => $r->status === \App\Enums\Bcms\RecipientStatus::Sent));

        // CODE REVIEW BLOCKING ITEM — the `alert.dispatched` audit row must
        // name the RESOLVED channels (what actually went out), not the
        // stored `[]`, and must say WHY it differs from the stored value.
        $audit = \App\Models\Bcms\AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $alert->getKey())
            ->where('event', 'alert.dispatched')
            ->latest('id')->first();
        $this->assertNotNull($audit, 'The dispatch must be on the audit record.');
        $this->assertSame(
            $expectedChannels,
            $audit->after['channels'],
            'The audit row must record the tenant default channels actually used, not the stored empty set.',
        );
        $this->assertSame([], $audit->after['channels_requested']);
        $this->assertSame('tenant_default', $audit->after['channels_source']);
    }

    /* ================================================================== */
    /*  Helpers.
    /* ================================================================== */

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => $name,
            'email' => 'p'.$n.'@khb.test', 'password' => bcrypt('secret'), 'is_active' => false,
        ]);

        return Contact::query()->create([
            'organization_id' => $this->organization->id, 'user_id' => $user->id,
            'source' => ContactSource::Manual->value, 'full_name' => $name,
            'employee_id' => 'E-'.$n, 'business_unit_id' => $this->unit->id,
            'email' => 'contact'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en', 'consent_status' => 'granted',
            'verification_status' => 'verified', 'last_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function occurrenceFor(
        Organization $organization,
        ?string $scheduledDate = null,
        string $status = 'planned',
        string $name = 'Evacuation drill',
    ): int {
        static $sequence = 0;
        $sequence++;

        $unit = BusinessUnit::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'code' => 'BU-OCC'],
            ['name' => 'Operations', 'is_active' => true],
        );

        $programme = \App\Models\Bcms\ExerciseProgramme::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'year' => (int) now()->year],
            ['name' => 'Programme', 'status' => 'draft'],
        );

        $type = \App\Models\Bcms\ExerciseType::query()->first();

        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'organization_id' => $organization->id, 'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type?->getKey(), 'business_unit_id' => $unit->id,
            'name' => $name.' '.$sequence, 'frequency_per_year' => 1,
        ]);

        return (int) \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $organization->id, 'definition_id' => $definition->getKey(),
            'sequence_no' => 1, 'scheduled_date' => $scheduledDate ?? now()->addDay()->toDateString(),
            'status' => $status,
        ])->getKey();
    }
}
