<?php

namespace App\Grids\Definitions;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\NearMiss;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The near-miss register (WP-09 migration of
 * resources/views/risk/loss-events/near-misses.blade.php).
 *
 * Schema notes (verified against 2026_02_22_200022_create_near_misses_table):
 * the real reference column is `reference` (`event_reference` is a nullable
 * duplicate bolted on later), the loss amount is `potential_loss_kobo` in
 * minor units (`potential_loss` is an accessor), and the date is
 * `date_occurred`.
 */
class NearMissesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'near_misses';
    }

    public function permission(): string
    {
        return 'loss_event.view';
    }

    public function query(): Builder
    {
        // WP-00 node scoping is DELIBERATELY NOT APPLIED here, and this is
        // a gap rather than a decision that near misses are public.
        //
        // near_misses has no entity_id — the 2026_02_25 migration added the
        // column to risks, controls, issues, loss_events and key_risk_indicators
        // only — so there is nothing to prefix-match on. Its scoped relations
        // are linked_control_id and risk_register_id, and both are optional
        // annotations added during investigation rather than the record's
        // owner: most rows carry neither, so scoping through them would hide
        // almost every near miss from every pinned user and reveal the rest by
        // an accident of whether somebody had linked a control. That is
        // arbitrary, not node-scoped.
        //
        // The fix is to give near_misses an entity_id and the ScopedToGraph
        // trait, which is a migration and outside this change.
        return NearMiss::query()
            ->with('businessUnit')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            // The old Blade linked the reference to /risk/loss-events/{id}
            // with the NEAR MISS id — the loss-event show page for an
            // unrelated record. Near misses have no show route, so the
            // reference is plain text here.
            Column::make('reference', 'Reference')->sortable()->searchable(),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (NearMiss $nm) => str($nm->title)->limit(45)),

            Column::make('date_occurred', 'Date')->sortable()->date(),

            Column::make('businessUnit.name', 'Business Unit'),

            Column::make('potential_loss_kobo', 'Potential Loss')->sortable()->money(),

            Column::make('severity', 'Severity')->sortable()->rag([
                'critical' => 'red',
                'high' => 'red',
                'medium' => 'amber',
                'low' => 'green',
            ]),

            Column::make('status', 'Status')->sortable()->badge([
                'open' => 'bg-blue-100 text-blue-700',
                'investigating' => 'bg-yellow-100 text-yellow-700',
                'under review' => 'bg-yellow-100 text-yellow-700',
                'closed' => 'bg-gray-100 text-gray-600',
                'converted' => 'bg-purple-100 text-purple-700',
                '*' => 'bg-gray-100 text-gray-600',
            ]),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')->options([
                'open' => 'Open',
                'investigating' => 'Investigating',
                'under review' => 'Under Review',
                'closed' => 'Closed',
                'converted' => 'Converted',
            ]),

            Filter::make('severity', 'All Severities')->options([
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ]),

            Filter::make('business_unit', 'All Units')
                ->column('business_unit_id')
                ->options(
                    fn () => BusinessUnit::where('organization_id', TenantContext::organizationId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                ),
        ];
    }

    public function bulkActions(): array
    {
        return [
            // Replaces the per-row convert form (row actions are
            // navigation-only). Mirrors LossEventController::convertNearMiss.
            BulkAction::make('convert', 'Convert to Loss Event', 'swap_horiz', function ($nearMisses) {
                $converted = 0;

                foreach ($nearMisses as $nearMiss) {
                    if ($nearMiss->status === 'converted' || $nearMiss->converted_loss_event_id) {
                        continue;
                    }

                    $lossEvent = LossEvent::create([
                        'organization_id' => $nearMiss->organization_id,
                        'event_reference' => \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'),
                        'title' => 'Converted: '.$nearMiss->title,
                        'description' => $nearMiss->description."\n\n[Converted from Near-Miss: {$nearMiss->reference}]",
                        'date_of_loss' => $nearMiss->date_occurred,
                        'date_discovered' => $nearMiss->date_reported,
                        'business_unit_id' => $nearMiss->business_unit_id,
                        'gross_loss_amount_kobo' => $nearMiss->potential_loss_kobo ?? 0,
                        'event_severity' => strtoupper($nearMiss->severity ?? 'MEDIUM'),
                        'risk_register_id' => $nearMiss->risk_register_id,
                        'basel_l1_category' => 'UNCLASSIFIED',
                        'basel_l2_category' => 'UNCLASSIFIED',
                        'cbn_risk_category' => 'UNCLASSIFIED',
                        'loss_category' => 'actual_loss',
                        'current_status' => 'DRAFT',
                        'created_by' => auth()->id(),
                    ]);

                    $nearMiss->update([
                        'status' => 'converted',
                        'converted_loss_event_id' => $lossEvent->id,
                    ]);

                    \App\Services\AuditTrailService::record($lossEvent, 'create', null, null, null, "Converted from near-miss: {$nearMiss->reference}");
                    \App\Events\NearMissConverted::dispatch($nearMiss, $lossEvent);

                    $converted++;
                }

                return "{$converted} near ".str('miss')->plural($converted).' converted to loss events. Complete the Basel/CBN classification on each.';
            })->can('loss_event.create')
                ->confirm('Convert the selected near misses to loss events? Already-converted rows are skipped.'),
        ];
    }

    public function defaultSort(): array
    {
        return ['date_occurred', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No near misses recorded.';
    }

    public function emptyIcon(): string
    {
        return 'warning';
    }
}
