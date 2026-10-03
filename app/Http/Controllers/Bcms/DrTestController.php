<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\ConfirmBcmsDrTestObjectivesRequest;
use App\Http\Requests\Bcms\StoreBcmsDrTestRequest;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Presenters\Bcms\DrPresenter;
use App\Services\Bcms\Dr\DrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * `docs/bcms/screens/dr-test-record.md` — a PLANNED test's outcome.
 *
 * "THIS IS FOR A TEST THAT WAS PLANNED AND RAN" (`dr-test-record.md` §1). A
 * real invocation is never written here — there is no route or method on
 * this controller that accepts an `incident_id` (ADR 0020 §4). See
 * `docs/bcms/screens/dr-failover-failback-record.md` for that case, which is
 * a read-only assembly reached from the incident, not a store route.
 */
class DrTestController extends Controller
{
    public function __construct(private DrService $dr, private DrPresenter $presenter) {}

    public function index(DrSystem $system): Response
    {
        Gate::authorize('bcms.dr.view');

        return Inertia::render('Bcms/Dr/Tests/Index', $this->presenter->testHistory($system));
    }

    public function show(DrTest $test): Response
    {
        Gate::authorize('bcms.dr.view');

        return Inertia::render('Bcms/Dr/Tests/Show', $this->presenter->testShow($test));
    }

    public function store(StoreBcmsDrTestRequest $request, DrSystem $system): RedirectResponse
    {
        $this->dr->recordTest($system, $request->validated(), $request->user());

        return redirect()->route('bcms.dr-systems.tests.index', $system)->with('success', 'Test recorded.');
    }

    public function confirmObjectives(ConfirmBcmsDrTestObjectivesRequest $request, DrTest $test): RedirectResponse
    {
        try {
            $this->dr->confirmObjectives($test, $request->boolean('met'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Objective confirmed.');
    }

    // Raising a finding from a breached test reuses the existing
    // `bcms.findings.store` route (`FindingController::store`, extended in
    // this phase to accept `dr_test_id`) rather than a second route here —
    // `dr-test-record.md` §2's own instruction.
}
