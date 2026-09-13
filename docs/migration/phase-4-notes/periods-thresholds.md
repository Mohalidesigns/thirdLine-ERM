# Phase 4.2 — Reporting periods and threshold re-baselining

`Risk/PeriodController` (174 lines) and `Risk/ThresholdController` (83 lines),
with `risk/periods/index.blade.php` and `risk/thresholds/rebaseline.blade.php`.

Both controllers arrived from WP-04 already service-backed — `PeriodService` and
`ThresholdRebaselineService` own the work — so this module is a policy, four
Form Requests and two pages rather than an extraction.

## What landed

| Piece | Where |
|---|---|
| Policies | `app/Policies/PeriodPolicy.php`, `app/Policies/MeasureThresholdPolicy.php` |
| Form Requests | `app/Http/Requests/Periods/ReopenPeriodRequest.php`, `app/Http/Requests/Thresholds/{Approve,Reject}RebaselineRequest.php` |
| Pages | `resources/js/Pages/Periods/Index.jsx`, `resources/js/Pages/Thresholds/Rebaseline.jsx` |
| Tests | `tests/Feature/Periods/PeriodAndThresholdPagesTest.php` |

`Ported::ROUTES` 72 → 74. `risk.periods.select` is not a page — it sets the
session's period and redirects — so it is not registered.

## Approving a re-baselining is a CLASS-level ability

`rebaselineApprove` lives on `MeasureThresholdPolicy` and takes no instance,
which looks odd until you see what the alternative resolves to.

The thing being approved is stored as an **`ApprovalRequest`** row, and
`ApprovalRequestPolicy` already owns `approve` / `reject` for the approvals
module (Phase 3.7). Writing `can('approve', $approvalRequest)` here would land
there and ask for **`approval.act`** — not `threshold.rebaseline_approve`, which
is what the route requires. A user with the approvals permission would have been
able to re-baseline a bank's limits, and a user with the threshold permission
would have been refused.

This is the same trap 4.1 hit from the other direction, where a breach ability
was written onto the KRI's policy and was never reached at all. **Laravel
resolves a policy from the subject's class**, so the subject decides which
policy answers — and when two modules act on the same model, only one of them
can own the instance abilities.

The question this screen actually asks is "may this user re-baseline
thresholds", which needs no instance. That the specific request belongs to the
caller's tenant and really is a re-baselining stays a guard in the controller,
where the wrong action is a **404** rather than a permission answer — the id
addresses a request that exists, but this route does not decide other kinds.

## Close and reopen are separate permissions

Carried across from the routes, and worth stating because it is easy to collapse
them: `period.close` does not imply `period.reopen`. The seeder gives the
standard risk-manager role the first and not the second. Reopening is the one
operation that can change a number a board pack has already been built on, so
the mandatory ten-character reason is kept, now enforced in one place
(`ReopenPeriodRequest`) instead of an Alpine handler, an `alert()` and a server
rule that disagreed about the message.

Closing gets a real `ConfirmDialog` that names what will happen — how many
values lock, and that formula thresholds are re-evaluated afterwards — rather
than the Blade table's `onsubmit="return confirm(...)"`.

## A badge that never rendered

`risk/periods/index.blade.php` drew a "selected" badge from `$selectedPeriod`,
**which the controller never passed**, so it never appeared. The selected period
is the one bound to the session, which is what "selected" means in the period
selector at the top of every other screen; it comes from `PeriodContext` now and
`the_selected_period_is_marked` pins it.

## Carried across deliberately unchanged

- **`risk.periods.select` stays a redirect action**, including its open-redirect
  guard: a target is followed only when it is a path on this application, never
  an absolute URL from the query string.
- **Re-baselining still runs at period close**, because that is the moment the
  denominators — capital, revenue, CPI — are final. It raises approval tasks and
  does not move a limit on its own.
- **Approving writes a NEW effective-dated band set** and retains the one in
  force, so breaches already recorded keep reading against the limit that
  applied when they happened.
- **"No prior bound" is not zero.** A change whose `in_force` is null prints
  "unset" and its relative change prints "no prior bound", rather than
  formatting null as `0.00` and inventing a percentage against it.

## PHPStan

Relations typed with generics on `Period` (`closedBy`), `MeasureThreshold`
(`measure`) and `Measure` (`unit` — the related model is `Unit`, not
`MeasureUnit`, which the type-checker caught immediately). Baseline is **24
lines shorter and gains nothing**.
