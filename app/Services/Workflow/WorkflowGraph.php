<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowNodeType;

/**
 * A read model over workflow_definitions.definition.
 *
 * The engine never reaches into the JSON directly. Everything that asks "what
 * comes after this node", "who does this node belong to", "is this node a
 * gateway" goes through here, so the stored shape can change once rather than
 * in fifteen places — and so a malformed definition fails at one boundary
 * instead of as an array-key warning three calls deep.
 *
 * SHAPE
 *   nodes: [{code, type, name, assignee_rule, assignee_config, sla_hours,
 *            escalate_after_hours, escalate_to, on_timeout, required_fields,
 *            form_id, allow_delegate, allow_return, condition_expression,
 *            gate, outcome, service, x, y}]
 *   edges: [{from, to, when?, label?}]
 *
 * `when` is an expression over {outcome, context, subject}. An edge with no
 * `when` is unconditional; on an exclusive gateway the first satisfied edge in
 * document order wins, and an unconditional edge is therefore the default
 * branch and belongs last.
 */
class WorkflowGraph
{
    /** @param array<string, mixed> $definition */
    public function __construct(private array $definition) {}

    /** @param array<string, mixed>|null $definition */
    public static function make(?array $definition): self
    {
        return new self($definition ?? ['nodes' => [], 'edges' => []]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nodes' => $this->nodes(),
            'edges' => $this->edges(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->nodes() === [];
    }

    /* ------------------------------------------------------------------ */
    /*  Nodes */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return array_values(array_filter(
            $this->definition['nodes'] ?? [],
            fn ($node) => is_array($node) && isset($node['code'])
        ));
    }

    /** @return array<string, mixed>|null */
    public function node(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        foreach ($this->nodes() as $node) {
            if ($node['code'] === $code) {
                return $node;
            }
        }

        return null;
    }

    public function typeOf(string $code): ?WorkflowNodeType
    {
        $node = $this->node($code);

        return $node === null ? null : WorkflowNodeType::tryFrom($node['type'] ?? '');
    }

    /** @return list<array<string, mixed>> */
    public function nodesOfType(WorkflowNodeType $type): array
    {
        return array_values(array_filter(
            $this->nodes(),
            fn (array $node) => ($node['type'] ?? null) === $type->value
        ));
    }

    /**
     * The single start node.
     *
     * Returns null rather than guessing when there are none or several — the
     * validator refuses to publish either case, and an engine that silently
     * picked the first would start half the instances in the wrong place.
     */
    public function startNode(): ?array
    {
        $starts = $this->nodesOfType(WorkflowNodeType::Start);

        return count($starts) === 1 ? $starts[0] : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Edges */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return array_values(array_filter(
            $this->definition['edges'] ?? [],
            fn ($edge) => is_array($edge) && isset($edge['from'], $edge['to'])
        ));
    }

    /** @return list<array<string, mixed>> */
    public function edgesFrom(string $code): array
    {
        return array_values(array_filter($this->edges(), fn (array $edge) => $edge['from'] === $code));
    }

    /** @return list<array<string, mixed>> */
    public function edgesTo(string $code): array
    {
        return array_values(array_filter($this->edges(), fn (array $edge) => $edge['to'] === $code));
    }

    /**
     * How many branches a join node waits for.
     *
     * Its incoming edge count, unless the node states otherwise — a join fed by
     * a loop-back edge counts that edge too, and `join_count` is the escape
     * hatch for saying so.
     */
    public function joinArity(string $code): int
    {
        $node = $this->node($code) ?? [];

        return max(1, (int) ($node['join_count'] ?? count($this->edgesTo($code))));
    }

    /* ------------------------------------------------------------------ */
    /*  Reachability */
    /* ------------------------------------------------------------------ */

    /**
     * Node codes reachable from the start, ignoring edge conditions.
     *
     * Conditions are ignored on purpose: a node only reachable when a condition
     * happens to hold is still reachable, and the validator's question is
     * whether a node can EVER run, not whether it will.
     *
     * @return list<string>
     */
    public function reachableFromStart(): array
    {
        $start = $this->startNode();

        if ($start === null) {
            return [];
        }

        $seen = [];
        $queue = [$start['code']];

        while ($queue !== []) {
            $code = array_shift($queue);

            if (in_array($code, $seen, true)) {
                continue;
            }

            $seen[] = $code;

            foreach ($this->edgesFrom($code) as $edge) {
                if (! in_array($edge['to'], $seen, true)) {
                    $queue[] = $edge['to'];
                }
            }
        }

        return $seen;
    }

    /**
     * Node codes from which no end node can be reached.
     *
     * A definition with one of these can strand an instance forever, with a
     * task nobody can clear and a subject stuck in review — the failure mode
     * that makes people stop trusting a workflow tool.
     *
     * @return list<string>
     */
    public function deadEnds(): array
    {
        $ends = array_column($this->nodesOfType(WorkflowNodeType::End), 'code');

        if ($ends === []) {
            return array_column($this->nodes(), 'code');
        }

        // Walk backwards from every end node; whatever the walk never touches
        // cannot reach an end.
        $canReachEnd = [];
        $queue = $ends;

        while ($queue !== []) {
            $code = array_shift($queue);

            if (in_array($code, $canReachEnd, true)) {
                continue;
            }

            $canReachEnd[] = $code;

            foreach ($this->edgesTo($code) as $edge) {
                if (! in_array($edge['from'], $canReachEnd, true)) {
                    $queue[] = $edge['from'];
                }
            }
        }

        return array_values(array_diff(array_column($this->nodes(), 'code'), $canReachEnd));
    }
}
