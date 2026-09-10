<?php

namespace App\Grids\Definitions;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\EmergingRisk;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The emerging risk register (WP-09 migration of
 * resources/views/risk/emerging/index.blade.php).
 *
 * Two behaviours moved rather than disappeared:
 *
 *  - The old table carried a POST form per row for "mark reviewed today" and
 *    another for delete. Row actions in this engine are navigation-only by
 *    design, so both became bulk actions: they re-fetch through the grid's own
 *    query (so a forged id cannot cross tenants) and re-check their route's
 *    permission server-side, which the inline forms only ever got from the
 *    route middleware.
 *
 *  - The controller ordered by (velocity_score * proximity_score) DESC — the
 *    radar score, so the register opens on the most urgent scan. The engine
 *    sorts by a declared column, so query() selects that product as a
 *    radar_score alias and the column is declared against it; the ordering is
 *    the one the register always had. Both scores also sort individually.
 *
 * Emerging risks have no show route (only edit), so the reference links to
 * the edit form, as the title did before.
 */
class EmergingRisksGrid extends GridDefinition
{
    public function name(): string
    {
        return 'emerging_risks';
    }

    public function permission(): string
    {
        return 'risk.view';
    }

    public function query(): Builder
    {
        // radar_score is selected as a real column so the engine can sort on
        // it: the register has always opened on the most urgent horizon scan
        // first, and velocity × proximity is what "urgent" means here.
        // WP-00 node scoping is DELIBERATELY NOT APPLIED here. emerging_risks
        // has no entity_id, and its only link to a scoped model is
        // converted_risk_id, which is NULL until the horizon scan graduates
        // into the register — scoping on it would show a subtree user only the
        // emerging risks that had already stopped being emerging.
        //
        // Horizon scanning is also whole-organization work by nature: an
        // emerging risk is a thing nobody owns yet, which is why it has no node
        // to be pinned to.
        return EmergingRisk::query()
            ->select('emerging_risks.*')
            ->selectRaw('(emerging_risks.velocity_score * emerging_risks.proximity_score) as radar_score')
            ->with(['category', 'owner', 'convertedRisk'])
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (EmergingRisk $e) => route('risk.emerging.edit', $e)),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (EmergingRisk $e) => str($e->title)->limit(55)),

            Column::make('source', 'Source')
                ->searchable()
                ->hiddenByDefault()
                ->using(fn (EmergingRisk $e) => $e->source ? (string) str($e->source)->limit(45) : '—'),

            Column::make('category.name', 'Category'),

            Column::make('horizon', 'Horizon')->sortable()->badge([
                '0-3m' => 'bg-red-100 text-red-700',
                '3-6m' => 'bg-orange-100 text-orange-700',
                '6-12m' => 'bg-yellow-100 text-yellow-700',
                '12m+' => 'bg-gray-100 text-gray-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('velocity_score', 'Velocity')
                ->sortable()
                ->using(fn (EmergingRisk $e) => $e->velocity_score.'/5'),

            Column::make('proximity_score', 'Proximity')
                ->sortable()
                ->using(fn (EmergingRisk $e) => $e->proximity_score.'/5'),

            // The radar score the register sorts on by default (see query()).
            Column::make('radar_score', 'Radar Score')
                ->sortable()->hiddenByDefault()
                ->using(fn (EmergingRisk $e) => (int) ($e->velocity_score * $e->proximity_score)),

            Column::make('potential_impact', 'Impact')->sortable()->rag([
                'Critical' => 'red',
                'High' => 'red',
                'Medium' => 'amber',
                'Low' => 'green',
            ]),

            Column::make('status', 'Status')->sortable()->badge([
                'monitoring' => 'bg-blue-100 text-blue-700',
                'assessing' => 'bg-yellow-100 text-yellow-700',
                'escalated' => 'bg-red-100 text-red-700',
                'converted' => 'bg-purple-100 text-purple-700',
                'closed' => 'bg-gray-100 text-gray-600',
                '*' => 'bg-gray-100 text-gray-600',
            ]),

            // The old cell said "Never" in amber when the entry had never been
            // reviewed, which is the one thing this column exists to surface.
            Column::make('last_reviewed_at', 'Reviewed')
                ->sortable()
                ->using(fn (EmergingRisk $e) => $e->last_reviewed_at?->format('d M y') ?? 'Never'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')
                ->options(collect(EmergingRisk::STATUSES)
                    ->mapWithKeys(fn ($status) => [$status => ucfirst($status)])
                    ->all()),

            Filter::make('horizon', 'All Horizons')
                ->options(collect(EmergingRisk::HORIZONS)
                    ->mapWithKeys(fn ($horizon) => [$horizon => $horizon])
                    ->all()),

            Filter::make('impact', 'All Impacts')
                ->column('potential_impact')
                ->options(collect(EmergingRisk::IMPACTS)
                    ->mapWithKeys(fn ($impact) => [$impact => $impact])
                    ->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Edit', 'edit', fn (EmergingRisk $e) => route('risk.emerging.edit', $e))
                ->can('risk.edit'),
        ];
    }

    public function bulkActions(): array
    {
        return [
            // Exactly EmergingRiskController::review — a date, not a timestamp,
            // because last_reviewed_at is a date column.
            BulkAction::make('mark_reviewed', 'Mark reviewed today', 'event_available', function ($entries) {
                $count = 0;

                foreach ($entries as $entry) {
                    $entry->update(['last_reviewed_at' => now()->toDateString()]);
                    $count++;
                }

                return "{$count} ".str('entry')->plural($count).' marked as reviewed today.';
            })->can('risk.edit')
                ->confirm('Mark the selected entries as reviewed today?'),

            // EmergingRisk soft-deletes, so this is the same reversible removal
            // the per-row delete form performed.
            BulkAction::make('delete', 'Remove from register', 'delete', function ($entries) {
                $count = $entries->count();
                $entries->each->delete();

                return "{$count} ".str('entry')->plural($count).' removed from the register.';
            })->can('risk.delete')
                ->confirm('Remove the selected entries from the emerging risk register?'),
        ];
    }

    public function defaultSort(): array
    {
        return ['radar_score', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No emerging risks recorded yet.';
    }

    public function emptyIcon(): string
    {
        return 'radar';
    }
}
