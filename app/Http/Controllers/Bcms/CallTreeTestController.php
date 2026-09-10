<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\FindingClassification;
use App\Http\Controllers\Controller;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Presenters\Bcms\CallTreePresenter;
use App\Services\Bcms\CallTrees\BrokenBranchAnalyser;
use App\Services\Bcms\CallTrees\CallTreeRemediation;
use App\Services\Bcms\CallTrees\CascadeEngine;
use App\Services\Bcms\CallTrees\CascadeScorecard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Running a cascade, watching it, and acting on what it found.
 *
 * `live()` IS JSON AND IS POLLED, NOT PUSHED. The blueprint asks for SSE or a
 * websocket; this product broadcasts over the log driver and ships to on-prem
 * Nigerian banks where a websocket through the estate's proxy is a project of
 * its own. A cascade is minutes long and tens to hundreds of nodes wide, so a
 * five-second poll of one small JSON document is both sufficient and the only
 * option that works on the day of the demo behind a corporate firewall. It is
 * recorded as a deviation in `docs/bcms/phase-6-notes.md` rather than left for
 * somebody to discover.
 *
 * THE RESULTS SCREEN IS THE DEMO. Everything it needs is computed on the server
 * and shipped whole (development standard §1), because the moment that matters
 * is the one where somebody points at "34 staff isolated" and clicks the button
 * beside it.
 */
class CallTreeTestController extends Controller
{
    public function __construct(
        private CascadeEngine $engine,
        private CascadeScorecard $scorecard,
        private BrokenBranchAnalyser $branches,
        private CallTreeRemediation $remediation,
        private CallTreePresenter $presenter,
    ) {}

    public function store(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.test');

        $data = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', array_column(CascadeMode::cases(), 'value'))],
            'announced' => ['nullable', 'boolean'],
            'occurrence_id' => ['nullable', 'integer'],
            'initiate' => ['nullable', 'boolean'],
        ]);

        $occurrence = null;

        if (filled($data['occurrence_id'] ?? null)) {
            $occurrence = ExerciseOccurrence::query()->find((int) $data['occurrence_id']);

            if ($occurrence === null) {
                throw ValidationException::withMessages(['occurrence_id' => 'That calendar entry does not exist.']);
            }
        }

        try {
            $test = $this->engine->schedule(
                $call_tree,
                CascadeMode::from($data['mode']),
                (bool) ($data['announced'] ?? true),
                $occurrence,
                (int) $request->user()?->getKey(),
            );

            if ((bool) ($data['initiate'] ?? false)) {
                $this->engine->initiate($test, (int) $request->user()?->getKey());
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['mode' => $e->getMessage()]);
        }

        return redirect()->route('bcms.call-tree-tests.live', $test);
    }

    public function initiate(Request $request, CallTreeTest $test): RedirectResponse
    {
        Gate::authorize('bcms.calltree.test');

        try {
            $this->engine->initiate($test, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['initiate' => $e->getMessage()]);
        }

        return back()->with('success', 'Cascade initiated.');
    }

    /** The live map. */
    public function liveScreen(Request $request, CallTreeTest $test): Response
    {
        Gate::authorize('bcms.calltree.view');

        return Inertia::render('Bcms/CallTrees/Live', array_merge(
            $this->presenter->live($test),
            ['can' => ['test' => $request->user()?->can('bcms.calltree.test') === true]],
        ));
    }

    /** The polled payload behind it. */
    public function live(Request $request, CallTreeTest $test): JsonResponse
    {
        Gate::authorize('bcms.calltree.view');

        // Advancing the clock on read is deliberate. A cascade whose timeouts
        // only fire when a scheduled command runs would sit visibly stuck for
        // up to a minute during the one demo where somebody is watching, and
        // the tick is idempotent — it settles what is already overdue and
        // nothing else.
        if ($this->engine->isRunning($test)) {
            $this->engine->tick($test);
        }

        return response()->json($this->presenter->live($test->refresh()));
    }

    /** Results, scorecard and broken branches. */
    public function show(Request $request, CallTreeTest $test): Response
    {
        Gate::authorize('bcms.calltree.view');

        return Inertia::render('Bcms/CallTrees/Results', array_merge(
            $this->presenter->results($test),
            ['can' => [
                'test' => $request->user()?->can('bcms.calltree.test') === true,
                'manage' => $request->user()?->can('bcms.calltree.manage') === true,
                'contacts' => $request->user()?->can('bcms.contact.manage') === true,
                'findings' => $request->user()?->can('bcms.finding.manage') === true,
            ]],
        ));
    }

    public function scorecard(Request $request, CallTreeTest $test): JsonResponse
    {
        Gate::authorize('bcms.calltree.view');

        // A completed test reprints what was stored; a running one is computed
        // live. Recomputing a completed one would let the record improve as the
        // tree was repaired.
        return response()->json([
            'scorecard' => $test->completed_at !== null && $test->scorecard !== []
                ? $test->scorecard
                : $this->scorecard->compute($test),
            'stored' => $test->completed_at !== null,
        ]);
    }

    public function brokenBranches(Request $request, CallTreeTest $test): JsonResponse
    {
        Gate::authorize('bcms.calltree.view');

        return response()->json($this->branches->analyse($test));
    }

    /* ------------------------------------------------------------------ */
    /*  Recording what happened */
    /* ------------------------------------------------------------------ */

    public function acknowledge(Request $request, CallTreeTest $test, CallTreeTestNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.view');

        $this->assertNodeBelongs($test, $node);

        $data = $request->validate([
            'correct_action' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->engine->acknowledge(
            $node,
            via: CascadeEngine::CHANNEL_IN_APP,
            correctAction: (bool) ($data['correct_action'] ?? true),
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Acknowledgement recorded.');
    }

    public function recordFailure(Request $request, CallTreeTest $test, CallTreeTestNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.test');

        $this->assertNodeBelongs($test, $node);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', array_column(
                array_filter(CascadeOutcome::cases(), fn (CascadeOutcome $o) => ! $o->isReached() && $o !== CascadeOutcome::Pending),
                'value',
            ))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->engine->recordFailure($node, CascadeOutcome::from($data['outcome']), $data['note'] ?? null);

        return back()->with('success', 'Recorded.');
    }

    public function complete(Request $request, CallTreeTest $test): RedirectResponse
    {
        Gate::authorize('bcms.calltree.test');

        $this->engine->complete($test, (int) $request->user()?->getKey());

        return redirect()->route('bcms.call-tree-tests.show', $test)
            ->with('success', 'Cascade closed and scored.');
    }

    public function abort(Request $request, CallTreeTest $test): RedirectResponse
    {
        Gate::authorize('bcms.calltree.test');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->engine->abort($test, $data['reason'], (int) $request->user()?->getKey());

        return redirect()->route('bcms.call-tree-tests.show', $test)
            ->with('success', 'Cascade aborted. Everything recorded up to that point is kept.');
    }

    /* ------------------------------------------------------------------ */
    /*  The one-click actions */
    /* ------------------------------------------------------------------ */

    public function fixContact(Request $request, CallTreeTest $test, CallTreeTestNode $node): RedirectResponse
    {
        Gate::authorize('bcms.contact.manage');

        $this->assertNodeBelongs($test, $node);

        $data = $request->validate([
            'mobile_primary' => ['nullable', 'string', 'max:32'],
            'mobile_secondary' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:200'],
        ]);

        $contact = $node->node?->contact;

        if (! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'mobile_primary' => 'This node has no contact record to correct. Re-point the node at a person first.',
            ]);
        }

        try {
            $this->remediation->fixContact($contact, array_filter($data, fn ($v) => $v !== null), (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['mobile_primary' => $e->getMessage()]);
        }

        return back()->with('success', 'Contact record corrected. It is marked unverified until somebody confirms it.');
    }

    public function raiseFinding(Request $request, CallTreeTest $test, CallTreeTestNode $node): RedirectResponse
    {
        Gate::authorize('bcms.finding.manage');

        $this->assertNodeBelongs($test, $node);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'classification' => ['nullable', 'string', 'in:observation,improvement,nonconformity'],
        ]);

        $finding = $this->remediation->raiseFinding(
            $test,
            $node,
            $data['description'] ?? null,
            FindingClassification::from($data['classification'] ?? 'observation'),
            (int) $request->user()?->getKey(),
        );

        return back()->with('success', 'Raised as '.$finding->reference.' in the corrective action register.');
    }

    /* ------------------------------------------------------------------ */

    private function assertNodeBelongs(CallTreeTest $test, CallTreeTestNode $node): void
    {
        abort_unless((int) $node->test_id === (int) $test->getKey(), 404);
    }
}
