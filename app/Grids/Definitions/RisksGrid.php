<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The risk register (WP-09 migration of resources/views/risk/register/index.blade.php).
 *
 * Only the LIVE path renders this grid — when the selected period is closed,
 * RiskRegisterController@index still delegates to historicIndex(), which keeps
 * the hand-rolled "as at" table (the sort key exists only after the measure
 * overlay, so it cannot be a plain SQL grid).
 *
 * The residual_likelihood / residual_impact filters carry the heat-map cell
 * drill-through (?filters[residual_likelihood]=N&filters[residual_impact]=M).
 */
class RisksGrid extends GridDefinition
{
    /** Rating labels as written by RiskScoringService / the seeders. */
    private const RATING_RAG = [
        'Critical' => 'red',
        'High' => 'red',
        'Medium' => 'amber',
        'Low' => 'green',
    ];

    public function name(): string
    {
        return 'risks';
    }

    public function permission(): string
    {
        return 'risk.view';
    }

    public function query(): Builder
    {
        // WP-00 node scoping: tenancy answers "which bank", visibleTo()
        // answers "which part of it". Without this line a user pinned to a
        // branch was served the whole group's register — and because every
        // grid path (list, CSV/XLSX export, bulk action, row action) funnels
        // through this one method, the leak was in all of them at once.
        return Risk::query()
            ->with(['category', 'riskOwner', 'businessUnit'])
            ->where('organization_id', TenantContext::organizationId())
            ->visibleTo();
    }

    public function columns(): array
    {
        return [
            Column::make('risk_code', 'Risk ID')
                ->sortable()->searchable()
                ->linkTo(fn (Risk $r) => route('risk.register.show', $r)),

            Column::make('title', 'Risk Name')
                ->sortable()->searchable()
                ->using(fn (Risk $r) => str($r->title)->limit(45)),

            Column::make('category.name', 'Category'),

            Column::make('inherent_rating', 'Inherent Rating')
                ->sortable('inherent_score')
                ->rag(self::RATING_RAG)
                ->using(fn (Risk $r) => $r->inherent_rating ?? 'Not Assessed'),

            Column::make('residual_rating', 'Residual Rating')
                ->sortable('residual_score')
                ->rag(self::RATING_RAG)
                ->using(fn (Risk $r) => $r->residual_rating ?? 'Not Assessed'),

            Column::make('riskOwner.name', 'Risk Owner')
                ->using(fn (Risk $r) => $r->riskOwner->name ?? 'Unassigned'),

            Column::make('businessUnit.name', 'Business Unit'),

            Column::make('status', 'Status')->sortable()->badge([
                'active' => 'bg-green-100 text-green-700',
                'dormant' => 'bg-yellow-100 text-yellow-700',
                'closed' => 'bg-gray-100 text-gray-600',
                'retired' => 'bg-gray-100 text-gray-500',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('description', 'Description')
                ->hiddenByDefault()->searchable()
                ->using(fn (Risk $r) => str($r->description)->limit(80)),

            Column::make('created_at', 'Created')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        // The scale reads in the words the heat map uses — a cell drill-through
        // that lands showing "2" cannot be checked against the cell it came
        // from. Keys stay the stored 1–5 scores.
        $likelihood = collect(ScoringProfileTemplates::DEFAULT_LIKELIHOOD_LABELS)
            ->mapWithKeys(fn ($label, $score) => [(string) $score => "{$score} · {$label}"])->all();
        $impact = collect(ScoringProfileTemplates::DEFAULT_IMPACT_LABELS)
            ->mapWithKeys(fn ($label, $score) => [(string) $score => "{$score} · {$label}"])->all();

        return [
            Filter::make('category', 'All Categories')
                ->column('category_id')
                ->options(fn () => RiskCategory::where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')->pluck('name', 'id')->all()),

            Filter::make('status', 'All Statuses')->options([
                'active' => 'Active',
                'dormant' => 'Dormant',
                'closed' => 'Closed',
                'retired' => 'Retired',
            ]),

            Filter::make('rating', 'All Ratings')
                ->column('residual_rating')
                ->options([
                    'Critical' => 'Critical',
                    'High' => 'High',
                    'Medium' => 'Medium',
                    'Low' => 'Low',
                ]),

            Filter::make('business_unit', 'All Business Units')
                ->column('business_unit_id')
                ->options(fn () => BusinessUnit::where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')->pluck('name', 'id')->all()),

            // Heat-map cell drill-through (one likelihood × impact pair).
            Filter::make('residual_likelihood', 'Residual Likelihood')->options($likelihood),
            Filter::make('residual_impact', 'Residual Impact')->options($impact),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (Risk $r) => route('risk.register.show', $r)),
            RowAction::make('Edit', 'edit', fn (Risk $r) => route('risk.register.edit', $r))
                ->can('risk.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['inherent_rating', 'desc']; // sorts on inherent_score (sqlColumn)
    }

    public function emptyMessage(): string
    {
        return 'No risks found matching your criteria.';
    }

    public function emptyIcon(): string
    {
        return 'assessment';
    }
}
