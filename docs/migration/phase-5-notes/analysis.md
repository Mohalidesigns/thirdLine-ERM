# Phase 5.1 — Analysis

`Risk/AnalysisController` and the four `risk/analysis/*` views onto Inertia.
Two commits: the extraction and the figures first (`9420918`), the screens
second (`37f37bc`).

## Scope check

Four views, as the prompt says, and none of them uses `x-dynamic-form`. No
MySQL-only SQL in the controller. The mapped route names are unchanged —
`risk.analysis.correlation` in particular stays, because it is linked from the
nav and from saved bookmarks even though nothing on that page is a correlation.

## The movement chart could not show movement

`buildRiskMovementData()` and the four trend builders selected
`where('created_at', '<=', $end)` and then read each risk's **current** score.
That is not a point-in-time read: it counts the risks that EXISTED by that date
and rates every one of them as it stands TODAY. A risk rated Low in January and
Critical in June was counted Critical in January too.

So every series could only slope upward as the register grew, and **none of
them could ever show a risk moving between bands** — on charts titled "Risk
Movement" and "Rating Trend".

`RiskRepository` has answered exactly this question since WP-04 TASK 4, with
carry-forward semantics and a one-query read. `RiskMovementService` uses
`valuesAsOf()` now.
`a_risk_that_moved_between_bands_now_shows_as_moved` is the test that proves
it; before 5.1 it could not have passed.

**Why not `RiskRepository::asOf()` directly.** It treats a null
`date_identified` as "existed in every period" — the safe reading for a register
LISTING, where omitting a risk understates it. Here that would put risks in
quarters before they were entered and overstate every historic bucket.
`date_identified` is nullable and optional on the create form, so existence is
`date_identified ?? created_at` and only the SCORES come from the repository.

**The compatibility property that made this safe to do inside a port**: a
register never assessed through the measure engine has no history to read
as-at, so every number is unchanged. The five original characterisation
assertions are untouched and still green.

## The bands were the default profile's, hard-coded

`>= 20 / >= 12 / >= 5` are the default 5×5 profile's edges (Low 1-4, Medium
5-11, High 12-19, Critical 20-25) and **no other profile's**. A bank on a 4×4 or
6×6 matrix — which the builder offers and `ScoringProfileTemplates` generates
bands for — had its movement chart drawn against a scale it does not use. The
characterisation pins that the two agree exactly for the default profile, which
makes the switch a no-op there and a correction everywhere else.

## The Treatment Progress chart measured neither treatments nor progress

`buildTreatmentTrendData()` was drawn from the **risks** table:

- `completed` counted risks whose status was closed/retired and whose
  `updated_at` fell in the month. `updated_at` moves on any edit, so a risk
  closed in January and edited in June counted as completed in June — and
  closing a risk is not completing a treatment.
- `overdue` counted active risks rated High or Critical created more than six
  months ago. The code's own comment called that "simplified".

`treatment_plans` carries `completion_date`, `target_date` and a status list,
and `treatments:check-overdue` maintains the overdue state nightly. Both series
read it now. An unstarted plan past its target is **not** counted late, which is
the rule `TreatmentPlan::RUNNING_STATUSES` already states for the sweep.

## And the reads

Each builder ran one whole-register `->get()` **per bucket** — four loads for
the quarterly chart, thirteen per chart across four charts for a default
twelve-month trends page. The register is loaded once per screen now.

## Three prompt instructions, two declined

The phase prompt's technical instructions are a hypothesis; each was checked.

| Instruction | Verdict |
|---|---|
| Pages are thin wrappers around `Widget` | **Declined.** `Widget` needs a payload envelope from `WidgetPayloadPresenter`, which requires a persisted `WidgetDefinition` and routes `risk.widgets.payload` by its key. Using it would mean seeding dashboard widget rows per tenant for pages that are not dashboards, and would still not carry the heat map's filters or the risks listed inside each cell. |
| Shared controls as a `network` widget | **Declined.** `NetworkResolver` draws the anchor NODE's neighbourhood in the object graph — two hops, capped at 60. This page reports a matrix of pairwise control overlap. Rendering it as a neighbourhood graph would lose the only number the page exists to report. Same shape as 3.8's "Matrix = widget type heatmap". |
| Add a `bowtie` resolver | **Declined.** A bow-tie is a single-subject diagram reached by `?risk_id=` — the detail view of one record. A dashboard widget answers a question about a population. |

The `heatmap` widget itself is untouched and stays where it belongs, on
dashboards.

## A defect I nearly introduced

The first draft of the bow-tie port mapped its control objects through a `row`
helper reading `control_code` and `effectiveness_rating`. Those objects are flat
`stdClass` built a few lines above the return with `name`/`type`/
`effectiveness`/`gaps`, so the helper would have invented two dead columns —
verbatim the defect 3.8 and 4.5 both found, nearly committed by the person who
wrote both notes. Worth recording: **the shaping step of a Blade→Inertia port is
itself a place dead reads get created**, because it is where someone guesses at
field names instead of reading the thing being shaped.

## Numbers

`AnalysisController` 851 → 765 across the extraction, back to 818 with the
Inertia payload shaping. `Ported::ROUTES` 98 → 102. Four Blade views deleted.
