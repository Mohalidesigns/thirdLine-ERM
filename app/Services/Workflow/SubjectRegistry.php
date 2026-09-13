<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Subjects\GenericSubjectBinding;
use App\Services\Workflow\Subjects\SubjectBinding;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the SubjectBinding for a morph alias.
 *
 * Keyed on the alias rather than the class so the map reads the same as
 * workflow_definitions.entity_type, approval_requests.entity_type and
 * risk_audit_trail.entity_type — the four places that name a kind of thing all
 * name it the same way (MorphTypes is the authority).
 *
 * An unmapped subject gets GenericSubjectBinding rather than an exception: the
 * engine must be able to run a process over a graph object of a type a
 * configurer invented this morning, and that object has no legacy columns to
 * mirror, which is exactly what the generic binding does.
 */
class SubjectRegistry
{
    /** @var array<string, SubjectBinding> */
    private array $resolved = [];

    public function for(?Model $subject): ?SubjectBinding
    {
        if ($subject === null) {
            return null;
        }

        return $this->forType($subject->getMorphClass());
    }

    public function forType(?string $entityType): ?SubjectBinding
    {
        $alias = MorphTypes::normalise($entityType) ?? $entityType;

        if ($alias === null) {
            return null;
        }

        if (isset($this->resolved[$alias])) {
            return $this->resolved[$alias];
        }

        $class = config('workflow.subjects.'.$alias);

        return $this->resolved[$alias] = $class && class_exists($class)
            ? app($class)
            : app(GenericSubjectBinding::class);
    }

    /**
     * The aliases with a dedicated binding — used by the definitions screen to
     * offer only subjects the engine can actually mirror.
     *
     * @return list<string>
     */
    public function boundTypes(): array
    {
        return array_keys((array) config('workflow.subjects', []));
    }
}
