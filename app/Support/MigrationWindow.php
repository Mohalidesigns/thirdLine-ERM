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
 * A failed migration never fires `MigrationsEnded`, so the window stays open
 * for the rest of that process. Every caller of the migrator in this product
 * (`migrate`, `migrate:fresh` under `RefreshDatabase`) is a process that then
 * exits or fails the suite, so nothing runs afterwards with webhooks wrongly
 * suppressed — but `close()` is public for anything that ever needs to recover
 * in-process.
 *
 * Bound as a SINGLETON on purpose: the state is a fact about the process (is
 * the migrator running in it?), not about a tenant or a request, so there is no
 * per-tenant state to leak — the opposite of the transient `RuleEvaluator`.
 */
final class MigrationWindow
{
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
