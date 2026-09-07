<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\ChallengeLineRequest;
use App\Http\Requests\Rcsa\ReviewDecisionRequest;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaLineComment;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaReviewService;
use App\Services\Rcsa\RcsaWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Steps 8 and 9 from the other side of the desk — the ORM review queue and one
 * assessment inside it (§9.2).
 *
 * THE GRID IS READ-ONLY HERE AND THERE IS NO WAY TO MAKE IT OTHERWISE. Nothing
 * on this controller writes an assessed field. A reviewer who could correct a
 * rating directly would turn a self-assessment into an ORM assessment, and the
 * audit trail would show the business having said something it never said —
 * so the strongest thing a reviewer can do to a number is suggest a different
 * one and send the line back.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly RcsaReviewService $reviews,
        private readonly RcsaWorkflowService $workflow,
        private readonly RcsaAssessmentService $assessments,
    ) {}

    /**
     * The work queue (§9.2).
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaAssessment::class);

        $queue = $this->reviews->queue(
            $request->only(['cycle', 'status', 'business_unit']),
            viewerId: (int) $request->user()->id,
        );

        // The reviewer's own claimed work, separated out. A queue that mixes
        // "yours, in progress" with "nobody's yet" is one people scroll past.
        $mine = array_values(array_filter(
            $queue,
            fn (array $row) => (int) ($row['reviewer_id'] ?? 0) === (int) $request->user()->id,
        ));

        return Inertia::render('RcsaReview/Index', [
            'queue' => $queue,
            'mine' => $mine,
            'filters' => $request->only(['cycle', 'status', 'business_unit']),
            'cycles' => RcsaCycle::query()
                ->whereIn('status', [RcsaCycle::OPEN, RcsaCycle::IN_REVIEW])
                ->orderByDesc('period_start')
                ->get(['id', 'name'])
                ->all(),
            'weights' => [
                'above_appetite' => RcsaReviewService::ABOVE_APPETITE_WEIGHT,
                'escalation' => RcsaReviewService::ESCALATION_WEIGHT,
            ],
        ]);
    }

    /**
     * One assessment under review.
     */
    public function show(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('review', $assessment);

        $assessment->load(['cycle:id,name,status,due_date', 'businessUnit:id,name,code', 'submitter:id,name', 'reviewer:id,name']);

        $lines = $assessment->lines()
            ->with([
                'actionPlans.owner:id,name',
                'comments.author:id,name',
                'priorLine:id,inherent_score,inherent_level,residual_score,residual_level,control_effectiveness',
            ])
            ->get();

        return Inertia::render('RcsaReview/Show', [
            'assessment' => [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                'cycle' => $assessment->getRelationValue('cycle')?->name,
                'cycle_id' => $assessment->cycle_id,
                'submitted_by' => $assessment->getRelationValue('submitter')?->name,
                'submitted_at' => $assessment->submitted_at?->toDateTimeString(),
                'reviewer' => $assessment->getRelationValue('reviewer')?->name,
                'reviewer_id' => $assessment->reviewer_id,
                'is_mine' => (int) $assessment->reviewer_id === (int) $request->user()->id,
                'escalated' => $assessment->isEscalated(),
                'escalation_reason' => $assessment->escalation_reason,
                'returned_reason' => $assessment->returned_reason,
                // The PDF filed at submission. P4 wrote it and nothing read it
                // until now — the reviewer is the first person who has reason
                // to want the document rather than the rows.
                'has_snapshot' => filled($assessment->snapshot_path),
            ],
            'lines' => $lines->map(fn (RcsaAssessmentLine $line) => $this->toRow($line))->all(),
            'summary' => $this->reviews->summary($assessment),
            'history' => $this->workflow->history($assessment),
            'can' => [
                'claim' => $request->user()->can('review', $assessment),
                'validate' => $request->user()->can('validate', $assessment),
                'return' => $request->user()->can('returnForRework', $assessment),
                'escalate' => $request->user()->can('escalate', $assessment),
            ],
        ]);
    }

    /**
     * Take it for review.
     */
    public function claim(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('review', $assessment);

        try {
            $this->workflow->claim($assessment, $request->user(), $request);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'You are reviewing this assessment.');
    }

    /* ------------------------------------------------------------------ */
    /*  Per-line: the accept / flag toggle and the challenge */
    /* ------------------------------------------------------------------ */

    public function challenge(ChallengeLineRequest $request, RcsaAssessment $assessment, RcsaAssessmentLine $line)
    {
        abort_unless($line->assessment_id === $assessment->id, 404);

        $this->reviews->challenge(
            line: $line,
            actor: $request->user(),
            body: $request->validated()['body'],
            suggested: $request->suggestedValues(),
            verdict: $request->validated()['verdict'] ?? RcsaAssessmentLine::ORM_CHALLENGED,
        );

        return back()->with('success', "Challenge recorded on {$line->risk_no}.");
    }

    /**
     * The accept / flag toggle of §9.2.
     */
    public function mark(Request $request, RcsaAssessment $assessment, RcsaAssessmentLine $line)
    {
        Gate::authorize('review', $assessment);
        abort_unless($line->assessment_id === $assessment->id, 404);

        $verdict = $request->validate([
            'verdict' => ['required', 'in:'.RcsaAssessmentLine::ORM_ACCEPTED.','.RcsaAssessmentLine::ORM_FLAGGED],
        ])['verdict'];

        $verdict === RcsaAssessmentLine::ORM_ACCEPTED
            ? $this->reviews->accept($line, $request->user())
            : $this->reviews->flag($line, $request->user());

        return back();
    }

    /* ------------------------------------------------------------------ */
    /*  The three decisions (§9.2) */
    /* ------------------------------------------------------------------ */

    public function validateAssessment(ReviewDecisionRequest $request, RcsaAssessment $assessment)
    {
        Gate::authorize('validate', $assessment);

        try {
            $this->workflow->validate($assessment, $request->user(), $request->validated()['reason'] ?? null, $request);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.review.index')
            ->with('success', 'Validated. It stays read-only and closes with the cycle.');
    }

    /**
     * Return for rework — P5's acceptance criterion.
     */
    public function returnForRework(ReviewDecisionRequest $request, RcsaAssessment $assessment)
    {
        Gate::authorize('returnForRework', $assessment);

        try {
            $result = $this->workflow->returnForRework(
                $assessment,
                $request->user(),
                $request->validated()['reason'],
                $request,
            );
        } catch (\RuntimeException $e) {
            // The reviewer flagged nothing. Said on the screen they are on,
            // with the reason still in the form, rather than as a toast on a
            // page they have been redirected away from.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.review.index')
            ->with('success', sprintf(
                'Returned for rework. %d risk%s reopened; the rest stay locked.',
                $result['reopened'],
                $result['reopened'] === 1 ? ' is' : 's are',
            ));
    }

    public function escalate(ReviewDecisionRequest $request, RcsaAssessment $assessment)
    {
        Gate::authorize('escalate', $assessment);

        try {
            $this->workflow->escalate($assessment, $request->user(), $request->validated()['reason'], $request);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Escalated. It now sits at the top of the review queue.');
    }

    /**
     * The PDF filed at submission.
     *
     * STREAMED FROM STORAGE, NEVER RE-RENDERED. A PDF produced now from live
     * rows is not what was filed — see RcsaSubmissionService. If the file is
     * gone, that is a 404, not a cue to make a new one.
     */
    public function snapshot(RcsaAssessment $assessment)
    {
        Gate::authorize('view', $assessment);

        abort_if(blank($assessment->snapshot_path), 404);
        abort_unless(Storage::disk('local')->exists($assessment->snapshot_path), 404);

        return Storage::disk('local')->download(
            $assessment->snapshot_path,
            sprintf('rcsa-%d-as-filed.pdf', $assessment->id),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * One row of the read-only review grid.
     *
     * @return array<string, mixed>
     */
    private function toRow(RcsaAssessmentLine $line): array
    {
        $prior = $line->getRelationValue('priorLine');

        return [
            'id' => $line->id,
            'risk_no' => $line->risk_no,
            'process_name' => $line->process_name,
            'potential_risk' => $line->potential_risk,
            'risk_category' => $line->risk_category,
            'existing_control' => $line->existing_control,

            'inherent_likelihood' => $line->inherent_likelihood,
            'inherent_impact' => $line->inherent_impact,
            'inherent_score' => $line->inherent_score,
            'inherent_level' => $line->inherent_level,
            'control_effectiveness' => $line->control_effectiveness,
            'residual_score' => $line->residual_score,
            'residual_level' => $line->residual_level,
            'risk_treatment' => $line->effectiveTreatment(),
            'appetite_status' => $line->appetite_status,
            'above_appetite' => $this->assessments->isAboveAppetite($line),

            'treatment_override' => $line->treatment_override,
            'treatment_override_reason' => $line->treatment_override_reason,
            'assessment_rationale' => $line->assessment_rationale,
            'moved_materially' => $this->assessments->movedMaterially($line),
            'prior' => $prior === null ? null : [
                'inherent_score' => $prior->inherent_score,
                'residual_score' => $prior->residual_score,
                'residual_level' => $prior->residual_level,
                'control_effectiveness' => $prior->control_effectiveness,
            ],

            'orm_status' => $line->orm_status,
            'is_locked' => $line->isLocked(),

            'action_plans' => $line->actionPlans->map(fn ($plan) => [
                'id' => $plan->id,
                'control_to_implement' => $plan->control_to_implement,
                'owner' => $plan->getRelationValue('owner')?->name,
                'target_date' => $plan->target_date?->toDateString(),
                'status' => $plan->status,
            ])->all(),

            'comments' => $line->comments->map(fn (RcsaLineComment $comment) => [
                'id' => $comment->id,
                'type' => $comment->type,
                'body' => $comment->body,
                'suggested' => $comment->suggested_values,
                'by' => $comment->getRelationValue('author')?->name,
                'at' => $comment->created_at?->toDateTimeString(),
            ])->all(),
        ];
    }
}
