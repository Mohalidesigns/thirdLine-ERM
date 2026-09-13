<?php

namespace App\Services\Widgets;

use App\Models\WidgetDefinition;

/**
 * WP-08 TASK 2 — one implementation per widget_type.
 *
 * A resolver answers the definition's question inside the given context and
 * returns the type-specific `data` block of the render payload. It never
 * reads the request, the session or auth() — everything situational arrives
 * in the context, which is what keeps a widget renderable on an HQ page, in
 * a digest email and in an export with identical numbers.
 */
interface WidgetTypeResolver
{
    /**
     * @return array<string, mixed> the payload's `data` block
     */
    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array;
}
