<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Issues register (WP-09 migration of resources/views/risk/issues/index.blade.php).
 *
 * Schema notes (the drift the old view papered over): the real columns are
 * `issue_status` (not status; values are UPPERCASE — OPEN, IN_PROGRESS, …),
 * `remediation_due_date` (target_resolution_date is a read-only bridge
 * accessor over it), and `current_escalation_level` (escalation_level is a
 * bridge). Priority is mixed-case in the data (seeder wrote HIGH, the form
 * writes high), so the priority filter compares lowercased. The old page
 * eager-loaded issueOwner but rendered $issue->owner — both resolve to
 * responsibleOwner(); the grid loads and reads `owner` so there is no N+1.
 */
class IssuesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'issues';
    }

    public function permission(): string
    {
        return 'issue.view';
    }

    public function query(): Builder
    {
        // WP-00 node scoping — see RisksGrid.
        return Issue::query()
            ->with('owner')
            ->where('organization_id', TenantContext::organizationId())
            ->visibleTo();
    }

    public function columns(): array
    {
        return [
            Column::make('issue_reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (Issue $i) => route('risk.issues.show', $i)),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (Issue $i) => str($i->title)->limit(50)
                    .($i->cbn_examination_finding ? ' · CBN' : '')),

            Column::make('issue_source', 'Source')->badge([
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('priority', 'Priority')
                ->sortable()
                ->rag([
                    'critical' => 'red',
                    'high' => 'red',
                    'medium' => 'amber',
                    'low' => 'green',
                ])
                ->using(fn (Issue $i) => strtolower($i->priority ?? '') ?: 'medium'),

            // The map is keyed on the lowercased value produced by using()
            // (rendered as "In progress" instead of shouting IN_PROGRESS).
            Column::make('issue_status', 'Status')->sortable()->badge([
                'open' => 'bg-blue-100 text-blue-700',
                'in_progress' => 'bg-yellow-100 text-yellow-700',
                'overdue' => 'bg-red-100 text-red-700',
                'pending_closure' => 'bg-purple-100 text-purple-700',
                'closed' => 'bg-green-100 text-green-700',
                'cancelled' => 'bg-gray-100 text-gray-500',
                'reopened' => 'bg-orange-100 text-orange-700',
                '*' => 'bg-gray-100 text-gray-700',
            ])->using(fn (Issue $i) => strtolower((string) $i->issue_status)),

            Column::make('owner.name', 'Owner')
                ->using(fn (Issue $i) => $i->owner->name ?? '—'),

            Column::make('remediation_due_date', 'Due Date')->sortable()->date(),

            Column::make('days_open', 'Days Open')
                ->using(fn (Issue $i) => $i->issue_status !== 'CLOSED' && $i->created_at
                    ? (int) $i->created_at->diffInDays(now()).'d'
                    : '—'),

            Column::make('current_escalation_level', 'Escalation')
                ->sortable()
                ->badge([
                    'L0' => 'bg-gray-100 text-gray-500',
                    'L1' => 'bg-yellow-100 text-yellow-700',
                    'L2' => 'bg-orange-100 text-orange-700',
                    'L3' => 'bg-red-100 text-red-700',
                    '*' => 'bg-gray-100 text-gray-500',
                ])
                ->using(fn (Issue $i) => 'L'.(int) $i->current_escalation_level),

            Column::make('description', 'Description')
                ->hiddenByDefault()->searchable()
                ->using(fn (Issue $i) => str($i->description)->limit(80)),

            Column::make('created_at', 'Logged')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('issue_status', 'All Statuses')->options([
                'OPEN' => 'Open',
                'IN_PROGRESS' => 'In Progress',
                'OVERDUE' => 'Overdue',
                'PENDING_CLOSURE' => 'Pending Closure',
                'CLOSED' => 'Closed',
                'CANCELLED' => 'Cancelled',
                'REOPENED' => 'Reopened',
            ]),

            // Priority is stored in mixed case (HIGH from the seeder, high
            // from the form) — compare lowercased.
            Filter::make('priority', 'All Priorities')->options([
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ])->apply(fn (Builder $q, string $value) => $q->whereRaw('LOWER(priority) = ?', [$value])),

            Filter::make('source', 'All Sources')
                ->column('issue_source')
                ->options(fn () => Issue::where('organization_id', TenantContext::organizationId())
                    ->whereNotNull('issue_source')
                    ->distinct()
                    ->orderBy('issue_source')
                    ->pluck('issue_source', 'issue_source')
                    ->map(fn ($v) => (string) str($v)->lower()->replace('_', ' ')->title())
                    ->all()),

            Filter::make('overdue', 'Overdue?')->options([
                'yes' => 'Overdue Only',
                'no' => 'Not Overdue',
            ])->apply(function (Builder $query, string $value) {
                $overdue = fn (Builder $q) => $q
                    ->whereNotNull('remediation_due_date')
                    ->where('remediation_due_date', '<', now())
                    ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED']);

                $value === 'yes'
                    ? $query->where($overdue)
                    : $query->whereNot($overdue);
            }),

            Filter::make('escalation_level', 'All Levels')
                ->column('current_escalation_level')
                ->options([
                    '0' => 'Level 0 - None',
                    '1' => 'Level 1',
                    '2' => 'Level 2',
                    '3' => 'Level 3 - Board',
                ]),

            Filter::make('business_unit_id', 'All Business Units')
                ->options(fn () => BusinessUnit::where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (Issue $i) => route('risk.issues.show', $i)),
            RowAction::make('Edit', 'edit', fn (Issue $i) => route('risk.issues.edit', $i))
                ->can('issue.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No issues found.';
    }

    public function emptyIcon(): string
    {
        return 'search_off';
    }
}
