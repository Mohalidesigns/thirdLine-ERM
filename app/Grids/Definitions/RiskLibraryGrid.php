<?php

namespace App\Grids\Definitions;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\RiskCategory;
use App\Models\RiskLibraryItem;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Master Risk Library grid (ADR 0028 Phase 1, risk-library-index.md).
 *
 * Selects lightweight columns only (no JSON cause/consequence/control payloads)
 * and computes instances_count via withCount('risks') to avoid N+1 queries.
 */
class RiskLibraryGrid extends GridDefinition
{
    public function name(): string
    {
        return 'risk_library';
    }

    public function permission(): string
    {
        return 'risk_library.view';
    }

    public function query(): Builder
    {
        return RiskLibraryItem::query()
            ->select([
                'id',
                'organization_id',
                'code',
                'title',
                'description',
                'category_id',
                'basel_l1_event_type',
                'basel_l2_event_type',
                'current_version',
                'status',
                'updated_at',
            ])
            ->with(['category:id,code,name'])
            ->withCount('risks')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('code', 'Code')
                ->sortable()
                ->searchable()
                ->linkTo(fn (RiskLibraryItem $item) => route('risk.library.show', $item)),

            Column::make('title', 'Risk Title')
                ->sortable()
                ->searchable()
                ->using(fn (RiskLibraryItem $item) => str($item->title)->limit(80)),

            Column::make('category.name', 'Category')
                ->using(fn (RiskLibraryItem $item) => $item->category?->name ?? '—'),

            Column::make('basel_l1_event_type', 'Basel Event Type')
                ->sortable()
                ->using(function (RiskLibraryItem $item): string {
                    $l1 = $item->baselL1Enum()?->label();
                    $l2 = $item->baselL2Enum()?->label();
                    if ($l1 === null) {
                        return '—';
                    }

                    return $l2 ? "{$l1} — {$l2}" : $l1;
                }),

            Column::make('current_version', 'Version')
                ->sortable()
                ->using(fn (RiskLibraryItem $item) => 'v'.$item->current_version),

            Column::make('risks_count', 'Instances')
                ->sortable()
                ->using(fn (RiskLibraryItem $item) => (int) ($item->risks_count ?? 0)),

            Column::make('status', 'Status')
                ->sortable()
                ->badge([
                    RiskLibraryItem::STATUS_ACTIVE => 'bg-green-50 text-[#2D7D46]',
                    RiskLibraryItem::STATUS_RETIRED => 'bg-gray-100 text-gray-700',
                    '*' => 'bg-gray-100 text-gray-700',
                ]),

            Column::make('description', 'Description')
                ->hiddenByDefault()
                ->searchable()
                ->using(fn (RiskLibraryItem $item) => str($item->description)->limit(100)),

            Column::make('updated_at', 'Updated')
                ->hiddenByDefault()
                ->sortable()
                ->date(),
        ];
    }

    public function filters(): array
    {
        $l1Options = [];
        foreach (BaselEventTypeL1::cases() as $case) {
            $l1Options[$case->value] = $case->label();
        }

        $l2Options = [];
        foreach (BaselEventTypeL2::cases() as $case) {
            $l2Options[$case->value] = $case->label();
        }

        return [
            Filter::make('status', 'All Statuses')
                ->column('status')
                ->options([
                    RiskLibraryItem::STATUS_ACTIVE => 'Active',
                    RiskLibraryItem::STATUS_RETIRED => 'Retired',
                ]),

            Filter::make('category_id', 'All Categories')
                ->column('category_id')
                ->options(fn () => RiskCategory::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),

            Filter::make('basel_l1', 'All Basel L1')
                ->column('basel_l1_event_type')
                ->options($l1Options),

            Filter::make('basel_l2', 'All Basel L2')
                ->column('basel_l2_event_type')
                ->options($l2Options),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (RiskLibraryItem $item) => route('risk.library.show', $item)),
            RowAction::make('Edit', 'edit', fn (RiskLibraryItem $item) => route('risk.library.edit', $item))
                ->can('risk_library.update'),
        ];
    }

    public function defaultSort(): array
    {
        return ['code', 'asc'];
    }
}
