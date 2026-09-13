<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowNodeType;

/**
 * WP-06 TASK 3 — what a definition must satisfy before it may be published.
 *
 * The checks are chosen for the mistakes people actually make when drawing a
 * process, each of which produces a running instance nobody can clear:
 *
 *   no start / two starts        the engine cannot decide where to begin
 *   no end                       every instance runs forever
 *   an unreachable node          a step somebody drew and wired to nothing
 *   a dead end                   a branch from which no end can be reached —
 *                                the task sits on a queue permanently
 *   an edge to a missing node    a rename that did not update its edges
 *   an approval with no assignee rule and no fallback role
 *                                a decision offered to nobody
 *   a join that nothing forks into
 *                                waits for branches that never arrive
 *   a malformed condition        fails closed, silently blocking the branch
 *
 * A draft may be saved in any state. Publishing is where correctness is
 * demanded, because publishing is where it starts costing somebody their week.
 */
class WorkflowDefinitionValidator
{
    public function __construct(private ConditionEvaluator $conditions) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> the errors, empty when publishable
     */
    public function errors(array $definition): array
    {
        $graph = WorkflowGraph::make($definition);
        $nodes = $graph->nodes();
        $codes = array_column($nodes, 'code');

        $errors = [];

        if ($nodes === []) {
            return ['The workflow has no steps.'];
        }

        $errors = array_merge(
            $errors,
            $this->structureErrors($graph, $nodes, $codes),
            $this->edgeErrors($graph, $codes),
            $this->nodeErrors($graph, $nodes),
            $this->reachabilityErrors($graph),
        );

        return array_values(array_unique($errors));
    }

    public function isValid(array $definition): bool
    {
        return $this->errors($definition) === [];
    }

    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function structureErrors(WorkflowGraph $graph, array $nodes, array $codes): array
    {
        $errors = [];

        $duplicates = array_keys(array_filter(array_count_values($codes), fn (int $count) => $count > 1));

        foreach ($duplicates as $code) {
            $errors[] = "Two steps share the code [{$code}]. Step codes must be unique.";
        }

        $starts = $graph->nodesOfType(WorkflowNodeType::Start);

        if ($starts === []) {
            $errors[] = 'The workflow has no start step.';
        } elseif (count($starts) > 1) {
            $errors[] = 'The workflow has '.count($starts).' start steps; it may have exactly one.';
        }

        if ($graph->nodesOfType(WorkflowNodeType::End) === []) {
            $errors[] = 'The workflow has no end step, so no instance of it could ever finish.';
        }

        foreach ($nodes as $node) {
            if (blank($node['code'] ?? null)) {
                $errors[] = 'A step has no code.';

                continue;
            }

            if (WorkflowNodeType::tryFrom($node['type'] ?? '') === null) {
                $errors[] = "Step [{$node['code']}] has an unknown type [".($node['type'] ?? 'none').'].';
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function edgeErrors(WorkflowGraph $graph, array $codes): array
    {
        $errors = [];

        foreach ($graph->edges() as $edge) {
            if (! in_array($edge['from'], $codes, true)) {
                $errors[] = "A connection starts at [{$edge['from']}], which is not a step in this workflow.";
            }

            if (! in_array($edge['to'], $codes, true)) {
                $errors[] = "A connection points at [{$edge['to']}], which is not a step in this workflow.";
            }

            if (($error = $this->conditions->syntaxError($edge['when'] ?? null)) !== null) {
                $errors[] = "The condition on [{$edge['from']}] → [{$edge['to']}] does not parse: {$error}";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function nodeErrors(WorkflowGraph $graph, array $nodes): array
    {
        $errors = [];

        foreach ($nodes as $node) {
            $code = $node['code'] ?? '?';
            $type = WorkflowNodeType::tryFrom($node['type'] ?? '');

            if ($type === null) {
                continue;
            }

            if ($type === WorkflowNodeType::End && $graph->edgesFrom($code) !== []) {
                $errors[] = "End step [{$code}] has an outgoing connection; nothing runs after an end step.";
            }

            if ($type !== WorkflowNodeType::End && $type !== WorkflowNodeType::Timer && $graph->edgesFrom($code) === []) {
                $errors[] = "Step [{$code}] has no outgoing connection, so the workflow stops there without finishing.";
            }

            if ($type->waitsForHuman()) {
                $errors = array_merge($errors, $this->assigneeErrors($node, $code));
            }

            if ($type === WorkflowNodeType::Join) {
                if (count($graph->edgesTo($code)) < 2) {
                    $errors[] = "Join step [{$code}] has fewer than two incoming branches; there is nothing to join.";
                }

                if ($graph->nodesOfType(WorkflowNodeType::ParallelGateway) === []) {
                    $errors[] = "Join step [{$code}] has no fork anywhere in the workflow, so it would wait forever.";
                }
            }

            if ($type === WorkflowNodeType::ParallelGateway && count($graph->edgesFrom($code)) < 2) {
                $errors[] = "Fork step [{$code}] has fewer than two outgoing branches; it forks nothing.";
            }

            if ($type === WorkflowNodeType::ExclusiveGateway) {
                $unconditional = array_filter($graph->edgesFrom($code), fn (array $e) => blank($e['when'] ?? null));

                if ($unconditional === []) {
                    $errors[] = "Decision step [{$code}] has no default branch: if no condition matches, the "
                        .'instance stops there. Add a connection with no condition.';
                }
            }

            if (isset($node['sla_hours']) && (float) $node['sla_hours'] < 0) {
                $errors[] = "Step [{$code}] has a negative service level.";
            }

            if (($error = $this->conditions->syntaxError($node['condition_expression'] ?? null)) !== null) {
                $errors[] = "The condition on step [{$code}] does not parse: {$error}";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function assigneeErrors(array $node, string $code): array
    {
        $rule = $node['assignee_rule'] ?? null;
        $config = (array) ($node['assignee_config'] ?? []);
        $fallback = (array) ($config['fallback_roles'] ?? $node['fallback_roles'] ?? []);

        if ($rule === null) {
            return ["Step [{$code}] needs somebody to do it: choose who it is assigned to."];
        }

        $named = match ($rule) {
            'user' => ! blank($config['user_id'] ?? null),
            'role', 'group' => ! blank($config['roles'] ?? $config['role'] ?? null) || ! blank($config['user_ids'] ?? null),
            'expression' => ! blank($config['expression'] ?? null),
            // owner, delegate, manager and relationship_traversal resolve from
            // the subject at run time, so there is nothing to check here beyond
            // a fallback for when the subject has no owner set.
            'relationship_traversal' => ! blank($config['relationship'] ?? null),
            default => true,
        };

        if (! $named) {
            return ["Step [{$code}] is assigned by [{$rule}] but the rule has not been filled in."];
        }

        if (in_array($rule, ['owner', 'delegate', 'manager', 'relationship_traversal'], true) && $fallback === []) {
            return ["Step [{$code}] resolves its assignee from the record. Add a fallback role for records "
                .'that have no owner set, or the task lands on nobody.'];
        }

        return [];
    }

    /** @return list<string> */
    private function reachabilityErrors(WorkflowGraph $graph): array
    {
        $errors = [];

        if ($graph->startNode() === null) {
            // Already reported; reachability is meaningless without a start.
            return [];
        }

        $reachable = $graph->reachableFromStart();

        foreach ($graph->nodes() as $node) {
            if (! in_array($node['code'], $reachable, true)) {
                $errors[] = "Step [{$node['code']}] cannot be reached from the start.";
            }
        }

        foreach ($graph->deadEnds() as $code) {
            if (in_array($code, $reachable, true)) {
                $errors[] = "No end step can be reached from [{$code}]; an instance that gets there never finishes.";
            }
        }

        return $errors;
    }
}
