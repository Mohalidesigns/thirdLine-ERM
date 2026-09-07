# P4 — appetite, action plans and the submission gate

Step 7 and step 8: a risk above appetite cannot be filed without a plan, and
submission is what hands the work to the second line.

## What landed

| Piece | Where |
|---|---|
| The gate | `app/Services/Rcsa/RcsaSubmissionService.php` |
| Action plan rules | `app/Http/Requests/Rcsa/StoreActionPlanRequest.php` |
| Endpoints | `AssessmentController::{storePlan,updatePlan,destroyPlan,submit}` |
| Snapshot | `resources/views/reports/pdf/rcsa-snapshot.blade.php` |
| Permission | `rcsa_assessment.submit` + `2026_09_07_100002` grant |
| Pages | `resources/js/Pages/RcsaAssessments/ActionPlans.jsx`, plus the workspace's submit button |
| Tests | `tests/Feature/Rcsa/SubmissionTest.php` — 17 tests |

## The acceptance criterion

> *Step 7 behaves per rule 6 — submission is impossible with an incomplete
> above-appetite line.*

`an_above_appetite_line_without_a_complete_plan_blocks_submission` walks the
whole rule in one test: score a risk to 18.75 (above appetite), try to submit —
refused; add a plan **missing its owner**, try again — still refused, because a
control with no owner is a wish; fill the owner in, and it goes through. The
partial-plan step is the one that matters, since a gate that only checks
"is there a row" would have passed it.

`a_line_within_appetite_needs_no_plan` is the other half — the block does not
appear where it is not required, and a within-appetite assessment submits with
no plans at all.

## What submission does, and why all of it or none

Four effects, in one transaction: every line locks, a PDF snapshot is written,
the ORM is notified, the status moves. Doing three of them is worse than doing
none — an assessment that is half-submitted is editable by nobody and reviewable
by nobody.

Two deliberate exceptions to that:

- **The PDF is rendered before the transaction opens.** dompdf on a 500-line
  assessment is seconds of CPU, and holding a write transaction across it would
  block every other save in the unit for its duration.
- **A snapshot that cannot be rendered does not fail the submission.** A
  rendering library falling over at the end of a quarter's work must not be
  what stops a risk champion filing it. The path stays null, the failure is
  logged, and `a_snapshot_that_cannot_be_rendered_does_not_block_the_submission`
  pins that by making the renderer throw.

**The snapshot is evidence, not a report.** It is rendered once, at the moment
of submission, and never regenerated: a PDF produced later from live rows is
not what was filed, it is a re-render under whatever the data has since become.
Its columns are the workbook's, in the workbook's order, with the treatment plan
as its own section — so somebody holding it beside `SB_RCSA Template 2026` is
reading the same document.

## Decisions worth knowing

- **Submitting is a different permission from completing.** Answering the three
  columns is ordinary work; submission locks every line and hands the
  assessment on. Keeping them apart is what lets a tenant answer §14 Q6 (does a
  BU Head approve first?) by taking `rcsa_assessment.submit` off the champion,
  without a code change.
- **A line somebody else has open blocks submission.** Not because of a data
  race — the version check already handles that — but because submitting would
  freeze a colleague's work in whatever half-finished state it was in. Your own
  lock does not block you.
- **The target date must be in the future on create, and is not re-checked on
  update.** A remediation due last month is not a plan, and accepting one at the
  moment of assessment is how a register fills with items that were overdue
  before they existed. But a plan whose date has *since* passed is genuinely
  overdue — and that is exactly when somebody needs to edit it, so refusing
  would make the overdue item uneditable.
- **The plan block appears on the row, when the residual lands above appetite.**
  Not saved up as a surprise at submission. In grid mode the treatment cell
  grows a red "Plan needed" link; in guided mode the block sits under step 6.
- **Who is "the ORM"?** The assessment's `reviewer_id` when set — P5 assigns
  them — and otherwise everyone who may close a cycle, which is the operational
  risk function in every role map this product ships. Deliberate
  over-notification: an assessment submitted into silence is the failure mode
  that matters.

## Two things the browser found

**The snapshot silently produced nothing.** `DocumentRenderer::pdf()` resolves
per-tenant branding from whatever it is handed as `organization`, and returns an
empty array for anything that is not an `Organization` model — after which the
shared PDF layout dies on a missing `primary_colour`. I had passed
`organization_id`. It was caught only because the test asserted the file exists
rather than that submission succeeded; the error had been swallowed by the
deliberate "a broken snapshot does not block submission" catch, which is exactly
the kind of forgiving code that hides its own failures. `RcsaAssessment` gained
an `organization()` relation.

**The notification appeared not to arrive.** `notifications_log` showed nothing
after a submission that reported four recipients. It turned out `sendMany`
queues a fan-out for more than one recipient (by design — two hundred inserts do
not belong in the request that was somebody pressing Submit), and the first
`queue:work --once` had picked up an unrelated stale job. Draining the queue
produced all four. No defect, but worth writing down: **on this installation a
notification is not visible until a worker runs**, and `--once` is not a way to
test one.

## Deviations from the plan

### 1. Action plans are gated on `rcsa_assessment.complete`

§11 lists `rcsa.actionplan.view|update|close|verify`. Recording a plan during an
assessment is part of completing it — the same person, the same screen, the same
sitting. What those four permissions are really about is the plan **register**
that outlives the cycle: closure with evidence, ORM verification, reminders.
That is P5, and it gets its own permissions there.

### 2. `progress_pct`, extensions and verification are untouched

`rcsa_action_plans` carries `progress_pct`, `extension_reason`,
`verified_by` and the rest. P4 writes none of them: they belong to the tracking
register in P5, and a half-built extension flow now would be a screen nobody can
complete.

### A third thing the suite found

The snapshot template started life at `resources/views/rcsa/snapshot.blade.php`
and turned `AuthPagesTest::a_blade_page_view_has_not_come_back` red. That guard
exists because the Blade→Inertia migration deleted every page view, and a new
`.blade.php` outside the sanctioned prefixes is how that would quietly regress.
It was right and the file was in the wrong place: a PDF template belongs under
`reports/pdf/` with the other five, extending the same layout. Moved rather
than the guard widened.

## Verification

- `php artisan test --filter=SubmissionTest` — 17 passed.
- Full suite: **2,179 passed, 4 skipped** (2,162 before this phase).
- PHPStan clean on the new paths; Pint clean.
- Driven end to end in a browser: the plan block appeared on the above-appetite
  row, adding a plan cleared that row's blocker, Submit stayed disabled until
  the other four risks were assessed, and submitting produced **5 locked lines,
  a 45 KB PDF starting `%PDF-`, four queued notifications and status
  `submitted`**.

## What P5 needs from this

- The state machine's first two transitions exist: `in_progress → submitted`.
  P5 adds `under_review`, `validated` and `returned`, and `returned` is already
  in `RcsaAssessment::EDITABLE` so a returned assessment reopens for editing.
- `blockers()` is the same list a reviewer needs when deciding whether to return
  something, and it is already shaped for jump links.
- `snapshot_path` is written and nothing reads it yet — P5's review screen
  should offer it as a download, and P6's export should not regenerate it.
