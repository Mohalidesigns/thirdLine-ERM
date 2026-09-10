# Phase 3.4 — Controls and control tests: implementation notes

Branch `p3/controls`, cut from the Phase 3.3 commit. Prompt: `05-phase-prompts/phase-3-core-risk.md` §3.4; common brief: `module-brief.md`.

Two controllers (`ControlController` 324 lines, `ControlTestController` 352) and seven Blade views. The prompt says nine views; the directory held seven — `index` had already gone in Phase 2, and there is no separate tests index.

## What landed

| Area | Delivered |
|---|---|
| Characterisation | `tests/Feature/Characterisation/ControlTestingDashboardTest.php` — six cases pinning the dashboard's counts, the overdue rule, the pass-rate formula to one decimal, the ten-row caps and their ordering, and tenant isolation. Written against the Blade view data, re-pointed at the Inertia props with the same expected numbers. |
| Policies | `app/Policies/ControlPolicy.php` and `app/Policies/ControlTestPolicy.php`. Permission first, then tenancy, then node scope — a test's reach resolved **through its control**, since a test is not a thing a branch is pinned to. `ControlTestPolicy` absorbs both inline `Gate::define` closures; they are deleted from `AppServiceProvider`. |
| Form Requests | `app/Http/Requests/Controls/` — eight: Store/Update for controls and tests, plus `LinkControlRiskRequest`, `ExecuteControlTestRequest`, `ReviewControlTestRequest`, `UploadControlTestEvidenceRequest`. Every FK tenant-scoped; the file rules are `FileUploadService`'s, so the request and the storage-time check cannot drift. |
| Model vocabularies | `Control::{TYPES,NATURES,FREQUENCIES,EFFECTIVENESS_RATINGS,STATUSES}` and `ControlTest::{TYPES,RESULTS,STATUSES}`, lifted out of the controllers' inline `in:` rules. The pages' selects are fed from the same constants the requests validate against, so a form cannot offer a value the server rejects. |
| Services | `app/Services/Controls/ControlService.php` (library, mappings, effectiveness recalculation) and `ControlTestService.php` (dashboard, lifecycle, evidence). The review DECISION stays in `ControlTestBinding`, so a review from My Tasks does what one from the test's page does. |
| Controllers | Both thin: authorise, call a service, render. Lifecycle guards stay in the controller (they answer with a flash message, not a 403). |
| Pages | `Pages/Controls/{Create,Edit,Show}.jsx` + shared `ControlForm.jsx`; `Pages/ControlTests/{Dashboard,Create,Edit,Show}.jsx` + shared `TestForm.jsx`. |
| Evidence | Upload through `useForm({ forceFormData: true })` → `UploadControlTestEvidenceRequest` → `FileUploadService`, unchanged in effect. Download stays a plain `<a href>`: the response is a file stream, not a page. |
| Cross-renderer | `Ported::ROUTES` += seven names. |
| Deleted | `resources/views/risk/controls/**` — the directory, including `tests/`, is gone. |
| Model typing | Return types with generics on `Control::{owner,businessUnit,risks,riskMappings,controlOwner,tests}` and `ControlTest::{control,tester,reviewer,creator,evidence}`. |
| Tests | `tests/Feature/Controls/{ControlsTestCase,ControlPoliciesTest,ControlPagesTest,ControlTestPagesTest}.php` — 35 tests. |

## Bugs found and fixed

Three, all of the same shape as Phase 3.2's `mapControl`: a write naming columns the table does not have, or nulling a column that is NOT NULL. None had been reported because each made its feature fail outright rather than subtly.

- **Linking a control to a risk was a constraint violation every time.** `linkToRisk()` wrote `weight`, `rationale`, `mapping_status` and `linked_by`. None of the four is a column of `risk_control_mapping`, and none is fillable, so mass assignment dropped all of them — leaving `mapping_rationale`, which is NOT NULL, unset. The real column names are `control_weight`, `mapping_rationale`, `is_key_control`; an absent weight is now left to the column's `1.00` default rather than overwritten with null. Covered by `ControlPagesTest::linking_a_control_to_a_risk_writes_the_columns_the_table_actually_has`.
- **Resubmitting a rejected control test was a 500.** `resubmit()` cleared `result` to null; `control_tests.result` is a NOT NULL enum whose "no result yet" value is `not_tested`. Covered by `ControlTestPagesTest::a_rejected_test_returns_to_the_tester_for_rework`.
- **Control tests had no node-scope guard at all.** `show`, `edit`, `update` and `startTest` checked nothing beyond the tenant global scope that route binding applies. A test belonging to a sibling branch was readable and editable by typing its id, while the same branch's controls were not. Now guarded, 404 rather than 403, resolved through the control.

## Deviations from the prompt, with reasons

- **The two folded abilities keep their hyphenated names.** `WorkflowEngine::canAct()` asks `can('review-control-test', $test)` through `ControlTestBinding::gate()`. Laravel camel-cases a hyphenated ability on a model, so it lands on `ControlTestPolicy::reviewControlTest()`, which delegates to `review()`. The binding therefore did not have to change — which is a different call from Phase 3.3, where `RiskAssessmentBinding::gate()` was repointed to `'approve'`. Both work; this one touches less outside the module, and `ControlPoliciesTest::the_workflow_engines_spelling_of_the_ability_still_resolves` pins it.
- **No `ReviewDialog`.** The prompt asks for one as a `ConfirmDialog` variant. `ConfirmDialog` sits on `Modal`, the unverified Headless UI `<Transition>` watch item that every phase so far has avoided, and the review needs a form (notes, or a required rejection reason) rather than a confirmation. It is an inline panel, as the Blade page's `x-data="{ rejecting: false }"` was and as Phase 3.3's assessment review is.
- **`Control::EFFECTIVENESS_RATINGS` has three values, not five.** The library form has always offered `effective / partially_effective / ineffective`, while `RiskAssessmentControl::RATINGS` adds `mostly_effective` and `not_operating`. `Control::$effectiveness_label` already renders all five, so a control rated through an assessment displays correctly. Widening the library's list is a domain decision; the constant records the discrepancy rather than resolving it.
- **`ControlTestController` does not use `EnforcesNodeScope`.** It has the same guard inlined as a private method. The trait also carries `abortUnlessNodeVisible()`, which this controller never calls and which does not type-check in a context where the record's model is unknown — pulling in the trait would have added a PHPStan error for a method that is never reached.
- **`passRate` stays a figure at zero rather than an unavailable tile.** Unlike a KPI standing in for something unmeasured, this one IS measured: zero of zero completed tests passed. The subtitle says "Nothing completed yet" when that is the case.
- **Delete and unlink confirmations use `window.confirm`**, as every phase since Phase 2 has.

## Test assertions adapted

- `tests/Feature/Characterisation/ControlTestingDashboardTest.php` — `$response->original->getData()` → `inertiaProps()`. One assertion gained a `(float)` cast: `round(100.0, 1)` crosses JSON as int `100` because `json_encode` drops the zero fraction, while `66.7` stays a float. The number is unchanged.
- `tests/Feature/Metadata/DynamicRendererTest.php` — five cases asserted on Blade markup (`name="..."`, `value="x" selected`). They now read the `schema` and `control` props. One changed in substance: `the_control_create_form_renders_every_configured_field` asserted that EVERY configured field renders under its column name; a column-backed attribute is now an input the bespoke form owns and is deliberately stripped from the schema, so the test asserts the split instead — unmapped fields reach the schema, mapped ones do not.
- `tests/Feature/Metadata/DynamicDetailIntegrationTest.php` — two cases. The "not rendered twice" test counted occurrences of "Business Unit" in the HTML; it now asserts directly that `business_unit_id` is absent from `configuredDetail`, which is what the omit list is for.

## Not done / watch items

- **`ControlService::nextControlCode()` keeps the old `CTL-%04d` from `max(id)+1`** rather than moving to `ReferenceCodeService`, which has no control prefix. A collision is theoretically possible under concurrent creates, exactly as before.
- **`Control::updateTestStats()` is called on completion only**, so a test deleted afterwards leaves the rolling counters stale. Unchanged.
- **`npm run build` not run** (per the brief); JSX syntax-checked with esbuild.

## Verification

- `vendor/bin/phpunit` — 1546 tests, 9519 assertions, 4 failures (all `AssetResidencyTest`, expected in a worktree).
- `vendor/bin/pint` — pass on every file touched.
- `vendor/bin/phpstan analyse --memory-limit=2G` — 6 errors, the same six reported at HEAD, all in files this phase did not touch. The baseline shrank from 616 baselined errors to 602; nothing was added to it.
- Acceptance criteria: (2) neither control-test closure remains in `app/Providers` — asserted by `ControlPoliciesTest`; (3) `grep -rn "'exists:" app/Http/Requests` is empty, asserted per module; (5) `resources/views/risk/controls` no longer exists.
