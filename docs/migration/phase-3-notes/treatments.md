# Phase 3.5 — Treatment plans

`Risk/TreatmentPlanController` (610 lines) and five Blade views (1,292 lines)
onto Inertia. `risk.treatments.index` was already an Inertia page from Phase 2's
grid work; the other five routes move here and the five views are deleted in the
same commit.

## What landed

| Piece | Where |
|---|---|
| Policy | `app/Policies/TreatmentPlanPolicy.php` |
| Form Requests | `app/Http/Requests/Treatments/{Store,Update}TreatmentPlanRequest`, `{Approve,Reject}TreatmentPlanRequest`, `StoreTreatmentCommentRequest` |
| Service | `app/Services/Treatments/TreatmentPlanService.php` |
| Pages | `resources/js/Pages/Treatments/{Dashboard,Review,Create,Edit,Show}.jsx`, `TreatmentForm.jsx`, `format.js` |
| Characterisation | `tests/Feature/Characterisation/TreatmentDashboardStatsTest.php` |
| Module tests | `tests/Feature/Treatments/{TreatmentsTestCase,TreatmentPagesTest,TreatmentPoliciesTest}.php` |

`Ported::ROUTES` gains the five route names (58 → 63). The controller drops from
610 lines to 330, and every figure it computed inline is now in the service
where a test can reach it.

## The last two inline gates are gone

`approve-treatment-plan` and `resubmit-treatment-plan` moved out of
`AppServiceProvider` into the policy. **Only `view-grid` and
`approve-loss-event` remain there**, and `approve-loss-event` is not a Phase 3
module.

Both abilities keep their hyphenated names, following 3.4 rather than 3.3:
`TreatmentPlanBinding::gate()` returns `'approve-treatment-plan'` and
`WorkflowEngine::canAct()` asks for it by that name, so the policy carries
`approveTreatmentPlan()` and `resubmitTreatmentPlan()` as delegates and **the
binding is untouched**. `TreatmentPoliciesTest::the_workflow_spelling_reaches_the_policy`
pins that, and `the_absorbed_gate_closures_are_gone` asserts `Gate::has()` is
false for both — an ability with neither a closure nor a policy method denies
silently.

The lifecycle rule ("only a `pending_review` plan can be approved") stays in the
controller, where it answers with a flash message rather than a 403.
`approving_does_not_depend_on_the_status` pins that the policy ignores status.

## Deviations, and why

**1. `avgEffectiveness` — a no-op filter removed, the figure unchanged.** The
Blade controller computed `whereNotNull('progress_pct')->avg('progress_pct')`,
which reads as "average only the plans with recorded progress". It never did
that: `progress_pct` is `smallInteger()->default(0)` and **NOT NULL**
(`2026_02_22_200010`), so nothing could be filtered out and a not-started plan's
0 has always dragged the mean down. The filter is dropped; the number is
identical. This was caught by running the Blade screen first — the leftover
characterisation test in the worktree asserted **50** (the value the code looks
like it produces), and the real screen produces **38**. The test now pins 38 and
says why.

**2. Charts are house SVG/CSS components, not `Widget`s.** The phase prompt says
the dashboard's charts become `Widget`s. They are `DonutChart`, `HBarChart`,
`TrendChart` and a local `BudgetBars` instead, following the entity dashboard
(3.1), which faced the same choice. The widget engine is for tiles a tenant
*composes onto a dashboard* through `WidgetDefinition` rows; these four are
fixed furniture on one module's own screen, and routing them through
`WidgetDataService` would have meant seeding four definitions and a
`TreatmentProgressResolver` to render what four existing components already
render. No Chart.js was hand-built, which is what the instruction guards
against.

**3. Plans by Status is bars, not a donut.** The five status bands **overlap** —
an overdue plan is counted under In Progress as well — so they do not partition
a whole and a donut would state a falsehood about the data. The Blade chart was
a bar chart for the same reason. `the_chart_series` pins that the five bands sum
to 5 across 4 plans.

**4. Reject on the review page now has somewhere to type the reason.** The Blade
review screen posted Approve and Reject as bare forms with no body, but
`reject()` has always required `rejection_reason`. **That button could only ever
return a validation error.** The React page opens the same reason box the plan's
own page uses. `rejecting_requires_a_reason_and_records_it` covers both halves.

**5. The comment box is required.** It was
`$request->input('comment', 'Comment added')`, so submitting an empty textarea
wrote the literal string `Comment added` into `risk_audit_trail` — a placeholder
in the one table an examiner reads. `StoreTreatmentCommentRequest` requires it.

**6. The dead period filter is gone.** The dashboard header carried a
`<select id="periodFilter">` with four ranges and **no JavaScript bound to it**;
changing it did nothing. It is not ported. Re-introducing it means a real
server-side period, which is a feature, not a migration.

**7. The empty progress chart is gone.** `show()` built
`$progressHistory = ['labels' => [], 'values' => []]` and never filled it, and
the view drew the canvas only `@if (!empty($progressHistory['labels']))` — i.e.
never. Nothing renders it now. There is no progress history table to read, and
inventing a series would be a fabricated figure.

**8. `AiDraftButton` is now generic.** The Treatment Plan Builder and the Risk
Statement Builder were the same Alpine card with different copy, endpoint and
draft mapping, so those three are props and `Register/Create.jsx` passes its
own. The treatment card is gated on `can('ai.use')` — the permission the route
actually requires. Note `Register/Create` gates its card on
`config('features.ai_intelligence') && can('ai.view')`, while
`risk.ai.tools.*` sits behind `permission:ai.use` alone: **a user with `ai.view`
but not `ai.use` still sees a button that 403s on the register page.** Not this
module's to change, but it should be.

**9. Node scoping moved from the controller into the policy.** Six
`abortUnlessNodeVisibleThrough($treatment, 'risk')` calls and six hand-written
`organization_id` comparisons are replaced by `TreatmentPlanPolicy::withinReach()`,
which resolves the same subtree question through `Risk::visibleTo()` — the query
the grids use. `EnforcesNodeScope` is no longer used by this controller.

**10. `status` on the edit form is a closed list.** `TreatmentPlan::EDITABLE_STATUSES`
is exactly the controller's old inline `in:` rule, which deliberately excludes
`draft`, `approved`, `rejected` and `on_hold` — those belong to the approval
path. `the_edit_form_cannot_set_a_status_the_approval_path_owns` pins it, so an
owner cannot approve their own plan by picking a value.

## Things carried across deliberately unchanged

- **Both spellings of in-progress.** `ACTIVE_STATUSES` includes `in_progress`
  *and* `in-progress` (and `open`), because both are in the data — the column
  was a free string before it was constrained — and the Blade dashboard counted
  both. `RUNNING_STATUSES` is the same set minus `not_started`: a plan nobody
  has started is not late. Two constants because the dashboard genuinely used
  two different lists.
- **`upcomingDeadlines` bounds only the top of the window**, so an already-overdue
  plan appears in it. That is what the Blade query did.
- **The activity feed says "was updated" for every row**, derived from
  `updated_at`. There is no per-plan activity log to read; an action verb would
  be invented.
- **`TreatmentCompleted` (Wave 3) is untouched** and still fires on the
  transition into `completed`, not on the status — `re_saving_a_completed_plan_does_not_re_fire_the_event`
  pins that.
- **The 200038 → canonical column mapping**, which the controller carried as two
  private consts, is `TreatmentPlanService::FIELD_MAP` / `PASSTHROUGH_FIELDS`.
  The forms still post `treatment_title` etc. because those are the
  `FormFieldRegistry` attribute codes; the table still holds `action_title`.
  One mapping, one place. See `docs/schema/canonical-columns.md`.

## Known gap

A tenant-configured (unmapped) field is captured by the create/edit forms and
saved by `PersistsConfiguredAttributes`, but **the show page does not display
it** — the Blade show page did not either, so this is carried parity, not a
regression. `Register/Show.jsx` solves it with `FormSchemaPresenter::detail()`;
doing the same here is a small follow-up.

## Existing assertions adapted

`tests/Feature/Metadata/DynamicRendererTest::a_form_offers_the_organizations_own_scoring_scale_not_a_hardcoded_five`
used `risk.treatments.create` as its worked example and asserted on the Blade
markup (`name="expected_residual_impact"`, and no `<option value="5">`). It now
reads the same two facts off the Inertia schema the React `DynamicForm` renders
from. **The question it asks is unchanged** — does a 4×4 organisation get a
four-point scale — only where the answer is read from.

No other existing test referenced these five routes.

## PHPStan

`TreatmentPlan::risk()` and `organization()` are typed with generics, and the
model gains an `@property` block for the columns
`2026_02_22_200038_align_schema_with_controllers` adds through its own
`addColumns()` loop — Larastan reads schema from `Schema::create`/`table` calls
it can see statically, so those columns were invisible and every read of one was
reported as an undefined property.

`phpstan-baseline.neon` is **72 lines shorter and gains nothing**: regenerating
it made ~66 lines of entries stale, and the four blocks covering the six errors
that are red at HEAD anyway (4 `nullsafe.neverNull` in `WorkflowPresenter`, 2
`argument.type` in the `Approval`/`MyTask` controllers) were stripped back out so
this commit adds no suppression.
