<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Enums\Tprm\AssessmentStatus;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * Where every assessment has got to — FR-RPT-07.
 *
 * IT INCLUDES ENGAGEMENTS WITH NO ASSESSMENT AT ALL, and that is the point of
 * running it. A list of assessments answers "how are the assessments going";
 * the question a programme manager actually has is "who has not been
 * assessed", and a report built only from `tp_assessments` cannot answer it —
 * the rows that matter are the ones that do not exist.
 */
class AssessmentStatusReport implements OperationalReport
{
    use StatesAbsence;

    public function key(): string
    {
        return 'assessment-status';
    }

    public function title(): string
    {
        return 'Assessment status';
    }

    public function description(): string
    {
        return 'Every live engagement and the state of its most recent assessment, including those that have '
            .'never been assessed.';
    }

    public function permission(): string
    {
        return 'tprm.assessment.view';
    }

    public function headers(): array
    {
        return [
            'Engagement', 'Provider', 'Tier', 'Assessment', 'Cycle', 'Status', 'Issued', 'Due',
            'Submitted', 'Validated', 'Days in current state', 'Next assessment due', 'Overdue',
        ];
    }

    public function rows(): array
    {
        $engagements = Engagement::query()
            ->with(['thirdParty:id,legal_name'])
            ->get()
            ->filter(fn (Engagement $engagement) => $engagement->status->isLive());

        $latest = Assessment::query()
            ->whereIn('engagement_id', $engagements->map(fn (Engagement $e) => $e->getKey())->all())
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('engagement_id')
            ->map(fn ($group) => $group->first());

        return $engagements
            ->sortBy(fn (Engagement $e) => $e->reference)
            ->map(function (Engagement $engagement) use ($latest) {
                $assessment = $latest->get($engagement->getKey());

                return [
                    $engagement->reference,
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->effective_tier?->label() ?? 'Not tiered',
                    // "Never assessed" is a row, not an omission. An
                    // assessment has no reference column of its own; the
                    // template and cycle are how a reader identifies one.
                    $assessment === null
                        ? 'Never assessed'
                        : ($assessment->template_version ? 'Template v'.$assessment->template_version : 'Assessment'),
                    $assessment === null ? '' : (string) $assessment->cycle_label,
                    $assessment?->status->label() ?? 'No assessment on record',
                    $assessment?->issued_at?->toDateString() ?? '',
                    $assessment?->due_at?->toDateString() ?? '',
                    $assessment?->submitted_at?->toDateString() ?? '',
                    $assessment?->validated_at?->toDateString() ?? '',
                    $assessment === null ? '' : (int) $assessment->updated_at->diffInDays(now()),
                    $engagement->next_assessment_due?->toDateString() ?? 'No cadence set',
                    $this->overdueLabel($engagement, $assessment),
                ];
            })
            ->values()
            ->all();
    }

    public function notes(): array
    {
        return [
            'Population' => 'Live engagements, whether or not an assessment exists',
            'Assessment shown' => 'The most recently issued one',
        ];
    }

    private function overdueLabel(Engagement $engagement, ?Assessment $assessment): string
    {
        if ($engagement->next_assessment_due === null) {
            // Nothing is due, so nothing is late. A different problem, and not
            // a smaller one than being overdue.
            return 'No cadence set';
        }

        if ($engagement->next_assessment_due->isFuture()) {
            return 'No';
        }

        return $assessment?->status === AssessmentStatus::Validated
            ? 'Yes — reassessment due'
            : 'Yes';
    }
}
