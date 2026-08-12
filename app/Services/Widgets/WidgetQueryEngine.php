<?php

namespace App\Services\Widgets;

use App\Models\WidgetDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * WP-08 TASK 1 — executes a widget definition's declarative `query` block.
 *
 * The block is {source, filters[], group_by, aggregate, sort, limit}. Every
 * name in it is checked against WidgetSourceRegistry before it touches the
 * builder; an unknown source, column or operator throws, and the widget
 * renders its error state rather than guessing. Tenancy comes from the source
 * models' own global scopes — there is no DB::table() entry point here.
 *
 * The engine also applies the two context dimensions:
 *   scope   WidgetScope, as a node_id IN (…) restriction (shape depends on
 *           the source's node_column_kind)
 *   periods ResolvedPeriods, as a date-column range over the window — only
 *           when the definition opted in with query.date_filter, because
 *           half this platform's numbers are current-state (an open issue is
 *           open, whichever month you select) and stamping a period onto
 *           them would be fiction. Measure-driven widgets do their period
 *           filtering on measure_values.period_id instead, in their
 *           resolvers.
 */
class WidgetQueryEngine
{
    private const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'like', 'null', 'not_null'];

    public function __construct(private readonly WidgetSourceRegistry $registry) {}

    /**
     * The scoped, filtered base query — before grouping. Resolvers that need
     * rows (register, activity_table) use this directly.
     */
    public function baseQuery(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): Builder {
        $sourceKey = (string) $definition->queryConfig('source', '');
        $source = $this->registry->source($sourceKey);

        if ($source === null) {
            throw new InvalidArgumentException("Unknown widget source [{$sourceKey}].");
        }

        /** @var Builder $query */
        $query = $source['model']::query();

        $this->applyScope($query, $scope, $source);
        $this->applyPeriods($query, $definition, $periods, $source);
        $this->applyFilters($query, $definition, $context, $sourceKey);

        return $query;
    }

    /**
     * Grouped aggregation: [{key, value}] ordered by the definition's sort.
     *
     * @return Collection<int, object{key: mixed, value: int|float}>
     */
    public function aggregate(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): Collection {
        $sourceKey = (string) $definition->queryConfig('source', '');
        $groupBy = $definition->queryConfig('group_by');

        if (! is_string($groupBy) || ! $this->registry->allowsColumn($sourceKey, $groupBy)) {
            throw new InvalidArgumentException("Widget group_by [{$groupBy}] is not an allowed column of [{$sourceKey}].");
        }

        $query = $this->baseQuery($definition, $context, $scope, $periods);

        $expression = $this->aggregateExpression($definition, $sourceKey);

        $rows = $query->toBase()
            ->selectRaw($this->quote($groupBy).' as agg_key')
            ->selectRaw($expression.' as agg_value')
            ->groupBy($groupBy)
            ->get()
            ->map(fn (object $row) => (object) ['key' => $row->agg_key, 'value' => $row->agg_value + 0]);

        return $this->sortAndLimit($rows, $definition);
    }

    /** A single scalar: COUNT by default, SUM/AVG/MIN/MAX of a whitelisted column. */
    public function scalar(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): int|float|null {
        $sourceKey = (string) $definition->queryConfig('source', '');
        $query = $this->baseQuery($definition, $context, $scope, $periods);

        $value = $query->toBase()->selectRaw($this->aggregateExpression($definition, $sourceKey).' as agg_value')->value('agg_value');

        return $value === null ? null : $value + 0;
    }

    /* ------------------------------------------------------------------ */
    /*  Context application */
    /* ------------------------------------------------------------------ */

    private function applyScope(Builder $query, WidgetScope $scope, array $source): void
    {
        if ($scope->isUnrestricted()) {
            return;
        }

        $nodeIds = $scope->nodeIds ?? [];
        $column = $source['node_column'];
        $kind = $source['node_column_kind'] ?? 'node_ref';

        match ($kind) {
            // The row IS an object: in scope when it is one of the nodes or
            // hangs off one.
            'self_or_node' => $query->where(function (Builder $q) use ($nodeIds, $column) {
                $q->whereIn($q->qualifyColumn('id'), $nodeIds)
                    ->orWhereIn($q->qualifyColumn($column), $nodeIds);
            }),
            // The row references an object (measure_breaches.object_id): in
            // scope when that object is a node in scope or hangs off one.
            'object_ref' => $query->whereIn(
                $query->qualifyColumn($column),
                \App\Models\GraphObject::query()->toBase()
                    ->select('id')
                    ->where(function ($q) use ($nodeIds) {
                        $q->whereIn('id', $nodeIds)->orWhereIn('node_id', $nodeIds);
                    }),
            ),
            default => $query->whereIn($query->qualifyColumn($column), $nodeIds),
        };
    }

    private function applyPeriods(Builder $query, WidgetDefinition $definition, ResolvedPeriods $periods, array $source): void
    {
        if (! $definition->queryConfig('date_filter', false) || $periods->window === []) {
            return;
        }

        $column = $source['date_column'];
        $start = collect($periods->window)->min(fn ($p) => $p->start_date);
        $end = collect($periods->window)->max(fn ($p) => $p->end_date);

        $query->whereBetween($query->qualifyColumn($column), [$start, $end]);
    }

    private function applyFilters(Builder $query, WidgetDefinition $definition, WidgetContext $context, string $sourceKey): void
    {
        $declared = $definition->queryConfig('filters', []);
        $declared = is_array($declared) ? $declared : [];

        // Runtime filters (saved filters, drill-down narrowing) arrive as
        // {column: value} and are normalised onto the same declarative shape,
        // so they pass through the same whitelist.
        $runtime = collect($context->filters)
            ->map(fn ($value, $field) => is_array($value)
                ? ['field' => $field, 'op' => 'in', 'value' => $value]
                : ['field' => $field, 'op' => 'eq', 'value' => $value])
            ->values()
            ->all();

        foreach (array_merge($declared, $runtime) as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $op = (string) ($filter['op'] ?? 'eq');
            $value = $filter['value'] ?? null;

            if (! $this->registry->allowsColumn($sourceKey, $field)) {
                throw new InvalidArgumentException("Widget filter column [{$field}] is not allowed on [{$sourceKey}].");
            }

            if (! in_array($op, self::OPERATORS, true)) {
                throw new InvalidArgumentException("Widget filter operator [{$op}] is not allowed.");
            }

            $column = $query->qualifyColumn($field);

            match ($op) {
                'eq' => $query->where($column, '=', $value),
                'neq' => $query->where($column, '!=', $value),
                'in' => $query->whereIn($column, is_array($value) ? $value : [$value]),
                'not_in' => $query->whereNotIn($column, is_array($value) ? $value : [$value]),
                'gt' => $query->where($column, '>', $value),
                'gte' => $query->where($column, '>=', $value),
                'lt' => $query->where($column, '<', $value),
                'lte' => $query->where($column, '<=', $value),
                'between' => $query->whereBetween($column, [
                    is_array($value) ? ($value[0] ?? null) : null,
                    is_array($value) ? ($value[1] ?? null) : null,
                ]),
                // Value is bound, never interpolated; the % lives in the value.
                'like' => $query->where($column, 'like', '%'.trim((string) $value, '%').'%'),
                'null' => $query->whereNull($column),
                'not_null' => $query->whereNotNull($column),
            };
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Aggregation vocabulary */
    /* ------------------------------------------------------------------ */

    private function aggregateExpression(WidgetDefinition $definition, string $sourceKey): string
    {
        $aggregate = $definition->queryConfig('aggregate', []);
        $fn = strtolower((string) ($aggregate['fn'] ?? 'count'));
        $field = $aggregate['field'] ?? null;

        if ($fn === 'count') {
            return 'count(*)';
        }

        if (! in_array($fn, ['sum', 'avg', 'min', 'max'], true)) {
            throw new InvalidArgumentException("Widget aggregate [{$fn}] is not allowed.");
        }

        if (! is_string($field) || ! $this->registry->allowsSum($sourceKey, $field)) {
            throw new InvalidArgumentException("Widget aggregate column [{$field}] is not allowed on [{$sourceKey}].");
        }

        return $fn.'('.$this->quote($field).')';
    }

    /**
     * @param  Collection<int, object{key: mixed, value: int|float}>  $rows
     * @return Collection<int, object{key: mixed, value: int|float}>
     */
    private function sortAndLimit(Collection $rows, WidgetDefinition $definition): Collection
    {
        $sort = $definition->queryConfig('sort', []);
        $by = (string) ($sort['by'] ?? 'value');
        $dir = strtolower((string) ($sort['dir'] ?? 'desc'));

        $sorted = $by === 'key'
            ? $rows->sortBy(fn (object $r) => $r->key, SORT_NATURAL)
            : $rows->sortBy(fn (object $r) => $r->value, SORT_REGULAR);

        if ($dir === 'desc') {
            $sorted = $sorted->reverse();
        }

        $limit = (int) $definition->queryConfig('limit', 0);

        return ($limit > 0 ? $sorted->take($limit) : $sorted)->values();
    }

    /**
     * Column names here have already passed the registry whitelist, which only
     * contains [a-z0-9_] identifiers — the assertion keeps that invariant
     * honest at the last point before raw SQL.
     */
    private function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe identifier [{$identifier}].");
        }

        return $identifier;
    }
}
