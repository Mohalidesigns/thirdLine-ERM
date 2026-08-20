<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The KRI library (WP-09 migration of resources/views/risk/kri/index.blade.php).
 *
 * A KRI has no category column of its own — its category is that of the risk
 * it monitors, which the old controller injected as a synthetic attribute on
 * the paginated collection. Here the risk.category relation is eager-loaded
 * and read in the cell instead. The single-number green/amber/red thresholds
 * are the model's accessors folding the *_threshold_min/max column pairs back
 * to the number the form collected. trend_direction actually holds
 * improving/deteriorating/stable — the old view tested for up/down values
 * nothing ever wrote, so the trend cell always rendered flat.
 */
class KrisGrid extends GridDefinition
{
    public function name(): string
    {
        return 'kris';
    }

    public function permission(): string
    {
        return 'kri.view';
    }

    public function query(): Builder
    {
        // WP-00 node scoping — see RisksGrid. Scoped on the KRI's own
        // entity_id rather than through its risk: a KRI is pinned to the unit
        // that reports the reading, which is not always the unit that owns the
        // risk it indicates.
        return KeyRiskIndicator::query()
            ->with(['risk.category', 'owner'])
            ->where('organization_id', TenantContext::organizationId())
            ->visibleTo();
    }

    public function columns(): array
    {
        return [
            Column::make('name', 'KRI Name')
                ->sortable()->searchable()
                ->linkTo(fn (KeyRiskIndicator $k) => route('risk.kri.show', $k)),

            Column::make('category', 'Category')
                ->using(fn (KeyRiskIndicator $k) => $k->risk?->category?->name ?? '—'),

            Column::make('current_value', 'Current Value')
                ->sortable()
                ->using(fn (KeyRiskIndicator $k) => $k->current_value === null
                    ? '—'
                    : number_format((float) $k->current_value, 2)
                        .($k->unit_of_measure ? ' '.$k->unit_of_measure : '')),

            Column::make('green_threshold', 'Green Threshold')
                ->using(fn (KeyRiskIndicator $k) => $k->green_threshold ?? '—'),

            Column::make('amber_threshold', 'Amber Threshold')
                ->using(fn (KeyRiskIndicator $k) => $k->amber_threshold ?? '—'),

            Column::make('red_threshold', 'Red Threshold')
                ->using(fn (KeyRiskIndicator $k) => $k->red_threshold ?? '—'),

            Column::make('current_status', 'Status')
                ->sortable()
                ->using(fn (KeyRiskIndicator $k) => $k->current_status ?? 'green')
                ->rag([
                    'green' => 'green',
                    'amber' => 'amber',
                    'yellow' => 'amber',
                    'red' => 'red',
                ]),

            Column::make('trend_direction', 'Trend')
                ->using(fn (KeyRiskIndicator $k) => match ($k->trend_direction) {
                    'deteriorating' => '↑ Deteriorating',
                    'improving' => '↓ Improving',
                    'stable' => '→ Stable',
                    default => '→',
                }),

            Column::make('measurement_frequency', 'Frequency')
                ->using(fn (KeyRiskIndicator $k) => ucfirst($k->measurement_frequency ?? '—')),

            Column::make('owner.name', 'Owner'),

            Column::make('kri_code', 'KRI Code')
                ->hiddenByDefault()->sortable()->searchable(),
        ];
    }

    public function filters(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            Filter::make('status', 'All Statuses')
                ->column('current_status')
                ->options([
                    'green' => 'Green (Normal)',
                    'amber' => 'Amber (Warning)',
                    'red' => 'Red (Breach)',
                ]),

            Filter::make('risk_id', 'All Risks')
                ->options(fn () => Risk::query()
                    ->where('organization_id', $orgId)
                    ->orderBy('risk_code')
                    ->pluck('risk_code', 'id')
                    ->all()),

            Filter::make('category', 'All Categories')
                ->options(fn () => KeyRiskIndicator::query()
                    ->where('key_risk_indicators.organization_id', $orgId)
                    ->join('risks', 'risks.id', '=', 'key_risk_indicators.risk_id')
                    ->join('risk_categories', 'risk_categories.id', '=', 'risks.category_id')
                    ->distinct()
                    ->orderBy('risk_categories.name')
                    ->pluck('risk_categories.name')
                    ->mapWithKeys(fn ($name) => [$name => $name])
                    ->all())
                ->apply(fn (Builder $query, string $value) => $query
                    ->whereHas('risk.category', fn ($q) => $q->where('name', $value))),

            Filter::make('frequency', 'All Frequencies')
                ->column('measurement_frequency')
                ->options([
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                    'monthly' => 'Monthly',
                    'quarterly' => 'Quarterly',
                ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (KeyRiskIndicator $k) => route('risk.kri.show', $k)),
            RowAction::make('Edit', 'edit', fn (KeyRiskIndicator $k) => route('risk.kri.edit', $k))
                ->can('kri.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['kri_code', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No KRIs found.';
    }

    public function emptyIcon(): string
    {
        return 'speed';
    }
}
