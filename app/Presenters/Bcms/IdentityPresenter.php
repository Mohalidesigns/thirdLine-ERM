<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\SyncChangeDecision;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Services\Bcms\Identity\ConnectorHealth;
use App\Support\Bcms\DirectoryAttributeMap;

/**
 * Shapes data for the two Phase 2C screens — controllers do not compute
 * (development standard §1).
 *
 * SECRETS NEVER REACH THIS CLASS'S OUTPUT. `IdentityConnector::$hidden`
 * already keeps `client_secret` off any `toArray()`, and every method here
 * builds its own array rather than passing the model through, so a future
 * column added to the model does not appear on a screen by accident.
 */
class IdentityPresenter
{
    public function __construct(private ConnectorHealth $health) {}

    /**
     * @return array<string, mixed>
     *
     * URL PROPS ARE BUILT HERE, NEVER IN JSX (`ModuleActionUrlRouteKeyTest`) —
     * this screen's own actions take no route parameter, but the runs table's
     * per-row review link binds a uuid-keyed `IdentitySyncRun`, so every
     * action on this screen is server-built for the same one reason.
     */
    public function connectorScreen(?IdentityConnector $connector): array
    {
        $recentRuns = $connector === null ? collect() : $connector->runs()->limit(10)->get();

        return [
            'connector' => $connector === null ? null : [
                'uuid' => $connector->uuid,
                'name' => $connector->name,
                'provider' => $connector->provider->value,
                'directory_tenant_id' => $connector->directory_tenant_id,
                'client_id' => $connector->client_id,
                'has_client_secret' => $connector->client_secret !== null,
                'token_base_url' => $connector->token_base_url,
                'graph_base_url' => $connector->graph_base_url,
                'directory_filter' => $connector->directory_filter,
                'attribute_map' => $connector->attribute_map ?? DirectoryAttributeMap::defaults(),
                'attribute_map_is_default' => $connector->attribute_map === null,
                'sync_schedule' => $connector->sync_schedule,
                'auto_apply_policy' => $connector->auto_apply_policy->value,
                'credential_expires_on' => $connector->credential_expires_on?->toDateString(),
                'is_active' => $connector->is_active,
                'health' => $this->health->summarize($connector) + [
                    'consecutive_failures' => $this->consecutiveFailures($recentRuns),
                ],
            ],
            'attribute_map_defaults' => DirectoryAttributeMap::defaults(),
            'never_synced_fields' => DirectoryAttributeMap::neverSynced(),
            'declared_scope' => ['User.Read.All'],
            'roster_summary' => $connector === null ? null : $this->rosterSummary((int) $connector->organization_id),
            'runs' => $recentRuns->map(fn (IdentitySyncRun $run) => $this->runSummary($run))->all(),
            'update_url' => route('bcms.settings.identity.update'),
            'test_url' => route('bcms.settings.identity.test'),
            'sync_url' => route('bcms.settings.identity.sync'),
            'runs_status_url' => route('bcms.settings.identity.runs-status'),
            'settings_index_url' => route('bcms.settings.index'),
        ];
    }

    /**
     * Consecutive `failed` runs counting back from the most recent, for the
     * last-run-failed banner's "{n} consecutive failure(s)" — computed here,
     * against the same rows the last-runs table already renders, rather than
     * a second query, so the two can never disagree.
     *
     * @param  \Illuminate\Support\Collection<int, IdentitySyncRun>  $runsNewestFirst
     */
    private function consecutiveFailures($runsNewestFirst): int
    {
        $count = 0;

        foreach ($runsNewestFirst as $run) {
            if ($run->status->value !== 'failed') {
                break;
            }

            $count++;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private function rosterSummary(int $organizationId): array
    {
        $base = Contact::query()->where('organization_id', $organizationId);

        return [
            'total_contacts' => (clone $base)->count(),
            'by_source' => [
                'entra' => (clone $base)->where('source', ContactSource::Entra->value)->count(),
                'manual' => (clone $base)->where('source', ContactSource::Manual->value)->count(),
                'self_service' => (clone $base)->where('source', ContactSource::SelfService->value)->count(),
            ],
            'unmatched_departments' => (clone $base)->where('source', ContactSource::Entra->value)->whereNull('business_unit_id')->count(),
            'no_manager_edge' => (clone $base)->where('is_active', true)->whereNull('manager_contact_id')->whereNull('manager_user_id')->count(),
            'consent_not_requested' => (clone $base)->where('consent_status', 'not_requested')->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function runsIndex(IdentityConnector $connector): array
    {
        return $connector->runs()->limit(50)->get()->map(fn (IdentitySyncRun $run) => $this->runSummary($run))->all();
    }

    /**
     * The small JSON document `CallTrees/Live.jsx`'s poll pattern mirrors
     * (§6, identity-connector.md) — latest run only, nothing a 5-second tick
     * should not repeat.
     *
     * @return array<string, mixed>
     */
    public function latestRunStatus(IdentityConnector $connector): array
    {
        $run = $connector->runs()->first();

        return ['run' => $run === null ? null : $this->runSummary($run)];
    }

    /** @return array<string, mixed> */
    public function runSummary(IdentitySyncRun $run): array
    {
        return [
            'uuid' => $run->uuid,
            'trigger' => $run->trigger->value,
            'trigger_label' => $run->trigger->label(),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'directory_objects_read' => $run->directory_objects_read,
            'pages_fetched' => $run->pages_fetched,
            'joiner_count' => $run->joiner_count,
            'leaver_count' => $run->leaver_count,
            'mover_count' => $run->mover_count,
            'contact_change_count' => $run->contact_change_count,
            'auto_applied_count' => $run->auto_applied_count,
            'pending_count' => $run->pending_count,
            'error_class' => $run->error_class,
            'error_code' => $run->error_code,
            // Server-built, never a uuid assembled in JSX (ModuleActionUrlRouteKeyTest).
            'review_url' => route('bcms.identity.runs.show', $run),
        ];
    }

    /**
     * Gate 2 defect 2 (identity-change-review.md §6): a run's changes are
     * paginated server-side — `changes` ships as a paginator (`data`/`links`,
     * `ImportPreview.jsx`'s shape), never every row for the run in one prop.
     * `kind` is the primary filter, `decision` the secondary one (default
     * `pending`, which is why superseded rows are absent unless a caller asks
     * for them explicitly). The five tile counts are a separate, cheap
     * grouped-count query against `decision = pending` only — they answer
     * "how many of each kind are waiting" regardless of which page or which
     * decision-view is on screen, so a reviewer three pages into "Leavers"
     * still sees the true totals in the tile row.
     *
     * @return array<string, mixed>
     */
    public function runShow(IdentitySyncRun $run, string $kind = 'all', string $decision = 'pending'): array
    {
        $run->loadMissing('connector');
        $connector = $run->connector;

        $changesBase = IdentitySyncChange::query()->where('sync_run_id', $run->getKey());

        $changesQuery = (clone $changesBase)->with(['contact:id,full_name', 'decidedByUser:id,name']);

        if ($kind !== 'all') {
            $changesQuery->where('kind', $kind);
        }

        match ($decision) {
            'decided' => $changesQuery->whereIn('decision', [
                SyncChangeDecision::Approved->value,
                SyncChangeDecision::Rejected->value,
                SyncChangeDecision::AutoApplied->value,
            ]),
            'superseded' => $changesQuery->where('decision', SyncChangeDecision::Superseded->value),
            default => $changesQuery->where('decision', SyncChangeDecision::Pending->value),
        };

        // requires_ack rows sit in their own visual band, first — §2's
        // "own visual band within each tab" — preserved across pages rather
        // than only within a single in-memory sort.
        $changes = $changesQuery
            ->orderByDesc('requires_ack')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $changes->through(fn (IdentitySyncChange $change) => $this->changeRow($run, $change));

        // Portable SQL (development standard, "the database gap"): plain
        // `count(*)` grouped by `kind`, no JSON/CTE/window function — safe on
        // MariaDB 10.4. `->toBase()` skips the `kind` enum cast so the
        // grouped rows key on the plain string value pagination and the tabs
        // both use.
        $pendingByKind = (clone $changesBase)
            ->where('decision', SyncChangeDecision::Pending->value)
            ->toBase()
            ->selectRaw('kind, count(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind');

        $needsAckCount = (clone $changesBase)
            ->where('decision', SyncChangeDecision::Pending->value)
            ->where('requires_ack', true)
            ->count();

        $tileCounts = [
            'joiner' => (int) ($pendingByKind['joiner'] ?? 0),
            'leaver' => (int) ($pendingByKind['leaver'] ?? 0),
            'mover' => (int) ($pendingByKind['mover'] ?? 0),
            'contact_change' => (int) ($pendingByKind['contact_change'] ?? 0),
            'needs_ack' => $needsAckCount,
        ];

        $supersededCount = (clone $changesBase)->where('decision', SyncChangeDecision::Superseded->value)->count();
        $decidedCount = (clone $changesBase)->whereIn('decision', [
            SyncChangeDecision::Approved->value,
            SyncChangeDecision::Rejected->value,
            SyncChangeDecision::AutoApplied->value,
        ])->count();

        return [
            'connector' => $connector === null ? null : ['uuid' => $connector->uuid, 'name' => $connector->name],
            'connector_settings_url' => route('bcms.settings.identity'),
            'run' => $this->runSummary($run),
            'runs' => $connector === null ? [] : $connector->runs()->limit(10)->get()
                ->map(fn (IdentitySyncRun $r) => $this->runSummary($r))->all(),
            'bulk_decide_url' => route('bcms.identity.changes.bulk-decide', $run),
            'filters' => ['kind' => $kind, 'decision' => $decision],
            'tile_counts' => $tileCounts,
            'pending_total' => $tileCounts['joiner'] + $tileCounts['leaver'] + $tileCounts['mover'] + $tileCounts['contact_change'],
            'superseded_count' => $supersededCount,
            'decided_count' => $decidedCount,
            'changes' => $changes,
        ];
    }

    /** @return array<string, mixed> */
    public function changeRow(IdentitySyncRun $run, IdentitySyncChange $change): array
    {
        return [
            'id' => $change->getKey(),
            'kind' => $change->kind->value,
            'subject_name' => $change->subject_name,
            'contact_id' => $change->contact_id,
            'before' => $change->before_json,
            'after' => array_diff_key((array) $change->after_json, array_flip(['_manager_object_id'])),
            'impact' => $change->impact_json,
            'requires_ack' => $change->requires_ack,
            'decision' => $change->decision->value,
            'decision_label' => $change->decision->label(),
            'decided_at' => $change->decided_at?->toIso8601String(),
            'decided_by_name' => $change->decidedByUser?->name,
            'applied_at' => $change->applied_at?->toIso8601String(),
            'apply_error_class' => $change->apply_error_class,
            'is_actionable' => $change->decision === SyncChangeDecision::Pending,
            // Server-built (ModuleActionUrlRouteKeyTest): {change} is a
            // numeric child of {run}, never assembled from a bare id in JSX.
            'decide_url' => route('bcms.identity.changes.decide', [$run, $change]),
        ];
    }
}
