# ADR 0029 — Every database session runs in UTC, and the TIMESTAMP values already written are converted once, in the same deploy

**Status:** Accepted · **Date:** 2026-10-16 · **Phase:** cross-module platform defect (database connection; every module's `TIMESTAMP` columns). It lands **after PR #14 has deployed**, as two PRs off `main` (§Sequencing), **not** on PR #14 · **Author:** architect
**Amended:** 2026-10-16, after gate 2 (code-reviewer) rejected the implementation. `composer install` deletes the config cache, so the pin is live from then on, not from `config:cache`. Changed: Context (the deploy section, rewritten), §2b.2, §2c.5, §3a (rewritten: the window opens before `composer install`, a `schedule:run` wait, no cache reorder), §4 test 9, §5a (precondition 0, check 11, the maintenance-driver and `hidepid` checks, stop conditions), implementation steps 4 and 7, and Sequencing (rewritten: PR #14 first, then two PRs). **Unchanged:** the pin, the old-zone source, the ledger, the column rulings, `risk_audit_trail`, `down()` and the preflight check.
**Requested by:** the coordinator, from the production finding of 2026-10-16 (`@@global.time_zone = SYSTEM`, `@@system_time_zone = CEST` on the VPS's MySQL 8.0.46)
**Closes:** the connection-time-zone question that ADR 0022 ("What this ADR deliberately does not do") and ADR 0025 ("Residual: `TIMESTAMP` is read through the session time zone") both deferred to "its own ADR". This is that ADR.
**Consumers:** backend-engineer **(lead: one config line on two connections, one migration with one ledger table, one preflight check, the `deploy.sh` maintenance window, tests, one standard entry)** · reliability-engineer (the deploy window, the VPS checks, `docs/DEPLOYMENT.md`, rollback pairing, backups) · qa-engineer · code-reviewer · compliance-analyst (what may be said about audit-trail event times after the conversion) · ERM (`risk_audit_trail`: column type changes, values do not) · TPRM track (`tp_audit_logs` chain: values converted, chain unchanged) · BCMS · RCSA · platform (`failed_jobs`, notifications, permissions, tokens: converted, no code change)

## Context

### What production runs

| Setting | Production (VPS, MySQL 8.0.46) | Reproduced locally |
|---|---|---|
| `@@global.time_zone` | `SYSTEM` | `SYSTEM` on MariaDB 10.4.28 (`127.0.0.1:3313`) and MySQL 8.0.46 (`:3314`) |
| `@@system_time_zone` | `CEST` (Europe/Berlin, DST-observing) | `CEST` on both |
| `config/app.php` `timezone` | `'UTC'` (hard-coded) | same |
| `config/database.php` `mysql`/`mariadb` `timezone` | **not set** | same |
| Anything running `SET time_zone` | **nothing** | same |

Laravel's `MySqlConnector::configureConnection()` issues `SET time_zone=…` only when the
connection config has a `timezone` key. Ours has none, so every session runs on the server's
`SYSTEM` zone. `NOW()`, `CURRENT_TIMESTAMP` and `CURDATE()` run two hours ahead of the UTC
the application writes (one hour in winter). Every `TIMESTAMP` value is converted through CEST
on each write and each read.

### The mechanism, and why nothing looks wrong

MySQL and MariaDB store a `TIMESTAMP` as a UTC instant. They convert **from** the session zone
on write and **to** the session zone on read. The application writes UTC strings, for example
`'2026-10-16 06:00:00'`. Under a CEST session that string is taken to mean 06:00 CEST, and
the server stores 04:00Z. Read back under the same session, it returns `'06:00:00'` again. The
round trip is exact, so no test, screen or audit chain can see anything wrong. The stored
instant is wrong by the server's offset, and it stays hidden for as long as nothing changes the
session zone.

`DATETIME` columns take no part in this. They store the literal string, so app-written values
in them are already correct. That includes the 17 columns ADR 0022 converted.

### Why it is a defect even though every round trip is exact

1. **A one-hour outage every March, on both engines.** A UTC string that falls in CEST's
   spring-forward gap is a local time that does not exist. Under `STRICT_TRANS_TABLES`, which
   Laravel's `strict => true` sets, the server **rejects the write**. I inserted
   `'2026-03-29 02:30:00'` into a `TIMESTAMP` column under `SYSTEM`. MariaDB 10.4.28 and
   MySQL 8.0.46 both returned `ERROR 1292 (22007): Incorrect datetime value`. Between 02:00
   and 02:59 UTC on the last Sunday of March, every `created_at`/`updated_at` write fails, so
   every insert and update in the product fails for an hour. The next occurrence is
   **2027-03-28 02:00–02:59 UTC**. Any app-supplied instant with a value in that hour fails on
   any day of the year.
2. **Database-side defaults are in a different zone from everything else.** The five
   `useCurrent()` columns and the two `CURDATE()` defaults evaluate in CEST. Any row that took
   a default would read two hours ahead of the app-written columns beside it.
3. **The stored instant depends on the host's clock setting.** If the database moves to
   another host, the OS zone changes, or a `mysqldump` (whose default `--tz-utc` dumps the
   internal UTC) is restored onto a server in a different zone, **every** `TIMESTAMP` reads
   shifted. At that point every row of both hash chains reads as tampered: `risk_audit_trail`
   hashes `changed_at` (`RiskAuditTrail::HASHED_FIELDS`) and `tp_audit_logs` hashes
   `created_at` (`Tprm\AuditLog::HASHED_FIELDS`). This is ADR 0025's residual, and today it is
   one server setting away from happening.
4. **Anyone querying the database by hand compares two clocks.** `WHERE created_at > NOW() -
   INTERVAL 1 HOUR` at the console is wrong by the offset.

### The trap: the obvious fix moves every existing value

Reproduced on both engines with the same results:

| Step | Session | `c` (summer row) | `c` (winter row) |
|---|---|---|---|
| App writes `'2026-10-16 06:00:00'` / `'2026-01-15 06:00:00'` | `SYSTEM` (CEST) | reads `06:00:00` | reads `06:00:00` |
| Connection pinned, no data change | `+00:00` | **reads `04:00:00`** | **reads `05:00:00`** |
| `UPDATE t SET c = CONVERT_TZ(c, '+00:00', 'SYSTEM')` under `+00:00` | `+00:00` | reads `06:00:00` | reads `06:00:00` |

**Setting `'timezone' => '+00:00'` on its own would silently move every existing app-written
`TIMESTAMP` back by one or two hours in production.** That covers every `created_at` and
`updated_at` in the product. It would also break both audit chains for every existing row,
because the hashed string would no longer be the string that was sealed. So the pin and the
data conversion must ship in one deploy, with nothing writing between them.

The repair is exact and DST-aware row by row. `CONVERT_TZ(x, '+00:00', 'SYSTEM')` applies the
offset that was in force at each row's own instant. It needs no time-zone tables when the zone
is `SYSTEM` or a numeric offset. I checked the autumn fall-back hour as well: a row written as
`'2026-10-25 02:30:00'`, an ambiguous local time, comes back as `'2026-10-25 02:30:00'`.

### Inventory (`origin/main` @ `463e0b3`, fully migrated scratch schema on MariaDB)

- 281 base tables, all InnoDB, and no views.
- **828 `TIMESTAMP` columns in 271 tables.** None is generated, none has fractional
  precision, none has `ON UPDATE` (ADR 0022's guard), and **none is in a unique index** (28
  non-unique indexes include one).
- **Five have a database default (`CURRENT_TIMESTAMP`).** I traced every create path for each
  one (§2e). Every path writes the column from PHP.
- **Two `DATE` columns default to `CURDATE()`:** `loss_events.date_reported` and
  `near_misses.date_reported`.
- **Triggers:** only `risk_audit_trail_no_update` and `risk_audit_trail_no_delete`
  (`2026_08_09_100004`). Both `SIGNAL 45000`, so they **block any `UPDATE` repair** of
  `risk_audit_trail`.
- No raw `NOW()`, `CURDATE()`, `CURRENT_TIMESTAMP`, `UNIX_TIMESTAMP()` or `FROM_UNIXTIME()` in
  `app/`, `packages/`, `config/`, `routes/` or the seeders. The queue, cache and session tables
  hold integer epochs. They are zone-free and out of scope.
- One connection, the default, used by everything. Nothing defines a connection at runtime.

### The new configuration goes live at `composer install`, minutes before `migrate`

*(Amended 2026-10-16, after gate 2. The first version of this section said the migration ran
under the previous release's cached configuration. That was false.)*

`scripts/deploy.sh` runs: `git fetch`/`git reset` → **`composer install`** → npm ci and build
(minutes) → `migrate` → seed → `config:cache` → permissions → preflight → restart php-fpm and
`risk-queue`. There is no maintenance mode.

`composer.json:70` runs `Illuminate\Foundation\ComposerScripts::postAutoloadDump` as a
post-autoload-dump script. That calls `clearCompiled()`, which **unlinks
`bootstrap/cache/config.php`** (`vendor/laravel/framework/src/Illuminate/Foundation/ComposerScripts.php:100-110`).
So the moment `composer install` finishes, no config cache exists. Every new process then reads
`config/database.php` from disk, which is the new file carrying the `'+00:00'` pin. That covers
each php-fpm request, each minute's `schedule:run`, the `artisan migrate` process (and so
**every migration in that run, including the ones that sort before this one**), and the
permission seeder. The only process still on the old (`SYSTEM`) configuration is the
long-running queue worker. It keeps the configuration it booted with until it is restarted, and
**it is not restarted if preflight fails**.

As the script stands, this gives two windows. Both corrupt data:

1. **From `composer install` to `migrate`** (the npm build, measured in minutes): php-fpm and
   the scheduler write **true UTC** into tables that are **not yet converted**. The conversion
   then shifts those rows a second time, and they read one or two hours late from then on.
2. **From `migrate` until the worker restarts** (indefinitely, if preflight fails): the old
   worker writes **old-zone** values into tables that **have been converted**. Those rows read
   one or two hours early.

A `tp_audit_logs` row written in either window would read as tampered permanently.

**One more fact that shapes the sequencing.** `deploy.yml` runs
`bash /var/www/thirdLine-ERM/scripts/deploy.sh`, and the script's own `git reset` replaces that
file partway through the run. The deploy that **ships** a change to `deploy.sh` is therefore
executed by the **previous** `deploy.sh`. A new maintenance window protects only the deploys
after the one that installs it (§Sequencing).

## Decision

### 1. The connection is pinned to `'+00:00'`, hard-coded, on both MySQL-family connections

In `config/database.php`, add `'timezone' => '+00:00',` to the `mysql` **and** `mariadb`
connections, with a one-line comment naming this ADR. Leave `pgsql` and `sqlsrv` unchanged;
nothing uses them.

- **`'+00:00'`, not `'UTC'`.** A named zone needs the server's time-zone tables. The MariaDB
  scratch server has none (`mysql.time_zone_name` is empty), and there `CONVERT_TZ(…, 'UTC')`
  returns `NULL` and `SET time_zone = 'UTC'` fails. A failed `SET` on connect would turn every
  request into a 500. A numeric offset is parsed natively by MariaDB 10.4 and MySQL 8 and needs
  no tables. UTC has no DST, so a fixed offset is exact.
- **Hard-coded, not `env('DB_TIMEZONE', '+00:00')`.** The invariant is "the database session
  zone equals the application zone", and the application zone is a hard-coded `'UTC'`
  (`config/app.php:68`). An environment knob on one side can only break that invariant.
  Nobody has a use for any other value. A production `.env` the repository cannot see would be
  the one place it could be set wrong, and preflight (§3b) would then have to police a value
  we invited people to set. A real future need for another zone requires its own ADR, because
  it would require another conversion.
- Because every connection now issues `SET time_zone='+00:00'` on connect, the queue, the
  scheduler, php-fpm, artisan commands and the test suite all run in UTC. `NOW()` equals
  `UTC_TIMESTAMP()`.

### 2. One forward migration converts the existing values, once

`database/migrations/2026_10_16_100001_run_every_session_in_utc_and_convert_timestamp_values.php`
sorts after every migration on `main` and on PR #14 (whose latest is `2026_10_05_100001`).
It is **raw SQL through `DB::statement`/`DB::update`/`DB::select` and the query builder. It
uses no Eloquent model**, so its behaviour cannot drift when a model changes. It uses three
public methods on the anonymous class so the tests can drive them (§4):
`resolveOldZone(): string`, `convertFrom(string $oldZone): array` and
`convergeColumnToDatetime(string $table, string $column, string $oldZone): void`.

#### 2a. A ledger table, written in the same transaction as each table's conversion

This is a structural addition, and this ADR authorises it. The table is `db_timezone_conversions`.
It is platform-owned and unprefixed, following the `risk_audit_trail` and `llm_usage_events`
precedent. It has no `organization_id` and no model.

| Column | Type | Meaning |
|---|---|---|
| `id` | `bigIncrements` | |
| `table_name` | `string(64)`, **unique** | The converted table, or `'*'` for a whole-run skip |
| `column_names` | `text` | Comma-separated, in `ORDINAL_POSITION` order (`''` for `'*'`) |
| `outcome` | `string(32)` | `converted` · `skipped_utc_equivalent` · `skipped_fresh_database` |
| `from_zone` | `string(64)` | The old zone exactly as used, for example `SYSTEM` or `+02:00` |
| `system_time_zone` | `string(64)`, nullable | `@@system_time_zone` at run time, for example `CEST`, for the record |
| `rows_affected` | `unsignedBigInteger`, default 0 | Changed rows as the server reports them |
| `converted_at` | **`dateTime`** (DEVELOPMENT_STANDARD §13) | `now()`, UTC |

**Why a ledger, when the `migrations` table already guarantees a single run.** The
`migrations` row is written only after `up()` returns. MySQL cannot wrap 271 tables' updates
and two DDL statements in one transaction: DDL commits implicitly. A failure on table 140 would
leave 139 tables converted with no `migrations` row, and a re-run would convert those 139 a
second time, which is a silent two-hour shift. The ledger row is written **inside the same
transaction as that table's `UPDATE`**, so "converted" and "recorded" commit together or not at
all. A re-run resumes at the first table without a row. The ledger is also the evidence
compliance-analyst needs (§2l).

#### 2b. The old zone is `@@global.time_zone`, validated before anything is written

`resolveOldZone()`:

1. If the ledger already has `converted` rows (a resumed run), use their `from_zone`. If they
   disagree with each other, throw. A resumed run must use the zone the first attempt used.
2. Otherwise use **`@@global.time_zone`**, not `@@session.time_zone`. No release before this
   one ever set a session zone, so every app-written row went through the global zone.
   The migrate process's own session cannot tell us the old zone. It is already `+00:00` in
   production, because `composer install` deleted the old config cache (Context). It is also
   `+00:00` on any uncached developer checkout. Trusting the session would make the migration
   skip as "UTC-equivalent" everywhere, and every existing value would move.
3. **Validate:** `CONVERT_TZ('2026-01-15 12:00:00', '+00:00', ?)` and
   `CONVERT_TZ('2026-07-15 12:00:00', '+00:00', ?)` must both be non-`NULL`. If either is
   `NULL`, the global zone is a named zone and the server has no time-zone tables. In that case
   **throw before any write**, with a message naming the zone and this ADR. Do not guess an
   offset. A fixed offset is wrong for half the year in any DST zone.

**UTC-equivalence test** (used in §2c): the zone is UTC-equivalent when both of those
`CONVERT_TZ` results are **exactly** their inputs. January and July are both tested so that a
zone that is UTC in winter only (Europe/London, for example) is not mistaken for UTC.

#### 2c. Order of operations in `up()`, and when it skips

1. Driver guard: if the driver is not `mysql` or `mariadb`, return.
2. Create `db_timezone_conversions` if it does not exist.
3. `$old = $this->resolveOldZone()`. This throws on an unusable zone, before step 4.
4. `convergeColumnToDatetime('risk_audit_trail', 'changed_at', $old)` (§2f). This runs
   **always**, including on the skip paths below. It is schema convergence, so every
   environment ends with one shape.
5. `SET time_zone = '+00:00'`. **Leave it set** for the rest of the process. In production
   the connection was already `+00:00` (Context), and any later migration in the run writes into
   converted tables, so it must write UTC. Restoring the zone found at entry would be wrong on
   any connection that entered on `SYSTEM`. **Migrations that sort before this one in the same
   run also write under `+00:00`, into tables that have not been converted yet, and this
   migration would then shift those rows.** No ordering inside the migration can fix that. It is
   prevented by deploying this migration alone (§5a precondition 0).
6. If the ledger has a `'*'` row, return.
7. If `$old` is UTC-equivalent, insert `'*'` / `skipped_utc_equivalent` and return. This is
   CI (UTC containers) and any host that was always UTC.
8. If there are no `converted` ledger rows and the `organizations` table is missing or has no
   rows (raw `DB::table`, soft-deleted rows count), insert `'*'` / `skipped_fresh_database` and
   return. A database with no tenant has no application-written rows. Its only rows were written
   earlier in this same `migrate` run, under whatever config that process loaded, which on a
   fresh install is the new pinned config. Converting them would shift them. The worst case of
   this rule is a tenantless database whose system rows are an hour off, which is harmless.
   This is how the test suite's and a fresh install's migrations behave on a developer's
   CEST or WAT server.
9. `$this->convertFrom($old)`.

#### 2d. Which columns: every `TIMESTAMP` column found at run time, not a frozen list

```sql
SELECT c.TABLE_NAME, c.COLUMN_NAME
  FROM information_schema.COLUMNS c
  JOIN information_schema.TABLES  t
    ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
 WHERE c.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_TYPE   = 'BASE TABLE'
   AND LOWER(c.DATA_TYPE) = 'timestamp'
 ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;
```

**Why discovery and not a list.** The set of `TIMESTAMP` columns differs between environments.
It depends on which of PR #14's migrations have run, which BCMS and TPRM tables exist, and
whether ADR 0022's convergence has run. Every one of those columns was written through the same
`SYSTEM` session, so "every `TIMESTAMP` column" is exactly the affected set, with no exceptions
(§2e). A frozen list can only be wrong in the direction that matters: a column it misses is
silently shifted by the pin. ADR 0022 used a list because it was fixing 17 specific defects.
This migration fixes a property of the type. `BASE TABLE` excludes views, of which there are
none today. `risk_audit_trail.changed_at` is no longer `timestamp` by the time this query runs
(step 4), so it drops out on its own.

`convertFrom(string $oldZone): array<string,int>`:

- `SET time_zone = '+00:00'` (again, so that a test calling it directly gets the same
  conditions).
- For each discovered table **with no ledger row of its own** (a `'*'` row does not count, so a
  test can call this after the suite's own skip), in **one transaction per table**:
  1. `SELECT SUM(c1 IS NULL) AS n1, SUM(c2 IS NULL) AS n2, … FROM t`
  2. `UPDATE t SET c1 = CONVERT_TZ(c1, '+00:00', ?), c2 = CONVERT_TZ(c2, '+00:00', ?), …`, with
     `$oldZone` bound once per column and no `WHERE` clause. `NULL` stays `NULL`. Every
     `TIMESTAMP` column of the table is in the `SET` list, so no `ON UPDATE` could fire even if
     one existed.
  3. Repeat query 1. **If any null count changed, throw**, which rolls the table back. The
     conversion must never create or remove a `NULL`. This catches a zero date (where
     `CONVERT_TZ` returns `NULL`) and any value `CONVERT_TZ` cannot map.
  4. Insert the ledger row (`converted`, column list, `$oldZone`, `@@system_time_zone`, the
     `UPDATE`'s affected-row count, `now()`).
- Return `[table => rows_affected]`, and print one summary line per run to the console.

Tables with zero rows still get a ledger row, so the coverage is complete and a re-run skips
them.

#### 2e. The database-defaulted columns: rulings per column

A row stamped by the database itself (`DEFAULT CURRENT_TIMESTAMP` with the column omitted)
already holds the **correct** instant. Converting it would make it wrong, so I traced each
column's create paths.

| Column | Every create path | Ruling |
|---|---|---|
| `approval_requests.requested_at` | One: `ApprovalService::request()` (`app/Services/ApprovalService.php:41-50`) passes `'requested_at' => now()`. No seeder, factory or raw insert. `Tprm\ContractController:285` and `TrustProfileShare`/`Waiver` are different tables. | **Convert** |
| `issue_escalation_log.escalated_at` | Two: `IssueEscalationService` (`:103-110`, `'escalated_at' => now()`) and `EnterpriseGapSeeder` (`:347-364`, `'escalated_at' => $now->copy()->subDays(…)`). Both explicit. | **Convert** |
| `failed_jobs.failed_at` | The framework's failed-job provider, `'failed_at' => Date::now()` | **Convert** |
| `loss_event_approvals.actioned_at` | `LossEventController:631,643` (`now()`), `Workflow\Subjects\LossEventBinding:125` (`$task->completed_at`) | **Convert** |
| `risk_audit_trail.changed_at` | `AuditTrailService:41`, `PersistsConfiguredAttributes:133`, `TreatmentPlanService:520`, `RiskRegisterService`, `RcsaAuditRecorder`. All explicit since the first commit (`5679a4f`). | **Type change, not `UPDATE`** (§2f) |

No column needs row-by-row treatment. Every path writes from PHP, so every row is app-written
and old-zone, and the uniform conversion is right. A row someone inserted by hand at the
console, relying on the default, would read one or two hours late afterwards. No such path
exists in the code, and nothing can tell such a row apart, so none is looked for.

#### 2f. `risk_audit_trail`: the column becomes `DATETIME` under the old zone, and the triggers are never touched

`convergeColumnToDatetime('risk_audit_trail', 'changed_at', $old)`:

- If the table or column is missing, or `DATA_TYPE` is not `timestamp`, return. That makes it
  idempotent and is the resumed-run case.
- `SET time_zone = ?` with `$old`, then
  `ALTER TABLE risk_audit_trail MODIFY changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  (nullability read from `IS_NULLABLE` and preserved, as in `2026_09_23_120001`).

`TIMESTAMP → DATETIME` reads each value **through the session zone** and stores the literal it
produces. Under the old zone that literal is exactly the string the application wrote and
sealed. I verified this on both engines with a `BEFORE UPDATE … SIGNAL` trigger on the table:

- the values were unchanged as read under `+00:00` afterwards;
- **the trigger did not fire**, because `ALTER` is not a row update;
- the trigger was still in place afterwards;
- on MySQL 8.0.46, a user holding only `ALTER` (no `SUPER`, no `TRIGGER`) could run it.

The default is kept. It evaluates in the session zone, and after §1 that is UTC, so the
default is now correct. Every writer sets the column anyway.

**Why this and not the alternatives:**

- *Drop the triggers, `UPDATE`, recreate the triggers.* This lifts the append-only guard on
  the evidence table. DDL commits implicitly, so it cannot be atomic: a failure between the
  drop and the create leaves the trail mutable, and no preflight check looks for the triggers.
  On MySQL 8 with binary logging, `CREATE TRIGGER` also needs `SUPER` or
  `log_bin_trust_function_creators = 1` again, at every deploy that runs it, on every host.
  **Rejected.**
- *Leave it a `TIMESTAMP` and document it.* After the pin, every existing row would read one
  or two hours early, and **`audit:verify` would report every existing ERM row as tampered**.
  **Rejected.**

What it costs: one structural change to an evidence table. No stored value as the application
reads it changes, and the chain verifies before and after (test 4). The column also leaves
session-zone conversion permanently, so this chain can never again be broken by a server zone
change. **`tp_audit_logs` is not given the same type change.** No trigger blocks its
`UPDATE`, the pin plus preflight's check (§3b) protects it, and no defect requires a second
schema change.

#### 2g. The two `CURDATE()` defaults: no data change, no schema change

`DATE` is zone-free, so existing values are what they are. After the pin the default evaluates
in UTC, which matches `now()->toDateString()` in the writers (`LossEventController:531`,
`DataImportProcessor:192`). A row that took the default between 22:00 and 24:00 UTC during CEST
carries the next day. Nothing can identify such a row, so it is left alone.

#### 2h. Idempotency and restored backups

- **Re-run after a mid-way failure:** resumes per table through the ledger (§2a), with the
  zone the first attempt used (§2b.1).
- **Restore of a post-conversion backup:** the ledger and the `migrations` row travel with the
  data, so nothing runs again. `mysqldump`'s default `--tz-utc` dumps and restores the internal
  UTC exactly. Backups must keep that default; reliability-engineer, note this.
- **Restore of a pre-conversion backup, then `migrate`:** the conversion runs with the
  **target** server's `@@global.time_zone`. It is correct only if the target's zone matches the
  zone the data was written under (production: Europe/Berlin via `SYSTEM`). Restoring such a
  dump onto a WAT or UTC machine and migrating would convert with the wrong zone. **The rule is
  that a pre-conversion production backup is migrated only on a host whose system zone is the
  same as production's.** I rejected an override variable for this, because it is a knob nobody
  will set correctly at the one moment it matters.
- **Partial restore** of individual tables from a pre-conversion dump into a converted
  database is not supported. The ledger records which tables were converted and when; anything
  beyond that is a per-incident decision.

#### 2i. `down()` reverses the conversion

`down()` (mysql/mariadb only):

1. `SET time_zone = '+00:00'`.
2. For each `converted` ledger row, in one transaction per table, run
   `UPDATE t SET c = CONVERT_TZ(c, ?, '+00:00')` with the row's `from_zone`, apply the same
   null-count postcondition, and delete the ledger row.
3. Drop `db_timezone_conversions`.
4. **Leave `risk_audit_trail.changed_at` as `DATETIME`.** It is zone-free, so it is correct
   under a `SYSTEM` session as well. Going back to `TIMESTAMP` would re-expose the chain for no
   gain. ADR 0022 §3 makes the same kind of call.

A real `down()` is provided because rolling **code** back past this release without rolling the
**data** back is wrong in the same way as the original trap, in the opposite direction: a
`SYSTEM` session reads the converted values one or two hours late. The two must move together.
`docs/DEPLOYMENT.md`'s rollback section gains this rule. To roll back past this migration:
maintenance on, `php artisan migrate:rollback --step=<n>` (so that only migrations from this one
onward roll back) **while this release's code is still on disk**, then `git reset` to the older
SHA, then `config:cache`, restart, and maintenance off. Never one half without the other. A
string that falls in a future spring-forward gap (written under UTC after the conversion) comes
back one hour off after a `down()`. That is unavoidable and documented.

#### 2j. Size, locking, binary log

There is one `UPDATE` per table, in its own transaction, plus two null-count scans. It runs
inside the maintenance window (§3a), with the worker drained and the scheduler idle, so it
competes with nothing. The `ALTER` on `risk_audit_trail` is a table copy (`ALGORITHM=COPY` on
MySQL 8 for a type change) that blocks writes to that table only for its duration. MySQL 8's row
binary log records every changed row, which is fine at the expected scale. **The stop rule
comes from the VPS sizing (§5):** if the largest affected table estimates above **1,000,000
rows**, or the total above **5,000,000**, do not deploy. Bring the numbers back. A chunked
variant with per-chunk ledger rows would be a separate amendment to this ADR.

#### 2k. Failure modes, all loud

| Condition | What happens |
|---|---|
| Global zone is named and the server has no tz tables | Throws in `resolveOldZone()`. Nothing has been written. `migrate` fails. The site stays in maintenance (§3a). |
| A value maps outside the `TIMESTAMP` range (a stored local time within an hour of 2038-01-19 03:14:07) | `ERROR 1292` on both engines (verified). That table rolls back, the ledger shows how far the run got, and a re-run resumes. |
| A zero date or unmappable value | The null-count postcondition throws and the table rolls back. |
| Ledger rows disagree on `from_zone` | Throws before any write. |

#### 2l. What compliance may say

In 827 `TIMESTAMP` columns, the conversion rewrites the **stored internal instant**. That
includes `tp_audit_logs.created_at`, done with a raw `UPDATE` that the model's
`updating` guard does not see. It changes **no value as the application, its exports, its
screens or its hash chains read it**. `risk_audit_trail` is not updated at all. The accurate
statement is: "on <date>, migration `2026_10_16_100001` corrected how event times were stored,
so that they no longer depend on the database server's clock setting. Every displayed and
hashed value is unchanged, both chains verify before and after, and the run is recorded per
table in `db_timezone_conversions`." Compliance-analyst owns the final wording for any
supervisory export.

### 3. The deploy window is closed with maintenance mode, and preflight checks the pin

#### 3a. `scripts/deploy.sh`: the maintenance window opens before `composer install`

*(Amended 2026-10-16, after gate 2. The first version opened the window just before `migrate`
and moved the caches ahead of the seeder. Both rested on the false premise corrected in Context.
`composer install` already makes the pin live, so the window must open before it. No cache
reorder is needed.)*

The script becomes, in this order (only the new and moved lines are shown; everything else
stays as it is):

```bash
git fetch --all --prune                       # a network failure here costs no downtime

echo "==> Maintenance mode on"
php artisan down --retry=60                   # previous release's code and cached config
trap 'echo "!!  DEPLOY FAILED IN MAINTENANCE MODE: the site is serving 503. See docs/DEPLOYMENT.md."' ERR
sudo /usr/bin/systemctl restart risk-queue    # drain: see (2)

git reset --hard origin/main
composer install …                            # from here on the new config is live (Context)
npm ci … && npm run build

# (4) wait out any schedule:run that started before `down`
…
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
ensure_runtime_permissions                    # PR #14's function, kept as is
php artisan app:preflight                     # on failure: stay down, see (5)
sudo /usr/bin/systemctl restart php8.4-fpm
sudo /usr/bin/systemctl restart risk-queue

echo "==> Maintenance mode off"
php artisan up
```

1. **`down` goes after `git fetch` and before `git reset`.** It therefore runs on the previous
   release's code with its cache, while no composer step has yet deleted that cache. php-fpm
   answers 503 from `public/index.php` before the framework boots, so neither old code on the old
   config nor new code on the new config can write. The composer, npm, migrate and seed steps all
   fall inside the window. **The build time is now downtime, and that cost is accepted.** It is
   the only way to keep new-config writers out of unconverted tables.
2. **The worker is drained immediately after `down`, still before `git reset`.**
   `systemctl restart` delivers SIGTERM. Laravel's worker finishes its current job and exits,
   and the restart blocks until it has. The replacement worker boots on the **previous**
   release's code and config. It is parked: `Worker::daemonShouldRun()` is false while the
   application is down, unless the unit passes `--force` (checked in §5a). It does not run a job
   until the final restart replaces it. This uses only the two restart rights the `deploy` user
   already has. Residual: the parked process sits on files that `git reset` then replaces. It
   only sleeps and polls the maintenance flag, so it loads no new code. The final restart retires
   it either way.
3. **The scheduler.** Every `schedule:run` that cron starts after `down` skips every event. No
   event in `routes/console.php` is `evenInMaintenanceMode()` or `runInBackground()`.
4. **Wait out an in-flight `schedule:run` before `migrate`** (reviewer advisory A1). A
   `schedule:run` that started just before `down` keeps running its foreground commands on the
   old configuration. Its old-zone writes into unconverted tables are correct, **if** it has
   finished before the conversion starts. Immediately before `migrate`, poll
   `pgrep -f 'artisan schedule:run'` every 5 seconds, up to 60 times (5 minutes), and require
   **two consecutive empty polls**, so a run cron starts between polls is seen. The build has
   usually already outlasted it. On timeout the script prints that the site is in maintenance
   mode and serving 503, then runs `false`: a bare `exit 1` does **not** fire the `ERR` trap.
   The bound is 5 minutes because `.github/workflows/deploy.yml`'s `command_timeout` is raised
   from 20m to **40m**, so the drain, the build, the wait and the conversion fit. This depends
   on `deploy` being able to see another user's processes (no `hidepid`, checked in §5a).
5. **On preflight failure, the site stays in maintenance mode.** Replace the failure message:
   the site is serving 503 and stays that way, the worker is parked, nothing is writing; fix what
   preflight reported and re-run the deploy. **Never run `php artisan up` by hand without first
   running `sudo systemctl restart risk-queue`.** The parked worker is on the previous release's
   configuration and would resume writing in the old zone. This changes behaviour deliberately.
   Today a failed preflight leaves php-fpm serving the new code anyway (the script's own comment
   admits it), so "Do not serve traffic" was never enforced. Now it is. The `ERR` trap makes a
   failed `composer`, `npm`, `migrate` or permissions step explain itself the same way.
6. **`up` is the very last step**, after both restarts.
7. **The caches stay where they are**, after the seeder. The first version of this ADR moved
   them; that move is withdrawn. The seeder already reads the pinned config from disk.

**The window protects every later deploy, not only this one.** After every `composer install`,
new code runs against the old schema until `migrate` finishes. This conversion is the case where
that does measurable damage.

`tests/Feature/DeployScriptRunsPreflightTest.php::preflight_runs_before_the_services_are_restarted`
uses the **first** `systemctl restart`, and the drain is now earlier. Amend it to assert against
`systemctl restart php8.4-fpm` and the **last** `systemctl restart risk-queue`. **That is not a
weakening.** The only restart allowed before preflight is the drain inside the maintenance
window, and test 9 pins its position. Match on non-comment lines only, because the script's
comments name these commands.

This edit is made on top of PR #14's `deploy.sh`, keeping `ensure_runtime_permissions`
(§Sequencing).

#### 3b. Preflight gains one check: `Database time zone`

Add `checkDatabaseTimeZone()` to `App\Console\Commands\Preflight`, called right after
`checkDatabaseEngine()`:

- Driver not `mysql`/`mariadb`: **PASS**, "not applicable (driver x)".
- `SELECT @@session.time_zone, @@global.time_zone, @@system_time_zone`. **FAIL** if the
  session zone is not exactly `'+00:00'`: "session time zone is {s}; ADR 0029 pins '+00:00' —
  the config cache predates this release or the pin was removed; every TIMESTAMP would be read
  through {g}". This is the check that matters on every future deploy.
- `db_timezone_conversions` is missing: **FAIL**, "ADR 0029's conversion migration has not run".
- Otherwise **PASS**, with the ledger in the detail, for example "session +00:00 (server
  SYSTEM/CEST); 271 tables converted from SYSTEM on 2026-10-xx" or "conversion skipped:
  utc_equivalent". The deploy log then records what production did.

No check for "has the global zone been converted" beyond the ledger's presence. The server's
global zone legitimately stays `SYSTEM`/CEST for ever, and the application no longer reads
through it. Changing the VPS's global zone is not part of this ADR, and it is not needed.

### 4. Tests (the contract for qa-engineer)

Every one of these runs on both CI legs (MariaDB 10.4 and MySQL 8.0.46). CI's containers are
UTC, so a test that needs a non-UTC history **creates** it with `SET time_zone = '+02:00'`, and
drives the migration with an injected old zone. The migration's own `up()` takes the
`skipped_utc_equivalent` path on CI and the `skipped_fresh_database` path on a developer's
CEST or WAT server.

1. **`tests/Feature/Database/DatabaseSessionTimeZoneTest`**
   - `config('database.connections.mysql.timezone')` and `…mariadb.timezone` are both
     `'+00:00'`.
   - `DB::purge(); DB::reconnect();` **first**, then `select @@session.time_zone` is `'+00:00'`.
     The reconnect is load-bearing. The conversion migration leaves the migrating connection on
     `+00:00` itself (§2c.5), and `RefreshDatabase` migrates on the same connection in the same
     process. Without the reconnect, this test would pass with the pin deleted. This is the
     assertion that fails on CI's UTC servers when the pin is missing: there the session would
     report `SYSTEM`.
   - `select NOW() = UTC_TIMESTAMP()` is `1`, and `|NOW() − now()| ≤ 5 s` against PHP's UTC
     clock.
2. **`tests/Feature/Database/TimestampZoneConversionTest`** (`RefreshDatabase`;
   `$m = require database_path('migrations/2026_10_16_100001_….php')`, the
   `CanonicalColumnBackfillTest` pattern):
   - `Carbon::setTestNow('2026-07-15 10:00:00')`, then `DB::statement("SET time_zone = '+02:00'")`
     **before creating any fixture**. Then create an organisation, a user, a risk (or any
     audited ERM entity, so a `risk_audit_trail` row exists), a `tp_audit_logs` row through
     `TprmAuditable`, and an `approval_requests` row through `ApprovalService`. (An injected
     fixed offset cannot exercise DST. Per-row DST handling under `SYSTEM` is engine behaviour,
     verified on both engines for this ADR, and rehearsed in implementation step 7.)
   - `SET time_zone = '+00:00'` and read raw. The `TIMESTAMP` columns read `08:00:00`.
     **This asserts that the defect reproduces.** `risk_audit_trail.changed_at` already reads
     `10:00:00`, because it is `DATETIME`.
   - `$m->convertFrom('+02:00')`. Every converted column reads back exactly the string
     written. The ledger has one `converted` row per table holding `TIMESTAMP` columns, each
     with `from_zone = '+02:00'`.
   - `NULL`s stay `NULL` (one nullable column left null in the fixture).
3. **Idempotency:** a second `$m->convertFrom('+02:00')` changes nothing (values and ledger
   row count identical). Also delete one table's ledger row and call it again: only that table
   moves. That is the resumed-run behaviour, and the test documents why the ledger exists.
4. **Both chains verify across the conversion:** in test 2's world, after `convertFrom`,
   `RiskAuditTrail::isIntact()` and `Tprm\AuditLog::isIntact()` are true for every fixture row.
   Also show that they would have been false had the pin been applied without the conversion:
   read under `+00:00` before converting, then assert `isIntact()` is false on the TPRM row.
5. **`down()`** after `convertFrom('+02:00')`: under `SET time_zone = '+02:00'`, raw reads
   return the original strings, and the ledger table is gone. `down()` drops a table, which is
   DDL and commits implicitly, so this test uses **`DatabaseMigrations`, not
   `RefreshDatabase`**. The schema is rebuilt around it, and later tests are not affected.
6. **`risk_audit_trail` shape and ALTER behaviour:**
   - After migrations: `changed_at` `DATA_TYPE = 'datetime'`, `NOT NULL`, default
     `CURRENT_TIMESTAMP` (normalise `current_timestamp()` against `CURRENT_TIMESTAMP` for the
     two engines). `information_schema.TRIGGERS` still lists both
     `risk_audit_trail_no_update` and `risk_audit_trail_no_delete`.
   - A probe test **without `RefreshDatabase`** (DDL commits implicitly; a plain `TestCase`
     that touches only its own probe table): create
     `adr0029_probe (id, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)`, insert under
     `+02:00`, add a `BEFORE UPDATE … SIGNAL` trigger (CI's root has `SUPER`), then call
     `$m->convergeColumnToDatetime('adr0029_probe', 'changed_at', '+02:00')`. The value reads
     back unchanged under `+00:00`, the trigger still exists, and a second call is a no-op. Drop
     the probe in `tearDown`.
7. **`resolveOldZone()` and the skip rules:** a zone for which `CONVERT_TZ` returns `NULL`
   (for example `'Not/AZone'`) throws, and nothing is written. `+00:00` is UTC-equivalent and
   `+02:00` is not. After `RefreshDatabase`, the ledger holds exactly one `'*'` row with outcome
   `skipped_utc_equivalent` or `skipped_fresh_database`. Accept either, because which one
   depends on the server's zone.
8. **`tests/Feature/PreflightDatabaseTimeZoneTest`:** under test config, preflight's
   `Database time zone` row is PASS. After `DB::statement("SET time_zone = '+01:00'")`,
   `artisan app:preflight --allow-local` reports it as FAIL and exits non-zero.
9. **`DeployScriptRunsPreflightTest`, extended** (non-comment lines; amended after gate 2):
   `git fetch` < `artisan down` < the `ERR` trap < first `systemctl restart risk-queue` <
   `git reset` < **`composer install`** < `npm run build` < the `pgrep -f 'artisan schedule:run'`
   wait < `artisan migrate` < `app:preflight` < `systemctl restart php8.4-fpm` < last
   `systemctl restart risk-queue` < `artisan up`. **`artisan down` < `composer install` is the
   load-bearing pair.** Swapping them reopens the window in which the pin is live and the data
   is not yet converted. Also assert that `artisan up` appears exactly once, and after the
   preflight block, so a preflight failure cannot reach it. Amend the existing first-restart
   assertion as §3a describes.

**Guards to add to DEVELOPMENT_STANDARD §11's load-bearing list:**
`DatabaseSessionTimeZoneTest`, `TimestampZoneConversionTest` and `PreflightDatabaseTimeZoneTest`.

### 5. What production needs

#### 5a. Read-only checks on the VPS, before this PR merges

**Precondition 0 (amended after gate 2): this migration must be the only pending migration in
the deploy that carries it.** Inside that `migrate` run the pin is already live (Context). Any
migration that sorts earlier and runs in the same run therefore writes or compares under
`+00:00` against data that has not been converted. Concretely, PR #14's
`2026_10_05_100001` compares raw `measure_breaches.resolved_at` (a `TIMESTAMP`) against
`end_date.' 00:00:00'`. Under `+00:00` on unconverted data it reads the value one or two hours
early and misses every row it exists to repair. Before merging the pin PR:

```bash
cd /var/www/thirdLine-ERM && php artisan migrate:status | grep -i pending   # must print NOTHING
git -C /var/www/thirdLine-ERM rev-parse HEAD                                # the deployed SHA
```

Then, locally, `git diff --name-only <deployed SHA> origin/main -- database/migrations` must be
empty. Together these mean `2026_10_16_100001` will be the **only** pending migration when the
pin PR deploys. If either check shows anything, deploy that first.

Run the following in the `mysql` client against the application's database, as the
application's user. Every statement is a read; the `SET SESSION` in 11 changes only your own
session. Record every answer.

```sql
-- 1. The zone facts, and the offset right now.
SELECT VERSION(), @@global.time_zone, @@session.time_zone, @@system_time_zone,
       NOW(), UTC_TIMESTAMP(), TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset_now,
       @@log_bin, @@log_bin_trust_function_creators, @@sql_mode;

-- 2. What the migration will see as the old zone. Both must be NON-NULL; if they are
--    exactly 12:00:00 the migration will skip as UTC-equivalent.
SELECT CONVERT_TZ('2026-01-15 12:00:00', '+00:00', @@global.time_zone) AS jan,
       CONVERT_TZ('2026-07-15 12:00:00', '+00:00', @@global.time_zone) AS jul;

-- 3. Migration state.
SELECT COUNT(*) AS migrations_run, MAX(batch) AS last_batch FROM migrations;
SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 5;

-- 4. Scope: tables, views, TIMESTAMP columns. Reference on origin/main @ 463e0b3, fully
--    migrated: 281 base tables, 0 views, 828 timestamp columns in 271 tables.
SELECT (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE') AS base_tables,
       (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE <> 'BASE TABLE') AS views,
       COUNT(*) AS timestamp_columns,
       COUNT(DISTINCT c.TABLE_NAME) AS tables_with_timestamps
  FROM information_schema.COLUMNS c
  JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
 WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
   AND LOWER(c.DATA_TYPE) = 'timestamp';

-- 5. Size: the 20 largest affected tables (InnoDB estimates), then the total.
SELECT t.TABLE_NAME, t.TABLE_ROWS AS est_rows,
       ROUND((t.DATA_LENGTH + t.INDEX_LENGTH) / 1048576, 1) AS mb
  FROM information_schema.TABLES t
 WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
   AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c
                WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
                  AND LOWER(c.DATA_TYPE) = 'timestamp')
 ORDER BY t.TABLE_ROWS DESC LIMIT 20;

SELECT SUM(t.TABLE_ROWS) AS est_rows_total
  FROM information_schema.TABLES t
 WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
   AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c
                WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
                  AND LOWER(c.DATA_TYPE) = 'timestamp');

-- 6. Exact counts and the date range of the data (read under SYSTEM: these are the strings
--    the application sees today — keep them, they are the post-deploy comparison).
SELECT COUNT(*), MIN(created_at), MAX(created_at) FROM organizations;
SELECT COUNT(*), MIN(created_at), MAX(created_at) FROM users;
SELECT COUNT(*), MIN(changed_at), MAX(changed_at) FROM risk_audit_trail;

-- 7. The database-defaulted columns: do any rows exist?
SELECT 'approval_requests' AS t, COUNT(*), MIN(requested_at), MAX(requested_at) FROM approval_requests
UNION ALL SELECT 'issue_escalation_log', COUNT(*), MIN(escalated_at), MAX(escalated_at) FROM issue_escalation_log
UNION ALL SELECT 'loss_event_approvals', COUNT(*), MIN(actioned_at), MAX(actioned_at) FROM loss_event_approvals
UNION ALL SELECT 'failed_jobs', COUNT(*), MIN(failed_at), MAX(failed_at) FROM failed_jobs;

-- 8. The TPRM chain table (ADR 0025 expected it absent until PR #11 deployed).
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tp_audit_logs';
-- Only if that returned 1:
SELECT COUNT(*), MIN(created_at), MAX(created_at) FROM tp_audit_logs;

-- 9. The append-only triggers, and the column the migration will retype.
SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA = DATABASE();
SELECT DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'risk_audit_trail' AND COLUMN_NAME = 'changed_at';

-- 10. The application user's rights (ALTER, CREATE, UPDATE needed; SUPER is NOT needed).
SHOW GRANTS FOR CURRENT_USER();

-- 11. Zero dates in any TIMESTAMP column (reviewer advisory A6). The migration's null-count
--     postcondition throws on one, and the site then stays in maintenance. This builds one
--     query over every TIMESTAMP column and runs it. Verified on both engines. The SET raises
--     MySQL's 1024-byte GROUP_CONCAT default, which 828 columns would silently truncate.
SET SESSION group_concat_max_len = 4194304;
SELECT GROUP_CONCAT(CONCAT('SELECT ''', c.TABLE_NAME, '.', c.COLUMN_NAME,
         ''' AS col, COUNT(*) AS zero_rows FROM `', c.TABLE_NAME,
         '` WHERE UNIX_TIMESTAMP(`', c.COLUMN_NAME, '`) = 0') SEPARATOR ' UNION ALL ')
  INTO @q
  FROM information_schema.COLUMNS c
  JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
 WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
   AND LOWER(c.DATA_TYPE) = 'timestamp';
SET @q = CONCAT('SELECT col, zero_rows FROM (', @q, ') z WHERE zero_rows > 0');
PREPARE zq FROM @q; EXECUTE zq; DEALLOCATE PREPARE zq;   -- expect an EMPTY result
```

These shell commands are read-only too:

```bash
timedatectl                          # the OS zone; expect Europe/Berlin (CEST/CET)
ls -l --time-style=full-iso /etc/localtime   # when the OS zone was last set
systemctl cat risk-queue             # the worker command line: must NOT contain --force
grep -c "Incorrect datetime value" /var/www/thirdLine-ERM/storage/logs/*.log   # past DST-gap rejections
grep -E '^APP_MAINTENANCE_(DRIVER|STORE)=' /var/www/thirdLine-ERM/.env   # expect nothing, or DRIVER=file
grep -o 'hidepid=[^,]*' /proc/mounts   # expect nothing: deploy's pgrep must see www-data's schedule:run
```

**Stop and bring the answers back to the architect if any of these is true:**

- (2) returns `NULL`. The zone is named and has no tz tables, and the migration will throw.
- (5): the largest table is above 1,000,000 estimated rows, or the total is above 5,000,000.
- (4) shows base tables this application did not create. The schema is shared, and discovery
  would convert another application's columns.
- `systemctl cat risk-queue` shows `--force`. Maintenance mode will not park the worker; fix
  the unit first.
- `/etc/localtime` was changed **after** the earliest `MIN(created_at)` in (6). Rows older than
  that change were written under a different zone, and one old zone cannot convert them all.
- (11) returns any row. A zero date would fail the migration inside the maintenance window.
- `APP_MAINTENANCE_DRIVER` is set to anything but `file`. §3a's reasoning (php-fpm answering
  503 from `public/index.php`, the worker reading the flag) assumes the file driver, which is
  `config/app.php`'s default.
- `/proc` is mounted with `hidepid`. §3a(4)'s wait would then see nothing and pass vacuously.
- Precondition 0 fails.
- (9) shows no triggers, which means `2026_08_09_100004` has not run. Find out why before
  adding another migration on top.

Expected outcome: a CEST-to-UTC conversion of every table, a total in the thousands to tens of
thousands of rows, an `ALTER` on a small `risk_audit_trail`, and maintenance mode lasting the build plus seconds of
conversion.

#### 5b. After the deploy (read-only; the `SET` changes only your own session)

```sql
SET time_zone = '+00:00';   -- the application's zone from now on; ad-hoc sessions must do the same
SELECT table_name, outcome, from_zone, system_time_zone, rows_affected, converted_at
  FROM db_timezone_conversions ORDER BY id;
SELECT COUNT(*), MIN(created_at), MAX(created_at) FROM users;           -- must equal 5a(6) exactly
SELECT COUNT(*), MIN(changed_at), MAX(changed_at) FROM risk_audit_trail; -- must equal 5a(6) exactly
SELECT NOW() = UTC_TIMESTAMP();                                          -- 1
```

Then run `php artisan audit:verify`, which must report the ERM chain intact, and confirm the
deploy log's preflight `Database time zone` row is PASS. **Ad-hoc console sessions must
`SET time_zone = '+00:00'` from now on.** Without it the console shows every `TIMESTAMP` one or
two hours later than the application does. Add this to `docs/DEPLOYMENT.md`.

### 6. The standard gains §17

Add this to `docs/DEVELOPMENT_STANDARD.md` in the same commit (it is §17: PR #14 already owns §16):

> ## 17. Every database session runs in UTC
>
> `config/database.php` pins `'timezone' => '+00:00'` on the `mysql` and `mariadb`
> connections, matching `config/app.php`'s `UTC`. A `TIMESTAMP` is stored as an instant and
> converted through the **session** zone on every read and write. Until ADR 0029 the session
> was the server's `SYSTEM` zone (CEST on the production VPS), so stored instants depended on
> a host setting, `CURRENT_TIMESTAMP` ran two hours ahead, and every UTC write in CEST's
> spring-forward hour was **rejected** (`1292`) on both engines. Never `SET time_zone` in
> application code, and never make the pin an environment variable. Use `+00:00`, not `UTC`:
> named zones need time-zone tables the servers do not have. Changing the pin needs an ADR
> **and** a data conversion in the same deploy. The pin alone moves every existing value.
> Ad-hoc console sessions must `SET time_zone = '+00:00'` to see what the application sees.
> Guarded by `DatabaseSessionTimeZoneTest`, `TimestampZoneConversionTest`,
> `PreflightDatabaseTimeZoneTest` and preflight's `Database time zone` check.

Add the three tests to §11.

## Implementation steps (backend-engineer)

1. `config/database.php`: add the pin to `mysql` and `mariadb` (§1).
2. The migration (§2): ledger table, `resolveOldZone`, `convergeColumnToDatetime`,
   `convertFrom`, `up()` in the order of §2c, and `down()` as in §2i. Write a docblock that
   states the trap, the old-zone choice, the skip rules, the ledger and the reason for leaving
   the session at `+00:00`, and names this ADR. Use raw SQL with bound zone parameters and
   backticked identifiers taken only from `information_schema`.
3. `Preflight::checkDatabaseTimeZone()` (§3b).
4. `scripts/deploy.sh` (§3a), edited on top of PR #14's version. Maintenance on, the `ERR` trap
   and the drain go after `git fetch` and before `git reset`/`composer install`. The
   `schedule:run` wait goes before `migrate`. A failed preflight stays down. `up` is last. The
   caches do **not** move. Then `docs/DEPLOYMENT.md`: the "What a deploy does" list (including
   that build time is now downtime), the rollback pairing (§2i), the backup rule (§2h) and the
   console rule (§5b). **This step ships as its own PR (§Sequencing, step 2).**
5. Tests 1–9 (§4). Amend `DeployScriptRunsPreflightTest` as §3a describes.
6. DEVELOPMENT_STANDARD §17 and §11 (§6).
7. Run the whole suite on MariaDB locally. CI proves the MySQL 8 leg. Then run a manual
   rehearsal on the CEST scratch servers (`:3313` MariaDB, `:3314` MySQL 8). Never use `risk`
   or ports 3306/3307 for this.
   1. Check out the commit before the pin, point `.env` at a scratch database, migrate, seed,
      and `config:cache`, which is production's state. Record 5a(6).
   2. Check out the pin commit, then run **`composer install`**, or at least delete
      `bootstrap/cache/config.php`, which is what composer's post-autoload-dump does. **This step
      is not optional.** It is the step the first rehearsal design skipped, and without it the
      rehearsal does not test what production runs. Confirm that
      `php artisan tinker --execute="echo DB::selectOne('select @@session.time_zone tz')->tz;"`
      prints `+00:00` **before** `migrate`.
   3. Run `migrate`. The ledger must show `converted` rows with `from_zone = SYSTEM`, which
      proves the old zone came from `@@global`, not from the already-pinned session. Then
      confirm 5b's equalities and `audit:verify`.
8. Commit only these files on `fix/db-session-utc`.

## Sequencing

*(Amended 2026-10-16, after gate 2. The first version said this work was "independent of PR #14
in both orders". That was false, for two reasons: precondition 0, and the permissions step
below.)*

**The order is fixed, and it takes three deploys:**

1. **PR #14 merges and deploys first.** Two reasons:
   - Its `2026_10_05_100001` must run in a deploy **without** the pin (precondition 0). In the
     same `migrate` run as this migration it would compare `resolved_at` under `+00:00` against
     unconverted data and miss the rows it repairs.
   - Its `ensure_runtime_permissions` replaces the bare `find … -exec chmod` lines. On the VPS
     those lines fail under `set -e` today (storage/app/private/rcsa, 2026-10-05). With this
     ADR's `ERR` trap and maintenance window in place, that known failure would stop the deploy
     **inside** the window and leave production serving 503. So `deploy.sh` is edited on top of
     PR #14's version, and `ensure_runtime_permissions` is kept.
2. **This branch is rebased onto `main` after that and split into two PRs.**
   - **PR A carries the `deploy.sh` window (§3a), its tests (test 9 and the amended
     first-restart assertion) and the `docs/DEPLOYMENT.md` deploy-steps text.** There is no pin
     and no migration in it. It merges and deploys first. **It has to be separate.** `deploy.yml`
     runs `scripts/deploy.sh` from disk, and the script's own `git reset` replaces it partway
     through, so the deploy that ships a new window is run by the previous script, **without**
     the window (Context). After PR A deploys, run one `workflow_dispatch` deploy with no code
     change. That exercises the new window once with nothing at stake, and the deploy log shows
     `down`, the drain, the wait and `up` working on the real host.
   - **PR B carries the rest:** the pin, the migration, the preflight check, tests 1–8, the
     standard entry and the remaining DEPLOYMENT.md text. Merge it only after precondition 0 and
     the rest of §5a pass. It deploys under PR A's window.
- **Not folded into PR #14.** Its 14 commits have passed both gates on their own scope.
  Folding this in would reopen gates on scope they never reviewed.
- **Deadline:** before **2027-03-28 02:00 UTC**, the next spring-forward hour. There is no
  earlier deadline. The autumn change on 2026-10-25 round-trips correctly (verified), and the
  conversion is DST-aware per row whenever it runs. Every week it waits only adds rows to
  convert.
- **Schema freeze.** This is one new table and one column type change after the freeze, and
  this ADR authorises them. On the freeze's own test: **no track has built against
  `risk_audit_trail.changed_at` being a `TIMESTAMP`.** The cast is `datetime`, the hash reads
  the string, and both are unchanged. The ledger is new and has no consumer except preflight.

## Alternatives rejected

- **Pin the connection and convert nothing.** This moves every existing value back one or two
  hours and makes both chains read as tampered (Context, the trap table).
- **Convert every `TIMESTAMP` column to `DATETIME` under the old zone, and do not pin.** That
  is 827 table rebuilds. Laravel's `timestamps()` keeps creating `TIMESTAMP` in every future
  migration, so the session still decides what those store, and the March outage remains for
  each new column. The pin is the fix. The conversion is the one-off cost of the pin.
- **Pin code-side instead of in config** (a `ConnectionEstablished` listener that runs
  `SET time_zone`). The pin would then take effect at `git reset`, minutes before `migrate`, so
  the window simply moves earlier and gets longer: new code writes UTC into tables that have
  not been converted. It also hides a connection setting outside the connection config.
- **Take the old zone from `@@session.time_zone` at the start of the migration.** That is wrong
  on any uncached checkout, where the new config is already live (§2b.2).
- **Change the server's global zone to UTC instead.** The application does not control a hosted
  database's globals. The stored instants are already wrong, so a global change shifts the
  same values the pin would and still needs this conversion. Correctness would then also
  depend on a server setting nobody can see from the repository, which is ADR 0022's argument.
- **One transaction over all tables.** The `ALTER` commits implicitly, and one transaction over
  every row of 271 tables is the wrong unit for a resumable job. Per-table transactions with an
  in-transaction ledger row are resumable and atomic where it matters.
- **An override environment variable for the old zone** (for restores onto other hosts). Rare,
  and set wrong exactly when it is needed. The rule in §2h is enough.
- **A static, frozen column list.** It is wrong in whichever environment differs from the list
  (§2d).
- **Leave the deploy order alone and accept the window.** It would write old-zone rows into
  converted tables, including permanently "tampered" TPRM audit rows, and a failed preflight
  would extend the window without limit.

## What this ADR deliberately does not do

- It does not change the server's global or system zone, and it does not ask the host to load
  time-zone tables.
- It does not change any model, cast, service, screen or export. It changes no `DATETIME`
  column, and no `TIMESTAMP` column except `risk_audit_trail.changed_at`.
- It does not retype `tp_audit_logs.created_at` (§2f, last paragraph).
- It does not identify or repair rows stamped by a database default, `CURDATE()` dates
  stamped near midnight, or writes rejected in past DST gaps. None of them can be identified.
- It does not build a TPRM chain verifier (ADR 0025 booked that) or a re-conversion tool.
- It does not make `deploy.sh` roll back anything automatically.
- It adds no environment variable.

## Consequences

- `NOW()`, `CURRENT_TIMESTAMP` and `CURDATE()` agree with the application. `TIMESTAMP` storage
  no longer depends on the host's zone. The March outage hour cannot happen.
- Every value the application reads is the same before and after the deploy. Both audit chains
  verify across it. `db_timezone_conversions` records what was done to each table, by which
  zone, and when.
- ADR 0025's residual is closed. For `tp_audit_logs` it is closed by the pin, enforced at every
  deploy by preflight. For `risk_audit_trail` it is closed permanently, because the column
  is now `DATETIME`.
- Deploys now run in maintenance mode from just after `git fetch` to the final restart. That
  includes `composer install` and the npm build, so every deploy serves 503s for minutes, not
  seconds. A failed build, `migrate`, permissions step or preflight now leaves the site in
  maintenance until someone acts. That is the intended trade: "do not serve traffic" now means
  what it says.
- Rolling code back past this release requires rolling the data back with it (§2i). A
  pre-conversion backup must be migrated on a host in production's zone (§2h). Console sessions
  must `SET time_zone = '+00:00'` (§5b).
- The developer database `risk` converts from its own global zone on the next `migrate`.
  Pending data migrations in that same uncached run write under the new pin, so their rows are
  converted again and come out one offset late. That affects only `risk`, and only if such a
  migration is pending. Re-seeding is the remedy and is optional.
