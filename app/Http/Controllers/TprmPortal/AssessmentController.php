<?php

namespace App\Http\Controllers\TprmPortal;

use App\Enums\Tprm\ComplianceLevel;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\QuestionnaireSection;
use App\Services\Tprm\Portal\PortalAssessmentService;
use App\Services\Tprm\Portal\PortalMessageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * The vendor answering a questionnaire — FR-PRT-03.
 *
 * EVERY ACTION RE-ASSERTS OWNERSHIP through the service rather than trusting
 * route-model binding. Binding resolves under the tenant the portal session
 * bound, which stops another BANK's assessment being loaded — it does not stop
 * one vendor of that bank opening another vendor's assessment, because both
 * are in the same tenant. `assertBelongsTo` is what closes that, and it is the
 * cross-vendor half of AC-14.
 *
 * A REFUSAL IS A 404, NOT A 403 AND CERTAINLY NOT A 500. A vendor enumerating
 * uuids should not be able to tell "no such assessment" from "somebody else's
 * assessment" — the second answer confirms the row exists and who it is not
 * for. `assertOwned()` is the one place that translates the service's
 * exception, so no action can forget.
 */
class AssessmentController extends Controller
{
    public function __construct(
        private readonly PortalAssessmentService $assessments,
        private readonly PortalMessageService $messages,
    ) {}

    public function index(Request $request)
    {
        $user = $this->user($request);

        return Inertia::render('TprmPortal/Assessments/Index', [
            'assessments' => $this->assessments->visibleTo($user)
                ->with(['engagement:id,uuid,name', 'template:id,name'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (Assessment $assessment): array => [
                    'uuid' => $assessment->uuid,
                    'name' => $assessment->template?->name,
                    'engagement' => $assessment->engagement?->name,
                    'status' => $assessment->status->value,
                    'status_label' => $assessment->status->label(),
                    'due_at' => $assessment->due_at?->toDateString(),
                    'overdue' => $assessment->due_at?->isPast() && $this->assessments->isOpenForVendor($assessment),
                    'progress' => $this->assessments->progress($assessment),
                ])->values(),
        ]);
    }

    public function show(Request $request, Assessment $assessment)
    {
        $user = $this->user($request);
        $this->assertOwned($assessment, $user);

        $assessment->load(['template.sections.questions', 'engagement:id,name']);

        $responses = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->get()
            ->keyBy('question_id');

        $this->messages->markRead(AssessmentMessage::AUTHOR_VENDOR, $assessment);

        return Inertia::render('TprmPortal/Assessments/Respond', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'name' => $assessment->template?->name,
                'engagement' => $assessment->engagement?->name,
                'status' => $assessment->status->value,
                'status_label' => $assessment->status->label(),
                'due_at' => $assessment->due_at?->toDateString(),
                'editable' => $this->assessments->isOpenForVendor($assessment),
            ],
            'progress' => $this->assessments->progress($assessment),
            'sections' => $this->sections($assessment, $responses),
            'delegations' => $this->assessments->delegations($assessment)
                ->map(fn ($d): array => [
                    'section_id' => $d->section_id,
                    'to' => $d->assignee?->name,
                    'to_email' => $d->assignee?->email,
                    'note' => $d->note,
                    'open' => $d->isOpen(),
                ])->values(),
            'colleagues' => PortalUser::query()
                ->where('third_party_id', $user->third_party_id)
                ->where('id', '!=', $user->getKey())
                ->active()
                ->get(['id', 'name', 'email'])
                ->map(fn (PortalUser $c): array => [
                    'id' => $c->getKey(), 'name' => $c->name, 'email' => $c->email,
                ])->values(),
            'thread' => $this->messages->thread($assessment)->map(fn (AssessmentMessage $m): array => [
                'id' => $m->getKey(),
                'from_vendor' => $m->isFromVendor(),
                'body' => $m->body,
                'at' => $m->created_at?->toDayDateTimeString(),
            ])->values(),
            'complianceLevels' => collect(ComplianceLevel::cases())
                ->reject(fn (ComplianceLevel $c): bool => $c === ComplianceLevel::Unanswered)
                ->map(fn (ComplianceLevel $c): array => ['value' => $c->value, 'label' => $c->label()])
                ->values(),
        ]);
    }

    /** Save one answer. Called on every field blur — save-and-resume. */
    public function saveAnswer(Request $request, Assessment $assessment, AssessmentResponse $response)
    {
        $validated = $request->validate([
            'value' => ['nullable', 'string', 'max:8000'],
            'compliance' => ['nullable', 'string'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $this->user($request);
        $this->assertOwned($assessment, $user);

        try {
            $this->assessments->saveAnswer(
                $assessment,
                $response,
                $user,
                (string) ($validated['value'] ?? ''),
                ComplianceLevel::tryFrom((string) ($validated['compliance'] ?? '')),
                $validated['comment'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            // Reaching here means the assessment IS theirs and the refusal is
            // about its state — which they are entitled to be told.
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function delegate(Request $request, Assessment $assessment, QuestionnaireSection $section)
    {
        $validated = $request->validate([
            'portal_user_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $this->user($request);
        $this->assertOwned($assessment, $user);

        $colleague = PortalUser::query()
            ->where('third_party_id', $user->third_party_id)
            ->find($validated['portal_user_id']);

        if ($colleague === null) {
            return back()->with('error', 'That colleague is not on your portal account.');
        }

        try {
            $this->assessments->delegate($assessment, $section, $user, $colleague, $validated['note'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf('%s now owns that section.', $colleague->name));
    }

    public function submit(Request $request, Assessment $assessment)
    {
        $user = $this->user($request);
        $this->assertOwned($assessment, $user);

        $result = $this->assessments->submit($assessment, $user);

        if (! $result['submitted']) {
            return back()->with('error', $result['reason'].' '.implode(' · ', array_slice($result['outstanding'], 0, 3)));
        }

        return redirect()
            ->route('tprm-portal.assessments.index')
            ->with('success', 'Submitted. Your client will be in touch through the message thread.');
    }

    public function postMessage(Request $request, Assessment $assessment)
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $user = $this->user($request);
        $this->assertOwned($assessment, $user);

        $this->messages->postFromVendor($user, $validated['body'], $assessment);

        return back();
    }

    /**
     * The questionnaire, section by section, as the vendor sees it.
     *
     * A FOREACH RATHER THAN NESTED `map()` CALLS. The chained version was
     * shorter and produced three separate static-analysis complaints about an
     * unresolvable callback type, which is the analyser saying the same thing
     * a reader would: two levels of closure returning anonymous arrays is hard
     * to follow and impossible to name.
     *
     * @param  \Illuminate\Support\Collection<int, AssessmentResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function sections(Assessment $assessment, $responses): array
    {
        $sections = [];

        foreach ($assessment->template->sections ?? [] as $section) {
            $questions = [];

            foreach ($section->questions as $question) {
                /** @var AssessmentResponse|null $response */
                $response = $responses->get($question->getKey());

                if ($response === null) {
                    // Scoped out of this assessment: the client's rules decided
                    // it does not apply, and showing it would ask the vendor
                    // for something nobody wants.
                    continue;
                }

                $questions[] = [
                    'response_id' => $response->getKey(),
                    'code' => $question->code,
                    'text' => $question->text,
                    'help_text' => $question->help_text,
                    'required' => (bool) $question->is_required,
                    'value' => $response->value,
                    'compliance' => $response->compliance?->value,
                    'comment' => $response->vendor_comment,
                    // Confirm-or-correct, with the source cited.
                    'prefilled' => (bool) $response->is_auto_answered,
                    'prefill_note' => $response->auto_answer_source['note'] ?? null,
                    'reviewer_status' => $response->reviewer_status,
                    'reviewer_comment' => $response->reviewer_comment,
                ];
            }

            $sections[] = [
                'id' => $section->getKey(),
                'code' => $section->code,
                'title' => $section->title,
                'questions' => $questions,
            ];
        }

        return $sections;
    }

    /**
     * Translate the service's refusal into a 404. See the class comment.
     */
    private function assertOwned(Assessment $assessment, PortalUser $user): void
    {
        try {
            $this->assessments->assertBelongsTo($assessment, $user);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    private function user(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return $user;
    }
}
