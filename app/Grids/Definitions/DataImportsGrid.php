<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Models\DataImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The data import history (WP-09 migration of
 * resources/views/risk/imports/index.blade.php).
 *
 * Column names come from the data_imports table: file_name, import_type,
 * total_rows, success_count, error_count, status and imported_by (the
 * relation is DataImport::importer()). The old table had no filter UI at all.
 */
class DataImportsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'imports';
    }

    public function permission(): string
    {
        return 'import.view';
    }

    public function query(): Builder
    {
        return DataImport::query()
            ->with('importer')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('file_name', 'File')->sortable()->searchable(),

            Column::make('import_type', 'Type')->sortable()->badge([
                'risks' => 'bg-blue-100 text-blue-700',
                'controls' => 'bg-indigo-100 text-indigo-700',
                'loss_events' => 'bg-red-100 text-red-700',
                'issues' => 'bg-orange-100 text-orange-700',
                'kris' => 'bg-teal-100 text-teal-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('total_rows', 'Total')->sortable()->count(),

            Column::make('success_count', 'Success')->sortable()->count(),

            Column::make('error_count', 'Errors')->sortable()->count(),

            Column::make('status', 'Status')->sortable()->badge([
                'pending' => 'bg-gray-100 text-gray-700',
                'processing' => 'bg-yellow-100 text-yellow-700',
                'completed' => 'bg-green-100 text-green-700',
                'failed' => 'bg-red-100 text-red-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('importer.name', 'Imported By'),

            Column::make('created_at', 'Date')->sortable()->datetime(),

            Column::make('skipped_count', 'Skipped')->hiddenByDefault()->sortable()->count(),

            Column::make('completed_at', 'Completed')->hiddenByDefault()->sortable()->datetime(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')->options([
                'pending' => 'Pending',
                'processing' => 'Processing',
                'completed' => 'Completed',
                'failed' => 'Failed',
            ]),

            Filter::make('import_type', 'All Types')->options([
                'risks' => 'Risks',
                'controls' => 'Controls',
                'loss_events' => 'Loss Events',
                'issues' => 'Issues',
                'kris' => 'KRIs',
            ]),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No imports yet.';
    }

    public function emptyIcon(): string
    {
        return 'upload_file';
    }
}
