<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Controls\ExecuteControlTestRequest;
use App\Http\Requests\Controls\ReviewControlTestRequest;
use App\Http\Requests\Controls\StoreControlTestRequest;
use App\Http\Requests\Controls\UpdateControlTestRequest;
use App\Http\Requests\Controls\UploadControlTestEvidenceRequest;
use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Presenters\GridPresenter;
use App\Services\Controls\ControlTestService;
use App\Services\FileUploadService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Authorization\GraphScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Control testing (migration Phase 3.4). Each action authorises through
 * ControlTestPolicy, hands the work to ControlTestService, and renders a page.
 *
 * The lifecycle guards — "only a test pending review can be reviewed", "only a
 * rejected test can be resubmitted" — stay here rather than moving into the
 * policy. They answer with a flash message, not a 403, and
 * WorkflowEngine::canAct() asks the policy the same question about tasks that
 * are not yet decided.
 */
class ControlTestController extends Controller
{
    public function __construct(
        private readonly ControlTestService $tests,
        private readonly FileUploadService $uploads,
    ) {}

    /**
     * WP-00 node scoping.
     *
     * ControlTest carries the tenant global scope, so route-model binding is
     * tenant-safe; it does NOT use ScopedToGraph, so there is no
     * binding-level 404 for node scope to inherit. A test is visible exactly
     * when its control is, and this is where that is enforced — 404 rather
     * than the policy's 403, because the caller may hold every permission in
     * the product and leaking the existence of a sibling branch's test is
     * itself a disclosure. ControlTestPolicy checks the same reach as defence
     * in depth.
     *
     * This is EnforcesNodeScope::abortUnlessNodeVisibleThrough() inlined: the
     * trait also carries abortUnlessNodeVisible(), which this controller never
     * calls and which does not type-check for a record whose model is unknown.
     */
    private function abortUnlessVisible(ControlTest $test): void
    {
        if (! GraphScope::isSubtreeLimited(request()->user())) {
            return;
        }

        abort_unless(
            GraphScope::applyThrough(ControlTest::query()->whereKey($test->getKey()), 'control')->exists(),
            404
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard and index */
    /* ------------------------------------------------------------------ */

    public function dashboard()
    {
        Gate::authorize('viewAny', ControlTest::class);

        return Inertia::render('ControlTests/Dashboard', $this->tests->dashboard());
    }

    /**
     * WP-09: the register itself is the shared data grid — search, filters,
     * sorting, columns and export all live in
     * App\Grids\Definitions\ControlTestsGrid. Only the page header is left.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', ControlTest::class);

        $total = ControlTest::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('ControlTests/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('control_tests'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / Store */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        Gate::authorize('create', ControlTest::class);

        return Inertia::render('ControlTests/Create', array_merge(
            $this->tests->formOptions(),
            ['controlId' => $request->integer('control_id') ?: null],
        ));
    }

    public function store(StoreControlTestRequest $request)
    {
        $test = $this->tests->create(
            $request->validated(),
            TenantContext::organizationId(),
            $request->user()?->id,
        );

        return redirect()->route('risk.control-tests.show', $test)
            ->with('success', 'Control test scheduled successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);
        Gate::authorize('view', $controlTest);

        $user = $request->user();

        return Inertia::render('ControlTests/Show', array_merge(
            $this->tests->detail($controlTest),
            [
                'can' => [
                    'update' => $user->can('update', $controlTest)
                        && in_array($controlTest->status, ['scheduled', 'in_progress'], true),
                    'start' => $user->can('execute', $controlTest) && $controlTest->status === 'scheduled',
                    'complete' => $user->can('execute', $controlTest) && $controlTest->status === 'in_progress',
                    'uploadEvidence' => $user->can('execute', $controlTest),
                    'review' => $user->can('review', $controlTest) && $controlTest->status === 'pending_review',
                    'resubmit' => $user->can('resubmit', $controlTest) && $controlTest->status === 'rejected',
                ],
            ],
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / Update */
    /* ------------------------------------------------------------------ */

    public function edit(ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);
        Gate::authorize('update', $controlTest);

        return Inertia::render('ControlTests/Edit', array_merge(
            $this->tests->formOptions(),
            ['test' => $this->tests->present($controlTest->load(['tester', 'reviewer']))],
        ));
    }

    public function update(UpdateControlTestRequest $request, ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);

        $this->tests->update($controlTest, $request->validated());

        return redirect()->route('risk.control-tests.show', $controlTest)
            ->with('success', 'Control test updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Execution */
    /* ------------------------------------------------------------------ */

    public function startTest(ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);
        Gate::authorize('execute', $controlTest);

        if ($controlTest->status !== 'scheduled') {
            return back()->with('error', 'Only a scheduled test can be started.');
        }

        $this->tests->start($controlTest);

        return back()->with('success', 'Test started.');
    }

    public function completeTest(ExecuteControlTestRequest $request, ControlTest $controlTest, ModuleApprovals $approvals)
    {
        $this->abortUnlessVisible($controlTest);

        $this->tests->complete($controlTest, $request->validated(), $request->user(), $approvals);

        return back()->with('success', 'Test submitted'
            .($controlTest->reviewer_id ? ' for review. The reviewer has been notified.' : '.'));
    }

    public function reviewTest(ReviewControlTestRequest $request, ControlTest $controlTest, ModuleApprovals $approvals)
    {
        $this->abortUnlessVisible($controlTest);

        if ($controlTest->status !== 'pending_review') {
            return back()->with('error', 'Only tests pending review can be reviewed.');
        }

        $approved = $this->tests->review($controlTest, $request->validated(), $request->user(), $approvals);

        return back()->with('success', $approved
            ? 'Test approved.'
            : 'Test rejected. The tester has been notified.');
    }

    /**
     * Tester re-submits a rejected test after rework.
     * Status moves rejected -> in_progress (so they can update result again).
     */
    public function resubmit(ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);
        Gate::authorize('resubmit', $controlTest);

        if ($controlTest->status !== 'rejected') {
            return back()->with('error', 'Only rejected tests can be resubmitted.');
        }

        $this->tests->resubmit($controlTest);

        return back()->with('success', 'Test returned to in-progress for rework.');
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence */
    /* ------------------------------------------------------------------ */

    /**
     * Accept a piece of evidence against a control test.
     *
     * WP-11. Two defects were fixed when this was written, both of them
     * upload-policy defects that existed because the endpoint hand-rolled its
     * own policy instead of using the service written for it:
     *
     *  1. STORAGE. `$file->store(..., 'public')` put audit evidence in the
     *     web-served bucket (see downloadEvidence() below). It goes through
     *     FileUploadService, which can only ever write to the private disk.
     *
     *  2. FILE TYPE. `file_type` was the tail of the client-supplied filename.
     *     A file could be recorded as a `pdf` on the strength of nothing but
     *     its name, and that string is what the document repository shows an
     *     auditor. It is derived from the MIME type detected off the bytes.
     *
     * The accepted extension list and the 10 MB cap live in
     * FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE, which is what
     * UploadControlTestEvidenceRequest validates against, so the request rules
     * and the storage-time check cannot drift apart.
     */
    public function uploadEvidence(UploadControlTestEvidenceRequest $request, ControlTest $controlTest)
    {
        $this->abortUnlessVisible($controlTest);

        $this->tests->attachEvidence(
            $controlTest,
            $request->file('file'),
            $request->input('description'),
            $request->user()?->id,
        );

        return back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Stream a piece of control-test evidence to an authorised user.
     *
     * WP-11. The tenancy check below was always correct and was always
     * irrelevant, because the bytes it guarded were ALSO being served straight
     * off the web server: evidence was written to the `public` disk, whose root
     * is symlinked to `public/storage`, so a guessed URL returned it to anyone
     * — no session, no permission, no organisation check.
     *
     * Evidence now lives on the private `local` disk, which is not
     * web-reachable, so THIS action is the only way to the bytes and its checks
     * finally mean something.
     */
    public function downloadEvidence(ControlTest $controlTest, ControlTestEvidence $evidence)
    {
        $this->abortUnlessVisible($controlTest);
        Gate::authorize('view', $controlTest);

        abort_unless($evidence->control_test_id === $controlTest->id, 403, 'Unauthorized access.');

        try {
            return $this->uploads->download($evidence->file_path, $evidence->file_name);
        } catch (\RuntimeException $e) {
            // Missing on disk, or a stored path that does not resolve inside
            // the private disk. Either way the user gets the same answer and
            // the detail goes to the log, not to the response.
            Log::warning('Control test evidence could not be served.', [
                'evidence_id' => $evidence->id,
                'control_test_id' => $controlTest->id,
                'reason' => $e->getMessage(),
            ]);

            abort(404, 'Evidence file not found.');
        }
    }
}
