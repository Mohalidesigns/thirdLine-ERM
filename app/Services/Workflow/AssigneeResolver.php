<?php

namespace App\Services\Workflow;

use App\Models\GraphObject;
use App\Models\ObjectRelationship;
use App\Models\ObjectRelationshipType;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflow\Subjects\SubjectBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Turns a node's assignee_rule into "this person" or "anyone holding this role".
 *
 * A resolution is either NAMED (a user id) or an OFFER (one or more roles, or a
 * shortlist of people, first to act claims it). Both are legitimate: a
 * definition seeded before the customer has drawn their org chart can only say
 * "the risk-manager decides", and that is a more honest statement than picking
 * whichever risk-manager happens to have the lowest user id.
 *
 * A rule that resolves to nobody is not an error the engine can swallow. It
 * returns an unassigned offer with the node's fallback roles, and logs — a task
 * on nobody's list is a decision that never happens, and silence there is how a
 * regulatory deadline gets missed.
 */
class AssigneeResolver
{
    public function __construct(private ConditionEvaluator $conditions) {}

    /**
     * @param  array<string, mixed>  $node
     * @return array{assignee_id:?int, assignee_role:?string, candidate_roles:list<string>, candidate_user_ids:list<int>}
     */
    public function resolve(array $node, WorkflowInstance $instance, ?Model $subject, ?SubjectBinding $binding): array
    {
        $rule = $node['assignee_rule'] ?? 'role';
        $config = (array) ($node['assignee_config'] ?? []);

        $resolved = match ($rule) {
            'user' => $this->named($config['user_id'] ?? null),
            'role', 'group' => $this->offer($config),
            'owner' => $this->named($subject && $binding ? $binding->ownerId($subject) : null),
            'delegate' => $this->named(
                ($subject && $binding ? $binding->delegateId($subject) : null)
                ?? ($subject && $binding ? $binding->ownerId($subject) : null)
            ),
            'manager' => $this->named($this->managerOf($instance, $subject, $binding)),
            'relationship_traversal' => $this->named($this->traverse($config, $instance, $subject, $binding)),
            'expression' => $this->fromExpression($config, $instance),
            default => $this->empty(),
        };

        // Every rule may name fallback roles. They are what keeps a task
        // reachable when the named person has left, been deactivated, or was
        // never set on this record in the first place.
        $fallbackRoles = $this->roleList($config['fallback_roles'] ?? $node['fallback_roles'] ?? []);

        if ($resolved['assignee_id'] === null && $resolved['candidate_roles'] === [] && $resolved['candidate_user_ids'] === []) {
            if ($fallbackRoles === []) {
                Log::warning('Workflow node resolved to no assignee and has no fallback role.', [
                    'instance_id' => $instance->id,
                    'node' => $node['code'] ?? null,
                    'rule' => $rule,
                ]);
            }

            $resolved['candidate_roles'] = $fallbackRoles;
        }

        $resolved['assignee_role'] = $resolved['candidate_roles'][0] ?? null;

        // A named assignee who cannot log in is the same as no assignee.
        if ($resolved['assignee_id'] !== null && ! $this->isActive($resolved['assignee_id'], $instance->organization_id)) {
            Log::info('Workflow assignee is inactive or outside the tenant; offering the task to the fallback roles instead.', [
                'instance_id' => $instance->id,
                'node' => $node['code'] ?? null,
                'user_id' => $resolved['assignee_id'],
            ]);

            $resolved['assignee_id'] = null;
            $resolved['candidate_roles'] = $resolved['candidate_roles'] ?: $fallbackRoles;
            $resolved['assignee_role'] = $resolved['candidate_roles'][0] ?? null;
        }

        return $resolved;
    }

    /**
     * Everyone who should be told a task exists: the named assignee, or every
     * holder of the offered roles, plus any named shortlist.
     *
     * @param  array{assignee_id:?int, candidate_roles:list<string>, candidate_user_ids:list<int>}  $resolution
     * @return list<int>
     */
    public function recipients(array $resolution, ?int $organizationId): array
    {
        if ($resolution['assignee_id'] !== null) {
            return [$resolution['assignee_id']];
        }

        $ids = $resolution['candidate_user_ids'];

        if ($resolution['candidate_roles'] !== []) {
            $ids = array_merge($ids, User::query()
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
                ->where('is_active', true)
                ->role($resolution['candidate_roles'])
                ->pluck('id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /* ------------------------------------------------------------------ */
    /*  Rules */
    /* ------------------------------------------------------------------ */

    private function named(int|string|null $userId): array
    {
        return [
            'assignee_id' => $userId === null || $userId === '' ? null : (int) $userId,
            'assignee_role' => null,
            'candidate_roles' => [],
            'candidate_user_ids' => [],
        ];
    }

    /** @param array<string, mixed> $config */
    private function offer(array $config): array
    {
        return [
            'assignee_id' => null,
            'assignee_role' => null,
            'candidate_roles' => $this->roleList($config['roles'] ?? $config['role'] ?? []),
            'candidate_user_ids' => array_values(array_map('intval', (array) ($config['user_ids'] ?? []))),
        ];
    }

    private function empty(): array
    {
        return ['assignee_id' => null, 'assignee_role' => null, 'candidate_roles' => [], 'candidate_user_ids' => []];
    }

    /**
     * The manager is the owner of the node ABOVE the subject's node in the org
     * graph.
     *
     * The users table has no manager_id, and inventing one would be a fiction
     * nobody maintains. The object graph already records who owns each business
     * unit, so "escalate to my manager" means "escalate to whoever owns the unit
     * my unit reports into" — which is the answer an escalation matrix is
     * actually asking for.
     */
    private function managerOf(WorkflowInstance $instance, ?Model $subject, ?SubjectBinding $binding): ?int
    {
        $nodeId = $subject && $binding ? $binding->nodeId($subject) : null;

        if ($nodeId === null) {
            return null;
        }

        $node = GraphObject::withoutGlobalScopes()->find($nodeId);
        $parent = $node?->parent_id
            ? GraphObject::withoutGlobalScopes()->find($node->parent_id)
            : null;

        return $parent?->owner_id ?? $node?->owner_id;
    }

    /**
     * Follow a typed relationship out of the subject's own graph object and
     * take the owner of what it lands on.
     *
     * config: {relationship: 'assures', direction: 'outgoing'|'incoming'}
     * "The control's assurance provider approves this" is one edge, not a
     * column somebody has to remember to keep in step.
     */
    private function traverse(array $config, WorkflowInstance $instance, ?Model $subject, ?SubjectBinding $binding): ?int
    {
        $code = $config['relationship'] ?? null;

        if ($code === null || $subject === null) {
            return null;
        }

        $object = GraphObject::withoutGlobalScopes()
            ->where('source_model_type', $subject->getMorphClass())
            ->where('source_model_id', $subject->getKey())
            ->first();

        if ($object === null) {
            return null;
        }

        $type = ObjectRelationshipType::withoutGlobalScopes()->where('code', $code)->first();

        if ($type === null) {
            return null;
        }

        $outgoing = ($config['direction'] ?? 'outgoing') !== 'incoming';

        $related = ObjectRelationship::withoutGlobalScopes()
            ->where('relationship_type_id', $type->id)
            ->where($outgoing ? 'from_object_id' : 'to_object_id', $object->id)
            ->orderBy('id')
            ->first();

        if ($related === null) {
            return null;
        }

        $target = GraphObject::withoutGlobalScopes()
            ->find($outgoing ? $related->to_object_id : $related->from_object_id);

        return $target?->owner_id;
    }

    /**
     * An expression returning either a user id or a role name.
     *
     * The escape hatch for routing that is genuinely data-driven —
     * get(subject, 'severity') == 'critical' ? 'chief-risk-officer' : 'risk-manager'
     */
    private function fromExpression(array $config, WorkflowInstance $instance): array
    {
        $expression = $config['expression'] ?? null;

        if ($expression === null) {
            return $this->empty();
        }

        // evaluate() returns bool; this rule needs the raw value, so it asks
        // the evaluator for a truthiness-free read by comparing against itself.
        $value = $this->conditions->value($expression, [
            'context' => $instance->context ?? [],
            'subject' => $instance->contextValue('subject', []),
        ]);

        if (is_numeric($value)) {
            return $this->named((int) $value);
        }

        if (is_string($value) && $value !== '') {
            return array_merge($this->empty(), ['candidate_roles' => [$value]]);
        }

        return $this->empty();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function roleList(mixed $roles): array
    {
        return array_values(array_filter(array_map(
            fn ($role) => is_string($role) ? trim($role) : null,
            (array) $roles
        )));
    }

    private function isActive(int $userId, ?int $organizationId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->exists();
    }
}
