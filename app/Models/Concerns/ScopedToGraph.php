<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds Model::visibleTo($user) to models that hang off the organizational
 * graph via entity_id.
 *
 * Deliberately an opt-in query scope rather than a global one: node scoping
 * answers "what may this person see", which is a question about a caller, and
 * a global scope would silently narrow background jobs and roll-up
 * calculations that must run across the whole graph.
 */
trait ScopedToGraph
{
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        return GraphScope::apply(
            $query,
            $user ?? auth()->user(),
            $this->getGraphEntityColumn()
        );
    }

    public function getGraphEntityColumn(): string
    {
        return 'entity_id';
    }
}
