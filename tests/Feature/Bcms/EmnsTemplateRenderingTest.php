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
use App\Models\Bcms\CallTree;
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

            if (\App\Support\Bcms\AlertTemplateVariables::needsPerRecipientVariable($definition['variables'])) {
                // ADR 0024 §3.3 — EXBLOCKED and CONTACTVERIFY are refused at
                // compose, deliberately; the deferral itself is covered by
                // its own test below.
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
     * CODE REVIEW BLOCKING DEFECT, PERMANENT REGRESSION TEST for
     * `audienceRuleMentionsAnySite()`. The test above proves the SAME
     * audience shape fails closed, but with no incident linked there is
     * nothing to fall back to either way — it cannot tell "the exclusion
     * check correctly blocked the fallback" apart from "there was never a
     * fallback source". This is the one shape that CAN tell them apart:
     * an incident genuinely AT Ikeja, so a naive fallback has a real,
     * wrong value ready to supply. Deleting the
     * `audienceRuleMentionsAnySite()` ternary in
     * `computeAlertDerivedVariables()` keeps every OTHER EMNS test green
     * and brings back exactly the shipped defect: "EVACUATE Ikeja Branch
     * NOW" sent to everyone the rule explicitly excludes Ikeja from.
     *
     * Verified by removing the ternary and re-running this test alone: it
     * failed (site_name resolved to "Ikeja Branch" and the render
     * succeeded instead of throwing). Restored immediately after
     * confirming the failure.
     */
    #[Test]
    public function an_incident_at_the_excluded_site_still_fails_closed_on_site_name(): void
    {
        $ikeja = Site::create(['organization_id' => $this->organization->id, 'code' => 'IKJ2', 'name' => 'Ikeja Branch']);

        $incident = app(IncidentService::class)->declare([
            'title' => 'Ikeja branch fire',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev1->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $incident->update(['site_id' => $ikeja->id]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'incident_id' => $incident->getKey(),
            'severity' => $template->severity->value, 'channels' => ['sms'],
            // Everyone in the unit EXCEPT Ikeja — the incident is AT Ikeja,
            // so a fallback that ignored the exclusion would name exactly
            // the site the audience was built to leave out.
            'audience_rule' => [
                'type' => 'all_of',
                'rules' => [
                    ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
                    ['type' => 'none_of', 'rules' => [['type' => 'site', 'ids' => [$ikeja->id]]]],
                ],
            ],
            'template_variables' => ['assembly_point' => 'x'],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en'),
            UnresolvedTemplateVariableException::class,
            'site_name',
        );
    }

    /**
     * The control for the test above: the SAME incident-at-a-site shape,
     * but an audience that names no site AT ALL. This is the one case the
     * fallback exists for, and it must still work — proving the fix is a
     * narrowing of the fallback's CONDITION, not a removal of the
     * fallback itself.
     */
    #[Test]
    public function an_incident_at_a_site_with_no_site_named_in_the_audience_still_derives_it(): void
    {
        $hq = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ3', 'name' => 'Lagos HQ']);

        $incident = app(IncidentService::class)->declare([
            'title' => 'HQ power failure',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev2->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);
        $incident->update(['site_id' => $hq->id]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'incident_id' => $incident->getKey(),
            'severity' => $template->severity->value, 'channels' => ['sms'],
            // No `site` leaf anywhere — the audience is silent about sites,
            // which is exactly when the incident's own site should stand in.
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            'template_variables' => ['assembly_point' => 'x'],
        ], $this->operator->id);

        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en');

        $this->assertStringContainsString('Lagos HQ', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);
    }

    /* ================================================================== */
    /*  CODE REVIEW ADVISORY C — the untested derivations: readiness
    /*  figures and the My Resilience links.
    /* ================================================================== */

    #[Test]
    public function open_task_count_and_blocking_note_derive_from_the_occurrences_readiness_tasks(): void
    {
        $occurrenceId = $this->occurrenceWithNoSite();

        // Two OPEN, one COMPLETE — only the open ones count. `whereIn`
        // status list is `open`, `in_progress`, `overdue`.
        \App\Models\Bcms\ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrenceId,
            'title' => 'Book the venue', 'status' => 'open',
        ]);
        \App\Models\Bcms\ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrenceId,
            'title' => 'Confirm facilitator', 'status' => 'overdue',
        ]);
        \App\Models\Bcms\ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrenceId,
            'title' => 'Print signage', 'status' => 'complete',
        ]);

        $occurrence = \App\Models\Bcms\ExerciseOccurrence::query()->findOrFail($occurrenceId);
        // `location` is free text on the occurrence itself, independent of
        // `site_id` — EXREMINDER declares it and this occurrence has no
        // site, so it needs its own value.
        $occurrence->forceFill(['blocking_tasks_open' => 2, 'location' => 'Training Room B'])->save();

        $template = AlertTemplate::query()->where('code', 'EXREMINDER')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Exercise reminder', 'message' => 'x',
            'template_id' => $template->getKey(), 'occurrence_id' => $occurrenceId,
            'severity' => $template->severity->value, 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en');

        // "2 readiness task(s) open" — the open + overdue ones, not the
        // completed one.
        $this->assertStringContainsString('2 readiness task(s) open', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);

        $email = app(TemplateRenderer::class)->render($alert, ChannelKey::Email, 'en');
        $this->assertStringContainsString(
            '2 blocking task(s) must be closed before the exercise can start.',
            $email->body,
        );
    }

    #[Test]
    public function blocking_task_count_derives_from_the_occurrences_own_stored_count(): void
    {
        $occurrenceId = $this->occurrenceWithNoSite();
        \App\Models\Bcms\ExerciseOccurrence::query()->findOrFail($occurrenceId)
            ->forceFill(['blocking_tasks_open' => 5])->save();

        // Template-free (no template_id): the derivation itself does not
        // depend on which template happens to declare the variable —
        // `EXBLOCKED`, the one template that does, also declares the
        // per-recipient `your_task_count` and can never be composed
        // (ADR 0024 §2's "resulting template status" table), so this is
        // exercised directly rather than through a route that cannot exist.
        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'x', 'message' => 'Blocking tasks: {{blocking_task_count}}',
            'occurrence_id' => $occurrenceId, 'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en');

        $this->assertSame('Blocking tasks: 5', $message->body);
    }

    #[Test]
    public function the_three_my_resilience_links_render_real_urls(): void
    {
        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'x',
            'message' => 'Plans: {{plan_link}} Training: {{training_link}} Profile: {{profile_link}}',
            'severity' => AlertSeverity::Informational->value, 'channels' => ['email'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Email, 'en');

        $base = route('bcms.myresilience.index');
        $this->assertStringContainsString($base.'#plans', $message->body);
        $this->assertStringContainsString($base.'#training', $message->body);
        $this->assertStringContainsString($base.'#profile', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);
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
    /*  ADR 0024 §2 — CALLTREEACT's tree_name, and LESSONSBULLETIN's pir_link.
    /* ================================================================== */

    #[Test]
    public function calltreeact_renders_with_a_call_tree_audience_and_fails_closed_without_one(): void
    {
        $tree = CallTree::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Operations call tree',
        ]);
        $template = AlertTemplate::query()->where('code', 'CALLTREEACT')->where('locale', 'en')->firstOrFail();

        $withTree = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Call tree activated', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'], 'audience_rule' => ['type' => 'call_tree', 'id' => $tree->getKey()],
        ], $this->operator->id);

        $message = app(TemplateRenderer::class)->render($withTree, ChannelKey::Sms, 'en');
        $this->assertStringContainsString('Operations call tree', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);

        // An audience that names no call tree at all cannot render this
        // template — nothing to call it, correctly refused rather than
        // guessed.
        $withoutTree = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Call tree activated', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($withoutTree, ChannelKey::Sms, 'en'),
            UnresolvedTemplateVariableException::class,
            'tree_name',
        );
    }

    #[Test]
    public function lessonsbulletin_is_refused_with_a_draft_pir_and_sendable_with_a_final_one(): void
    {
        $incident = app(IncidentService::class)->declare([
            'title' => 'Core switch failure',
            'severity' => \App\Enums\Bcms\IncidentSeverity::Sev2->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->operator);

        $template = AlertTemplate::query()->where('code', 'LESSONSBULLETIN')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'What we learned', 'message' => 'x',
            'template_id' => $template->getKey(), 'incident_id' => $incident->getKey(),
            'severity' => $template->severity->value, 'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        // No AAR at all yet.
        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en', ['lessons_summary' => 'x']),
            UnresolvedTemplateVariableException::class,
            'pir_link',
        );

        $aar = \App\Models\Bcms\Aar::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'status' => 'draft',
        ]);

        // A DRAFT PIR does not unblock it either — the bulletin must not go
        // out before the review it announces is finished.
        $this->assertThrows(
            fn () => app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en', ['lessons_summary' => 'x']),
            UnresolvedTemplateVariableException::class,
            'pir_link',
        );

        $aar->update(['status' => 'final']);

        $message = app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en', ['lessons_summary' => 'x']);
        $this->assertStringNotContainsString('{{', $message->body);
        $this->assertStringContainsString(route('bcms.incidents.review.show', $incident), $message->body);
    }

    /* ================================================================== */
    /*  A variable removed from the stored map after compose is refused at
    /*  release, exactly as a variable never supplied would be.
    /* ================================================================== */

    #[Test]
    public function removing_a_stored_operator_variable_after_compose_is_refused_at_release(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $this->contact('Amina')->update(['site_id' => $site->id]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park'],
        ], $this->operator->id);

        // Renders clean before the tamper.
        app(TemplateRenderer::class)->render($alert, ChannelKey::Sms, 'en');

        // The test tampers with the stored row directly — no route does
        // this (write once, §1) — to prove `release()` refuses on the
        // stored map alone, the same as a variable never supplied.
        $alert->forceFill(['template_variables' => []])->save();

        // EVACUATE trips dual approval on its own — clear it, since this
        // test is about the stored-variable refusal, not the approval gate.
        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
            'email' => 'second-tamper@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        app(AlertService::class)->approve($alert->refresh(), $this->operator);
        $alert = app(AlertService::class)->approve($alert->refresh(), $second);

        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert->refresh(), $this->operator->id),
            InvalidArgumentException::class,
            'assembly_point',
        );
    }

    /* ================================================================== */
    /*  ADR 0024 §3.7 — estimate()'s preview.
    /* ================================================================== */

    #[Test]
    public function estimate_returns_a_preview_identical_to_what_dispatch_would_actually_send(): void
    {
        $site = Site::create(['organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ']);
        $this->contact('Amina')->update(['site_id' => $site->id]);

        $template = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now', 'message' => 'x',
            'template_id' => $template->getKey(), 'severity' => $template->severity->value,
            'channels' => ['sms'], 'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
            'template_variables' => ['assembly_point' => 'Rear car park, Block B'],
        ], $this->operator->id);
        $alert->forceFill(['is_simulation' => true])->save();

        $estimate = app(AlertService::class)->estimate($alert);

        $this->assertNotEmpty($estimate['preview']);
        $sms = collect($estimate['preview'])->firstWhere('channel', 'sms');
        $this->assertNotNull($sms);
        $this->assertSame('en', $sms['locale']);
        $this->assertStringContainsString('Rear car park, Block B', $sms['body']);
        $this->assertStringNotContainsString('{{', $sms['body']);
        // Simulation prefix included, so the preview is the text that would
        // actually be sent — not a re-derivation of it.
        $this->assertStringStartsWith(\App\Contracts\Bcms\RenderedMessage::EXERCISE_PREFIX, $sms['body']);
        $this->assertIsInt($sms['segments']);
        $this->assertContains($sms['encoding'], ['gsm7', 'ucs2']);

        // Identical to what the dispatcher actually records for the same
        // recipient — release, dispatch, and compare.
        app(AlertService::class)->release($alert, $this->operator->id);
        $ids = AlertRecipient::query()->where('alert_id', $alert->getKey())->pluck('id')->all();
        (new \App\Jobs\Bcms\DispatchAlertChunkJob(
            (int) $alert->getKey(), (int) $alert->organization_id, array_map('intval', $ids),
        ))->handle(app(\App\Services\Bcms\Emns\AlertDispatcher::class));

        $delivery = \App\Models\Bcms\NotificationDelivery::query()
            ->where('alert_id', $alert->getKey())->where('channel', 'sms')->firstOrFail();

        $this->assertSame($sms['body'], $delivery->raw_response['would_have_sent']);
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
