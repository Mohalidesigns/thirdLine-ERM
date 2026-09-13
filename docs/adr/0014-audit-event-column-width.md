# ADR 0014 — The audit column that was too narrow to hold the audit

**Status:** Accepted · **Date:** 2026-09-09 · **Phase:** post-merge, `integration/tprm-bcms` · **Author:** backend (defect fix)
**Requested by:** qa-engineer gate 1, defect 2 · **Broadcast to:** all tracks

## Context

`bcms_audit_logs.event` was declared `string('event', 20)` in
`2026_09_09_120007_create_bcms_supporting_tables_and_deferred_keys.php:191`,
with the comment `created|updated|deleted|restored`. Those four are what
`BcmsAuditable` writes from Eloquent's model hooks, and 20 characters holds them
comfortably.

They are not the only events BCMS writes. `recordAudit()` is called by hand
throughout the module, and there are **twenty-two distinct names**. Twelve do
not fit:

| Event | Chars | | Event | Chars |
|---|---|---|---|---|
| `contact.repaired_after_cascade` | 30 | | `call_tree_test.aborted` | 22 |
| `call_tree_test.unannounced` | 26 | | `alert.dispatch_refused` | 22 |
| `call_tree.hygiene_flagged` | 25 | | `call_tree.superseded` | 20 |
| `call_tree.deputy_assigned` | 25 | | `call_tree.reparented` | 20 |
| `call_tree_test.initiated` | 24 | | `template.deactivated` | 20 |
| `call_tree_test.completed` | 24 | | `reminder_ladder_rebuilt` | 23 |

On MariaDB, with Laravel's `strict => true` (`STRICT_TRANS_TABLES`), that insert
raises `SQLSTATE[22001] 1406 Data too long`.

**And nothing failed.** `BcmsAuditable::writeBcmsAuditRow()` catches `Throwable`
deliberately — the comment is explicit that auditing must never fail the
business write — logs an error, and returns. The state change succeeded; the
record of it did not exist; no exception reached the caller.

Three defects had to line up, and all three were reasonable on their own:

1. a column sized for the events its author had seen,
2. a catch-all that is *correct* — a failed audit write genuinely should not
   roll back a plan activation or an alert dispatch,
3. a test database (SQLite) that enforces no VARCHAR length at all.

Remove any one and this surfaces immediately. Together they made a GRC module
lose audit rows in silence, on the only database a customer runs, for as long as
BCMS has existed. It went unnoticed through Phases 0–7 and both prior gates.

This breaches **Definition of Done item 4** — "every state change writes an
activity-log entry" — in the module whose purpose is to be defensible at an
examination. An examiner asking "who suppressed this cascade" would have been
told nothing, and the product would not have known it was not telling them.

Found by `qa-engineer` at gate 1 as the root cause of three failures previously
recorded as "undiagnosed": `Phase5CountdownTest:600`, `Phase6CallTreeTest:444`
and `:471`. All three assert on audit rows that were never written.

## Decision

`bcms_audit_logs.event` becomes `varchar(60)`
(`2026_09_16_120001_widen_bcms_audit_log_event_column.php`).

**60, matching `tp_audit_logs.event`** — the same kind of column holding the
same kind of value in the sibling module, which has never overflowed. This is
precedent inside the product rather than a number chosen for this defect.

**Not 30.** Sizing a column to its current longest value guarantees the next
event name breaks it, and the estate already paid for this mistake once.

Widening needs no data migration: every value that fit in 20 fits in 60.

`down()` restores the 20-character declaration and does nothing else. It will
fail on a database that has since written a longer event, and that failure is
correct — rolling this back means discarding audit rows, which is not a thing a
migration should do quietly.

## What this ADR deliberately does not change

**The catch-all in `writeBcmsAuditRow()` stays.** It is right. A business
operation must not fail because its audit row could not be written, and the
alternative — letting the exception through — would trade silent audit loss for
loud data loss. The problem was never the catch; it was that nothing else was
watching.

**No change to any caller, and none to the event names.** All twenty-two were
correct all along; the column was wrong.

The trait and the model did change, but not in their behaviour: `AuditLog` gains
the cache key as a constant (a trait constant cannot be read through the trait's
own name, and both the writer and the watchdog need it), and
`writeBcmsAuditRow()` increments it inside the catch it already had. No caller
sees a difference, and a write that previously succeeded still succeeds.

## What replaces the silence

`tests/Feature/Bcms/BcmsAuditEventWidthTest.php` reads every event name out of
`app/` — parsing the first argument of each `recordAudit()` call, so it catches
the ternary in `AlertTemplateController` that a naive regex misses — and asserts
each fits the live column width read from `information_schema`.

It also asserts the database really does reject an oversized value — **by
SQLSTATE, 22001, not by exception class**. An earlier version asserted only
`QueryException`, and gate 2 showed by control experiment that the same insert
with a valid short event throws `QueryException` too (23000, a foreign key
violation). The test could not tell "the width was enforced" from "the fixture
was wrong", and on a non-strict server it would have passed while values
truncated silently. It now also inserts a value that fits, so a fixture that
could never insert for any reason cannot satisfy it either.

It fails if the scan itself stops finding names. A tripwire that can silently
match nothing is the same class of bug as the one it is here to prevent.

The day someone adds a longer event name, this fails loudly instead of the audit
trail going quiet.

**That covers ONE cause. It is not the whole silence, and this ADR said
otherwise in its first version.** The column width is now guarded in CI, against
the test database. In production the `catch` still swallows, and a lock timeout,
a full disk, a value that will not encode, or a future migration narrowing any
column on this table reproduces the original defect exactly — undetected, in the
module whose deliverable is the log.

So `BcmsWatchdog` gained a fourth signal. `writeBcmsAuditRow()` now increments
`AuditLog::AUDIT_FAILURE_CACHE_KEY` in its catch — wrapped again, because a
broken cache must not fail a business write either — and the watchdog reports
the count, exits non-zero, and clears it so the next run reports only new
failures. A count that never resets stops carrying information the day after it
first fires.

The catch is still not removed, and still should not be. What changed is that
failing is no longer indistinguishable from working.

## Consequences

- One structural migration against the frozen schema. Widening only; no
  behavioural or contract change; no track needs to rebuild against it.
- Three previously-unexplained BCMS test failures should resolve. They were
  never test bugs.
- Any BCMS audit row that should have been written on MariaDB before this
  migration **was lost and is not recoverable**. On the development databases
  that is noise. If BCMS has run against any customer or pilot database, the
  audit trail there has holes for the twelve events above, and that is a
  disclosure question rather than a technical one.
- The watchdog signal counts failures; it does not reconstruct the rows. A run
  that reports `3 audit rows could not be written` tells an operator the trail
  has three holes, not what belonged in them. Recovering the content would mean
  writing the audit row somewhere that cannot fail before attempting the
  database, which is a larger design change than this defect warranted.
- **The freeze guard cannot see this class of defect.**
  `database/schema/bcms-manifest.php` records column NAMES only, so widening
  `event` from 20 to 60 produces no manifest diff — and neither would narrowing
  it back. That is how a 20-character `event` passed the Phase 0 freeze in the
  first place. Recording types alongside names would close it; that is its own
  decision and is not made here.
