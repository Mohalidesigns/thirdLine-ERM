<?php

namespace App\Services\Widgets;

use App\Models\WidgetDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-08 TASK 1 — executes a widget definition's declarative `query` block.
 *
 * The block is {source, filters[], group_by, aggregate, sort, limit}. Every
 * name in it is checked against WidgetSourceRegistry before it touches the
 * builder; an unknown source, column or operator throws, and the widget
 * renders its error state rather than guessing. Tenancy on `baseQuery()`
 * itself comes from the source models' own global scopes.
 *
 * `engagementsUnderNodes()` IS THE ONE EXCEPTION, AND THIS SENTENCE USED TO
 * SAY THERE WAS NONE (Gate 1, defect 2). It resolves TPRM's `engagement_functions`
 * node-scope kind through four raw `DB::table()` calls — `objects`,
 * `tp_engagement_functions`, `tp_business_functions`, `tp_engagements` — none
 * of which carries Eloquent's global scope, and each now filters
 * `organization_id` to `TenantContext::organizationId()` explicitly. Before
 * this the join was closed only because every one of those tables' primary
 * keys is a global auto-increment and the caller's `$nodeIds` were already
 * tenant-scoped — an argument about today's schema, not a guarantee, and one
 * the class's own docblock contradicted.
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
        $this->applyVisibility($query, $context);
        $this->applyPeriods($query, $definition, $periods, $source);
        $this->applyFilters($query, $definition, $context, $sourceKey);

        return $query;
    }

    /**
     * ADR 0017 record visibility, for sources whose model participates in it
     * — B1 (gate 1 code review #1): `baseQuery()` never called this, so a
     * Kano-assigned user with `bcms.plan.view` saw every branch's plans and
     * drills through a widget, the exact leak ADR 0017 exists to close on
     * every other read path. Node scope (`applyScope()`, which branch a
     * widget is PLACED ON) and record visibility (which rows THIS USER may
     * see at all) are different questions — a user assigned to nothing still
     * sees organisation-level rows on an unrestricted widget, which node
     * scope alone would not stop.
     *
     * R1 (gate 1 code review #2): the FIRST fix here re-implemented ADR 0017
     * as a second copy — `BindsToVisibleRecord::constrainToVisibleRecord()`
     * already does exactly this (both the ANCHOR and DERIVED cases, in one
     * method), and the copy had already drifted from it: it dropped the
     * named-user arm (`ExerciseOccurrence::orgVisibilityNamedUsers()` —
     * `facilitator_id`, `participants.user_id` — so a Kano facilitator of a
     * Lagos drill saw it on the calendar but not on the widget); it added a
     * `whereHas('definition', ...)` existence requirement with no "whole
     * estate" short-circuit, exactly the defect the trait's own docblock
     * names (a soft-deleted definition would have silently hidden the
     * occurrence from an `rcsa_scope.all_units` holder too); and it failed
     * OPEN (silently applied nothing) on a terminal the trait's own
     * `constrainAnchorPath()` fails LOUD on.
     *
     * `constrainToVisibleRecord()` now takes an optional `?User $user`
     * (defaulting to `Auth::user()`, so every non-widget caller is
     * unchanged) for exactly this reason — an explicit user rather than the
     * implicit authenticated one, because a widget's context user is not
     * always the request's authenticated user (a scheduled digest can
     * render somebody else's dashboard). One call handles both the ANCHOR
     * case (`Plan`) and the DERIVED case (`ExerciseOccurrence`, anchored on
     * its `definition`) — the trait itself decides which, the same way it
     * already does for route binding.
     *
     * EVERY OTHER SOURCE IS UNAFFECTED. No TPRM model, no ERM model
     * (`Risk`, `Control`, …) and `KeyRiskIndicator` use
     * `BindsToVisibleRecord` at all — `TprmWidgetTest`/
     * `TprmWidgetHqRenderTest` stay green, unedited, because
     * `method_exists()` is false for every one of their models.
     */
    private function applyVisibility(Builder $query, WidgetContext $context): void
    {
        $model = $query->getModel();

        if (method_exists($model, 'constrainToVisibleRecord')) {
            $model->constrainToVisibleRecord($query, $context->user);
        }
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
            // TPRM. The row carries an ENGAGEMENT id, and an engagement hangs
            // off the org graph through the business functions it supports.
            'engagement_functions' => $query->whereIn(
                $query->qualifyColumn($column),
                $this->engagementsUnderNodes($nodeIds),
            ),
            // TPRM. The row carries a THIRD PARTY id — an incident names a
            // provider, not one engagement — so it is in scope when any of
            // that provider's engagements is.
            'third_party_engagements' => $query->whereIn(
                $query->qualifyColumn($column),
                \Illuminate\Support\Facades\DB::table('tp_engagements')
                    ->select('third_party_id')
                    ->whereIn('id', $this->engagementsUnderNodes($nodeIds))
                    ->whereNull('deleted_at'),
            ),
            // BCMS (ADR 0021 Amendment 1). The row carries its OWN
            // `business_unit_id` directly (a plan) — in scope when that unit
            // is one of the nodes in scope, or hangs off one. Unlike TPRM's
            // engagements, a BCMS plan or drill belongs to exactly one unit,
            // so this is the same shape as `unitsUnderNodes()` reuses, not a
            // second join.
            'business_unit_ref' => $query->whereIn(
                $query->qualifyColumn($column),
                $this->unitsUnderNodes($nodeIds),
            ),
            // BCMS (ADR 0021 Amendment 1). The row carries a DEFINITION id
            // (an exercise occurrence) — in scope when that definition's own
            // `business_unit_id` is one of the nodes in scope, or hangs off
            // one. A corporate drill with no business unit on its definition
            // is unattributable and out of scope on every node — the same
            // "genuinely unattributable" rule `engagementsUnderNodes()`
            // documents for TPRM.
            'bcms_definition_units' => $query->whereIn(
                $query->qualifyColumn($column),
                \Illuminate\Support\Facades\DB::table('bcms_exercise_definitions')
                    ->select('id')
                    ->where('organization_id', TenantContext::organizationId())
                    ->whereNull('deleted_at')
                    ->whereIn('business_unit_id', $this->unitsUnderNodes($nodeIds)),
            ),
            default => $query->whereIn($query->qualifyColumn($column), $nodeIds),
        };
    }

    /**
     * The engagements that sit under a set of org nodes.
     *
     * TPRM TABLES CARRY NO `node_id`, AND DELIBERATELY SO. An engagement is
     * not owned by one part of the organisation the way a risk is: it supports
     * business FUNCTIONS, and a payments switch can serve treasury, operations
     * and the branch network at once. Denormalising a single node onto
     * `tp_engagements` would have to pick one of those, and picking wrongly is
     * how a business unit stops seeing the vendor it depends on.
     *
     * So the resolution is a join, and it has TWO limbs:
     *
     *   1. Through `tp_engagement_functions` to the function's owning business
     *      unit — the real relationship, and the one the user chose.
     *   2. Through the engagement's OWN `business_unit_id`.
     *
     * The second limb exists because without it an engagement linked to no
     * business function would be invisible on every node page while appearing
     * in the unscoped register — a row that silently disappears when somebody
     * navigates into their own unit. An engagement with neither is genuinely
     * unattributable and is out of scope on every node, which is a gap the
     * register shows rather than one this query hides.
     *
     * @param  list<int>  $nodeIds
     */
    private function engagementsUnderNodes(array $nodeIds): \Illuminate\Database\Query\Builder
    {
        $organizationId = TenantContext::organizationId();

        $unitIds = $this->unitsUnderNodes($nodeIds);

        $viaFunctions = \Illuminate\Support\Facades\DB::table('tp_engagement_functions')
            ->join(
                'tp_business_functions',
                'tp_business_functions.id',
                '=',
                'tp_engagement_functions.business_function_id'
            )
            ->select('tp_engagement_functions.engagement_id')
            ->where('tp_engagement_functions.organization_id', $organizationId)
            ->where('tp_business_functions.organization_id', $organizationId)
            ->whereIn('tp_business_functions.owning_business_unit_id', $unitIds)
            ->whereNull('tp_business_functions.deleted_at');

        return \Illuminate\Support\Facades\DB::table('tp_engagements')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($viaFunctions, $unitIds) {
                $query->whereIn('id', $viaFunctions)
                    ->orWhereIn('business_unit_id', $unitIds);
            });
    }

    /**
     * Business units whose graph object is one of the nodes in scope, or
     * hangs off one. `objects.source_model_type` is the morph alias the sync
     * service writes.
     *
     * Extracted from `engagementsUnderNodes()` (ADR 0021 Amendment 1) so
     * `applyScope()`'s `business_unit_ref`/`bcms_definition_units` arms can
     * reuse the exact same node → business-unit resolution TPRM's own join
     * already relies on, rather than a second copy that could drift from it.
     * TPRM's behaviour is unchanged by this extraction — same query, same
     * bindings, called from the same place.
     *
     * @param  list<int>  $nodeIds
     */
    private function unitsUnderNodes(array $nodeIds): \Illuminate\Database\Query\Builder
    {
        $organizationId = TenantContext::organizationId();

        return \Illuminate\Support\Facades\DB::table('objects')
            ->select('source_model_id')
            ->where('organization_id', $organizationId)
            ->where('source_model_type', 'business_unit')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($nodeIds) {
                $query->whereIn('id', $nodeIds)->orWhereIn('node_id', $nodeIds);
            });
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
            $value = $this->resolveDynamicValue($filter['value'] ?? null);

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

    /**
     * A stored filter value is literal JSON, evaluated once at seed/author
     * time — `'2026-09-23'` never becomes tomorrow. `$today` is the one
     * token this engine resolves LIVE, at render time, so a seeded "upcoming"
     * filter stays honest for ever rather than freezing on the day it was
     * written (B14, gate 1 code review #1 — `BcmsWidgetSeeder`'s drill
     * calendar had baked `date('Y-m-d')` in at seed time).
     */
    private function resolveDynamicValue(mixed $value): mixed
    {
        if ($value === '$today') {
            return now()->toDateString();
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $v === '$today' ? now()->toDateString() : $v, $value);
        }

        return $value;
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
