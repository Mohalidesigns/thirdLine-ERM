<?php

namespace App\Grids\Definitions;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Control;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The control library register (WP-09 exemplar migration — this replaced
 * the hand-rolled table in resources/views/risk/controls/index.blade.php).
 */
class ControlsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'controls';
    }

    public function permission(): string
    {
        return 'control.view';
    }

    public function query(): Builder
    {
        return Control::query()
            ->with('owner')
            ->withCount('risks')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('control_code', 'Control ID')
                ->sortable()->searchable()
                ->linkTo(fn (Control $c) => route('risk.controls.show', $c)),

            Column::make('name', 'Control Name')
                ->sortable()->searchable()
                ->using(fn (Control $c) => str($c->name)->limit(45)),

            Column::make('control_type', 'Type')->sortable()->badge([
                'preventive' => 'bg-blue-100 text-blue-700',
                'detective' => 'bg-purple-100 text-purple-700',
                'corrective' => 'bg-orange-100 text-orange-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('control_nature', 'Nature')
                ->using(fn (Control $c) => ucfirst(str_replace('_', ' ', $c->control_nature ?? '—'))),

            Column::make('owner.name', 'Owner'),

            Column::make('effectiveness_rating', 'Effectiveness')->sortable()->rag([
                'effective' => 'green',
                'partially_effective' => 'amber',
                'ineffective' => 'red',
            ])->using(fn (Control $c) => $c->effectiveness_rating ?? 'not tested')
              ->editableSelect([
                  'effective' => 'Effective',
                  'partially_effective' => 'Partially Effective',
                  'ineffective' => 'Ineffective',
              ]),

            Column::make('risks_count', 'Linked Risks')->sortable()->count(),

            Column::make('last_test_date', 'Last Tested')->sortable()->date(),

            Column::make('description', 'Description')
                ->hiddenByDefault()
                ->using(fn (Control $c) => str($c->description)->limit(80)),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('control_type', 'All Types')->options([
                'preventive' => 'Preventive',
                'detective' => 'Detective',
                'corrective' => 'Corrective',
                'directive' => 'Directive',
            ]),
            Filter::make('control_nature', 'All Natures')->options([
                'manual' => 'Manual',
                'automated' => 'Automated',
                'semi_automated' => 'Semi-automated',
            ]),
            Filter::make('effectiveness', 'All Effectiveness')
                ->column('effectiveness_rating')
                ->options([
                    'effective' => 'Effective',
                    'partially_effective' => 'Partially Effective',
                    'ineffective' => 'Ineffective',
                ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (Control $c) => route('risk.controls.show', $c)),
            RowAction::make('Edit', 'edit', fn (Control $c) => route('risk.controls.edit', $c))
                ->can('control.edit'),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('delete', 'Delete', 'delete', function ($controls) {
                $count = $controls->count();
                $controls->each->delete();

                return "{$count} ".str('control')->plural($count).' deleted.';
            })->can('control.delete')
              ->confirm('Delete the selected controls? Linked risks keep their history.'),
        ];
    }

    public function editPermission(): ?string
    {
        return 'control.edit';
    }

    public function updateCell(Model $row, string $key, mixed $value): bool
    {
        // Only the effectiveness rating is inline-editable, and only to a
        // declared option — the component has already validated that.
        if ($key !== 'effectiveness_rating') {
            return false;
        }

        $row->update(['effectiveness_rating' => $value]);

        return true;
    }

    public function defaultSort(): array
    {
        return ['control_code', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No controls found.';
    }

    public function emptyIcon(): string
    {
        return 'verified_user';
    }
}
