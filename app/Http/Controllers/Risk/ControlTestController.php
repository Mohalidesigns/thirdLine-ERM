<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Services\ReferenceCodeService;
use Illuminate\Http\Request;

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
        $request->validate([
            'control_id'     => 'required|exists:controls,id',
            'title'          => 'required|string|max:255',
            'test_type'      => 'required|in:design_effectiveness,operating_effectiveness,walkthrough,substantive',
            'tester_id'      => 'required|exists:users,id',
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

    public function completeTest(Request $request, ControlTest $controlTest)
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

        return back()->with('success', 'Test completed successfully.');
    }

    public function reviewTest(Request $request, ControlTest $controlTest)
    {
        $request->validate([
            'reviewer_notes' => 'nullable|string',
            'action'         => 'required|in:approve,reject',
        ]);

        $controlTest->update([
            'reviewer_notes' => $request->reviewer_notes,
            'reviewed_at'    => now(),
            'reviewer_id'    => auth()->id(),
            'status'         => $request->action === 'approve' ? 'completed' : 'in_progress',
        ]);

        $controlTest->control->updateTestStats();

        return back()->with('success', 'Test review ' . ($request->action === 'approve' ? 'approved' : 'returned') . '.');
    }

    public function uploadEvidence(Request $request, ControlTest $controlTest)
    {
        $request->validate([
            'file'        => 'required|file|max:10240',
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
