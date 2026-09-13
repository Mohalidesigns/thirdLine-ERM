# Phase 3.3 — Risk assessments: implementation notes

Branch `p3/assessments`, cut from the Phase 3.2 commit. Prompt: `05-phase-prompts/phase-3-core-risk.md` §3.3; common brief: `module-brief.md`.

The largest module in the phase: an 807-line controller and an 876-line Blade form carrying a thirteen-step chain that writes to five tables in one transaction.

## What landed

| Area | Delivered |
|---|---|
| Characterisation | `tests/Feature/Characterisation/AssessmentChainServiceTest.php` — ten cases pinning steps 4, 5, 7 and 8 with the expected numbers written out as literals rather than derived by calling the service, so the test disagrees with the service if the service moves. Green before and after. |
| **Decision 5b** | `POST risk/assessments/preview` → `AssessmentChainService::preview()`. The Alpine `assessmentChain()` mirror is deleted. |
| Policy | `app/Policies/RiskAssessmentPolicy.php` — `viewAny/view/create/update/submit/approve/reject/resubmit`. Permission first, then reach (organisation, then node scope **through the risk**, since an assessment is not a thing a branch is pinned to). Absorbs both inline `Gate::define` closures this module owned; they are deleted from `AppServiceProvider`. |
| Form Requests | `app/Http/Requests/Assessments/{StoreAssessmentRequest,UpdateAssessmentRequest,PreviewAssessmentRequest}.php`. `validateChain()` and `validateChainIntegrity()` moved wholesale; Update extends Store so a draft cannot validate differently from a new assessment. Every FK is tenant-scoped. |
| Service | `app/Services/Assessments/AssessmentService.php` — `selectableRisks()`, `formData()`, `detail()`, `persistChain()`, `syncCauses()`, `syncActionPlans()`, `syncKris()` and the presentation helpers. The arithmetic stays in `AssessmentChainService` / `RiskScoringService`; this composes them. |
| Controller | 807 lines → a thin controller: authorise, call a service, render. Lifecycle guards stay here (see the deviation below). |
| Pages | `Pages/Assessments/{SelectRisk,Create,Show}.jsx`, `hooks/useAssessmentPreview.js`, and two house chart components, `Components/{RadarChart,GroupedBarChart}.jsx`. `Create.jsx` is the five-stage stepper covering all thirteen steps, and serves edit as well. |
| Cross-renderer | `Ported::ROUTES` += `risk.assessments.create/show/edit`. Blade pages linking to `risk/assessments/create` (the sidebar, the bow-tie analysis screen) already go through `Ported::isPath`, so they full-page navigate. |
| Deleted | `resources/views/risk/assessments/{create,select-risk,show}.blade.php` — the directory is gone. |
| Model typing | Return types with generics on `RiskAssessment::{risk,assessor,reviewer,assessedControls}`, `RiskCause::category`, `RiskAssessmentControl::control`, `Risk::causes`, `TreatmentPlan::owner`; `@property` for `overall_score`, `overall_rating`, `impact_score`. Behaviour unchanged. |
| Tests | `tests/Feature/Assessments/{AssessmentsTestCase,PolicyTestSupport,RiskAssessmentPolicyTest,AssessmentPagesTest,AssessmentRequestsTest,AssessmentPreviewTest}.php` — 46 tests, plus the 10 characterisation cases. |

## Decision 5b — the preview endpoint

The prompt's preferred option, taken. The Alpine component it replaces had two problems, and the second is the one that mattered:

1. It was a **second implementation** of impact aggregation, effectiveness weighting, the axis split and the band lookup, written in JavaScript and free to drift from the server's.
2. It **could not evaluate a tenant's configured residual formula**. Its own comment said so — *"A tenant-configured formula is applied server-side; this preview does not attempt to evaluate it"* — and it fell back to the platform default. An organisation on a custom formula watched one number while typing and got a different one on save.

`AssessmentChainService::preview()` does not re-derive anything. It turns the posted chain into **unsaved `RiskAssessmentControl` instances** and then calls the same `effectiveness()` and `deriveResidual()` the save path calls. The equivalence is structural, not asserted — there is no second implementation to keep in step — and `AssessmentPreviewTest` proves it end to end for three scenarios (preventive controls, detective controls, and a tenant whose `residual_formula` is their own), by posting the same payload to the preview endpoint and to `store` and comparing the numbers.

`AssessmentPreviewTest::a_custom_residual_formula_moves_the_preview_off_the_platform_default` exists so the custom-formula scenario cannot pass vacuously: if the tenant formula happened to produce the platform default's answer, the fixture would prove nothing, and this fails loudly if that ever becomes true.

`AssessmentPagesTest::the_form_computes_no_scores_of_its_own` greps the page and the hook (comments stripped) for the arithmetic that used to live there. The failure mode this guards is somebody reintroducing a local calculation for responsiveness.

## Deviations from the prompt, with reasons

- **The policy is `RiskAssessmentPolicy`, not `AssessmentPolicy`.** Laravel discovers `App\Models\RiskAssessment` → `App\Policies\RiskAssessmentPolicy` by convention. Under the prompt's name the policy was never found, every ability fell through to false, and five workflow tests went red — which is how this was caught. The brief requires auto-discovery; the model's name wins.
- **The lifecycle rules are NOT in the policy.** "Only an in-review assessment can be approved", "only a draft can be edited" stay in the controller. Two reasons: the screens answer a wrong status with a flash message rather than a 403, and `WorkflowEngine::canAct()` asks the same `approve` ability about tasks — folding the status in would change what the engine considers actionable. `RiskAssessmentPolicyTest::the_lifecycle_rule_is_not_in_the_policy` pins both halves.
- **`RiskAssessmentBinding::gate()` now returns `'approve'`.** It named the deleted closure. `can('approve', $assessment)` resolves through the policy, so the engine's authorization is unchanged and "the assigned reviewer OR a risk-manager/CRO" has one implementation rather than two.
- **`EnforcesNodeScope` is kept, unlike Phase 3.2.** `RiskAssessment` does not use `ScopedToGraph`, so there is no route-binding 404 to inherit as there was for `Risk`. The controller keeps the explicit guard so a sibling branch's assessment is **not found** rather than forbidden — the caller may hold every permission in the product, and leaking the record's existence is itself a disclosure. The policy checks the same reach as defence in depth.
- **The charts are house SVG, not Chart.js.** Decision 3 permits Chart.js, but every chart the migration has ported so far is drawn by hand, and a chart dependency for one five-axis polygon is not the trade the rest of the product made. `RadarChart.jsx` and `GroupedBarChart.jsx` follow `DonutChart`/`HBarChart`.
- **Derived figures render "Not yet scored", never 0.** An unscored chain has no inherent risk; printing `0/25` beside a rating band is the fabrication `NoFabricatedNumbersTest` exists to stop, and it is the same call Phases 3.1 and 3.2 made.
- **Impact-dimension labels and the stage list moved into the service** as constants. They were literals in the Blade template, and the page should not decide which dimensions exist — the tenant's scoring profile does.

## Bugs found and fixed

- **`syncCauses()` crashed on a cause posted without a category or source.** It read `$row['cause_category_id']` directly out of `validated()`, which omits absent nullable keys. The Blade form always posted every field, so it never raised; a JSON client sending only a description got a 500. Both optional keys are now read defensively.

## Test assertions adapted

None. No existing test asserted Blade text on these routes — `AssessmentApprovalIntegrityTest`, `Workflow/*`, `Measures/*` and the grid tests all work against models and props and were untouched and green throughout.

## Not done / watch items

- **The two modules disagree about treatment vocabulary.** `RiskAssessment::TREATMENT_STRATEGIES` offers `avoid/reduce/share/transfer/accept`; `risks.treatment_strategy` accepts `mitigate/accept/transfer/avoid`. An assessment strategy of `reduce` therefore does not round-trip onto the risk as `mitigate`. Both are carried unchanged — reconciling them is a domain decision, and `AssessmentPagesTest` records the discrepancy in a comment so it is not mistaken for a porting slip.
- **`impact_strategic` has no column on `risks`** but does on `risk_assessments`, so the radar's fifth axis is only ever populated from assessments. Unchanged.
- **The preview fires on a 300 ms debounce and is not cancelled on unmount**, only sequenced — a late response is dropped rather than aborted. Matching `SearchBox`'s existing behaviour.
- **`npm run build` not run** (per the brief); JSX syntax-checked with esbuild.

## Verification

- `vendor/bin/phpunit` — 1505 tests, 9115 assertions, 4 failures (all `AssetResidencyTest`, expected in a worktree).
- `vendor/bin/pint` — pass on every file touched.
- `vendor/bin/phpstan analyse --memory-limit=2G` — 6 errors, the same six reported at HEAD, all in files this phase did not touch. The baseline shrank from 656 baselined errors to 616 (32 entries went stale once the chain's models were typed and were pruned); nothing was added to it.
- Acceptance criteria: (2) neither assessment closure remains in `app/Providers` — asserted by `RiskAssessmentPolicyTest`; (3) `grep -rn "'exists:" app/Http/Requests` is empty, asserted per module by `*RequestsTest`; (4) `AssessmentPreviewTest` covers the three scenarios including the custom residual formula.
