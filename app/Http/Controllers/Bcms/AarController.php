<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\ReopenAarRequest;
use App\Http\Requests\Bcms\UpdateAarRequest;
use App\Models\Bcms\Aar;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\User;
use App\Services\Bcms\Exercises\AarAiDrafter;
use App\Services\Bcms\Exercises\AarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The after-action report builder (aar-builder spec).
 *
 * THE GATE IS LIVE, NOT A SUBMIT-TIME SURPRISE (spec §2). `conditions()` runs
 * on every render and is the same method `finalise()` itself calls — the
 * screen can never show "ready" when the server would refuse.
 *
 * THE AI DRAFT'S SUGGESTED FINDINGS ARE FLASHED, NOT STORED. They are a
 * proposal (clause map refinement 11: "it may not call `FindingService::
 * raise()`"), not a row anywhere, so they only survive the one redirect back
 * to this screen and are gone on the next visit unless a human raises one.
 *
 * A PIR REACHED THROUGH THIS SHARED ROUTE NEEDS THE INCIDENT CONJUNCTION TOO
 * (ADR 0020 §1, `pir-post-incident-review.md` §1, Phase 10 Gate 1 finding).
 * `bcms.aars.show`/`.update`/`.finalise`/`.distribute`/`.ai-draft` are gated
 * only on the exercise-side `bcms.exercise.*`/`bcms.aar.*` abilities, which
 * say nothing about whether a viewer may see or act on a **post-incident**
 * review specifically — a PIR can carry personal data about staff and
 * customers a way an exercise AAR by construction does not. A holder of
 * `bcms.aar.manage` alone (the `risk-owner` catalog role's actual grant) could
 * open and edit any incident's review through this one shared surface, which
 * offers no read-only mode of its own — `bcms.incidents.review.show` is the
 * dedicated, lesser-privileged read path (`permission:bcms.incident.view`);
 * reaching a PIR through the shared AAR builder additionally requires
 * `bcms.incident.manage`, the same conjunction `IncidentPresenter::review()`
 * already computes for its own `can.manage`/`can.approve` flags.
 * `authorizeIncidentConjunction()` is the one place every action on this
 * controller checks it, so a new action added later does not have to
 * remember to.
 */
class AarController extends Controller
{
    public function __construct(
        private AarService $service,
        private AarAiDrafter $drafter,
    ) {}

    public function show(Request $request, Aar $aar): Response|RedirectResponse
    {
        Gate::authorize('bcms.exercise.view');
        $this->authorizeIncidentConjunction($aar);

        // Advisory A11 (Gate 1 re-gate): this shared route assumes an
        // occurrence and renders `Bcms/Exercises/Aar` against one —
        // `bcms.incidents.review.show` is the dedicated, correctly-shaped
        // screen for a PIR (`IncidentReviewController::show()`,
        // `docs/bcms/screens/pir-post-incident-review.md` §1's own "same
        // route, disambiguated server-side" preference, realised here as a
        // redirect since the two screens are not, in fact, the same
        // component).
        if ($aar->isPostIncident() && $aar->incident !== null) {
            return redirect()->route('bcms.incidents.review.show', $aar->incident);
        }

        $conditions = $this->service->conditions($aar);
        $aar->refresh();
        $occurrence = $aar->occurrence;
        $user = $request->user();

        $approval = $user !== null ? $this->service->approverAllowed($aar, $user) : ['allowed' => true, 'reason' => null, 'blocking' => false];

        return Inertia::render('Bcms/Exercises/Aar', [
            'aar' => [
                // Numeric id, deliberately: the AAR builder's inline "Raise a
                // finding" form posts `aar_id` as a plain body field to the
                // EXISTING `bcms.findings.store` route (phase-9-notes.md §2),
                // and `FindingController::store()` validates it with
                // `VisibleToUser(Aar::class)` against the integer key, not the
                // uuid. This is form data, not a route parameter, so it does
                // not trip `ModuleActionUrlRouteKeyTest`.
                'id' => $aar->getKey(),
                'uuid' => $aar->uuid,
                'status' => $aar->status,
                'summary' => $aar->summary,
                'what_worked' => $aar->what_worked,
                'what_failed' => $aar->what_failed,
                'quantitative_results' => $aar->quantitative_results,
                'participant_feedback' => $aar->participant_feedback,
                'ai_generated' => (bool) $aar->ai_generated,
                'ai_draft_generated_at' => $aar->ai_draft_generated_at?->toIso8601String(),
                'approved_by' => $aar->approver?->name,
                'approved_at' => $aar->approved_at?->toIso8601String(),
                'distributed_at' => $aar->distributed_at?->toIso8601String(),
                'iso_clause_ref' => $aar->iso_clause_ref,
            ],
            'occurrence' => $occurrence === null ? null : [
                'uuid' => $occurrence->uuid,
                'definition_name' => $occurrence->definition?->name,
                'exercise_type' => $occurrence->definition?->exerciseType?->name,
                'ladder_level' => $occurrence->definition?->exerciseType?->ladder_level?->label(),
                'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
                'facilitator' => $occurrence->facilitator?->name,
                'facilitator_id' => $occurrence->facilitator_id,
            ],
            'conditions' => $conditions,
            'all_conditions_met' => collect($conditions)->every(fn (array $c) => $c['met']),
            'approval' => $approval,
            // Section 3 (spec §2): collapsed to the last 10, matching the
            // execution workspace's own chip/type styling — the export (§4)
            // carries the full timeline regardless of what is expanded here.
            'timeline' => $occurrence === null ? ['entries' => [], 'total' => 0] : [
                'entries' => $occurrence->timeline()->with('loggedBy:id,name')->orderByDesc('logged_at')->limit(10)->get()
                    ->map(fn ($t) => [
                        'id' => $t->getKey(),
                        'logged_at' => $t->logged_at->toIso8601String(),
                        'entry_type' => $t->entry_type,
                        'content' => $t->content,
                        'logged_by' => $t->loggedBy?->name,
                    ])->values()->all(),
                'total' => $occurrence->timeline()->count(),
            ],
            'findings' => Finding::query()->where('aar_id', $aar->getKey())
                ->with('correctiveActions.owner:id,name')->get()->map(fn (Finding $f) => [
                    'uuid' => $f->uuid,
                    'reference' => $f->reference,
                    'classification' => $f->classification?->value,
                    'severity' => $f->severity?->value,
                    'iso_clause_ref' => $f->iso_clause_ref,
                    'status' => $f->status,
                    'description' => $f->description,
                    // Server-built, per the module's `ModuleActionUrlRouteKeyTest`
                    // convention — the AAR builder's inline "Add corrective
                    // action" form (spec §2 item 8) reuses `Findings/Index.jsx`'s
                    // existing `AddAction` posting shape rather than rebuilding it.
                    'store_action_url' => route('bcms.actions.store', $f),
                    'actions' => $f->correctiveActions->map(fn (CorrectiveAction $a) => [
                        'uuid' => $a->uuid,
                        'reference' => $a->reference,
                        'title' => $a->title,
                        'owner' => $a->owner?->name,
                        'due_date' => $a->due_date?->toDateString(),
                        'status' => $a->status?->value,
                    ])->values()->all(),
                ])->values()->all(),
            'ai' => [
                'available' => $this->drafter->available($aar),
                'unavailable_reason' => $this->drafter->unavailableReason($aar),
            ],
            'ai_suggested_findings' => session('bcms_aar_ai_suggestions_'.$aar->getKey(), []),
            // The owner picker for the inline "Add corrective action" form —
            // same unscoped tenant-wide roster `FindingController::create()`
            // already exposes for the identical control (ADR 0017 §7).
            'options' => [
                'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
            'can' => [
                'manage' => $request->user()?->can('bcms.aar.manage') === true,
                'approve' => $request->user()?->can('bcms.aar.approve') === true,
                'export' => $request->user()?->can('bcms.report.export') === true,
                'finding_manage' => $request->user()?->can('bcms.finding.manage') === true,
            ],
            'urls' => [
                'update' => route('bcms.aars.update', $aar),
                'finalise' => route('bcms.aars.finalise', $aar),
                'reopen' => route('bcms.aars.reopen', $aar),
                'distribute' => route('bcms.aars.distribute', $aar),
                'ai_draft' => route('bcms.aars.ai-draft', $aar),
                'export' => $occurrence !== null ? route('bcms.occurrences.aar.export', $occurrence) : null,
                'raise_finding' => route('bcms.findings.store'),
                'workspace' => $occurrence !== null ? route('bcms.occurrences.workspace', $occurrence) : null,
            ],
        ]);
    }

    public function update(UpdateAarRequest $request, Aar $aar): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->service->update($aar, $data);

            foreach ($data['objective_disposition'] ?? [] as $row) {
                $this->service->disposeObjective($aar, $row['objective_text'], $row['note'] ?? null);
            }

            foreach ($data['carried_action_disposition'] ?? [] as $row) {
                $this->service->disposeCarriedAction($aar, $row['reference'], $row['disposition'], $row['note'] ?? null);
            }
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    public function finalise(Request $request, Aar $aar): RedirectResponse
    {
        Gate::authorize('bcms.aar.approve');
        $this->authorizeIncidentConjunction($aar);

        try {
            $this->service->finalise($aar, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Finalised. The timeline, scores, injects and attendance are now locked.');
    }

    public function reopen(ReopenAarRequest $request, Aar $aar): RedirectResponse
    {
        try {
            $this->service->reopen($aar, $request->user(), $request->string('reason')->toString());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reopened. Re-approval and re-distribution are required before this can be final again.');
    }

    public function distribute(Request $request, Aar $aar): RedirectResponse
    {
        Gate::authorize('bcms.aar.approve');
        $this->authorizeIncidentConjunction($aar);

        try {
            $this->service->distribute($aar, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Distributed.');
    }

    public function aiDraft(Request $request, Aar $aar): RedirectResponse
    {
        Gate::authorize('bcms.aar.manage');
        $this->authorizeIncidentConjunction($aar);

        // Gate 1 re-gate defect 6: fail fast with a named reason before
        // calling the drafter at all — `AarAiDrafter::draft()` refuses a
        // PIR too (belt-and-braces, in case a future caller reaches it
        // directly), but this is the message a real request sees.
        if ($aar->isPostIncident()) {
            return back()->with('error', 'AI drafting for a post-incident review runs under a separate '
                .'capability (post_incident_learning), which is not enabled in this deployment.');
        }

        try {
            $result = $this->drafter->draft($aar);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return back()->with('error', 'AI drafting is unavailable: '.$result['reason']);
        }

        // Flashed for exactly one redirect back — a proposal, not a stored
        // fact (class docblock).
        session()->flash('bcms_aar_ai_suggestions_'.$aar->getKey(), $result['suggested_findings']);

        return back()->with('success', 'Draft ready — review before saving.');
    }

    /**
     * `bcms.incident.manage`, additionally, whenever this `Aar` is a
     * post-incident review — see the class docblock. A plain exercise AAR
     * (`$aar->isPostIncident() === false`) is unaffected.
     */
    private function authorizeIncidentConjunction(Aar $aar): void
    {
        if ($aar->isPostIncident()) {
            Gate::authorize('bcms.incident.manage');
        }
    }
}
