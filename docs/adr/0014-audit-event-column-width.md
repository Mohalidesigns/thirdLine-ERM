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

**No change to the trait, the model, or any caller.** Twelve event names were
correct all along. The column was wrong.

## What replaces the silence

`tests/Feature/Bcms/BcmsAuditEventWidthTest.php` reads every event name out of
`app/` — parsing the first argument of each `recordAudit()` call, so it catches
the ternary in `AlertTemplateController` that a naive regex misses — and asserts
each fits the live column width read from `information_schema`.

It also asserts the database really does reject an oversized value, so the guard
is proven to be guarding something, and it fails if the scan itself stops
finding names. A tripwire that can silently match nothing is the same class of
bug as the one it is here to prevent.

The day someone adds a longer event name, this fails loudly instead of the audit
trail going quiet.

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
