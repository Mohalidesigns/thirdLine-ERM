<?php

namespace App\Livewire;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\GridDefinition;
use App\Grids\GridQuery;
use App\Grids\GridRegistry;
use App\Models\DataGridView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WP-09 TASK 2: the shared data grid.
 *
 * One Livewire component drives every register/index table in the app.
 * Everything entity-specific lives in a GridDefinition (see app/Grids);
 * this class owns the behaviour: server-side search/filter/sort/paginate,
 * a column chooser, saved views per user, bulk actions, inline edit, row
 * selection, CSV/XLSX export, and the empty/loading states.
 *
 * Security posture: the definition's base query is tenant-scoped at the
 * source, every Livewire update re-checks the definition's permission
 * (route middleware does not run on component updates), sort/filter/search
 * inputs are whitelisted against declared columns, and bulk selections are
 * re-fetched through the grid's own query so a forged id list cannot reach
 * another tenant's rows.
 */
class DataGrid extends Component
{
    use WithPagination;

    #[Locked]
    public string $grid;

    #[Url(except: '')]
    public string $search = '';

    /** @var array<string, string> filter key => selected value */
    #[Url(except: [])]
    public array $filters = [];

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: '')]
    public string $dir = '';

    public int $perPage = 0;

    /** @var string[] visible column keys, in definition order */
    public array $columns = [];

    /** @var array<int|string> selected row ids on the current page (and beyond) */
    public array $selected = [];

    public bool $selectingAll = false;

    /** Inline edit state: "rowId:columnKey" or null. */
    public ?string $editing = null;

    public string $editValue = '';

    public ?int $currentViewId = null;

    public string $newViewName = '';

    /* ------------------------------------------------------------ setup */

    /**
     * @param  array<string, string>  $initialFilters  pre-applied filter values
     *                                                 (e.g. a register that opens on "active" rows); the user can
     *                                                 still clear them. URL state wins over the initial value.
     */
    public function mount(string $grid, array $initialFilters = []): void
    {
        $this->grid = $grid;
        $definition = $this->definition();

        abort_unless(auth()->user()?->can($definition->permission()), 403);

        if ($this->filters === [] && $initialFilters !== []) {
            $this->filters = $initialFilters;
        }

        [$sort, $dir] = $definition->defaultSort();
        $this->sort = $this->sort ?: $sort;
        $this->dir = in_array($this->dir, ['asc', 'desc'], true) ? $this->dir : $dir;
        $this->perPage = $this->perPage ?: $definition->perPageOptions()[0];

        if ($this->columns === []) {
            $this->columns = collect($definition->columns())
                ->filter(fn (Column $c) => $c->visibleByDefault)
                ->pluck('key')
                ->all();
        }

        $default = $this->savedViews()->firstWhere('is_default', true);
        if ($default && $this->search === '' && $this->filters === []) {
            $this->applyView($default->id);
        }
    }

    public function definition(): GridDefinition
    {
        return GridRegistry::resolve($this->grid);
    }

    /**
     * Livewire updates bypass route middleware, so the page-level permission
     * gate is re-asserted before anything the component does.
     */
    public function hydrate(): void
    {
        abort_unless(auth()->user()?->can($this->definition()->permission()), 403);
    }

    /* ------------------------------------------------------------ query */

    protected function baseQuery(): Builder
    {
        // Migration Phase 2: the pipeline lives in GridQuery so the React
        // grid's presenter and this component build identical SQL.
        return GridQuery::apply($this->definition(), $this->search, $this->filters, $this->sort, $this->dir);
    }
    /* ----------------------------------------------------------- events */

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->resetSelection();
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
        $this->resetSelection();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(200, $this->perPage));
        $this->resetPage();
    }

    public function sortBy(string $key): void
    {
        if (! $this->definition()->column($key)?->sortable) {
            return;
        }

        if ($this->sort === $key) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $key;
            $this->dir = 'asc';
        }

        $this->resetPage();
    }

    public function toggleColumn(string $key): void
    {
        $definition = $this->definition();
        if (! $definition->column($key)) {
            return;
        }

        if (in_array($key, $this->columns, true)) {
            if (count($this->columns) > 1) {
                $this->columns = array_values(array_diff($this->columns, [$key]));
            }

            return;
        }

        // Keep definition order regardless of toggle order.
        $order = collect($definition->columns())->pluck('key')->all();
        $visible = [...$this->columns, $key];
        $this->columns = array_values(array_intersect($order, $visible));
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filters = [];
        $this->currentViewId = null;
        $this->resetPage();
        $this->resetSelection();
    }

    /* -------------------------------------------------------- selection */

    public function toggleRow(string $id): void
    {
        $this->selectingAll = false;

        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
        } else {
            $this->selected[] = $id;
        }
    }

    public function togglePage(array $pageIds): void
    {
        $pageIds = array_map('strval', $pageIds);
        $allSelected = array_diff($pageIds, $this->selected) === [];

        $this->selectingAll = false;
        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $pageIds))
            : array_values(array_unique([...$this->selected, ...$pageIds]));
    }

    public function selectAllMatching(): void
    {
        $this->selectingAll = true;
        $this->selected = $this->baseQuery()->pluck($this->baseQuery()->getModel()->getKeyName())
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function resetSelection(): void
    {
        $this->selected = [];
        $this->selectingAll = false;
    }

    /* ----------------------------------------------------- bulk actions */

    public function runBulk(string $key): void
    {
        $definition = $this->definition();

        /** @var BulkAction|null $action */
        $action = collect($definition->bulkActions())->firstWhere('key', $key);
        if (! $action || $this->selected === []) {
            return;
        }

        abort_if($action->permission && ! auth()->user()->can($action->permission), 403);

        // Re-fetch through the grid's own query: forged ids from another
        // organization simply do not come back.
        $rows = $definition->query()
            ->whereKey($this->selected)
            ->get();

        $message = ($action->handle)($rows);

        $this->resetSelection();
        $this->resetPage();

        if ($message) {
            session()->flash('success', $message);
        }
    }

    /* ------------------------------------------------------ inline edit */

    public function startEdit(string $rowId, string $key): void
    {
        $definition = $this->definition();
        $column = $definition->column($key);

        if (! $column?->editable) {
            return;
        }
        if ($definition->editPermission() && ! auth()->user()->can($definition->editPermission())) {
            return;
        }

        $row = $definition->query()->whereKey($rowId)->first();
        if (! $row) {
            return;
        }

        $this->editing = "{$rowId}:{$key}";
        $this->editValue = (string) ($definition->valueOf($row, $column) ?? '');
    }

    public function saveEdit(): void
    {
        if (! $this->editing) {
            return;
        }

        [$rowId, $key] = explode(':', $this->editing, 2);
        $definition = $this->definition();
        $column = $definition->column($key);

        if (! $column?->editable) {
            $this->cancelEdit();

            return;
        }
        if ($definition->editPermission() && ! auth()->user()->can($definition->editPermission())) {
            $this->cancelEdit();

            return;
        }

        // Select-type columns only accept declared option values.
        if (is_array($column->editable)
            && ! array_key_exists($this->editValue, $column->editable['options'])) {
            $this->cancelEdit();

            return;
        }

        $row = $definition->query()->whereKey($rowId)->first();
        if ($row) {
            $definition->updateCell($row, $key, $this->editValue);
        }

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editValue = '';
    }

    /* ------------------------------------------------------ saved views */

    public function savedViews(): Collection
    {
        return DataGridView::where('user_id', auth()->id())
            ->where('grid', $this->grid)
            ->orderBy('name')
            ->get();
    }

    public function saveView(bool $asDefault = false): void
    {
        $name = trim($this->newViewName);
        if ($name === '') {
            return;
        }

        if ($asDefault) {
            DataGridView::where('user_id', auth()->id())
                ->where('grid', $this->grid)
                ->update(['is_default' => false]);
        }

        $view = DataGridView::updateOrCreate(
            ['user_id' => auth()->id(), 'grid' => $this->grid, 'name' => $name],
            [
                'state' => [
                    'search' => $this->search,
                    'filters' => $this->filters,
                    'sort' => $this->sort,
                    'dir' => $this->dir,
                    'perPage' => $this->perPage,
                    'columns' => $this->columns,
                ],
                'is_default' => $asDefault,
            ]
        );

        $this->currentViewId = $view->id;
        $this->newViewName = '';
    }

    public function applyView(int $viewId): void
    {
        $view = DataGridView::where('user_id', auth()->id())
            ->where('grid', $this->grid)
            ->find($viewId);

        if (! $view) {
            return;
        }

        $definition = $this->definition();
        $state = $view->state;

        $this->search = (string) ($state['search'] ?? '');
        $this->filters = (array) ($state['filters'] ?? []);
        $this->dir = in_array($state['dir'] ?? '', ['asc', 'desc'], true) ? $state['dir'] : $this->dir;
        $this->perPage = (int) ($state['perPage'] ?? $this->perPage);

        $sort = (string) ($state['sort'] ?? '');
        if ($definition->column($sort)?->sortable) {
            $this->sort = $sort;
        }

        $known = collect($definition->columns())->pluck('key')->all();
        $columns = array_values(array_intersect($known, (array) ($state['columns'] ?? [])));
        if ($columns !== []) {
            $this->columns = $columns;
        }

        $this->currentViewId = $view->id;
        $this->resetPage();
        $this->resetSelection();
    }

    public function deleteView(int $viewId): void
    {
        DataGridView::where('user_id', auth()->id())
            ->where('grid', $this->grid)
            ->whereKey($viewId)
            ->delete();

        if ($this->currentViewId === $viewId) {
            $this->currentViewId = null;
        }
    }

    /* ----------------------------------------------------------- export */

    public function exportCsv(): StreamedResponse
    {
        [$columns, $rows] = $this->exportData();

        $filename = $this->grid.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns->pluck('label')->all());
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportXlsx(): mixed
    {
        [$columns, $rows] = $this->exportData();

        $filename = $this->grid.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new \App\Grids\GridExport($columns->pluck('label')->all(), $rows),
            $filename
        );
    }

    /**
     * Export honours the CURRENT view of the data — search, filters, sort,
     * visible columns — and the current selection when one exists.
     *
     * @return array{0: Collection<int, Column>, 1: array<int, array>}
     */
    protected function exportData(): array
    {
        $definition = $this->definition();

        $columns = collect($definition->columns())
            ->filter(fn (Column $c) => in_array($c->key, $this->columns, true))
            ->values();

        $query = $this->baseQuery();
        if ($this->selected !== []) {
            $query->whereKey($this->selected);
        }

        $rows = [];
        $query->chunk(500, function ($chunk) use (&$rows, $columns, $definition) {
            foreach ($chunk as $row) {
                $rows[] = $columns->map(function (Column $column) use ($row, $definition) {
                    $value = $definition->valueOf($row, $column);

                    return match ($column->type) {
                        'money' => $value === null ? null
                            : (float) $value / ($column->typeOptions['minor'] ? 100 : 1),
                        'date', 'datetime' => $value?->format('Y-m-d H:i:s'),
                        default => is_scalar($value) || $value === null ? $value : (string) $value,
                    };
                })->all();
            }
        });

        return [$columns, $rows];
    }

    /* ----------------------------------------------------------- render */

    public function render()
    {
        $definition = $this->definition();

        $rows = $this->baseQuery()->paginate($this->perPage);

        $visibleColumns = collect($definition->columns())
            ->filter(fn (Column $c) => in_array($c->key, $this->columns, true))
            ->values();

        return view('livewire.data-grid', [
            'definition' => $definition,
            'rows' => $rows,
            'visibleColumns' => $visibleColumns,
            'allColumns' => collect($definition->columns()),
            'gridFilters' => collect($definition->filters()),
            'rowActions' => collect($definition->rowActions())
                ->filter(fn ($a) => ! $a->permission || auth()->user()->can($a->permission))
                ->values(),
            'bulkActions' => collect($definition->bulkActions())
                ->filter(fn ($a) => ! $a->permission || auth()->user()->can($a->permission))
                ->values(),
            'views' => $this->savedViews(),
        ]);
    }
}
