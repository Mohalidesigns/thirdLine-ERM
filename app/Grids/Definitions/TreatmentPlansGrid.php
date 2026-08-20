<?php

namespace App\Grids\Definitions;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\TreatmentPlan;
use App\Support\Authorization\GraphScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Treatment plans register (WP-09 migration of
 * resources/views/risk/treatments/index.blade.php).
 *
 * Schema notes: `title` is an accessor over the canonical `action_title`
 * column, `progress` over `progress_pct`, and cost lives in
 * `cost_estimate_ngn` — a DECIMAL(18,2) NAIRA column, not kobo — hence
 * ->money(false). The strategy and priority selects on the old view were
 * dead (the controller never read them); here they are real filters.
 */
class TreatmentPlansGrid extends GridDefinition
{
    public function name(): string
    {
        return 'treatments';
    }

    public function permission(): string
    {
        return 'treatment.view';
    }

    public function query(): Builder
    {
        // WP-00 node scoping, through the parent. treatment_plans carries no
        // entity_id, but risk_id is NOT NULL and cascade-deletes with the risk:
        // the plan exists only as the response to that risk, so it inherits the
        // risk's visibility rather than having one of its own.
        return GraphScope::applyThrough(
            TreatmentPlan::query()
                ->with(['risk', 'owner'])
                ->where('organization_id', TenantContext::organizationId()),
            'risk'
        );
    }

    public function columns(): array
    {
        return [
            Column::make('title', 'Plan Title')
                ->sortable('action_title')->searchable('action_title')
                ->linkTo(fn (TreatmentPlan $p) => route('risk.treatments.show', $p))
                ->using(fn (TreatmentPlan $p) => str($p->title)->limit(35)),

            Column::make('risk.risk_code', 'Linked Risk')
                ->linkTo(fn (TreatmentPlan $p) => $p->risk ? route('risk.register.show', $p->risk) : '#'),

            Column::make('strategy', 'Strategy')->sortable()->badge([
                'mitigate' => 'bg-blue-100 text-blue-700',
                'transfer' => 'bg-purple-100 text-purple-700',
                'avoid' => 'bg-red-100 text-red-700',
                'accept' => 'bg-green-100 text-green-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('priority', 'Priority')->sortable()->rag([
                'critical' => 'red',
                'high' => 'red',
                'medium' => 'amber',
                'low' => 'green',
            ]),

            Column::make('progress_pct', 'Progress')->sortable()->progress(),

            Column::make('owner.name', 'Owner')
                ->using(fn (TreatmentPlan $p) => $p->owner->name ?? 'Unassigned'),

            Column::make('target_date', 'Target Date')
                ->sortable()
                ->using(fn (TreatmentPlan $p) => $p->target_date
                    ? $p->target_date->format('d M Y')
                        .($p->target_date->isPast() && ! in_array($p->status, ['completed', 'cancelled'], true) ? ' · Overdue' : '')
                    : '—'),

            Column::make('cost_estimate_ngn', 'Cost Estimate')->sortable()->money(false),

            Column::make('status', 'Status')->sortable()->badge([
                'not_started' => 'bg-gray-100 text-gray-600',
                'in_progress' => 'bg-blue-100 text-blue-700',
                'pending_review' => 'bg-purple-100 text-purple-700',
                'completed' => 'bg-green-100 text-green-700',
                'on_hold' => 'bg-yellow-100 text-yellow-700',
                'overdue' => 'bg-red-100 text-red-700',
                'cancelled' => 'bg-gray-100 text-gray-500',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('treatment_code', 'Plan Code')
                ->hiddenByDefault()->sortable()->searchable(),

            Column::make('created_at', 'Created')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('strategy', 'All Strategies')->options([
                'mitigate' => 'Mitigate',
                'transfer' => 'Transfer',
                'accept' => 'Accept',
                'avoid' => 'Avoid',
            ]),

            Filter::make('priority', 'All Priorities')->options([
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ]),

            // "overdue" is both a literal status value and, in practice, a
            // date condition — the tab must catch plans whose target date
            // slipped while the status still says in_progress.
            Filter::make('status', 'All Statuses')->options([
                'not_started' => 'Not Started',
                'in_progress' => 'In Progress',
                'pending_review' => 'Pending Review',
                'completed' => 'Completed',
                'on_hold' => 'On Hold',
                'overdue' => 'Overdue',
                'cancelled' => 'Cancelled',
            ])->apply(function (Builder $query, string $value) {
                if ($value === 'overdue') {
                    $query->where(function (Builder $q) {
                        $q->where('status', 'overdue')
                            ->orWhere(function (Builder $q) {
                                $q->whereNotIn('status', ['completed', 'cancelled'])
                                    ->whereNotNull('target_date')
                                    ->where('target_date', '<', now());
                            });
                    });

                    return;
                }

                $query->where('status', $value);
            }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (TreatmentPlan $p) => route('risk.treatments.show', $p)),
            RowAction::make('Edit', 'edit', fn (TreatmentPlan $p) => route('risk.treatments.edit', $p))
                ->can('treatment.edit'),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('delete', 'Delete', 'delete', function ($plans) {
                $count = $plans->count();
                $plans->each->delete(); // SoftDeletes

                return "{$count} ".str('plan')->plural($count).' deleted.';
            })->can('treatment.delete')
                ->confirm('Delete the selected treatment plans? Their linked risks are unaffected.'),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No treatment plans found.';
    }

    public function emptyIcon(): string
    {
        return 'assignment';
    }
}
