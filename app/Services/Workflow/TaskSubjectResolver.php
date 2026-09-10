<?php

namespace App\Services\Workflow;

use App\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The record behind a task, resolved once per list rather than per row
 * (migration Phase 3.7 — was MyTaskController::resolveSubjects), and the
 * two lines every screen prints for it.
 */
class TaskSubjectResolver
{
    /**
     * @param  Collection<int, WorkflowTask>  $tasks
     * @return array<string, Model> keyed "entity_type:id"
     */
    public function resolve(Collection $tasks): array
    {
        $subjects = [];

        $tasks->groupBy(fn (WorkflowTask $task) => $task->instance?->entity_type)
            ->each(function ($group, $entityType) use (&$subjects) {
                if (blank($entityType)) {
                    return;
                }

                $class = Relation::getMorphedModel($entityType);

                if ($class === null || ! class_exists($class)) {
                    return;
                }

                $ids = $group->map(fn (WorkflowTask $task) => $task->instance?->entity_id)->filter()->unique();

                foreach ($class::query()->whereIn('id', $ids)->get() as $model) {
                    $subjects[$entityType.':'.$model->getKey()] = $model;
                }
            });

        return $subjects;
    }

    /**
     * @return array{reference: string, title: string}
     */
    public function describe(?Model $subject, ?string $entityType = null, ?int $entityId = null): array
    {
        if ($subject === null) {
            return [
                'reference' => trim(str_replace('_', ' ', (string) $entityType).' #'.$entityId),
                'title' => '',
            ];
        }

        $reference = $subject->getAttribute('event_reference')
            ?? $subject->getAttribute('issue_reference')
            ?? $subject->getAttribute('risk_code')
            ?? $subject->getAttribute('treatment_code')
            ?? $subject->getAttribute('code')
            ?? class_basename($subject).' #'.$subject->getKey();

        return [
            'reference' => (string) $reference,
            'title' => Str::limit((string) ($subject->getAttribute('title') ?? $subject->getAttribute('name') ?? ''), 60),
        ];
    }
}
