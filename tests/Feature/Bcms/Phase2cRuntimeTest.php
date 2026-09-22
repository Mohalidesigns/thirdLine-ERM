<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DirectoryClient;
use App\Enums\Bcms\SyncChangeKind;
use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Jobs\Bcms\SyncBcmsIdentityJob;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Identity\ChangeApplier;
use App\Services\Bcms\Identity\ConnectorHealth;
use App\Services\Bcms\Identity\DirectorySyncService;
use App\Services\Bcms\Identity\EntraGraphClient;
use App\Services\Bcms\Identity\FakeDirectoryClient;
use App\Support\Bcms\DirectoryUser;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\IdentityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 2C — the RUNTIME half: queue topology, idempotency, the overlap
 * lock, the failure paths and the two watchdog checks.
 *
 * `Phase2cIdentitySyncTest` proves the nine acceptance criteria of ADR 0018
 * §6 — what a correct sync produces. This file proves what happens when the
 * sync is run twice, run while it is already running, killed halfway, or
 * never run at all. Those are the failures a customer meets at 2 a.m. and
 * none of them raise an error on their own.
 *
 * `Http::preventStrayRequests()` IS IN FORCE HERE TOO. Everything but the two
 * `EntraGraphClient` retry tests runs against `FakeDirectoryClient`, and
 * those two run against `Http::fake()` with `Sleep::fake()`, so the retry
 * ladder is proved without the suite waiting for it.
 */
class Phase2cRuntimeTest extends TestCase
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

        foreach (IdentityDemoSeeder::DEPARTMENTS as [$code, $name]) {
            BusinessUnit::query()->create([
                'organization_id' => $this->organization->id, 'code' => $code, 'name' => $name, 'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Helpers */
    /* ================================================================== */

    private function connector(array $overrides = []): IdentityConnector
    {
        return IdentityConnector::query()->create(array_merge([
            'organization_id' => $this->organization->id,
            'provider' => 'entra',
            'name' => 'Kano Heritage Bank — Entra ID',
            'directory_tenant_id' => 'test-tenant',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'token_base_url' => 'https://login.microsoftonline.com',
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_schedule' => 'nightly',
            'auto_apply_policy' => 'safe_only',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Six people, one department, one manager edge — small on purpose. The
     * 200-user fixture belongs to the acceptance test; what this file needs is
     * a directory it can sync three times inside a second.
     *
     * @return list<DirectoryUser>
     */
    private function smallDirectory(): array
    {
        $department = IdentityDemoSeeder::DEPARTMENTS[0][1];

        $head = new DirectoryUser('OBJ-HEAD', 'head@khb.test', 'Amina Yusuf', 'head@khb.test', '+2348030000000', null, 'Head of Operations', $department, 'Kano HQ', 'EMP-000', true, null);

        $users = [$head];

        for ($i = 1; $i <= 5; $i++) {
            $users[] = new DirectoryUser(
                "OBJ-{$i}", "staff{$i}@khb.test", "Staff {$i}", "staff{$i}@khb.test",
                '+23480300000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), null,
                'Officer', $department, 'Kano HQ', 'EMP-00'.$i, true, 'OBJ-HEAD',
            );
        }

        return $users;
    }

    private function swapDirectoryClient(FakeDirectoryClient $fake): FakeDirectoryClient
    {
        app()->instance(DirectoryClient::class, $fake);

        return $fake;
    }

    private function identityAdmin(string $email = 'identity-admin@khb.test'): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Identity Admin',
            'email' => $email, 'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $role = Role::findOrCreate('identity-admin-'.$this->organization->id, 'web');
        $role->givePermissionTo(Permission::findOrCreate('bcms.identity.manage', 'web'));
        $user->assignRole($role);

        return $user->refresh();
    }

    /* ================================================================== */
    /*  Queue topology */
    /* ================================================================== */

    /**
     * MUTATION: delete the `onQueue()` call in `SyncBcmsIdentityJob`'s
     * constructor and this fails — a 3,600-second directory read lands on
     * `default`, where it shares a supervisor with interactive work.
     */
    #[Test]
    public function the_sync_job_is_queued_on_bcms_sync_and_nowhere_else(): void
    {
        Queue::fake();
        $this->connector();

        $this->artisan('bcms:sync-directory')->assertSuccessful();

        Queue::assertPushed(SyncBcmsIdentityJob::class, function (SyncBcmsIdentityJob $job): bool {
            return $job->queue === config('bcms.queues.sync') && $job->queue === 'bcms-sync';
        });

        // The job carries the queue itself, so a hand-retry from Horizon or a
        // future caller that forgets `->onQueue()` cannot move it.
        $fresh = new SyncBcmsIdentityJob(1, $this->organization->id, false);
        $this->assertSame('bcms-sync', $fresh->queue);
        $this->assertSame(1, $fresh->tries, 'A half-run sync retried from the top writes the same contacts twice.');
        $this->assertSame(SyncBcmsIdentityJob::TIMEOUT_SECONDS, $fresh->timeout);
    }

    /**
     * The dispatch-time half of the overlap guard. Two sweeps (two app
     * servers, or a cron that fired twice) queue ONE job for a connector.
     *
     * MUTATION: drop `implements ShouldBeUnique` and the second dispatch is
     * pushed as well, giving one connector two concurrent directory reads.
     */
    #[Test]
    public function a_second_sweep_cannot_queue_a_second_job_for_the_same_connector(): void
    {
        Queue::fake();
        $this->connector();

        $this->artisan('bcms:sync-directory')->assertSuccessful();
        $this->artisan('bcms:sync-directory')->assertSuccessful();

        Queue::assertPushed(SyncBcmsIdentityJob::class, 1);
    }

    /* ================================================================== */
    /*  The demo seeder's connector is inactive */
    /* ================================================================== */

    /**
     * `IdentityDemoSeeder` points its connector's `token_base_url` and
     * `graph_base_url` at the REAL Microsoft hosts with a `demo-client`/
     * `demo-secret` that were never registered there (gate 2 rejection #3,
     * blocking defect 3). Seeded active on `nightly_plus_delta`, this
     * connector was queued by `bcms:sync-directory --delta` every fifteen
     * minutes on any estate the seeder ran on, each queue POSTing junk
     * credentials to `login.microsoftonline.com` and waking `BcmsWatchdog`
     * on every failure. ADR 0018 §2.2 point 5: a connector is created
     * disabled until a human runs "Test connection" — the seeder's own two
     * `runFull()` calls go straight through `DirectorySyncService`, which
     * never reads `is_active`, so the seeded run history (and the 200
     * contacts) are exactly as populated as before.
     */
    #[Test]
    public function the_demo_seeders_connector_is_inactive_and_the_scheduled_delta_skips_it(): void
    {
        Http::preventStrayRequests();

        (new IdentityDemoSeeder)->run($this->organization);

        $connector = IdentityConnector::query()->where('organization_id', $this->organization->id)->firstOrFail();

        $this->assertFalse((bool) $connector->is_active, 'A seeded connector must not be able to reach a real Microsoft host on its own.');
        $this->assertSame('nightly_plus_delta', $connector->sync_schedule, 'The schedule stays real; only activation is withheld.');

        // Run history the screens read from is still there, seeded once as
        // ordinary `DirectorySyncService::runFull()` calls, not through the
        // command loop that reads `is_active`.
        $this->assertSame(200, Contact::query()->where('organization_id', $this->organization->id)->count());
        $this->assertGreaterThanOrEqual(2, IdentitySyncRun::query()->where('organization_id', $this->organization->id)->count());

        Queue::fake();

        $this->artisan('bcms:sync-directory', ['--delta' => true])->assertSuccessful();

        // An inactive connector must never be queued by the scheduled sweep
        // — the exact leak this fix closes.
        Queue::assertNotPushed(SyncBcmsIdentityJob::class);
    }

    /* ================================================================== */
    /*  Idempotency */
    /* ================================================================== */

    /**
     * THE IDEMPOTENCY PROOF, run three times exactly as the standing
     * requirement asks: three dispatcher runs against an unchanged directory
     * produce one roster, not three, and stage one set of joiners.
     */
    #[Test]
    public function three_dispatcher_runs_over_an_unchanged_directory_produce_one_roster(): void
    {
        $this->connector();
        $this->swapDirectoryClient(new FakeDirectoryClient($this->smallDirectory()));

        $this->artisan('bcms:sync-directory')->assertSuccessful();
        $this->artisan('bcms:sync-directory')->assertSuccessful();
        $this->artisan('bcms:sync-directory')->assertSuccessful();

        TenantContext::set($this->organization->id);

        $runs = IdentitySyncRun::query()->orderBy('started_at')->get();
        $this->assertCount(3, $runs, 'Three sweeps, three run rows — each read is its own audited event.');

        foreach ($runs as $run) {
            $this->assertNotNull($run->finished_at, 'Every run must be closed out; a row left on `running` is the silence the watchdog exists to catch.');
            $this->assertSame(SyncRunStatus::Success, $run->status);
        }

        $this->assertSame(6, Contact::query()->count(), 'Three identical syncs created the roster three times over.');

        $joiners = IdentitySyncChange::query()->where('kind', SyncChangeKind::Joiner->value)->count();
        $this->assertSame(6, $joiners, 'A joiner was staged again by a later run — the same person would be applied twice.');

        $this->assertSame(0, (int) $runs[1]->joiner_count);
        $this->assertSame(0, (int) $runs[2]->joiner_count);
    }

    /* ================================================================== */
    /*  The overlap lock */
    /* ================================================================== */

    /**
     * The nightly full and the quarter-hourly delta share one lock, and the
     * lock lives in the service so the in-request "Sync now" path is covered
     * by the same guard.
     *
     * MUTATION: remove `exclusively()` from `runFull()` and a second run row
     * appears, with both runs superseding each other's pending changes and
     * `ChangeApplier` applying the same joiner from two runs.
     */
    #[Test]
    public function a_run_cannot_start_while_another_holds_the_connector_lock(): void
    {
        $connector = $this->connector();
        $this->swapDirectoryClient(new FakeDirectoryClient($this->smallDirectory()));

        $inFlight = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subMinutes(2),
            'status' => SyncRunStatus::Running->value,
        ]);

        $lock = Cache::lock('bcms:identity:sync:'.$this->organization->id.':'.$connector->getKey(), 3600);
        $this->assertTrue($lock->get());

        try {
            $returned = app(DirectorySyncService::class)->runFull($connector, SyncTrigger::ScheduledDelta);
        } finally {
            $lock->release();
        }

        $this->assertTrue($inFlight->is($returned), 'A contended run must hand back the run already in flight, not start a second one.');
        $this->assertSame(1, IdentitySyncRun::query()->count());
        $this->assertSame(0, Contact::query()->count(), 'The contended caller read the directory anyway.');
    }

    /**
     * The delta sweep does not even queue behind a run in flight — the
     * nightly full is what maintains the manager hierarchy (ADR 0018 §3.1)
     * and must be the one that wins.
     */
    #[Test]
    public function the_delta_sweep_skips_a_connector_whose_run_is_still_in_flight(): void
    {
        Queue::fake();

        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta']);

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subMinutes(3),
            'status' => SyncRunStatus::Running->value,
        ]);

        $this->artisan('bcms:sync-directory', ['--delta' => true])
            ->expectsOutputToContain('skipped 1 with a run already in flight')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * A tenant that chose `manual` has said a human decides when their
     * directory is read. Sweeping them anyway also defeats
     * `ConnectorHealth::isStale()`, which returns false for `manual` on
     * purpose — so the failures the sweep caused would never be reported.
     */
    #[Test]
    public function a_manual_connector_is_never_swept_by_either_arm(): void
    {
        Queue::fake();
        $this->connector(['sync_schedule' => 'manual']);

        $this->artisan('bcms:sync-directory')->assertSuccessful();
        $this->artisan('bcms:sync-directory', ['--delta' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_inactive_connector_is_never_swept_and_a_deactivated_one_stops_mid_flight(): void
    {
        Queue::fake();
        $this->connector(['is_active' => false]);

        $this->artisan('bcms:sync-directory')->assertSuccessful();

        Queue::assertNothingPushed();

        // And the job itself re-checks: a connector switched off between the
        // sweep and the worker picking the job up must not be read.
        Queue::swap(app('queue'));
        TenantContext::set($this->organization->id);
        $connector = IdentityConnector::query()->firstOrFail();
        $this->swapDirectoryClient(new FakeDirectoryClient($this->smallDirectory()));

        (new SyncBcmsIdentityJob((int) $connector->getKey(), $this->organization->id, false))
            ->handle(app(DirectorySyncService::class));

        $this->assertSame(0, IdentitySyncRun::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    /* ================================================================== */
    /*  Failure paths */
    /* ================================================================== */

    /**
     * The worker died. `failed()` closes out the write-ahead row so the
     * connector screen and `ConnectorHealth` stop reporting a sync that is
     * still in progress.
     *
     * MUTATION: delete `failed()` and the run stays `running` for ever —
     * neither a success nor a failure, and invisible to `isStale()`.
     */
    #[Test]
    public function a_failed_job_closes_out_the_run_it_wrote_ahead(): void
    {
        $connector = $this->connector();

        $run = IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subMinutes(10),
            'status' => SyncRunStatus::Running->value,
        ]);

        (new SyncBcmsIdentityJob((int) $connector->getKey(), $this->organization->id, false))
            ->failed(new RuntimeException('Graph said: user MUSA.BELLO+2348030000000 is invalid'));

        $run->refresh();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('job_failed_RuntimeException', $run->error_class);
        $this->assertStringNotContainsString('2348030000000', (string) $run->error_class, 'A provider message reached a seven-year regulator-facing column.');
    }

    /**
     * A failure that is NOT a directory read — a deadlock, an enum cast, a
     * narrowed column — still closes the run out, with a bounded class and no
     * message, and still reaches the engineers by rethrowing.
     */
    #[Test]
    public function an_exception_while_staging_marks_the_run_and_rethrows(): void
    {
        $connector = $this->connector();
        $this->swapDirectoryClient(new FakeDirectoryClient($this->smallDirectory()));

        // A hand-written stub rather than a mock: the point is a writer that
        // blows up the way a deadlock or a narrowed column does, and the
        // message it carries has to be a realistic one so the assertion that
        // NONE of it reaches `error_class` means something.
        app()->instance(ChangeApplier::class, new class extends ChangeApplier
        {
            public function __construct() {}

            public function apply(IdentitySyncChange $change): void
            {
                throw new RuntimeException('SQLSTATE[HY000]: mobile_primary +2348030000000');
            }

            public function finalizeManagerEdge(int $contactId, ?string $managerObjectId): void {}
        });

        try {
            app(DirectorySyncService::class)->runFull($connector, SyncTrigger::ScheduledFull);
            $this->fail('The staging failure was swallowed; a scheduled job would report success on a night it staged nothing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SQLSTATE', $e->getMessage());
        }

        $run = IdentitySyncRun::query()->firstOrFail();

        $this->assertContains($run->status, [SyncRunStatus::Failed, SyncRunStatus::Partial]);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('staging_RuntimeException', $run->error_class);
        $this->assertStringNotContainsString('2348030000000', (string) $run->error_class);

        // And the lock is released, not held for its full hour: the next run
        // starts normally rather than being told one is already in flight.
        $this->assertTrue(
            Cache::lock('bcms:identity:sync:'.$this->organization->id.':'.$connector->getKey(), 10)->get(),
            'The connector lock survived the failure — one bug would park the connector for an hour.',
        );
    }

    /* ================================================================== */
    /*  The watchdog */
    /* ================================================================== */

    /**
     * `kill -9` on the worker: no exception, no `failed()`, no queue timeout
     * — just a run row on `running` for ever. `ConnectorHealth::isStale()`
     * cannot see it, because it reads the last SUCCESSFUL run and this one
     * never claimed to be one.
     */
    #[Test]
    public function the_watchdog_alerts_on_a_run_stuck_on_running_past_its_timeout(): void
    {
        $connector = $this->connector();
        $admin = $this->identityAdmin();

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subSeconds(SyncBcmsIdentityJob::TIMEOUT_SECONDS + 3600),
            'status' => SyncRunStatus::Running->value,
        ]);

        // A successful run an hour ago, so neither `isStale()` nor the
        // full-reconciliation check fires — the stuck row is the ONLY reason
        // this alert can be raised.
        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'status' => SyncRunStatus::Success->value,
        ]);

        $this->assertFalse(app(ConnectorHealth::class)->isStale($connector->refresh()));

        $this->artisan('bcms:watchdog')->assertFailed();

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'type' => 'bcms.watchdog.identity_sync',
        ]);
    }

    /**
     * The delta keeps succeeding, the nightly full has not run for a week,
     * and every freshness indicator is green — ADR 0018 §3.1 says a delta
     * never re-resolves a manager edge, so the call tree has quietly stopped
     * being maintained.
     *
     * MUTATION: delete `hierarchyStale()` and this passes silently, which is
     * exactly how the customer finds out during an exercise.
     */
    #[Test]
    public function the_watchdog_alerts_when_only_the_delta_is_still_succeeding(): void
    {
        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta']);
        $admin = $this->identityAdmin();

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subDays(6),
            'finished_at' => now()->subDays(6),
            'status' => SyncRunStatus::Success->value,
        ]);

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledDelta->value,
            'started_at' => now()->subMinutes(12),
            'finished_at' => now()->subMinutes(11),
            'status' => SyncRunStatus::Success->value,
        ]);

        // The existing health check is satisfied — a delta succeeded twelve
        // minutes ago — which is the whole point of the second check.
        $this->assertFalse(app(ConnectorHealth::class)->isStale($connector->refresh()));

        $this->artisan('bcms:watchdog')->assertFailed();

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'type' => 'bcms.watchdog.identity_sync',
        ]);
    }

    #[Test]
    public function a_healthy_connector_wakes_nobody(): void
    {
        $connector = $this->connector();
        $admin = $this->identityAdmin();

        IdentitySyncRun::query()->create([
            'organization_id' => $this->organization->id,
            'identity_connector_id' => $connector->getKey(),
            'trigger' => SyncTrigger::ScheduledFull->value,
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
            'status' => SyncRunStatus::Success->value,
        ]);

        $this->artisan('bcms:watchdog')->assertSuccessful();

        $this->assertDatabaseMissing('notifications_log', [
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'type' => 'bcms.watchdog.identity_sync',
        ]);
    }

    /* ================================================================== */
    /*  The Graph client's own reliability */
    /* ================================================================== */

    /**
     * Graph throttles a paged read as a matter of course. Treating the first
     * 429 as a run failure is how a nightly sync becomes "partial" every
     * night; retrying for ever is how a worker disappears.
     */
    #[Test]
    public function the_graph_client_honours_retry_after_and_stops_at_the_cap(): void
    {
        Sleep::fake();
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['code' => 'TooManyRequests', 'message' => 'tenant ABC throttled']], 429, ['Retry-After' => '5']),
        ]);

        try {
            foreach (app(EntraGraphClient::class)->users($connector) as $ignored) {
                break;
            }
            $this->fail('A permanently throttled directory read must still end as a bounded failure.');
        } catch (DirectorySyncException $e) {
            $this->assertSame('graph_rate_limited', $e->errorClass);
            $this->assertSame('5', $e->errorCode);
        }

        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);

        // One token request plus three attempts at the page: the cap held.
        Http::assertSentCount(4);
    }

    /**
     * The token is cached for `expires_in` minus five minutes, which is not a
     * guarantee — a secret can be rotated while a paged read is in flight.
     * One forced re-authentication separates "the token aged out under us"
     * from "this credential is dead".
     */
    #[Test]
    public function a_401_part_way_through_a_read_re_authenticates_exactly_once(): void
    {
        Sleep::fake();
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'token expired for ABC']], 401)
                ->push(['value' => [['id' => 'OBJ-1', 'displayName' => 'Amina Yusuf', 'accountEnabled' => true, 'manager' => null]]], 200),
        ]);

        $read = [];

        foreach (app(EntraGraphClient::class)->users($connector) as $user) {
            $read[] = $user;
        }

        $this->assertCount(1, $read, 'A 401 that a fresh token would have fixed failed the run instead.');

        // token, 401, token again (the cached one was forgotten), then the page.
        Http::assertSentCount(4);
    }

    /**
     * `bcms_identity_sync_runs.error_code` is a `varchar(40)`
     * (`DirectorySyncService::abort()` caps `error_class` the same way, for
     * the same reason). Graph's own `error.code` vocabulary is normally well
     * inside that, but nothing about a provider response is closed enough to
     * trust with a raw column write — a run must end `partial`/`failed`
     * rather than throwing a `QueryException` out of a failure path.
     */
    #[Test]
    public function a_sixty_character_graph_error_code_is_capped_to_the_column_width(): void
    {
        $connector = $this->connector();
        $longCode = str_repeat('X', 60);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['code' => $longCode, 'message' => 'tenant ABC misconfigured']], 400),
        ]);

        try {
            foreach (app(EntraGraphClient::class)->users($connector) as $ignored) {
                break;
            }
            $this->fail('A 400 from Graph must still end as a bounded failure.');
        } catch (DirectorySyncException $e) {
            $this->assertSame(str_repeat('X', 40), $e->errorCode);
            $this->assertSame(40, strlen((string) $e->errorCode));
        }
    }

    /**
     * Gate 2 advisory 2: the 429 branch carries `Retry-After` straight into
     * `error_code` — a bounded integer per Graph's own contract, but that is
     * a promise about the PROVIDER's behaviour, not a guarantee this class
     * can rely on for a raw `varchar(40)` column write. A malformed or
     * malicious 60-character `Retry-After` must be capped exactly like
     * `classifiedFailure()`'s Graph `error.code` is.
     */
    #[Test]
    public function a_sixty_character_retry_after_header_is_capped_to_the_column_width(): void
    {
        Sleep::fake();
        $connector = $this->connector();
        $longRetryAfter = str_repeat('9', 60);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/*' => Http::response(
                ['error' => ['code' => 'TooManyRequests', 'message' => 'tenant ABC throttled']],
                429,
                ['Retry-After' => $longRetryAfter],
            ),
        ]);

        try {
            foreach (app(EntraGraphClient::class)->users($connector) as $ignored) {
                break;
            }
            $this->fail('A permanently throttled directory read must still end as a bounded failure.');
        } catch (DirectorySyncException $e) {
            $this->assertSame('graph_rate_limited', $e->errorClass);
            $this->assertSame(str_repeat('9', 40), $e->errorCode);
            $this->assertSame(40, strlen((string) $e->errorCode));
        }
    }

    /**
     * Gate 2 rejection #3, advisory 4. The config comment above
     * `bcms.identity.allowed_hosts` claimed a redirect "fails the run" —
     * untrue against Guzzle's own defaults, which follow up to five
     * redirects, and a 307/308 preserves the method AND body. The token
     * request carries `client_secret` in its body, so a redirect followed
     * here would have POSTed the secret to wherever the `Location` header
     * pointed, before `DirectoryHostGuard` ever got a look at that second
     * host — the guard only runs on the URL this class chose to fetch, not
     * on one Guzzle followed on its own.
     *
     * `Http::preventStrayRequests()` (this file's `setUp()`) is what proves
     * the negative here: if `EntraGraphClient` ever followed the redirect,
     * the second request to the off-list host has no matching fake and the
     * call would blow up with a `StrayRequestException` instead of ending as
     * this test's bounded, classified failure — established by temporarily
     * removing the `->withoutRedirecting()` call this test guards and
     * confirming exactly that exception is what today's code produces.
     */
    #[Test]
    public function a_307_from_the_token_endpoint_is_never_followed_to_another_host(): void
    {
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(
                '',
                307,
                ['Location' => 'https://evil.example.com/steal-secret'],
            ),
        ]);

        $run = app(DirectorySyncService::class)->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame('token_http_307', $run->error_class);
        $this->assertNotNull($run->error_code);

        // No request of any kind ever reached the redirect target — not the
        // GET a followed redirect would replay the body-less token request
        // as, nor anything else.
        foreach (Http::recorded() as $pair) {
            $this->assertStringNotContainsString(
                'evil.example.com',
                $pair[0]->url(),
                'The redirect target must never be reached — it would have carried the client secret.',
            );
        }

        // Only the original token request may be sent; the redirect must
        // never be followed.
        Http::assertSentCount(1);
    }

    /* ================================================================== */
    /*  Gate 2 blocking defect 1 — the Microsoft host allowlist */
    /* ================================================================== */

    /**
     * `graph_base_url`/`token_base_url` are form fields a `bcms.identity.
     * manage` holder types in — a role that may WRITE `client_secret` but
     * never READ it back (ADR 0018 §5). A `delta_link` is provider-supplied
     * at first, but it is STORED and REPLAYED by this application on every
     * subsequent delta run — so a stored link that has drifted (or been
     * tampered with) off the Microsoft host allowlist must fail the run
     * before a single request leaves the box, not merely be logged
     * afterwards. `Http::preventStrayRequests()` (this file's `setUp()`)
     * would itself fail the test loudly if `EntraGraphClient` ever reached
     * for the rogue host without a matching fake — `assertNothingSent()`
     * additionally proves it did not even attempt the token request first.
     */
    #[Test]
    public function a_delta_link_that_has_drifted_off_the_allowed_host_fails_the_run_and_reaches_it_never(): void
    {
        $connector = $this->connector(['delta_link' => 'https://evil.example.com/v1.0/users/delta?$deltatoken=abc']);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(), 'expires_in' => 3600], 200),
            'https://evil.example.com/*' => Http::response(['value' => [['id' => 'SHOULD-NEVER-BE-READ']]], 200),
        ]);

        $run = app(DirectorySyncService::class)->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame('graph_host_not_allowed', $run->error_class);
        $this->assertSame('evil.example.com', $run->error_code);
        $this->assertSame(0, $run->joiner_count);

        Http::assertNothingSent();
    }

    private function fakeJwt(): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['roles' => ['User.Read.All']])), '+/', '-_'), '=');

        return 'header.'.$payload.'.signature';
    }
}
