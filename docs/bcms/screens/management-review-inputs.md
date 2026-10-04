# Screen spec — `Bcms/Reviews/Show` (the management-review record and its extended inputs snapshot)

**Route:** `GET bcms/reviews/{review}` → `bcms.reviews.show` (**new** — `Programme/Index.jsx`
today lists reviews inline with no detail screen; this spec adds the one that clause 9.3 actually
needs), `permission:bcms.programme.manage` to see the capture/edit affordances,
`bcms.report.view` sufficient to read an approved review (an approved 9.3 record is exactly the
kind of artefact `compliance-evidence-matrix.md` links out to, and an examiner reading it should
not need programme-management rights). Existing writes, unchanged: `POST bcms/programme/
{programme}/reviews` → `bcms.reviews.store` (`bcms.programme.manage`, opens **and**
immediately snapshots via `captureReviewInputs()`), `POST bcms/reviews/{review}/capture` →
`bcms.reviews.capture` (`bcms.programme.manage`, re-snapshots before approval), `POST
bcms/reviews/{review}/approve` → `bcms.reviews.approve` (`bcms.programme.approve`).

**Reads:** `docs/bcms/phase-11-spec.md` §2.4 (the extended snapshot: `previous_review_actions`,
`audit_results`, `incidents`, `exercise_evaluation_outputs`, `call_tree_and_emns_performance`,
`dr_achievement`, `supplier_continuity`, `interested_party_feedback` (free text, no register to
draw from), `bia_and_risk_changes`, `context_changes`, `improvement_opportunities` — extending
Phase 1's original maturity/findings/corrective-actions/exercises/plans set), the clause map §1.2
("Green. Phase 11 adds the agenda content pack and extends the snapshot" for 9.3.results; "Amber
until the snapshot is extended" for 9.3.inputs); **`docs/adr/0021-…-kri-register-is-adopted-not-
invented.md` §1 and §4 — binding: the snapshot grows to carry an `internal_audit` block (report
reference, date, auditor, independence statement, conclusion) in the *same* change that adds the
items above, "because a snapshot extended twice produces two shapes of stored json that the pack
then has to tolerate for ever."** This block is also what `compliance-evidence-matrix.md`'s 9.2
amber state reads from — this screen is where that block is captured, in an ordinary form field,
not invented by an audit-programme table that does not exist.

**Siblings:** `resources/js/Pages/Bcms/Programme/Index.jsx` (the review list this screen is opened
from — `reviews` prop, each row's `Link` target becomes this new show route; the "snapshot with a
timestamp" framing already stated there in the panel's own copy carries over unchanged);
`resources/js/Pages/Bcms/Bia/Workspace.jsx` (the `blocking[]` red-banner pattern, reused here for
"cannot approve until inputs are captured" — clause 9.3 requires inputs captured **before**
approval, already enforced server-side per the phase-11-spec's own note); `resources/js/Pages/
Tprm/Reports/BoardPack.jsx` ("figures render from the snapshot… nothing on this page recomputes
anything" — the exact discipline this screen's whole body follows).

---

## 1. Purpose and the user

A **programme owner** (`bcms.programme.manage`) opens a review record right after opening it, to
work through the eleven-ish clause 9.3.2 inputs as an agenda: what does the maturity trend say,
what audit results exist, what did the last review's actions come to, what happened in incidents
and exercises since. They capture inputs (possibly more than once, right up to the meeting), and
then a **more senior approver** (`bcms.programme.approve`, often a different person — the board or
its delegate) opens the same record afterward to approve it as the minuted decision. An
**examiner**, weeks later, opens it as the 9.3 mandatory record: an approved review with an inputs
snapshot and decisions, dated and attributed.

The defining fact about this screen, stated in Phase 1's own reasoning and restated here because
Phase 11 adds nine input categories to it: **it is a snapshot, not a live query.** A screen that
re-ran the maturity score or re-counted open findings every time it rendered would show a reader in
December a meeting that considered December's numbers — which is not what happened if the review
was held in October. Every figure on this screen after capture renders from
`bcms_management_reviews.inputs`, the stored JSON, never recomputed.

## 2. Layout

`AppLayout`, `PageHeader` title `Management review — {review.title}`, subtitle `{held_on} ·
{status_label}`.

**Status banner** (matching `aar-builder.md`'s three-state banner pattern, adapted to this
record's own lifecycle):

1. **Opened, inputs not yet captured** (rare — `storeReview()` captures immediately today, but the
   state exists for a re-capture in progress): amber, "Inputs have not been captured for this
   review yet."
2. **Inputs captured, awaiting approval** (`gray-50`/`900`, plain): "Inputs captured
   {inputs_captured_at}. Awaiting approval." — with a **"Re-capture inputs"** action
   (`bcms.programme.manage`) that re-runs `captureReviewInputs()` and updates the timestamp, for
   use right up until approval.
3. **Approved** (`emerald-50`/`900`): "Approved by {approved_by} on {approved_at}. This review's
   inputs and decisions are frozen." No re-capture action renders once approved — matching the
   TPRM board pack's "prepare a new period rather than recomputing this one" rule exactly: a later
   position is a new review, not an edit to this one.

**The inputs snapshot**, rendered as a series of named sections **in the clause 9.3.2 agenda
order** from `Reference\ManagementReviewAgenda` (phase-11-spec §2.4 — "one source, two consumers"
applies here the same way it does to the internal-audit checklist: this screen renders the same
ordered list the seeder ships, it does not maintain a second copy of the agenda order):

1. Status of actions from previous reviews (`previous_review_actions`)
2. Changes in external/internal issues relevant to the BCMS (`context_changes`)
3. Performance information: maturity trend, exercises programme completion, plan currency
   (Phase 1's original set, unchanged)
4. **Internal audit results** (`internal_audit` — ADR 0021 §1/§4): report reference, date,
   auditor, independence statement, conclusion. **A form, not read-only text, while the review is
   in the not-yet-approved state** — this is the one section on the page the reviewer fills in
   directly rather than one the system computes, because no audit-programme table exists to
   compute it from (ADR 0021 §1); once approved, it renders as plain frozen text like every other
   section.
5. Incidents: count, severity mix, notification timeliness, lessons (`incidents`)
6. Exercise evaluation outputs: AAR outcomes, quantitative misses (`exercise_evaluation_outputs`)
7. Call tree and EMNS performance (`call_tree_and_emns_performance`)
8. DR achievement: RTO/RPO achievement rate (`dr_achievement`)
9. Supplier continuity: expired vendor attestations (`supplier_continuity` — reads the same chase
   list `supplier-resilience.md` shows, snapshotted at capture time)
10. Interested-party feedback (`interested_party_feedback` — **free text only**; there is no
    feedback register to draw from, and the section says so under its label: "No feedback register
    exists in this build — recorded as free text.")
11. BIA and risk changes (`bia_and_risk_changes`)
12. Improvement opportunities (`improvement_opportunities`)

Each section header carries its own small muted `iso22301.9.3.inputs` reference (matching
`aar-builder.md`'s per-card clause-ref convention) except section 4, which carries
`iso22301.9.2.results`/`.programme` alongside 9.3's, since it is doing double duty as both a review
input and the 9.2 evidence-by-reference `compliance-evidence-matrix.md` reads.

**Decisions and actions** section, below the inputs: free text for decisions taken, plus a table of
corrective actions raised from this review (reusing `Findings/Index.jsx`'s `AddAction` component
unchanged, `source` context implied by being raised from a review) — this is where 9.3's "decisions"
half lives, distinct from the "inputs" half above it.

**Footer action bar**: Re-capture inputs (§ above, pre-approval only) · Approve
(`bcms.programme.approve`, disabled with the reason "inputs must be captured first" if
`inputs_captured_at` is null — this is already enforced server-side per the phase spec's own note,
the button here just reflects it live) · Export (`bcms.report.export`, a plain download of this one
review as a section of the pack-writer's own rendering, always available once inputs exist).

## 3. Every state

- **Loading.** Skeleton sections, matching the module convention.
- **Not yet captured.** Amber banner above; sections 3–12 (the computed ones) show a single shared
  placeholder — "Not yet captured for this review" — rather than each independently rendering an
  empty state, because there is nothing to distinguish between twelve blank sections before capture
  ever runs.
- **Captured, section 4 (internal audit) empty.** The form renders with all fields blank and a
  short prompt: "If an internal audit covered any part of the BCMS since the last review, record it
  here — report reference, date, auditor and their independence statement, and the conclusion. This
  is the only place that fact is captured; there is no internal-audit register in this system
  (ADR 0021 §1)." Leaving it blank and approving is a **valid** path — the matrix then renders 9.2
  red, honestly, per `compliance-evidence-matrix.md`'s own rule, not blocked here.
- **Captured, all other sections populated from live data at capture time.** Rendered as plain text/
  small tables per section, each figure carrying no further interactivity (no drill-down here — an
  examiner wanting the underlying detail follows the same links `compliance-evidence-matrix.md`
  and the source screens already provide, not a second set built into this snapshot).
- **Re-captured** (pre-approval). `inputs_captured_at` updates; a small note appears: "Re-captured
  {timestamp}, replacing the {previous_timestamp} snapshot — only the latest snapshot is kept
  before approval." (Matching Phase 1's stated design: the record is a snapshot, and re-capturing
  before approval is expected, not an amendment needing its own audit trail beyond the timestamp
  change itself, which the append-only `risk_audit_trail`/BCMS audit log already carries.)
- **Approve attempted with no inputs captured.** Button disabled, tooltip states why (matching the
  disabled-with-reason convention used for AAR finalisation) rather than a server 422 surprise.
- **Approved.** Emerald banner; every section (including section 4) becomes plain frozen text —
  the form disappears entirely, not a disabled input, matching `aar-builder.md`'s "final state
  removes controls from the tab order" rule exactly.
- **Permission-denied.** Standard 403; capture/re-capture/approve controls simply do not render for
  a viewer without the respective permission.
- **Server/network error.** Standard handling; an in-progress section-4 form's typed values are
  preserved on a failed save attempt (standard Inertia behaviour).
- **Degraded network.** See §6.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Capture / re-capture inputs | `POST bcms.reviews.capture` (pre-approval only; the control does not render once approved) | None — a snapshot replacing an unapproved snapshot is reversible by re-capturing again |
| Save section 4 (internal audit) fields | Included in the capture payload, or a dedicated small `PATCH` if the backend prefers — either way, saved as part of the same review row, not a new table | None |
| Approve | `POST bcms.reviews.approve` | **Confirmation required, stating the consequence**: "Approve this review? Its inputs and decisions become frozen. A later position is recorded as a new review, not an edit to this one." — matching the weight `aar-builder.md`'s Finalise confirmation carries for an equivalent one-way lock |
| Raise a corrective action from the review | `POST bcms.actions.store` (existing route) | None |
| Export this review | `GET` a per-review export, `bcms.report.export` | None — non-destructive |

## 5. Accessibility (WCAG 2.1 AA)

- **Each of the twelve agenda sections is a real `<h2>`** in the agenda's own order, so a
  screen-reader user can navigate section-to-section exactly as they would through
  `aar-builder.md`'s nine sections.
- **Section 4's form fields have visible labels**, not placeholder-only text, and its "no
  internal-audit register exists" note is static visible text preceding the fields, read by a
  screen reader as part of the section rather than as a separate aside.
- **The approve confirmation is a true modal** (`role="dialog"`, `aria-modal="true"`, focus trapped
  and returned), matching `aar-builder.md`/`Readiness.jsx`'s existing modal pattern exactly — not a
  bare `window.confirm`, given the weight of the action.
- **Keyboard path:** status banner (non-focusable) → sections 1–12 in order (section 4's form
  fields in tab order only pre-approval) → decisions text area → corrective-action add-form →
  footer action bar (Re-capture, Approve, Export).
- **Read-only (approved) state removes all form controls from the tab order**, per
  `aar-builder.md`'s established rule, rather than leaving disabled inputs a keyboard user tabs
  through pointlessly.
- **Contrast:** reuses the module's existing `emerald-50/900` and `amber-50/900` pairings exactly.

## 6. Low bandwidth (100 kbps)

- **One page load, twelve text sections** — no chart; every figure that could be chart-shaped
  (maturity trend, exercise completion) renders as the same text/small-table treatment
  `Programme/Index.jsx` already uses for its own maturity panel, not a new visualisation.
- **The whole snapshot loads with the page**, matching `aar-builder.md`'s reasoning exactly: a
  document this evidentiary should not risk a partially-loaded state on a slow connection.
- **Capture/re-capture is a synchronous, server-computed operation** with no large client payload
  either direction — the heavy lifting (querying nine phases' worth of data) happens server-side
  and returns the finished snapshot.
- **Export is explicit and deferred**, never triggered on page load.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Sections 1, 3, 5–12 | Computed by `captureReviewInputs()` at capture time from the named phase's own tables (Phase 1's original four plus phase-11-spec §2.4's additions), written once into `bcms_management_reviews.inputs` and never recomputed on read |
| Section 4 (internal audit) | Entered directly on this screen into the same `inputs` JSON — the only section that is a form rather than a computed read, because no source table exists to compute it from (ADR 0021 §1) |
| `inputs_captured_at`, `approved_by`, `approved_at` | `bcms_management_reviews` columns directly |
| The corrective-actions table | `bcms_corrective_actions` linked to a finding raised from this review, same source `Findings/Index.jsx` reads |

## 8. Deliberately out of scope

- **A second maturity, findings or exercise-completion computation.** Every figure in sections 3
  and 5–12 is captured once, by the existing `captureReviewInputs()` service, from the existing
  producing phases' tables — this screen adds a rendering and one new input field (section 4), not
  a second read path.
- **An internal-audit programme/results register.** Section 4 is a free-text-plus-structured-field
  capture inside this review's own snapshot, not a table, a list, or a workflow of its own — per
  ADR 0021 §1's explicit refusal to build one scoped to BCMS.
- **A feedback register for interested parties.** Section 10 is free text; inventing a register to
  back it is out of scope per phase-11-spec §2.4's own note.
- **Editing an approved review's inputs.** Frozen means frozen; a later position is a new review.
