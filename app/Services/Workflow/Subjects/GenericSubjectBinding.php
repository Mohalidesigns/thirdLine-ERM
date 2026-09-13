<?php

namespace App\Services\Workflow\Subjects;

use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * The binding for a subject with no module-specific columns to mirror.
 *
 * Used for graph objects of configurer-defined types — a Policy, an Obligation,
 * anything WP-05 lets somebody invent — where the decision IS the lifecycle
 * state and there is no second copy of it to keep in step.
 */
class GenericSubjectBinding extends BaseSubjectBinding
{
    public function context(Model $subject): array
    {
        return array_filter([
            'id' => $subject->getKey(),
            'type' => $subject->getMorphClass(),
            'code' => $subject->getAttribute('code'),
            'name' => $subject->getAttribute('name') ?? $subject->getAttribute('title'),
            'lifecycle_state' => $subject->getAttribute('lifecycle_state'),
            'status' => $subject->getAttribute('status'),
        ], fn ($value) => $value !== null);
    }

    /**
     * Move the lifecycle state when the subject has one.
     *
     * The target state names come from the definition's end node
     * (approved_state / rejected_state), so a Policy process can say "approved"
     * and an Obligation process "in_force" without either needing code.
     */
    public function onApproved(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $comments): void
    {
        $this->moveLifecycle($subject, $instance, 'approved_state', 'approved');
    }

    public function onRejected(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->moveLifecycle($subject, $instance, 'rejected_state', 'rejected');
    }

    public function onReturned(Model $subject, ?WorkflowInstance $instance, ?User $actor, ?string $reason): void
    {
        $this->moveLifecycle($subject, $instance, 'draft_state', 'draft');
    }

    private function moveLifecycle(Model $subject, ?WorkflowInstance $instance, string $key, string $default): void
    {
        // No lifecycle column means there is nothing to mirror, and the
        // instance's own outcome is the whole record of the decision.
        if (! array_key_exists('lifecycle_state', $subject->getAttributes())) {
            return;
        }

        $state = $instance?->contextValue($key)
            ?? data_get($instance?->definition?->trigger_config, $key)
            ?? $default;

        $this->write($subject, ['lifecycle_state' => $state]);
    }
}
