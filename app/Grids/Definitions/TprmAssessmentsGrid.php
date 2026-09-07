<?php

namespace App\Grids\Definitions;

use App\Enums\Tprm\AssessmentStatus;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Tprm\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Assessment Console — TRD §11's status board, reviewer workload and
 * ageing.
 *
 * OVERDUE IS A FILTER, NOT A STATUS, and the distinction is the same one the
 * findings module makes: overdue is a function of `due_at` and the clock, so a
 * stored status would go stale at midnight and every report reading status
 * would disagree with every report reading dates.
 *
 * The default sort is due date ascending with nulls last, so the console opens
 * on what is late rather than on what was created most recently.
 */
class TprmAssessmentsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_assessments';
    }

    public function permission(): string
    {
        return 'tprm.assessment.view';
    }

    public function query(): Builder
    {
        return Assessment::query()
            ->select('tp_assessments.*')
            ->with([
                'engagement:id,uuid,reference,name,third_party_id,effective_tier',
                'engagement.thirdParty:id,legal_name',
                'template:id,code,name',
                'reviewer:id,name',
            ])
            ->where('tp_assessments.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('engagement.reference', 'Engagement')
                ->searchable('tp_engagements.reference')
                ->linkTo(fn (Assessment $a) => route('tprm.assessments.show', $a))
                ->using(fn (Assessment $a) => $a->engagement->reference),

            Column::make('third_party', 'Third party')
                ->using(fn (Assessment $a) => $a->engagement->thirdParty->legal_name),

            Column::make('template.name', 'Questionnaire')
                ->using(fn (Assessment $a) => $a->template->name),

            Column::make('cycle_label', 'Cycle')
                ->sortable()
                ->using(fn (Assessment $a) => $a->cycle_label ?: ucfirst((string) $a->assessment_type)),

            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (Assessment $a) => $a->status->label())
                ->rag([
                    'Scored' => 'green', 'Closed' => 'green', 'Validated' => 'green',
                    'Expired' => 'red',
                    'Submitted' => 'amber', 'Under review' => 'amber', 'Clarification requested' => 'amber',
                    'Draft' => 'neutral', 'Withdrawn' => 'neutral',
                ]),

            Column::make('due_at', 'Due')
                ->sortable()
                ->using(function (Assessment $a) {
                    if ($a->due_at === null) {
                        return 'No due date';
                    }

                    // Overdue is stated in days, because "12 Aug" makes a
                    // reader do the arithmetic and a console exists to save
                    // them that.
                    $days = $a->daysOverdue();

                    return $days !== null
                        ? $a->due_at->format('d M Y')." ({$days}d overdue)"
                        : $a->due_at->format('d M Y');
                }),

            Column::make('reviewer.name', 'Reviewer')
                ->using(fn (Assessment $a) => $a->reviewer->name ?? 'Unassigned'),

            Column::make('progress', 'Answered')
                ->using(fn (Assessment $a) => $a->applicable_count > 0
                    ? "{$a->answered_count} / {$a->applicable_count}"
                    : '—'),

            Column::make('ac', 'Coverage')
                ->sortable()
                ->hiddenByDefault()
                // Absent until the assessment is scored, and absent prints as
                // absent rather than as 0.
                ->using(fn (Assessment $a) => $a->ac === null ? 'Not scored' : number_format((float) $a->ac, 2)),

            Column::make('ec', 'Evidence confidence')
                ->sortable()
                ->hiddenByDefault()
                ->using(fn (Assessment $a) => $a->ec === null ? 'Not scored' : number_format((float) $a->ec, 2)),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All statuses')
                ->column('tp_assessments.status')
                ->options(fn () => collect(AssessmentStatus::cases())
                    ->mapWithKeys(fn (AssessmentStatus $s) => [$s->value => $s->label()])->all()),

            Filter::make('due', 'Due date')
                ->options([
                    'overdue' => 'Overdue',
                    'due_14' => 'Due within 14 days',
                    'none' => 'No due date',
                ])
                ->apply(function (Builder $query, string $value): void {
                    match ($value) {
                        'overdue' => $query
                            ->whereNotNull('due_at')
                            ->where('due_at', '<', now())
                            ->whereIn('status', ['issued', 'in_progress', 'clarification_requested']),
                        'due_14' => $query->whereBetween('due_at', [now(), now()->addDays(14)]),
                        default => $query->whereNull('due_at'),
                    };
                }),

            Filter::make('reviewer', 'All reviewers')
                ->column('tp_assessments.internal_reviewer_id')
                ->options(fn () => User::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->where('is_active', true)
                    ->orderBy('name')->pluck('name', 'id')->all()),

            Filter::make('type', 'All types')
                ->column('tp_assessments.assessment_type')
                ->options(fn () => collect(Assessment::TYPES)
                    ->mapWithKeys(fn (string $t) => [$t => ucwords(str_replace('_', ' ', $t))])->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open', 'visibility', fn (Assessment $a) => route('tprm.assessments.show', $a)),
        ];
    }

    public function defaultSort(): array
    {
        return ['due_at', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No assessments yet. Issue one from an engagement.';
    }
}
