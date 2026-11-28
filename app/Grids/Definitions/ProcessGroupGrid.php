<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Models\BusinessUnit;
use App\Models\ProcessGroup;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Process Groups Admin grid (ADR 0028 Phase 2, process-groups-admin.md).
 *
 * Zero N+1: eager-loads businessUnit and owner, and uses withCount on
 * topLevelProcesses.
 */
class ProcessGroupGrid extends GridDefinition
{
    public function name(): string
    {
        return 'process_groups';
    }

    public function permission(): string
    {
        return 'process.view';
    }

    public function query(): Builder
    {
        return ProcessGroup::query()
            ->select([
                'id',
                'organization_id',
                'business_unit_id',
                'code',
                'apqc_code',
                'name',
                'description',
                'owner_id',
                'sort_order',
                'status',
                'updated_at',
            ])
            ->with([
                'businessUnit:id,code,name',
                'owner:id,name',
            ])
            ->withCount(['topLevelProcesses as processes_count'])
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('code', 'Code')
                ->sortable()
                ->searchable(),

            Column::make('name', 'Group Name')
                ->sortable()
                ->searchable(),

            Column::make('apqc_code', 'APQC Code')
                ->sortable()
                ->searchable()
                ->hiddenByDefault()
                ->using(fn (ProcessGroup $group) => $group->apqc_code ?? '—'),

            Column::make('business_unit.name', 'Department')
                ->using(fn (ProcessGroup $group) => $group->businessUnit?->name ?? 'Enterprise / Cross-Departmental'),

            Column::make('owner.name', 'Owner')
                ->using(fn (ProcessGroup $group) => $group->owner?->name ?? '—'),

            Column::make('processes_count', 'Processes')
                ->sortable()
                ->using(fn (ProcessGroup $group) => (int) ($group->processes_count ?? 0)),

            Column::make('sort_order', 'Order')
                ->sortable()
                ->using(fn (ProcessGroup $group) => (int) $group->sort_order),

            Column::make('status', 'Status')
                ->sortable()
                ->badge([
                    ProcessGroup::STATUS_ACTIVE => 'bg-green-50 text-[#2D7D46]',
                    ProcessGroup::STATUS_INACTIVE => 'bg-gray-100 text-gray-700',
                    '*' => 'bg-gray-100 text-gray-700',
                ]),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')
                ->column('status')
                ->options([
                    ProcessGroup::STATUS_ACTIVE => 'Active',
                    ProcessGroup::STATUS_INACTIVE => 'Inactive',
                ]),

            Filter::make('business_unit_id', 'All Departments')
                ->options(function (): array {
                    $units = BusinessUnit::query()
                        ->where('organization_id', TenantContext::organizationId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();

                    return ['enterprise' => 'Enterprise / Cross-Departmental'] + $units;
                })
                ->apply(function (Builder $query, string $value): void {
                    if ($value === 'enterprise') {
                        $query->whereNull('business_unit_id');
                    } else {
                        $query->where('business_unit_id', (int) $value);
                    }
                }),
        ];
    }

    public function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }
}
