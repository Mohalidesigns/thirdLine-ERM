<?php

namespace App\Services\Bcms\Emns;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\RecipientStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Contact;
use App\Models\User;
use App\Services\Bcms\AudienceResolver;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Support\Bcms\AudienceRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Composing, pricing, approving and releasing an alert.
 *
 * THE CONTROLS IN THIS FILE ARE THE ONES THAT STOP A CATASTROPHE. An EMNS is a
 * button that puts a sentence on twelve thousand phones at three in the
 * morning, and every safeguard here exists because of a specific way that goes
 * wrong: dual approval stops an accidental all-staff "ACTIVE FIRE"; simulation
 * mode stops a training exercise evacuating a building; the quiet-hours split
 * stops a routine reminder waking a branch while never delaying a real one.
 *
 * DISPATCH IS SEPARATED FROM APPROVAL BY A STATE, NOT BY A UI. A screen that
 * merely hides the button is a screen somebody can post past. `dispatch()`
 * refuses an alert that has not cleared its approval rule, and the refusal is
 * an exception rather than a silent downgrade to simulation — quietly turning
 * somebody's real alert into a test is worse than making them get a signature.
 *
 * THE RECIPIENT LIST IS MATERIALISED BEFORE ANYTHING IS SENT. `bcms_alert_
 * recipients` is written in one transaction at dispatch, so the roll-call
 * denominator is fixed at the moment of dispatch and cannot drift as the
 * contact roster changes underneath it. A headcount that moved while people
 * were being counted is not a headcount.
 */
class AlertService
{
    public function __construct(
        private AudienceResolver $audience,
        private ContactResolver $contacts,
        private ChannelRegistry $channels,
        private TemplateRenderer $renderer,
        private BcmsSettings $settings,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Composing */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function compose(array $attributes, ?int $userId = null): Alert
    {
        $template = isset($attributes['template_id'])
            ? AlertTemplate::query()->find($attributes['template_id'])
            : null;

        $severity = $this->severityFor($attributes, $template);

        return Alert::query()->create(array_merge([
            'status' => 'draft',
            'severity' => $severity->value,
            // An alert attached to an exercise DEFAULTS TO SIMULATION
            // (criterion 5). Not "can be set to" — an exercise that reached a
            // branch for real is the failure this default exists to prevent,
            // and turning it off is a deliberate act with its own permission.
            'is_simulation' => filled($attributes['occurrence_id'] ?? null),
            'response_required' => (bool) ($template?->is_life_safety),
            'ack_window_minutes' => 30,
            'escalation_enabled' => true,
            'currency' => $this->settings->for()->alert_currency ?? 'NGN',
            'iso_clause_ref' => 'ISO22301:8.4.3',
            'created_by' => $userId ?? auth()->id(),
            'initiated_by' => $userId ?? auth()->id(),
        ], $attributes, ['severity' => $severity->value]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function severityFor(array $attributes, ?AlertTemplate $template): AlertSeverity
    {
        $value = $attributes['severity']
            ?? ($template === null ? null : $template->severity)
            ?? AlertSeverity::Advisory->value;

        return $value instanceof AlertSeverity ? $value : AlertSeverity::from((string) $value);
    }

    /* ------------------------------------------------------------------ */
    /*  Estimating — criterion 9 */
    /* ------------------------------------------------------------------ */

    /**
     * Who this would reach, on what, and what it would cost — BEFORE dispatch.
     *
     * THE ESTIMATE IS BUILT THE SAME WAY THE DISPATCH IS. It resolves the real
     * audience, asks `ContactResolver` for the real channels each person can be
     * reached on (so consent and missing addresses are already applied), and
     * prices the real rendered body through the real adapter. Anything cheaper
     * — a headcount times a flat rate — is the kind of estimate that is 40%
     * wrong on a dispatch where half the audience has no mobile number, and
     * criterion 9 asks for 5%.
     *
     * @return array<string, mixed>
     */
    public function estimate(Alert $alert): array
    {
        $rule = $alert->audience_rule ? AudienceRule::fromArray($alert->audience_rule) : null;
        $contacts = $this->audience->resolve($rule);
        $channels = $this->channelKeys($alert);

        $costMinor = 0;
        $priced = 0;
        $unpriced = 0;
        $perChannel = [];
        $unreachable = 0;

        foreach ($contacts as $contact) {
            $usable = $this->contacts->channelsFor($contact, $channels, $alert->severity === AlertSeverity::LifeSafety);

            if ($usable === []) {
                $unreachable++;

                continue;
            }

            foreach ($usable as $channel) {
                $message = $this->renderer->render($alert, $channel, $contact->preferred_language ?: 'en');
                $adapter = $this->channels->for($channel);
                $cost = $adapter->estimateCostMinor($this->contacts->recipient($contact), $message);

                $perChannel[$channel->value]['messages'] = ($perChannel[$channel->value]['messages'] ?? 0) + 1;

                if ($cost === null) {
                    $unpriced++;
                    $perChannel[$channel->value]['unpriced'] = ($perChannel[$channel->value]['unpriced'] ?? 0) + 1;

                    continue;
                }

                $priced++;
                $costMinor += $cost;
                $perChannel[$channel->value]['cost_minor'] = ($perChannel[$channel->value]['cost_minor'] ?? 0) + $cost;
            }
        }

        $estimate = [
            'recipients' => $contacts->count(),
            // Named, because "we will reach 1,200 people" and "we will reach
            // 1,200 people except the 43 with no working number" are different
            // statements and only the second is true.
            'unreachable' => $unreachable,
            'reachable' => $contacts->count() - $unreachable,
            'channels' => $channels === [] ? [] : array_map(fn (ChannelKey $c) => $c->value, $channels),
            'per_channel' => $perChannel,
            'priced_messages' => $priced,
            'unpriced_messages' => $unpriced,
            // Null when nothing could be priced. Zero would tell a CFO the
            // dispatch was free (development standard §5).
            'estimated_cost_minor' => $priced === 0 ? null : $costMinor,
            'currency' => $alert->currency ?? 'NGN',
            'mocked_channels' => array_values(array_filter(
                array_map(fn (ChannelKey $c) => $this->channels->isMock($c) ? $c->value : null, $channels),
            )),
            'requires_dual_approval' => $this->requiresDualApproval($alert, $contacts->count()),
            'offline_capable' => $this->hasOfflineChannel($channels),
        ];

        $alert->forceFill([
            'recipient_count' => $estimate['recipients'],
            'estimated_cost_minor' => $estimate['estimated_cost_minor'],
        ])->save();

        return $estimate;
    }

    /* ------------------------------------------------------------------ */
    /*  Approving — criterion 4 */
    /* ------------------------------------------------------------------ */

    /**
     * Does this alert need a second pair of eyes?
     *
     * TWO THRESHOLDS, EITHER OF WHICH TRIPS IT: severity and blast radius. A
     * critical alert to nine people and an advisory to nine thousand are both
     * things somebody should confirm, for different reasons, and a rule that
     * only looked at severity would wave through the all-staff mistake this
     * control exists to prevent.
     *
     * A SIMULATION NEVER NEEDS APPROVAL. It reaches nobody outside the sandbox,
     * and requiring a second authoriser to run a training exercise is how
     * operators learn to route around the control.
     *
     * AN EXERCISE ESCALATED TO A LIVE DISPATCH ALWAYS NEEDS IT, unconditionally
     * — not "if it also trips the severity or headcount threshold". Standing
     * rule: exercise-linked traffic defaults to simulation, and the one way off
     * that default (`AlertController::escalateLive()`, gated on
     * `bcms.alert.life_safety`) must never be a single person's decision. The
     * only way an alert reaches this check with `occurrence_id` set and
     * `is_simulation` false is via that escalation — `compose()` always
     * defaults an exercise-linked alert to simulation.
     *
     * `$recipientCount`, WHEN GIVEN, MUST BE A FRESHLY RESOLVED COUNT, NOT THE
     * STORED COLUMN. `bcms_alerts.recipient_count` is only ever written by
     * `estimate()` — an optional, manually-triggered step — and by `release()`
     * itself. Falling back to the stored value here is correct for advisory
     * reads (a dashboard showing "would this need approval"); `release()` must
     * never take that fallback, because an alert nobody estimated reads as
     * zero recipients, and zero is "nobody counted", not "the audience is
     * small".
     */
    public function requiresDualApproval(Alert $alert, ?int $recipientCount = null): bool
    {
        if ($alert->is_simulation) {
            return false;
        }

        if ($alert->occurrence_id !== null) {
            return true;
        }

        if ($alert->template?->requires_dual_approval) {
            return true;
        }

        if (! $this->settings->for()->require_dual_approval_for_live) {
            return false;
        }

        $count = $recipientCount ?? (int) $alert->recipient_count;

        // The two thresholds are DEPLOYMENT-WIDE configuration, not tenant
        // settings, and `bcms_settings` has no column for them. That is a
        // deliberate reading rather than an omission: "how loud is too loud to
        // send unchecked" is a property of the product's duty of care, not a
        // number a customer should be able to raise to nine thousand on a
        // Friday afternoon. The tenant switch above is what a customer
        // controls. Making them per-tenant would need two columns and an ADR.
        $severityThreshold = (string) config('bcms.dual_approval.severity', AlertSeverity::Critical->value);
        $countThreshold = (int) config('bcms.dual_approval.recipients', 500);

        $order = array_column(AlertSeverity::cases(), 'value');
        $severityTrips = array_search($alert->severity->value, $order, true)
            >= array_search($severityThreshold, $order, true);

        return $severityTrips || $count >= $countThreshold;
    }

    /**
     * Turn an exercise-linked alert off simulation, so it reaches its real
     * audience for real.
     *
     * THE PERMISSION CHECK IS THE CALLER'S JOB (`bcms.alert.life_safety`,
     * enforced in `AlertController`), not this method's — the same split
     * `approve()` and `release()` already use. What this method owns is the
     * state change and the fact that `requiresDualApproval()` will now return
     * true unconditionally for it, so `release()` refuses the alert until two
     * different people have approved it, same as any other dual-approval
     * alert. There is deliberately no separate "un-escalate": going back to
     * simulation is a new draft, not an undo of a safety decision.
     *
     * ANY APPROVAL ALREADY ON THE RECORD WAS GIVEN FOR THE DRILL, NOT FOR A
     * LIVE DISPATCH, AND IS WITHDRAWN HERE. Two signatures collected while
     * `requiresDualApproval()` returned false for a simulation cannot be
     * allowed to satisfy the unconditional requirement this method is about to
     * switch on — `isDispatchable()` must not read stale approvals as consent
     * to something nobody was asked to consent to. `approved_by`,
     * `approved_at`, `second_approved_by` and `second_approved_at` are nulled
     * and `status` is reset to `draft` in the same state change, and the
     * withdrawal — what was cleared, and why — is recorded in the audit entry
     * rather than silently disappearing from the record.
     */
    public function escalateLive(Alert $alert, User $actor): Alert
    {
        if ($alert->occurrence_id === null) {
            throw new InvalidArgumentException(
                'Only an alert linked to an exercise can be escalated to a live dispatch.'
            );
        }

        if (! $alert->is_simulation) {
            throw new InvalidArgumentException('This alert already targets a live dispatch.');
        }

        if ($alert->status === 'dispatched' || $alert->status === 'dispatching') {
            throw new InvalidArgumentException('This alert has already been dispatched as a simulation.');
        }

        $withdrawn = [
            'status' => $alert->status,
            'approved_by' => $alert->approved_by,
            'approved_at' => $alert->approved_at?->toIso8601String(),
            'second_approved_by' => $alert->second_approved_by,
            'second_approved_at' => $alert->second_approved_at?->toIso8601String(),
        ];

        $hadApprovals = $alert->approved_by !== null || $alert->second_approved_by !== null;

        $alert->forceFill([
            'is_simulation' => false,
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
            'second_approved_by' => null,
            'second_approved_at' => null,
            'updated_by' => $actor->getKey(),
        ])->save();

        $alert->recordAudit('alert.exercise_escalated_live', [
            'escalated_by' => $actor->name,
            'severity' => $alert->severity->value,
            'occurrence_id' => $alert->occurrence_id,
            'approvals_withdrawn' => $hadApprovals,
            'withdrawn' => $withdrawn,
            'reason' => 'Signatures given for a simulation do not carry over to a live dispatch; '
                .'both authorisers must approve again.',
        ]);

        return $alert->refresh();
    }

    public function approve(Alert $alert, User $approver): Alert
    {
        if ($alert->status === 'dispatched') {
            throw new InvalidArgumentException('This alert has already been dispatched.');
        }

        // A SIMULATION CANNOT BE "APPROVED". `requiresDualApproval()` is false
        // for every simulation by design (a drill reaches nobody outside the
        // sandbox), so without this guard a simulation could collect two
        // signatures that mean nothing at the moment they are given, and only
        // become live approvals later if the alert is escalated — which is
        // exactly how a drill's paperwork turns into a live evacuation's
        // authorisation. `escalateLive()` withdraws any approval already on
        // the record for the same reason; this stops one from being recorded
        // in the first place.
        //
        // THE GUARD CHECKS `is_simulation` DIRECTLY, NOT `requiresDualApproval()`.
        // The full predicate also depends on `recipient_count`, which
        // `estimate()` — an optional, manually-triggered button — may never
        // have populated. Guarding on the full predicate would let a real,
        // high-volume alert nobody had estimated read as "does not require
        // approval" here while `release()` (which always resolves the real
        // audience before deciding) correctly demands that same approval a
        // moment later — an alert `approve()` says needs nothing and
        // `release()` refuses to send, told to the same operator in the same
        // incident. `approve()` and `release()` must never disagree about
        // whether an alert needs a second signature, so the guard here is
        // narrowed to the one condition that is true regardless of any count:
        // a simulation never needs it, full stop.
        if ($alert->is_simulation) {
            throw new InvalidArgumentException(
                'A simulation does not require approval and cannot be approved.'
            );
        }

        // THE SECOND AUTHORISER CANNOT BE THE FIRST. A dual-approval control
        // that one person can satisfy twice is not a control, and it is the
        // first thing an auditor tests.
        if ($alert->approved_by !== null && (int) $alert->approved_by === (int) $approver->getKey()) {
            throw new InvalidArgumentException(
                'The second authoriser must be a different person. '
                .'You have already approved this alert.'
            );
        }

        $field = $alert->approved_by === null ? 'approved' : 'second_approved';

        $alert->forceFill([
            $field.'_by' => $approver->getKey(),
            $field.'_at' => now(),
            'status' => 'approved',
        ])->save();

        $alert->recordAudit('alert.approved', [
            'approver' => $approver->name,
            'stage' => $field,
            'severity' => $alert->severity->value,
            'recipients' => (int) $alert->recipient_count,
        ]);

        return $alert->refresh();
    }

    /**
     * Is this alert cleared to go out?
     *
     * `$recipientCount`, WHEN GIVEN, MUST BE A FRESHLY RESOLVED COUNT. See
     * `requiresDualApproval()` — this method only forwards it. Callers showing
     * an advisory state (the dashboard) may omit it and take the stored
     * column; `release()` must never omit it.
     */
    public function isDispatchable(Alert $alert, ?int $recipientCount = null): bool
    {
        if ($alert->status === 'dispatched') {
            return false;
        }

        if (! $this->requiresDualApproval($alert, $recipientCount)) {
            return true;
        }

        return $alert->approved_by !== null && $alert->second_approved_by !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Releasing */
    /* ------------------------------------------------------------------ */

    /**
     * Materialise the recipient list and mark the alert released.
     *
     * The actual sending is a queued fan-out (`DispatchAlertJob`), because
     * criterion 2 requires ten thousand recipients queued in under thirty
     * seconds and no HTTP request should be holding that.
     *
     * @return Collection<int, Contact>
     */
    public function release(Alert $alert, ?int $userId = null): Collection
    {
        // THE AUDIENCE IS RESOLVED BEFORE THE DUAL-APPROVAL CHECK, NOT AFTER.
        // `recipient_count` is only ever written by `estimate()` — an optional,
        // manually-triggered button — and by this method itself, so reading
        // the stored column here would let an alert nobody estimated dispatch
        // as if it had zero recipients. A rate, or a count, over "nobody
        // looked" is undefined, not zero, and the blast-radius threshold this
        // gate exists to enforce must be checked against the real audience,
        // not against whether an operator happened to click a button first.
        $rule = $alert->audience_rule ? AudienceRule::fromArray($alert->audience_rule) : null;
        $contacts = $this->audience->resolve($rule);

        if (! $this->isDispatchable($alert, $contacts->count())) {
            throw new InvalidArgumentException(
                'This alert needs a second authoriser before it can be dispatched. '
                .'The attempt has been recorded.'
            );
        }

        if ($contacts->isEmpty()) {
            throw new InvalidArgumentException(
                'This audience resolves to nobody. Check the rule before dispatching — an alert that '
                .'reaches no one still looks dispatched on the dashboard.'
            );
        }

        DB::transaction(function () use ($alert, $contacts, $userId) {
            $channels = $this->channelKeys($alert);
            $isLifeSafety = $alert->severity === AlertSeverity::LifeSafety;
            $now = now();
            $rows = [];

            foreach ($contacts as $contact) {
                $usable = $this->contacts->channelsFor($contact, $channels, $isLifeSafety);

                $rows[] = [
                    'organization_id' => $alert->organization_id,
                    'alert_id' => $alert->getKey(),
                    'contact_id' => $contact->getKey(),
                    'contact_name_snapshot' => $contact->full_name,
                    'resolved_channels' => json_encode(array_map(fn (ChannelKey $c) => $c->value, $usable)),
                    // A person with no usable channel is FAILED at the moment
                    // of dispatch rather than left queued for ever. The
                    // roll-call has to be able to say "we could not reach these
                    // eleven people at all", which is a different problem from
                    // "these eleven have not answered yet".
                    'status' => $usable === []
                        ? RecipientStatus::Failed->value
                        : RecipientStatus::Queued->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                AlertRecipient::query()->insert($chunk);
            }

            $alert->forceFill([
                'status' => 'dispatching',
                'dispatched_at' => $now,
                'recipient_count' => $contacts->count(),
                'updated_by' => $userId ?? auth()->id(),
            ])->save();
        });

        $alert->recordAudit('alert.dispatched', [
            'recipients' => $contacts->count(),
            'channels' => $alert->channels,
            'severity' => $alert->severity->value,
            'is_simulation' => (bool) $alert->is_simulation,
        ]);

        return $contacts;
    }

    /**
     * May this alert go out right now, or do quiet hours hold it?
     *
     * CRITERION 8, AND THE RULE IS ENFORCED HERE RATHER THAN IN THE UI. Life
     * safety and critical traffic never wait; informational and advisory do.
     * `AlertSeverity::respectsQuietHours()` is the single source of that, so a
     * new severity cannot be added without answering the question.
     */
    public function isHeldByQuietHours(Alert $alert): bool
    {
        // Phase 0 already put this rule in one place and gave it the only
        // signature that cannot be got wrong: it takes the severity, so there
        // is no path that checks the clock without checking whether this
        // traffic is allowed to wait.
        return $this->settings->mayDefer($alert->severity, now(), (int) $alert->organization_id);
    }

    /**
     * @return list<ChannelKey>
     */
    public function channelKeys(Alert $alert): array
    {
        $channels = array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? ChannelKey::tryFrom($c) : null,
            (array) ($alert->channels ?? []),
        )));

        if ($channels !== []) {
            return $channels;
        }

        $organizationId = (int) $alert->organization_id;

        return $alert->severity === AlertSeverity::LifeSafety
            ? $this->settings->lifeSafetyChannels($organizationId)
            : $this->settings->defaultChannels($organizationId);
    }

    /**
     * @param  list<ChannelKey>  $channels
     */
    private function hasOfflineChannel(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel->isOfflineCapable()) {
                return true;
            }
        }

        return false;
    }
}
