<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\ProcessGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Process Catalogue grid (ADR 0028 Phase 2, process-dashboard-and-catalogue.md).
 *
 * Zero N+1: eager-loads processGroup, businessUnit, owner, parent and uses
 * withCount(['subProcesses', 'risks', 'entities']).
 */
class ProcessGrid extends GridDefinition
{
    public function name(): string
    {
        return 'processes';
    }

    public function permission(): string
    {
        return 'process.view';
    }

    public function query(): Builder
    {
        return BusinessProcess::query()
            ->select([
                'id',
                'organization_id',
                'business_unit_id',
                'process_group_id',
                'parent_id',
                'code',
                'apqc_code',
                'name',
                'owner_id',
                'criticality',
                'is_active',
                'updated_at',
            ])
            ->with([
                'processGroup:id,code,name',
                'businessUnit:id,code,name',
                'owner:id,name',
                'parent:id,code,name',
            ])
            ->withCount(['subProcesses', 'risks', 'entities'])
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('code', 'Code')
                ->sortable()
                ->searchable()
                ->linkTo(fn (BusinessProcess $process) => route('risk.processes.show', $process)),

            Column::make('name', 'Process Name')
                ->sortable()
                ->searchable()
                ->using(function (BusinessProcess $process): string {
                    if ($process->parent !== null) {
                        return $process->name.' (↳ '.$process->parent->name.')';
                    }

                    return $process->name;
                }),

            Column::make('apqc_code', 'APQC Code')
                ->sortable()
                ->searchable()
                ->hiddenByDefault()
                ->using(fn (BusinessProcess $process) => $process->apqc_code ?? '—'),

            Column::make('process_group.name', 'Process Group')
                ->using(fn (BusinessProcess $process) => $process->processGroup?->name ?? 'Ungrouped'),

            Column::make('business_unit.name', 'Department')
                ->using(fn (BusinessProcess $process) => $process->businessUnit?->name ?? 'Enterprise / Cross-Departmental'),

            Column::make('owner.name', 'Owner')
                ->using(fn (BusinessProcess $process) => $process->owner?->name ?? '—'),

            Column::make('criticality', 'Criticality')
                ->sortable()
                ->badge([
                    'critical' => 'bg-red-50 text-red-700',
                    'high' => 'bg-orange-50 text-orange-700',
                    'medium' => 'bg-amber-50 text-amber-700',
                    'low' => 'bg-green-50 text-[#2D7D46]',
                    '*' => 'bg-gray-100 text-gray-600',
                ]),

            Column::make('sub_processes_count', 'Sub-Processes')
                ->sortable()
                ->using(fn (BusinessProcess $process) => $process->parent_id !== null ? '—' : (int) ($process->sub_processes_count ?? 0)),

            Column::make('risks_count', 'Linked Risks')
                ->sortable()
                ->using(fn (BusinessProcess $process) => (int) ($process->risks_count ?? 0)),

            Column::make('entities_count', 'Entities')
                ->hiddenByDefault()
                ->sortable()
                ->using(fn (BusinessProcess $process) => (int) ($process->entities_count ?? 0)),

            Column::make('is_active', 'Status')
                ->sortable()
                ->using(fn (BusinessProcess $process) => $process->is_active ? 'active' : 'inactive')
                ->badge([
                    'active' => 'bg-green-50 text-[#2D7D46]',
                    'inactive' => 'bg-gray-100 text-gray-700',
                    '*' => 'bg-gray-100 text-gray-700',
                ]),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('level', 'All Levels')
                ->options([
                    'process' => 'Top-Level Processes',
                    'sub_process' => 'Sub-Processes',
                ])
                ->apply(function (Builder $query, string $value): void {
                    if ($value === 'process') {
                        $query->whereNull('parent_id');
                    } elseif ($value === 'sub_process') {
                        $query->whereNotNull('parent_id');
                    }
                }),

            Filter::make('process_group_id', 'All Process Groups')
                ->options(function (): array {
                    $groups = ProcessGroup::query()
                        ->where('organization_id', TenantContext::organizationId())
                        ->ordered()
                        ->pluck('name', 'id')
                        ->all();

                    return ['ungrouped' => 'Ungrouped'] + $groups;
                })
                ->apply(function (Builder $query, string $value): void {
                    if ($value === 'ungrouped') {
                        $query->whereNull('process_group_id');
                    } else {
                        $query->where('process_group_id', (int) $value);
                    }
                }),

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

            Filter::make('owner_id', 'All Owners')
                ->column('owner_id')
                ->options(fn () => User::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),

            Filter::make('criticality', 'All Criticalities')
                ->column('criticality')
                ->options([
                    'critical' => 'Critical',
                    'high' => 'High',
                    'medium' => 'Medium',
                    'low' => 'Low',
                ]),

            Filter::make('has_risks', 'Risk Coverage')
                ->options([
                    'none' => 'No Linked Risks',
                    'has' => 'Has Linked Risks',
                ])
                ->apply(function (Builder $query, string $value): void {
                    if ($value === 'none') {
                        $query->doesntHave('risks');
                    } elseif ($value === 'has') {
                        $query->has('risks');
                    }
                }),

            Filter::make('is_active', 'All Statuses')
                ->options([
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->apply(function (Builder $query, string $value): void {
                    if ($value === '1') {
                        $query->where('is_active', true);
                    } elseif ($value === '0') {
                        $query->where('is_active', false);
                    }
                }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (BusinessProcess $process) => route('risk.processes.show', $process)),
            RowAction::make('Edit', 'edit', fn (BusinessProcess $process) => route('risk.processes.edit', $process))
                ->can('process.update'),
        ];
    }

    public function defaultSort(): array
    {
        return ['code', 'asc'];
    }
}
