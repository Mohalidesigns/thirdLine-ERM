# Phase 3.8 — RCSA

`Risk/RcsaController` (534 lines) and four Blade views (539 lines) onto Inertia.
The last module of Phase 3.

## What landed

| Piece | Where |
|---|---|
| Gate subject | `app/Support/Rcsa/RcsaProgramme.php` |
| Policy | `app/Policies/RcsaPolicy.php` (registered by hand in `AppServiceProvider`) |
| Form Request | `app/Http/Requests/Rcsa/SubmitRcsaWorksheetRequest.php` |
| Services | `app/Services/Rcsa/RcsaService.php`, `RcsaWorksheetService.php` |
| Pages | `resources/js/Pages/Rcsa/{Dashboard,Worksheet,Controls,Matrix}.jsx`, `RcsaWorksheetTable.jsx` |
| Characterisation | `tests/Feature/Characterisation/RcsaFiguresTest.php` |
| Module tests | `tests/Feature/Rcsa/{RcsaTestCase,RcsaPagesTest,RcsaPolicyTest,WorksheetFilingTest}.php` |

Controller 534 → 197 lines. `Ported::ROUTES` gains the four GET routes
(63 → 67).

## THE PHASE PROMPT'S THREE TECHNICAL INSTRUCTIONS DO NOT FIT THE CODE

§3.8 reads: "`RcsaPolicy` (rcsa.view/submit). Worksheet is a large editable
table — build `RcsaWorksheet.jsx` on `GridTable` from Phase 2 (inline edit
mode), submit → existing `RcsaWorksheetSubmitted` event. Matrix = `Widget` type
`heatmap`." Three of those four were written without the code in front of them.
Each was checked before being set aside.

**1. There is no `Rcsa` model, so a discovered policy is impossible.** RCSA is
four screens computed over `Risk`, `Control` and `RiskControlMapping` plus one
write into `campaign_assignments` / `campaign_responses`. Laravel finds a policy
from the model it is named for; there is no `App\Models\Rcsa` and there should
not be. The two alternatives were both worse than the third option:

- an empty model with no table, invented only to host a policy; or
- hanging `rcsa.view` / `rcsa.submit` off `CampaignAssignment`, which the
  campaigns module owns and will want to authorise with `campaign.*`.

`Gate::policy()` accepts **any class string**, not only Eloquent models. So
`App\Support\Rcsa\RcsaProgramme` — a final, stateless, never-instantiated class
— is the subject, registered against `RcsaPolicy` in `AppServiceProvider`. The
controller then reads exactly like every other ported one
(`Gate::authorize('viewAny', RcsaProgramme::class)`), and
`RcsaPolicyTest::the_policy_is_registered_for_the_programme_subject` asserts the
wiring with `Gate::getPolicyFor()`. **This is the only hand-registered policy in
the product**; the other eleven are discovered.

**2. The worksheet cannot be a `GridTable`.** `GridTable`'s inline edit posts
**one cell at a time** to `grid.urls.cell`, a per-cell endpoint belonging to a
registered `Grid` definition. A worksheet is a **batch**: many risk lines filed
together in one transaction, as a draft or a submission, raising
`RcsaWorksheetSubmitted` on submit. Putting it on `GridTable` would mean either
replacing that atomicity with N independent cell saves — losing the
draft/submit distinction and the event with it — or borrowing the markup while
bypassing the save path, which is not reuse. `RcsaWorksheetTable.jsx` is a
purpose-built editable table that posts the whole worksheet as one form. The
atomicity is pinned by `a_rejected_worksheet_writes_nothing_at_all`.

**3. The matrix is not the `heatmap` widget.** `HeatmapResolver` renders
**probability × consequence** off the organisation's scoring profile. The RCSA
matrix is **risks × controls**, with a coverage percentage per risk. They share
the word "matrix" and nothing else; rendering one as the other would show the
wrong data. The three dashboard charts are the house SVG/CSS components, as in
3.1 and 3.5.

## Fabricated figures removed

The dashboard presented four constants as assessment findings. None was computed
from anything, and `NoFabricatedNumbersTest` exists because of exactly this
family of defect:

| Was | Now |
|---|---|
| `control_gaps => 0` on every unit, every tenant | column removed |
| `due_date => now()->addDays(30)` — the same invented date on every unit, moving forward a day with every page load | column removed |
| `control_effectiveness => 'partially'` on every top risk | the **weakest effectiveness among the risk's mapped controls**, or "Not assessed" when nothing is mapped |
| `action_required => 'Review control design and operating effectiveness'` on every top risk | column removed; the count of mapped controls is shown instead |

Weakest rather than average, because a risk with one ineffective control is not
two-thirds covered — it has a hole. `a_risk_with_no_mapped_controls_reports_no_effectiveness`
and `the_weakest_mapped_control_is_reported` pin both halves.

## The controls screen asked for columns that do not exist

`risk/rcsa/controls.blade.php` rendered nine columns. The `Control` model has
**none** of `type`, `risk`, `design_effectiveness`, `operating_effectiveness`,
`overall_rating`, `issues_count`, `assessor`, `assessed_at` — every one went
through `?? '-'` or `?? 0`, so **seven of the nine columns printed a dash for
every control on every tenant, forever**. The screen has been rebuilt on the
columns the model actually has: code, name, `control_type`, business unit,
owner, `effectiveness_rating`, mapped-risk count, `last_test_date`,
`next_test_due`. `the_controls_screen_reports_real_columns` pins it. Its three
filters (effectiveness, type, business unit) were already supported by the
controller and are now reachable from the screen.

## Dead work the port made live

- **The worksheet's `$risks` query.** The controller ran a paginated,
  node-scoped, eager-loaded query of the org's active risks with two filters —
  **and the view never referenced it.** Twenty rows of work discarded on every
  request. It now feeds a "Register Risk" picker on each worksheet line, which
  is what makes `risks.*.risk_id` reachable: the validator has always accepted
  that field and the service has always stored it, but no form ever posted it,
  so it could not be anything but null. `a_worksheet_line_can_be_linked_to_a_register_risk`
  covers it.
- **`$categories`.** Loaded and ignored, while the category select offered seven
  hardcoded English names. A tenant whose taxonomy is not those seven was
  offered categories it does not use. The select is the organisation's own
  `RiskCategory` list now.
- **The matrix's business-unit filter.** The `<select>` had no handler, so the
  controller's `business_unit_id` branch was unreachable from the screen.
  `the_matrix_filters_by_business_unit` pins it working.
- **The dashboard's "assessment cycle" select** had no handler and no name and
  is not ported. A real cycle filter is a feature, not a migration.

## A bug that hid a whole screen from the test suite

`topRisks` ordered by `FIELD(residual_rating, …)`, which is **MySQL-only**. On
SQLite — what the suite runs on — the RCSA dashboard threw, which is why no test
had ever covered it. It is a portable `CASE` expression now, and the
characterisation test could then be written at all. (In production, on MySQL,
the ordering worked; nothing user-visible changes.)

## Carried across deliberately unchanged

- **The overlapping assessment windows.** Completed = assessed inside 12 months;
  in progress = 12–18 months; not started = never; overdue = never **or** more
  than 12 months. Overdue therefore overlaps the other two — the six tiles are
  six independent answers, not a partition. The characterisation test pins 3
  overdue against 6 risks while completed + in progress + not started is 5.
- **The 80 / 50 per-unit status thresholds**, and `total` on the controls screen
  counting unrated controls (which is why total can exceed
  effective + partial + ineffective).
- **The ad-hoc campaign fallback.** A worksheet filed when no RCSA campaign is
  open opens one rather than being rejected. Refusing it would reintroduce the
  defect this write path was built to fix — a respondent's work failing to
  persist because of configuration they cannot change.
- **`RcsaWorksheetSubmitted`** fires on submit and not on draft, unchanged.
- **The rating band** comes from `RiskScoringService`, not a private copy. A copy
  here once used `>= 6` for Medium while the service used `>= 5`, so a risk
  scoring exactly 5 was Medium or Low depending on which screen recorded it.
  `the_rating_band_matches_the_scoring_service` asserts against the service
  rather than a literal, so the two cannot drift apart again.

## Deliberately not added

**No node scope on the worksheet's business unit.** A submission is filed
against a `BusinessUnit`, and `BusinessUnit` does not use `ScopedToGraph` —
there is no node column on it to scope by. A rule restricting which unit an
assessor may file for would have to invent that mapping, which is a design
decision about the object graph, not a migration. Tenancy on the id is enforced
by the tenant-bound `Rule::exists`, as before. Flagged here rather than guessed
at.

**The process field is optional, and now says so.** The Blade markup marked it
`required`; the server has always accepted it as nullable. The label matches the
rule rather than the other way round, because tightening a rule would reject
worksheets that are valid today.

## Existing assertions adapted

`tests/Feature/RcsaWorksheetSubmissionTest::the_worksheet_screen_lists_the_respondents_own_submissions`
asserted `assertSee('Your recent worksheets')` on the Blade worksheet. It reads
the `mySubmissions` prop now. **The question is unchanged** — can a respondent
see the work they filed from this screen — only where the answer is read from.
That file's other `assertSee`s target `risk.campaigns.submission`, which is
still Blade (campaigns is a later phase) and is untouched.

## PHPStan

Relations typed with generics on `RiskControlMapping` (`risk`, `control`) and
`CampaignAssignment` (`campaign`, `businessUnit`, `responses`).
`RcsaController::options()` is a template method: Eloquent generics are
invariant, so a `Collection<int, BusinessUnit>` is not a
`Collection<int, Model>` and typing it against `Model` would have made every
call site an error. `withCount` aliases (`total_risks`, `assessed`,
`high_risks`, `responses_count`) are read with `getAttribute()` — they are
query-time attributes, not columns, and declaring them on the model would claim
they always exist.

`phpstan-baseline.neon` is **90 lines shorter and gains nothing** — no entry
mentions this module. The four blocks covering the six errors red at HEAD anyway
were stripped back out after regeneration.

## Phase 3 acceptance criteria

- **Criterion 2** — `grep -rn "Gate::define" app/Providers` returns none of the
  six risk-module closures. Only `view-grid` (Phase 2's grid guard) and
  `approve-loss-event` (not a Phase 3 module) remain.
- **Criterion 3** — `grep -rn "'exists:" app/Http/Requests` returns nothing.
- **Criterion 5** — `resources/views/risk/rcsa` no longer exists, completing the
  set with scoping, register, assessments, controls, treatments, appetite,
  approvals and my-tasks. `resources/views/risk/workflows/designer.blade.php`
  still does, as Phase 6 requires.
