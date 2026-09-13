<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\ComplianceLevel;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Services\Tprm\Findings\FindingRaiser;
use App\Services\Tprm\Portal\TrustProfilePrefill;
use App\Services\Tprm\Scoring\EngagementContext;
use Illuminate\Support\Facades\DB;

/**
 * The assessment lifecycle — issue, submit, review, validate, score, expire,
 * and build a delta.
 *
 * THE ORDER OF validate → score IS DELIBERATE and it is the module's argument
 * in miniature. Scoring before review would produce a number over unreviewed
 * vendor claims; the whole point of TRD §7.4 is that a score is worth what the
 * evidence behind it is worth, and nobody has looked at the evidence until a
 * reviewer has. So `score()` refuses an assessment that is not validated.
 */
class AssessmentService
{
    public function __construct(
        private readonly QuestionnaireScoper $scoper,
        private readonly EngagementContext $context,
        private readonly AnswerInheritanceResolver $inheritance,
        private readonly TrustProfilePrefill $prefill,
    ) {}

    /**
     * Scope a template against an engagement and create the assessment with
     * one response row per applicable question.
     */
    public function issue(
        Engagement $engagement,
        QuestionnaireTemplate $template,
        ?\DateTimeInterface $dueAt = null,
        ?int $userId = null,
        string $type = 'initial',
    ): Assessment {
        $sections = $this->templateShape($template);
        $context = $this->context->build($engagement);
        $scoping = $this->scoper->scope($sections, $context);

        return DB::transaction(function () use ($engagement, $template, $scoping, $dueAt, $userId, $type) {
            $assessment = Assessment::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'template_id' => $template->getKey(),
                // The version AT ISSUE. A successor may be published while
                // this cycle is open, and the answers belong to this one.
                'template_version' => $template->version,
                'assessment_type' => $type,
                'cycle_label' => now()->format('Y').' '.ucfirst($type),
                'status' => AssessmentStatus::Scoped->value,
                'due_at' => $dueAt,
                'scoping_trace' => $scoping->toArray(),
                'question_count' => $scoping->totalCount(),
                'applicable_count' => $scoping->includedCount(),
                'created_by' => $userId,
            ]);

            $questions = $this->questionsByCode($template);

            foreach ($scoping->includedCodes as $code) {
                $question = $questions[$code] ?? null;

                if ($question === null) {
                    continue;
                }

                $response = AssessmentResponse::create([
                    'organization_id' => $engagement->organization_id,
                    'assessment_id' => $assessment->getKey(),
                    'question_id' => $question->getKey(),
                ]);

                // FR-ASM-06: never re-ask cold what an earlier cycle already
                // answered with evidence that is still good.
                $this->inheritance->apply($response, $question, $engagement);
            }

            /*
             * FR-PRT-04: then fill what is still blank from the vendor's
             * published trust profile, where the vendor has approved a share
             * with this client.
             *
             * AFTER INHERITANCE, NEVER BEFORE. An answer this vendor gave THIS
             * client in an earlier cycle, with evidence this client accepted,
             * beats a general statement the vendor published for everybody —
             * and `apply()` only touches responses that are still unanswered,
             * so ordering is the whole of the precedence rule.
             */
            $this->prefill->apply($assessment);

            return $assessment->refresh();
        });
    }

    /**
     * Issue a scoped assessment to the vendor.
     */
    public function send(Assessment $assessment, ?int $userId = null): bool
    {
        return $this->transition($assessment, AssessmentStatus::Issued, $userId, [
            'issued_at' => now(),
        ]);
    }

    public function submit(Assessment $assessment, ?int $userId = null): bool
    {
        $unanswered = $assessment->responses()
            ->where('compliance', ComplianceLevel::Unanswered->value)
            ->whereHas('question', fn ($q) => $q->where('is_required', true))
            ->count();

        if ($unanswered > 0) {
            return false;
        }

        return $this->transition($assessment, AssessmentStatus::Submitted, $userId, [
            'submitted_at' => now(),
            'answered_count' => $assessment->responses()
                ->where('compliance', '!=', ComplianceLevel::Unanswered->value)->count(),
        ]);
    }

    /**
     * Return the assessment to the vendor with ONLY the flagged items open
     * (FR-ASM-08).
     *
     * The accepted answers stay accepted. A clarification that reopened
     * everything would ask a vendor to re-answer forty questions because two
     * were unclear, and the vendor would resubmit the same forty unchanged.
     */
    public function requestClarification(Assessment $assessment, ?int $userId = null): bool
    {
        $flagged = $assessment->responses()
            ->where('reviewer_status', AssessmentResponse::REVIEW_CLARIFICATION)
            ->count();

        if ($flagged === 0) {
            return false;
        }

        return $this->transition($assessment, AssessmentStatus::ClarificationRequested, $userId);
    }

    public function validate(Assessment $assessment, ?int $userId = null): bool
    {
        return $this->transition($assessment, AssessmentStatus::Validated, $userId, [
            'validated_at' => now(),
            'internal_reviewer_id' => $userId,
        ]);
    }

    /**
     * Compute AC and EC and store them — TRD §7.4.
     *
     * REFUSES AN UNVALIDATED ASSESSMENT. A score over answers nobody has
     * reviewed is a score over vendor claims, and the product's whole argument
     * is that those are worth 0.35 rather than 1.0 until somebody checks.
     *
     * @return array{scored: bool, reason: string|null, score: AssessmentScore|null}
     */
    public function score(Assessment $assessment, AssessmentScorer $scorer): array
    {
        if ($assessment->status !== AssessmentStatus::Validated) {
            return [
                'scored' => false,
                'reason' => 'An assessment is scored after it is validated, not before — a score over unreviewed '
                    .'answers is a score over the vendor\'s own claims.',
                'score' => null,
            ];
        }

        $answers = $this->scoreableAnswers($assessment);
        $score = $scorer->score($answers);

        DB::transaction(function () use ($assessment, $score) {
            foreach ($score->answers as $answerScore) {
                $assessment->responses()
                    ->whereHas('question', fn ($q) => $q->where('code', $answerScore->questionCode))
                    ->update(['computed_conf' => round($answerScore->confidence, 3)]);
            }

            $assessment->forceFill([
                'ac' => round($score->assuranceCoverage, 3),
                'ec' => $score->evidenceConfidence === null ? null : round($score->evidenceConfidence, 3),
                'raw_score' => round($score->assuranceCoverage, 3),
                'section_scores' => $score->sections,
                'domain_scores' => $score->domains,
                'status' => AssessmentStatus::Scored->value,
            ])->save();
        });

        // Phase 5, and the ORDER matters. The findings are raised first so
        // that the recomputation below sees them: raising afterwards would
        // score the engagement, then add three findings, and leave the number
        // a step behind the register until something else moved it.
        //
        // FR-ASM-09. Idempotent on the question, so re-scoring an assessment
        // does not duplicate the gap it already raised.
        app(FindingRaiser::class)->fromAssessment($assessment->refresh());

        // AC and EC are two of the three factors in M, so a new assessment
        // score moves the residual score. Dispatched here rather than from the
        // controller, because the importer and the API reach this method too.
        if ($assessment->engagement !== null) {
            EngagementScoreInvalidated::dispatch(
                $assessment->engagement,
                EngagementScoreInvalidated::ASSESSMENT_SCORED,
            );
        }

        return ['scored' => true, 'reason' => null, 'score' => $score];
    }

    /**
     * Build a delta reassessment — FR-ASM-11, AC-12.
     *
     * Carries forward every answer whose evidence is still current and whose
     * carry-forward count is under the limit, and opens only the remainder.
     * The vendor sees the carried answers read-only for context, which is what
     * stops a periodic reassessment being a re-typing exercise that produces
     * the same answers with less care than the first time.
     */
    public function buildDelta(Assessment $previous, ?int $userId = null): Assessment
    {
        $engagement = $previous->engagement;
        $template = $previous->template;

        $delta = $this->issue($engagement, $template, null, $userId, 'delta');

        $limit = (int) config('tprm.defaults.carry_forward_cycle_limit');

        DB::transaction(function () use ($previous, $delta, $limit) {
            $priorByQuestion = $previous->responses()->get()->keyBy('question_id');

            foreach ($delta->responses()->with('question')->get() as $response) {
                $prior = $priorByQuestion->get($response->question_id);

                if ($prior === null || ! $prior->isAnswered()) {
                    continue;
                }

                // Already carried as far as the policy allows: ask it again.
                if ($prior->carry_forward_cycles + 1 > $limit) {
                    continue;
                }

                // Evidence that has expired is exactly what a reassessment is
                // for, so it is not carried.
                if ($this->evidenceExpired($prior)) {
                    continue;
                }

                $response->forceFill([
                    'value' => $prior->value,
                    'assurance_level' => $prior->assurance_level?->value,
                    'compliance' => $prior->compliance->value,
                    'carried_forward_from_response_id' => $prior->getKey(),
                    'carry_forward_cycles' => $prior->carry_forward_cycles + 1,
                    'vendor_comment' => $prior->vendor_comment,
                ])->save();
            }

            $delta->forceFill([
                'parent_assessment_id' => $previous->getKey(),
                'answered_count' => $delta->responses()
                    ->where('compliance', '!=', ComplianceLevel::Unanswered->value)->count(),
            ])->save();
        });

        return $delta->refresh();
    }

    /**
     * Guarded transition. Returns false rather than throwing, so a controller
     * can answer a wrong-state request with a flash message rather than a 403
     * (development standard §3).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        Assessment $assessment,
        AssessmentStatus $target,
        ?int $userId = null,
        array $attributes = [],
    ): bool {
        if (! $assessment->status->canTransitionTo($target)) {
            return false;
        }

        $assessment->forceFill($attributes + [
            'status' => $target->value,
            'updated_by' => $userId,
        ])->save();

        return true;
    }

    /**
     * Turn the stored responses into the pure scorer's input.
     *
     * @return list<ScoreableAnswer>
     */
    public function scoreableAnswers(Assessment $assessment): array
    {
        $responses = $assessment->responses()->with(['question.section'])->get();

        return $responses->map(fn (AssessmentResponse $response) => new ScoreableAnswer(
            questionCode: (string) $response->question?->code,
            // The relation is eager-loaded above, so larastan proves it
            // non-null here; the fallback weight belongs on the column, which
            // defaults to 1.
            weight: (float) $response->question->risk_weight,
            compliance: $response->compliance,
            assuranceLevel: $response->assurance_level,
            sectionCode: $response->question?->section?->code,
            domainTag: $response->question?->section?->domain_tag,
            isCritical: (bool) $response->question?->is_critical,
            evidenceExpired: $this->evidenceExpired($response),
            bridgeLetterOnly: $this->bridgeLetterOnly($response),
            scopeMismatch: (bool) ($response->quality_flags['scope_mismatch'] ?? false),
            qualityFlagged: (bool) ($response->quality_flags['quality'] ?? false),
            carryForwardCycles: (int) $response->carry_forward_cycles,
        ))->values()->all();
    }

    /**
     * Whether every document behind this answer has expired.
     *
     * ALL rather than ANY: an answer evidenced by a current SOC 2 and a lapsed
     * ISO certificate is still evidenced. Penalising it for the stale one
     * would push vendors to remove old evidence rather than add new.
     */
    private function evidenceExpired(AssessmentResponse $response): bool
    {
        $documents = DB::table('tp_response_evidence')
            ->join('tp_documents', 'tp_response_evidence.document_id', '=', 'tp_documents.id')
            ->where('tp_response_evidence.response_id', $response->getKey())
            ->whereNull('tp_documents.deleted_at')
            ->select('tp_documents.valid_to')
            ->get();

        if ($documents->isEmpty()) {
            return false;
        }

        return $documents->every(
            fn ($document) => $document->valid_to !== null
                && $document->valid_to < now()->toDateString()
        );
    }

    /**
     * Whether the only assurance behind this answer is a bridge letter — the
     * AC-05 cap.
     */
    private function bridgeLetterOnly(AssessmentResponse $response): bool
    {
        $types = DB::table('tp_response_evidence')
            ->join('tp_documents', 'tp_response_evidence.document_id', '=', 'tp_documents.id')
            ->leftJoin('tp_document_types', 'tp_documents.document_type_id', '=', 'tp_document_types.id')
            ->where('tp_response_evidence.response_id', $response->getKey())
            ->whereNull('tp_documents.deleted_at')
            ->pluck('tp_document_types.code');

        return $types->isNotEmpty() && $types->every(fn (?string $code) => $code === 'bridge_letter');
    }

    /**
     * The template as the scoper wants it — sections with their questions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function templateShape(QuestionnaireTemplate $template): array
    {
        return $template->sections()->with('questions')->get()->map(fn ($section) => [
            'code' => $section->code,
            'title' => $section->title,
            'visibility_rule' => $section->visibility_rule,
            'questions' => $section->questions->map(fn (Question $question) => [
                'code' => $question->code,
                'visibility_rule' => $question->visibility_rule,
                'is_required' => (bool) $question->is_required,
            ])->all(),
        ])->all();
    }

    /**
     * @return array<string, Question>
     */
    private function questionsByCode(QuestionnaireTemplate $template): array
    {
        return Question::query()
            ->whereIn('section_id', $template->sections()->pluck('id'))
            ->get()
            ->keyBy('code')
            ->all();
    }
}
