<?php

namespace App\Services\Tprm\Monitoring;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\SignalSeverity;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Alert;
use App\Models\Tprm\AlertRule;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\MonitoringSignal;
use App\Services\NotificationService;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Scoring\EngagementContext;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Support\Collection;

/**
 * Turning signals into action — FR-MON-04.
 *
 * THE COOLDOWN IS PER RULE PER SUBJECT, and that is what keeps the stream
 * credible. A rule watching forty vendors must still fire for the thirty-ninth
 * while sitting quiet on the first; a global cooldown would silence the whole
 * rule because one vendor tripped it this morning.
 *
 * EVERY ACTION RECORDS WHAT ACTUALLY HAPPENED. A rule may ask for a targeted
 * assessment on an engagement with no published template, or a suspension on
 * one already terminated. The alert stores the outcome per action, so a
 * console can say "notified, finding raised, assessment not built — no
 * template covers the implicated controls" rather than showing a green tick
 * over a half-completed response.
 *
 * ONE FAILING ACTION DOES NOT ABANDON THE REST. Each runs in its own
 * try/catch: a notification failing must not stop the suspension that was the
 * point of the rule.
 */
class AlertEngine
{
    public function __construct(
        private readonly EngagementContext $context,
        private readonly RuleEvaluator $rules,
        private readonly FindingService $findings,
        private readonly TargetedAssessmentBuilder $targeted,
    ) {}

    /**
     * Evaluate every enabled rule against the unprocessed signals.
     *
     * @return Collection<int, Alert>
     */
    public function process(?int $organizationId = null, int $limit = 500): Collection
    {
        $signals = MonitoringSignal::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->unprocessed()
            ->orderBy('observed_at')
            ->limit($limit)
            ->get();

        $alerts = collect();

        foreach ($signals as $signal) {
            try {
                $alerts = $alerts->merge($this->processSignal($signal));
            } catch (\Throwable $exception) {
                logger()->error('TPRM alert processing failed for a signal', [
                    'signal_id' => $signal->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }

            // Marked processed whatever happened, including on failure. A
            // signal that threw once will throw again on every sweep, and a
            // queue that retries it forever is a queue that stops moving.
            $signal->forceFill(['is_processed' => true])->save();
        }

        return $alerts;
    }

    /**
     * @return Collection<int, Alert>
     */
    public function processSignal(MonitoringSignal $signal): Collection
    {
        $engagement = $signal->engagement_id === null
            ? null
            : Engagement::query()->withoutGlobalScopes()->find($signal->engagement_id);

        $facts = $this->factsFor($signal, $engagement);
        $alerts = collect();

        $rules = AlertRule::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $signal->organization_id)
            ->enabled()
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->watches($signal->signal_type->value)) {
                continue;
            }

            if (! $rule->inScope($facts, $this->rules) || ! $rule->matches($facts, $this->rules)) {
                continue;
            }

            if ($this->inCooldown($rule, $signal)) {
                continue;
            }

            $alerts->push($this->fire($rule, $signal, $engagement));
        }

        return $alerts;
    }

    /**
     * Fire one rule against one signal.
     */
    public function fire(AlertRule $rule, MonitoringSignal $signal, ?Engagement $engagement): Alert
    {
        $alert = Alert::create([
            'organization_id' => $signal->organization_id,
            'rule_id' => $rule->getKey(),
            'signal_id' => $signal->getKey(),
            'third_party_id' => $signal->third_party_id,
            'engagement_id' => $signal->engagement_id,
            'severity' => $rule->severity->value,
        ]);

        $taken = [];

        foreach ($rule->actionList() as $action) {
            try {
                $taken[] = $this->act($action, $alert, $rule, $signal, $engagement);
            } catch (\Throwable $exception) {
                $taken[] = [
                    'action' => $action,
                    'done' => false,
                    // The failure is recorded on the alert, not only in a log:
                    // a console showing "suspension failed" is actionable and
                    // a silent partial response is not.
                    'note' => 'Failed: '.$exception->getMessage(),
                ];

                logger()->error('TPRM alert action failed', [
                    'alert_id' => $alert->getKey(),
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $alert->forceFill(['actions_taken' => $taken])->save();

        return $alert->refresh();
    }

    /**
     * @return array{action: string, done: bool, note: string|null}
     */
    private function act(string $action, Alert $alert, AlertRule $rule, MonitoringSignal $signal, ?Engagement $engagement): array
    {
        return match ($action) {
            AlertRule::ACTION_NOTIFY => $this->notify($alert, $rule, $signal, $engagement),
            AlertRule::ACTION_CREATE_FINDING => $this->createFinding($alert, $rule, $signal, $engagement),
            AlertRule::ACTION_TARGETED_ASSESSMENT => $this->buildTargetedAssessment($alert, $signal, $engagement),
            AlertRule::ACTION_SUSPEND_ENGAGEMENT => $this->suspend($alert, $engagement),
            AlertRule::ACTION_ADJUST_RESIDUAL => $this->adjustResidual($engagement),
            AlertRule::ACTION_ESCALATE, AlertRule::ACTION_CREATE_TASK => $this->notify($alert, $rule, $signal, $engagement),
            default => ['action' => $action, 'done' => false, 'note' => 'Unknown action.'],
        };
    }

    /**
     * @return array{action: string, done: bool, note: string|null}
     */
    private function createFinding(Alert $alert, AlertRule $rule, MonitoringSignal $signal, ?Engagement $engagement): array
    {
        if ($engagement === null) {
            return [
                'action' => AlertRule::ACTION_CREATE_FINDING,
                'done' => false,
                // Named rather than silently skipped: a signal about a vendor
                // rather than a service has nothing to hang a finding on, and
                // a reader has to know that is why none appeared.
                'note' => 'This signal is not attached to an engagement, so there is nothing to raise a '
                    .'finding against.',
            ];
        }

        $finding = $this->findings->raise(
            $engagement,
            'monitoring',
            $this->severityFor($rule->severity),
            $signal->title,
            [
                'source_id' => $signal->getKey(),
                'description' => sprintf(
                    "Raised automatically by the monitoring rule \"%s\" from a %s signal observed on %s.\n\n%s",
                    $rule->name,
                    $signal->signal_type->label(),
                    $signal->observed_at?->toDateString() ?? 'an unrecorded date',
                    json_encode($signal->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ),
            ],
        );

        $alert->forceFill(['created_finding_id' => $finding->getKey()])->save();

        return [
            'action' => AlertRule::ACTION_CREATE_FINDING,
            'done' => true,
            'note' => 'Raised '.$finding->reference.'.',
        ];
    }

    /**
     * FR-MON-06 — a mini-assessment covering only the implicated controls.
     *
     * @return array{action: string, done: bool, note: string|null}
     */
    private function buildTargetedAssessment(Alert $alert, MonitoringSignal $signal, ?Engagement $engagement): array
    {
        if ($engagement === null) {
            return [
                'action' => AlertRule::ACTION_TARGETED_ASSESSMENT,
                'done' => false,
                'note' => 'This signal is not attached to an engagement.',
            ];
        }

        $result = $this->targeted->build($engagement, $signal);

        if ($result['assessment'] === null) {
            return [
                'action' => AlertRule::ACTION_TARGETED_ASSESSMENT,
                'done' => false,
                'note' => $result['reason'],
            ];
        }

        $alert->forceFill(['created_assessment_id' => $result['assessment']->getKey()])->save();

        return [
            'action' => AlertRule::ACTION_TARGETED_ASSESSMENT,
            'done' => true,
            'note' => sprintf(
                '%d question(s) covering the implicated controls, out of %d in the template.',
                $result['question_count'],
                $result['template_question_count'],
            ),
        ];
    }

    /**
     * @return array{action: string, done: bool, note: string|null}
     */
    private function suspend(Alert $alert, ?Engagement $engagement): array
    {
        if ($engagement === null) {
            return ['action' => AlertRule::ACTION_SUSPEND_ENGAGEMENT, 'done' => false, 'note' => 'No engagement.'];
        }

        if (in_array($engagement->status->value, ['terminated', 'archived'], true)) {
            return [
                'action' => AlertRule::ACTION_SUSPEND_ENGAGEMENT,
                'done' => false,
                'note' => 'Already '.$engagement->status->label().'.',
            ];
        }

        $engagement->forceFill(['status' => EngagementStatus::MonitoringException->value])->save();

        $engagement->writeAuditRow('suspended_by_alert_rule', null, [
            'alert_id' => $alert->getKey(),
            'note' => 'Suspended by a monitoring rule. Resuming is a deliberate act.',
        ]);

        EngagementScoreInvalidated::dispatch($engagement, EngagementScoreInvalidated::MONITORING_SIGNAL);

        return ['action' => AlertRule::ACTION_SUSPEND_ENGAGEMENT, 'done' => true, 'note' => 'Suspended.'];
    }

    /**
     * @return array{action: string, done: bool, note: string|null}
     */
    private function adjustResidual(?Engagement $engagement): array
    {
        if ($engagement === null) {
            return ['action' => AlertRule::ACTION_ADJUST_RESIDUAL, 'done' => false, 'note' => 'No engagement.'];
        }

        // Through the event, so the recomputation goes down the same path as
        // every other trigger. The signal is already stored, so the recomputed
        // score picks it up without this action doing arithmetic of its own —
        // an alert rule that computed a score would be a second scoring engine.
        EngagementScoreInvalidated::dispatch($engagement, EngagementScoreInvalidated::MONITORING_SIGNAL);

        return ['action' => AlertRule::ACTION_ADJUST_RESIDUAL, 'done' => true, 'note' => 'Recomputed.'];
    }

    /**
     * @return array{action: string, done: bool, note: string|null}
     */
    private function notify(Alert $alert, AlertRule $rule, MonitoringSignal $signal, ?Engagement $engagement): array
    {
        $userId = $engagement?->relationship_owner_id;

        if ($userId === null) {
            return [
                'action' => AlertRule::ACTION_NOTIFY,
                'done' => false,
                'note' => 'No relationship owner to notify.',
            ];
        }

        NotificationService::send(
            organizationId: (int) $signal->organization_id,
            userId: $userId,
            type: 'tprm.monitoring.alert',
            subject: $rule->name.': '.$signal->title,
            body: sprintf(
                'A %s signal was observed on %s%s. %s',
                $signal->signal_type->label(),
                $signal->observed_at?->toDateString() ?? 'an unrecorded date',
                $engagement !== null ? ' against '.$engagement->reference : '',
                $alert->created_finding_id !== null ? 'A finding has been raised.' : '',
            ),
            metadata: [
                'alert_id' => $alert->getKey(),
                'signal_id' => $signal->getKey(),
                'engagement_id' => $engagement?->getKey(),
            ],
            actionUrl: route('tprm.monitoring.index', absolute: false),
            priority: $rule->severity === SignalSeverity::Critical ? 'high' : 'medium',
            category: 'workflow',
        );

        return ['action' => AlertRule::ACTION_NOTIFY, 'done' => true, 'note' => 'Notified.'];
    }

    /**
     * Whether this rule has already fired for this subject recently.
     */
    private function inCooldown(AlertRule $rule, MonitoringSignal $signal): bool
    {
        if ($rule->cooldown_hours <= 0) {
            return false;
        }

        return Alert::query()
            ->withoutGlobalScopes()
            ->where('rule_id', $rule->getKey())
            // Per SUBJECT. A rule watching forty vendors must still fire for
            // the thirty-ninth while quiet on the first.
            ->where(fn ($query) => $query
                ->where('third_party_id', $signal->third_party_id)
                ->orWhere('engagement_id', $signal->engagement_id))
            ->where('created_at', '>=', now()->subHours($rule->cooldown_hours))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function factsFor(MonitoringSignal $signal, ?Engagement $engagement): array
    {
        $facts = [
            'signal.type' => $signal->signal_type->value,
            'signal.severity' => $signal->severity->value,
            'signal.age_days' => $signal->observed_at === null
                ? 0
                : (int) $signal->observed_at->diffInDays(now()),
        ];

        if ($engagement !== null) {
            $facts = array_merge($this->context->build($engagement), $facts);
        }

        return $facts;
    }

    private function severityFor(SignalSeverity $severity): FindingSeverity
    {
        return match ($severity) {
            SignalSeverity::Critical => FindingSeverity::Critical,
            SignalSeverity::High => FindingSeverity::High,
            SignalSeverity::Medium => FindingSeverity::Medium,
            default => FindingSeverity::Low,
        };
    }
}
