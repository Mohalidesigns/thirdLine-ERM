<?php

namespace App\Grids;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything entity-specific about one grid: the base query (tenant-scoped
 * at the source), the columns, the toolbar filters, and the actions.
 * GridPresenter (shaping) and GridController (endpoints) own all behaviour
 * — definitions hold no request state and are cheap to construct per
 * request.
 */
abstract class GridDefinition
{
    /** Registry name; also the saved-view namespace and export file stem. */
    abstract public function name(): string;

    /** Base query. MUST already be organization-scoped. */
    abstract public function query(): Builder;

    /** @return Column[] */
    abstract public function columns(): array;

    /** @return Filter[] */
    public function filters(): array
    {
        return [];
    }

    /** @return RowAction[] */
    public function rowActions(): array
    {
        return [];
    }

    /** @return BulkAction[] */
    public function bulkActions(): array
    {
        return [];
    }

    /** ['column', 'asc'|'desc'] — must name a sortable column key. */
    public function defaultSort(): array
    {
        $first = collect($this->columns())->first(fn (Column $c) => $c->sortable);

        return [$first?->key ?? 'id', 'desc'];
    }

    public function perPageOptions(): array
    {
        return [15, 25, 50, 100];
    }

    /**
     * Permission required to view the grid at all. Routes already gate the
     * page; the component re-checks because Livewire updates bypass routes.
     */
    abstract public function permission(): string;

    /** Title shown above the empty state, e.g. "No controls found." */
    public function emptyMessage(): string
    {
        return 'Nothing found.';
    }

    /** Material symbol for the empty state. */
    public function emptyIcon(): string
    {
        return 'search_off';
    }

    /**
     * Inline-edit write path. Only called for columns that declared
     * ->editableText()/->editableSelect(); override to persist and to
     * enforce any per-field rules. Return false to reject the write.
     */
    public function updateCell(Model $row, string $key, mixed $value): bool
    {
        return false;
    }

    /** Permission required for inline edits (checked before updateCell). */
    public function editPermission(): ?string
    {
        return null;
    }

    public function column(string $key): ?Column
    {
        return collect($this->columns())->firstWhere('key', $key);
    }

    /** Raw cell value for exports and rendering, honouring ->using(). */
    public function valueOf(Model $row, Column $column): mixed
    {
        if ($column->value) {
            return ($column->value)($row);
        }

        return data_get($row, $column->key);
    }
}
