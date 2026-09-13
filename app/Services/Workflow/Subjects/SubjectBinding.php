<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

/**
 * What the engine needs to know about the thing being approved.
 *
 * The engine is deliberately ignorant of risk assessments, loss events and
 * treatment plans. Everything module-specific — which Gate guards the
 * decision, which owner the "owner" assignee rule means, and which legacy
 * columns must keep being written — lives behind this interface.
 *
 * KEEPING THE LEGACY COLUMNS WRITTEN IS THE POINT OF onApproved().
 * risk_assessments.approved_by, treatment_plans.status, loss_events.approved_at
 * are read by list screens, exports, the board pack and half the reports. WP-06
 * moves the DECISION onto the engine; it does not get to break those in the
 * same release. The engine writes them through this binding for one release,
 * after which the follow-up ticket removes both the writes and the columns.
 */
interface SubjectBinding
{
    /**
     * The Gate ability guarding a decision on this subject, or null when the
     * node's permission is the only check.
     *
     * WP-06 keeps the existing Gates as the permission layer rather than
     * reinventing one: approve-risk-assessment already encodes "the assigned
     * reviewer OR a risk-manager/CRO", which is knowledge the definitions
     * should not have to restate.
     */
    public function gate(): ?string;

    /** The user the `owner` assignee rule resolves to. */
    public function ownerId(Model $subject): ?int;

    /** The user the `delegate` assignee rule resolves to, before falling back to the owner. */
    public function delegateId(Model $subject): ?int;

    /** The org-graph node this subject hangs off, for `manager` and traversal rules. */
    public function nodeId(Model $subject): ?int;

    /** A human label for notifications and task lists — "LE-2026-0031 — ATM cash shortage". */
    public function label(Model $subject): string;

    /**
     * Values worth freezing into the instance context at start.
     *
     * Conditions read these, so they must be the values AS AT the start of the
     * process: routing a loss event to the board because it exceeded a limit
     * should not change because somebody later edited the amount.
     *
     * @return array<string, mixed>
     */
    public function context(Model $subject): array;

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void;

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void;

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void;

    /** Sent back for rework: the subject returns to an editable state. */
    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void;

    public function onCancelled(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void;

    /** A task reached a human node — the hook for module-specific notification wording. */
    public function onTaskRaised(Model $subject, WorkflowTask $task): void;
}
