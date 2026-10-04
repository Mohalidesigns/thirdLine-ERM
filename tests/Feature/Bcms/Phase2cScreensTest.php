<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DirectoryClient;
use App\Enums\Bcms\SyncChangeDecision;
use App\Enums\Bcms\SyncTrigger;
use App\Jobs\Bcms\SyncBcmsIdentityJob;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Identity\DirectorySyncService;
use App\Services\Bcms\Identity\FakeDirectoryClient;
use App\Support\Bcms\DirectoryUser;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The two Phase 2C screens — ADR 0018 §5, work order §5.
 *
 * PROPS BOUNDARY ONLY (development standard §10): what is asserted is the
 * shape the server shipped, not that React drew it.
 *
 * TWO PERMISSIONS, TESTED SEPARATELY. `bcms.identity.manage` holds the
 * credential and runs a sync; `bcms.identity.review` decides a change. A test
 * that used one admin for both would never notice if the split collapsed.
 */
class Phase2cScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email, ?int $organizationId = null): User
    {
        $user = User::create([
            'organization_id' => $organizationId ?? $this->organization->id, 'name' => 'Test User', 'email' => $email,
            'password' => Hash::make('secret'), 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.md5($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function connector(array $overrides = []): IdentityConnector
    {
        return IdentityConnector::query()->create(array_merge([
            'organization_id' => $this->organization->id,
            'provider' => 'entra', 'name' => 'KHB Entra', 'directory_tenant_id' => 't',
            'client_id' => 'c', 'client_secret' => 's',
            'token_base_url' => 'https://login.microsoftonline.com',
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_schedule' => 'nightly', 'auto_apply_policy' => 'none', 'is_active' => true,
        ], $overrides));
    }

    /* ================================================================== */
    /*  The connector screen */
    /* ================================================================== */

    #[Test]
    public function the_connector_screen_requires_identity_manage(): void
    {
        $this->actingAs($this->userWith([], 'nobody@khb.test'))
            ->get(route('bcms.settings.identity'))
            ->assertForbidden();
    }

    #[Test]
    public function the_connector_screen_never_ships_the_client_secret(): void
    {
        $connector = $this->connector();

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'admin@khb.test'))
            ->get(route('bcms.settings.identity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Settings/Identity', false)
                ->where('connector.has_client_secret', true)
                ->missing('connector.client_secret')
                ->has('attribute_map_defaults')
                ->has('never_synced_fields')
                ->where('declared_scope', ['User.Read.All'])
                ->has('roster_summary')
                ->has('runs')
                // Every action on this screen is a server-built prop
                // (ModuleActionUrlRouteKeyTest) — asserted here as the
                // contract, not merely by absence of a numeric id in JSX.
                ->where('update_url', route('bcms.settings.identity.update'))
                ->where('test_url', route('bcms.settings.identity.test'))
                ->where('sync_url', route('bcms.settings.identity.sync'))
                ->where('runs_status_url', route('bcms.settings.identity.runs-status'))
                ->where('settings_index_url', route('bcms.settings.index'))
            );
    }

    /**
     * `ConnectorHealth::isStale()` counts ANY successful run, delta included
     * — right for "is the screen green", wrong for "is the hierarchy still
     * being maintained" (ADR 0018 §3.1: a delta never re-resolves a manager
     * edge). `full_reconciliation_stale` is the second, narrower fact: a
     * tenant on `nightly_plus_delta` whose deltas keep succeeding while the
     * nightly full has failed for a week must show BOTH — `is_stale: false`
     * (the screen has no reason to panic) and `full_reconciliation_stale:
     * true` (the tree is quietly going out of date), or the connector
     * screen and `bcms:watchdog`'s `hierarchyStale()` alert disagree about
     * the same connector.
     */
    #[Test]
    public function the_connector_screen_flags_a_stale_full_reconciliation_even_when_a_delta_recently_succeeded(): void
    {
        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta']);

        $fullRun = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'scheduled_full',
            'started_at' => now()->subDays(6),
            'finished_at' => now()->subDays(6),
            'status' => 'success',
        ]);

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'scheduled_delta',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'status' => 'success',
        ]);

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'health-admin@khb.test'))
            ->get(route('bcms.settings.identity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('connector.health.is_stale', false)
                ->where('connector.health.full_reconciliation_stale', true)
                ->where('connector.health.last_full_reconciliation_at', $fullRun->finished_at->toIso8601String())
            );
    }

    /**
     * The healthy case, on the same fixture shape: a full reconciliation
     * inside the 48-hour window must clear BOTH flags, not just `is_stale`.
     */
    #[Test]
    public function the_connector_screen_does_not_flag_a_recent_full_reconciliation(): void
    {
        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta']);

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'scheduled_full',
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
            'status' => 'success',
        ]);

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'health-admin2@khb.test'))
            ->get(route('bcms.settings.identity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('connector.health.is_stale', false)
                ->where('connector.health.full_reconciliation_stale', false)
            );
    }

    #[Test]
    public function the_connector_screens_runs_status_poll_ships_the_latest_run_only(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-10', 'u10@khb.test', 'Person Ten', 'u10@khb.test', '+2348000000040', null, 'Officer', 'BU-OP', null, 'E10', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'poll-admin@khb.test'))
            ->getJson(route('bcms.settings.identity.runs-status'))
            ->assertOk()
            ->assertJson(['run' => ['uuid' => $run->uuid, 'status' => $run->status->value]]);
    }

    #[Test]
    public function the_runs_status_poll_requires_identity_manage(): void
    {
        $this->connector();

        $this->actingAs($this->userWith([], 'no-poll@khb.test'))
            ->getJson(route('bcms.settings.identity.runs-status'))
            ->assertForbidden();
    }

    #[Test]
    public function a_blank_secret_on_update_leaves_the_stored_one_alone(): void
    {
        $connector = $this->connector();
        $admin = $this->userWith(['bcms.identity.manage'], 'admin2@khb.test');

        $payload = [
            'name' => $connector->name, 'directory_tenant_id' => $connector->directory_tenant_id,
            'client_id' => $connector->client_id, 'client_secret' => '',
            'token_base_url' => $connector->token_base_url, 'graph_base_url' => $connector->graph_base_url,
            'directory_filter' => null, 'attribute_map' => null,
            'sync_schedule' => 'nightly', 'auto_apply_policy' => 'none',
            'credential_expires_on' => null,
        ];

        $this->actingAs($admin)->put(route('bcms.settings.identity.update'), $payload)->assertRedirect();

        $connector->refresh();
        $this->assertSame('s', $connector->client_secret, 'A blank secret field must not wipe a working credential.');
    }

    /**
     * Gate 2 blocking defect 1. `graph_base_url` is a form field a
     * `bcms.identity.manage` holder types in — a role that may WRITE
     * `client_secret` but never READ it back (ADR 0018 §5). Without this
     * check, pointing it at a link-local/internal address is exactly the
     * SSRF `App\Support\Http\OutboundUrlGuard` exists to refuse everywhere
     * else in the product, and the field error here (not a 500 from
     * `EntraGraphClient` the first time a sync actually runs) is the
     * courtesy every other connector/webhook URL field already gets.
     */
    #[Test]
    public function a_private_address_graph_base_url_is_refused_at_save_not_at_the_first_sync(): void
    {
        $connector = $this->connector();
        $admin = $this->userWith(['bcms.identity.manage'], 'private-graph@khb.test');

        $payload = [
            'name' => $connector->name, 'directory_tenant_id' => $connector->directory_tenant_id,
            'client_id' => $connector->client_id, 'client_secret' => '',
            'token_base_url' => $connector->token_base_url,
            'graph_base_url' => 'https://169.254.169.254/v1.0',
            'directory_filter' => null, 'attribute_map' => null,
            'sync_schedule' => 'nightly', 'auto_apply_policy' => 'none',
            'credential_expires_on' => null,
        ];

        $this->actingAs($admin)
            ->from(route('bcms.settings.identity'))
            ->put(route('bcms.settings.identity.update'), $payload)
            ->assertRedirect(route('bcms.settings.identity'))
            ->assertSessionHasErrors('graph_base_url');

        $connector->refresh();
        $this->assertNotSame('https://169.254.169.254/v1.0', $connector->graph_base_url, 'The rogue host must never be saved.');
    }

    /**
     * `token_base_url` carries the connector's `client_secret` in every
     * request body it makes (the client-credentials POST). Plain http would
     * cross the network with that secret in clear — refused at save for the
     * same reason `OutboundUrlGuard` requires https everywhere else in the
     * product a URL is user-supplied.
     */
    #[Test]
    public function an_http_token_base_url_is_refused_at_save(): void
    {
        $connector = $this->connector();
        $admin = $this->userWith(['bcms.identity.manage'], 'http-token@khb.test');

        $payload = [
            'name' => $connector->name, 'directory_tenant_id' => $connector->directory_tenant_id,
            'client_id' => $connector->client_id, 'client_secret' => '',
            'token_base_url' => 'http://login.microsoftonline.com',
            'graph_base_url' => $connector->graph_base_url,
            'directory_filter' => null, 'attribute_map' => null,
            'sync_schedule' => 'nightly', 'auto_apply_policy' => 'none',
            'credential_expires_on' => null,
        ];

        $this->actingAs($admin)
            ->from(route('bcms.settings.identity'))
            ->put(route('bcms.settings.identity.update'), $payload)
            ->assertRedirect(route('bcms.settings.identity'))
            ->assertSessionHasErrors('token_base_url');

        $connector->refresh();
        $this->assertStringStartsWith('https://', (string) $connector->token_base_url, 'The plain-http URL must never be saved.');
    }

    /**
     * "Sync now" QUEUES the job, it never runs one inline — a synchronous
     * `runFull()` here outlives nginx/FPM at 5,000 users. `Queue::fake()`
     * proves the dispatch happened without a worker ever picking it up, so
     * no `IdentitySyncRun` row exists yet: the row is written by the JOB,
     * not by this request.
     */
    #[Test]
    public function only_identity_manage_may_run_a_sync(): void
    {
        Queue::fake();
        $connector = $this->connector();
        $admin = $this->userWith(['bcms.identity.manage'], 'can-sync@khb.test');

        $this->actingAs($this->userWith([], 'no-sync@khb.test'))
            ->post(route('bcms.settings.identity.sync'))
            ->assertForbidden();

        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->post(route('bcms.settings.identity.sync'))
            ->assertRedirect(route('bcms.settings.identity'))
            ->assertSessionHas('success');

        Queue::assertPushed(SyncBcmsIdentityJob::class, fn (SyncBcmsIdentityJob $job) => $job->connectorId === $connector->getKey()
            && $job->organizationId === $this->organization->id
            && $job->delta === false
            && $job->triggeredByUserId === $admin->getKey());

        $this->assertFalse(
            IdentitySyncRun::query()->where('identity_connector_id', $connector->getKey())->exists(),
            'The run row is written by the job when a worker picks it up, not by the request that queued it.',
        );
    }

    /**
     * A second click while a sync is already running must not queue a
     * second job silently — the admin is told explicitly, and sent to the
     * run that is actually in flight rather than a fresh one.
     */
    #[Test]
    public function a_sync_already_in_flight_is_named_rather_than_queued_again(): void
    {
        Queue::fake();
        $connector = $this->connector();

        $inFlight = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'scheduled_full',
            'started_at' => now()->subMinutes(2),
            'status' => 'running',
        ]);

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'second-click@khb.test'))
            ->post(route('bcms.settings.identity.sync'))
            ->assertRedirect(route('bcms.identity.runs.show', $inFlight))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /* ================================================================== */
    /*  The sync change review queue */
    /* ================================================================== */

    #[Test]
    public function the_review_queue_requires_identity_review_not_identity_manage(): void
    {
        $this->actingAs($this->userWith(['bcms.identity.manage'], 'manage-only@khb.test'))
            ->get(route('bcms.identity.runs.index'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['bcms.identity.review'], 'reviewer@khb.test'))
            ->get(route('bcms.identity.runs.index'))
            ->assertOk();
    }

    #[Test]
    public function a_run_from_another_tenant_404s(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-5', 'u5@khb.test', 'Person Five', 'u5@khb.test', '+2348000000034', null, 'Officer', 'BU-OP', null, 'E5', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);

        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ]);
        $reviewer = $this->userWith(['bcms.identity.review'], 'other-reviewer@ob.test', $other->id);

        $this->actingAs($reviewer)
            ->get(route('bcms.identity.runs.show', $run))
            ->assertNotFound();
    }

    #[Test]
    public function a_change_id_from_a_different_run_does_not_scope_into_this_one(): void
    {
        $connector = $this->connector();

        // Resolved AFTER each `instance()` swap below, deliberately — a
        // `DirectorySyncService` already built holds the `DirectoryClient`
        // that existed at the moment it was constructed, not whatever the
        // container is bound to later.
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-2', 'u2@khb.test', 'Person Two', 'u2@khb.test', '+2348000000031', null, 'Officer', 'BU-OP', null, 'E2', true, null),
        ]));
        $runA = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);
        $changeA = IdentitySyncChange::query()->where('sync_run_id', $runA->getKey())->firstOrFail();

        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-3', 'u3@khb.test', 'Person Three', 'u3@khb.test', '+2348000000032', null, 'Officer', 'BU-OP', null, 'E3', true, null),
        ]));
        $runB = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);

        $reviewer = $this->userWith(['bcms.identity.review'], 'scope-reviewer@khb.test');

        // {run} = runB, {change} = a change belonging to runA — the nested
        // route's ->scopeBindings() must refuse this rather than resolve it.
        $this->actingAs($reviewer)
            ->postJson(route('bcms.identity.changes.decide', [$runB, $changeA]), ['decision' => 'approved'])
            ->assertNotFound();
    }

    #[Test]
    public function the_single_decide_action_requires_identity_review_not_identity_manage(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-8', 'u8@khb.test', 'Person Eight', 'u8@khb.test', '+2348000000037', null, 'Officer', 'BU-OP', null, 'E8', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);
        $change = IdentitySyncChange::query()->where('sync_run_id', $run->getKey())->firstOrFail();

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'manage-only-decide@khb.test'))
            ->postJson(route('bcms.identity.changes.decide', [$run, $change]), ['decision' => 'approved'])
            ->assertForbidden();

        $change->refresh();
        $this->assertSame(SyncChangeDecision::Pending, $change->decision, 'A forbidden decide() call must not decide anything.');
    }

    #[Test]
    public function the_bulk_decide_action_requires_identity_review_not_identity_manage(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-9', 'u9@khb.test', 'Person Nine', 'u9@khb.test', '+2348000000038', null, 'Officer', 'BU-OP', null, 'E9', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);
        $change = IdentitySyncChange::query()->where('sync_run_id', $run->getKey())->firstOrFail();

        $this->actingAs($this->userWith(['bcms.identity.manage'], 'manage-only-bulk@khb.test'))
            ->post(route('bcms.identity.changes.bulk-decide', $run), [
                'decision' => 'approved',
                'change_ids' => [$change->getKey()],
            ])
            ->assertForbidden();

        $change->refresh();
        $this->assertSame(SyncChangeDecision::Pending, $change->decision, 'A forbidden bulk-decide() call must not decide anything.');
    }

    /**
     * A cross-tenant probe against bulk-decide, mirroring
     * `a_run_from_another_tenant_404s` for the single-run screen: the
     * reviewer holds `bcms.identity.review` but in a DIFFERENT
     * organisation, so `{run}`'s own tenant-scoped binding must 404 before
     * anything in the request body is even looked at — and the pending
     * change in the real tenant must be left untouched.
     */
    #[Test]
    public function a_bulk_decide_from_another_tenant_404s_and_changes_nothing(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-11', 'u11@khb.test', 'Person Eleven', 'u11@khb.test', '+2348000000041', null, 'Officer', 'BU-OP', null, 'E11', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);
        $change = IdentitySyncChange::query()->where('sync_run_id', $run->getKey())->firstOrFail();

        $other = Organization::create([
            'name' => 'Other Bank Two', 'short_name' => 'OB2', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ]);
        $reviewer = $this->userWith(['bcms.identity.review'], 'other-bulk-reviewer@ob2.test', $other->id);

        $this->actingAs($reviewer)
            ->post(route('bcms.identity.changes.bulk-decide', $run), [
                'decision' => 'approved',
                'change_ids' => [$change->getKey()],
            ])
            ->assertNotFound();

        $change->refresh();
        $this->assertSame(SyncChangeDecision::Pending, $change->decision, 'A 404 cross-tenant probe must change nothing in the real tenant.');
    }

    #[Test]
    public function the_run_show_screen_ships_the_review_queue_shape(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-4', 'u4@khb.test', 'Person Four', 'u4@khb.test', '+2348000000033', null, 'Officer', 'BU-OP', null, 'E4', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);

        $this->actingAs($this->userWith(['bcms.identity.review'], 'reviewer2@khb.test'))
            ->get(route('bcms.identity.runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Identity/Review', false)
                ->has('run.uuid')
                ->where('run.review_url', route('bcms.identity.runs.show', $run))
                ->has('connector.uuid')
                ->where('connector_settings_url', route('bcms.settings.identity'))
                ->where('bulk_decide_url', route('bcms.identity.changes.bulk-decide', $run))
                ->has('runs')
                // Gate 2 defect 2 (identity-change-review.md §6): `changes`
                // is a server-paginated list (`data`/`links`), never a
                // by-kind bucket of every row in the run.
                ->where('filters', ['kind' => 'all', 'decision' => 'pending'])
                ->has('tile_counts.joiner')
                ->has('tile_counts.leaver')
                ->has('tile_counts.mover')
                ->has('tile_counts.contact_change')
                ->has('tile_counts.needs_ack')
                ->has('pending_total')
                ->has('superseded_count')
                ->has('decided_count')
                ->has('changes.data')
                ->has('changes.links')
            );
    }

    #[Test]
    public function every_change_row_ships_its_own_decide_url_scoped_to_its_run(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-6', 'u6@khb.test', 'Person Six', 'u6@khb.test', '+2348000000035', null, 'Officer', 'BU-OP', null, 'E6', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);
        $change = IdentitySyncChange::query()->where('sync_run_id', $run->getKey())->firstOrFail();

        $this->actingAs($this->userWith(['bcms.identity.review'], 'reviewer3@khb.test'))
            ->get(route('bcms.identity.runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('changes.data.0.decide_url', route('bcms.identity.changes.decide', [$run, $change]))
                ->has('changes.data.0.decision_label')
                ->has('changes.data.0.is_actionable')
            );
    }

    /* ================================================================== */
    /*  Gate 2 defect 2 — server-side pagination of the change queue */
    /* ================================================================== */

    /**
     * Seeds a run with more pending changes than one page holds, by writing
     * `IdentitySyncChange` rows directly rather than driving 60 fake Graph
     * users through `DirectorySyncService` — the pagination boundary is a
     * property of the presenter's query, not of the sync itself.
     */
    private function seedChanges(IdentitySyncRun $run, int $count, string $kind = 'leaver', string $decision = 'pending', bool $requiresAck = false): void
    {
        for ($i = 0; $i < $count; $i++) {
            IdentitySyncChange::query()->create([
                'organization_id' => $this->organization->id,
                'sync_run_id' => $run->getKey(),
                'kind' => $kind,
                'directory_object_id' => "obj-{$kind}-{$decision}-{$i}",
                'subject_name' => "Person {$kind} {$i}",
                'before_json' => null,
                'after_json' => null,
                'impact_json' => null,
                'requires_ack' => $requiresAck,
                'decision' => $decision,
            ]);
        }
    }

    #[Test]
    public function a_run_with_more_rows_than_one_page_ships_one_page_plus_a_links_payload(): void
    {
        $connector = $this->connector();
        $run = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'manual', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'status' => 'success',
        ]);
        $this->seedChanges($run, 60);

        $response = $this->actingAs($this->userWith(['bcms.identity.review'], 'page-reviewer@khb.test'))
            ->get(route('bcms.identity.runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('changes.total', 60)
                ->where('changes.per_page', 50)
                ->has('changes.data', 50)
                ->has('changes.links')
            );
    }

    #[Test]
    public function the_kind_filter_scopes_the_paginated_changes(): void
    {
        $connector = $this->connector();
        $run = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'manual', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'status' => 'success',
        ]);
        $this->seedChanges($run, 3, kind: 'leaver');
        $this->seedChanges($run, 2, kind: 'mover');

        $this->actingAs($this->userWith(['bcms.identity.review'], 'kind-reviewer@khb.test'))
            ->get(route('bcms.identity.runs.show', [$run, 'kind' => 'mover']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.kind', 'mover')
                ->has('changes.data', 2)
                ->where('changes.data.0.kind', 'mover')
                // The tile counts are the whole run's pending totals per
                // kind, unaffected by the `kind` filter on the paginated list.
                ->where('tile_counts.leaver', 3)
                ->where('tile_counts.mover', 2)
            );
    }

    #[Test]
    public function superseded_changes_are_hidden_by_default_and_shown_with_the_decision_filter(): void
    {
        $connector = $this->connector();
        $run = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'manual', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'status' => 'success',
        ]);
        $this->seedChanges($run, 2, kind: 'leaver', decision: 'pending');
        $this->seedChanges($run, 4, kind: 'leaver', decision: 'superseded');

        $reviewer = $this->userWith(['bcms.identity.review'], 'superseded-reviewer@khb.test');

        $this->actingAs($reviewer)
            ->get(route('bcms.identity.runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.decision', 'pending')
                ->has('changes.data', 2)
                ->where('superseded_count', 4)
            );

        $this->actingAs($reviewer)
            ->get(route('bcms.identity.runs.show', [$run, 'decision' => 'superseded']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.decision', 'superseded')
                ->has('changes.data', 4)
                ->where('changes.data.0.decision', 'superseded')
            );
    }

    #[Test]
    public function tile_counts_are_unaffected_by_pagination_or_the_kind_and_decision_filters(): void
    {
        $connector = $this->connector();
        $run = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => 'manual', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'status' => 'success',
        ]);
        $this->seedChanges($run, 60, kind: 'leaver', decision: 'pending');
        $this->seedChanges($run, 5, kind: 'joiner', decision: 'pending', requiresAck: true);

        $this->actingAs($this->userWith(['bcms.identity.review'], 'tiles-reviewer@khb.test'))
            ->get(route('bcms.identity.runs.show', [$run, 'kind' => 'leaver', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('changes.data', 10) // page 2 of 60 at 50/page
                ->where('tile_counts.leaver', 60)
                ->where('tile_counts.joiner', 5)
                ->where('tile_counts.needs_ack', 5)
                ->where('pending_total', 65)
            );
    }

    #[Test]
    public function the_review_index_redirects_to_the_latest_run_when_one_exists(): void
    {
        $connector = $this->connector();
        app()->instance(DirectoryClient::class, new FakeDirectoryClient([
            new DirectoryUser('U-7', 'u7@khb.test', 'Person Seven', 'u7@khb.test', '+2348000000036', null, 'Officer', 'BU-OP', null, 'E7', true, null),
        ]));
        $run = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::Manual);

        $this->actingAs($this->userWith(['bcms.identity.review'], 'reviewer4@khb.test'))
            ->get(route('bcms.identity.runs.index'))
            ->assertRedirect(route('bcms.identity.runs.show', $run));
    }

    #[Test]
    public function the_review_index_renders_the_never_synced_state_with_no_runs(): void
    {
        $this->actingAs($this->userWith(['bcms.identity.review'], 'reviewer5@khb.test'))
            ->get(route('bcms.identity.runs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Identity/Review', false)
                ->missing('run')
                ->where('runs', [])
            );
    }
}
