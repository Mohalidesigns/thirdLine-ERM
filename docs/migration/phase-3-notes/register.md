# Phase 3.2 — Risk register: implementation notes

Branch `p3/register`. Prompt: `05-phase-prompts/phase-3-core-risk.md` §3.2; common brief: `module-brief.md`.

## What landed

| Area | Delivered |
|---|---|
| Characterisation | `tests/Feature/Characterisation/RiskRegisterScoringTest.php` — written against the Blade screens first (the KPI tiles were parsed out of the rendered HTML, because the figures lived in an `@php` block in the view), then re-pointed at the `metrics` prop with the same expected numbers. Pins: which scores the detail screen shows and where they come from; the three-source priority for control effectiveness; and the create/update disagreement about collapsing impact dimensions. |
| Policy | `app/Policies/RiskPolicy.php` — `viewAny/view/create/update/delete` on `risk.view/create/edit/delete`, plus `mapControl` and `updateAttributes` which both ride on `update`. Permission first, then organisation, then node scope (`GraphScope::isSubtreeLimited` → the same `visibleTo()` query the grids use, so a record hidden from a list is not reachable by policy either). Discovered by convention; asserted with `Gate::getPolicyFor`. No `Gate::define` closure belonged to this module, none deleted. |
| Form Requests | `app/Http/Requests/Register/{StoreRiskRequest,UpdateRiskRequest,MapControlRequest,UpdateRiskAttributesRequest}.php`. Every foreign key is `Rule::exists(...)->where('organization_id', TenantContext::organizationId())`; a test asserts the string form `'exists:` appears in none of them, and each FK has a cross-tenant rejection case. Configured attributes via `ValidatesConfiguredAttributes`. |
| Service | `app/Services/Register/RiskRegisterService.php` — read side (`detail`, `metrics`, `present`, `unmappedControls`, `formOptions`) and write side (`create`, `update`, `delete`, `mapControl`, `audit`). The scoring is carried across unchanged, asymmetry included. |
| Controller | `RiskRegisterController` constructor-injects `RiskRegisterService` + `FormSchemaPresenter`; each action authorises (`Gate::authorize` / Form Request) → service → `Inertia::render` or redirect with flash. `EnforcesNodeScope` dropped: route binding 404s an out-of-subtree record and the policy is the second line, as in Phase 3.1. `index` unchanged from Phase 2 apart from `viewAny` authorisation and the historic branch. |
| Pages | `Pages/Register/{Create,Edit,Show,Historic}.jsx`, shared `Pages/Register/RiskForm.jsx` (with `seedConfigured`/`toPayload`), `Components/AiDraftButton.jsx`. `Show` carries all seven tabs (Overview, Controls, Assessment, Treatment, KRIs, Attributes, History) as local state. `Index.jsx` from Phase 2 kept; its "New Risk" button is now an Inertia `<Link>`. |
| Cross-renderer | `Ported::ROUTES` += `risk.register.create/show/edit`. `risk.register.index` was already there and covers both the live grid and the as-at page. Links to assessments / controls / treatments / KRIs stay plain `<a href>` (still Blade) via `tryRoute`. |
| New route | `PATCH risk/register/{register}/attributes` → `risk.register.attributes`, for the Attributes tab. The Blade page edited those fields through a Livewire component that saved itself; there was no HTTP route to port. |
| Deleted | `resources/views/risk/register/{create,edit,show,historic}.blade.php` — the directory is gone. |
| Model typing | Return types with generics on `Risk::{entity,businessUnit,assessments,treatmentPlans,auditTrail,controls,kris,controlMappings,keyRiskIndicators,auditTrails}`, and `@property` for `inherent_score`, `inherent_rating`, `risk_type`. Behaviour unchanged. |
| Tests | `tests/Feature/Register/{RegisterTestCase,RiskPolicyTest,RiskPagesTest,RiskRequestsTest}.php` (36 tests) + the characterisation test above (9). |

## Bugs found and fixed

Three of these are in one action, which is why none of them had ever been reported: `mapControl` could not run at all.

- **Mapping a control always returned 403.** The route parameter is `{register}`; the action's parameter was `$risk`, so implicit binding matched nothing and the action received an empty `Risk` whose `organization_id` was null — which failed the tenancy check on the next line. Renamed to `$register`; `MapControlRequest` reads `$this->route('register')` to match. Covered by `RiskPagesTest::mapping_a_control_attaches_it`.
- **`risk_control_mapping.mapping_rationale` is NOT NULL**, and `control_weight` is NOT NULL with a default of `1.00`, while both inputs are optional on the form. The controller wrote `?? null` into each. An absent weight is now left to the column default rather than overwritten with null; an absent rationale is stored as the empty string the column can hold.
- **Editing a risk with Risk Source left blank was a 500.** `risks.risk_source` is NOT NULL and `update()` assigned `?? null`; the select offers an empty option, and `ConvertEmptyStringsToNull` turns it into one. The stored value is now kept, which is what leaving a field untouched implies. Covered by `RiskPagesTest::updating_a_risk_without_a_risk_source_keeps_the_stored_one`.

## Deviations from the prompt, with reasons

- **An unassessed risk reports no inherent score instead of "0/25 · Low".** `Risk::inherentScore()` is an accessor that returns `likelihood × impact` when the column is null, and `inherentRating()` bands whatever that produces — so two nulls multiplied to 0 and banded as "Low" appeared on the KPI tile of every newly-created risk. The accessor is untouched (grids, widgets and exports all read it); `RiskRegisterService::metrics()` decides whether the tile has anything to show, and `KpiCard`'s `unavailable` state says so when it does not. This is the same call Phase 3.1 made on "Exceeding Appetite", and the line `NoFabricatedNumbersTest` exists to hold.
- **Control effectiveness with nothing mapped shows "Not measured" rather than 0%.** Same reasoning; the subtitle ("No controls mapped") is unchanged.
- **A control with no effectiveness rating shows N/A rather than a 0% bar.** A zero-width red bar reads as "measured, and terrible".
- **The prompt says four `fetch()` AI draft helpers on the create page; there is one** (`risk.ai.tools.risk-statement`). Ported as `AiDraftButton.jsx` with axios.
- **The AI card is now feature-gated.** Its route sits behind `feature:ai_intelligence` + `permission:ai.view`; the Blade page drew the card unconditionally, so a tenant without the feature got a button that 404'd. The server sends `canDraftWithAi`.
- **`description` and `notes` stay plain textareas, not `RichTextEditor`s** — the prompt's default for narrative fields. Three things read `risks.description` as text: `RisksGrid` excerpts it to 80 characters, search tokenises it, and the AI Risk Statement Builder writes a plain Cause/Event/Consequence block into it. Editor.js JSON in any of those is a regression, and none of them is this module's to change.
- **The inherent-score preview on the form is labelled a preview.** It mirrors `likelihood × max(impact)`, which is what the create path computes on a default profile; a tenant on an `average` or `weighted` profile stores something else. The old Blade page printed the formula as a promise and computed nothing.
- **Configured attributes are edited on the Attributes tab and read-only on Overview** (`configuredDetail`, with the same `omit` list the Blade page carried), which is the prompt's instruction for avoiding the Wave 2 "attribute appears twice" complaint. Column-backed fields are stripped from the schema on every form — the bespoke inputs own those columns.
- **The as-at register is its own page rather than the DataGrid**, unchanged from Phase 2's reasoning: the scores are measure-engine overlay values that exist only in memory, so there is no SQL column to sort or filter on. Filtering, sorting and paging stay server-side over the overlaid collection.
- **Delete confirmation uses `window.confirm`**, as Phase 2 and 3.1 did — `ConfirmDialog` sits on the unverified Headless UI `<Transition>` watch item.
- **`AssessmentChainService::controlsFor()` gained a private `controlRow()` method.** Typing `Risk::controls()` let PHPStan infer the map closure's precise array shape, which `Collection`'s invariant value template will not accept as the declared `array<string, mixed>`; extracting the row so its return type is declared is the fix. No behaviour change — the body is the old closure verbatim.
- **Columns on `RiskAssessment`, `TreatmentPlan`, `KeyRiskIndicator` and `Control` are read with `data_get()`**, the line Phase 3.1 drew: typing those models belongs to the phase that ports them (3.3, 3.5, and 3.4).

## Test assertions adapted

- `tests/Feature/Grid/RisksGridTest.php` — asserted `views/risk/register/historic.blade.php` still existed. Now asserts the whole `views/risk/register` directory is gone and `js/Pages/Register/Historic.jsx` is there.
- `tests/Feature/Measures/PeriodSelectorTest.php` — read the as-at rows from `viewData('risks')` and the banner from rendered text. Now reads `asOfPeriod.name` and `risks.data` from the Inertia props; the expected scores and ratings are unchanged.
- `tests/Feature/Metadata/DynamicDetailIntegrationTest.php` — asserted the formatted money string appeared in the detail page's HTML. Now reads the `configuredDetail` prop and asserts the same `'NGN 2,500.50'` on the field. The point of the test survives: `configured` (the Attributes tab) carries the raw minor-unit value, so a formatted string can still only have come from the detail renderer.

## Not done / watch items

- **`create()` and `update()` still collapse the impact dimensions differently.** Create runs them through `RiskScoringService` (profile-aware); update takes the plain maximum and ignores the profile. Editing a risk without touching its impacts can therefore change its stored score on a non-default profile. Both are pinned by the characterisation test. Unifying them is a scoring decision for whoever owns `ScoringProfile`, not a porting one.
- **The as-at page lazy-loads `category`, `businessUnit` and `riskOwner` per row** (25 rows a page), exactly as the Blade view did. `RiskRepository::asOf` returns models without those relations; eager-loading them is a change to a repository shared with the dashboard widget resolvers.
- **`npm run build` not run** (per the brief); JSX syntax-checked with esbuild.
- `AssetResidencyTest`'s four failures are the expected worktree ones — it needs `public/build/manifest.json`, which only the main checkout builds.

## Verification

- `vendor/bin/phpunit` — 1455 tests, 8819 assertions, 4 failures (all `AssetResidencyTest`, expected in a worktree).
- `vendor/bin/pint` — pass on every file touched.
- `vendor/bin/phpstan analyse --memory-limit=2G` — 6 errors, the same six reported at HEAD, all in files this phase did not touch. The baseline shrank from 818 baselined errors to 656 (99 entries went stale once `Risk`'s relations were typed and were pruned); nothing was added to it.
