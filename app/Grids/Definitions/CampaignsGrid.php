<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\AssessmentCampaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The assessment campaign register (WP-09 migration of
 * resources/views/risk/campaigns/index.blade.php).
 *
 * The old controller already read `status` and `type` query parameters, but
 * the Blade never rendered a filter bar — the two filters were reachable only
 * by hand-editing the URL. Declaring them here makes them real.
 *
 * `completion_pct` is a stored decimal(5,2) maintained by
 * AssessmentCampaign::recalculateProgress(); the old cell rounded it to a
 * whole percent, which the progress bar does too.
 */
class CampaignsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'campaigns';
    }

    public function permission(): string
    {
        return 'campaign.view';
    }

    public function query(): Builder
    {
        return AssessmentCampaign::query()
            ->with(['creator', 'questionnaire'])
            ->withCount('assignments')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('campaign_code', 'Code')
                ->sortable()->searchable()
                ->linkTo(fn (AssessmentCampaign $c) => route('risk.campaigns.show', $c)),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (AssessmentCampaign $c) => str($c->title)->limit(50)),

            Column::make('campaign_type', 'Type')->sortable()->badge([
                'rcsa' => 'bg-blue-100 text-blue-700',
                'fraud_risk' => 'bg-red-100 text-red-700',
                'compliance' => 'bg-purple-100 text-purple-700',
                'new_product' => 'bg-teal-100 text-teal-700',
                'custom' => 'bg-gray-100 text-gray-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('status', 'Status')->sortable()->badge([
                'draft' => 'bg-gray-100 text-gray-700',
                'active' => 'bg-blue-100 text-blue-700',
                'in_progress' => 'bg-yellow-100 text-yellow-700',
                'under_review' => 'bg-purple-100 text-purple-700',
                'closed' => 'bg-green-100 text-green-700',
                'cancelled' => 'bg-red-100 text-red-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('assignments_count', 'Assignments')->sortable()->count(),

            Column::make('completion_pct', 'Completion')->sortable()->progress(),

            Column::make('period', 'Period')
                ->using(fn (AssessmentCampaign $c) => $c->start_date && $c->end_date
                    ? $c->start_date->format('d M Y').' – '.$c->end_date->format('d M Y')
                    : '—'),

            Column::make('questionnaire.title', 'Questionnaire')->hiddenByDefault(),

            Column::make('creator.name', 'Created By')->hiddenByDefault(),

            Column::make('created_at', 'Created')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')->options([
                'draft' => 'Draft',
                'active' => 'Active',
                'in_progress' => 'In Progress',
                'under_review' => 'Under Review',
                'closed' => 'Closed',
                'cancelled' => 'Cancelled',
            ]),

            // The controller's parameter was `type`, backed by campaign_type.
            Filter::make('type', 'All Types')
                ->column('campaign_type')
                ->options([
                    'rcsa' => 'RCSA',
                    'fraud_risk' => 'Fraud Risk',
                    'compliance' => 'Compliance',
                    'new_product' => 'New Product',
                    'custom' => 'Custom',
                ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (AssessmentCampaign $c) => route('risk.campaigns.show', $c)),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No campaigns found.';
    }

    public function emptyIcon(): string
    {
        return 'campaign';
    }
}
