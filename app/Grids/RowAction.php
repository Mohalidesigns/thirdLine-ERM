<?php

namespace App\Grids;

use Closure;

/**
 * One entry in a row's ⋮ menu. Navigation only — anything destructive or
 * state-changing belongs in a BulkAction (which confirms and re-checks
 * permissions server-side) or on the record's own page.
 */
class RowAction
{
    public string $label;
    public string $icon;

    /** @var Closure(mixed): string */
    public Closure $url;

    public ?string $permission = null;

    public static function make(string $label, string $icon, Closure $url): self
    {
        $action = new self();
        $action->label = $label;
        $action->icon = $icon;
        $action->url = $url;

        return $action;
    }

    public function can(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }
}
