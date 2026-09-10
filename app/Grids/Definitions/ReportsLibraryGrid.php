<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Jobs\GenerateReportJob;
use App\Models\GeneratedReport;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The report library (WP-09 migration of
 * resources/views/risk/reports/library.blade.php).
 *
 * Schema notes (verified against 2026_04_23_120000_create_generated_reports_table
 * and 2026_08_10_100004_add_artifact_columns_to_generated_reports): the title is
 * `name`, the as-at date is `period_as_at` (a date, distinct from the legacy
 * free-text `period` and from created_at), the artifact fields are `format`,
 * `version` and `status`, and the author relation is `generatedBy`.
 *
 * "Position as at" is rendered through using() rather than ->date() so the
 * legacy fallback the old cell had — period_as_at, else the free-text period,
 * else an em dash — survives; a legacy row would otherwise read as blank.
 *
 * Download stays a RowAction pointing at risk.reports.download. Row actions are
 * not conditional per row in this engine, so the link is always offered and the
 * download controller remains the authority on whether an artifact exists —
 * which it already was.
 */
class ReportsLibraryGrid extends GridDefinition
{
    public function name(): string
    {
        return 'reports_library';
    }

    public function permission(): string
    {
        return 'report.view';
    }

    public function query(): Builder
    {
        return GeneratedReport::query()
            ->with('generatedBy')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('name', 'Report')->sortable()->searchable(),

            Column::make('report_type', 'Type')->sortable()->searchable()->badge([
                'board_pack' => 'bg-purple-100 text-purple-700',
                'executive' => 'bg-blue-100 text-blue-700',
                'regulatory' => 'bg-orange-100 text-orange-700',
                'risk_register' => 'bg-teal-100 text-teal-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('period_as_at', 'Position As At')
                ->sortable()
                ->using(fn (GeneratedReport $r) => $r->period_as_at?->format('d M Y') ?? $r->period ?? '—'),

            Column::make('version', 'Version')
                ->sortable()
                ->using(fn (GeneratedReport $r) => 'v'.$r->version),

            Column::make('format', 'Format')->sortable()->badge([
                'pdf' => 'bg-red-100 text-red-700',
                'xlsx' => 'bg-green-100 text-green-700',
                'csv' => 'bg-gray-100 text-gray-700',
                '*' => 'bg-gray-100 text-gray-600',
            ]),

            Column::make('created_at', 'Generated')->sortable()->datetime('d M y H:i'),

            Column::make('generatedBy.name', 'By'),

            Column::make('status', 'Status')->sortable()->badge([
                'completed' => 'bg-green-100 text-green-700',
                'failed' => 'bg-red-100 text-red-700',
                'queued' => 'bg-yellow-100 text-yellow-700',
                'processing' => 'bg-yellow-100 text-yellow-700',
                '*' => 'bg-gray-100 text-gray-600',
            ]),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('report_type', 'All Types')
                ->options(collect(GenerateReportJob::TYPES)
                    ->mapWithKeys(fn ($type) => [$type => ucwords(str_replace('_', ' ', $type))])
                    ->all()),

            Filter::make('status', 'All Statuses')->options([
                'queued' => 'Queued',
                'processing' => 'Processing',
                'completed' => 'Completed',
                'failed' => 'Failed',
            ]),

            Filter::make('format', 'All Formats')->options([
                'pdf' => 'PDF',
                'xlsx' => 'Excel workbook',
                'csv' => 'CSV',
            ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Download', 'download', fn (GeneratedReport $r) => route('risk.reports.download', $r)),
            RowAction::make('View progress', 'pending', fn (GeneratedReport $r) => route('risk.reports.status', $r)),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No reports have been generated yet.';
    }

    public function emptyIcon(): string
    {
        return 'description';
    }
}
