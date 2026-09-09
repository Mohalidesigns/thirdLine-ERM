<?php

namespace App\Services\Bcms\Findings;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Services\Bcms\Integration\ErmBridge;
use App\Services\ReferenceCodeService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The CAPA half of the shared contract — ISO 22301 clause 10.1.
 *
 * `completed` AND `verified` ARE TWO STATES AND THE GAP BETWEEN THEM IS THE
 * WHOLE POINT OF THE CLAUSE. The owner says the action is done; somebody who is
 * not the owner confirms it worked. A register that stops at `completed` records
 * intentions, and `verify()` refuses the owner by name rather than trusting a
 * convention.
 *
 * `carried_to_occurrence_id` IS NOT WRITTEN HERE. It is exposed on the model and
 * populated exclusively by the exercise engine in Phase 9 (Orchestration §5).
 * That column is the mechanism behind the ISO 22398 ladder — each level builds
 * on the corrective actions of the one below — and anything else writing it
 * destroys the audit trail of why an action appeared on an exercise's checklist.
 *
 * OVERDUE IS COMPUTED BY A SWEEP, NOT BY AN ACCESSOR. An accessor makes
 * "overdue" a property of when you looked; a stored status makes it a property
 * of the record, which is what a board pack printed in March has to still say in
 * December.
 */
class CorrectiveActionService
{
    public function __construct(private ErmBridge $erm) {}

    /** @param array<string, mixed> $attributes */
    public function create(Finding $finding, string $title, array $attributes = [], ?int $userId = null): CorrectiveAction
    {
        return DB::transaction(fn () => CorrectiveAction::query()->create(array_merge([
            'finding_id' => $finding->getKey(),
            'reference' => ReferenceCodeService::generate('bcms_corrective_actions', 'reference', 'BCA'),
            'title' => $title,
            'status' => CorrectiveActionStatus::Open->value,
            'iso_clause_ref' => \App\Enums\Bcms\IsoClauseRef::Iso22301_10_1_corrective->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes)));
    }

    public function assign(CorrectiveAction $action, int $ownerId, ?string $dueDate = null, ?int $userId = null): CorrectiveAction
    {
        $action->update(array_filter([
            'owner_id' => $ownerId,
            'due_date' => $dueDate,
            'status' => $action->status === CorrectiveActionStatus::Open
                ? CorrectiveActionStatus::InProgress->value
                : null,
            'updated_by' => $userId ?? auth()->id(),
        ], fn ($v) => $v !== null));

        return $action->refresh();
    }

    public function complete(CorrectiveAction $action, ?int $userId = null): CorrectiveAction
    {
        $userId ??= auth()->id();

        if ($action->status->isClosed()) {
            throw new InvalidArgumentException('This action is already closed.');
        }

        $action->update([
            'status' => CorrectiveActionStatus::Completed->value,
            'completed_at' => now(),
            'completed_by' => $userId,
            'updated_by' => $userId,
        ]);

        return $action->refresh();
    }

    /**
     * Verify that the action actually closed the finding.
     *
     * THE VERIFIER MAY NOT BE THE OWNER OR THE PERSON WHO COMPLETED IT. Clause
     * 10.1 asks whether the action WORKED, which the person who did it cannot
     * answer about themselves. Enforced here rather than left to a policy,
     * because a policy is checked at a route and this service is also called
     * from a job.
     */
    public function verify(CorrectiveAction $action, int $verifierId, ?string $note = null, ?int $evidenceFileId = null): CorrectiveAction
    {
        if ($action->status !== CorrectiveActionStatus::Completed) {
            throw new InvalidArgumentException('Only a completed corrective action can be verified.');
        }

        if ($verifierId === (int) $action->owner_id || $verifierId === (int) $action->completed_by) {
            throw new InvalidArgumentException(
                'A corrective action must be verified by somebody other than the person who owned or completed it '
                .'(ISO 22301 clause 10.1).'
            );
        }

        return DB::transaction(function () use ($action, $verifierId, $note, $evidenceFileId) {
            $action->update([
                'status' => CorrectiveActionStatus::Verified->value,
                'verified_by' => $verifierId,
                'verified_at' => now(),
                'verification_note' => $note,
                'verification_evidence_id' => $evidenceFileId,
                'updated_by' => $verifierId,
            ]);

            $this->erm->syncActionClosure($action->refresh());

            return $action;
        });
    }

    /**
     * Accept the risk rather than correcting it.
     *
     * `acceptance_expires_on` is not optional in spirit: an acceptance with no
     * expiry is a nonconformity nobody will look at again, which is the state
     * clause 10.1 exists to prevent. It is nullable in the column because a
     * permanent acceptance is occasionally right, and the register reports the
     * ones with no expiry separately.
     */
    public function acceptRisk(CorrectiveAction $action, string $rationale, int $approverId, ?string $expiresOn = null): CorrectiveAction
    {
        $action->update([
            'status' => CorrectiveActionStatus::AcceptedRisk->value,
            'acceptance_rationale' => $rationale,
            'accepted_by' => $approverId,
            'accepted_at' => now(),
            'acceptance_expires_on' => $expiresOn,
            'updated_by' => $approverId,
        ]);

        return $action->refresh();
    }

    /**
     * Mark actions past their due date as overdue, and reopen lapsed
     * acceptances.
     *
     * Called by the daily sweep. Both halves matter: an acceptance that has
     * expired and stays closed is a nonconformity that quietly went away.
     *
     * @return array{overdue: int, lapsed: int}
     */
    public function sweep(): array
    {
        $overdue = CorrectiveAction::query()
            ->whereIn('status', [CorrectiveActionStatus::Open->value, CorrectiveActionStatus::InProgress->value])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => CorrectiveActionStatus::Overdue->value]);

        $lapsed = CorrectiveAction::query()
            ->where('status', CorrectiveActionStatus::AcceptedRisk->value)
            ->whereNotNull('acceptance_expires_on')
            ->whereDate('acceptance_expires_on', '<', now()->toDateString())
            ->update([
                'status' => CorrectiveActionStatus::Open->value,
                'accepted_at' => null,
                'acceptance_expires_on' => null,
            ]);

        return ['overdue' => $overdue, 'lapsed' => $lapsed];
    }
}
