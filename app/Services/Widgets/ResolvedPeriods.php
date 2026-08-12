<?php

namespace App\Services\Widgets;

use App\Models\Period;

/**
 * The resolved "when" of one widget render.
 *
 * $primary is the single period a point-in-time widget reads; $window is the
 * ordered list a trend widget iterates (for point-in-time bindings it is the
 * primary alone). Both empty means "no period could be resolved" and the
 * widget renders its no-data state.
 */
class ResolvedPeriods
{
    /**
     * @param  list<Period>  $window  oldest first
     */
    public function __construct(
        public readonly ?Period $primary,
        public readonly array $window = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->primary === null && $this->window === [];
    }

    /** @return list<int> */
    public function windowIds(): array
    {
        return array_map(fn (Period $p) => (int) $p->id, $this->window);
    }

    public function label(): ?string
    {
        return $this->primary?->name ?? $this->primary?->code;
    }
}
