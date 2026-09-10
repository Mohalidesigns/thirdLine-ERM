<?php

namespace App\Grids;

use Illuminate\Database\Eloquent\Builder;

/**
 * The one query pipeline every grid consumer shares: definition query →
 * search → declared filters → declared sort.
 *
 * Extracted from the Livewire DataGrid in migration Phase 2 so the React
 * grid's presenter and the Livewire component (until it is deleted) build
 * the same SQL from the same state. Inputs are whitelisted against the
 * definition — an unknown filter key, an undeclared filter value or an
 * unsortable column is ignored, never passed into SQL.
 */
final class GridQuery
{
    /**
     * @param  array<string, mixed>  $filters  filter key => selected value
     */
    public static function apply(
        GridDefinition $definition,
        string $search,
        array $filters,
        ?string $sort,
        ?string $dir,
    ): Builder {
        $query = $definition->query();

        $searchable = collect($definition->columns())->filter(fn (Column $c) => $c->searchable);
        $term = trim($search);

        if ($term !== '' && $searchable->isNotEmpty()) {
            $query->where(function (Builder $q) use ($searchable, $term) {
                foreach ($searchable as $column) {
                    $q->orWhere($column->orderByColumn(), 'like', "%{$term}%");
                }
            });
        }

        $declared = collect($definition->filters())->keyBy('key');

        foreach ($filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $filter = $declared->get($key);

            if ($filter && array_key_exists((string) $value, $filter->resolveOptions())) {
                $filter->applyTo($query, (string) $value);
            }
        }

        $sortColumn = $sort === null ? null : $definition->column($sort);

        if ($sortColumn?->sortable) {
            $query->orderBy($sortColumn->orderByColumn(), $dir === 'asc' ? 'asc' : 'desc');
        }

        return $query;
    }
}
