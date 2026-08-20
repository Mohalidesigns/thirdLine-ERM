<?php

namespace App\Grids\Definitions;

use App\Grids\BulkAction;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Support\Authorization\GraphScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The KRI breach register (WP-09 migration of
 * resources/views/risk/kri/breaches.blade.php).
 *
 * Rows are measure_breaches (the WP-04 register), not measurements: a breach
 * has a lifecycle — open → acknowledged → resolved/false_positive — and the
 * KRI-facing fields (name, category, owner, unit) come off the measure and
 * its facade KRI. The base query joins `measures` (as a sub-select with
 * renamed columns, so no column name collides with measure_breaches — the
 * engine plucks the unqualified primary key for select-all) so search and the
 * KRI column sort run server-side against the measure's name/code.
 *
 * The page mounts this grid with initialFilters ['status' => 'active'], so
 * the default view stays the work list (open + acknowledged), as before.
 */
class KriBreachesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'kri_breaches';
    }

    public function permission(): string
    {
        return 'kri.view';
    }

    public function query(): Builder
    {
        $query = MeasureBreach::query()
            ->joinSub(
                DB::table('measures')->select('id as measure_row_id', 'name as measure_name', 'code as measure_code'),
                'measure_names',
                'measure_names.measure_row_id',
                '=',
                'measure_breaches.measure_id'
            )
            ->where('measure_breaches.organization_id', TenantContext::organizationId())
            ->select('measure_breaches.*')
            ->with(['measure.unit', 'measure.keyRiskIndicator.risk.category', 'measure.owner', 'period', 'acknowledgedBy']);

        // WP-00 node scoping, two relations deep. A breach hangs off a measure,
        // and a measure is the KRI's twin — KriMeasureMigrator writes
        // measures.code = key_risk_indicators.kri_code, which is exactly the
        // join Measure::keyRiskIndicator() makes — so the breach inherits the
        // KRI's node. The breach row states the reading that broke a limit and
        // names the unit that reported it, which is the same disclosure the KRI
        // itself is.
        //
        // JUDGEMENT CALL: measure_breaches also covers measures that are not
        // KRIs at all (measure_kind is only sometimes 'kri'), and those have no
        // KRI to inherit from. They fall to the orWhereDoesntHave arm inside
        // applyThrough and stay visible while subtree_users_see_unassigned is
        // true, which is the same treatment a record with a NULL entity_id
        // gets. This screen is the KRI breach screen, so in practice almost
        // every row resolves to a KRI and is scoped.
        return GraphScope::applyThrough($query, 'measure.keyRiskIndicator');
    }

    public function columns(): array
    {
        return [
            Column::make('kri_name', 'KRI')
                ->sortable('measure_name')->searchable('measure_name')
                ->using(fn (MeasureBreach $b) => $b->measure?->name ?? '—')
                ->linkTo(function (MeasureBreach $b) {
                    $kri = $b->measure?->keyRiskIndicator;

                    return $kri ? route('risk.kri.show', $kri) : '#';
                }),

            Column::make('category', 'Category')
                ->using(fn (MeasureBreach $b) => $b->measure?->keyRiskIndicator?->risk?->category?->name ?? '—'),

            Column::make('value', 'Current Value')
                ->using(fn (MeasureBreach $b) => number_format(
                    (float) $b->value,
                    $b->measure?->decimal_places ?? 2
                ).$this->unitSuffix($b)),

            Column::make('threshold_value', 'Threshold')
                ->using(fn (MeasureBreach $b) => $b->threshold_value === null
                    ? '—'
                    : number_format((float) $b->threshold_value, $b->measure?->decimal_places ?? 2).$this->unitSuffix($b)),

            Column::make('band_to', 'Breach Level')->rag([
                'red' => 'red',
                'amber' => 'amber',
            ]),

            Column::make('days_in_breach', 'Days in Breach')
                ->using(fn (MeasureBreach $b) => (int) abs(
                    ($b->resolved_at ?? now())->diffInDays($b->breached_at)
                ).' days'),

            Column::make('owner', 'Owner')
                ->using(fn (MeasureBreach $b) => $b->measure?->owner?->name ?? '—'),

            Column::make('breached_at', 'Breach Date')->sortable()->date(),

            Column::make('status', 'Status')->badge([
                'open' => 'bg-red-100 text-red-700',
                'acknowledged' => 'bg-blue-100 text-blue-700',
                'resolved' => 'bg-green-100 text-green-700',
                'false_positive' => 'bg-gray-100 text-gray-600',
            ]),

            Column::make('kri_code', 'Code')
                ->hiddenByDefault()->searchable('measure_code')
                ->using(fn (MeasureBreach $b) => $b->measure?->code ?? '—'),
        ];
    }

    public function filters(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            // The register's default view is the work list, not the archive;
            // the page passes status=active as the initial filter value.
            Filter::make('status', 'Breach Status')
                ->options([
                    'active' => 'Open & acknowledged',
                    'closed' => 'Closed',
                    'all' => 'All',
                ])
                ->apply(function (Builder $query, string $value) {
                    match ($value) {
                        'closed' => $query->whereIn('measure_breaches.status', ['resolved', 'false_positive']),
                        'all' => null,
                        default => $query->whereIn('measure_breaches.status', ['open', 'acknowledged']),
                    };
                }),

            Filter::make('level', 'All Levels')
                ->column('band_to')
                ->options([
                    'red' => 'Red only',
                    'amber' => 'Amber only',
                ]),

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
                    ->whereHas('measure.keyRiskIndicator.risk.category', fn ($q) => $q->where('name', $value))),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open KRI', 'visibility', function (MeasureBreach $b) {
                $kri = $b->measure?->keyRiskIndicator;

                return $kri ? route('risk.kri.show', $kri) : '#';
            }),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('acknowledge', 'Acknowledge', 'how_to_reg', function ($breaches) {
                $count = 0;
                foreach ($breaches->where('status', 'open') as $breach) {
                    $breach->update([
                        'status' => 'acknowledged',
                        'acknowledged_by' => auth()->id(),
                        'acknowledged_at' => now(),
                    ]);
                    \App\Services\AuditTrailService::record($breach, 'breach_acknowledged', 'status', 'open', 'acknowledged');
                    $count++;
                }

                return "{$count} ".str('breach')->plural($count).' acknowledged.';
            })->can('kri.acknowledge_breach')
                ->confirm('Acknowledge the selected open breaches?'),

            BulkAction::make('resolve', 'Close as resolved', 'task_alt', function ($breaches) {
                $count = 0;
                foreach ($breaches->whereIn('status', ['open', 'acknowledged']) as $breach) {
                    $previous = $breach->status;
                    $breach->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                    ]);
                    \App\Services\AuditTrailService::record($breach, 'breach_closed', 'status', $previous, 'resolved');
                    $count++;
                }

                return "{$count} ".str('breach')->plural($count).' closed as resolved.';
            })->can('kri.acknowledge_breach')
                ->confirm('Close the selected breaches as resolved?'),
        ];
    }

    public function defaultSort(): array
    {
        return ['breached_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No breaches match. All KRIs are within tolerance.';
    }

    public function emptyIcon(): string
    {
        return 'verified';
    }

    private function unitSuffix(MeasureBreach $breach): string
    {
        $symbol = $breach->measure?->unit?->symbol;

        return $symbol ? ' '.$symbol : '';
    }
}
