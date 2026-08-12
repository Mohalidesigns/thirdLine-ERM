<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Entity;
use App\Models\EntityType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The entity register (WP-09 migration of
 * resources/views/risk/scoping/index.blade.php).
 *
 * The sortable columns mirror the controller's old whitelist
 * (entity_code, name, level, status, created_at); the searchable columns
 * mirror its old search (name, entity_code, description).
 */
class EntitiesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'entities';
    }

    public function permission(): string
    {
        return 'entity.view';
    }

    public function query(): Builder
    {
        return Entity::query()
            ->with(['entityType', 'parent', 'owner'])
            ->withCount(['risks', 'keyRiskIndicators', 'issues'])
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('entity_code', 'Code')
                ->sortable()->searchable()
                ->linkTo(fn (Entity $e) => route('risk.scoping.show', $e)),

            Column::make('name', 'Entity Name')->sortable()->searchable(),

            Column::make('entityType.name', 'Type')->badge([
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('parent.name', 'Parent Entity'),

            Column::make('level', 'Level')
                ->sortable('level')
                ->using(fn (Entity $e) => 'L'.$e->level)
                ->badge(['*' => 'bg-gray-100 text-gray-600']),

            Column::make('risks_count', 'Risks')->count(),

            Column::make('key_risk_indicators_count', 'KRIs')->count(),

            Column::make('issues_count', 'Issues')->count(),

            Column::make('owner.name', 'Owner'),

            Column::make('status', 'Status')->sortable()->badge([
                'active' => 'bg-green-100 text-green-700',
                'inactive' => 'bg-gray-100 text-gray-600',
                'archived' => 'bg-yellow-100 text-yellow-700',
                '*' => 'bg-gray-100 text-gray-600',
            ]),

            Column::make('description', 'Description')
                ->hiddenByDefault()->searchable()
                ->using(fn (Entity $e) => str($e->description ?? '')->limit(80)),

            Column::make('created_at', 'Created')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        $orgId = fn () => TenantContext::organizationId();

        return [
            Filter::make('entity_type_id', 'All Types')->options(
                fn () => EntityType::where('organization_id', $orgId())
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->pluck('name', 'id')
                    ->all()
            ),

            Filter::make('parent_id', 'All Parents')->options(
                fn () => Entity::where('organization_id', $orgId())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()
            ),

            Filter::make('status', 'All Statuses')->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
                'archived' => 'Archived',
            ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (Entity $e) => route('risk.scoping.show', $e)),
            RowAction::make('Edit', 'edit', fn (Entity $e) => route('risk.scoping.edit', $e))
                ->can('entity.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['entity_code', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No entities found.';
    }

    public function emptyIcon(): string
    {
        return 'account_tree';
    }
}
