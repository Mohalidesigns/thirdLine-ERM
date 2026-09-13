<?php

namespace App\Http\Controllers\Risk;

use App\Grids\Column;
use App\Grids\GridExport;
use App\Grids\GridQuery;
use App\Grids\GridRegistry;
use App\Grids\GridState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grid\BulkGridActionRequest;
use App\Http\Requests\Grid\StoreGridViewRequest;
use App\Http\Requests\Grid\UpdateGridCellRequest;
use App\Models\DataGridView;
use App\Presenters\GridPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The data grid's endpoints (migration Phase 2) — what the Livewire DataGrid
 * component's methods used to be.
 *
 * Every route carries `can:view-grid,{grid}` (AppServiceProvider), which
 * resolves the definition's own permission; the write endpoints re-check
 * the action's or the definition's edit permission on top. Bulk selections
 * are re-fetched THROUGH THE GRID'S OWN QUERY, so a forged id list cannot
 * reach another tenant's rows.
 */
class GridController extends Controller
{
    public function __construct(private readonly GridPresenter $presenter) {}

    /** The presenter output as JSON, for pages that refresh a grid out of band. */
    public function show(Request $request, string $grid)
    {
        return response()->json($this->presenter->present(GridRegistry::resolve($grid), $request, $request->user()));
    }

    /** Inline edit: one declared-editable cell, one declared value. */
    public function cell(UpdateGridCellRequest $request, string $grid)
    {
        $definition = GridRegistry::resolve($grid);

        $validated = $request->validated();

        $column = $definition->column($validated['key']);

        abort_unless($column !== null && (bool) $column->editable, 422, 'That column is not editable.');
        abort_if($definition->editPermission() && ! $request->user()->can($definition->editPermission()), 403);

        $value = (string) ($validated['value'] ?? '');

        // Select-type columns only accept declared option values.
        if (is_array($column->editable) && ! array_key_exists($value, $column->editable['options'])) {
            abort(422, 'That value is not one of the declared options.');
        }

        $row = $definition->query()->whereKey($validated['id'])->first();

        abort_if($row === null, 404);

        $definition->updateCell($row, $column->key, $value);

        return response()->json([
            'ok' => true,
            'cell' => $this->presenter->cell($row->fresh(), $column, $definition),
        ]);
    }

    /** Run a bulk action over the selection (or over every matching row). */
    public function bulk(BulkGridActionRequest $request, string $grid, string $action)
    {
        $definition = GridRegistry::resolve($grid);

        $bulk = collect($definition->bulkActions())->firstWhere('key', $action);

        abort_if($bulk === null, 404);
        abort_if($bulk->permission && ! $request->user()->can($bulk->permission), 403);

        $validated = $request->validated();

        $query = $definition->query();

        if ($request->boolean('all')) {
            // "Select all N matching": the current search and filters decide
            // the set, exactly as the page shows it.
            $state = GridState::fromRequest($definition, $request, $request->user()->id);
            $query = GridQuery::apply($definition, $state->search, $state->filters, null, null);
        } else {
            $ids = $validated['ids'] ?? [];

            if ($ids === []) {
                return back()->with('error', 'Nothing selected.');
            }

            $query->whereKey($ids);
        }

        $message = ($bulk->handle)($query->get());

        return back()->with('success', $message ?: 'Done.');
    }

    /** Save the current state as a named personal view. */
    public function storeView(StoreGridViewRequest $request, string $grid)
    {
        $definition = GridRegistry::resolve($grid);

        $validated = $request->validated();

        $state = GridState::fromRequest($definition, $request, $request->user()->id);

        if ($request->boolean('as_default')) {
            DataGridView::query()
                ->where('user_id', $request->user()->id)
                ->where('grid', $definition->name())
                ->update(['is_default' => false]);
        }

        $view = DataGridView::updateOrCreate(
            ['user_id' => $request->user()->id, 'grid' => $definition->name(), 'name' => trim($validated['name'])],
            ['state' => $state->toViewState(), 'is_default' => $request->boolean('as_default')],
        );

        return back()->with('success', "View “{$view->name}” saved.")->with('gridView', $view->id);
    }

    public function destroyView(Request $request, string $grid, int $view)
    {
        GridRegistry::resolve($grid);

        DataGridView::query()
            ->where('user_id', $request->user()->id)
            ->where('grid', $grid)
            ->whereKey($view)
            ->delete();

        return back()->with('success', 'View deleted.');
    }

    /**
     * Export honours the CURRENT view of the data — search, filters, sort,
     * visible columns — and, when `ids` is given, the selection.
     */
    public function export(Request $request, string $grid, string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        $definition = GridRegistry::resolve($grid);
        $state = GridState::fromRequest($definition, $request, $request->user()->id);

        $columns = collect($definition->columns())
            ->filter(fn (Column $c) => in_array($c->key, $state->columns, true))
            ->values();

        $query = GridQuery::apply($definition, $state->search, $state->filters, $state->sort, $state->dir);

        $ids = array_filter((array) $request->query('ids', []));

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $rows = $this->flatten($query, $columns, $definition);
        $filename = $definition->name().'-'.now()->format('Ymd-His').'.'.$format;

        if ($format === 'xlsx') {
            return Excel::download(new GridExport($columns->pluck('label')->all(), $rows), $filename);
        }

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns->pluck('label')->all());
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  Collection<int, Column>  $columns
     * @return list<array<int, mixed>>
     */
    private function flatten($query, Collection $columns, $definition): array
    {
        $rows = [];

        $query->chunk(500, function ($chunk) use (&$rows, $columns, $definition) {
            foreach ($chunk as $row) {
                $rows[] = $columns->map(function (Column $column) use ($row, $definition) {
                    $value = $definition->valueOf($row, $column);

                    return match ($column->type) {
                        'money' => $value === null ? null
                            : (float) $value / ($column->typeOptions['minor'] ? 100 : 1),
                        'date', 'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
                        default => is_scalar($value) || $value === null ? $value : (string) $value,
                    };
                })->all();
            }
        });

        return $rows;
    }
}
