<?php

namespace App\Grids;

use App\Models\DataGridView;
use Illuminate\Http\Request;

/**
 * The request-side state of one grid: what the toolbar has set, resolved
 * against the definition's defaults and the user's default saved view.
 *
 * Shape-compatible with what the Livewire grid stored in
 * data_grid_views.state (search, filters, sort, dir, perPage, columns), so
 * a view saved before Phase 2 applies unchanged.
 */
final class GridState
{
    /**
     * @param  array<string, string>  $filters
     * @param  list<string>  $columns
     */
    public function __construct(
        public readonly string $search,
        public readonly array $filters,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $perPage,
        public readonly array $columns,
        public readonly ?int $viewId,
        public readonly int $page,
    ) {}

    public static function fromRequest(GridDefinition $definition, Request $request, int $userId): self
    {
        $known = collect($definition->columns())->pluck('key')->all();
        [$defaultSort, $defaultDir] = $definition->defaultSort();
        $defaultColumns = collect($definition->columns())
            ->filter(fn (Column $c) => $c->visibleByDefault)
            ->pluck('key')
            ->all();

        $search = trim((string) $request->query('search', ''));
        $filters = self::cleanFilters($definition, (array) $request->query('filters', []));
        $sort = (string) $request->query('sort', '');
        $dir = (string) $request->query('dir', '');
        $perPage = (int) $request->query('per_page', 0);
        $columns = self::cleanColumns($known, $request->query('columns'));
        $viewId = $request->filled('view') ? (int) $request->query('view') : null;

        // A named view wins; otherwise, a request carrying no grid state at
        // all opens on the user's default view — the same rule the Livewire
        // grid applied on mount.
        $view = null;

        if ($viewId !== null) {
            $view = DataGridView::query()
                ->where('user_id', $userId)
                ->where('grid', $definition->name())
                ->find($viewId);
        } elseif ($search === '' && $filters === [] && $sort === '' && $columns === [] && $perPage === 0) {
            $view = DataGridView::query()
                ->where('user_id', $userId)
                ->where('grid', $definition->name())
                ->where('is_default', true)
                ->first();
        }

        // A view id that is not this user's on this grid is not applied — and
        // not echoed back either, or the client would believe it was.
        if ($view === null) {
            $viewId = null;
        }

        if ($view !== null) {
            $state = (array) $view->state;

            $search = $search !== '' ? $search : (string) ($state['search'] ?? '');
            $filters = $filters !== [] ? $filters : self::cleanFilters($definition, (array) ($state['filters'] ?? []));
            $sort = $sort !== '' ? $sort : (string) ($state['sort'] ?? '');
            $dir = $dir !== '' ? $dir : (string) ($state['dir'] ?? '');
            $perPage = $perPage > 0 ? $perPage : (int) ($state['perPage'] ?? 0);
            $columns = $columns !== [] ? $columns : self::cleanColumns($known, $state['columns'] ?? null);
            $viewId = $view->id;
        }

        if (! $definition->column($sort)?->sortable) {
            $sort = $defaultSort;
            $dir = $dir !== '' ? $dir : $defaultDir;
        }

        return new self(
            search: $search,
            filters: $filters,
            sort: $sort,
            dir: in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir,
            perPage: $perPage > 0 ? max(1, min(200, $perPage)) : $definition->perPageOptions()[0],
            columns: $columns !== [] ? $columns : $defaultColumns,
            viewId: $viewId,
            page: max(1, (int) $request->query('page', 1)),
        );
    }

    /**
     * The saved-view payload — identical to what the Livewire grid wrote.
     *
     * @return array{search: string, filters: array<string, string>, sort: string, dir: string, perPage: int, columns: list<string>}
     */
    public function toViewState(): array
    {
        return [
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'perPage' => $this->perPage,
            'columns' => $this->columns,
        ];
    }

    /**
     * The query parameters that reproduce this state, for export links and
     * the client's URL.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'per_page' => $this->perPage,
            'columns' => implode(',', $this->columns),
            'view' => $this->viewId,
        ], fn ($v) => $v !== '' && $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private static function cleanFilters(GridDefinition $definition, array $filters): array
    {
        $declared = collect($definition->filters())->pluck('key')->flip();
        $clean = [];

        foreach ($filters as $key => $value) {
            if ($declared->has($key) && is_scalar($value) && (string) $value !== '') {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function cleanColumns(array $known, mixed $requested): array
    {
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }

        if (! is_array($requested)) {
            return [];
        }

        // Keep definition order regardless of the order requested.
        return array_values(array_intersect($known, array_map('strval', $requested)));
    }
}
