<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\IssueAssessmentRequest;
use App\Http\Requests\Tprm\ReviewResponseRequest;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Presenters\GridPresenter;
use App\Services\Tprm\Assessment\AssessmentScorer;
use App\Services\Tprm\Assessment\AssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The Assessment Console and the Assessment Review screen (TRD §11).
 *
 * The review screen is where the module's argument becomes visible to a
 * reviewer: every answer carries its assurance chip, its evidence, and the
 * confidence that assurance earned it — so the difference between "we do this"
 * and "here is the SOC 2 that says we do this" is on the screen rather than
 * buried in a score.
 */
class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Assessment::class);

        return Inertia::render('Tprm/Assessments/Index', [
            'summary' => fn () => $this->summary(),
            'workload' => fn () => $this->reviewerWorkload(),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_assessments'),
                $request,
                $request->user()
            ),
            'can' => [
                'issue' => $request->user()->can('tprm.assessment.issue'),
                'review' => $request->user()->can('tprm.assessment.review'),
            ],
        ]);
    }

    public function show(Request $request, Assessment $assessment)
    {
        Gate::authorize('view', $assessment);

        $assessment->load([
            'engagement:id,uuid,reference,name,third_party_id,effective_tier',
            'engagement.thirdParty:id,legal_name,slug,uuid',
            'template:id,code,name,version,catalogue_status,catalogue_note,declared_question_count',
            'reviewer:id,name',
            'responses.question.section',
            'responses.question.controlMaps',
        ]);

        return Inertia::render('Tprm/Assessments/Review', [
            'assessment' => $this->payload($assessment),
            'sections' => $this->sectionPayload($assessment),
            // The scoring panel updates as answers are reviewed, and it is
            // computed from the SAME scorer the final score uses — a preview
            // that used a simpler path would disagree with what gets stored.
            'liveScore' => $this->liveScore($assessment),
            'scoping' => $this->scopingPayload($assessment),
            'messages' => $this->messagePayload($assessment),
            'options' => [
                'compliance' => collect(ComplianceLevel::cases())
                    ->map(fn (ComplianceLevel $c) => ['value' => $c->value, 'label' => $c->label()])->values(),
                'assurance' => collect(AssuranceLevel::cases())
                    ->map(fn (AssuranceLevel $a) => [
                        'value' => $a->value, 'label' => $a->label(), 'definition' => $a->definition(),
                    ])->values(),
            ],
            'can' => [
                'review' => $request->user()->can('tprm.assessment.review'),
                'validate' => $request->user()->can('tprm.assessment.validate'),
            ],
        ]);
    }

    /**
     * Issue a questionnaire against an engagement.
     */
    public function store(IssueAssessmentRequest $request)
    {
        $engagement = Engagement::findOrFail($request->integer('engagement_id'));
        $template = QuestionnaireTemplate::query()
            ->availableTo($engagement->organization_id)
            ->findOrFail($request->integer('template_id'));

        if ($template->status !== QuestionnaireTemplate::STATUS_PUBLISHED) {
            return back()->with('error', 'Only a published questionnaire can be issued.');
        }

        $assessment = $this->assessments->issue(
            $engagement,
            $template,
            $request->date('due_at'),
            $request->user()->id,
            $request->string('assessment_type')->toString() ?: 'initial',
        );

        return redirect()
            ->route('tprm.assessments.show', $assessment)
            ->with('success', sprintf(
                'Assessment scoped: %d of %d questions apply to this engagement. The scoping trace records why '
                .'each of the others was left out.',
                $assessment->applicable_count,
                $assessment->question_count
            ));
    }

    public function send(Request $request, Assessment $assessment)
    {
        Gate::authorize('issue', $assessment);

        return $this->assessments->send($assessment, $request->user()->id)
            ? back()->with('success', 'The assessment was issued.')
            : back()->with('error', 'This assessment is not in a state that can be issued.');
    }

    /**
     * Record a reviewer's verdict on one answer — FR-ASM-08.
     */
    public function review(ReviewResponseRequest $request, Assessment $assessment, AssessmentResponse $response)
    {
        if ((int) $response->assessment_id !== (int) $assessment->getKey()) {
            abort(404);
        }

        $response->forceFill([
            'reviewer_status' => $request->string('reviewer_status')->toString(),
            'reviewer_comment' => $request->input('reviewer_comment'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // A reviewer may correct the compliance verdict — that is what review
        // means. The assurance level is corrected too, because a vendor
        // claiming `independently_assured` with a policy PDF attached is the
        // commonest thing a reviewer fixes.
        if ($request->filled('compliance')) {
            $response->compliance = ComplianceLevel::from($request->string('compliance')->toString());
        }

        if ($request->filled('assurance_level')) {
            $response->assurance_level = AssuranceLevel::from($request->string('assurance_level')->toString());
        }

        $response->save();

        if ($request->filled('message')) {
            AssessmentMessage::create([
                'organization_id' => $assessment->organization_id,
                'assessment_id' => $assessment->getKey(),
                'response_id' => $response->getKey(),
                'author_type' => AssessmentMessage::AUTHOR_INTERNAL,
                'author_id' => $request->user()->id,
                'body' => $request->string('message')->toString(),
            ]);
        }

        return back()->with('success', 'Answer reviewed.');
    }

    public function requestClarification(Request $request, Assessment $assessment)
    {
        Gate::authorize('review', $assessment);

        return $this->assessments->requestClarification($assessment, $request->user()->id)
            ? back()->with('success', 'Returned to the vendor with only the flagged answers open.')
            : back()->with('error', 'Flag at least one answer for clarification first.');
    }

    public function validateAssessment(Request $request, Assessment $assessment)
    {
        Gate::authorize('validateAssessment', $assessment);

        $pending = $assessment->responses()
            ->where('reviewer_status', AssessmentResponse::REVIEW_PENDING)
            ->count();

        if ($pending > 0) {
            return back()->with('error', "{$pending} answer(s) have not been reviewed yet.");
        }

        if (! $this->assessments->validate($assessment, $request->user()->id)) {
            return back()->with('error', 'This assessment is not awaiting validation.');
        }

        $result = $this->assessments->score($assessment->refresh(), AssessmentScorer::fromConfig());

        return $result['scored']
            ? back()->with('success', sprintf(
                'Validated and scored: coverage %.2f, evidence confidence %s.',
                $result['score']->assuranceCoverage,
                $result['score']->evidenceConfidence === null
                    ? 'not measurable — nothing is evidenced'
                    : number_format((float) $result['score']->evidenceConfidence, 2)
            ))
            : back()->with('error', $result['reason']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function payload(Assessment $assessment): array
    {
        return [
            'id' => $assessment->getKey(),
            'uuid' => $assessment->uuid,
            'status' => $assessment->status->value,
            'status_label' => $assessment->status->label(),
            'type' => $assessment->assessment_type,
            'cycle_label' => $assessment->cycle_label,
            'template' => [
                'code' => $assessment->template?->code,
                'name' => $assessment->template?->name,
                'version' => $assessment->template_version,
                // The pack's own completeness note, so a reviewer knows
                // whether they are looking at the whole of a standard.
                'catalogue_status' => $assessment->template?->catalogue_status,
                'catalogue_note' => $assessment->template?->catalogue_note,
            ],
            'engagement' => [
                'reference' => $assessment->engagement?->reference,
                'name' => $assessment->engagement?->name,
                'third_party' => $assessment->engagement?->thirdParty?->legal_name,
                'tier' => $assessment->engagement?->effective_tier?->value,
                'url' => $assessment->engagement
                    ? route('tprm.engagements.show', $assessment->engagement)
                    : null,
            ],
            'reviewer' => $assessment->reviewer?->name,
            'due_at' => $assessment->due_at?->toDateString(),
            'days_overdue' => $assessment->daysOverdue(),
            'question_count' => $assessment->question_count,
            'applicable_count' => $assessment->applicable_count,
            'answered_count' => $assessment->answered_count,
            'ac' => $assessment->ac,
            'ec' => $assessment->ec,
            'section_scores' => $assessment->section_scores,
            'domain_scores' => $assessment->domain_scores,
        ];
    }

    /**
     * Answers grouped by section, in template order.
     *
     * @return list<array<string, mixed>>
     */
    private function sectionPayload(Assessment $assessment): array
    {
        // Built with a loop rather than groupBy()->map(). The collection
        // generics on a grouped Eloquent collection are unresolvable to static
        // analysis, and the alternative — annotating four nested closures — is
        // more code than the loop and harder to read.
        $sections = [];

        foreach ($assessment->responses as $response) {
            $section = $response->question->section;
            $code = $section->code ?? 'other';

            $sections[$code] ??= [
                'code' => $code,
                'title' => $section->title ?? 'Other',
                'domain' => $section->domain_tag ?? null,
                'responses' => [],
            ];

            $sections[$code]['responses'][] = [
                'id' => $response->getKey(),
                'question_code' => $response->question->code,
                'question' => $response->question->text,
                'help_text' => $response->question->help_text,
                'type' => $response->question->type,
                'is_critical' => (bool) $response->question->is_critical,
                'risk_weight' => $response->question->risk_weight,
                'evidence_required' => (bool) $response->question->evidence_required,
                'controls' => $response->question->controlMaps->map(fn ($map) => [
                    'framework' => $map->framework,
                    'control_id' => $map->control_id,
                ])->values()->all(),
                'value' => $response->value,
                'compliance' => $response->compliance->value,
                'compliance_label' => $response->compliance->label(),
                'assurance_level' => $response->assurance_level?->value,
                'assurance_label' => $response->assurance_level?->label(),
                'computed_conf' => $response->computed_conf,
                'vendor_comment' => $response->vendor_comment,
                'reviewer_status' => $response->reviewer_status,
                'reviewer_comment' => $response->reviewer_comment,
                // Auto-answered items are visually distinct on the screen and
                // show their source, so a reviewer never mistakes a carried
                // answer for one the vendor has just given.
                'is_auto_answered' => (bool) $response->is_auto_answered,
                'auto_answer_source' => $response->auto_answer_source,
                'carry_forward_cycles' => $response->carry_forward_cycles,
            ];
        }

        return array_values($sections);
    }

    /**
     * The live score, computed from the current answers with the same scorer
     * that will produce the stored one.
     *
     * @return array<string, mixed>
     */
    private function liveScore(Assessment $assessment): array
    {
        $score = AssessmentScorer::fromConfig()->score(
            $this->assessments->scoreableAnswers($assessment)
        );

        return [
            'ac' => round($score->assuranceCoverage, 3),
            'ec' => $score->evidenceConfidence === null ? null : round($score->evidenceConfidence, 3),
            'm' => $score->mitigation() === null ? null : round((float) $score->mitigation(), 3),
            'coverage_capped' => $score->coverageCapped,
            'critical_failures' => $score->criticalFailures,
            'sections' => $score->sections,
            'domains' => $score->domains,
            'scored_count' => $score->scoredCount,
            'applicable_count' => $score->applicableCount,
            'warnings' => $score->warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function scopingPayload(Assessment $assessment): array
    {
        $trace = $assessment->scoping_trace ?? [];

        $excluded = collect($trace['trace'] ?? [])
            ->filter(fn (array $entry) => ! $entry['included'])
            ->groupBy('reason')
            ->map(fn ($entries, $reason) => ['reason' => $reason, 'questions' => $entries->keys()->values()])
            ->values();

        return [
            'included_count' => $trace['included_count'] ?? 0,
            'total_count' => $trace['total_count'] ?? 0,
            'excluded_count' => $trace['excluded_count'] ?? 0,
            'exclusions' => $excluded,
            'unresolved_facts' => $trace['unresolved_facts'] ?? [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function messagePayload(Assessment $assessment): array
    {
        return AssessmentMessage::query()
            ->where('assessment_id', $assessment->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (AssessmentMessage $message) => [
                'id' => $message->getKey(),
                'response_id' => $message->response_id,
                'author_type' => $message->author_type,
                'body' => $message->body,
                'at' => $message->created_at?->toDayDateTimeString(),
            ])->values()->all();
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $base = fn () => Assessment::query();

        return [
            'total' => $base()->count(),
            'awaiting_vendor' => $base()->whereIn('status', ['issued', 'in_progress', 'clarification_requested'])->count(),
            'awaiting_review' => $base()->whereIn('status', ['submitted', 'under_review'])->count(),
            'overdue' => $base()->overdue()->count(),
        ];
    }

    /**
     * Reviewer workload — TRD §11's "workload by reviewer".
     *
     * @return list<array{reviewer: string, open: int}>
     */
    private function reviewerWorkload(): array
    {
        return Assessment::query()
            ->with('reviewer:id,name')
            ->whereIn('status', ['submitted', 'under_review'])
            ->get()
            ->groupBy(fn (Assessment $a) => $a->reviewer->name ?? 'Unassigned')
            ->map(fn ($group, $name) => ['reviewer' => $name, 'open' => $group->count()])
            ->sortByDesc('open')
            ->values()
            ->all();
    }
}
