<?php

namespace App\Services\Tprm\Portal;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\ComplianceLevel;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentDelegation;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\QuestionnaireSection;
use App\Services\Tprm\Assessment\AssessmentService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The vendor's side of answering a questionnaire — FR-PRT-03.
 *
 * SAVE-AND-RESUME IS THE DEFAULT AND NOT A FEATURE. A tier-1 questionnaire is
 * forty questions that need three people and a week; a form that loses work on
 * a dropped connection is a form that gets answered in a spreadsheet and
 * emailed instead, which is the behaviour this whole module exists to end.
 * Every `saveAnswer()` writes immediately, one response at a time.
 *
 * A VENDOR CAN ONLY EVER TOUCH ITS OWN ASSESSMENT, and the check is here
 * rather than in a controller because the portal has several routes into an
 * answer — the section screen, the inline evidence upload, the delegation
 * hand-off — and a guard on one of them is a guard on one of them.
 */
class PortalAssessmentService
{
    public function __construct(private readonly AssessmentService $assessments) {}

    /**
     * Every assessment this vendor may see.
     *
     * ISSUED AND LATER ONLY. A `draft` or `scoped` assessment is the client
     * still deciding what to ask; showing it to the vendor would leak the
     * bank's internal deliberation and invite answers to questions that may
     * never be sent.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Assessment>
     */
    public function visibleTo(PortalUser $user)
    {
        return Assessment::query()
            ->whereHas('engagement', fn ($q) => $q->where('third_party_id', $user->third_party_id))
            ->whereNotIn('status', [
                AssessmentStatus::Draft->value,
                AssessmentStatus::Scoped->value,
            ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertBelongsTo(Assessment $assessment, PortalUser $user): void
    {
        $assessment->loadMissing('engagement');

        if (! $user->actsFor((int) $assessment->engagement?->third_party_id)) {
            throw new InvalidArgumentException('That assessment does not belong to your organisation.');
        }
    }

    /**
     * Whether the vendor may still write to this assessment.
     *
     * `clarification_requested` IS WRITABLE and `submitted` is not. A reviewer
     * who asks a question needs the vendor able to answer it without the whole
     * assessment being reopened; a submitted assessment under review must not
     * change underneath the person reviewing it.
     */
    public function isOpenForVendor(Assessment $assessment): bool
    {
        return in_array($assessment->status, [
            AssessmentStatus::Issued,
            AssessmentStatus::InProgress,
            AssessmentStatus::ClarificationRequested,
        ], true);
    }

    /**
     * Save one answer.
     *
     * ANSWERING CLEARS `is_auto_answered`. A pre-filled answer the vendor has
     * looked at and kept is still the vendor's answer — but one they EDITED is
     * no longer from the trust profile, and leaving the flag set would cite a
     * source for words the profile never contained.
     *
     * @throws InvalidArgumentException
     */
    public function saveAnswer(
        Assessment $assessment,
        AssessmentResponse $response,
        PortalUser $user,
        string $value,
        ?ComplianceLevel $compliance = null,
        ?string $comment = null,
    ): AssessmentResponse {
        $this->assertBelongsTo($assessment, $user);

        if (! $this->isOpenForVendor($assessment)) {
            throw new InvalidArgumentException(
                'This assessment is with the reviewer and cannot be edited. If something needs correcting, '
                .'say so in the message thread and they can send it back.'
            );
        }

        if ((int) $response->assessment_id !== $assessment->getKey()) {
            throw new InvalidArgumentException('That answer belongs to a different assessment.');
        }

        $changed = trim($value) !== trim((string) $response->value);

        $attributes = [
            'value' => $value,
            'compliance' => ($compliance ?? ComplianceLevel::Unanswered)->value,
            // Editing a pre-filled answer detaches it from its source: a
            // citation pointing at a trust profile that never contained these
            // words is worse than no citation.
            'is_auto_answered' => $changed ? false : $response->is_auto_answered,
            'auto_answer_source' => $changed ? null : $response->auto_answer_source,
        ];

        if ($comment !== null) {
            $attributes['vendor_comment'] = $comment;
        }

        $response->forceFill($attributes)->save();

        // First answer moves the assessment off `issued`, so the client's
        // register shows work has started without anybody reporting it.
        if ($assessment->status === AssessmentStatus::Issued) {
            $this->assessments->transition($assessment, AssessmentStatus::InProgress, null);
        }

        return $response->refresh();
    }

    /**
     * Progress, for the vendor's own bar and for the client's register.
     *
     * COUNTS REQUIRED QUESTIONS SEPARATELY, because those are the ones that
     * block submission. A bar reading 90% that will not let the vendor submit
     * is a bar that has told them the wrong thing.
     *
     * @return array{answered: int, total: int, required_answered: int, required_total: int, pct: float}
     */
    public function progress(Assessment $assessment): array
    {
        $rows = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->with('question:id,is_required')
            ->get();

        $answered = $rows->filter(fn (AssessmentResponse $r): bool => $r->isAnswered());

        $requiredTotal = $rows->filter(fn (AssessmentResponse $r): bool => (bool) $r->question?->is_required);
        $requiredAnswered = $requiredTotal->filter(fn (AssessmentResponse $r): bool => $r->isAnswered());

        return [
            'answered' => $answered->count(),
            'total' => $rows->count(),
            'required_answered' => $requiredAnswered->count(),
            'required_total' => $requiredTotal->count(),
            'pct' => $rows->count() === 0 ? 0.0 : round(($answered->count() / $rows->count()) * 100, 1),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Delegation — FR-PRT-03 */
    /* ------------------------------------------------------------------ */

    /**
     * Hand one section to a colleague at the same vendor.
     *
     * THE COLLEAGUE MUST BE AT THE SAME VENDOR AND THE SAME CLIENT. A portal
     * account is scoped to one organisation; delegating across that boundary
     * would hand somebody an assessment their account has no standing to see.
     *
     * @throws InvalidArgumentException
     */
    public function delegate(
        Assessment $assessment,
        QuestionnaireSection $section,
        PortalUser $from,
        PortalUser $to,
        ?string $note = null,
    ): AssessmentDelegation {
        $this->assertBelongsTo($assessment, $from);

        if ($to->organization_id !== $from->organization_id || $to->third_party_id !== $from->third_party_id) {
            throw new InvalidArgumentException(
                'You can only delegate to a colleague on the same portal account.'
            );
        }

        return DB::transaction(fn (): AssessmentDelegation => AssessmentDelegation::updateOrCreate(
            [
                'assessment_id' => $assessment->getKey(),
                'section_id' => $section->getKey(),
            ],
            [
                'organization_id' => $assessment->organization_id,
                'delegated_to' => $to->getKey(),
                'delegated_by' => $from->getKey(),
                'note' => $note,
                'delegated_at' => now(),
                'completed_at' => null,
            ],
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, AssessmentDelegation>
     */
    public function delegations(Assessment $assessment)
    {
        return AssessmentDelegation::query()
            ->where('assessment_id', $assessment->getKey())
            ->with(['assignee:id,name,email', 'delegator:id,name'])
            ->get();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Submit, refusing while a required question is unanswered.
     *
     * The refusal NAMES WHAT IS MISSING. "Please complete all required fields"
     * on a forty-question form across six sections is a scavenger hunt.
     *
     * @return array{submitted: bool, reason: string|null, outstanding: list<string>}
     */
    public function submit(Assessment $assessment, PortalUser $user): array
    {
        $this->assertBelongsTo($assessment, $user);

        if (! $this->isOpenForVendor($assessment)) {
            return [
                'submitted' => false,
                'reason' => 'This assessment has already been submitted.',
                'outstanding' => [],
            ];
        }

        $outstanding = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->where('compliance', ComplianceLevel::Unanswered->value)
            ->whereHas('question', fn ($q) => $q->where('is_required', true))
            ->with('question:id,code,text')
            ->get()
            ->map(fn (AssessmentResponse $r): string => sprintf(
                '%s — %s',
                $r->question->code ?? '',
                mb_strimwidth((string) $r->question?->text, 0, 90, '…'),
            ))
            ->values()
            ->all();

        if ($outstanding !== []) {
            return [
                'submitted' => false,
                'reason' => sprintf(
                    '%d required question%s still unanswered.',
                    count($outstanding),
                    count($outstanding) === 1 ? ' is' : 's are',
                ),
                'outstanding' => $outstanding,
            ];
        }

        /*
         * A questionnaire that arrived fully pre-filled from the trust profile
         * has had nothing saved into it, so it is still `issued` — and the
         * lifecycle has no Issued → Submitted edge. Without this step the
         * vendor presses Submit, nothing happens, and the one case the trust
         * profile exists to create is the one case that cannot be submitted.
         */
        if ($assessment->status === AssessmentStatus::Issued) {
            $this->assessments->transition($assessment, AssessmentStatus::InProgress, null);
            $assessment->refresh();
        }

        $submitted = $this->assessments->submit($assessment, null);

        return [
            'submitted' => $submitted,
            'reason' => $submitted ? null : 'This assessment cannot be submitted from its current status.',
            'outstanding' => [],
        ];
    }
}
