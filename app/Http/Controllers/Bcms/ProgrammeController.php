<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreBcmsProgrammeRequest;
use App\Http\Requests\Bcms\StoreBcmsScopeItemRequest;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\BusinessUnit;
use App\Presenters\Bcms\ProgrammePresenter;
use App\Services\Bcms\MaturityService;
use App\Services\Bcms\ProgrammeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programme governance — ISO 22301 clauses 4, 5, 6 and 9.3.
 *
 * Lifecycle rules live here rather than in a policy, per development standard
 * §3: "only an approved programme can be activated" is a lifecycle question, and
 * the right answer to it is a flash message, not a 403. The policy answers a
 * different question — may this person act at all.
 */
class ProgrammeController extends Controller
{
    public function __construct(
        private ProgrammeService $programmes,
        private MaturityService $maturity,
    ) {}

    public function index(Request $request, ProgrammePresenter $presenter): Response
    {
        Gate::authorize('bcms.view');

        $programme = Programme::query()
            ->with(['owner:id,name', 'approver:id,name'])
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Bcms/Programme/Index', $presenter->present($programme, $request->user()) + [
            'business_units' => BusinessUnit::query()->orderBy('name')->get(['id', 'code', 'name']),
            'processes' => Process::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreBcmsProgrammeRequest $request): RedirectResponse
    {
        $programme = $this->programmes->create($request->validated());

        return back()->with('success', "Programme '{$programme->name}' created.");
    }

    public function update(StoreBcmsProgrammeRequest $request, Programme $programme): RedirectResponse
    {
        if ($programme->status === 'closed') {
            return back()->with('error', 'A closed programme cannot be edited.');
        }

        $programme->update($request->validated() + ['updated_by' => $request->user()?->getKey()]);

        return back()->with('success', 'Programme updated.');
    }

    public function approve(Request $request, Programme $programme): RedirectResponse
    {
        Gate::authorize('bcms.programme.approve');

        try {
            $this->programmes->approve($programme, (int) $request->user()->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Programme approved.');
    }

    public function activate(Request $request, Programme $programme): RedirectResponse
    {
        Gate::authorize('bcms.programme.approve');

        try {
            $this->programmes->activate($programme, (int) $request->user()->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Programme activated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Scope — clause 4.3 */
    /* ------------------------------------------------------------------ */

    public function storeScope(StoreBcmsScopeItemRequest $request, Programme $programme): RedirectResponse
    {
        $data = $request->validated();

        $scopable = $data['scopable_type'] === 'business_unit'
            ? BusinessUnit::query()->findOrFail($data['scopable_id'])
            : Process::query()->findOrFail($data['scopable_id']);

        try {
            $this->programmes->setScope($programme, $scopable, (bool) $data['in_scope'], $data['rationale'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Scope updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Obligations */
    /* ------------------------------------------------------------------ */

    public function seedObligations(Programme $programme): RedirectResponse
    {
        Gate::authorize('bcms.programme.manage');

        $added = $this->programmes->seedObligations($programme);

        return back()->with('success', $added === 0
            ? 'The obligation register is already up to date.'
            : "{$added} obligation(s) added to the register.");
    }

    public function updateObligation(Request $request, ProgrammeObligation $obligation): RedirectResponse
    {
        Gate::authorize('bcms.programme.manage');

        $data = $request->validate([
            'applies' => ['required', 'boolean'],
            // An obligation marked NOT applicable must say why. "This does not
            // apply to us" with no reason is the sentence an examiner asks
            // about first.
            'applicability_note' => ['nullable', 'string', 'max:1000', 'required_if:applies,false'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'how_satisfied' => ['nullable', 'string', 'max:2000'],
        ], [
            'applicability_note.required_if' => 'An obligation marked as not applicable needs a reason.',
        ]);

        $obligation->update($data + ['updated_by' => $request->user()?->getKey()]);

        return back()->with('success', 'Obligation updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Management review — clause 9.3 */
    /* ------------------------------------------------------------------ */

    public function storeReview(Request $request, Programme $programme): RedirectResponse
    {
        Gate::authorize('bcms.programme.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'held_on' => ['required', 'date'],
            'chaired_by' => ['nullable', 'integer', 'exists:users,id'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['string', 'max:200'],
            'discussion' => ['nullable', 'string'],
        ]);

        $review = $this->programmes->openManagementReview($programme, $data['title'], $data);

        // The inputs are captured at creation so the record shows what the
        // meeting could actually see. Re-capturing later is a deliberate act.
        $this->programmes->captureReviewInputs($review);

        return back()->with('success', "Management review {$review->reference} opened, with its clause 9.3 inputs captured.");
    }

    public function captureReviewInputs(ManagementReview $review): RedirectResponse
    {
        Gate::authorize('bcms.programme.manage');

        $this->programmes->captureReviewInputs($review);

        return back()->with('success', 'Clause 9.3 inputs re-captured.');
    }

    public function approveReview(Request $request, ManagementReview $review): RedirectResponse
    {
        Gate::authorize('bcms.programme.approve');

        try {
            $this->programmes->approveManagementReview($review, (int) $request->user()->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Management review approved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Maturity */
    /* ------------------------------------------------------------------ */

    public function assessMaturity(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.report.view');

        $assessment = $this->maturity->assess(null, 'manual', $request->user()?->getKey());

        return back()->with('success', $assessment->overall_score === null
            ? 'Maturity assessed. No clause group has enough evidence to score yet.'
            : "Maturity assessed: {$assessment->overall_score} out of 5.");
    }
}
