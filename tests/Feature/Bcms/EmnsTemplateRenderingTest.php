<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\ContactSource;
use App\Exceptions\Bcms\UnresolvedTemplateVariableException;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Contact;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\TemplateRenderer;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Notification\Channels\SmsSegmenter;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\Reference\AlertTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * THE DEFECT: an alert composed from a template sent SMS and voice text with
 * its `{{placeholders}}` never filled in. `TemplateRenderer::forChannel()`
 * swapped in the SMS/voice `channel_renderings` override AFTER substitution
 * had already run against the primary body, so the override's own
 * placeholders never saw a value — a shipped `EVACUATE` SMS would reach a
 * phone reading "EVACUATE {{site_name}} NOW … Assemble at {{assembly_point}}."
 * `AlertDispatcher` compounded it by never passing any variables at all.
 *
 * THE FIX IS ONE SUBSTITUTION PATH FOR EVERY CHANNEL (`TemplateRenderer`
 * itself) AND FAIL-CLOSED WHEN IT CANNOT FILL EVERYTHING IN
 * (`UnresolvedTemplateVariableException`, refused at `AlertService::release()`
 * and, as a last line of defence, inside `AlertDispatcher::sendOne()`).
 */
class EmnsTemplateRenderingTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Guard: every shipped template renders clean on every channel.
    /* ================================================================== */

    #[Test]
    public function every_shipped_active_template_renders_on_every_channel_with_no_leftover_placeholder(): void
    {
        $renderer = app(TemplateRenderer::class);

        foreach (AlertTemplates::all() as $definition) {
            if (($definition['active'] ?? true) === false) {
                // Pending review — never rendered for real; its own test
                // covers the exclusion (EmnsTemplateSeedingTest).
                continue;
            }

            $template = AlertTemplate::query()
                ->where('code', $definition['code'])->where('locale', $definition['locale'])
                ->firstOrFail();

            $alert = app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'Coverage sweep',
                'message' => 'x',
                'template_id' => $template->getKey(),
                'severity' => $template->severity->value,
                'channels' => ['sms'],
                'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            ], $this->operator->id);

            // A complete, non-empty value for every variable the template
            // itself declares — proving substitution works end to end, not
            // that this test happens to supply the values production can
            // derive today.
            $variables = array_combine(
                $definition['variables'],
                array_map(fn (string $name) => 'Test-'.$name, $definition['variables']),
            );

            foreach (ChannelKey::cases() as $channel) {
                $message = $renderer->render($alert, $channel, 'en', $variables);

                $this->assertStringNotContainsString(
                    '{{',
                    $message->body,
                    "{$definition['code']} ({$definition['locale']}) on {$channel->value} still carries an unfilled placeholder.",
                );

                if ($message->subject !== null) {
                    $this->assertStringNotContainsString('{{', $message->subject);
                }
            }
        }
    }

    /* ================================================================== */
    /*  Guard: EVACUATE's SMS and voice text render with the real values.
    /* ================================================================== */

    #[Test]
    public function evacuates_sms_and_voice_render_with_the_real_site_name_and_assembly_point(): void
    {
        $site = Site::create([
            'organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ',
        ]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms', 'voice'],
            // The site is named directly in the audience rule — exactly what
            // an operator does when targeting "everyone at this site" — so
            // `site_name` is derived, not supplied by the test.
            'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
        ], $this->operator->id);

        $renderer = app(TemplateRenderer::class);

        // `assembly_point` has no structural home on an Alert today (see this
        // fix's HANDOFF) — supplied the way a caller would once one exists.
        $sms = $renderer->render($alert, ChannelKey::Sms, 'en', ['assembly_point' => 'Car Park B']);

        $this->assertSame(
            'EVACUATE Lagos HQ NOW. Nearest safe exit. No lifts. Assemble at Car Park B.',
            $sms->body,
        );
        $this->assertStringNotContainsString('{{', $sms->body);

        $voice = $renderer->render($alert, ChannelKey::Voice, 'en', ['assembly_point' => 'Car Park B']);
        $this->assertStringContainsString('Lagos HQ', $voice->body);
        $this->assertStringContainsString('Car Park B', $voice->body);
        $this->assertStringNotContainsString('{{', $voice->body);

        // The primary body (used for email/push/in-app) is filled in too —
        // same variable set, one substitution path.
        $email = $renderer->render($alert, ChannelKey::Email, 'en', ['assembly_point' => 'Car Park B']);
        $this->assertStringContainsString('Lagos HQ', $email->body);
        $this->assertStringContainsString('Car Park B', $email->body);
        $this->assertStringContainsString('Lagos HQ', (string) $email->subject);
    }

    /* ================================================================== */
    /*  CODE REVIEW BLOCKING DEFECT 1 — an excluded site must never be
    /*  named as the site to evacuate, and a wider (any_of) audience must
    /*  never be narrowed to one branch of it.
    /* ================================================================== */

    /**
     * GATE 2 DEFECT, LIFE-SAFETY SEVERITY, PERMANENT REGRESSION TEST.
     * `all_of[org_node Lagos, none_of[site Ikeja]]` — "everyone in Lagos
     * EXCEPT Ikeja branch" — used to render "EVACUATE Ikeja Branch NOW":
     * the site walk followed every `rules` array it found, including a
     * `none_of` node's, and `none_of`'s children are EXCLUDED from the
     * audience, not a candidate to be named. `EVACUATE` referencing
     * `site_name` must now fail closed instead — there is no other site
     * named in this rule for it to fall back to correctly.
     */
    #[Test]
    public function an_excluded_site_is_never_named_and_evacuate_fails_closed_instead(): void
    {
        $ikeja = Site::create(['organization_id' => $this->organization->id, 'code' => 'IKJ', 'name' => 'Ikeja Branch']);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => [
                'type' => 'all_of',
                'rules' => [
                    ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
                    ['type' => 'none_of', 'rules' => [['type' => 'site', 'ids' => [$ikeja->id]]]],
                ],
            ],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en', ['assembly_point' => 'x']),
            UnresolvedTemplateVariableException::class,
            'site_name',
        );
    }

    /**
     * `any_of[site A, org_node Finance]` is a WIDER audience than "site A
     * alone" — naming A would misname who is actually being addressed.
     * `site_name` must derive nothing here, whether `any_of` is the root or
     * nested inside an `all_of`.
     */
    #[Test]
    public function an_any_of_audience_never_derives_a_single_site(): void
    {
        $siteA = Site::create(['organization_id' => $this->organization->id, 'code' => 'A', 'name' => 'Site A']);

        $rootAnyOf = [
            'type' => 'any_of',
            'rules' => [
                ['type' => 'site', 'ids' => [$siteA->id]],
                ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            ],
        ];

        $nestedAnyOf = [
            'type' => 'all_of',
            'rules' => [
                ['type' => 'site', 'ids' => [$siteA->id]],
                ['type' => 'any_of', 'rules' => [
                    ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
                    ['type' => 'role', 'names' => ['branch-manager'], 'org_node_id' => $this->unit->id],
                ]],
            ],
        ];

        // ALLCLEAR needs `site_name` AND `additional_instructions` — supply
        // the second so any failure below is provably about `site_name`,
        // not a coincidence of some other unrelated missing variable.
        $allClear = AlertTemplate::query()->where('code', 'ALLCLEAR')->where('locale', 'en')->firstOrFail();
        $renderer = app(TemplateRenderer::class);

        foreach ([$rootAnyOf, $nestedAnyOf] as $rule) {
            $alert = app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'All clear', 'message' => 'x',
                'template_id' => $allClear->getKey(), 'severity' => $allClear->severity->value,
                'channels' => ['sms'], 'audience_rule' => $rule,
            ], $this->operator->id);

            $this->assertThrows(
                fn () => $renderer->render($alert, ChannelKey::Sms, 'en', ['additional_instructions' => 'Proceed as normal.']),
                UnresolvedTemplateVariableException::class,
                'site_name',
            );
        }
    }

    /**
     * The two shapes that DO count as "exactly one site" — a bare `site`
     * leaf, and an `all_of` containing exactly one `site` leaf alongside
     * other, non-widening restrictions (`org_node`, `none_of`).
     */
    #[Test]
    public function a_single_site_leaf_or_an_all_of_containing_exactly_one_site_still_derives(): void
    {
        $hq = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ2', 'name' => 'Kano HQ']);

        $bareLeaf = ['type' => 'site', 'ids' => [$hq->id]];

        $allOfWithRestrictions = [
            'type' => 'all_of',
            'rules' => [
                ['type' => 'site', 'ids' => [$hq->id]],
                ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
                ['type' => 'none_of', 'rules' => [
                    ['type' => 'role', 'names' => ['contractor'], 'org_node_id' => $this->unit->id],
                ]],
            ],
        ];

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();
        $renderer = app(TemplateRenderer::class);

        foreach ([$bareLeaf, $allOfWithRestrictions] as $rule) {
            $alert = app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'Evacuate now', 'message' => 'x',
                'template_id' => $template->getKey(), 'severity' => $template->severity->value,
                'channels' => ['sms'], 'audience_rule' => $rule,
            ], $this->operator->id);

            $message = $renderer->render($alert, ChannelKey::Sms, 'en', ['assembly_point' => 'the yard']);
            $this->assertStringContainsString('Kano HQ', $message->body);
            $this->assertStringNotContainsString('{{', $message->body);
        }
    }

    /* ================================================================== */
    /*  What else an Alert genuinely carries: the linked incident.
    /* ================================================================== */

    #[Test]
    public function an_alert_composed_from_the_crisis_room_derives_the_incident_reference_and_title_and_its_site(): void
    {
        $site = Site::create([
            'organization_id' => $this->organization->id, 'code' => 'DC1', 'name' => 'Kano Data Centre',
        ]);

        $incident = app(IncidentService::class)->declare([
            'title' => 'Core switch failure',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev2->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $incident->update(['site_id' => $site->id]);

        $template = AlertTemplate::query()->where('code', 'CRISISCONVENE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Convene the crisis team',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'incident_id' => $incident->getKey(),
            'severity' => AlertSeverity::Critical->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        // `incident_reference` and `incident_title` are derived from the
        // linked incident — not supplied here — and `bridge`/`convene_by`
        // are the genuine gap (no structural home; see this fix's HANDOFF).
        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en', [
            'bridge' => '+234-800-BRIDGE', 'convene_by' => '09:00',
        ]);

        $this->assertStringContainsString($incident->reference, $message->body);
        $this->assertStringContainsString('+234-800-BRIDGE', $message->body);
        $this->assertStringContainsString('09:00', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);
    }

    /**
     * GATE 1 DEFECT, PERMANENT REGRESSION TEST. `site_id` is nullable on
     * both `bcms_incidents` and `bcms_exercise_occurrences` and null is the
     * default — an IT-only incident, or an exercise with no site, genuinely
     * has none. A version of `computeAlertDerivedVariables()` that read
     * `?->site->name` (single nullsafe, dropped "because PHPStan said the
     * second one was redundant") crashed every incident- or exercise-linked
     * alert with no site attached — `ErrorException: Attempt to read
     * property "name" on null` — on `estimate()`, `release()` and
     * `sendOne()`, since that method runs unconditionally for every render,
     * whether or not the chosen template even references `site_name`.
     *
     * One-line change that would make this fail: dropping either `?->`
     * in `TemplateRenderer::computeAlertDerivedVariables()`.
     */
    #[Test]
    public function an_incident_or_exercise_linked_alert_with_no_site_never_throws_a_raw_error(): void
    {
        $incident = app(IncidentService::class)->declare([
            'title' => 'IT-only outage, no building involved',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev3->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $this->assertNull($incident->site_id, 'Sanity: this incident genuinely has no site.');

        $crisisConvene = AlertTemplate::query()->where('code', 'CRISISCONVENE')->where('locale', 'en')->firstOrFail();

        $incidentAlert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Convene the crisis team',
            'message' => 'x',
            'template_id' => $crisisConvene->getKey(),
            'incident_id' => $incident->getKey(),
            'severity' => AlertSeverity::Critical->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        // CRISISCONVENE never references `site_name`, so this must render
        // clean rather than throw — the crash under test happened before
        // the template's own variable list was ever consulted.
        $message = app(TemplateRenderer::class)->render($incidentAlert, ChannelKey::Sms, 'en', [
            'bridge' => 'x', 'convene_by' => 'x',
        ]);
        $this->assertStringNotContainsString('{{', $message->body);

        // The occurrence side of the same guard.
        $occurrenceId = $this->occurrenceWithNoSite();

        $callTreeTemplate = AlertTemplate::query()->where('code', 'CALLTREEACT')->where('locale', 'en')->firstOrFail();

        $occurrenceAlert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Call tree activated',
            'message' => 'x',
            'template_id' => $callTreeTemplate->getKey(),
            'occurrence_id' => $occurrenceId,
            'severity' => AlertSeverity::Urgent->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $occMessage = app(TemplateRenderer::class)->render($occurrenceAlert, ChannelKey::Sms, 'en', [
            'tree_name' => 'Lagos ops',
        ]);
        $this->assertStringNotContainsString('{{', $occMessage->body);

        // And a template that DOES need `site_name`, on the same no-site
        // occurrence, must fail CLOSED — naming the variable — never crash.
        $evacuate = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $evacuateAlert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $evacuate->getKey(),
            'occurrence_id' => $occurrenceId,
            'severity' => $evacuate->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($evacuateAlert, ChannelKey::Sms, 'en', ['assembly_point' => 'x']),
            UnresolvedTemplateVariableException::class,
            'site_name',
        );
    }

    /* ================================================================== */
    /*  Guard: a missing variable fails closed at release, named.
    /* ================================================================== */

    #[Test]
    public function a_template_missing_a_variable_is_refused_at_release_and_names_it(): void
    {
        $this->contact('Amina');

        // ITOUTAGE: no dual approval and a small audience, so the refusal
        // under test is the unresolved-variable one and not the unrelated
        // dual-approval gate.
        $template = AlertTemplate::query()->where('code', 'ITOUTAGE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Core banking outage',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertFalse(app(AlertService::class)->requiresDualApproval($alert), 'Sanity: not the approval gate.');

        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert, $this->operator->id),
            InvalidArgumentException::class,
            'service_name',
        );

        $this->assertSame('draft', $alert->refresh()->status, 'A refused release must not move the alert forward.');
        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    #[Test]
    public function the_estimate_panel_also_fails_closed_with_the_same_named_error(): void
    {
        $this->contact('Amina');

        $template = AlertTemplate::query()->where('code', 'ITOUTAGE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Core banking outage',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(AlertService::class)->estimate($alert),
            UnresolvedTemplateVariableException::class,
            'service_name',
        );
    }

    /* ================================================================== */
    /*  CODE REVIEW BLOCKING DEFECT 2 — the fail-closed check must run
    /*  BEFORE SMS/USSD truncation, not after.
    /* ================================================================== */

    /**
     * GATE 2 DEFECT, PERMANENT REGRESSION TEST. `assertFullyResolved()` used
     * to run AFTER `shapeForChannel()` had already truncated the body to fit
     * two SMS segments. A placeholder sitting past the cut point vanished
     * along with the truncated tail — the check saw only the surviving
     * prefix, found nothing wrong, and the alert would have gone out
     * missing an instruction with no refusal at all. (A placeholder
     * straddling the cut is the other half of the same defect: it would
     * leave a literal `{{...` on the wire — never sent here, but the same
     * root cause.) The check must see the FULL, untruncated body.
     */
    #[Test]
    public function a_placeholder_beyond_the_two_segment_cut_fails_closed_rather_than_vanishing(): void
    {
        $filler = str_repeat('Evacuate the building calmly and proceed to the nearest safe exit. ', 6);
        $message = $filler.'Wait near {{assembly_point}} for further instructions.';

        $this->assertGreaterThan(
            2 * SmsSegmenter::GSM_CONCAT,
            SmsSegmenter::units($message),
            'Sanity: the placeholder sits well beyond where a 2-segment truncation would cut.',
        );

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now',
            'message' => $message,
            'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en'),
            UnresolvedTemplateVariableException::class,
            'assembly_point',
        );
    }

    /* ================================================================== */
    /*  The dispatcher's own last line of defence.
    /* ================================================================== */

    #[Test]
    public function the_dispatcher_fails_a_single_recipient_closed_rather_than_send_a_broken_message(): void
    {
        // A locale-fallback edge `release()`'s preflight does not see: the
        // template is deactivated AFTER release (between materialising
        // recipients and the queued chunk actually running), simulating
        // "something changed mid-dispatch" — exactly what the dispatcher's
        // catch exists for.
        $this->contact('Amina');

        $template = AlertTemplate::query()->where('code', 'ROLLCALL')->where('locale', 'en')->firstOrFail();

        // ROLLCALL has no variables at all, so it renders cleanly and clears
        // the release() preflight...
        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Are you safe?',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        // ROLLCALL is life-safety severity, which trips the dual-approval
        // threshold on its own regardless of the template's own flag — clear
        // it the same way `Phase7EmnsTest::cleared()` does, since this test
        // is about the dispatcher's own defence, not approval.
        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
            'email' => 'second@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        app(AlertService::class)->approve($alert, $this->operator);
        $alert = app(AlertService::class)->approve($alert->refresh(), $second);

        app(AlertService::class)->release($alert, $this->operator->id);

        // ...then the template is edited, after release, to reference a
        // variable nothing will ever supply — the shape of an in-flight
        // content change, not a hypothetical.
        $template->update([
            'channel_renderings' => array_merge((array) $template->channel_renderings, [
                'sms' => 'Are you safe near {{unexpected_variable}}?',
            ]),
        ]);

        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())->pluck('id')->all();
        $job = new \App\Jobs\Bcms\DispatchAlertChunkJob(
            (int) $alert->getKey(), (int) $alert->organization_id, array_map('intval', $ids),
        );
        $job->handle(app(\App\Services\Bcms\Emns\AlertDispatcher::class));

        $recipient = AlertRecipient::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $this->assertSame(\App\Enums\Bcms\RecipientStatus::Failed, $recipient->status);

        $delivery = \App\Models\Bcms\NotificationDelivery::query()->where('alert_id', $alert->getKey())->firstOrFail();
        $this->assertSame(\App\Enums\Bcms\DeliveryStatus::Failed, $delivery->status);
        $this->assertSame('unresolved_template_variable', $delivery->raw_response['error']);
        $this->assertContains('unexpected_variable', $delivery->raw_response['variables']);

        // ITEM 5: `failed_reason` IS A FIXED, GREPPABLE STRING — the column
        // `EvidenceExport` prints verbatim on every no-delivery row. Not
        // `getMessage()`'s full sentence; a stable "what was missing" an
        // examiner can search a CSV for months later.
        $this->assertSame('Template variable(s) not supplied: unexpected_variable', $delivery->failed_reason);
    }

    /* ================================================================== */
    /*  CODE REVIEW ITEM 4 — the two acceptance criteria unproven at the
    /*  HTTP level: estimate() returns a named 422, and a refused dispatch
    /*  returns `errors.dispatch` and writes the audit row.
    /* ================================================================== */

    #[Test]
    public function estimate_returns_a_422_with_the_named_error_over_http(): void
    {
        $this->contact('Amina');

        $template = AlertTemplate::query()->where('code', 'ITOUTAGE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Core banking outage',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->actingAs($this->operator);
        $this->operator->givePermissionTo(Permission::findOrCreate('bcms.alert.compose', 'web'));

        // Deleting `AlertController::estimate()`'s `AlertRenderingRefusedException`
        // catch turns this into an uncaught 500 — this assertion is what
        // fails then, not a mock standing in for the controller.
        $response = $this->postJson(route('bcms.alerts.estimate', $alert));

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));
        $this->assertStringContainsString('service_name', (string) $response->json('error'));
    }

    #[Test]
    public function a_refused_dispatch_returns_errors_dispatch_and_writes_an_audit_row_naming_the_variable(): void
    {
        $this->contact('Amina');

        $template = AlertTemplate::query()->where('code', 'ITOUTAGE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Core banking outage',
            'message' => 'x',
            'template_id' => $template->getKey(),
            'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertFalse(
            app(AlertService::class)->requiresDualApproval($alert),
            'Sanity: the refusal under test is the unresolved-variable one, not the approval gate.',
        );

        $this->actingAs($this->operator);
        $this->operator->givePermissionTo(Permission::findOrCreate('bcms.alert.dispatch', 'web'));

        $response = $this->post(route('bcms.alerts.dispatch', $alert));

        $response->assertSessionHasErrors('dispatch');

        /** @var \Illuminate\Support\ViewErrorBag $errors */
        $errors = session('errors');
        $this->assertStringContainsString('service_name', (string) $errors->get('dispatch')[0]);

        $entry = AuditLog::query()
            ->where('auditable_type', Alert::class)
            ->where('auditable_id', $alert->getKey())
            ->where('event', 'alert.dispatch_refused')
            ->latest('id')->first();

        $this->assertNotNull($entry, 'A refused dispatch must be on the audit record — what an auditor asks to see.');
        $this->assertStringContainsString('service_name', (string) $entry->after['reason']);

        // And nothing was actually queued.
        $this->assertSame('draft', $alert->refresh()->status);
        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
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

    /**
     * A real occurrence row with no `site_id` — the FK is nullable and this
     * is the default, not an edge case a test has to force.
     */
    private function occurrenceWithNoSite(): int
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

        $occurrence = \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => \App\Enums\Bcms\OccurrenceStatus::Planned->value,
        ]);

        $this->assertNull($occurrence->site_id, 'Sanity: this occurrence genuinely has no site.');

        return (int) $occurrence->getKey();
    }
}
