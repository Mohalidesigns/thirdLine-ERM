<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\SyncRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\UpdateIdentityConnectorRequest;
use App\Jobs\Bcms\SyncBcmsIdentityJob;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncRun;
use App\Presenters\Bcms\IdentityPresenter;
use App\Services\Bcms\Identity\DirectorySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Entra connector — a section in the BCMS settings area, not a screen of
 * its own (ADR 0018 §5, work order §5: "no `ModuleSections` entry and no new
 * nav item — these screens are reached from BCMS settings").
 *
 * ONE CONNECTOR PER TENANT (`unique(organization_id, provider)`), so this
 * controller reads/writes a single row rather than a collection.
 */
class IdentityController extends Controller
{
    public function __construct(
        private IdentityPresenter $presenter,
        private DirectorySyncService $sync,
    ) {}

    public function show(): Response
    {
        Gate::authorize('bcms.identity.manage');

        return Inertia::render('Bcms/Settings/Identity', $this->presenter->connectorScreen($this->connector()));
    }

    public function update(UpdateIdentityConnectorRequest $request): RedirectResponse
    {
        $organizationId = TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            // `ResolveTenant` aborts with 403 before this action runs for any
            // web request with no organisation resolved — this is the belt
            // for that buckle (gate 2 advisory 6), in case a future path
            // ever reaches here without it. Without this check, creating a
            // connector with a null `organization_id` would 500 at the
            // column's own NOT NULL constraint instead of refusing cleanly.
            return back()->with('error', 'No organisation is resolved for this request; the connector was not saved.');
        }

        $connector = $this->connector();
        $data = $request->validated();

        // Secrets are write-only (ADR 0018 §5): a blank field leaves the
        // stored secret alone rather than wiping a working credential.
        if (! array_key_exists('client_secret', $data) || $data['client_secret'] === null || $data['client_secret'] === '') {
            unset($data['client_secret']);
        }

        if ($connector === null) {
            $data['organization_id'] = $organizationId;
            $data['provider'] = 'entra';
            $data['created_by'] = $request->user()?->getKey();
            $connector = IdentityConnector::query()->create($data);
            $connector->recordAudit('identity.connector.created', ['name' => $connector->name]);
        } else {
            $data['updated_by'] = $request->user()?->getKey();
            $connector->update($data);
            $connector->recordAudit('identity.connector.updated', ['name' => $connector->name]);
        }

        return back()->with('success', 'Identity connector saved.');
    }

    public function test(): RedirectResponse
    {
        Gate::authorize('bcms.identity.manage');

        $connector = $this->connector();

        if ($connector === null) {
            return back()->with('error', 'Save the connector before testing it.');
        }

        $result = $this->sync->testConnection($connector);

        if (! $result['ok']) {
            return back()->with('error', sprintf(
                'Test connection failed (%s / %s). Nothing was written; the directory was never contacted for anything beyond one page of one user.',
                $result['error_class'] ?? 'unknown_error',
                $result['error_code'] ?? 'n/a',
            ));
        }

        return back()->with('success', sprintf(
            'Connected. Token scopes: %s. Sample read: %d user(s).',
            implode(', ', $result['scopes']) ?: '(none reported)',
            $result['sample_count'],
        ));
    }

    /**
     * "Sync now" QUEUES the read; it never runs one inline.
     *
     * A synchronous `runFull()` here used to outlive nginx/FPM at 5,000
     * users, and an admin whose click appeared to hang would click again —
     * which is exactly the double-dispatch `SyncBcmsIdentityJob`'s
     * `ShouldBeUnique` key exists to absorb. Dispatch is the same one job
     * the nightly sweep and the 15-minute delta use, on the same
     * `bcms-sync` queue, so "Sync now" cannot land a 3,600-second read
     * behind interactive work by taking a different path than the
     * scheduler's.
     *
     * THE IN-FLIGHT CHECK IS HERE, NOT LEFT TO THE QUEUE'S OWN LOCK.
     * `ShouldBeUnique` stops a SECOND JOB being queued, but it drops the
     * duplicate silently — a caller cannot tell from `dispatch()` alone
     * whether it was suppressed. Reading `bcms_identity_sync_runs` for a
     * `running` row answers the question a human actually asked ("is one
     * happening right now") and lets the flash name it and link to it,
     * rather than "Sync finished: Running." ever being shown for a sync
     * that never started.
     */
    public function sync(): RedirectResponse
    {
        Gate::authorize('bcms.identity.manage');

        $connector = $this->connector();

        if ($connector === null || ! $connector->is_active) {
            return back()->with('error', 'The connector must be saved and active before a sync can run.');
        }

        $inFlight = IdentitySyncRun::query()
            ->where('identity_connector_id', $connector->getKey())
            ->where('status', SyncRunStatus::Running->value)
            ->orderByDesc('started_at')
            ->first();

        if ($inFlight !== null) {
            return redirect()
                ->route('bcms.identity.runs.show', $inFlight)
                ->with('error', sprintf(
                    'A sync is already running (started %s) — this is that run, not a new one.',
                    $inFlight->started_at?->diffForHumans() ?? 'moments ago',
                ));
        }

        SyncBcmsIdentityJob::dispatch(
            (int) $connector->getKey(),
            (int) $connector->organization_id,
            false,
            request()->user()?->getKey(),
        );

        return redirect()
            ->route('bcms.settings.identity')
            ->with('success', 'Sync queued. This page updates automatically once it starts.');
    }

    /**
     * The poll behind the run-in-progress banner (identity-connector.md §6)
     * — a small JSON document, latest run only, mirroring
     * `CallTreeTestController::live()`'s shape for the same reason.
     */
    public function runsStatus(): JsonResponse
    {
        Gate::authorize('bcms.identity.manage');

        $connector = $this->connector();

        return response()->json($connector === null ? ['run' => null] : $this->presenter->latestRunStatus($connector));
    }

    private function connector(): ?IdentityConnector
    {
        return IdentityConnector::query()
            ->where('organization_id', TenantContext::organizationIdOrNull())
            ->first();
    }
}
