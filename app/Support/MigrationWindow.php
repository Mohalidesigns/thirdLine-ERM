<?php

namespace App\Support;

/**
 * Whether this process is inside a `php artisan migrate` run right now.
 *
 * Opened by Laravel's own `MigrationsStarted` event and closed by
 * `MigrationsEnded` (both wired in `AppServiceProvider::boot()`), so it is true
 * for exactly the span in which the migrator is applying migrations — including
 * the data migrations that save Eloquent models — and false everywhere else:
 * web requests, queue workers, the scheduler, seeders run on their own, and a
 * test that calls one migration's `up()` directly.
 *
 * WHY IT EXISTS. A data migration that saves a model fires that model's
 * observers, and an observer written for live traffic assumes the schema of the
 * release it shipped in. `2026_08_12_120003_seed_units_and_period_calendars`
 * saves calendars for every organisation that exists; on a deployed database
 * (one with organisations — CI's fresh one has none) that fired
 * `WebhookEventObserver`, which queried `webhook_subscriptions` twenty-one
 * migrations before `2026_08_15_120004_create_webhook_tables` creates it, and
 * the production upgrade stopped at migration 25 of 124. Even with the table
 * present it would be wrong: a backfill is not a business event, and an
 * integrator must not receive a `.created` for every record a schema change
 * touched.
 *
 * WHAT USES IT. The two observers that turn a save into a business event:
 * `WebhookEventObserver` (a delivery to an integrator) and
 * `WorkflowTriggerObserver` (an on_create / on_transition workflow, with its
 * tasks and notifications). The second failed the same upgrade the same way
 * once the first was silenced — `workflow_definitions.trigger` does not exist
 * until `2026_08_14_120001`. The audit trail deliberately does not consult it: a
 * row a migration writes into a governed table is still a change to that
 * table, and a gap in the trail is worse than an entry attributed to the
 * system.
 *
 * THE RULE FOR THE NEXT ONE (DEVELOPMENT_STANDARD §16): any observer that turns
 * a save into an outward event returns early here as its first statement.
 * `OutwardObserversHonourMigrationWindowTest` fails the build when one does not.
 *
 * A COUNTER, NOT A FLAG. The migrator can run inside the migrator: a
 * migration may call `Artisan::call('migrate', ['--path' => ...])`, and a
 * package or a test can do the same from inside an outer run. Each inner run
 * fires its own `MigrationsStarted` / `MigrationsEnded` pair. With a boolean
 * the inner `MigrationsEnded` closed the window while the OUTER run was still
 * applying migrations, so every data migration after the nested call fired
 * observers again — the exact failure this class exists to prevent, one level
 * down. So `open()` increments, `close()` decrements, and the window is open
 * while the depth is above zero. `close()` never goes below zero: an unpaired
 * `MigrationsEnded` (a listener added mid-run, a test firing the event by
 * hand) must not leave the counter at -1, where the next real `open()` would
 * bring it to 0 and leave a genuine migrate run unguarded.
 *
 * A FAILED RUN LEAVES THE WINDOW OPEN. `Migrator::runPending()` fires
 * `MigrationsEnded` only after its last migration succeeds, so a migration
 * that throws leaves its level open for the rest of this application
 * instance. A later migrate run in the same instance does NOT close it: it
 * opens one level and closes only that one, and the depth comes back to where
 * the failed run left it, still open. `php artisan migrate` exits on the
 * failure, php-fpm and the queue worker are other processes with their own
 * window, and every test boots its own application, so none of those inherits
 * it. Anything that retries in-process does: a tinker session, a command or
 * test that catches the failure and migrates again, or an outer run that
 * catches an inner one. Every observer guarded here then stays silent for
 * every save that follows. `reset()` is the recovery: it closes every level at
 * once. Nothing in app/ calls it today.
 *
 * Bound as a SINGLETON on purpose: the state is a fact about the process (is
 * the migrator running in it?), not about a tenant or a request, so there is no
 * per-tenant state to leak — the opposite of the transient `RuleEvaluator`.
 */
final class MigrationWindow
{
    /** How many migrate runs are in progress in this process, nested or not. */
    private int $depth = 0;

    public function open(): void
    {
        $this->depth++;
    }

    /** Close one level. An unpaired close is a no-op, never a negative depth. */
    public function close(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /** Close every level — recovery after a migrate run that threw. */
    public function reset(): void
    {
        $this->depth = 0;
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    public function depth(): int
    {
        return $this->depth;
    }
}
