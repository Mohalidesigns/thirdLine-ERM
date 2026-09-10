<?php

namespace App\Http\Controllers\Tprm;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Obligation;
use App\Presenters\GridPresenter;
use App\Services\Tprm\Contracts\ObligationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The obligation register — FR-CTR-06.
 *
 * The tiles split by WHO OWES WHAT before anything else, because those are two
 * different conversations: chasing a vendor, and doing the thing ourselves.
 * The second is the one that produces supervisory findings and the one a
 * register full of vendor duties quietly hides.
 */
class ObligationController extends Controller
{
    public function __construct(private readonly ObligationService $obligations) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Obligation::class);

        return Inertia::render('Tprm/Obligations/Index', [
            'summary' => fn () => $this->obligations->summary($request->user()->organization_id),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_obligations'),
                $request,
                $request->user()
            ),
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
            ],
        ]);
    }

    /**
     * Record that a duty was performed.
     *
     * A refusal for missing evidence is a flash message rather than a 403: the
     * user holds the authority, and what is wrong is that a tick with nothing
     * behind it is what a supervisor asks to see behind.
     */
    public function satisfy(Request $request, Obligation $obligation)
    {
        Gate::authorize('update', $obligation);

        $validated = $request->validate([
            'evidence_document_id' => 'nullable|integer',
        ]);

        $result = $this->obligations->satisfy(
            $obligation,
            $validated['evidence_document_id'] ?? null,
            $request->user()->id,
        );

        if (! $result['satisfied']) {
            return back()->withInput()->with('error', $result['reason']);
        }

        return back()->with('success', $result['next_due'] === null
            ? 'Recorded. This obligation is complete.'
            : 'Recorded. It falls due again on '.$result['next_due'].'.');
    }

    public function assign(Request $request, Obligation $obligation)
    {
        Gate::authorize('update', $obligation);

        $validated = $request->validate([
            'owner_id' => 'required|integer|exists:users,id',
        ]);

        $obligation->forceFill([
            'owner_id' => $validated['owner_id'],
            'updated_by' => $request->user()->id,
        ])->save();

        return back()->with('success', 'The obligation was assigned.');
    }

    /**
     * Record a breach by hand.
     *
     * The nightly sweep records breaches past the grace period; this is for
     * the case a person knows about before the clock does — a vendor that has
     * said it will miss next month's report.
     */
    public function breach(Request $request, Obligation $obligation)
    {
        Gate::authorize('update', $obligation);

        $validated = $request->validate([
            'note' => 'required|string|min:10|max:1000',
        ]);

        $this->obligations->breach($obligation, $validated['note'], $request->user()->id);

        return back()->with('success', 'The breach was recorded and the obligation advanced to its next occurrence.');
    }
}
