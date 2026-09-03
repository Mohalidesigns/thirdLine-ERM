<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Presenters\GridPresenter;
use App\Services\FileUploadService;
use App\Services\ReferenceCodeService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlTestController extends Controller
{
    /**
     * WP-11. Evidence upload and download both go through the shared upload
     * service now, so the disk, the extension allowlist, the size cap and the
     * traversal guard are decided in one place instead of per action.
     */
    public function __construct(
        private readonly FileUploadService $uploads,
    ) {}

    public function dashboard(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $totalTests = ControlTest::where('organization_id', $orgId)->count();
        $scheduledTests = ControlTest::where('organization_id', $orgId)->where('status', 'scheduled')->count();
        $inProgress = ControlTest::where('organization_id', $orgId)->where('status', 'in_progress')->count();
        $completedTests = ControlTest::where('organization_id', $orgId)->where('status', 'completed')->count();

        $overdueTests = ControlTest::where('organization_id', $orgId)
            ->where('status', 'scheduled')
            ->where('scheduled_date', '<', now())
            ->count();

        $passRate = $completedTests > 0
            ? round(ControlTest::where('organization_id', $orgId)->where('status', 'completed')->where('result', 'effective')->count() / $completedTests * 100, 1)
            : 0;

        $recentTests = ControlTest::where('organization_id', $orgId)
            ->with(['control', 'tester'])
            ->latest('updated_at')
            ->take(10)
            ->get();

        $upcomingTests = ControlTest::where('organization_id', $orgId)
            ->where('status', 'scheduled')
            ->with(['control', 'tester'])
            ->orderBy('scheduled_date')
            ->take(10)
            ->get();

        return view('risk.controls.testing-dashboard', compact(
            'totalTests', 'scheduledTests', 'inProgress', 'completedTests',
            'overdueTests', 'passRate', 'recentTests', 'upcomingTests'
        ));
    }

    /**
     * WP-09: the register itself is the shared data grid — search, filters,
     * sorting, columns and export all live in
     * App\Grids\Definitions\ControlTestsGrid. Only the page header is left.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        $total = ControlTest::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('ControlTests/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('control_tests'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        $orgId = auth()->user()->organization_id;
        $controls = Control::where('organization_id', $orgId)->orderBy('name')->get();
        $users = \App\Models\User::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();

        return view('risk.controls.tests.create', compact('controls', 'users'));
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $request->validate([
            'control_id' => ['required', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'title' => 'required|string|max:255',
            'test_type' => 'required|in:design_effectiveness,operating_effectiveness,walkthrough,substantive',
            'tester_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'reviewer_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'scheduled_date' => 'required|date',
        ]);

        $test = ControlTest::create([
            'organization_id' => auth()->user()->organization_id,
            'control_id' => $request->control_id,
            'test_code' => ReferenceCodeService::generate('control_tests', 'test_code', 'CT'),
            'title' => $request->title,
            'description' => $request->description,
            'test_type' => $request->test_type,
            'tester_id' => $request->tester_id,
            'reviewer_id' => $request->reviewer_id,
            'scheduled_date' => $request->scheduled_date,
            'status' => 'scheduled',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('risk.control-tests.show', $test)->with('success', 'Control test scheduled successfully.');
    }

    public function show(ControlTest $controlTest)
    {
        $controlTest->load(['control.risks', 'tester', 'reviewer', 'evidence', 'creator']);

        return view('risk.controls.tests.show', compact('controlTest'));
    }

    public function edit(ControlTest $controlTest)
    {
        $orgId = auth()->user()->organization_id;
        $controls = Control::where('organization_id', $orgId)->orderBy('name')->get();
        $users = \App\Models\User::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();

        return view('risk.controls.tests.edit', compact('controlTest', 'controls', 'users'));
    }

    public function update(Request $request, ControlTest $controlTest)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'test_type' => 'required',
            'scheduled_date' => 'required|date',
        ]);

        $controlTest->update($request->only([
            'title', 'description', 'test_type', 'tester_id', 'reviewer_id', 'scheduled_date',
        ]));

        return redirect()->route('risk.control-tests.show', $controlTest)->with('success', 'Control test updated.');
    }

    public function startTest(ControlTest $controlTest)
    {
        $controlTest->update(['status' => 'in_progress', 'started_date' => now()]);

        return back()->with('success', 'Test started.');
    }

    public function completeTest(Request $request, ControlTest $controlTest, ModuleApprovals $approvals)
    {
        $request->validate([
            'result' => 'required|in:effective,partially_effective,ineffective',
            'findings' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'score' => 'nullable|integer|min:0|max:100',
        ]);

        $controlTest->update([
            'result' => $request->result,
            'findings' => $request->findings,
            'recommendations' => $request->recommendations,
            'score' => $request->score,
            'completed_date' => now(),
            'status' => $controlTest->reviewer_id ? 'pending_review' : 'completed',
        ]);

        $controlTest->control->updateTestStats();

        // A test with no reviewer is complete on submission — there is nothing
        // to approve, so no workflow is started. That is the same rule the
        // status line above encodes; the engine does not change it.
        if ($controlTest->reviewer_id && ! $approvals->submit('control_test_review', $controlTest, [], $request->user())) {
            // No published definition for this tenant yet: tell the reviewer
            // directly, exactly as the approval queue used to.
            \App\Services\NotificationService::send(
                $controlTest->organization_id,
                $controlTest->reviewer_id,
                'approval_request',
                'Control test awaiting review: '.($controlTest->test_code ?? 'CT-'.$controlTest->id),
                'A control test has been submitted for your review.',
                ['entity_type' => $controlTest->getMorphClass(), 'entity_id' => $controlTest->id],
            );
        }

        return back()->with('success', 'Test submitted'
            .($controlTest->reviewer_id ? ' for review. The reviewer has been notified.' : '.'));
    }

    public function reviewTest(Request $request, ControlTest $controlTest, ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('review-control-test', $controlTest), 403,
            'Only the assigned reviewer or an authorised approver can review this test.');

        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'reviewer_notes' => 'nullable|string|max:3000',
            'rejection_reason' => 'required_if:action,reject|nullable|string|max:2000',
        ]);

        if ($controlTest->status !== 'pending_review') {
            return back()->with('error', 'Only tests pending review can be reviewed.');
        }

        $approve = $validated['action'] === 'approve';
        $comments = $approve
            ? ($validated['reviewer_notes'] ?? null)
            : ($validated['rejection_reason'] ?? $validated['reviewer_notes'] ?? null);

        // The status change, the reviewer stamp and the control's rolling
        // effectiveness recalculation all live in ControlTestBinding now, so a
        // review recorded from My Tasks does exactly what one recorded here does.
        if (! $approvals->decide($controlTest, $approve ? 'approve' : 'reject', $request->user(), ['comments' => $comments])) {
            $approvals->decideDirectly($controlTest, $approve ? 'approve' : 'reject', $request->user(), $comments);
        }

        return back()->with('success', $approve
            ? 'Test approved.'
            : 'Test rejected. The tester has been notified.');
    }

    /**
     * Tester re-submits a rejected test after rework.
     * Status moves rejected -> in_progress (so they can update result again).
     */
    public function resubmit(ControlTest $controlTest)
    {
        abort_unless(auth()->user()->can('resubmit-control-test', $controlTest), 403,
            'Only the tester or original creator can resubmit this test.');

        if ($controlTest->status !== 'rejected') {
            return back()->with('error', 'Only rejected tests can be resubmitted.');
        }

        $controlTest->update([
            'status' => 'in_progress',
            'result' => null,
            'reviewer_notes' => null,
            'reviewed_at' => null,
        ]);

        return back()->with('success', 'Test returned to in-progress for rework.');
    }

    /**
     * Stream a piece of control-test evidence to an authorised user.
     *
     * WP-11. The tenancy check below was always correct and was always
     * irrelevant, because the bytes it guarded were ALSO being served straight
     * off the web server. `uploadEvidence()` wrote to the `public` disk, whose
     * root (`storage/app/public`) is symlinked to `public/storage`, so
     * `GET /storage/control-test-evidence/{testId}/{name}.pdf` returned the
     * evidence to anyone who could guess or be given the URL — no session, no
     * `control_test.view` permission, no organisation check. Anyone holding one
     * URL also held the directory naming scheme for every other tenant.
     *
     * Evidence now lives on the private `local` disk (`storage/app/private`,
     * `serve => false`), which is not web-reachable, so THIS action is the only
     * way to the bytes and its checks finally mean something. Loss-event and
     * issue attachments were already on that disk behind exactly this shape of
     * action; control-test evidence was simply missed.
     *
     * Cross-tenant requests do not usually reach the 403 below: ControlTest
     * carries the tenant global scope, so route-model binding 404s first. The
     * explicit check stays because a 404 from the binding is a side effect of
     * another component's behaviour, and an authorisation guard should not
     * depend on one.
     */
    public function downloadEvidence(ControlTest $controlTest, ControlTestEvidence $evidence)
    {
        $orgId = TenantContext::organizationId();
        if ($controlTest->organization_id !== $orgId || $evidence->control_test_id !== $controlTest->id) {
            abort(403, 'Unauthorized access.');
        }

        try {
            return $this->uploads->download($evidence->file_path, $evidence->file_name);
        } catch (\RuntimeException $e) {
            // Missing on disk, or a stored path that does not resolve inside
            // the private disk. Either way the user gets the same answer and
            // the detail goes to the log, not to the response.
            \Illuminate\Support\Facades\Log::warning('Control test evidence could not be served.', [
                'evidence_id' => $evidence->id,
                'control_test_id' => $controlTest->id,
                'reason' => $e->getMessage(),
            ]);

            abort(404, 'Evidence file not found.');
        }
    }

    /**
     * Accept a piece of evidence against a control test.
     *
     * WP-11. Two defects fixed here, both of them upload-policy defects that
     * existed because this endpoint hand-rolled its own policy instead of
     * using the service written for it:
     *
     *  1. STORAGE. `$file->store(..., 'public')` put audit evidence in the
     *     web-served bucket (see downloadEvidence() above). It now goes through
     *     FileUploadService, which can only ever write to the private disk.
     *
     *  2. FILE TYPE. `file_type` was `$file->getClientOriginalExtension()` —
     *     the tail of the client-supplied filename. A file could be recorded as
     *     a `pdf` on the strength of nothing but its name, and that string is
     *     what the document repository shows an auditor. It is now derived from
     *     the MIME type detected off the bytes.
     *
     * The accepted extension list and the 10 MB cap are unchanged in effect;
     * they have simply moved into FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE
     * so the request rules and the storage-time check cannot drift apart.
     */
    public function uploadEvidence(Request $request, ControlTest $controlTest)
    {
        $orgId = TenantContext::organizationId();
        if ($controlTest->organization_id !== $orgId) {
            abort(403, 'Unauthorized access.');
        }

        $request->validate([
            'file' => $this->uploads->rules(FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE),
            'description' => 'nullable|string|max:500',
        ]);

        $stored = $this->uploads->store(
            $request->file('file'),
            'control-test-evidence/'.$controlTest->id,
            FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE,
        );

        ControlTestEvidence::create([
            'control_test_id' => $controlTest->id,
            'file_name' => $stored['file_name'],
            'file_path' => $stored['storage_path'],
            'file_type' => $stored['file_type'],
            'file_size' => $stored['file_size_bytes'],
            'description' => $request->description,
            'uploaded_by' => auth()->id(),
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }
}
