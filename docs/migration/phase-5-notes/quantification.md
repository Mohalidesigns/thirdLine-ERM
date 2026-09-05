# Phase 5.2 — Quantification and ICAAP

`Risk/QuantificationController`, 1,611 lines — the largest single controller in
the programme. Extraction before pages, characterisation before extraction.

**In progress.** This note covers what has landed so far: `IcaapService`,
`ScenarioLibrary`, `Distributions`, and two create paths that had never worked.
Still to come: `QuantificationReportService` (the four report assemblers),
`ScenarioService`, `SimulationService`, the three policies, and the fifteen
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

## Numbers so far

`QuantificationController` 1,611 → 1,129 lines. Criterion 3 wants it under 300
with no method over 40; the four report assemblers and the simulation
orchestration are the rest of the distance.
