<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The risk assessment register (WP-09 migration of
 * resources/views/risk/assessments/index.blade.php).
 *
 * Schema notes: the old Blade read `likelihood`, `impact` and `rating`,
 * which are accessors bridging the real columns `likelihood_score`,
 * `impact_score` and `overall_rating`. `score_change` was never a column —
 * the old index silently showed 0 for every row because only show()
 * computed it; here a correlated subselect fetches the chronologically
 * previous assessment's overall_score so the delta is real.
 */
class RiskAssessmentsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'assessments';
    }

    public function permission(): string
    {
        return 'assessment.view';
    }

    public function query(): Builder
    {
        // Correlated subselect: the previous assessment of the same risk,
        // ordered the way show() orders history (assessment_date, then id).
        $previous = DB::table('risk_assessments as prev')
            ->select('prev.overall_score')
            ->whereColumn('prev.risk_id', 'risk_assessments.risk_id')
            ->where(function ($q) {
                $q->whereColumn('prev.assessment_date', '<', 'risk_assessments.assessment_date')
                    ->orWhere(function ($q) {
                        $q->whereColumn('prev.assessment_date', '=', 'risk_assessments.assessment_date')
                            ->whereColumn('prev.id', '<', 'risk_assessments.id');
                    });
            })
            ->orderByDesc('prev.assessment_date')
            ->orderByDesc('prev.id')
            ->limit(1);

        return RiskAssessment::query()
            ->with(['risk', 'assessor'])
            ->where('organization_id', TenantContext::organizationId())
            ->select('risk_assessments.*')
            ->addSelect(['previous_overall_score' => $previous]);
    }

    public function columns(): array
    {
        return [
            Column::make('id', 'Assessment ID')
                ->sortable()
                ->using(fn (RiskAssessment $a) => 'ASS-'.str_pad((string) $a->id, 4, '0', STR_PAD_LEFT))
                ->linkTo(fn (RiskAssessment $a) => route('risk.assessments.show', $a)),

            Column::make('risk.risk_code', 'Risk')
                ->linkTo(fn (RiskAssessment $a) => route('risk.register.show', $a->risk_id)),

            Column::make('assessor.name', 'Assessor'),

            Column::make('assessment_date', 'Assessment Date')->sortable()->date(),

            Column::make('likelihood_score', 'Likelihood')->sortable()
                ->using(fn (RiskAssessment $a) => ($a->likelihood_score ?? '–').'/5'),

            Column::make('impact_score', 'Impact')->sortable()
                ->using(fn (RiskAssessment $a) => ($a->impact_score !== null ? (int) $a->impact_score : '–').'/5'),

            Column::make('overall_score', 'Overall Score')->sortable(),

            // Actual band labels from the scoring profile (Title Case).
            Column::make('overall_rating', 'Rating')->searchable()->rag([
                'Critical' => 'red',
                'High' => 'red',
                'Medium' => 'amber',
                'Low' => 'green',
            ]),

            Column::make('score_change', 'vs Previous')
                ->using(function (RiskAssessment $a) {
                    if ($a->previous_overall_score === null || $a->overall_score === null) {
                        return '—';
                    }

                    $delta = (int) $a->overall_score - (int) $a->previous_overall_score;

                    return match (true) {
                        $delta > 0 => "▲ +{$delta}",
                        $delta < 0 => "▼ {$delta}",
                        default => '0',
                    };
                }),

            Column::make('status', 'Status')->sortable()->searchable()->badge([
                'draft' => 'bg-gray-100 text-gray-700',
                'in_review' => 'bg-blue-100 text-blue-700',
                'approved' => 'bg-green-100 text-green-700',
                'rejected' => 'bg-red-100 text-red-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('assessment_type', 'Type')
                ->hiddenByDefault()->searchable()
                ->using(fn (RiskAssessment $a) => ucfirst(str_replace('_', ' ', $a->assessment_type ?? '—'))),

            Column::make('assessment_notes', 'Notes')
                ->hiddenByDefault()->searchable()
                ->using(fn (RiskAssessment $a) => str($a->assessment_notes ?? '')->limit(60)),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('risk_id', 'All Risks')->options(
                fn () => Risk::where('organization_id', TenantContext::organizationId())
                    ->orderBy('risk_code')
                    ->pluck('risk_code', 'id')
                    ->all()
            ),

            Filter::make('assessment_type', 'All Types')->options([
                'initial' => 'Initial',
                'periodic' => 'Periodic',
                'event_driven' => 'Event Driven',
                'triggered' => 'Triggered',
                'annual' => 'Annual',
            ]),

            // Real workflow statuses — the old Blade offered in_progress and
            // completed, values no assessment ever carries.
            Filter::make('status', 'All Statuses')->options([
                'draft' => 'Draft',
                'in_review' => 'In Review',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (RiskAssessment $a) => route('risk.assessments.show', $a)),
        ];
    }

    public function defaultSort(): array
    {
        return ['assessment_date', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No assessments found.';
    }

    public function emptyIcon(): string
    {
        return 'assessment';
    }
}
