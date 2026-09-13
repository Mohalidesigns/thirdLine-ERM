# Phase 4.1 — Key risk indicators

`Risk/KriController` (645 lines) and five Blade views (799 lines) onto Inertia.
`risk.kri.index` and `risk.kri.breaches` were already Inertia pages from Phase
2's grid work.

## What landed

| Piece | Where |
|---|---|
| Policies | `app/Policies/KeyRiskIndicatorPolicy.php`, `app/Policies/MeasureBreachPolicy.php` |
| Form Requests | `app/Http/Requests/Kri/{Store,Update}KriRequest`, `RecordKriMeasurementRequest`, `UpdateKriThresholdsRequest`, `{Acknowledge,Resolve}BreachRequest` |
| Service | `app/Services/Kri/KriService.php` |
| Pages | `resources/js/Pages/Kri/{Dashboard,Create,Edit,Show,Thresholds}.jsx`, `KriForm.jsx` |
| Characterisation | `tests/Feature/Characterisation/KriDashboardFiguresTest.php` |
| Module tests | `tests/Feature/Kri/{KriTestCase,KriPagesTest,KriPolicyTest,KriThresholdsTest}.php` |

Controller 645 → 416 lines. `Ported::ROUTES` 67 → 72.

## THE THRESHOLD SCREEN SAVED NOTHING

`risk/kri/thresholds.blade.php` posted `kris[{id}][green_threshold]`. 
`KriController::updateThresholds()` read `$request->input('thresholds', [])` and
looked for `green_min` / `green_max` / `amber_min` / `amber_max` / `red_min` /
`red_max` — **a shape nothing in the codebase produced**. The loop ran zero
times, the method redirected with "Thresholds updated.", and every bulk edit of
a bank's risk tolerances was discarded behind a success message.

Proven before anything was changed, with a throwaway test that diffed the whole
row across the request: **zero columns changed**.

It compounded. Even with a matching payload the method wrote only the legacy
mirror columns and never called `KriMeasureBridge::syncDefinition()` — and since
WP-04 the bands the engine judges a breach against live in `measure_thresholds`,
not on the KRI. So a "successful" save would still not have moved the limit any
breach is measured by.

Both halves are fixed and pinned by `KriThresholdsTest`: the columns move, the
direction decides which columns, a cleared boundary is a real edit, another
tenant's id is rejected rather than silently skipped, and an open effective-dated
band set exists in `measure_thresholds` afterwards.

## Two threshold inputs, not three

A traffic-light band set needs **two** numbers. On a higher-is-worse indicator
green runs up to the green boundary, red starts at the red boundary, and amber
is whatever lies between — it has no boundary of its own.

The old forms asked for three. `$amber` was read into a variable in both
`store()` and `update()` and **never used in either direction branch**, and
`KeyRiskIndicator::getAmberThresholdAttribute()` has always returned the *red*
edge, so the amber box redisplayed red's number. Three inputs, two meanings, one
discarded. The forms now take the two boundaries and render the resulting bands
in plain English beside them, including a warning when the two are the wrong way
round for the chosen direction.

## The legacy measurement table is no longer read

Phase 4's acceptance criterion 3: `grep -rn "KriMeasurement::" app/Http/Controllers`
returns nothing. `KriPagesTest::no_controller_reads_the_legacy_measurement_table`
enforces it.

Two readers were removed:

- **The show page's history table** now pages `measure_values`, where a reading
  has lived since WP-04.
- **The dashboard's twelve-month trend** now counts the **breach register**.
  This answers a different question from the chart it replaces, deliberately:
  the old one counted rows in `kri_measurements` whose status was red or amber,
  so a KRI sitting in breach for six months contributed a count in each of the
  six — it plotted *months in breach* under a heading that said "Breach Trend".
  The new one counts crossings, which is one row per breach and is what the
  acknowledge/resolve workflow acts on. It is also the only version available
  without reading the legacy table.

`KriMeasureBridge`'s own legacy dual-write is untouched — retiring it is a
deprecation-process job, per the phase prompt.

## A breach ability cannot live on the KRI's policy

Written on `KeyRiskIndicatorPolicy` first, and the policy test caught it:
`can('acknowledgeBreach', $breach)` resolves the policy from the **subject's**
class, so an ability about a `MeasureBreach` parked on the KRI's policy is never
reached. It falls through to a Gate ability that does not exist and **denies
everyone, silently** — the same failure mode as the mis-named policy trap from
Phase 3, arriving from the other direction. Hence `MeasureBreachPolicy`, named
for its model like every other.

`kri.acknowledge_breach` rather than `kri.edit`, carried across from the route:
acknowledging is what makes mean time to acknowledge a real number, and it is a
different job from editing an indicator's definition.

## Another MySQL-only ordering

`FIELD(current_status, …)` on the dashboard, exactly as in 3.8's RCSA. It threw
on SQLite, so the KRI dashboard **had no test coverage at all**; a portable
`CASE` is what let the characterisation test be written.

## Dead columns dropped from the views

| Read | Reality |
|---|---|
| `$kri->category` (dashboard tiles, thresholds table) | no such column, and `store()` validated `category` then `unset()` it — always blank |
| `$kri->unit` | the column is `unit_of_measure`; the dashboard appended an empty string to every value |
| `$kri->trend` | the column is `trend_direction`; the arrow was always "flat" |
| `$kri->inverse_threshold` (thresholds checkbox) | no such column — always unchecked, and nothing read it back |

The dashboard's period filter (`<select id="periodFilter">`) had no handler and
is not ported, as in 3.5 and 3.8.

## Carried across deliberately unchanged

- **`yellow` is a status with no tile.** The column carries four values and the
  KPI row names three: yellow counts as healthy in the health score and lands in
  the Green slice. That is why five KRIs — one of each status — give an
  `avgHealthScore` of 60 and not 40, and the characterisation test says so.
- **The two NOT NULL column sets stay in step.** `key_risk_indicators` carries
  both the original columns and the 200038 alignment columns, and both are
  written, as they were.
- **Recording a measurement still goes through the engine**, so the reading
  lands against the period its date falls in and is read against the limits in
  force then. `KriBreachDetected` → `EscalateRiskOnKriBreach` is untouched.

## PHPStan

Relations typed with generics on `KeyRiskIndicator` (`risk`, `owner`),
`MeasureValue` (`period`, `enteredBy`, `measure`) and `MeasureBreach`
(`period`, `acknowledgedBy`), and an `@property` block for the 200038 columns
Larastan cannot see. `breachTrend()` uses the query builder rather than the
model, because a grouped count returns aliases and hydrating them as
`MeasureBreach` rows would be claiming they are.

`phpstan-baseline.neon` is **108 lines shorter and gains nothing** for this
module.
