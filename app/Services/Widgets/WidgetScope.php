<?php

namespace App\Services\Widgets;

use App\Models\GraphObject;

/**
 * The resolved "where" of one widget render: which graph nodes are in scope.
 *
 * $nodeIds NULL means unrestricted — the whole organization (tenancy still
 * applies through the model scopes; unrestricted never means cross-tenant).
 * An EMPTY list is not the same thing: it means the binding resolved to
 * nothing visible, and the widget must render empty rather than widening.
 */
class WidgetScope
{
    /**
     * @param  list<int>|null  $nodeIds  ids in the `objects` table
     */
    public function __construct(
        public readonly ?array $nodeIds,
        public readonly ?GraphObject $anchor = null,
    ) {}

    public static function unrestricted(?GraphObject $anchor = null): self
    {
        return new self(null, $anchor);
    }

    public static function nothing(): self
    {
        return new self([]);
    }

    public function isUnrestricted(): bool
    {
        return $this->nodeIds === null;
    }

    public function isEmpty(): bool
    {
        return $this->nodeIds !== null && $this->nodeIds === [];
    }
}
