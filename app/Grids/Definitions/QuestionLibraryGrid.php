<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Models\QuestionLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared question library (WP-09 migration of
 * resources/views/risk/questionnaires/library.blade.php).
 *
 * The library deliberately mixes each tenant's own questions with system-wide
 * ones (organization_id NULL, is_global true) — QuestionLibrary opts into that
 * through $tenantIncludesGlobal, so the model's global scope already returns
 * "mine OR global". The clause is restated here because GridDefinition::query()
 * is contractually the place tenancy is established, and because matching on a
 * NULL organization_id is stricter than the controller's old
 * `orWhere('is_global', true)`: a row flagged global but stamped with another
 * tenant's id was visible to everyone under the old rule.
 *
 * The page used paginate(50), so the per-page options start there.
 */
class QuestionLibraryGrid extends GridDefinition
{
    public function name(): string
    {
        return 'question_library';
    }

    public function permission(): string
    {
        return 'questionnaire.view';
    }

    public function query(): Builder
    {
        $organizationId = TenantContext::organizationId();

        return QuestionLibrary::query()
            ->where(fn (Builder $q) => $q
                ->where('question_library.organization_id', $organizationId)
                ->orWhereNull('question_library.organization_id'));
    }

    public function columns(): array
    {
        return [
            Column::make('category', 'Category')
                ->sortable()->searchable()
                ->badge(['*' => 'bg-blue-50 text-blue-700']),

            Column::make('question_text', 'Question')
                ->searchable()
                ->using(fn (QuestionLibrary $q) => str($q->question_text ?? '')->limit(80)),

            Column::make('question_type', 'Type')
                ->sortable()
                ->using(fn (QuestionLibrary $q) => ucfirst(str_replace('_', ' ', (string) ($q->question_type ?? '—')))),

            Column::make('usage_count', 'Usage')->sortable()->count(),

            Column::make('is_global', 'Scope')
                ->hiddenByDefault()
                ->using(fn (QuestionLibrary $q) => $q->organization_id === null ? 'Shared' : 'Organization'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('question_type', 'All Types')->options([
                'likert' => 'Likert',
                'rating' => 'Rating',
                'yes_no' => 'Yes/No',
                'free_text' => 'Free Text',
                'multiple_choice' => 'Multiple Choice',
                'numeric' => 'Numeric',
            ]),

            Filter::make('category', 'All Categories')
                ->options(fn () => QuestionLibrary::query()
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->mapWithKeys(fn ($category) => [$category => $category])
                    ->all()),
        ];
    }

    public function perPageOptions(): array
    {
        return [50, 100];
    }

    public function defaultSort(): array
    {
        return ['category', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No questions in library.';
    }

    public function emptyIcon(): string
    {
        return 'help_center';
    }
}
