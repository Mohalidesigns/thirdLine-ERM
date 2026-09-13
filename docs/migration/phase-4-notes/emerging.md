# Phase 4.6 — Emerging risks

`Risk/EmergingRiskController` (202 lines) and the emerging risk register's
create/edit pair onto Inertia. The last module of Phase 4.

## Scope check, done before any code

The prompt says "views `risk/emerging/*` (4)" and "grid + `Create/Edit/Show`".
Both are wrong, and were checked rather than assumed:

- **Three files, not four**, and only **two are pages**:
  `create.blade.php`, `edit.blade.php` and `_form.blade.php`, a partial they
  share. `emerging/index.blade.php` became `Pages/Emerging/Index.jsx` in Phase 2.
- **There is no Show route and never was.** The register grid links straight to
  edit. Nothing was built for a screen the product does not have.
- **`_form.blade.php` is `<x-dynamic-form type="EmergingRisk">`** — 4.4's trap
  applies squarely, so the React pages render `DynamicForm` from
  `FormSchemaPresenter::form()` rather than hand-written fields.
- MySQL-only SQL: none. `FIELD(|MONTH(|DATE_FORMAT(|YEAR(|DATEDIFF(` all clean
  in the controller, model and grid.
- The mapped validation rules were already tenant-bound `Rule::exists` for
  `category_id` and `owner_id` — this module was ahead of the others there, and
  `RiskIntelligenceGateTest` already pinned it.

## The create form could not save an entry. At all.

The largest defect of Phase 4, and the plainest.

`FormFieldRegistry::emergingRisk()` hand-wrote the options for three enum
fields, and **not one of the three matched the column it maps to**:

| Field | The form offered | The column accepts |
|---|---|---|
| `horizon` | `near_term`, `medium_term`, `long_term` | `0-3m`, `3-6m`, `6-12m`, `12m+` |
| `potential_impact` | `low`, `moderate`, `high`, `severe` | `Low`, `Medium`, `High`, `Critical` |
| `status` | `monitoring`, `escalating`, `promoted`, `dismissed` | `monitoring`, `assessing`, `escalated`, `converted`, `closed` |

`horizon` and `potential_impact` are both **required**. So a user filling the
form in and pressing save got a validation error on two fields whose every
available option was invalid, with no way through. A probe against HEAD asked
the form what it offered, submitted exactly that, and got back errors on
`horizon` and `potential_impact` with **zero rows created**.

Since the form became metadata-driven, on every tenant, the emerging risk
register has been read-only through the interface.

**Why nothing caught it.** `RiskIntelligenceGateTest` posts
`risk.emerging.store` directly with correct values (`'0-3m'`, `'Critical'`) and
never asks the form what it is offering. That is a whole class of test blind
spot worth naming: *a test that posts a route is not a test of the form in front
of it*. The regression test now reads the options off the rendered page and
submits those — `submitting_exactly_what_the_create_form_offers_is_accepted`.

**The fix has two halves.** `FormFieldRegistry::emergingRisk()` now derives all
three from `EmergingRisk::HORIZONS/IMPACTS/STATUSES` through a new `labelled()`
helper, so they cannot drift again; anything without an explicit label
title-cases from its own value, so a constant that grows a member still renders.
And because `2026_08_13_120005_register_column_backed_form_fields` has already
run everywhere and will not re-run,
`2026_09_05_100000_realign_emerging_risk_form_options` replays its upsert for
this one type — refreshing only the columns that describe storage and leaving
every column 120005 marks as tenant-owned (label, section, order, width, help
text, visibility) exactly as the tenant has it.

## A builder-added field was accepted and thrown away

`EmergingRiskController` has used `PersistsConfiguredAttributes` since WP-05,
and for this model it was **a no-op**. `EmergingRisk` was absent from
`ObjectTypeRegistry::modelTypeMap()` and carried no `HasObjectIdentity`, so
`resolveConfiguredType()` returned null and the trait bailed with 0 **before
validating or storing anything**. A field a tenant added through the builder
rendered on the form, accepted what was typed, and was discarded without a word
— verbatim the failure that trait's own docblock says it exists to prevent.

The graph had already declared the type: `ObjectTypeRegistry` has an
`EmergingRisk` object type with 14 column-backed attributes and two relationship
types pointing at it, and nothing had ever put a row in `objects` for one.

Six edits close it, all following the pattern the other fifteen models use:
`HasObjectIdentity` on the model, an `ObjectSourceMap::spec()` entry, a
`modelTypeMap()` entry, a place in `ObjectBackfiller::ORDER`, a canonical alias
in `MorphTypes`, and the model's name in `ObjectIdentityTest::requiredModels()`.

**The last two were found by the guard tests, not by me**, and that is worth
recording: `ObjectIdentityTest` asserts that every model in `ObjectSourceMap`
appears in its own list, and separately that every mirrored model has a morph
alias. Adding the model to the map turned both red immediately and walked the
rest of the wiring out. A test that fails when the codebase grows a case it does
not cover is doing more work than one that only checks what is already there.

**Operational step for existing installs:** run `php artisan graph:backfill` to
mirror emerging risks already on the register. `ObjectBackfiller` is built to be
re-runnable for exactly this — its docblock says "adding a model to
ObjectSourceMap later must not need a migration" — so this is deliberately not a
data migration authored here. New and amended entries mirror themselves.

`emerging_risks` has no `entity_id` or `business_unit_id`, so neither is mapped:
an emerging risk sits on the organisation's horizon, not a node's.

## A rejected save left an entry behind

`store()` created the record and only then called `saveConfiguredAttributes()`,
which validates on its own. So a required tenant field left blank threw a
`ValidationException` **after** `EmergingRisk::create()` had run and consumed a
reference from the per-tenant sequence. The user was told the field was
required, filled it in, submitted again — and the register held two entries,
one of them a ghost with a real reference number.

`update()` was a step worse: `$emerging->update(...)` ran first, so a submission
that failed on a tenant-added field still wrote every column. The record was
renamed, restatused and rescored by a request the user was told had failed.

Both are fixed the way 4.4 did it: the configured rules are merged into the Form
Request via `ValidatesConfiguredAttributes`, so **everything validates in one
pass before anything is written**, and both writes are wrapped in a transaction.

## A dead read: the whole of `formOptions()`

`create()` and `edit()` both called a private `formOptions()` returning
`categories`, `owners`, `statuses`, `horizons` and `impacts` — five values, two
of them database queries — and `_form.blade.php` referenced **none of them**,
because it renders `<x-dynamic-form>` and gets its options from the object type.
Two queries per form load, on every load, feeding nothing. Removed.

## Policy

`EmergingRiskPolicy`, discovered for its model, asking for `risk.view` /
`risk.create` / `risk.edit` / `risk.delete` — **not** a new `emerging.*` set.
That is the permission set the routes have always carried, for the reason the
controller's own docblock gives: an emerging risk is a register object, and
anyone trusted to maintain the risk register is trusted to maintain the horizon
in front of it. Minting a parallel set would hand every tenant four new
permissions nothing had asked them for and lock the screen for every existing
role on upgrade.

`review` is its own ability delegating to `update`: confirming an entry is still
current is a different act from revising it, even though it takes the same
permission. It replaces the controller's hand-rolled `assertSameTenant()`.

## PHPStan

Typing the model's four relations **first** rather than last — the lesson 4.5
ended on — made five baseline entries stale immediately, and they are removed.
Adding `HasObjectIdentity` adds the three larastan false positives that every
one of the other fifteen models using that trait already carries
(`syncObjectIdentity` on a `Model`-typed closure param, and a `method_exists`
narrowing); two blocks are added to match them. Net: three fewer baseline
entries, and the six errors red at HEAD unchanged.

Worth a follow-up outside this phase: fixing the closure signature inside
`HasObjectIdentity` itself would retire roughly 45 baseline entries across all
sixteen models at once.

## Phase 4 acceptance criteria, and the one gap in them

With 4.6 landed, the phase's six criteria were checked rather than assumed:

1. `tests/Feature/Measures/*`, `Issues/*`, `Notifications/*`, `Characterisation/*` green — yes, in the full run.
2. `grep -n "10000000" app` returns nothing — yes. The remaining hits for that digit run are all longer numbers (`1000000000`, `100000000`); the loss-event reportability threshold lives in `config/risk.php` as `10_000_000`.
3. `grep -rn "KriMeasurement::" app/Http/Controllers` returns nothing — yes.
4. All nine policies exist and are registered: KeyRiskIndicator, Period, MeasureThreshold, LossEvent, NearMiss, Issue, AssessmentCampaign, Questionnaire, EmergingRisk.
5. The four scheduled commands green in `tests/Feature/Console/*` — **this one had a gap.** `treatments:check-overdue` was covered there; `kri:check-breaches` and `issues:check-overdue` were covered elsewhere; **`measures:rebaseline-thresholds` had no test anywhere at all.** `ThresholdRebaselineService` is well covered by `Measures/FormulaThresholdRebaselineTest`, which calls `review()` directly — but nothing had ever run the command around it, so its option handling, its choice of period and its failure path were unexercised. A service with a green test and an untested command around it is a scheduled job that can break without anything going red, and this one decides which naira limits a bank's board is asked to move. `Console/RebaselineThresholdsCommandTest` now covers it, including that a second nightly run does not raise the same approval twice.
6. `resources/views/risk/{kri,periods,thresholds,loss-events,issues,campaigns,questionnaires,emerging}` all deleted — yes. `resources/views/risk/` now holds only `ai`, `analysis`, `documents`, `imports`, `quantification`, `regulatory`, `reports`, `workflows` and `dashboard.blade.php`, which belong to Phases 5 and 6.

## Numbers

`Ported::ROUTES` 96 → **98**. Three Blade files deleted; `resources/views/risk/`
now holds no module folder from Phases 3 or 4.
