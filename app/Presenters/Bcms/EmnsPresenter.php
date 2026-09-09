<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\SavedGroup;
use App\Models\User;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\RollCallService;
use App\Services\Bcms\Emns\TemplateRenderer;
use App\Services\Bcms\Notification\ChannelRegistry;
use Illuminate\Support\Collection;

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
        $alert->loadMissing(['template:id,code,name,locale', 'initiator:id,name', 'approver:id,name']);

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
            'channels' => $alert->channels,
            'recipient_count' => (int) $alert->recipient_count,
            'dispatched_at' => $alert->dispatched_at?->toIso8601String(),
            'occurrence_id' => $alert->occurrence_id === null ? null : (int) $alert->occurrence_id,
        ];
    }
}
