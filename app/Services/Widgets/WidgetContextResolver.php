<?php

namespace App\Services\Widgets;

use App\Models\GraphObject;
use App\Models\WidgetDefinition;
use App\Services\Graph\GraphQueryService;
use App\Support\Authorization\GraphScope;

/**
 * WP-08 TASK 1 — turns a widget's context_binding into a WidgetScope.
 *
 * THIS CLASS IS THE POINT OF THE ENGINE. A widget with
 * context_binding=inherit_subtree placed on the Group HQ page resolves to the
 * group's node ids; the SAME definition on a business-unit page resolves to
 * that unit's. One definition, N contexts, and the difference between them is
 * decided here and nowhere else.
 *
 * Failure direction: every unresolvable binding narrows, never widens. A
 * fixed node that has been deleted, or a pin that no longer resolves, returns
 * WidgetScope::nothing() — an empty panel is a question the user can ask
 * somebody; another unit's numbers on a board pack is an incident.
 */
class WidgetContextResolver
{
    public function __construct(private readonly GraphQueryService $graph) {}

    public function resolve(WidgetDefinition $definition, WidgetContext $context): WidgetScope
    {
        return match ($definition->context_binding) {
            'inherit_node' => $this->inheritNode($context),
            'inherit_subtree' => $this->inheritSubtree($context),
            'fixed_node' => $this->fixedNode($definition, $context),
            'user_scope' => $this->userScope($context),
            default => WidgetScope::unrestricted($context->node),
        };
    }

    /** The page's node alone — "this unit's own risks", not its children's. */
    private function inheritNode(WidgetContext $context): WidgetScope
    {
        if ($context->node === null) {
            // A node-inheriting widget on a page with no node (the classic
            // dashboard) reads as the whole organization, which is what that
            // page has always meant.
            return WidgetScope::unrestricted();
        }

        return new WidgetScope([(int) $context->node->id], $context->node);
    }

    /** The page's node and everything beneath it. */
    private function inheritSubtree(WidgetContext $context): WidgetScope
    {
        if ($context->node === null) {
            return WidgetScope::unrestricted();
        }

        return new WidgetScope($this->subtreeIds($context->node, $context), $context->node);
    }

    /** The node named in the definition, wherever the widget is placed. */
    private function fixedNode(WidgetDefinition $definition, WidgetContext $context): WidgetScope
    {
        $nodeId = $definition->queryConfig('node_id');

        // Resolved through the tenant scope AND the user's graph pin: a fixed
        // node the viewer may not see renders empty, it does not leak.
        $node = $nodeId ? GraphObject::query()->whereKey((int) $nodeId)->first() : null;

        if ($node === null) {
            return WidgetScope::nothing();
        }

        return new WidgetScope($this->subtreeIds($node, $context), $node);
    }

    /**
     * The viewer's own slice of the organization — their scope_entity_id
     * subtree for a pinned user, everything for an unpinned one.
     */
    private function userScope(WidgetContext $context): WidgetScope
    {
        if (! GraphScope::isSubtreeLimited($context->user)) {
            return WidgetScope::unrestricted($context->node);
        }

        $rootId = GraphObject::query()
            ->where('source_model_type', 'entity')
            ->where('source_model_id', $context->user->scope_entity_id)
            ->value('id');

        if ($rootId === null) {
            // Pinned to a node with no graph counterpart: fail closed, the
            // same direction GraphScope itself fails.
            return WidgetScope::nothing();
        }

        $root = GraphObject::query()->whereKey((int) $rootId)->first();

        return $root === null
            ? WidgetScope::nothing()
            : new WidgetScope($this->subtreeIds($root, $context), $root);
    }

    /** @return list<int> the node itself plus every descendant */
    private function subtreeIds(GraphObject $node, WidgetContext $context): array
    {
        return collect([(int) $node->id])
            ->merge($this->graph->descendantIds((int) $node->id, [], null, $context->user))
            ->unique()
            ->values()
            ->all();
    }
}
