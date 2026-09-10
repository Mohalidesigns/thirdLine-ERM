# Risk Intelligence — where every number comes from

WP-02, Task 1. Acceptance criterion: *"Every AI-surfaced number is traceable to a
real query — document the mapping."*

This file is that mapping. If a figure appears on a Risk Intelligence screen and
is not listed here, that is a defect.

## Status of the four original screens

| Screen | Was | Is now |
|---|---|---|
| Predictive | Trend line and 3-month "predictions" generated with `mt_rand()`; escalation probabilities from a hardcoded array `[0.87, 0.73, …]`; model accuracy 87.3 / precision 84.1 / recall 89.7 / F1 86.8 / AUC 0.912 / version "2.4.1", all constants | **Rebuilt** as `RiskForecastService`. Renamed *Forecast*. No model, therefore no model metrics. |
| Radar | Hardcoded array of 8 emerging risks with invented confidence percentages, identical for every tenant; `sources_scanned: 47` | **Rebuilt** on a real `emerging_risks` register that users populate. Renamed *Emerging Risk Radar*. |
| Regulatory Pulse | Hardcoded array of 6 circulars with invented reference numbers, completion percentages and action counts | **Rebuilt** as `RegulatoryPulseService` over `regulatory_circulars` and `regulatory_deadlines`. |
| Benchmarking | Peer values from `mt_rand(2, 5)`; "overall_ranking" from `mt_rand(3, 7)`; a 14-bank peer group that did not exist; hardcoded CAR 15.2 / NPL 3.8 / OpRisk 0.15 / maturity 3.8 | **Removed.** See "Why Benchmarking was removed" below. |

All three surviving screens sit behind `config('features.ai_intelligence')`,
which defaults to **false**. They 404 — not 403 — when the flag is off.

## Forecast (`RiskForecastService`)

Window: the last 12 calendar months, horizon 3 months. Both are constructor
arguments.

| Figure on screen | Source |
|---|---|
| Observed monthly line | `AVG(risk_assessments.residual_score)` grouped by month of `assessment_date`, filtered to `organization_id`. Months with no assessment stay **null** and are drawn as gaps — never interpolated. |
| Trend direction, slope per month | Ordinary least squares of the monthly means on month index. `slope > 0.05` reads rising, `< -0.05` falling, otherwise flat. |
| Months fitted (`n`) | Count of months in the window carrying at least one assessment with a non-null residual score. Below 3, no fit is produced and the screen says so. |
| Residual std. error | `sqrt(SSE / (n - 2))` from the fit. |
| Projected mean residual score | `intercept + slope × x₀` for each horizon month. |
| Projection range | `± s × sqrt(1 + 1/n + (x₀ - x̄)² / Sxx)` — the standard error of prediction for a new observation. Reported as a range with its basis stated. **No confidence percentage is attached**, because that would assert distributional properties nobody has verified. |
| Treatment velocity (net per month) | Plans whose `target_date` fell in the month and were not completed on or before it, less plans whose completion date fell in the month. Derived from stored dates only — there is no historical status snapshot in the schema. |
| Currently overdue | `treatment_plans` not in (`completed`, `cancelled`) with `target_date < today`. |
| KRI breach frequency | `kri_measurements.status = 'red'` over all measurements in the month, joined to the tenant through `key_risk_indicators`. **Null, not zero,** when nothing was measured. |
| Indicators red now | `key_risk_indicators.current_status = 'red'`. |
| Control test failure rate | `control_tests` with `completed_date` in the month and `result IN ('ineffective', 'partially_effective')`, over all tests completed that month. |
| Watchlist rows | The two most recent dated assessments per risk. Delta is `current.residual_score - previous.residual_score`. Both scores and both dates are shown so the reader can check the arithmetic. Unchanged scores are excluded. |
| Overdue treatments on a watchlist row | Count of that risk's overdue plans, as above. |
| Inputs panel | Row counts for the window: assessments dated in it, active risks, months fitted. |

**Deliberately absent:** accuracy, precision, recall, F1, AUC, model version,
last-trained date, training sample count, confidence percentages, and escalation
probabilities. There is no trained model here — there is a straight line through
twelve monthly averages, and the screen says exactly that.

The watchlist is described as *observed movement*, never as a prediction. A
genuine escalation probability needs a fitted hazard model and a labelled
outcome history; neither exists in this product.

## Emerging Risk Radar (`emerging_risks` table)

Every value is a stored column on a row a named user created.

| Figure on screen | Source |
|---|---|
| On radar | `emerging_risks` with status in (`monitoring`, `assessing`, `escalated`). |
| Fast moving / Imminent | `velocity_score >= 4` / `proximity_score >= 4`. |
| High or critical impact | `potential_impact IN ('High', 'Critical')`. |
| Scatter position | `proximity_score` (x) against `velocity_score` (y), both 1–5 ordinals entered by the analyst. |
| Radar score | `velocity_score × proximity_score`, 1–25. |
| By horizon / by category | Counts over `horizon` and the joined `risk_categories.name`. |
| Never reviewed / stale | `last_reviewed_at IS NULL` / older than 90 days. |

Velocity and proximity are **analyst judgements, attributable to their author**.
That is a different claim from a computed score, and the screen says so. There
is no confidence percentage because there is nothing to be confident about — it
is somebody's opinion, recorded openly.

WP-29 horizon scanning will write into this same table; `source`,
`source_reference` and `detected_at` exist so an automated entry stays
distinguishable from a manual one.

## Regulatory Pulse (`RegulatoryPulseService`)

| Figure on screen | Source |
|---|---|
| Circulars on file | `COUNT(regulatory_circulars)` for the tenant. |
| Issued in last 90 days | `date_issued >= now() - 90 days`. |
| High or critical impact | `impact_level IN ('critical', 'high')`. |
| Past effective date, not compliant | `effective_date < today` and `compliance_status != 'compliant'`. |
| Recorded compliance | Mean of `compliance_pct` across circulars that carry one. **Null when none is scored** — the screen says "not recorded" rather than implying 0%. The count of unscored circulars is shown alongside. |
| Impact mix chart | Counts by `impact_level`. Levels with no circulars read zero. |
| Filings overdue | `RegulatoryDeadline::isOverdue()` — `deadline_date` past and status not in (`submitted`, `not_applicable`). |
| Due in next 30 days | `deadline_date` between today and +30 days, status not submitted or N/A. |
| Days remaining | Calendar-day difference to `deadline_date`. |
| Linked register items | `count(affected_risk_ids)` / `count(affected_control_ids)` on the circular. |

The screen states plainly that it does not scan external sources. It shows what
the compliance team has recorded, and nothing else.

## Why Benchmarking was removed

The brief allowed either rebuilding it on genuine cross-tenant anonymised
aggregates with a k-anonymity floor of k ≥ 5 organizations and explicit per-org
opt-in, or removing it. It was removed.

Cross-tenant aggregation is not a reporting change — it is a data-sharing
feature. It needs a consent record per organization, a documented aggregation
boundary, a suppression rule when a cell falls below the k threshold, and a
contractual position on what a customer's data may be used for. Shipping it as a
side effect of a reporting work package would be the wrong way to make that
decision. Shipping the fabricated version for one more day was not an option, so
the screen, its route, its view, its sidebar entry and `AiDataService` are gone.

`RiskIntelligenceGateTest::the_benchmarking_route_no_longer_exists` keeps it
gone until someone brings back a real one.

## Phase 5.6 — the screens moved to React, the mapping did not

The three surviving screens are `Pages/Ai/{Forecast,Radar,RegulatoryPulse}.jsx`
now. **Every prop name is the one the Blade view used**, deliberately, so that
every row of this document still points at the figure it describes without
being rewritten. Nothing above changed.

Two properties this document commits to are worth naming as things the React
pages had to be built to preserve, because a chart library would have broken
both by default:

- **A month with no assessment stays a gap.** The forecast chart is hand-drawn
  SVG that splits each series into contiguous runs, so a null is a hole rather
  than a line joined across it. A default line chart interpolates, which would
  turn "nobody assessed anything in March" into a plotted value.
- **The projection is a range, not a confidence band.** It is drawn from the
  fit's standard error of prediction and labelled with its basis; there is no
  percentage attached, because that would assert distributional properties
  nobody has verified.

The radar scatter is likewise fixed to a 1-5 grid on both axes rather than
auto-fitted, because proximity and velocity are ordinal scores — an axis scaled
to the data would imply a continuous measure and make two registers
incomparable.

## Enforcement

- `scripts/check-no-rng.sh` — CI step. Fails on `mt_rand`, `rand`, `random_int`,
  `shuffle`, `str_shuffle`, `array_rand` and `uniqid` under
  `app/Http/Controllers`, `app/Services`, `app/Jobs`, `app/View`, `app/Livewire`
  and `resources/views`.
- `tests/Feature/NoFabricatedNumbersTest.php` — the same guard in the suite, so
  it fails before a push. Also asserts that no `auc_roc`, `model_version`,
  `training_samples` or `f1_score` key has reappeared, and that the one
  allowlisted file still uses a seeded `Random\Randomizer`.
- Allowlist: `app/Services/MonteCarloService.php` only. It draws Poisson and
  Box-Muller variates from `Random\Randomizer(Mt19937($seed))` and persists the
  seed on `simulation_runs.random_seed`, so any capital figure it produces can
  be reproduced.
