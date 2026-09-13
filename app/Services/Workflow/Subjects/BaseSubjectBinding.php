<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

/**
 * Sensible defaults for a subject that follows the platform's column
 * conventions, so a binding only has to state what is unusual about it.
 */
abstract class BaseSubjectBinding implements SubjectBinding
{
    public function gate(): ?string
    {
        return null;
    }

    public function ownerId(Model $subject): ?int
    {
        foreach (['owner_id', 'assigned_to_id', 'assigned_to', 'responsible_id', 'created_by'] as $column) {
            $value = $subject->getAttribute($column);

            if ($value !== null) {
                return (int) $value;
            }
        }

        return null;
    }

    public function delegateId(Model $subject): ?int
    {
        $value = $subject->getAttribute('delegate_owner_id') ?? $subject->getAttribute('reviewer_id');

        return $value === null ? null : (int) $value;
    }

    public function nodeId(Model $subject): ?int
    {
        $value = $subject->getAttribute('node_id');

        return $value === null ? null : (int) $value;
    }

    public function label(Model $subject): string
    {
        $reference = $subject->getAttribute('code')
            ?? $subject->getAttribute('reference')
            ?? class_basename($subject).' #'.$subject->getKey();

        $title = $subject->getAttribute('title') ?? $subject->getAttribute('name');

        return trim($reference.($title ? ' — '.$title : ''));
    }

    public function context(Model $subject): array
    {
        return [];
    }

    public function onStarted(Model $subject, ?WorkflowInstance $instance): void {}

    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void {}

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void {}

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void {}

    public function onCancelled(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void {}

    public function onTaskRaised(Model $subject, WorkflowTask $task): void {}

    /**
     * Write the subject's own status columns.
     *
     * forceFill rather than update() because several of these columns are
     * deliberately not fillable — approved_by on a loss event should not be
     * settable from a request payload — but model events still fire, so
     * observers, the object-graph mirror and the audit trail behave exactly as
     * they did when the controller wrote these columns itself.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function write(Model $subject, array $attributes): void
    {
        $subject->forceFill($attributes)->save();
    }
}
