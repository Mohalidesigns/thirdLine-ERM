<?php

namespace App\Services\Widgets;

use App\Models\GraphObject;
use App\Models\Period;
use App\Models\User;
use App\Support\Periods\PeriodContext;

/**
 * WP-08 TASK 1 — everything a widget needs to know about WHERE it is being
 * rendered.
 *
 * A widget definition is placement-agnostic; this object is the placement. It
 * is built once per page render (from the HQ node, the signed-in user and the
 * global period selector) and handed to every widget on the page, which is
 * what makes "the same definition on two nodes shows two results" a property
 * of the architecture rather than a convention.
 */
class WidgetContext
{
    /**
     * @param  GraphObject|null  $node  the page's node — null on pages that
     *                                  have no node (the classic dashboard),
     *                                  where inherit_* bindings widen to the
     *                                  whole organization
     * @param  array<string, mixed>  $filters  runtime filter state (saved
     *                                         filters, drill-down narrowing),
     *                                         merged over the definition's own
     */
    public function __construct(
        public readonly User $user,
        public readonly ?GraphObject $node = null,
        public readonly ?Period $period = null,
        public readonly array $filters = [],
    ) {}

    public static function for(User $user, ?GraphObject $node = null, array $filters = []): self
    {
        return new self($user, $node, PeriodContext::current(), $filters);
    }

    public function withFilters(array $filters): self
    {
        return new self($this->user, $this->node, $this->period, array_merge($this->filters, $filters));
    }

    public function organizationId(): int
    {
        return (int) $this->user->organization_id;
    }
}
