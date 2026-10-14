# ADR 0027 — A backfilled KRI breach resolves at the end of its period's last day, the rows already written are repaired once, and an impossible interval is absent from MTTR

**Status:** Accepted · **Date:** 2026-10-05 · **Phase:** post-go-live hardening (`fix/post-golive-hardening`), ERM WP-04 (measure engine, KRI backfill) · **Author:** architect
**Requested by:** the coordinator, post-go-live defect 2 (backfilled KRI breaches resolved before they breached)
**Consumers:** backend-engineer **(lead: one migrator change, one repair migration, one model change, tests)** · qa-engineer · code-reviewer · reliability-engineer (read-only VPS checks; the repair runs on deploy) · ERM / KRI owner (breach register, MTTR on `Kri/Breaches`) · compliance-analyst (§5: whether reconstructed breaches belong in MTTR at all)

## Context

`KriMeasureMigrator::writeHistoricBreach()` (line 559) reconstructs a breach from a
`kri_breach` notification. It marks the breach resolved with:

```php
'resolved_at' => Period::withoutGlobalScopes()->whereKey($periodId)->value('end_date') ?? $breachedAt,
```

`Builder::value()` applies the model's casts, and `Period::end_date` is cast as `date`. The
value is therefore **00:00 at the start of the period's last day**. This is
DEVELOPMENT_STANDARD §14's defect in a new place. The period is the month that
`PeriodService::resolve()` finds for `breached_at`, so every notification sent on the last
day of a month produces a breach that resolved before it began. I reproduced this with
Carbon 3.13.2, as locked in `composer.lock`. `diffInMinutes()` is signed in Carbon 3:

| `breached_at` | `resolved_at` written | `hoursToResolve()` |
|---|---|---|
| 2026-08-31 15:00:00 | 2026-08-31 00:00:00 | **−15.0** |
| 2026-08-31 15:00:00 | 2026-08-31 23:59:59 (this ADR) | 9.0 |

`meanTimeToResolveHours()` sums signed minutes, so each such row pulls the MTTR that
`KriController::breaches()` puts on the breach register (`mttrDays`) down. The register's
"Days in Breach" column wraps the value in `abs()` (`KriBreachesGrid:108`), which hides the
sign and shows the number as if it were valid.

The existing test, `KriMeasureEngineTest::the_migrator_moves_legacy_kris_and_their_history_into_the_engine`,
already uses a notification on the last day of a month (2026-03-31 07:00). It never asserts
`resolved_at`, so it has been passing over a −7.0-hour breach.

**The migration has run.** `2026_08_12_120004_migrate_kris_into_the_measure_engine` ran in
production on 2026-10-04, and on developer and CI databases. The migrator is idempotent: it
calls `firstOrNew` and returns early if the row exists. Fixing the migrator therefore changes
nothing that is already stored. Rows already written need a repair.

### What identifies a migrator row

Only `writeHistoricBreach()` writes this exact `note`:

> Reconstructed from the notification log during the WP-04 measure-engine migration. The pre-WP-04 platform kept no breach register, so acknowledgement and resolution times are not recoverable for this row.

The note alone is not enough. A migrator row can later be changed by the engine or by a
person, and the note survives that change:

- **The engine reopens it.** `MeasureService::reconcileBreach()` (lines 370–376) finds the
  row by its natural key. If it is resolved, the engine reopens it: `status` becomes open,
  `resolved_at` becomes null, and `breached_at` becomes the new reading's time.
  `resolveOpenBreaches()` later resolves it at `$value->entered_at`, and an imported reading
  can carry exactly midnight.
- **A person edits it.** `acknowledgeBreach` and `resolveBreach` keep the existing `note`
  when no new one is given.

Each of those writes touches `updated_at`. Eloquent saves set it, and both query-builder
resolvers set it explicitly. A migrator row that has never been written since the backfill
still has `updated_at = created_at`, because `firstOrNew()->fill()->save()` stamps both from
one clock read.

## Decision

### 1. The migrator: end of the period's last day, never earlier than the breach

```php
'resolved_at' => $this->reconstructedResolution($periodId, $breachedAt),
```

| Case | `resolved_at` |
|---|---|
| The period has an `end_date` | `max(endOfDay(end_date) in the app time zone, truncated to the second, breached_at)` |
| No `end_date` (the existing fallback) | `breached_at` (unchanged) |

- **Truncated to the second** (`->endOfDay()->startOfSecond()`). Eloquent stores
  `Y-m-d H:i:s`, so the stored value is 23:59:59 in any case. Truncating keeps the in-memory
  model equal to a fresh read, and keeps a fractional value away from `TIMESTAMP(0)`, where
  MySQL **rounds** `23:59:59.999999` up to the next day's midnight and MariaDB truncates it
  (ADR 0025 §2).
- **`max(…, breached_at)`** is a guard, not a case that happens today. `breached_at` always
  falls inside its own month. The guard means that no future change of how periods are
  resolved can write a negative interval.
- **The note text does not change.** It moves into `public const RECONSTRUCTED_NOTE` on the
  migrator, byte for byte. A test pins it (§4.4), because rewording it would orphan every row
  this ADR and its follow-ups identify by it.
- **End of period, not something more "accurate".** `resolved_at` on a reconstructed row
  was never a measurement, and the note says so. The end of the period is the latest moment
  the backfill can stand behind. It is also the convention the first author intended, minus
  the off-by-one.

### 2. The repair: one migration, exactly the rows the old migrator wrote

New: `database/migrations/2026_10_05_100001_repair_backfilled_kri_breaches_resolved_before_they_breached.php`.

**A row is repaired only when all of these hold.** The first five are filtered in SQL, and
the sixth in PHP:

| # | Predicate | Why |
|---|---|---|
| 1 | `note = <the literal>` (equality, not `LIKE`) | Only the migrator writes it. |
| 2 | `status = 'resolved'` | A reopened row is not a backfill any more. |
| 3 | `acknowledged_at IS NULL` | The migrator never acknowledges. |
| 4 | `resolved_at IS NOT NULL` | |
| 5 | `updated_at = created_at` (`whereColumn`) | **Nothing has written the row since the backfill**, neither the engine nor a person. |
| 6 | the raw `resolved_at` string `=== end_date . ' 00:00:00'` | **It holds exactly what the old migrator wrote.** This also makes the repair idempotent. |

**The new value** is the one §1 would have written:
`max(DateBounds::endOfDay(end_date), (string) breached_at)`. Both sides are
`Y-m-d H:i:s` strings, so a string comparison is a chronological one. The update is
`DB::table('measure_breaches')->where('id', $id)->where('resolved_at', $old)->update(['resolved_at' => $new])`.
It does **not** touch `updated_at` and does **not** change `note`.

**The acceptance criterion is equivalence:** after the repair, a database migrated by the
old migrator holds exactly what one migrated by the new migrator holds. That is also why the
**mid-month** rows are repaired, and not only the negative ones. A row backfilled on
2026-08-03 also says "resolved 2026-08-31 00:00", and a fresh install would say 23:59:59.
Leaving it would give the estate two conventions for the same reconstruction.
`updated_at` stays untouched for the same reason: a fresh backfill has
`updated_at = created_at`.

**Cross-engine rules, each deliberate:**

- **Only the query builder is used:** a join, equality, `whereNull`, `whereColumn` and
  `chunkById('b.id', 'id')`. There is no JSON function, CTE or window function. There is no
  `UPDATE … WHERE id IN (SELECT … FROM measure_breaches)`, which MySQL refuses with error
  1093. Each update targets one id. No model is saved, so no observer fires during the
  migration. That matters on an upgrade, where `2026_10_05_*` runs inside the migration
  window (02cf6df).
- **The time comparison runs in PHP, on strings the session returned.** `resolved_at` is a
  `TIMESTAMP` and `breached_at` a `DATETIME`. Comparing them in SQL works on both engines,
  but only while the session time zone is the one the row was written under. Comparing the
  returned strings makes the predicate explicit. **If the server's zone has changed since
  the backfill** (ADR 0025's residual), predicate 6 fails, the row is **skipped and not
  mis-repaired**, and the log counts it.
- **The literal is copied into the migration** as `const NOTE`, and the migration does not
  read `KriMeasureMigrator::RECONSTRUCTED_NOTE`. A migration is a snapshot, so a later edit
  to application code must not change what it matches. A test asserts the two are equal.

**It logs, as `2026_08_12_120010` does:** `info('ADR 0027 repair: …', [candidates, repaired, skipped_touched, skipped_not_period_midnight, negative_remaining])`.
`negative_remaining` is the count of note-bearing rows still holding `resolved_at < breached_at`
after the run, compared in PHP. These are rows the guards refused, and §3 keeps them out of
MTTR.

**`down()` is a no-op**, with a comment. The values it would restore are the defect.

### 3. Readers: an impossible interval is absent, not zero

`hoursToResolve()`, `hoursToAcknowledge()` and `meanTimeToResolveHours()` treat an interval
whose end is **before** its start as **unmeasurable**. The per-row methods return `null`, and
the mean **excludes** the row from both its numerator and its denominator. If no measurable
row is left, the mean is `null`.

**Not a clamp to zero.** Clamping turns an impossible value into a plausible one ("resolved
the moment it breached") and lowers the mean. That is a figure that is neither computed nor
absent, which DEVELOPMENT_STANDARD §5 forbids.

**Why a reader guard at all, when the data is repaired.** The repair covers the rows the
migrator wrote. It cannot cover the next source of the same shape, and two are already
visible:

- `resolved_at` and `acknowledged_at` are `TIMESTAMP` columns, read through the session time
  zone. `breached_at` is a `DATETIME`, which is not. A change of server time zone moves one
  against the other (ADR 0025's residual).
- `MeasureService::reconcileBreach()` reopens a breach with a new `breached_at` and leaves the
  old `acknowledged_at` in place. `hoursToAcknowledge()` on a reopened breach is negative
  today. The method has no screen reader yet, but it is public.

The guard is one private helper, `hoursBetween(?from, ?to): ?float`, which all three methods
use.

### 4. Tests

1. **Amend `KriMeasureEngineTest::the_migrator_moves_legacy_kris_and_their_history_into_the_engine`.**
   Assert that the breach's `resolved_at` is `2026-03-31 23:59:59` and that
   `hoursToResolve()` is `17.0`. **This fails on today's code (−7.0) on both legs.** Add a
   mid-month notification (a second KRI, `2026-02-10 10:00:00`, amber) and assert
   `2026-02-28 23:59:59`.
2. **New: `tests/Feature/Measures/RepairBackfilledBreachResolutionTest.php`.** Insert
   fixture rows with `DB::table()`, exactly as the old migrator wrote them. Create the August
   2026 period through `PeriodService::resolve()`. Run the migration through `require
   database_path(…)` (the `CanonicalColumnBackfillTest` pattern).

   | Row | Fixture | After `up()` |
   |---|---|---|
   | a | note, resolved, breached `2026-08-31 15:00:00`, resolved `2026-08-31 00:00:00`, `updated_at = created_at` | resolved `2026-08-31 23:59:59` |
   | b | as (a), breached `2026-08-03 10:00:00` | resolved `2026-08-31 23:59:59` |
   | c | as (a), `updated_at` one minute after `created_at` | **unchanged** |
   | d | `note` null (a real breach), resolved `2026-08-31 00:00:00` | **unchanged** |
   | e | note, `status = open`, `resolved_at` null | **unchanged** |
   | f | note, already `2026-08-31 23:59:59` | **unchanged** |

   Also assert:
   - `updated_at` is unchanged on (a) and (b);
   - a second `up()` changes nothing (compare a full snapshot of the table);
   - **equivalence:** the corrected migrator, run on a notification identical to (a) in a
     second organisation, writes the same `resolved_at` as the repaired (a);
   - every row in the table has `hoursToResolve() === null || >= 0`.
3. **Reader guard** (in `KriMeasureEngineTest`, or its own file). Raw-insert a resolved row
   with `resolved_at` before `breached_at`. Assert `hoursToResolve()` is null. Assert that
   `meanTimeToResolveHours()` over that row plus a 48-hour row is `48.0`, and over that row
   alone is `null`. Do the same for `hoursToAcknowledge()`. The existing assertions (48.0 and
   24.0) stay green.
4. **Pin the note.** `KriMeasureMigrator::RECONSTRUCTED_NOTE` must equal the migration's
   `NOTE`, and both must `assertSame` the historic literal quoted in Context.

### 5. Read-only checks on the VPS (reliability-engineer, before deploy)

```sql
SELECT COUNT(*)                                             AS reconstructed,
       SUM(b.resolved_at < b.breached_at)                   AS resolved_before_breached,
       SUM(b.updated_at <> b.created_at)                    AS touched_since_backfill,
       SUM(b.resolved_at = CONCAT(p.end_date, ' 00:00:00')) AS at_period_midnight
  FROM measure_breaches b
  JOIN periods p ON p.id = b.period_id
 WHERE b.note LIKE 'Reconstructed from the notification log during the WP-04%';
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone;
```

Record the numbers in the PR. After the deploy, the repair's `info` line must show
`repaired = at_period_midnight - touched_since_backfill` (to within rows touched in between)
and `negative_remaining = 0`. Bring any other result to the architect.

## Alternatives rejected

| Option | Verdict |
|---|---|
| **Clamp negatives to zero in the readers, and repair nothing** | **Rejected.** It invents "resolved instantly" (§3) and leaves the stored rows wrong for every other reader, the grid's `abs()` included. |
| **Set `resolved_at = breached_at`** (a zero-length breach) | **Rejected.** It asserts that each historic breach cleared the moment it was notified, which is as invented as the end of the period, and less defensible: the KRI was still in breach at month end as far as anyone can tell. It would also differ from what §1 writes on a fresh install. |
| **Null `resolved_at` and keep `status = resolved`** | **Rejected.** `meanTimeToResolveHours()` already skips nulls, but a resolved breach with no resolution time breaks the register's own lifecycle invariant. It is also a third convention. |
| **Identify migrator rows by `band_from IS NULL AND acknowledged_at IS NULL`** | **Rejected.** A real first-ever breach has `band_from` null too. Only the note is unique to the migrator, and only `updated_at = created_at` proves the row is untouched. |
| **Repair in raw SQL** (`UPDATE … JOIN periods … SET resolved_at = TIMESTAMP(p.end_date, '23:59:59')`) | **Rejected.** It cannot express "never earlier than `breached_at`" and "exactly the old value" without engine-specific functions, and it compares `TIMESTAMP` with `DATETIME` under whatever zone the session holds. The PHP-side predicate is explicit and portable. |
| **Add a `source` / `is_reconstructed` column** | **Rejected.** It is a structural migration after the freeze to identify rows the note and `created_at = updated_at` already identify. If §5's MTTR question below is answered "exclude", ask again then, with that phase named. |
| **Bump `updated_at` on repaired rows** | **Rejected.** A fresh backfill has `updated_at = created_at`, and equivalence is the acceptance criterion. The `info` log records the run. |

## What this ADR deliberately does not do

- **It does not decide whether reconstructed breaches belong in MTTR at all.** Their
  `resolved_at` is a placeholder, and the note says the resolution time is not recoverable.
  Even repaired, each one adds "time from notification to month end" to a figure on the
  breach register. That distortion predates this defect and is not what was reported.
  Excluding these rows changes a board-facing number for every organisation that has
  historic notifications. **Booked to the ERM/KRI owner with compliance-analyst**, with the
  recommended direction: exclude rows matching `RECONSTRUCTED_NOTE` with
  `updated_at = created_at`, with no new column.
- **It does not change `MeasureService`'s reopen semantics.** Whether a reopened breach should
  clear its old acknowledgement is booked to the KRI owner. §3 only stops the stale value
  producing a negative interval.
- It does not touch `breached_at`, `note`, `status` or any acknowledgement column on any
  row. It does not repair a row anything has written since the backfill.
- It does not change the grid's `abs()`. With §1 and §2 in place, no migrator row reaches
  it negative.
- It requests no schema change.

## Consequences

- No reconstructed breach resolves before it breached, on a fresh install or on an upgraded
  one. Both estates hold one convention.
- Rows edited since the backfill are left as their editors left them. Any of them that are
  still negative are counted in the deploy log and kept out of MTTR by §3.
- MTTR can no longer be pulled down by an impossible interval from any source.
- The note becomes a pinned identifier. Rewording it is now a test failure that points here.
