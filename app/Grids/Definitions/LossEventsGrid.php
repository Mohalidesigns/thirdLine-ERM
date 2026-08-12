<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The loss event register (WP-09 migration of
 * resources/views/risk/loss-events/index.blade.php).
 *
 * Built on the CANONICAL columns only: event_reference (not reference),
 * current_status (upper case), event_severity (upper case),
 * gross_loss_amount_kobo (minor units) — see docs/schema/canonical-columns.md.
 *
 * The old view's Basel cell read $event->baselL1Category->name, but no such
 * relation exists on the model: basel_l1_category is a plain string column,
 * so that cell rendered '-' on every row. The grid reads the column directly.
 * The old view's sort select and cbn/nfiu/ndic checkboxes were never read by
 * the controller; sorting is grid-native here and the reportable flags are
 * realised as the is_regulatory_reportable filter the controller does read.
 */
class LossEventsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'loss_events';
    }

    public function permission(): string
    {
        return 'loss_event.view';
    }

    public function query(): Builder
    {
        return LossEvent::query()
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('event_reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (LossEvent $e) => route('risk.loss-events.show', $e)),

            Column::make('title', 'Title')
                ->searchable()
                ->using(fn (LossEvent $e) => str($e->title)->limit(50)),

            Column::make('date_of_loss', 'Date of Loss')->sortable()->date(),

            Column::make('basel_l1_category', 'Basel Category')
                ->using(fn (LossEvent $e) => $e->basel_l1_category === null
                    ? '—'
                    : (string) Str::of($e->basel_l1_category)->lower()->replace('_', ' ')->title())
                ->badge(['*' => 'bg-blue-50 text-blue-700']),

            Column::make('gross_loss_amount_kobo', 'Gross Loss')->sortable()->money(),

            // event_severity is stored upper case (INSIGNIFICANT … CATASTROPHIC);
            // lower-cased for display, and the rag map keys match that form.
            Column::make('event_severity', 'Severity')
                ->sortable()
                ->using(fn (LossEvent $e) => strtolower($e->event_severity ?? ''))
                ->rag([
                    'catastrophic' => 'red',
                    'major' => 'red',
                    'moderate' => 'amber',
                    'minor' => 'green',
                    'insignificant' => 'green',
                ]),

            Column::make('current_status', 'Status')
                ->using(fn (LossEvent $e) => strtolower($e->current_status ?? ''))
                ->badge([
                    'new' => 'bg-gray-100 text-gray-700',
                    'draft' => 'bg-gray-100 text-gray-700',
                    'reported' => 'bg-blue-100 text-blue-700',
                    'under_investigation' => 'bg-yellow-100 text-yellow-700',
                    'pending_approval' => 'bg-purple-100 text-purple-700',
                    'approved' => 'bg-green-100 text-green-700',
                    'closed' => 'bg-green-100 text-green-700',
                    'escalated' => 'bg-red-100 text-red-700',
                    'reopened' => 'bg-orange-100 text-orange-700',
                    '*' => 'bg-gray-100 text-gray-600',
                ]),

            Column::make('days_open', 'Days Open')
                ->using(function (LossEvent $e) {
                    if ($e->current_status === 'CLOSED' || ! $e->created_at) {
                        return '—';
                    }

                    return (int) abs($e->created_at->diffInDays(now())).'d';
                }),

            Column::make('cbn_reportable', 'CBN')
                ->using(fn (LossEvent $e) => $e->cbn_reportable ? 'Yes' : 'No')
                ->badge([
                    'Yes' => 'bg-red-50 text-red-600',
                    'No' => 'bg-gray-100 text-gray-500',
                ]),

            Column::make('description', 'Description')
                ->hiddenByDefault()->searchable()
                ->using(fn (LossEvent $e) => str($e->description)->limit(80)),
        ];
    }

    public function filters(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            Filter::make('status', 'All Statuses')
                ->column('current_status')
                ->options([
                    'NEW' => 'New',
                    'DRAFT' => 'Draft',
                    'REPORTED' => 'Reported',
                    'UNDER_INVESTIGATION' => 'Under Investigation',
                    'PENDING_APPROVAL' => 'Pending Approval',
                    'APPROVED' => 'Approved',
                    'CLOSED' => 'Closed',
                    'REOPENED' => 'Reopened',
                    'ESCALATED' => 'Escalated',
                ]),

            Filter::make('basel_event_type', 'All Basel Categories')
                ->column('basel_l1_category')
                ->options(fn () => LossEvent::query()
                    ->where('organization_id', $orgId)
                    ->whereNotNull('basel_l1_category')
                    ->distinct()
                    ->orderBy('basel_l1_category')
                    ->pluck('basel_l1_category')
                    ->mapWithKeys(fn ($v) => [
                        $v => (string) Str::of($v)->lower()->replace('_', ' ')->title(),
                    ])
                    ->all()),

            Filter::make('cbn_category', 'All CBN Categories')
                ->column('cbn_risk_category')
                ->options(fn () => LossEvent::query()
                    ->where('organization_id', $orgId)
                    ->whereNotNull('cbn_risk_category')
                    ->distinct()
                    ->orderBy('cbn_risk_category')
                    ->pluck('cbn_risk_category')
                    ->mapWithKeys(fn ($v) => [
                        $v => (string) Str::of($v)->lower()->replace('_', ' ')->title(),
                    ])
                    ->all()),

            Filter::make('severity', 'All Severities')
                ->column('event_severity')
                ->options([
                    'INSIGNIFICANT' => 'Insignificant',
                    'MINOR' => 'Minor',
                    'MODERATE' => 'Moderate',
                    'MAJOR' => 'Major',
                    'CATASTROPHIC' => 'Catastrophic',
                ]),

            Filter::make('regulatory_reportable', 'Regulatory Reportable?')
                ->column('is_regulatory_reportable')
                ->options([
                    '1' => 'Regulatory reportable',
                    '0' => 'Not reportable',
                ]),

            Filter::make('business_unit_id', 'All Business Units')
                ->options(fn () => BusinessUnit::query()
                    ->where('organization_id', $orgId)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (LossEvent $e) => route('risk.loss-events.show', $e)),
            RowAction::make('Edit', 'edit', fn (LossEvent $e) => route('risk.loss-events.edit', $e))
                ->can('loss_event.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['date_of_loss', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No loss events found.';
    }

    public function emptyIcon(): string
    {
        return 'crisis_alert';
    }
}
