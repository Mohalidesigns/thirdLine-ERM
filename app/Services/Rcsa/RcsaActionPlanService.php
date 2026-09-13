<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §9.3 — the action-plan tracking register that survives the cycle.
 *
 * "This is where most RCSA implementations quietly fail — the assessment is
 * done and the remediation is never tracked." Four things stop it being a text
 * column:
 *
 *   1. `status` is written by a SWEEP, not by whoever remembers. Overdue means
 *      one thing on the dashboard, in the export and in the Board pack because
 *      exactly one piece of code decides it.
 *   2. An extension is a REQUEST WITH AN APPROVER, and moving the date keeps
 *      `original_target_date`. A register whose dates can be edited reports
 *      100% on time for ever.
 *   3. Closure is the OWNER'S CLAIM; `verified_at` is the second line
 *      accepting it. A plan the owner marks done is not a plan the ORM has
 *      accepted, and collapsing the two loses the only check there is.
 *   4. Reminders fire before the date, not after it. T-14 and T-7 are what
 *      change an outcome; the overdue notice is a record of one.
 */
class RcsaActionPlanService
{
    public function __construct(private readonly RcsaAuditRecorder $audit) {}

    /**
     * The register, filtered — the ORM's view and the owner dashboard are the
     * same query with a different `owner` filter.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RcsaActionPlan>
     */
    public function register(array $filters = []): Builder
    {
        return RcsaActionPlan::query()
            ->with([
                'owner:id,name',
                'verifiedBy:id,name',
                'line:id,assessment_id,risk_no,potential_risk,business_unit_name,residual_level,appetite_status',
                'line.assessment:id,cycle_id,business_unit_id,status',
                'line.assessment.cycle:id,name',
            ])
            ->when(filled($filters['owner'] ?? null), fn ($q) => $q->where('owner_id', $filters['owner']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(
                ($filters['overdue'] ?? null) === true || ($filters['overdue'] ?? null) === '1',
                // The COMPUTED condition, not `status = overdue`: a plan that
                // came due at midnight is overdue at 09:00 whether or not the
                // nightly sweep has run, and a filter that disagreed with the
                // badge beside it would be reported as a bug every quarter.
                fn ($q) => $q->whereNotNull('target_date')
                    ->whereDate('target_date', '<', now()->toDateString())
                    ->whereNotIn('status', RcsaActionPlan::SETTLED),
            )
            ->when(
                ($filters['pending_verification'] ?? null) === true || ($filters['pending_verification'] ?? null) === '1',
                fn ($q) => $q->where('status', RcsaActionPlan::COMPLETED)->whereNull('verified_at'),
            )
            ->when(filled($filters['cycle'] ?? null), fn ($q) => $q->whereHas(
                'line.assessment',
                fn ($a) => $a->where('cycle_id', $filters['cycle']),
            ))
            ->when(filled($filters['business_unit'] ?? null), fn ($q) => $q->whereHas(
                'line',
                fn ($l) => $l->where('business_unit_id', $filters['business_unit']),
            ))
            // Soonest first, and plans with no date last: the register is a
            // list of what to chase, and something due on Friday belongs above
            // something due in March.
            ->orderByRaw('case when target_date is null then 1 else 0 end')
            ->orderBy('target_date');
    }

    /**
     * The owner's own counts, for the dashboard tiles.
     *
     * @return array{total: int, open: int, overdue: int, due_soon: int, completed: int, verified: int}
     */
    public function summaryFor(?int $ownerId = null): array
    {
        $base = fn () => RcsaActionPlan::query()
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId));

        return [
            'total' => $base()->count(),
            'open' => $base()->whereNotIn('status', RcsaActionPlan::SETTLED)->count(),
            'overdue' => $base()
                ->whereNotNull('target_date')
                ->whereDate('target_date', '<', now()->toDateString())
                ->whereNotIn('status', RcsaActionPlan::SETTLED)
                ->count(),
            // The next fortnight — the window in which chasing still changes
            // the outcome, and the same 14 days the first reminder fires on.
            'due_soon' => $base()
                ->whereNotNull('target_date')
                ->whereDate('target_date', '>=', now()->toDateString())
                ->whereDate('target_date', '<=', now()->addDays(14)->toDateString())
                ->whereNotIn('status', RcsaActionPlan::SETTLED)
                ->count(),
            'completed' => $base()->whereIn('status', RcsaActionPlan::SETTLED)->count(),
            'verified' => $base()->whereNotNull('verified_at')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Owner actions */
    /* ------------------------------------------------------------------ */

    /**
     * Record progress on a plan.
     */
    public function recordProgress(RcsaActionPlan $plan, User $actor, int $percent, ?string $note = null): RcsaActionPlan
    {
        $this->assertOpen($plan);

        $plan->forceFill([
            'progress_pct' => max(0, min(100, $percent)),
            // Touching a plan at all takes it out of `open`, which is the
            // state meaning nobody has started. It does NOT take it out of
            // `overdue`: progress on a late plan is still late, and letting an
            // update quietly clear the flag is how an overdue register empties
            // itself without anything being delivered.
            'status' => $plan->status === RcsaActionPlan::OPEN ? RcsaActionPlan::IN_PROGRESS : $plan->status,
        ])->save();

        if (filled($note)) {
            $plan->line?->comments()->create([
                'organization_id' => $plan->organization_id,
                'user_id' => $actor->id,
                'type' => \App\Models\Rcsa\RcsaLineComment::COMMENT,
                'body' => sprintf('Action plan update (%d%%): %s', $plan->progress_pct, $note),
            ]);
        }

        return $plan->refresh();
    }

    /**
     * The owner says it is done, with the evidence that says so.
     *
     * EVIDENCE IS REQUIRED. "Completed" with nothing behind it is the sentence
     * that makes a remediation register worthless — the ORM verifying closure
     * has to have something to verify.
     */
    public function complete(RcsaActionPlan $plan, User $actor, string $evidence): RcsaActionPlan
    {
        $this->assertOpen($plan);

        if (blank($evidence)) {
            throw new RuntimeException('Say what was put in place, and how it can be checked.');
        }

        $before = $plan->status;

        DB::transaction(function () use ($plan, $actor, $evidence, $before) {
            $plan->forceFill([
                'status' => RcsaActionPlan::COMPLETED,
                'progress_pct' => 100,
                'completion_evidence' => $evidence,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->audit->planChange($plan, 'status', $before, RcsaActionPlan::COMPLETED, $actor, $evidence);
        });

        $this->notifyVerifiers($plan, $actor);

        return $plan->refresh();
    }

    /**
     * Ask for more time.
     *
     * A REQUEST, NOT AN EDIT. The proposed date is held in `target_date` only
     * once somebody approves it; until then the plan stays due when it was
     * due, and the register keeps saying so. `original_target_date` is written
     * at the first approval and never again, so "extended twice from March"
     * survives however many extensions follow.
     */
    public function requestExtension(RcsaActionPlan $plan, User $actor, string $proposedDate, string $reason): RcsaActionPlan
    {
        $this->assertOpen($plan);

        // `proposed_target_date`, NOT `target_date`. The plan stays due when it
        // was due until somebody approves the move, which is the whole point of
        // an extension being a request.
        $plan->forceFill([
            'proposed_target_date' => $proposedDate,
            'extension_reason' => $reason,
            'extension_requested_by' => $actor->id,
            'extension_requested_at' => now(),
            'extension_approved_by' => null,
        ])->save();

        $this->notifyApprovers($plan, $actor, $proposedDate, $reason);

        return $plan->refresh();
    }

    /**
     * Approve or refuse a requested extension.
     */
    public function decideExtension(RcsaActionPlan $plan, User $actor, bool $approved): RcsaActionPlan
    {
        if (! $plan->hasPendingExtension()) {
            throw new RuntimeException('There is no extension request on this plan.');
        }

        $proposed = $plan->proposed_target_date;

        // A REFUSAL CLEARS THE REQUEST AND NOTHING ELSE. The date does not
        // move, the plan keeps whatever status it had, and the owner is told —
        // a refused extension that silently vanished would read to the owner
        // exactly like one nobody had looked at.
        if (! $approved || $proposed === null) {
            $plan->forceFill([
                'proposed_target_date' => null,
                'extension_reason' => null,
                'extension_requested_by' => null,
                'extension_requested_at' => null,
                'extension_approved_by' => null,
            ])->save();

            $this->notifyOwner(
                $plan,
                'rcsa.actionplan.extension_refused',
                sprintf('Extension refused: %s', \Illuminate\Support\Str::limit((string) $plan->control_to_implement, 60)),
                sprintf('%s did not approve the new date. The plan is still due %s.', $actor->name, $plan->target_date?->toDateString() ?? 'as before'),
            );

            return $plan->refresh();
        }

        $previousDate = $plan->target_date;

        $plan->forceFill([
            // Written once. A plan extended from March to June and then to
            // September is still, in the register, a plan that was due in
            // March.
            'original_target_date' => $plan->original_target_date ?? $plan->target_date,
            'target_date' => $proposed,
            'proposed_target_date' => null,
            'extension_approved_by' => $actor->id,
            // A newly-extended plan is no longer overdue — that is what the
            // approval decided — but it is not back to `open` either.
            'status' => $plan->status === RcsaActionPlan::OVERDUE ? RcsaActionPlan::IN_PROGRESS : $plan->status,
        ])->save();

        // Column W moving is the single most audit-worthy event in the
        // register: it is how a remediation programme comes to report nothing
        // overdue without anything having been delivered.
        $this->audit->planChange($plan, 'target_date', $previousDate, $proposed, $actor, $plan->extension_reason);

        $this->notifyOwner(
            $plan,
            'rcsa.actionplan.extended',
            sprintf('Extension approved: %s', \Illuminate\Support\Str::limit((string) $plan->control_to_implement, 60)),
            sprintf('%s approved your extension. The new date is %s.', $actor->name, $proposed->toDateString()),
        );

        return $plan->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  ORM verification */
    /* ------------------------------------------------------------------ */

    /**
     * The second line accepts that the control is in place.
     *
     * ONLY A COMPLETED PLAN CAN BE VERIFIED. Verification is acceptance of a
     * claim, and there is nothing to accept until the owner has made one.
     */
    public function verify(RcsaActionPlan $plan, User $actor): RcsaActionPlan
    {
        if ($plan->status !== RcsaActionPlan::COMPLETED) {
            throw new RuntimeException('Only a plan the owner has marked complete can be verified.');
        }

        $plan->forceFill([
            'status' => RcsaActionPlan::CLOSED,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ])->save();

        $this->audit->planChange($plan, 'status', RcsaActionPlan::COMPLETED, RcsaActionPlan::CLOSED, $actor);

        $this->notifyOwner(
            $plan,
            'rcsa.actionplan.verified',
            sprintf('Action plan closed: %s', \Illuminate\Support\Str::limit($plan->control_to_implement, 60)),
            sprintf('%s verified your closure and the plan is now closed.', $actor->name),
        );

        return $plan->refresh();
    }

    /**
     * Send a completed plan back to its owner — the ORM does not accept it.
     */
    public function rejectClosure(RcsaActionPlan $plan, User $actor, string $reason): RcsaActionPlan
    {
        if ($plan->status !== RcsaActionPlan::COMPLETED) {
            throw new RuntimeException('Only a plan the owner has marked complete can be sent back.');
        }

        $plan->forceFill([
            'status' => RcsaActionPlan::IN_PROGRESS,
            'closed_by' => null,
            'closed_at' => null,
        ])->save();

        $this->notifyOwner(
            $plan,
            'rcsa.actionplan.closure_rejected',
            sprintf('Closure not accepted: %s', \Illuminate\Support\Str::limit($plan->control_to_implement, 60)),
            sprintf('%s did not accept the closure: %s', $actor->name, $reason),
        );

        return $plan->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function assertOpen(RcsaActionPlan $plan): void
    {
        if (in_array($plan->status, RcsaActionPlan::SETTLED, true)) {
            throw new RuntimeException('This plan is already closed.');
        }
    }

    private function notifyOwner(RcsaActionPlan $plan, string $type, string $subject, string $body): void
    {
        if ($plan->owner_id === null) {
            return;
        }

        NotificationService::send(
            organizationId: (int) $plan->organization_id,
            userId: (int) $plan->owner_id,
            type: $type,
            subject: $subject,
            body: $body,
            metadata: ['action_plan_id' => $plan->id, 'line_id' => $plan->line_id],
            actionUrl: route('rcsa.action-plans.index', absolute: false),
            priority: 'medium',
            category: 'workflow',
        );
    }

    private function notifyVerifiers(RcsaActionPlan $plan, User $actor): void
    {
        $recipients = User::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user) => $user->can('rcsa_actionplan.verify'))
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            return;
        }

        NotificationService::sendMany(
            organizationId: (int) $plan->organization_id,
            userIds: array_map('intval', $recipients),
            type: 'rcsa.actionplan.completed',
            subject: sprintf('%s says an action plan is done', $actor->name),
            body: sprintf(
                '%s marked "%s" complete. It needs ORM verification before it closes.',
                $actor->name,
                \Illuminate\Support\Str::limit((string) $plan->control_to_implement, 120),
            ),
            metadata: ['action_plan_id' => $plan->id, 'line_id' => $plan->line_id],
            actionUrl: route('rcsa.action-plans.index', ['pending_verification' => 1], absolute: false),
            priority: 'medium',
            category: 'workflow',
        );
    }

    private function notifyApprovers(RcsaActionPlan $plan, User $actor, string $proposedDate, string $reason): void
    {
        $recipients = User::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user) => $user->can('rcsa_actionplan.close'))
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            return;
        }

        NotificationService::sendMany(
            organizationId: (int) $plan->organization_id,
            userIds: array_map('intval', $recipients),
            type: 'rcsa.actionplan.extension_requested',
            subject: sprintf('%s asked to move an action plan date', $actor->name),
            body: sprintf(
                '"%s" is due %s. %s asks for %s: %s',
                \Illuminate\Support\Str::limit((string) $plan->control_to_implement, 100),
                $plan->target_date?->toDateString() ?? 'no date',
                $actor->name,
                $proposedDate,
                $reason,
            ),
            metadata: ['action_plan_id' => $plan->id, 'line_id' => $plan->line_id],
            actionUrl: route('rcsa.action-plans.index', absolute: false),
            priority: 'medium',
            category: 'workflow',
        );
    }
}
