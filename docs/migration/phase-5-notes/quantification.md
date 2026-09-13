# Phase 5.2 — Quantification and ICAAP

`Risk/QuantificationController`, 1,611 lines — the largest single controller in
the programme. Extraction before pages, characterisation before extraction.

**Done.** The extraction, the three policies and all fifteen pages, along with
**nine defects that had been shipping**. `resources/views/risk/quantification/`
no longer exists.

## Scope check

Eleven view files plus a `reports/` subdirectory of four, not the prompt's
fifteen loose views. No MySQL-only SQL. No `x-dynamic-form`, so 4.4's
schema-driven-form trap does not apply here.

## Neither create path worked. Either of them. Ever.

`quantification_scenarios.scenario_type` is `string(50)` **NOT NULL with no
default**. Neither `storeScenario()` nor `importLibrary()` set it, and the
create form has no field for it — so **every attempt to add a scenario through
the interface, by either route, ended in a NOT NULL violation and a 500.**

The library screen offers ten templates and not one of them could be taken. The
create form is the only other way in.

This is the module whose scenarios feed the Monte Carlo runs that produce the
aggregate VaR that becomes a Pillar 2B stress buffer in an ICAAP submission. The
register could only ever be populated by a seeder.

**Found by writing the library test**, not by reading — the same lesson 4.6
ended on: *a test that POSTs a route is not a test of the form in front of it*,
and here there was no test at all. `QuantificationScenario::TYPES` now documents
the vocabulary that is actually in the data (`single_event` from
`QuantificationSeeder`, `single_risk` from `DemoDataSeeder`, `stress` from the
controller's own `LOWER(scenario_type) = 'stress'` query), and
`DEFAULT_TYPE` is `single_event` — one loss event with a frequency and a
severity, which is what both paths create.

## IcaapService

`icaap()` was 170 lines of capital arithmetic inside a controller. It moved
whole, unchanged — the comments included, because they are the record of what
WP-08 removed when the August 2026 audit found this file slicing one Pillar 2A
column by 0.3/0.25/0.25/0.2 and presenting the slices as four risk types, and
shipping five stress scenarios whose CAR drops were hardcoded independently of
the bank's balance sheet.

`IcaapCharacterisationTest` was written first and pins every figure, including
the rules that replaced the fabrication:

- an absent input stays **absent** — an unrecorded balance sheet is not a
  balance sheet of zeroes, and 0% CAR is a specific, catastrophic claim;
- Pillar 2A is reported exactly as stored, with no decomposition;
- available capital is null until **every** deduction is known, because a
  waterfall with a missing bar is not a smaller waterfall;
- the preparer's own CAR is kept separate from the computed one and the two are
  reconciled rather than one quietly winning.

## The library is config now, with its provenance testable

Criterion 4. Ten templates that were a PHP literal inside the controller are
`config/quantification_library.php`, and `QuantificationLibraryTest` fails if
any entry lacks a `source`.

That matters more than it looks: a template's mean and standard deviation become
a lognormal severity distribution, `MonteCarloService` draws on it, the run
produces an aggregate VaR, and that VaR becomes a capital add-on in a regulatory
submission. A parameter entering that chain with no stated origin would reach a
regulator with none. The import writes the provenance into the scenario's own
description so it travels with the record rather than living only in a config
file.

`Distributions::lognormalFromMoments()` is the conversion, out of the controller
and under test: sigma from the coefficient of variation, then mu from sigma —
getting mu wrong is the defect migration `2026_08_19_120003` exists to correct.

## PHPStan found what the tests could not

Deleting the five private helpers (`naira`, `capitalRatioPercent`, the two
resolvers, `stressImpactRows`) broke the dashboard and three of the four
reports, which call them too. The ICAAP characterisation and the library tests
both stayed green, because neither exercises those routes.

PHPStan named all thirty-odd call sites immediately. The service is injected on
the constructor now rather than method-injected on `icaap()` alone.

**Worth remembering: an extraction is only proved faithful for the paths that
have tests.** Run the static analyser before believing a green characterisation
means the extraction is complete.

## The four reports

`QuantificationReportService` took the Capital Adequacy Summary, the Stress
Testing Report, the Risk Contribution Analysis and the Regulatory Compliance
Pack whole. `QuantificationReportsCharacterisationTest` was written first,
against the running Blade screens, and pins what only the reports do — the
capital arithmetic they share with the ICAAP screen is already
`IcaapCharacterisationTest`'s:

- Pillar 1 computed from the **resolved** minimum, and the partial-total rule
  that keeps headroom null until every deduction is known;
- the stress report reading the deliberately bound run and **never** falling
  back to the latest completed one — the fallback that let a single-scenario
  operational calibration be presented to a board as a macroeconomic stress
  test — including when the bound run belongs to another tenant;
- the basis flags on Risk Contribution, which are the only thing stopping an
  ordinal residual-score total from being printed with a naira sign;
- the resolved minimum reaching the regulatory pack's checklist line.

**A trap worth recording**: `keyBy('confidence')` truncates the float key 99.9
to the array key 99, because PHP array keys cannot be floats. "The run computed
no 99.9 row" therefore cannot be asserted with `has()`; the levels are asserted
as a list instead.

Five stale `phpstan-baseline` entries for the controller were **dropped rather
than re-homed**: summing a shaped collection with a closure instead of a string
key, and dropping a nullsafe that `??` already handled, fixes them outright.

## The cancel button had never cancelled anything

WP-06 added `progress`, `completed_iterations`, `cancel_requested_at` and
`job_run_id` to `simulation_runs` in `2026_08_15_120001` so a run could be
observed and cancelled, and `RunSimulationJob` reads all four. **None of them
was ever added to `SimulationRun::$fillable`**, so every `update()` naming them
was dropped in silence:

- the run never linked to its `JobRun`, so the results page could not find the
  job whose progress it was drawing;
- `MonteCarloService`'s progress writes went nowhere — the bar sat at zero for
  the whole run;
- `cancel_requested_at` never landed on the run, and the cancel handler then
  looked up `JobRun::whereKey(null)`, which matches no row. **The cancel button
  requested nothing of anybody**, while flashing "Cancellation requested. The
  run stops at its next checkpoint."

A Monte Carlo run over a real scenario set is the path that produces the
aggregate VaR that becomes a Pillar 2B stress buffer, and it could not be
stopped once started.

Found because `SimulationRunTest` asserts the cancellation **landed** — on both
records — rather than that the flash message did. There was no test of this
path at all before it. This is the mass-assignment half of the recurring
column-mismatch family: the other modules wrote columns the table lacks; here
the table had the columns and the model would not let them through.

## The settings screen saved three of its ten fields

`default_confidence`, `default_time_horizon`, `seed`, `target_car`,
`countercyclical_buffer`, `alert_green`, `alert_amber` and `alert_red` had no
column anywhere. A preparer typed them, the screen redirected with
"Quantification settings have been updated", and every one was discarded — and
`default_confidence` and `default_time_horizon` were `required` in the
validator, so they had to be filled in on every save to be thrown away.

The third stored field was worse in its way: `default_iterations` **did**
persist, and nothing read it — `simulate.blade.php` hardcoded 10,000 as its
selected option. The one setting that saved never reached the screen it
configures.

What the screen offers now is five fields, all of which round-trip:

- the three simulation defaults (iterations, confidence levels, horizon), read
  by the simulate form. `default_horizon_years` is the one column this needed;
- `cbn_minimum_car` and `cbn_conservation_buffer`, which already worked. Their
  **display** fallbacks now come from `config/quantification.php` rather than
  from literals that disagreed with it: the screen used to show 2.5 for the
  conservation buffer, the Basel III figure, while `resolveConservationBuffer()`
  fell back to the CBN's 1.0.

### Deviation from the phase prompt (criterion 3)

The prompt asks for `alert_amber => 12` and `target_car => 15.0` to move into
config. **They are deleted instead.** Relocating a number does not fix a form
that cannot save it, and a config key nothing reads is the same dead weight one
indirection further away. Nothing in the product reads a CAR RAG band: the
ICAAP screen and all four reports colour CAR binarily against the resolved
minimum (`meets_minimum`), so a green/amber/red band would have to be invented
— which is exactly what WP-08 deleted when it removed the 8% "Marginal" verdict
that appears in no CBN guideline.

`seed` goes for the same reason: WP-07 draws a fresh seed per run at queue
time, so the figure is re-derivable and a replayed job cannot produce a
different capital number. A box offering to pin it contradicts that design.

The controller-side half of the criterion holds:
`grep -n "12.5\|10.0\|15.0\|=> 12" app/Http/Controllers` now returns only
`AiToolsController`'s `max_tokens`/`timeout`, which is 5.6's work.

### The test that would have caught it

`QuantificationSettingsTest` reads the field names off the **rendered page**,
submits exactly those, and asserts every one came back. That is 4.6's lesson
generalised — *a test that POSTs a route is not a test of the form in front of
it* — and it means a field that saves nothing cannot be added to this screen
without turning it red.

## The three policies

`QuantificationScenarioPolicy`, `SimulationRunPolicy` and
`IcaapAssessmentPolicy`, each named for its model so Laravel discovers it, each
carrying the tenant check the controller used to write out by hand.

Two separations they exist to make, both of which had been unenforceable:

- **Running is not calibrating.** `quantification.run_simulation` has always
  been a separate seeded permission from `quantification.create`, and no route
  distinguished them beyond the middleware. A run over a real scenario set is
  tens of thousands of iterations per scenario, and its number is presented to
  a board and filed with the CBN — somebody trusted to calibrate a scenario is
  not automatically trusted to publish a capital figure from it. Cancelling
  asks for the RUN ability rather than an edit one: whoever may start the work
  may stop it.
- **Signing off is not preparing.** `quantification.approve_icaap` was seeded
  with the rest of the set and, until `IcaapAssessmentPolicy`, was read by
  nothing at all — `approved_by_board`, `board_approval_date`,
  `cbn_submission_date` and `cbn_submission_ref` sit on the assessment and no
  screen had ever guarded writing them. The policy does NOT hardcode
  preparer ≠ approver: segregation of duties is the tenant's role assignment to
  make, and the abilities are separate so it CAN be made.

## The register was blind on four screens

`quantification_scenarios` has no `risk_category`, `distribution_type`, `mean`,
`std_dev`, `frequency_per_year`, `min_loss`, `max_loss` or `last_run_at`
column. **Those are the create form's field names.** The real columns are
`cbn_risk_category`, `severity_distribution`, `expected_loss_per_event_kobo`,
`severity_sigma`, `expected_annual_frequency`, `severity_min_kobo` and
`severity_max_kobo` — which is why the write path has always had a mapping
(`attributesFrom()`).

Four screens read the form's vocabulary straight off the model, every one
behind `?? 0` or `?? '-'`:

- the **register list** — 6 of 9 columns: category `-`, distribution `-`, mean
  ₦0, std dev ₦0, `-/year`, last run `Never`;
- the **show page** — all four headline tiles (₦0, ₦0, 0/year, `-`) and six of
  seven parameter rows, printed beside a correctly-parameterised log-normal
  curve drawn from the very parameters the panel called zero;
- the **simulate picker** — every row read "· · Mean: ₦0", so an operator
  assembling a capital run had nothing to choose on;
- the **edit form** — below.

These are the parameters `MonteCarloService` draws to produce the VaR that
becomes a Pillar 2B buffer. ₦0 is the same class of claim as 0% CAR.

The fix is `ScenarioService::toFormValues()`, the exact inverse of
`attributesFrom()`, plus `Distributions::stdDevFromLognormal()` — the inverse
of the sigma half of the moment conversion. A scenario stored at
`DEFAULT_SIGMA` gets **null** back rather than the standard deviation that
sigma would imply: inventing one would put a number the preparer never chose
into a field they are about to save.

`last_run_at` is not resurrected. No such column has ever existed, and "which
runs included this scenario" is a query — the show page lists them.

## The edit screen could not edit, and filed a duplicate instead

`editScenario()` rendered `create-scenario.blade.php`. That form populated every
field from `old(...)` with **no fallback to the record**, so opening a scenario
for editing showed a completely blank form; and its action was hardcoded to
`route('risk.quantification.store-scenario')`, so saving it **created a second
scenario rather than amending the first**.

`grep -rn "update-scenario" resources/ app/` returned nothing outside
`routes/web.php`. The PUT route, `updateScenario()`, its validation and (as of
this phase) its policy check had never been reachable from the interface.

This is 4.6's defect one turn further on: there the create form could not
create; here the edit form creates instead of editing.

`ScenarioRegisterTest` is the guard. Its headline assertion goes IN through the
create form and comes OUT through the edit form's props, so a read naming a
column the write path never fills cannot survive it.

**A precision note worth keeping.** The mean round-trips exactly — it has its
own kobo column. The standard deviation does not: it is not stored, it is
recovered from `severity_sigma`, which is `decimal:6`. Six decimal places moves
the recovered figure by about one part in a million — four naira in three
million. The test asserts a relative tolerance and says why, rather than
pretending the round trip is exact.

## "VaR (99.5%)" was the 99.9% figure

`SimulationRun::getVar995Attribute()` reads `var_99_9_kobo`, because the engine
stores no 99.5 column — `MonteCarloService` writes 90 / 95 / 99 / 99.9. The
results list headed a column "VaR (99.5%)" and the results page a KPI tile the
same, both showing the 99.9 loss: a **larger** number than the one they claimed
to be, on pages read as the output of a capital model.

`IcaapService` already refuses this — it reports stress impact off the columns
that exist rather than off the levels a run requested — and both screens now
say 99.9.

The same presenter fixes an N+1 while it is there: `var_95`, `var_995` and
`expected_loss` each called `$this->aggregate_result`, which issues its own
query, so the list cost three queries a row.

## Notes on the pages

- **`figures.js` / `Figure.jsx`** hold the "missing stays missing" rule once.
  Each Blade report carried its own pair of closures for it; a zero in a
  capital column is a statement about the bank, and four copies of that rule
  would drift.
- **`SeriesChart.jsx`** is inline SVG. The house convention is no chart
  dependency (see `TrendChart`, `HBarChart`); the Blade screens broke it by
  reaching for Chart.js. All four series these pages draw are one dimension
  against one label, so they share it. An empty series renders **nothing**
  rather than an empty axis, and the callers say so in words.
- **The results page polls and cancels.** `useJobProgress` on the run's
  `JobRun`, with the cancel button that — until the `$fillable` fix earlier in
  this phase — requested nothing of anybody.
- **The library page** says which templates this organisation already holds, so
  a preparer is not offered an import that would file a duplicate.

## What the Inertia crossing did to the characterisation tests

Both characterisation tests had to change, and not by renaming a helper. Props
arrive as JSON: objects become arrays, and `json_encode` writes a whole float
without its fraction, so `13.0` crosses as `13` and returns an **int**. Every
`assertSame(13.0, ...)` failed — the trap already recorded from 3.5.

The assertions compare figures by VALUE now, through an `assertFigure` helper.
`assertNull` stays strict everywhere: an absent capital input staying absent is
the property those files exist to defend.

One assertion was added that PHP could not otherwise make. The pages call
`.map()` on `rows`, `stressScenarios`, `byType`, `byUnit` and `checklist`; a
Collection whose keys survive serialisation arrives as a JSON **object** and
the page throws at render while the props stay perfectly correct. No existing
test would catch it. `every_collection_prop_reaches_the_page_as_a_list` asserts
each has keys `0..n-1`.

## The dashboard, and the ninth defect

`dashboard()` was 117 lines of capital arithmetic inside the controller — the
shape `icaap()` had before `IcaapService`, extracted for the same reason into
`QuantificationDashboardService`. The arithmetic itself stays in `IcaapService`
so the dashboard and the ICAAP screen cannot drift apart.

**"₦0, as assessed".** The controller computed the ICAAP capital add-on as
`($pillar2a ?? 0) + ($pillar2b ?? 0)`, and the tile printed the result under
*"ICAAP Capital Add-on · Pillar 2A + Pillar 2B, **as assessed**"*. An
organisation with no assessment on file therefore read **₦0 as assessed** — a
specific claim that its capital add-on is nil, made from no data at all.

It is the same defect WP-08 removed from the expected-shortfall tile **on this
very screen**, and from CAR on the ICAAP screen. It had simply been missed
here. The add-on is null now unless at least one pillar is on record, and
Pillar 2A totals only the components actually recorded — the partial-total rule
the reports already use.

`QuantificationDashboardTest` is nine tests on a screen that had **none**,
despite its headline tiles being capital figures.

One design decision worth recording: the add-on chart is a labelled bar list
rather than the doughnut it was. **A doughnut cannot draw an absence** — only a
zero slice, which claims the component is nil. Same reasoning as the ICAAP
waterfall, which leaves a gap where a bar is unknown.

## Numbers, and a deviation from criterion 3

`QuantificationController` 1,611 → 531 lines, and
`resources/views/risk/quantification/` is gone.

Criterion 3 asks for the controller **under 300 lines with no method over 40**.

- **No method over 40: met.** The largest is now 38.
- **Under 300 lines: not met, deliberately.** The file is 531 lines, of which
  **304 are code** and 154 are comments, across 22 route actions — about
  fourteen code lines each, every figure computed in a service.

Getting to 300 would mean deleting the WP-08 explanations, and those comments
are the record of what these screens were getting wrong and why the replacement
is shaped as it is: the five hardcoded stress scenarios, the 8% "Marginal"
verdict that appears in no CBN guideline, the `car_required` column that does
not exist, the residual scores presented as capital. Prose that describes a
service's behaviour was moved into that service, which is the part of the
criterion worth honouring. What is left describes the controller's own choices
and stays.

The criterion's intent — a thin controller doing no real work — is met. Its
line count is not, and the reason is stated here rather than met by stripping
the record.
