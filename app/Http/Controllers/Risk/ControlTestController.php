<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Services\ApprovalService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControlTestController extends Controller
{
    public function dashboard(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $totalTests     = ControlTest::where('organization_id', $orgId)->count();
        $scheduledTests = ControlTest::where('organization_id', $orgId)->where('status', 'scheduled')->count();
        $inProgress     = ControlTest::where('organization_id', $orgId)->where('status', 'in_progress')->count();
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

    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $query = ControlTest::where('organization_id', $orgId)->with(['control', 'tester', 'reviewer']);

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('result'))   $query->where('result', $request->result);
        if ($request->filled('control'))  $query->where('control_id', $request->control);

        $tests = $query->latest('scheduled_date')->paginate(20);
        $controls = Control::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.controls.tests.index', compact('tests', 'controls'));
    }

    public function create()
    {
        $orgId    = auth()->user()->organization_id;
        $controls = Control::where('organization_id', $orgId)->orderBy('name')->get();
        $users    = \App\Models\User::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();

        return view('risk.controls.tests.create', compact('controls', 'users'));
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $request->validate([
            'control_id'     => ['required', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'title'          => 'required|string|max:255',
            'test_type'      => 'required|in:design_effectiveness,operating_effectiveness,walkthrough,substantive',
            'tester_id'      => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'reviewer_id'    => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'scheduled_date' => 'required|date',
        ]);

        $test = ControlTest::create([
            'organization_id' => auth()->user()->organization_id,
            'control_id'      => $request->control_id,
            'test_code'       => ReferenceCodeService::generate('control_tests', 'test_code', 'CT'),
            'title'           => $request->title,
            'description'     => $request->description,
            'test_type'       => $request->test_type,
            'tester_id'       => $request->tester_id,
            'reviewer_id'     => $request->reviewer_id,
            'scheduled_date'  => $request->scheduled_date,
            'status'          => 'scheduled',
            'created_by'      => auth()->id(),
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
        $orgId    = auth()->user()->organization_id;
        $controls = Control::where('organization_id', $orgId)->orderBy('name')->get();
        $users    = \App\Models\User::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();

        return view('risk.controls.tests.edit', compact('controlTest', 'controls', 'users'));
    }

    public function update(Request $request, ControlTest $controlTest)
    {
        $request->validate([
            'title'          => 'required|string|max:255',
            'test_type'      => 'required',
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

    public function completeTest(Request $request, ControlTest $controlTest, ApprovalService $approvals)
    {
        $request->validate([
            'result'          => 'required|in:effective,partially_effective,ineffective',
            'findings'        => 'nullable|string',
            'recommendations' => 'nullable|string',
            'score'           => 'nullable|integer|min:0|max:100',
        ]);

        $controlTest->update([
            'result'          => $request->result,
            'findings'        => $request->findings,
            'recommendations' => $request->recommendations,
            'score'           => $request->score,
            'completed_date'  => now(),
            'status'          => $controlTest->reviewer_id ? 'pending_review' : 'completed',
        ]);

        $controlTest->control->updateTestStats();

        // Open an approval request so the reviewer sees it in their queue and
        // gets notified (in-app + email placeholder).
        if ($controlTest->reviewer_id) {
            $approvals->requestApproval(
                $controlTest,
                action: 'approve_control_test',
                payload: ['result' => $controlTest->result, 'score' => $controlTest->score],
                reviewerId: $controlTest->reviewer_id,
            );
        }

        return back()->with('success', 'Test submitted'
            . ($controlTest->reviewer_id ? ' for review. The reviewer has been notified.' : '.'));
    }

    public function reviewTest(Request $request, ControlTest $controlTest, ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('review-control-test', $controlTest), 403,
            'Only the assigned reviewer or an authorised approver can review this test.');

        $validated = $request->validate([
            'action'         => 'required|in:approve,reject',
            'reviewer_notes' => 'nullable|string|max:3000',
            'rejection_reason' => 'required_if:action,reject|nullable|string|max:2000',
        ]);

        if ($controlTest->status !== 'pending_review') {
            return back()->with('error', 'Only tests pending review can be reviewed.');
        }

        if ($validated['action'] === 'approve') {
            $controlTest->update([
                'reviewer_notes' => $validated['reviewer_notes'] ?? null,
                'reviewed_at'    => now(),
                'reviewer_id'    => auth()->id(),
                'status'         => 'completed',
            ]);
            $approvals->approve(
                $approvals->latestPending($controlTest) ?? $approvals->requestApproval($controlTest, 'approve_control_test'),
                auth()->id(),
                $validated['reviewer_notes'] ?? null,
            );
            $message = 'Test approved.';
        } else {
            $controlTest->update([
                'reviewer_notes' => $validated['reviewer_notes'] ?? null,
                'reviewed_at'    => now(),
                'reviewer_id'    => auth()->id(),
                'status'         => 'rejected',
            ]);
            $approvals->reject(
                $approvals->latestPending($controlTest) ?? $approvals->requestApproval($controlTest, 'approve_control_test'),
                auth()->id(),
                $validated['rejection_reason'],
            );
            $message = 'Test rejected. The tester has been notified.';
        }

        $controlTest->control->updateTestStats();

        return back()->with('success', $message);
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

    public function downloadEvidence(ControlTest $controlTest, ControlTestEvidence $evidence)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($controlTest->organization_id !== $orgId || $evidence->control_test_id !== $controlTest->id) {
            abort(403, 'Unauthorized access.');
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (! $disk->exists($evidence->file_path)) {
            abort(404, 'Evidence file not found.');
        }

        return $disk->download($evidence->file_path, $evidence->file_name);
    }

    public function uploadEvidence(Request $request, ControlTest $controlTest)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($controlTest->organization_id !== $orgId) {
            abort(403, 'Unauthorized access.');
        }

        $request->validate([
            'file'        => 'required|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,png,jpg,jpeg,gif',
            'description' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $path = $file->store('control-test-evidence/' . $controlTest->id, 'public');

        ControlTestEvidence::create([
            'control_test_id' => $controlTest->id,
            'file_name'       => $file->getClientOriginalName(),
            'file_path'       => $path,
            'file_type'       => $file->getClientOriginalExtension(),
            'file_size'       => $file->getSize(),
            'description'     => $request->description,
            'uploaded_by'     => auth()->id(),
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }
}
