<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\SavedGroup;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\RollCallService;
use App\Services\Bcms\Emns\TemplateRenderer;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Support\Bcms\AlertTemplateVariables;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * What the EMNS console and the live dashboard draw.
 *
 * THE CONSOLE IS OPERATED UNDER STRESS BY SOMEBODY WHO HAS NOT USED IT IN SIX
 * MONTHS. That sentence is from the phase brief and it decides everything here:
 * the payload carries whole, pre-resolved answers — which templates exist,
 * which channels would really send, what a dispatch would cost, whether a
 * second signature is needed — so the screen never has to compute anything
 * while somebody is standing over it.
 *
 * IT SAYS WHICH CHANNELS ARE STILL MOCKED, PROMINENTLY. A crisis manager who
 * believes an SMS went out because a green tick appeared, when the adapter
 * recorded it and dispatched nothing, is the worst failure this module could
 * have — ADR 0004 says so and this is where that promise is kept.
 */
class EmnsPresenter
{
    public function __construct(
        private AlertService $alerts,
        private RollCallService $rollCall,
        private TemplateRenderer $renderer,
        private ChannelRegistry $channels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function console(?User $user = null): array
    {
        /** @var Collection<int, AlertTemplate> $templates */
        $templates = AlertTemplate::query()
            ->where('is_active', true)
            ->where('locale', 'en')
            ->orderBy('category')->orderBy('name')
            ->get();

        return [
            'templates' => $templates->map(fn (AlertTemplate $t) => [
                'id' => (int) $t->getKey(),
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category,
                'severity' => $t->severity,
                'is_life_safety' => (bool) $t->is_life_safety,
                'requires_dual_approval' => (bool) $t->requires_dual_approval,
                'subject' => $t->subject,
                'body' => $t->body,
                'default_channel_set' => $t->default_channel_set,
                'default_audience_rule' => $t->default_audience_rule,
                'sms' => $this->renderer->inspect((string) $t->body),
                // How many of the five languages this scenario can actually go
                // out in, on the card, before anybody picks it.
                'live_locales' => AlertTemplate::query()
                    ->where('code', $t->code)->where('is_active', true)->count(),
                // ADR 0024 §3.5 — one labelled input per operator variable
                // this template declares, in the order declared; no entry
                // for a derived variable (those are `derived_variables`
                // below, read-only, frontend-side).
                'operator_variables' => AlertTemplateVariables::operatorInputsFor(
                    $declaredVariables = array_values(array_filter((array) $t->variables, 'is_string')),
                ),
                // ADR 0024 §3.6 — the composer's read-only "Filled in
                // automatically" list: this template's own declared
                // variables that are `DERIVED`, in declaration order, each
                // with a human label (`{name, label}`) — `site_name` on
                // EVACUATE, say. Never a form field.
                'derived_variables' => AlertTemplateVariables::derivedInputsFor($declaredVariables),
                // ADR 0024 §3.3 — a template needing a per-recipient
                // variable is marked unavailable HERE, with the identical
                // message `StoreBcmsAlertRequest` refuses it with, so an
                // operator learns this while picking a template rather than
                // after filling in a whole form.
                'unavailable_reason' => AlertTemplateVariables::needsPerRecipientVariable($declaredVariables)
                    ? AlertTemplateVariables::PER_RECIPIENT_MESSAGE : null,
            ])->all(),

            'channels' => $this->channels->states(),
            'severities' => $this->renderer->severities(),

            'saved_groups' => SavedGroup::query()->orderBy('name')->get()
                ->map(fn (SavedGroup $g) => [
                    'id' => (int) $g->getKey(),
                    'uuid' => $g->uuid,
                    'name' => $g->name,
                    'description' => $g->description,
                    'rule' => $g->rule,
                ])->all(),

            // ITEM B — the audience picker's own options. Neither composer
            // could set `audience_rule` without these: the grammar
            // (`AudienceRule`, ADR 0003) is free-form JSON server-side, but
            // a human picks a saved group, a call tree, a site, an org node
            // or a role BY NAME, not by typing the rule.
            'audience_options' => $this->audienceOptions(),

            // ITEM D — the occurrence picker. An alert cannot target
            // `occurrence_id` (and so cannot default to simulation) without
            // one to choose from.
            'occurrence_options' => $this->occurrenceOptions(),

            'recent' => Alert::query()
                ->whereNotNull('dispatched_at')
                ->orderByDesc('dispatched_at')->limit(10)->get()
                ->map(fn (Alert $a) => $this->summary($a))->all(),

            'drafts' => Alert::query()
                ->whereNull('dispatched_at')
                ->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Alert $a) => $this->summary($a))->all(),

            'dual_approval' => [
                'severity' => config('bcms.dual_approval.severity'),
                'recipients' => (int) config('bcms.dual_approval.recipients'),
            ],

            'any_channel_mocked' => collect($this->channels->states())
                ->contains(fn (array $c) => $c['is_mock']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(Alert $alert): array
    {
        // `template:id,code,name,locale` used to omit `requires_dual_approval`,
        // a column `requiresDualApproval()` (AlertService::232) reads directly
        // off the relation. The select never fetched it, so the property was
        // always null and the console silently disagreed with `release()`,
        // which reads the same flag through the same relation and refuses to
        // dispatch. Any column a predicate below reads off `template` must be
        // in this list — see BCMS Phase 7 Gate 2 round 3 defect 5.
        $alert->loadMissing(['template:id,code,name,locale,requires_dual_approval', 'initiator:id,name', 'approver:id,name']);

        return [
            'alert' => array_merge($this->summary($alert), [
                'message' => $alert->message,
                'audience_rule' => $alert->audience_rule,
                'response_options' => $alert->response_options,
                'ack_window_minutes' => (int) $alert->ack_window_minutes,
                'escalation_enabled' => (bool) $alert->escalation_enabled,
                'estimated_cost_minor' => $alert->estimated_cost_minor === null ? null : (int) $alert->estimated_cost_minor,
                'actual_cost_minor' => $alert->actual_cost_minor === null ? null : (int) $alert->actual_cost_minor,
                'currency' => $alert->currency,
                'requires_dual_approval' => $this->alerts->requiresDualApproval($alert),
                'is_dispatchable' => $this->alerts->isDispatchable($alert),
                'held_by_quiet_hours' => $this->alerts->isHeldByQuietHours($alert),
                'approved_by' => $alert->approver?->name,
                'approved_at' => $alert->approved_at?->toIso8601String(),
                'second_approved_at' => $alert->second_approved_at?->toIso8601String(),
                'initiated_by' => $alert->initiator?->name,
                'template' => $alert->template?->name,
            ]),
            'roll_call' => $alert->dispatched_at === null ? null : $this->rollCall->summary($alert),
            'channels' => $this->channels->states(),
            'mocked_channels' => array_values(array_filter(array_map(
                fn (ChannelKey $c) => $this->channels->isMock($c) ? $c->value : null,
                $this->alerts->channelKeys($alert),
            ))),
        ];
    }

    /**
     * ITEM B — the audience picker's options, tenant-scoped through each
     * model's own global scope (`BelongsToOrganization`), same as
     * `saved_groups` above. `PUBLIC`, not `private`: the crisis room's own
     * presenter (`IncidentPresenter`) calls this too, so the composer there
     * offers the identical picker rather than a second, drifting one.
     *
     * @return array<string, mixed>
     */
    public function audienceOptions(): array
    {
        return [
            'call_trees' => CallTree::query()->orderBy('name')->get()
                ->map(fn (CallTree $t) => [
                    'id' => (int) $t->getKey(), 'uuid' => $t->uuid, 'name' => $t->name,
                ])->all(),

            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Site $s) => [
                    'id' => (int) $s->getKey(), 'uuid' => $s->uuid, 'name' => $s->name, 'code' => $s->code,
                ])->all(),

            'org_nodes' => BusinessUnit::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (BusinessUnit $u) => [
                    'id' => (int) $u->getKey(), 'name' => $u->name, 'code' => $u->code,
                ])->all(),

            // Role NAMES only — `AudienceResolver::byRole()` matches on
            // `roles.name` (Spatie), not a BCMS-owned role table. Limited to
            // names actually held by a user in this tenant, so the picker
            // does not leak every role name in the system to an operator
            // who could never target most of them.
            'roles' => Role::query()
                ->whereHas('users', fn ($q) => $q->where('organization_id', auth()->user()?->organization_id))
                ->orderBy('name')->pluck('name')->values()->all(),
        ];
    }

    /**
     * ITEM D — the occurrence picker's options: exercises an alert can be
     * linked to, so composing one can default to simulation (standing rule
     * 5). Limited to occurrences not yet run, the only ones a "we are about
     * to run/are running this drill" alert is ever linked to.
     *
     * @return list<array<string, mixed>>
     */
    public function occurrenceOptions(): array
    {
        // ADVISORY H — UPCOMING (OR ALREADY RUNNING), NOT "THE FIRST 50 BY
        // DATE". A plain ascending `orderBy('scheduled_date')->limit(50)`
        // let a backlog of past, never-run `planned` occurrences (missed,
        // never cancelled) push the drills actually coming up off the end
        // of a 50-row page. `in_progress` is kept regardless of date — an
        // occurrence can be running today against a `scheduled_date` set
        // days ago and must still be pickable.
        return ExerciseOccurrence::query()
            ->whereIn('status', [
                OccurrenceStatus::Planned->value, OccurrenceStatus::Confirmed->value, OccurrenceStatus::InProgress->value,
            ])
            ->where(function ($query) {
                $query->where('scheduled_date', '>=', now()->toDateString())
                    ->orWhere('status', OccurrenceStatus::InProgress->value);
            })
            ->orderBy('scheduled_date')
            ->with('definition:id,name')
            ->limit(50)
            ->get()
            ->map(fn (ExerciseOccurrence $o) => [
                'id' => (int) $o->getKey(),
                'uuid' => $o->uuid,
                'name' => $o->definition?->name,
                'scheduled_date' => $o->scheduled_date?->toDateString(),
                'status' => $o->status->value,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Alert $alert): array
    {
        return [
            'id' => (int) $alert->getKey(),
            'uuid' => $alert->uuid,
            'title' => $alert->title,
            'severity' => $alert->severity->value,
            'is_simulation' => (bool) $alert->is_simulation,
            'status' => $alert->status,
            // RESOLVED, not the raw stored column — `$alert->channels` is
            // `[]` for a fallback alert (composed with no explicit choice,
            // e.g. every crisis-room composer), and the console showing an
            // empty set for an alert that actually went out on the tenant's
            // SMS/voice/email defaults is the same "says nothing was sent"
            // failure the audit row fix addresses. `channels_source` tells
            // the screen which case it is looking at.
            'channels' => array_map(fn (ChannelKey $c) => $c->value, $this->alerts->channelKeys($alert)),
            'channels_source' => $alert->channels === [] ? 'tenant_default' : 'alert',
            'recipient_count' => (int) $alert->recipient_count,
            'dispatched_at' => $alert->dispatched_at?->toIso8601String(),
            'occurrence_id' => $alert->occurrence_id === null ? null : (int) $alert->occurrence_id,
        ];
    }
}
