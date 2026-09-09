# ADR 0013 — Phase 6 asks for one column, and refuses a status value it was offered

**Status:** accepted · **Date:** 2026-09-08 · **Phase:** 6 (Call Tree Management)
**Supersedes nothing. Amends the G0 freeze recorded in ADR 0002.**

## Context

Phase 0 froze the call-tree schema well. Four tables, sixty-one columns, and the
two that matter most — `bcms_call_tree_test_nodes.downstream_blocked_count` and
the four `_snapshot` columns beside it — were specified before anybody had
written a line of the cascade engine. Phase 6 needed neither a new table nor a
rethink of any of them.

It needed one column, and it needed to decline one value the migration's own
comment suggested.

## Decision 1 — `bcms_call_trees.supersedes_call_tree_id`

Acceptance criterion 2 requires that an approved tree becomes immutable and that
editing it produces v2. That is the same rule Phase 1 applied to the BC policy
and Phase 3 applied to plans, and both are implemented the same way: the new
version is a new row carrying `supersedes_plan_id`, and the chain is the history.

`bcms_call_trees` has `version` but nothing to point back with. Without a link,
`GET /call-trees/{id}/versions` has to reconstruct the chain from an identity
tuple — organisation, business unit, site, type and name — which is wrong the
first time somebody renames a tree. A department that becomes "Retail Operations"
would lose the four years of test history that proves it was cascaded.

So: one nullable self-referencing foreign key, `nullOnDelete`, mirroring
`bcms_plans.supersedes_plan_id` exactly. The mirror is the argument. A second
mechanism for the same idea in the same module is how two screens end up
disagreeing about what version 3 replaced.

**Rejected:** a `bcms_call_tree_versions` table holding a JSON snapshot of each
approved tree. It duplicates the node rows, and the node rows are what a test
snapshot already points at; a test that referenced a node in one table and a
version in another would need both to agree for ever.

## Decision 2 — `stale` is computed, not stored

The Phase 0 migration comments `status` as `draft|approved|stale|archived` and
says, correctly, that "`stale` is a status the system writes, not one a user
picks". Phase 6 keeps the sentence and rejects the column value, for two reasons.

**It destroys the governance fact.** An approved tree that goes stale would stop
reading as approved. The head of department's approval is a thing that happened;
a date passing does not un-happen it, and an examiner asking "who approved the
roster you cascaded in March" needs the answer to survive July.

**It makes overdue depend on a job.** A tree reviewed on 1 January with a
180-day cycle is overdue on 30 June whether or not the scheduler woke up. A
`status` column is only as true as its last write, and a dashboard that reports
health from one is reporting the health of the scheduler.

`CallTreeService::isStale()` computes it from `last_reviewed_at +
review_frequency_days`, and `staleQuery()` expresses the same rule in SQL so the
list and the badge cannot drift apart. `CallTreeStatus` therefore has three
cases, and its docblock carries this reasoning where somebody adding a fourth
will read it.

## Decision 3 — the cascade writes no `bcms_notification_deliveries` rows

Standing rule 8 requires the record to be written before the provider is called.
The cascade obeys it against `bcms_call_tree_test_nodes`, which Phase 0 built for
exactly this: `contacted_at`, `attempts`, `channel_used` and `outcome` are the
write-ahead row, and they are written before `NotificationChannel::send()`.

`bcms_notification_deliveries` has `alert_id` and `reminder_schedule_id` and no
column for a cascade. Adding a third would be a fourth place a delivery can hang
from, and it would duplicate a record that already exists per node per test.
When Phase 7 makes the call tree an EMNS audience type, a cascade dispatched
*through an alert* will write delivery rows against `alert_id` like any other
alert — that is the right home for it, and it arrives with the alert, not before.

## Consequences

- `bcms:verify-schema` names one added column; the manifest is regenerated in the
  same commit as the migration.
- The G0 freeze now stands at 15 (P1) → 8 (P2) → 5 (P3) → 1 (P4) → 0 (P5) → 1 (P6).
- Anyone adding `stale` to `CallTreeStatus` has to argue against this file first.
