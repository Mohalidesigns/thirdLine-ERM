# P5 — ORM review, the workflow, and the action-plan register

Steps 8 and 9. The assessment leaves the business unit, somebody in the second
line argues with it, and whatever was promised about the risks above appetite
gets chased until it happens.

## What landed

| Piece | Where |
|---|---|
| The state machine | `app/Services/Rcsa/RcsaWorkflowService.php` |
| Queue and per-line challenge | `app/Services/Rcsa/RcsaReviewService.php` |
| The remediation register | `app/Services/Rcsa/RcsaActionPlanService.php` |
| The nightly sweep | `app/Console/Commands/CheckRcsaActionPlans.php` (08:45 daily) |
| Transition log + escalation + extension columns | `2026_09_07_100003_create_rcsa_workflow_tables` |
| Models | `RcsaAssessmentTransition`, `RcsaLineComment` |
| Endpoints | `Rcsa\ReviewController`, `Rcsa\ActionPlanController`, plus `AssessmentController::{approve,reject,respond}` |
| Policies | `RcsaAssessmentPolicy::{approve,review,validate,returnForRework,escalate}`, `RcsaActionPlanPolicy` |
| Permissions | `rcsa_assessment.{review,validate,return,approve}`, `rcsa_actionplan.{view,update,close,verify}` + `100004` grant |
| Pages | `RcsaReview/{Index,Show}.jsx`, `RcsaActionPlans/Index.jsx`, plus the workspace's returned banner and ORM thread |
| Tests | `ReviewWorkflowTest` (18), `ReviewQueueTest` (8), `BuApprovalTest` (6), `ActionPlanRegisterTest` (15) — 47 |

## The acceptance criterion

> *Steps 8–9 complete; returned assessments reopen only the flagged lines.*

`returning_reopens_only_the_flagged_lines` is the sentence itself: submit three
risks, challenge one, return, and assert that exactly one line lost its
`locked_at` while the other two kept theirs.

The four tests around it are the ones that matter more, because the criterion is
easy to satisfy on the surface and easy to lose everywhere else:

- `an_unflagged_line_cannot_be_edited_after_a_return` — the PATCH endpoint
  answers 423 on the untouched line, and the message says *why* rather than
  "forbidden".
- `neither_bulk_apply_nor_action_plans_reach_a_locked_line` — the two other ways
  to write to a line. A bulk apply that ignored `locked_at` would have been the
  way round the whole rule.
- `the_save_service_refuses_a_locked_line_on_its_own` — the rule is in
  `RcsaAssessmentService::apply()` too, so a queued job or P6's offline import
  cannot walk past it.
- `returning_with_nothing_flagged_is_refused` — see below.

**Where the rule actually lives:** `RcsaAssessmentLine::acceptsEdits()`, on the
LINE. The assessment cannot express it — a returned assessment accepts edits,
so any check that consults only `RcsaAssessment::acceptsEdits()` hands back all
200 rows when the ORM asked for four. `locked_at` is set on every line at
submission and cleared, on return, only on the flagged ones.

## Decisions worth knowing

**A return with nothing flagged is refused, not permitted.** It would reopen
nothing and hand the assessor back an assessment they cannot change — a dead
end that reads like a bug and is impossible to act on. The reviewer is told to
flag what they want changed first. The BU head's return is the deliberate
exception (`requireFlags: false`) and reopens everything, because a head has no
per-line challenge to have flagged with.

**A challenge suggests; it never applies.** `suggested_values` is written and
nothing anywhere reads it back onto the line. A reviewer who could correct a
rating directly would turn a self-assessment into an ORM assessment, and the
audit trail would show the business having said something it never said. It is
still validated against the line's own methodology — a suggestion the assessor
could not legally apply is a trap, and "should be a 7" on a five-point scale
would sit in the trail for ever.

**Escalation is a flag, not a state.** §9.2 lists Escalate beside Validate and
Return, but §9.1's machine has no escalated state and the enum has no room for
one: an escalated assessment is still under review, with somebody senior now
watching. Three columns on the assessment, a `from == to` row in the log, and a
notification. Whichever decision finally lands clears it — otherwise it would
sit at the top of the queue for ever.

**Reviewing and deciding are three permissions, not one.** `review` is the ORM
Analyst's (queue, claim, challenge, escalate); `validate` and `return` are the
Head of ORM's. Handing an analyst all three collapses §9's two-person control
into one person. The policy adds the half permissions cannot: **whoever
submitted an assessment cannot review it**, whatever they hold, and whoever
approved cannot be the submitter.

**The BU-head step is `organizations.settings['rcsa']['bu_approval_required']`,
not a methodology column.** The methodology is locked once a cycle is open, so
putting a workflow policy there would mean a bank could not turn the approval
step on without versioning its scoring engine. It sits beside `board_pack` and
`mfa_required_roles`, and ships off — `the_step_ships_off_and_submission_goes_
straight_to_orm` pins that, because a bank that never asked for the step must
not find its assessments waiting in a state nobody knows to look at.

Lines lock and the snapshot renders at **filing**, not at approval: the head
reviews the same frozen document the ORM will, and the PDF records what the
unit signed off.

**Priority is sorted in PHP; the above-appetite count is computed in SQL.** The
count is a column on a screen showing every unit at once, so it groups by
methodology, asks it which bands sit above the ceiling, and does one grouped
`whereIn` over `residual_level` — ordinary SQL, identical on MySQL and SQLite.
The sort is PHP because the formula weighs three things from three places, and
an ORDER BY would bury the weights in a raw string nobody would think to look in
when the bank asks to change them. The queue is tens of rows.

The formula: **10 per risk above appetite + 1 per day waiting + 1000 if
escalated**. Age is measured from `submitted_at`, not `created_at` — an
assessment drafted in January and filed yesterday has not been waiting since
January. The screen states the formula under the table.

**Reminders fire at exactly T-14, T-7 and T-0**, and that exactness is what makes
the sweep idempotent without a "last reminded" column. Overdue is announced once,
at the moment the status flips, because it can only flip once — a daily "still
overdue" is how a register teaches its owners to filter it into a folder they
never open.

**An extension is a request with an approver, not an edit to the date.** The plan
stays due when it was due until somebody approves the move, and
`original_target_date` is written at the first approval and never again — a plan
extended from March to June and then to September is still, in the register, a
plan that was due in March. Nobody approves their own request.

**Closure is claimed, then accepted.** The owner marks it complete with evidence
(mandatory: the ORM verifying closure has to have something to verify); the
second line verifies. The policy stops one person doing both on the same plan
even where they legitimately hold both permissions — the Head of ORM does.

**The register outlives the cycle**, and nothing in it filters to an open one.
`the_register_survives_the_cycle_that_produced_it` closes the cycle, asserts the
assessment is frozen, and then completes the plan — because most remediation
lands after the cycle that raised it closes.

## What P5 found that the plan did not know

**P0 left the extension request half-built.** `rcsa_action_plans` had
`extension_reason`, `extension_approved_by` and `original_target_date` — the
whole of an *approved* extension — and nowhere to hold the date being asked for
while the request was undecided. The only place to put it would have been
`target_date`, which moves the deadline before anyone agreed to it: the exact
behaviour an extension workflow exists to prevent. `proposed_target_date`,
`extension_requested_by` and `extension_requested_at` finish it.

**`rcsa_line_revisions` could not hold a workflow transition** despite its
comment saying it might. Its `line_id` is NOT NULL, and the transitions that
matter most — submit, validate, return — are properties of the assessment with
no line to hang them on. Writing them against an arbitrary line would make the
audit read as though one risk had been validated.
`rcsa_assessment_transitions` is its own append-only table, written in the same
transaction as the status so a status the log does not explain cannot exist.

**Submission was the one movement with no history behind it.** P4's
`RcsaSubmissionService` forceFilled its own status. It now goes through
`RcsaWorkflowService::transition()` like everything else.

**P4 notified the wrong population.** `notifyReviewers()` picked whoever could
close a cycle, which was the closest thing that existed at the time and is a
different authority — a cycle coordinator is not a reviewer. It now reads
`rcsa_assessment.review`. `SubmissionTest` was updated with the reason.

**`StatusBadge` had no key for four of the workflow states.** `under_review`,
`validated`, `returned` and `bu_approval` all fell through to draft styling — so
on the review queue, whose entire job is to say what has been decided and what
has not, a validated assessment looked identical to an untouched one. Exactly
the P1 `published`/`retired` defect, in the same component.

**`window.prompt` was reached for again** in the BU head's "send back" button
and replaced with an in-page form before it shipped. It throws in the preview
browser; P1 shipped two features that could not be used because of it.

## Carried into P6 and P7

- The snapshot is now readable at `rcsa.review.snapshot`, streamed from storage.
  **P6's export must not regenerate it** — a PDF produced later from live rows is
  not what was filed.
- `assigned_to` on `rcsa_assessments` is still null. `reviewer_id` is now written
  by `claim()`; assignment of *assessors* stays open (§14 Q8) and belongs with
  P7's business-unit scoping.
- `RcsaActionPlanPolicy::reachable()` is tenant-only, like every other RCSA
  policy. Business-unit scoping is P7 and seams in there.
- The escalation notification and the extension-approval notification both fan
  out to permission holders. P7's scoping should narrow them to the escalated
  unit's own ORM.
