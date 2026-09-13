# P3 — cycles and the assessment workspace

Steps 1 to 6 of the process flow: pick the exercise, pick the unit, find it
already populated, rate inherent risk, rate the controls, watch residual fall
out.

## What landed

| Piece | Where |
|---|---|
| Models | `app/Models/Rcsa/{RcsaCycle,RcsaAssessment,RcsaAssessmentLine,RcsaActionPlan,RcsaLineRevision}.php` |
| Provisioning | `app/Services/Rcsa/RcsaCycleService.php` |
| The save path | `app/Services/Rcsa/RcsaAssessmentService.php` |
| Policies | `app/Policies/Rcsa/{RcsaCyclePolicy,RcsaAssessmentPolicy}.php` (discovered) |
| Form Requests | `app/Http/Requests/Rcsa/{StoreCycle,OpenCycle,UpdateAssessmentLine}Request.php` |
| Controllers | `app/Http/Controllers/Rcsa/{CycleController,AssessmentController}.php` |
| Routes | fourteen, under `rcsa.cycles.*` and `rcsa.assessments.*`, behind `feature:rcsa_v2` |
| Permissions | six new, `rcsa_cycle.*` and `rcsa_assessment.*`, + `2026_09_07_100001` grant |
| Pages | `resources/js/Pages/RcsaCycles/{Index,Show}.jsx`, `RcsaAssessments/{Index,Workspace,GuidedStep,HeatPosition}.jsx` |
| Tests | `tests/Feature/Rcsa/{CycleTestCase,CycleProvisioningTest,WorkspaceTest,CycleAuthorizationTest}.php` — 38 tests |

## Acceptance criteria

> *Steps 1–6 of the flow work end to end; calculated columns match the template
> on a 50-line assessment; two concurrent users cannot silently overwrite each
> other.*

**Fifty lines.** `fifty_lines_scored_through_the_endpoint_match_the_workbook`
provisions fifty risks, scores every one **through the real HTTP endpoint**
with fifty of the truth table's hundred combinations, and then checks all seven
calculated columns on all fifty lines against P0's fixture. It takes the
combinations at even indices rather than the first fifty, which would all be
likelihood 1 and 2.

**Concurrent writers.** `a_second_writer_working_from_a_stale_version_is_refused`
has two users read the same version; the first saves, the second is answered
409 with the first user's answer attached, and the first user's values stand.
There is a second, softer mechanism as well — an advisory lock that answers 423
while somebody has a row open in guided mode, with an expiry so a closed laptop
cannot hold an assessment hostage.

**Steps 1–6** were driven in a browser: cycle → assessment → grid, set
likelihood 5 and impact 5 (inherent painted **25 before** any control rating,
which is the local mirror doing its job), then Not Achieved → residual **18.8**,
treatment **treat**, progress **20%**, and the validation panel changing that
line from "not assessed" to "above risk appetite with no action plan". Guided
mode showed the same line with all six impact-criteria dimensions expanded, the
control-effectiveness bands and description, and the live heat position.

## The decisions that matter

- **Opening is one-way, and refuses rather than re-provisioning.** A second
  `open()` on an open cycle throws. Provisioning twice would either duplicate
  every line or discard scoring already done, and an assessment whose line
  count changes underneath the assessor is not a document anybody can sign. A
  risk added to the universe afterwards belongs to the *next* cycle, and the
  confirmation dialog says so before the button is pressed.
- **The line is a snapshot, and the test proves it.**
  `a_line_is_a_snapshot_that_a_later_universe_edit_cannot_rewrite` rewrites the
  risk statement, the driver, the category and deletes the controls *after*
  opening, then asserts the line is unchanged. This is the single most
  important property of the schema: editing master data in November must not
  rewrite what June's assessment said.
- **Opening locks the methodology.** Once assessments exist, re-cutting a band
  would silently re-rate every line already scored.
- **Only *scored* prior lines are offered as a comparison.** A previous cycle
  somebody opened and never filled in gives no basis for "has this moved", and
  offering one would invite an assessor to accept a blank as agreement.
- **The cycle freezes its assessments, not the other way round.** `acceptsEdits()`
  consults both. Without the cycle check, a unit left `in_progress` when the
  quarter closed would go on accepting edits and drift away from the figures the
  closed cycle reported.
- **The client's calculated values are discarded.**
  `calculated_columns_sent_by_the_client_are_ignored` posts a crafted request
  claiming a 25-score risk is harmless, and asserts the stored figures are the
  real ones. `apply()` reads only the assessed inputs.
- **Every material change is revisioned, including bulk ones.** Bulk apply goes
  through the same `apply()`, so a bulk action cannot become the easy way to
  change sixty ratings without a record. A save that changes nothing writes no
  revision — an autosaving grid fires on blur whether or not anything moved, and
  a trail full of "3 → 3" is a trail nobody reads.
- **Line enums are validated against the line's own methodology**, not the
  active one. Otherwise every historical line would become unsavable the morning
  a new methodology went live.

## Deviations from the plan

### 1. The workspace sends every line, and does not paginate

§8.2 asks for keyboard navigation, a sticky header and a sticky first column.
Those are incompatible with pagination — an assessor pressing Down at row 25
must not trigger a page load — and the progress panel counts every line anyway.
Five hundred lines of this shape is a few hundred kilobytes of JSON. If a tenant
ever runs an assessment large enough to hurt, the answer is virtualised rows on
the client, not pagination; the note is in `AssessmentController`'s docblock so
whoever hits it knows the reasoning rather than reinventing it.

### 2. Pages live in `RcsaCycles/` and `RcsaAssessments/`, not `Rcsa/`

The legacy module's pages are in `resources/js/Pages/Rcsa/`, and a new
`Rcsa/Workspace` would have sat directly beside the legacy `Rcsa/Worksheet` —
two near-identical names for unrelated screens. Every v2 page is in its own
top-level folder, as P1 and P2 did with `RcsaUniverse/`, which also makes the
P8 cutover a matter of deleting one directory.

### 3. `rcsa_cycle.manage` rather than the plan's `create`

§11 lists `rcsa.cycle.view|create|open|close`. Creating and editing a cycle are
one authority in practice — both are "shape the exercise" — while `open` is
genuinely different because it is irreversible. Three permissions rather than
four, and the one that got its own is the one that needed it.

### 4. Assessment assignment is not built

`rcsa_assessments.assigned_to` and `reviewer_id` are provisioned null. Who
should fill in a unit's RCSA is a routing decision that belongs with the review
workflow in P5, and inventing a rule now — the unit head? the risk champion? —
would be guessing at §14 Q6 and Q8. The columns exist and the screens show
"Unassigned".

### 5. AI assist is not built

§8.3's suggestions are the plan's own optional P6.5 and explicitly "a
differentiator, not a dependency".

## Verification

- `php artisan test --filter="Cycle|Workspace"` — 38 passed.
- Full suite: **2,162 passed, 4 skipped** (2,114 before this phase). Thirty-eight
  of the forty-eight new tests are P3's own; the other ten are `TenancyIsolationTest`
  generating its per-model checks over the five new models — which is the
  recursive-discovery fix from `70552a7` paying for itself, and means every RCSA
  v2 model is now covered by the tenancy guard without anyone listing it.
- PHPStan clean on the new paths; Pint clean.
- `NavigationPermissionGateTest`'s nav-route tally moves 79 → 81 for the two new
  links. Bookkeeping; the two substantive assertions beside it — nav permission
  equals route guard, nav feature flag equals route middleware — stayed green
  and are what proved the links are wired correctly.
- Driven end to end in a browser against MySQL, as described above.

## What P4 needs from this

- `RcsaAssessmentService::outstanding()` is already the submission gate's list —
  it returns unscored lines, above-appetite lines with no complete plan,
  unjustified overrides and unexplained material movement, each with a
  `line_id` for the jump link. P4 wires it to a Submit button and a state
  transition; it does not need new rules.
- `RcsaActionPlan` exists with `isComplete()` and the workspace shows
  `action_plans_count`, but nothing writes plans yet. That is P4's first job.
- `RcsaAssessment::EDITABLE` is the list P4 extends when it adds `submitted`.
