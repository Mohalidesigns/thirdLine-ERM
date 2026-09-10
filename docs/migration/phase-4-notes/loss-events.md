# Phase 4.3 — Loss events, near misses and RCA

`Risk/LossEventController` (979 lines, 24 methods) and eight Blade views (2,673
lines) onto Inertia. The largest module in the phase, landed in three commits:
the service extraction and the threshold fix, then the policies, then the views.

## What landed

| Piece | Where |
|---|---|
| Policies | `app/Policies/LossEventPolicy.php`, `app/Policies/NearMissPolicy.php` |
| Form Requests | `app/Http/Requests/LossEvents/` — six |
| Services | `app/Services/LossEvents/LossEventService.php`, `LossEventDashboardService.php` |
| Pages | `resources/js/Pages/LossEvents/{Dashboard,Create,Edit,Show,Approvals,Rca,Reports,CreateNearMiss}.jsx`, plus `LossEventForm`, `StepNav`, `format.js` |
| Characterisation | `Characterisation/LossEventStoreCharacterisationTest.php`, `LossEventDashboardFiguresTest.php` |
| Module tests | `tests/Feature/LossEvents/{LossEventPagesTest,LossEventPolicyTest,LossEventThresholdEvaluationTest}.php` |

`Ported::ROUTES` 74 → 82.

## The regulatory thresholds were evaluated twice per create

`store()` dispatched `LossEventCreated` — whose `EvaluateRegulatoryThresholds`
listener evaluates the whole Nigerian threshold set — and then constructed a
second `RegulatoryThresholdService` by hand and evaluated it all again. Nothing
detected it because both passes agree; the cost was every reported loss doing
the work twice, from two places that could drift.

The listener is kept, the inline call is gone, and a spy pins the count at one.

**Removing it would have quietly cost the reporter something real.** The inline
call's result was flashed as `regulatory_alerts` — how somebody reporting a loss
learns that CBN notification is due within seven days. An existing test caught
it. The listener now RETURNS what it computed and the service carries it back,
so the alert survives with one evaluation instead of two.

## The reportability threshold, and a disagreement it exposes

The literal `10000000` in `store()` is now
`config('risk.regulatory_reportable_threshold_ngn')`, resolved through
`RiskCalculationSettings` like every other tunable risk input and overridable
per organisation.

**It does not agree with `RegulatoryThresholdService::CBN_REPORTING_THRESHOLD_KOBO`,
which is NGN 5,000,000.** An event of NGN 6m raises a CBN alert saying
notification is required within seven days AND is left with
`is_regulatory_reportable = false`. That disagreement is older than this
refactor. It is a compliance policy call, not a refactor, so it is surfaced in
the config file and here rather than quietly aligned.

## `MONTH()` hid the dashboard from the test suite

The monthly trend used `MONTH(date_of_loss)` in a raw select. MySQL-only — so
the loss-event dashboard **threw on the SQLite the suite runs on and had never
been covered by a test at all**. This is the third instance of the pattern
(3.8's RCSA used `FIELD()`, 4.1's KRI dashboard did too). Portable now, which is
what made `LossEventDashboardFiguresTest` possible.

## Dead reads

- **`$e->reference` on the regulatory alert panel.** Neither a column on
  `loss_events` (it is `event_reference`) nor an accessor, so the
  `'LE-'.$e->id` fallback fired **every time** and the panel printed a string
  matching nothing a user can search for. `the_regulatory_alerts_use_the_real_event_reference`
  pins the real one.
- **The reports page loaded every loss event in the tenant** to render a screen
  that used none of the rows beyond counting them. It counts now.
- **The approvals queue supported a severity filter with no control to set it.**
  The controller read `$request->severity`; the Blade page rendered no select.
  It is reachable now, and pinned.

## Deliberate carry-overs

- **The two column sets stay in step.** `loss_events` carries the originals and
  the 200038 duplicates the forms post, and the mapping decides whether the CBN,
  NFIU and EFCC alerts fire — WP-01 found the fraud alerts had never fired
  because the category was written lower case while
  `RegulatoryThresholdService` matched upper. `LossEventStoreCharacterisationTest`
  pins the whole stored row, both sets, and still passes byte-identically after
  the extraction.
- **A near miss is zeroed and reclassified** whatever the form said, before the
  mapping runs, so every downstream figure agrees.
- **The four outstanding-work tiles are not year-bounded.** A notification owed
  from last December is still owed.
- **`pending` on the RCA screen counts events with NO analysis**, not analyses in
  a pending state — which is why those four counts do not sum. That is the
  figure the screen exists to surface.
- **Reporter and near-miss flag are not editable**, exactly as before: `update()`
  never accepted either. They are facts about what happened.

## Deviations

**The three-step create wizard is component state, not a query parameter.** The
Blade page drove it with `goToStep()` and a `step` parameter the controller read
and used only to highlight a circle. The steps are kept — the form is long
enough to want them — but changing tab no longer round-trips, the running net
loss updates as the numbers are typed, and a validation error lands the reporter
on the first step that has one rather than leaving them on step 3 wondering.

**`uploadAttachment` keeps its inline validation.** Its rules come from
`FileUploadService::rules()` at call time; a Form Request would have to resolve
the service to build them, which buys nothing here. It has no `exists:` rule, so
criterion 3 is unaffected.

## Authorization

`LossEventPolicy` absorbs `approve-loss-event` — **the last inline
`Gate::define` a risk module owned**. Only `view-grid` (Phase 2's grid guard)
remains in `AppServiceProvider`. It keeps its hyphenated name, because
`WorkflowEngine::canAct()` asks for it through `LossEventBinding::gate()`.

Sixteen hand-written tenancy guards became `Gate::authorize` calls. Writing an
RCA and signing it off are now separate abilities (`loss_event.edit` vs
`loss_event.approve`) — an analysis approved by whoever wrote it is not an
approval, and the old code let one permission do both.

`NearMissPolicy` rides on the loss-event permissions rather than inventing a
`near_miss.*` set the seeder has never issued. `NearMiss` has no node column, so
its reach is the tenant only.

## Noted, not fixed

`NearMiss::$status` is written lower-case `'open'` by `storeNearMiss()` while
the column defaults to `'OPEN'`, and the near-miss KPI queries
`where('status', 'open')`. On MySQL's case-insensitive collation both match; on
SQLite only the lower-case rows do. That screen is a Phase 2 grid page and out of
this module's scope, but the mixed case in one column is worth normalising.

## PHPStan

Relations typed with generics on `LossEvent` (seven), `LossEventControl`,
`LossEventApproval` and `GeneratedReport`; `@property` blocks for the 200038
columns on `LossEvent`, `LossEventRca` and `LossEventAttachment`. The Basel
breakdown moved to the query builder — a grouped aggregate returns aliases, and
hydrating them as `LossEvent` rows would claim they are events.

Baseline is **54 lines shorter and gains nothing** for this module.
