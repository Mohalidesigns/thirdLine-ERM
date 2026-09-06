<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Questionnaire;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The questionnaire library (WP-09 migration of
 * resources/views/risk/questionnaires/index.blade.php).
 *
 * The old row onclick sent everybody to the edit screen, which is gated on
 * questionnaire.edit — a viewer clicking a row got a 403. The primary link
 * points at show (gated on the same questionnaire.view this grid requires)
 * and editing is a permission-gated row action.
 */
class QuestionnairesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'questionnaires';
    }

    public function permission(): string
    {
        return 'questionnaire.view';
    }

    public function query(): Builder
    {
        return Questionnaire::query()
            ->with('creator')
            ->withCount('sections')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->linkTo(fn (Questionnaire $q) => route('risk.questionnaires.show', $q)),

            Column::make('questionnaire_type', 'Type')->sortable()->badge([
                'rcsa' => 'bg-blue-100 text-blue-700',
                'fraud_risk' => 'bg-red-100 text-red-700',
                'compliance' => 'bg-purple-100 text-purple-700',
                'new_product' => 'bg-teal-100 text-teal-700',
                'vendor' => 'bg-orange-100 text-orange-700',
                'custom' => 'bg-gray-100 text-gray-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('sections_count', 'Sections')->sortable()->count(),

            Column::make('status', 'Status')->sortable()->badge([
                'draft' => 'bg-gray-100 text-gray-700',
                'published' => 'bg-green-100 text-green-700',
                'archived' => 'bg-red-100 text-red-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('version', 'Version')
                ->sortable()
                ->using(fn (Questionnaire $q) => 'v'.($q->version ?? 1)),

            Column::make('created_at', 'Created')->sortable()->date(),

            Column::make('scoring_method', 'Scoring')
                ->hiddenByDefault()
                ->using(fn (Questionnaire $q) => ucfirst((string) ($q->scoring_method ?? '—'))),

            Column::make('creator.name', 'Created By')->hiddenByDefault(),

            Column::make('description', 'Description')
                ->hiddenByDefault()->searchable()
                ->using(fn (Questionnaire $q) => str($q->description ?? '')->limit(80)),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')->options([
                'draft' => 'Draft',
                'published' => 'Published',
                'archived' => 'Archived',
            ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (Questionnaire $q) => route('risk.questionnaires.show', $q)),
            RowAction::make('Edit', 'edit', fn (Questionnaire $q) => route('risk.questionnaires.edit', $q))
                ->can('questionnaire.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No questionnaires yet.';
    }

    public function emptyIcon(): string
    {
        return 'quiz';
    }
}
