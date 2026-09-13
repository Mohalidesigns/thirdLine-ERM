<?php

namespace App\Observers;

use App\Services\Workflow\WorkflowTriggerService;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires on_create and on_transition workflows.
 *
 * Registered against every model with a subject binding (see
 * AppServiceProvider). It does nothing at all unless the organization has
 * PUBLISHED a definition with that trigger — none of the ten shipped processes
 * use them — so installing WP-06 changes no existing behaviour, and a
 * configurer who wants automatic start gets it without a code change.
 *
 * THE STATUS COLUMN IS PER MODEL. The platform has four spellings of "what
 * state is this in" — status, issue_status, current_status, lifecycle_state —
 * because each was named by whoever built that module. Rather than pretend
 * otherwise, all four are watched.
 */
class WorkflowTriggerObserver
{
    /** The columns that mean "what state is this record in". */
    private const STATE_COLUMNS = ['status', 'issue_status', 'current_status', 'lifecycle_state'];

    public function __construct(private WorkflowTriggerService $triggers) {}

    public function created(Model $model): void
    {
        $this->triggers->handleCreated($model);
    }

    public function updated(Model $model): void
    {
        foreach (self::STATE_COLUMNS as $column) {
            if (! $model->wasChanged($column)) {
                continue;
            }

            $this->triggers->handleTransition(
                $model,
                $this->asString($model->getOriginal($column)),
                $this->asString($model->getAttribute($column)),
            );

            // One transition per save. A model with two state columns changing
            // in one write is a data problem, not two transitions.
            return;
        }
    }

    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
