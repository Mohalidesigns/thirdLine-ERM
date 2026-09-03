<?php

namespace App\Presenters;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\GridQuery;
use App\Grids\GridState;
use App\Grids\RowAction;
use App\Models\DataGridView;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Turns a GridDefinition plus the request's grid state into the props the
 * React DataGrid renders (migration Phase 2).
 *
 * Cells are shaped here, server-side, by the column's declared type — the
 * same rules resources/views/components/data-grid-cell.blade.php applied —
 * so the client never sees a closure, never formats money, and never decides
 * which rows exist. Row and bulk actions are permission-filtered per user.
 */
class GridPresenter
{
    private const RAG_CLASSES = [
        'red' => 'bg-red-100 text-red-700',
        'amber' => 'bg-yellow-100 text-yellow-700',
        'green' => 'bg-green-100 text-green-700',
        'neutral' => 'bg-gray-100 text-gray-600',
    ];

    /**
     * @return array<string, mixed>
     */
    public function present(GridDefinition $definition, Request $request, User $user): array
    {
        $state = GridState::fromRequest($definition, $request, $user->id);

        $paginator = GridQuery::apply($definition, $state->search, $state->filters, $state->sort, $state->dir)
            ->paginate($state->perPage, ['*'], 'page', $state->page)
            ->withQueryString();

        $columns = collect($definition->columns());
        $visible = $columns->filter(fn (Column $c) => in_array($c->key, $state->columns, true))->values();

        $rowActions = collect($definition->rowActions())
            ->filter(fn (RowAction $a) => ! $a->permission || $user->can($a->permission))
            ->values();

        $bulkActions = collect($definition->bulkActions())
            ->filter(fn (BulkAction $a) => ! $a->permission || $user->can($a->permission))
            ->values();

        $canEdit = $definition->editPermission() === null || $user->can($definition->editPermission());

        return [
            'name' => $definition->name(),
            'state' => [
                'search' => $state->search,
                'filters' => (object) $state->filters,
                'sort' => $state->sort,
                'dir' => $state->dir,
                'perPage' => $state->perPage,
                'columns' => $state->columns,
                'viewId' => $state->viewId,
            ],
            'columns' => $columns->map(fn (Column $c) => [
                'key' => $c->key,
                'label' => $c->label,
                'type' => $c->type,
                'sortable' => $c->sortable,
                'searchable' => $c->searchable,
                'visibleByDefault' => $c->visibleByDefault,
                'editable' => $this->editableMeta($c, $canEdit),
                'link' => $c->linkTo !== null,
            ])->values()->all(),
            'filters' => collect($definition->filters())->map(fn (Filter $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'options' => collect($f->resolveOptions())
                    ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])
                    ->values()
                    ->all(),
                'value' => $state->filters[$f->key] ?? '',
            ])->values()->all(),
            'perPageOptions' => $definition->perPageOptions(),
            'rows' => [
                'data' => collect($paginator->items())
                    ->map(fn (Model $row) => $this->row($row, $visible, $definition, $rowActions))
                    ->values()
                    ->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
            ],
            'selection' => ['mode' => $bulkActions->isNotEmpty() ? 'multiple' : 'none'],
            'rowActions' => $rowActions->map(fn (RowAction $a) => ['label' => $a->label, 'icon' => $a->icon])->all(),
            'bulkActions' => $bulkActions->map(fn (BulkAction $a) => [
                'key' => $a->key,
                'label' => $a->label,
                'icon' => $a->icon,
                'confirm' => $a->confirm,
            ])->values()->all(),
            'views' => $this->views($definition, $user),
            'canEdit' => $canEdit,
            'canExport' => ['csv' => true, 'xlsx' => true],
            'urls' => [
                'reload' => route('risk.grids.show', $definition->name()),
                'cell' => route('risk.grids.cell', $definition->name()),
                'bulk' => route('risk.grids.bulk', [$definition->name(), '__action__']),
                'views' => route('risk.grids.views.store', $definition->name()),
                'view' => route('risk.grids.views.destroy', [$definition->name(), '__view__']),
                'csv' => route('risk.grids.export', [$definition->name(), 'csv'] + $state->toQuery()),
                'xlsx' => route('risk.grids.export', [$definition->name(), 'xlsx'] + $state->toQuery()),
            ],
            'emptyMessage' => $definition->emptyMessage(),
            'emptyIcon' => $definition->emptyIcon(),
        ];
    }

    /**
     * One row: id, a cell per visible column, the primary link and the
     * permission-filtered row actions.
     *
     * @param  \Illuminate\Support\Collection<int, Column>  $visible
     * @param  \Illuminate\Support\Collection<int, RowAction>  $rowActions
     * @return array<string, mixed>
     */
    public function row(Model $row, $visible, GridDefinition $definition, $rowActions): array
    {
        $primary = $visible->first(fn (Column $c) => $c->linkTo);

        return [
            'id' => (string) $row->getKey(),
            'href' => $primary ? ($primary->linkTo)($row) : null,
            'cells' => $visible->mapWithKeys(fn (Column $c) => [$c->key => $this->cell($row, $c, $definition)])->all(),
            'actions' => $rowActions->map(fn (RowAction $a) => [
                'label' => $a->label,
                'icon' => $a->icon,
                'url' => ($a->url)($row),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cell(Model $row, Column $column, GridDefinition $definition): array
    {
        $value = $definition->valueOf($row, $column);
        $cell = ['text' => null];

        if ($column->linkTo) {
            $cell['href'] = ($column->linkTo)($row);
        }

        switch ($column->type) {
            case 'badge':
                $map = $column->typeOptions['map'];
                $cell['text'] = self::humanise($value);
                $cell['class'] = $map[$value] ?? $map['*'] ?? 'bg-gray-100 text-gray-600';
                break;

            case 'rag':
                $level = $column->typeOptions['map'][$value] ?? 'neutral';
                $cell['text'] = self::humanise($value);
                $cell['class'] = self::RAG_CLASSES[$level] ?? self::RAG_CLASSES['neutral'];
                $cell['level'] = $level;
                break;

            case 'date':
            case 'datetime':
                $cell['text'] = $value instanceof \DateTimeInterface
                    ? $value->format($column->typeOptions['format'])
                    : ($value === null ? null : (string) $value);
                break;

            case 'money':
                $cell['text'] = $value === null
                    ? null
                    : '₦'.number_format((float) $value / ($column->typeOptions['minor'] ? 100 : 1), 2);
                break;

            case 'progress':
                $cell['pct'] = max(0, min(100, (int) $value));
                $cell['text'] = $cell['pct'].'%';
                break;

            case 'count':
                $cell['text'] = (string) ($value ?? 0);
                break;

            default:
                $cell['text'] = $value === null ? null : (string) $value;
        }

        if ($column->editable) {
            // The value the inline editor starts from, not the display text.
            $cell['raw'] = is_scalar($value) || $value === null ? $value : (string) $value;
        }

        return $cell;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function editableMeta(Column $column, bool $canEdit): ?array
    {
        if (! $column->editable || ! $canEdit) {
            return null;
        }

        if (is_array($column->editable)) {
            return [
                'type' => 'select',
                'options' => collect($column->editable['options'])
                    ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])
                    ->values()
                    ->all(),
            ];
        }

        return ['type' => 'text'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function views(GridDefinition $definition, User $user): array
    {
        return DataGridView::query()
            ->where('user_id', $user->id)
            ->where('grid', $definition->name())
            ->orderBy('name')
            ->get()
            ->map(fn (DataGridView $view) => [
                'id' => $view->id,
                'name' => $view->name,
                'isDefault' => (bool) $view->is_default,
                'state' => $view->state,
            ])
            ->values()
            ->all();
    }

    private static function humanise(mixed $value): string
    {
        return ucfirst(str_replace('_', ' ', (string) ($value ?? '—')));
    }
}
