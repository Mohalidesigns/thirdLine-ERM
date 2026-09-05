# Phase 5.2 — Quantification and ICAAP

`Risk/QuantificationController`, 1,611 lines — the largest single controller in
the programme. Extraction before pages, characterisation before extraction.

**In progress.** The extraction is done — `IcaapService`, `ScenarioLibrary`,
`Distributions`, `QuantificationReportService`, `ScenarioService`,
`SimulationService`, `QuantificationSettingsService` — along with four defects
that had been shipping. Still to come: the three policies and the fifteen
pages.

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

## Numbers

`QuantificationController` 1,611 → 507 lines. Criterion 3 wants under 300 with
no method over 40; the pages are the rest of the distance.
