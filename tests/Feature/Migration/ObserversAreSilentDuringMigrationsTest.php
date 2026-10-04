<?php

namespace Tests\Feature\Migration;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Models\WorkflowInstance;
use App\Support\MigrationWindow;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * A data migration that saves a model must not publish a webhook for it, start
 * a workflow over it, or even look for anybody who might want either.
 *
 * THE DEPLOY THIS IS ABOUT. The first production upgrade (MySQL 8.0.46, a
 * database seeded at 4c51f90 with one organisation) stopped at migration 25 of
 * 124: `2026_08_12_120003_seed_units_and_period_calendars` creates periods for
 * every organisation that exists, `Period` is API-published, so
 * `WebhookEventObserver` asked `webhook_subscriptions` whether anyone was
 * listening — and that table is created by `2026_08_15_120004`, twenty-one
 * migrations later. With that silenced the next one fell the same way:
 * `2026_08_12_120004_migrate_kris_into_the_measure_engine` creates
 * `MeasureThreshold`s, a workflow subject, and `WorkflowTriggerObserver` read
 * `workflow_definitions.trigger`, which `2026_08_14_120001` adds. CI never saw
 * either, because a fresh database has no organisations for a backfill to
 * iterate.
 *
 * These tests run a REAL migration through the migrator — not a hand-flipped
 * flag — so they prove Laravel's `MigrationsStarted` / `MigrationsEnded` reach
 * `MigrationWindow`, and that the window closes again afterwards. A subscriber
 * and a published on_create definition are both present, so "nothing happened"
 * means suppressed, not "nobody was listening".
 */
class ObserversAreSilentDuringMigrationsTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private const BODY = 'tests.migration-window.body';

    private string $migrationDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        Cache::flush();

        config(['webhooks.allowed_hosts' => ['receiver.example.test']]);
        Http::fake(['*' => Http::response('ok', 200)]);

        // A migration file of our own, so the migrator runs it exactly as it
        // runs a shipped one. Its body is whatever the test binds.
        $this->migrationDir = storage_path('framework/testing/migration-window-'.uniqid());
        File::ensureDirectoryExists($this->migrationDir);
        File::put($this->migrationDir.'/2099_01_01_000000_save_models_inside_a_migration.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;

            return new class extends Migration
            {
                public function up(): void
                {
                    app('tests.migration-window.body')();
                }

                public function down(): void {}
            };
            PHP);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->migrationDir);

        parent::tearDown();
    }

    #[Test]
    public function a_model_saved_inside_a_migration_neither_queries_nor_dispatches_webhooks(): void
    {
        $this->subscribe(['risk.*']);
        Bus::fake([DeliverWebhookJob::class]);

        $subscriptionQueries = [];
        $windowWasOpen = null;

        $this->runMigrationBody(function () use (&$subscriptionQueries, &$windowWasOpen) {
            $windowWasOpen = app(MigrationWindow::class)->isOpen();

            DB::listen(function ($query) use (&$subscriptionQueries) {
                if (str_contains($query->sql, 'webhook_subscriptions')) {
                    $subscriptionQueries[] = $query->sql;
                }
            });

            $risk = $this->makeRisk(['title' => 'Written by a backfill']);
            $risk->update(['status' => 'closed']);
            $risk->delete();
        });

        $this->assertTrue($windowWasOpen, 'MigrationsStarted must open the window before the first migration runs.');
        $this->assertSame([], $subscriptionQueries,
            'Inside a migration the observer must not even ask whether anyone is subscribed: on an upgrading '
            .'database webhook_subscriptions may not exist yet, which is how the first production upgrade stopped.');
        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count(),
            'A backfill is not a business event; an integrator must not receive a delivery for it.');
        Bus::assertNotDispatched(DeliverWebhookJob::class);
        $this->assertFalse(Cache::has('webhooks:any:'.$this->organization->id),
            'Nothing should be cached on the migration\'s behalf either.');
    }

    #[Test]
    public function publishing_resumes_once_the_migrator_has_finished(): void
    {
        $this->subscribe(['risk.created']);

        $this->runMigrationBody(fn () => $this->makeRisk(['title' => 'Backfilled']));

        $this->assertFalse(app(MigrationWindow::class)->isOpen(), 'MigrationsEnded must close the window.');
        $this->assertSame(0, WebhookDelivery::withoutGlobalScopes()->count());

        $this->makeRisk(['title' => 'Created by a person, after the deploy']);

        $this->assertSame(1, WebhookDelivery::withoutGlobalScopes()->where('event', 'risk.created')->count(),
            'Once the migrator is done, a save must publish again — the window must not stay open.');
    }

    #[Test]
    public function a_model_created_inside_a_migration_starts_no_triggered_workflow(): void
    {
        $this->parallelDefinition([
            'code' => 'auto_on_create',
            'entity_type' => 'loss_event',
            'trigger' => 'on_create',
        ]);

        $workflowQueries = [];

        $backfilled = null;
        $this->runMigrationBody(function () use (&$backfilled, &$workflowQueries) {
            DB::listen(function ($query) use (&$workflowQueries) {
                if (str_contains($query->sql, 'workflow_definitions')) {
                    $workflowQueries[] = $query->sql;
                }
            });

            $backfilled = $this->makeLossEvent(['current_status' => 'NEW']);
            $backfilled->update(['current_status' => 'ESCALATED']);
        });

        $this->assertSame([], $workflowQueries,
            'Inside a migration the trigger observer must not read workflow_definitions: on an upgrading '
            .'database its trigger column may not exist yet.');
        $this->assertNull(WorkflowInstance::withoutGlobalScopes()->forEntity($backfilled)->first(),
            'A record a migration writes must not open a review, assign tasks and notify approvers.');

        $created = $this->makeLossEvent();

        $this->assertNotNull(WorkflowInstance::withoutGlobalScopes()->forEntity($created)->first(),
            'Outside a migration the same definition must still start — the guard must not disable the feature.');
    }

    #[Test]
    public function the_window_follows_the_migrators_own_events(): void
    {
        $window = app(MigrationWindow::class);

        $this->assertFalse($window->isOpen(), 'Nothing outside a migrate run may see the window open.');

        event(new MigrationsStarted('up'));
        $this->assertTrue($window->isOpen());
        $this->assertSame($window, app(MigrationWindow::class),
            'One instance per process: the listener and the observers must see the same flag.');

        event(new MigrationsEnded('up'));
        $this->assertFalse($window->isOpen());
    }

    /* ------------------------------------------------------------------ */

    private function runMigrationBody(callable $body): void
    {
        $this->app->instance(self::BODY, $body);

        $this->artisan('migrate', [
            '--path' => $this->migrationDir,
            '--realpath' => true,
            '--force' => true,
        ])->assertSuccessful();
    }

    /** @param list<string> $events */
    private function subscribe(array $events): WebhookSubscription
    {
        Cache::forget('webhooks:any:'.$this->organization->id);

        return WebhookSubscription::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test receiver',
            'url' => 'https://receiver.example.test/hook',
            'events' => $events,
            'is_active' => true,
        ]);
    }
}
