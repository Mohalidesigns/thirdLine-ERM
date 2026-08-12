<?php

namespace App\Grids;

use Closure;
use Illuminate\Support\Collection;

/**
 * An operation over the selected rows. The handler receives the selected
 * models re-fetched THROUGH THE GRID'S OWN QUERY, so tenancy and any
 * definition-level scoping hold no matter what ids the client submits.
 */
class BulkAction
{
    public string $key;
    public string $label;
    public string $icon;

    /** @var Closure(Collection): ?string handler; may return a flash message */
    public Closure $handle;

    public ?string $permission = null;
    public ?string $confirm = null;

    public static function make(string $key, string $label, string $icon, Closure $handle): self
    {
        $action = new self();
        $action->key = $key;
        $action->label = $label;
        $action->icon = $icon;
        $action->handle = $handle;

        return $action;
    }

    public function can(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function confirm(string $message): self
    {
        $this->confirm = $message;

        return $this;
    }
}
