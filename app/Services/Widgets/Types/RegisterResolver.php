<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetSourceRegistry;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * register — the paginated table: search, sort, per-row link, inline "+".
 *
 * Columns come from query.columns, checked against the source whitelist; the
 * payload carries values keyed by column so the Blade side renders headings
 * from the same list and cannot drift from the data. Pagination is
 * page/per_page from the runtime filters so the Livewire panel can page
 * without a bespoke endpoint per source.
 */
class RegisterResolver implements WidgetTypeResolver
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly WidgetSourceRegistry $registry,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $sourceKey = (string) $definition->queryConfig('source', '');
        $source = $this->registry->source($sourceKey);

        if ($source === null || $scope->isEmpty()) {
            return ['columns' => [], 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 10];
        }

        // Search and pagination arrive as runtime filters but are register
        // mechanics, not data filters — lift them out before the engine sees
        // the rest.
        $filters = $context->filters;
        $search = trim((string) ($filters['_search'] ?? ''));
        $page = max(1, (int) ($filters['_page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['_per_page'] ?? (int) $definition->queryConfig('limit', 10))));
        unset($filters['_search'], $filters['_page'], $filters['_per_page']);

        $query = $this->engine->baseQuery(
            $definition,
            new WidgetContext($context->user, $context->node, $context->period, $filters),
            $scope,
            $periods,
        );

        if ($search !== '') {
            $code = $source['code_column'];
            $label = $source['label_column'];
            $query->where(function ($q) use ($search, $code, $label) {
                $q->where($q->qualifyColumn($label), 'like', '%'.$search.'%')
                    ->orWhere($q->qualifyColumn($code), 'like', '%'.$search.'%');
            });
        }

        $columns = $this->columns($definition, $sourceKey, $source);

        $sort = $definition->queryConfig('sort', []);
        $sortBy = is_string($sort['by'] ?? null) && $this->registry->allowsColumn($sourceKey, $sort['by'])
            ? $sort['by']
            : $source['code_column'];
        $sortDir = strtolower((string) ($sort['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->toBase()->count();

        $rows = $query
            ->orderBy($query->qualifyColumn($sortBy), $sortDir)
            ->forPage($page, $perPage)
            ->get()
            ->map(function ($model) use ($columns) {
                $row = ['id' => $model->getKey()];
                foreach ($columns as $column) {
                    $value = $model->getAttribute($column['key']);
                    $row[$column['key']] = $value instanceof \DateTimeInterface
                        ? $value->format('Y-m-d')
                        : $value;
                }

                return $row;
            })
            ->values()
            ->all();

        return [
            'source' => $sourceKey,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(WidgetDefinition $definition, string $sourceKey, array $source): array
    {
        $requested = $definition->queryConfig('columns', []);

        $keys = collect(is_array($requested) ? $requested : [])
            ->filter(fn ($c) => is_string($c) && $this->registry->allowsColumn($sourceKey, $c))
            ->values();

        if ($keys->isEmpty()) {
            $keys = collect([$source['code_column'], $source['label_column']])
                ->filter(fn ($c) => $this->registry->allowsColumn($sourceKey, $c))
                ->values();
        }

        return $keys
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => (string) str($key)->replace('_', ' ')->title(),
            ])
            ->all();
    }
}
