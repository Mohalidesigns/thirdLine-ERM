<?php

namespace App\Services\Bcms\Incidents;

use App\Enums\Bcms\ActivationLevel;
use App\Enums\Bcms\IncidentLogEntryType;
use App\Enums\Bcms\IncidentSeverity;
use App\Enums\Bcms\IncidentStatus;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentLogEntry;
use App\Models\Bcms\IncidentTask;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanActivation;
use App\Models\User;
use App\Services\Bcms\Plans\PlanActivationService;
use App\Services\ReferenceCodeService;
use App\Support\Bcms\IncidentClock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Declaration through stand-down — `bcms_incidents`, `bcms_incident_log`,
 * `bcms_incident_tasks` (clause map §1, §1.3, PHASE-10 prompt scope).
 *
 * SEVERITY MAY ONLY MOVE WITH A REASON, at declaration and thereafter (clause
 * map §1.3's closing rule) — "a severity that drifts silently is how a sev1
 * becomes a sev3 by the time the board sees it." `declare()` and `regrade()`
 * both refuse a change with no `reason`, and both write it to the decision
 * log rather than only to a column, because the reason is itself evidence.
 *
 * DECLARING IS NEVER BLOCKED BY AN INCOMPLETE RECORD (`incident-declaration.md`
 * §1) — this screen's whole job is to get a real event captured in under two
 * minutes. What can be under-specified stays under-specified; what cannot
 * (title, a severity, `detected_at <= declared_at`) is validated by the form
 * request, not this service.
 *
 * STAND-DOWN IS A GATE, NOT A STATUS FLIP (clause map §1.1's stand-down row,
 * `incident-stand-down.md`). `standDown()` refuses unless `standDownChecklist()`
 * reports every blocking condition met, and the all-clear log entry IS the act
 * that satisfies the one condition ("a decision recording the stand-down")
 * that only this very call can satisfy.
 */
class IncidentService
{
    public function __construct(private PlanActivationService $planActivations) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function declare(array $data, User $by): Incident
    {
        // Gate 1 re-gate defect 1: `Carbon::parse()` alone keeps whatever
        // offset the request carried, and Eloquent's `datetime` cast then
        // writes that instance's LOCAL wall-clock figure into a column with
        // no offset of its own — `10:00+05:00` is stored as `10:00`, not the
        // true instant `05:00` UTC. Normalise to UTC once, here, before any
        // comparison or write.
        $detectedAt = IncidentClock::utc($data['detected_at'] ?? now()) ?? now();
        $declaredAt = now();

        if ($detectedAt->gt($declaredAt)) {
            throw new InvalidArgumentException('An incident cannot be detected after it was declared.');
        }

        $severity = IncidentSeverity::from($data['severity']);
        $activationLevel = isset($data['activation_level'])
            ? ActivationLevel::from($data['activation_level'])
            : $severity->defaultActivationLevel();

        return DB::transaction(function () use ($data, $by, $detectedAt, $declaredAt, $severity, $activationLevel) {
            $incident = Incident::query()->create([
                'organization_id' => $by->organization_id,
                'reference' => ReferenceCodeService::generate('bcms_incidents', 'reference', 'INC'),
                'title' => $data['title'],
                'incident_type' => $data['incident_type'] ?? null,
                'severity' => $severity->value,
                'business_unit_id' => $data['business_unit_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'detected_at' => $detectedAt,
                'declared_by' => $by->getKey(),
                'declared_at' => $declaredAt,
                'status' => IncidentStatus::Open->value,
                'activation_level' => $activationLevel->value,
                'impacted_processes' => $data['impacted_processes'] ?? [],
                'estimated_impact_minor' => $data['estimated_impact_minor'] ?? null,
                'currency' => $data['currency'] ?? null,
                'is_exercise' => false,
                'created_by' => $by->getKey(),
            ]);

            // The first decision entry: which criterion justified declaring,
            // and — if the severity was overridden from the suggested band —
            // why (declaration screen §2, clause map §1.1: "which criterion
            // was met is written to the log as the first decision entry").
            // A `decision` entry always needs options/rationale (clause map
            // §1.2), so this always supplies both, falling back to a stock
            // phrase when the officer gave no override reason — a real
            // declaration made in under two minutes, per the declaration
            // screen's own purpose, must never be blocked on typing a
            // sentence nobody asked for at the one moment speed matters.
            $this->log($incident, $by, [
                'entry_type' => IncidentLogEntryType::Decision->value,
                'content' => $data['declaration_reason'] ?? sprintf(
                    'Incident declared at %s severity, activation level %s.',
                    $severity->label(),
                    $activationLevel->label(),
                ),
                'options_considered' => $data['options_considered']
                    ?? 'Severity assessed against the tenant\'s default matrix at the moment of declaration.',
                'rationale' => $data['severity_override_reason']
                    ?? $data['declaration_reason']
                    ?? 'Declared per the suggested severity band; no override recorded.',
            ]);

            if (! empty($data['activate_plan_id'])) {
                $plan = Plan::query()->findOrFail($data['activate_plan_id']);

                $this->planActivations->activate(
                    $plan,
                    $by,
                    $data['activation_reason'] ?? 'Activated on incident declaration.',
                    false,
                    $incident->getKey(),
                );
            }

            return $incident->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function log(Incident $incident, User $by, array $data): IncidentLogEntry
    {
        $type = IncidentLogEntryType::from($data['entry_type']);

        if ($type->requiresOptionsAndRationale()
            && (blank($data['options_considered'] ?? null) || blank($data['rationale'] ?? null))) {
            throw new InvalidArgumentException(
                'A decision entry must record the options considered and the rationale at the time — '
                .'that is the artefact a post-incident inquiry reads first.'
            );
        }

        $content = trim((string) $data['content']);

        if ($type->requiresOptionsAndRationale()) {
            $content = trim(sprintf(
                "%s\n\nOptions considered: %s\nRationale: %s",
                $content,
                $data['options_considered'],
                $data['rationale'],
            ));
        }

        return IncidentLogEntry::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->getKey(),
            'logged_at' => now(),
            'logged_by' => $by->getKey(),
            'entry_type' => $type->value,
            'content' => $content,
            'attachments' => $data['attachments'] ?? null,
            'supersedes_entry_id' => $data['supersedes_entry_id'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function addTask(Incident $incident, array $data): IncidentTask
    {
        $this->assertNotTerminal($incident);

        return IncidentTask::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->getKey(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            // A6 (code review #3 advisory): the same non-UTC-offset defect
            // defect 1 closed for `detected_at`/`awareness_at` — a due date
            // picker on a browser in a non-UTC zone reaches this unconverted
            // otherwise.
            'due_at' => IncidentClock::utc($data['due_at'] ?? null),
            'priority' => $data['priority'] ?? null,
            'status' => 'open',
        ]);
    }

    /**
     * A6 (code review #3 advisory): a closed/cancelled incident does not
     * gain a completed task any more than it gains a new one
     * (`assertNotTerminal()`), and a task already `cancelled` cannot be
     * flipped to `complete` — the two are mutually exclusive final states,
     * and completing a task someone deliberately cancelled misrepresents
     * what happened during the response.
     */
    public function completeTask(IncidentTask $task): IncidentTask
    {
        if ($task->incident !== null) {
            $this->assertNotTerminal($task->incident);
        }

        if ($task->status === 'cancelled') {
            throw new InvalidArgumentException('This task was cancelled and cannot be marked complete.');
        }

        $task->update(['status' => 'complete', 'completed_at' => now()]);

        return $task->refresh();
    }

    /**
     * Severity may only move with a stated reason — clause map §1.3, applied
     * for the incident's whole life, not only at declaration.
     */
    public function regrade(Incident $incident, User $by, string $severity, string $reason, ?string $activationLevel = null): Incident
    {
        $this->assertNotTerminal($incident);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A severity change must give a reason.');
        }

        $newSeverity = IncidentSeverity::from($severity);
        $newActivation = $activationLevel !== null
            ? ActivationLevel::from($activationLevel)
            : $newSeverity->defaultActivationLevel();

        $from = $incident->severity;

        return DB::transaction(function () use ($incident, $by, $newSeverity, $newActivation, $reason, $from) {
            $incident->update([
                'severity' => $newSeverity->value,
                'activation_level' => $newActivation->value,
                'updated_by' => $by->getKey(),
            ]);

            $this->log($incident, $by, [
                'entry_type' => IncidentLogEntryType::Decision->value,
                'content' => sprintf(
                    'Severity re-graded from %s to %s.',
                    $from?->label() ?? 'unset',
                    $newSeverity->label(),
                ),
                'options_considered' => 'Re-assessed against the severity matrix and the information now available.',
                'rationale' => $reason,
            ]);

            return $incident->fresh();
        });
    }

    /**
     * A closed/cancelled incident does not gain new manual log entries,
     * tasks, a severity change, or a reportability answer (Gate 1 re-gate
     * defect 3, `crisis-room.md` §3, `incident-stand-down.md` "Already
     * closed"). Public so the controller can guard `classify()` — a
     * `NotificationService` action — the same way, without this incident
     * having to know that class exists.
     *
     * Deliberately NOT applied inside `log()` itself: `NotificationService::
     * classify()`/`reassessNotReportable()`/`withdraw()` also call `log()`,
     * and a regulatory notification can legitimately need a late correction
     * after the incident is operationally closed — this guards the
     * incident-commander actions named above, and `IncidentController::
     * storeLog()` applies it explicitly before every MANUAL entry a person
     * submits (Gate 1 re-gate defect 3), rather than this method inferring
     * "manual" from entry type. `Phase10ScreensTest`'s PIR fixtures rely on
     * this: they back-fill a decision-log entry on an already-closed
     * incident as test setup, through this method directly.
     */
    public function assertNotTerminal(Incident $incident): void
    {
        if ($incident->status?->isTerminal() === true) {
            throw new InvalidArgumentException(
                "This incident is already {$incident->status->label()} and cannot be changed further."
            );
        }
    }

    /**
     * The stand-down closure gate (`incident-stand-down.md` §2) — computed
     * live, the same method used to render the checklist and to refuse the
     * submit, so the screen can never show "ready" when the server would
     * refuse.
     *
     * `blocks_submit` is what a caller should gate the submit button on —
     * NOT `met`, which the screen still shows for information. It is
     * `false` for `plans` and `all_clear`, and `true` for everything else:
     *
     * - `plans` — `standDown()` settles every plan disposition (kept-active
     *   statements, then GAP 4's deactivate-the-rest) BEFORE it evaluates
     *   this checklist, so an unmet `plans` row on the GET render is not a
     *   reason to refuse the submit that is about to resolve it. Blocking
     *   submit on it made Gap 4 unreachable from the screen: the only way
     *   through was typing a statement into every box, which is the false
     *   record Amendment 2's shape warns against.
     * - `all_clear` — `met` is always `false` here by construction (see
     *   below); it is satisfied only by the very submission being gated, so
     *   treating it as blocking would make stand-down permanently
     *   unreachable.
     * - `tasks`, `notifications`, `reportability` — none of these is
     *   settled by the stand-down submission itself, so an unmet row here
     *   is a genuine refusal, on the GET render and inside `standDown()`
     *   alike.
     *
     * @return list<array{key: string, label: string, met: bool, message: string, blocks_submit: bool}>
     */
    public function standDownChecklist(Incident $incident): array
    {
        $notifications = app(NotificationService::class);
        $out = [];

        $openTasks = $incident->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count();
        $out[] = [
            'key' => 'tasks', 'label' => 'Every task is complete or cancelled',
            'met' => $openTasks === 0,
            'message' => $openTasks === 0 ? '' : "{$openTasks} task(s) still open.",
            'blocks_submit' => true,
        ];

        $openObligations = $notifications->overdueOrOpenCount($incident);
        $out[] = [
            'key' => 'notifications', 'label' => 'Every regulatory obligation is submitted or reassessed as not owed',
            'met' => $openObligations === 0,
            'message' => $openObligations === 0 ? '' : "{$openObligations} regulatory obligation(s) still open.",
            'blocks_submit' => true,
        ];

        $out[] = [
            'key' => 'reportability', 'label' => 'Neither reportability question is still unknown',
            'met' => $notifications->reportabilityStatus($incident, 'cbn') !== 'unknown'
                && $notifications->reportabilityStatus($incident, 'personal_data') !== 'unknown',
            'message' => 'One or both reportability questions have not been answered.',
            'blocks_submit' => true,
        ];

        $out[] = [
            'key' => 'all_clear', 'label' => 'A decision recording the stand-down and the all-clear',
            'met' => false, // Satisfied only by this very submission — see class docblock.
            'message' => 'Satisfied by submitting this form below.',
            'blocks_submit' => false,
        ];

        // ADR 0020 Amendment 4: a kept-active activation (`kept_active_entry_id`
        // set) is dispositioned, not blocking — `deactivated_at` still null is
        // correct, because the plan genuinely has not stopped.
        $openPlans = PlanActivation::query()
            ->where('incident_id', $incident->getKey())
            ->whereNull('deactivated_at')
            ->whereNull('kept_active_entry_id')
            ->count();
        $out[] = [
            'key' => 'plans', 'label' => 'Every activated plan is deactivated or explicitly kept active',
            'met' => $openPlans === 0,
            'message' => $openPlans === 0 ? '' : "{$openPlans} activation(s) need a disposition below.",
            // Settled by standDown() itself before this checklist is
            // evaluated for the submit gate — see docblock above.
            'blocks_submit' => false,
        ];

        return $out;
    }

    /**
     * ADR 0020 Amendment 4, rule 4 (ordering): inside ONE transaction, this
     * locks the incident row, writes every "kept active" disposition, THEN
     * evaluates `standDownChecklist()` and throws if any condition is unmet.
     * The throw rolls the dispositions back — a refused stand-down leaves no
     * marks, rather than leaving a plan half-dispositioned for the next
     * attempt to find.
     *
     * @param  array<string, mixed>  $data
     */
    public function standDown(Incident $incident, User $by, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $by, $data) {
            /** @var Incident $incident */
            $incident = Incident::query()->whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            // Gate 1 re-gate defect 3: without this, a second stand-down
            // request re-ran the whole gate against a record every
            // condition ALREADY satisfies (its own obligations, tasks and
            // plans are already dispositioned from the first stand-down),
            // so it silently passed again — a second all-clear log entry and
            // a rewritten `closed_at` on an incident that was already
            // closed. The lock above makes this check race-free against a
            // second concurrent submission too.
            $this->assertNotTerminal($incident);

            // ADR 0020 Amendment 4 rule 2, UNCHANGED: a KEY PRESENT in
            // `plans_remaining_active` with a blank/whitespace value is
            // REFUSED, naming the plan — `keepPlanActiveAtStandDown()`'s own
            // guard, called for every key present regardless of its value.
            // Typing into the box and then clearing it is not the same act
            // as never touching the box at all (below).
            foreach ((array) ($data['plans_remaining_active'] ?? []) as $activationId => $statement) {
                $this->keepPlanActiveAtStandDown($incident, $by, (int) $activationId, (string) $statement);
            }

            // GAP 4: the screen's own copy says "leave blank to deactivate" —
            // before this, an activation nobody mentioned at all (no key in
            // `plans_remaining_active`) simply stayed open forever: the
            // "plans" checklist condition kept refusing stand-down with no
            // way through short of typing a statement nobody meant. Every
            // activation still open once the loop above has run — i.e. never
            // named, since a named-but-blank one already threw — is
            // deactivated as part of this same stand-down.
            // A6: locked for the rest of this transaction, the same reason
            // the incident row above is — a second stand-down (or a plan
            // deactivated/kept-active through another route) racing this one
            // must not read a set of "still open" activations that this
            // transaction is about to act on.
            $stillOpen = PlanActivation::query()
                ->where('incident_id', $incident->getKey())
                ->whereNull('deactivated_at')
                ->whereNull('kept_active_entry_id')
                ->with('plan:id,title')
                ->lockForUpdate()
                ->get();

            if ($stillOpen->isNotEmpty() && $by->can('bcms.plan.activate') !== true) {
                $names = $stillOpen->map(fn (PlanActivation $a) => $a->plan->title ?? "activation {$a->getKey()}")
                    ->implode('", "');

                throw new InvalidArgumentException(
                    "Standing down this incident would deactivate \"{$names}\" — deactivating a plan needs the "
                    .'plan.activate permission, which you do not hold. Ask someone who holds it, or record a '
                    .'statement above to keep the plan(s) active instead.'
                );
            }

            foreach ($stillOpen as $activation) {
                $this->deactivatePlanAtStandDown($incident, $activation, $by);
            }

            $unmet = array_values(array_filter($this->standDownChecklist($incident), fn (array $c) => ! $c['met'] && $c['blocks_submit']));

            if ($unmet !== []) {
                throw new InvalidArgumentException(
                    'This incident cannot be stood down yet: '.implode(' ', array_column($unmet, 'message'))
                );
            }

            $this->log($incident, $by, [
                'entry_type' => IncidentLogEntryType::Escalation->value,
                'content' => trim(($data['reason'] ?? '')."\n\nAll-clear: ".($data['all_clear_message'] ?? '')),
                'options_considered' => null,
                'rationale' => null,
            ]);

            $incident->update([
                'status' => IncidentStatus::Closed->value,
                'closed_at' => now(),
                'updated_by' => $by->getKey(),
            ]);

            return $incident->fresh();
        });
    }

    /**
     * Record one activation as explicitly kept active past this incident's
     * stand-down (ADR 0020 Amendment 4, rule 2). `activation_reason` is NEVER
     * written here — it says why the plan started, and stand-down does not
     * change that; the "why it stays active" reason lives only on the
     * decision-log entry `kept_active_entry_id` points to.
     */
    private function keepPlanActiveAtStandDown(Incident $incident, User $by, int $activationId, string $statement): void
    {
        // A6: locked for the rest of this transaction — see the note on
        // `$stillOpen` in `standDown()`.
        $activation = PlanActivation::query()->where('id', $activationId)
            ->where('incident_id', $incident->getKey())
            ->lockForUpdate()
            ->first();

        if ($activation === null) {
            throw new InvalidArgumentException("Activation {$activationId} does not belong to this incident.");
        }

        $planTitle = $activation->plan->title ?? "activation {$activationId}";

        if ($activation->deactivated_at !== null) {
            throw new InvalidArgumentException("\"{$planTitle}\" has already been deactivated and cannot also be kept active.");
        }

        if ($activation->kept_active_entry_id !== null) {
            throw new InvalidArgumentException("\"{$planTitle}\" is already recorded as kept active.");
        }

        if (trim($statement) === '') {
            throw new InvalidArgumentException("A statement is required to keep \"{$planTitle}\" active past stand-down.");
        }

        $entry = $this->log($incident, $by, [
            'entry_type' => IncidentLogEntryType::Decision->value,
            'content' => sprintf(
                'Plan "%s"%s remains active after stand-down.',
                $planTitle,
                $activation->plan?->version ? " (v{$activation->plan->version})" : '',
            ),
            'options_considered' => 'Deactivate at stand-down, or keep active beyond the incident.',
            'rationale' => $statement,
        ]);

        $activation->update(['kept_active_entry_id' => $entry->getKey()]);

        $incident->recordAudit('incident.plan_kept_active', [
            'activation_id' => $activation->getKey(),
            'plan_id' => $activation->plan_id,
            'entry_id' => $entry->getKey(),
            'statement' => $statement,
        ]);
    }

    /**
     * GAP 4 — the routine counterpart of `keepPlanActiveAtStandDown()`:
     * "leave blank to deactivate" made real. No DECISION-log entry — unlike
     * keeping a plan active past its incident, deactivating it AT stand-down
     * is the ordinary case and needs no rationale of its own, the same as
     * the plain `plans.deactivate` route (`PlanDocumentController::
     * deactivate()`) never writes one, so `log()` is never given
     * `options_considered`/`rationale` here and never refuses for lacking
     * them. Reuses `PlanActivationService::deactivate()` rather than
     * updating the row here, so there is one write path for "this activation
     * stopped", not two.
     *
     * A6: writes an `action` entry to `bcms_incident_log` — the PIR reads
     * the incident's own timeline, and before this an activation deactivated
     * here left a mark only in `bcms_audit_logs`, which the PIR does not
     * read — in addition to, not instead of, the existing audit row.
     */
    private function deactivatePlanAtStandDown(Incident $incident, PlanActivation $activation, User $by): void
    {
        $this->planActivations->deactivate($activation);

        $planTitle = $activation->plan->title ?? "activation {$activation->getKey()}";

        $this->log($incident, $by, [
            'entry_type' => IncidentLogEntryType::Action->value,
            'content' => sprintf(
                'Plan "%s"%s deactivated at stand-down.',
                $planTitle,
                $activation->plan?->version ? " (v{$activation->plan->version})" : '',
            ),
        ]);

        $incident->recordAudit('incident.plan_deactivated_at_standdown', [
            'activation_id' => $activation->getKey(),
            'plan_id' => $activation->plan_id,
        ]);
    }

    /**
     * The declaration screen's live severity suggestion (`incident-
     * declaration.md` §2/§7) — a default matrix over the tenant's own
     * numbers, per clause map §1.3. Deployment-wide config only in this
     * phase; a tenant override is a settings-screen concern flagged for
     * follow-up.
     *
     * @param  array{is_critical_service?: bool, life_safety?: bool, open_banking_past_threshold?: bool, personal_data_high_risk?: bool, single_site?: bool, within_rto?: bool}  $inputs
     * @return array{severity: string, trigger: string}
     */
    public function suggestSeverity(array $inputs): array
    {
        if (($inputs['life_safety'] ?? false)
            || ($inputs['open_banking_past_threshold'] ?? false)
            || ($inputs['personal_data_high_risk'] ?? false)
            || (($inputs['is_critical_service'] ?? false) && ! ($inputs['within_rto'] ?? true))) {
            return ['severity' => IncidentSeverity::Sev1->value, 'trigger' => 'A critical service is down or forecast down past its BIA RTO, a life-safety impact exists, an Open Banking service is past its 30-minute threshold, or a personal-data breach carries high risk to data subjects.'];
        }

        if ($inputs['single_site'] ?? false) {
            return ['severity' => IncidentSeverity::Sev2->value, 'trigger' => 'A prioritised activity is disrupted but inside its RTO, or running on a documented workaround, contained to one site or business line.'];
        }

        return ['severity' => IncidentSeverity::Sev3->value, 'trigger' => 'Contained to one system with no customer impact and no data loss.'];
    }
}
