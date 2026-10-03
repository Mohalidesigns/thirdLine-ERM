<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\SyncChangeDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\BulkDecideIdentitySyncChangesRequest;
use App\Http\Requests\Bcms\DecideIdentitySyncChangeRequest;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Presenters\Bcms\IdentityPresenter;
use App\Services\Bcms\Identity\DirectorySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The sync change review queue — ADR 0018 §5 item 2, work order §5.
 *
 * `{run}` BINDS BY UUID; `{change}` IS A NESTED NUMERIC CHILD reached through
 * `->scopeBindings()` on the two per-change routes (ADR 0017 §5) — an id
 * from a different run 404s rather than resolving.
 */
class IdentitySyncController extends Controller
{
    public function __construct(
        private IdentityPresenter $presenter,
        private DirectorySyncService $sync,
    ) {}

    public function index(): Response|RedirectResponse
    {
        Gate::authorize('bcms.identity.review');

        $connector = IdentityConnector::query()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->first();

        // The screen's own design is "landed on one run's queue" (identity-
        // change-review.md §2) — the bare index only ever renders its own
        // "no sync has run yet" state; the moment a run exists, the latest
        // one is what a reviewer actually wants.
        if ($connector !== null) {
            $latest = $connector->runs()->first();

            if ($latest !== null) {
                return redirect()->route('bcms.identity.runs.show', $latest);
            }
        }

        return Inertia::render('Bcms/Identity/Review', [
            'connector' => $connector === null ? null : ['uuid' => $connector->uuid, 'name' => $connector->name],
            'connector_settings_url' => route('bcms.settings.identity'),
            'runs' => $connector === null ? [] : $this->presenter->runsIndex($connector),
        ]);
    }

    /**
     * `kind`/`decision` are read here, not trusted from the presenter's
     * caller — an unrecognised value falls back to the screen's own default
     * rather than reaching a `match()`/`where()` with a value nothing
     * validated (identity-change-review.md §6, gate 2 defect 2).
     */
    public function show(Request $request, IdentitySyncRun $run): Response
    {
        Gate::authorize('bcms.identity.review');

        $kind = $request->query('kind', 'all');
        if (! in_array($kind, ['all', 'joiner', 'leaver', 'mover', 'contact_change'], true)) {
            $kind = 'all';
        }

        $decision = $request->query('decision', 'pending');
        if (! in_array($decision, ['pending', 'decided', 'superseded'], true)) {
            $decision = 'pending';
        }

        return Inertia::render('Bcms/Identity/Review', $this->presenter->runShow($run, $kind, $decision));
    }

    public function decide(IdentitySyncRun $run, IdentitySyncChange $change, DecideIdentitySyncChangeRequest $request): RedirectResponse
    {
        if ($change->decision !== SyncChangeDecision::Pending) {
            return back()->with('error', 'This change has already been decided.');
        }

        $this->sync->decide($change, SyncChangeDecision::from($request->validated()['decision']), $request->user()?->getKey());

        return back()->with('success', 'Decision recorded.');
    }

    public function bulkDecide(IdentitySyncRun $run, BulkDecideIdentitySyncChangesRequest $request): RedirectResponse
    {
        $decision = SyncChangeDecision::from($request->validated()['decision']);
        $userId = $request->user()?->getKey();

        $candidates = $run->changes()
            ->whereIn('id', $request->validated()['change_ids'])
            ->where('decision', SyncChangeDecision::Pending->value)
            ->get();

        // ADR 0018 §3.2/§5: a change that breaks a call tree or empties a
        // saved audience requires individual acknowledgement and is
        // unconditionally excluded from bulk approval — "there is no
        // `all`". The review screen enforces this only by disabling the
        // row's checkbox, which is a client-side control; a requires_ack id
        // reaching this action at all means it did not come from the
        // screen (a non-UI caller), so it is refused here too rather than
        // trusted. The per-row `decide()` action remains the only route
        // that may apply a requires_ack change.
        [$blocked, $changes] = $candidates->partition(fn (IdentitySyncChange $change) => $change->requires_ack);

        foreach ($changes as $change) {
            $this->sync->decide($change, $decision, $userId);
        }

        if ($blocked->isNotEmpty()) {
            // The refused attempt is audited even though nothing about the
            // blocked rows changed — the attempt itself, on a run that IS
            // reachable to this tenant/reviewer, is what a regulator would
            // ask about, not merely the outcome of a change that never
            // happened.
            $run->recordAudit('identity.sync.bulk_decide_refused', [
                'requires_ack_change_ids' => $blocked->pluck('id')->values()->all(),
                'decision_attempted' => $decision->value,
            ]);

            return back()->with(
                'error',
                sprintf(
                    '%d change(s) %s. %d change(s) require individual acknowledgement (id: %s) and were NOT applied — decide each one individually.',
                    $changes->count(),
                    $decision->value,
                    $blocked->count(),
                    $blocked->pluck('id')->implode(', '),
                ),
            );
        }

        return back()->with('success', sprintf('%d change(s) %s.', $changes->count(), $decision->value));
    }
}
