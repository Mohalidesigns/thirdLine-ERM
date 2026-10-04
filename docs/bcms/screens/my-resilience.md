# Screen spec — `Bcms/MyResilience/Index` (the employee's own resilience view)

**Route:** `GET bcms/me/resilience` → `bcms.myresilience.index`. **Permission is an open question
this spec surfaces rather than assumes an answer to (§8) — proposed `bcms.myprofile.manage`**,
because the permission catalogue's own comment on that grant ("which every employee has through
`bcms.myprofile.manage`") signals it was written to be universal, but **it is not currently in
`RiskPermissionCatalog::baseline()`** (verified — the baseline list is `dashboard.view`,
`notification.view`, `document.view`, `task.view`, `task.act`, `job.view`, `api.tokens`,
`hq.view`, `my.view`, `search.view`; `bcms.myprofile.manage` is absent). If the intent is "every
employee, whether or not their role carries any other BCMS permission" — which the blueprint's own
"every branch manager sees their own drill calendar" language implies — that requires either
adding it to `baseline()` or a decision that this screen sits behind `feature:bcms` alone with no
further gate. Named for backend-engineer/architect, not decided here.

**Reads:** `plans/bcms/prompts/PHASE-11-training-reporting-compliance.md`'s screen 6, "**My
Resilience** (employee) — my plan, my drills, my readiness tasks, my emergency profile, my
assembly point"; `docs/bcms/phase-11-spec.md` §5's status note — "Partly exists (`me/readiness-
tasks`, my plan, my profile)" — **verified against the routes file: only `me/readiness-tasks`
(`bcms.readiness.mine`) exists today. There is no "my plan" or "my profile" route or screen yet.**
This spec is therefore the first design of the aggregating page, not a wrapper around three
existing ones. `docs/adr/0018-bcms-phase-2c-is-entra-only.md` §1 — **binding constraint**: "My
Emergency Profile (self-service channels, next-of-kin, language, consent capture)" is explicitly
listed as deferred to **Phase 2D**, and §3.4 there states the sync "never sets consent" and that
`ConsentStatus`'s default is `not_requested`, not `granted` — this screen's emergency-contact
section must render that state honestly and **must not** offer a self-service edit control for
phone numbers, consent or next-of-kin, because that control is exactly what 2D has not shipped.

**Siblings:** `resources/js/Pages/Bcms/Exercises/MyReadiness.jsx` (**the direct model for this
screen's overall register**: due-date-first sorting, an honest all-clear state phrased as
"nothing outstanding" rather than a checkmark tile, a single-column mobile-friendly table); `docs/
bcms/screens/training-compliance.md` (the Attendance/Competency two-column rule this screen's own
training section must not collapse into one "trained" indicator — the same discipline, applied to
one person instead of a register); `resources/js/Pages/Bcms/Plans/Show.jsx`'s acknowledgement
action (reused unchanged, §4).

---

## 1. Purpose and the user

**Every employee**, not a risk-function specialist, opens this on a phone — often the first time
they have opened the product at all, and quite possibly during a live incident when their EMNS
alert told them to check it. The design brief is therefore closer to the vendor portal's "plainest
language, most forgiving states" register than to any risk-management screen in this module: in
the first ten seconds, a person must be able to answer, about themselves, "am I current on my
training, is my emergency contact information something the bank can actually reach me on, do I
have a plan or a call-tree role, and what is coming up that involves me" — without any BCMS
vocabulary (no `iso_clause_ref`, no "curriculum," no "occurrence") appearing where a plain word
would do.

This is a **mobile-first, read-mostly** screen. Its one write action (§4) is acknowledging that a
plan has been read — an act every reader of a plan is already entitled to perform, per
`PlanDocumentController::acknowledge()`'s own existing "no extra permission" reasoning, reused
unchanged here rather than re-specified.

## 2. Layout

`AppLayout` in its narrow/mobile configuration (single column at all breakpoints — this screen
does not use the multi-column grid `Home.jsx`/`Programme/Index.jsx` use at desktop width, because
its primary reader is on a phone). `PageHeader` title `My resilience`, no subtitle beyond the
person's own name — this is a personal page, not a register, and does not need a count in the
subtitle the way `Findings/Index.jsx` does.

Five cards, stacked, in the order a person would ask the questions:

1. **My training.** For each curriculum the person is currently assigned to (resolved the same way
   `training-compliance.md` resolves assignment — current role-holders, not a stored enrolment
   list): curriculum name in plain language, **attendance and competency shown as two separate
   lines**, never blended ("Attended 12 Mar 2027" / "Assessed competent, 12 Mar 2027" or, for the
   three unassessed-but-distinct cases (code review — a FAILED assessment must not read the same as
   "nobody has looked at this yet"), "Assessment not passed — speak to your training lead" (a real
   record, an assessor and a date, just below the pass mark), "Attended — assessment outstanding"
   (attended, no assessment recorded yet) or "Not yet assessed" (not even attended) — matching
   `training-compliance.md`'s own two-column rule, restated here as two lines rather than two table
   columns because this card is single-column, and its own `assessed`/`failed` additive-boolean
   convention rather than a silent rename of `competency_assessed`). The failed line carries red
   text alongside the words, never colour alone. Next due date, with
   plain-language urgency ("Due in 340 days" / "Overdue since 3 Jan" in the module's usual
   red/amber treatment). No jargon: "competency assessed" is written as "confirmed you can do this"
   in the employee-facing copy, with the technical term available as a small muted aside for anyone
   who wants it, not the headline.
2. **My plan.** The approved plan(s) whose `business_unit_id`/`site_id` match the person's own
   department/site — title, version, effective date, and **the acknowledgement state**: "You
   confirmed you'd read this on {date}" or an **"I've read this"** button
   (`PlanDocumentController::acknowledge()`, unchanged) if not yet acknowledged. A `Link` to the
   full plan (`Plans/Show.jsx`, existing, `bcms.plan.view`).
3. **My role in an emergency.** Any `bcms_call_tree_nodes` row where `user_id` (or, for a contact
   with no login, `contact_id` via the person's own linked contact record) matches the signed-in
   person: which tree/department, tier, role label, whether they are a **must-reach** node (plain
   language: "You must be reached for this cascade to count as complete"), and whether they have a
   deputy recorded. If the person is nobody's node in any tree: "You do not currently have a role in
   any call tree." — stated plainly, because for many employees this is the correct, unremarkable
   answer, not a gap to apologise for.
4. **My emergency contact details.** A **read-only** rendering of the person's own `bcms_contacts`
   row: mobile number, WhatsApp, preferred language, **consent state and verification state shown
   honestly and separately** (per `ConsentStatus`/`VerificationStatus`'s own documented distinction
   — a number can be verified and its consent still withdrawn, and neither substitutes for the
   other): "Personal-phone alerts (SMS/voice/WhatsApp): {Not yet requested / Requested, awaiting
   your response / Granted / Withdrawn}" and "Number on file: {Verified {date} / Not yet verified /
   Bounced — please tell your BC coordinator}." **No edit control for any of these fields renders on
   this screen** — a permanent note beneath the section states why: "Updating your own contact
   details and consent here is not available in this build. Contact your BC coordinator to update
   your details in the meantime." (ADR 0018 §1 — self-service capture is Phase 2D's, not invented
   early as a half-working form).
5. **My next exercise.** The person's next occurrence where they are a `bcms_exercise_participants`
   row (via `user_id` or their own `contact_id`), scheduled date, what is expected of them (their
   `role`), and a `Link` into `MyReadiness.jsx` if they have outstanding readiness tasks for it —
   this card does not duplicate that screen's table, it is the one-line teaser that sends a person
   there. "Nothing scheduled that involves you in the next 90 days." when empty.

**"My readiness tasks"** is **not** a sixth card duplicating `MyReadiness.jsx` — it is a `Link`
from card 5 and from the page header, because that screen already exists, is already the
authoritative "what do I owe" list, and this page does not maintain a second copy of it.

## 3. Every state

- **Loading.** Skeleton cards, in order.
- **No BCMS contact record exists for this person at all** (a very new hire, or a role with no
  BCMS relevance). Cards 3–5 render their own honest "nothing found" states (§2); card 4 (emergency
  contact) instead shows: "No emergency contact record exists for you yet. Ask your BC coordinator
  to add one." rather than a blank set of fields — a missing `Contact` row is a different fact from
  a `Contact` row with nothing filled in, and the two must not read the same.
- **Nothing assigned/scheduled anywhere** (a genuinely uninvolved employee at a bank where BCMS
  scope is narrow). Every card shows its own plain all-clear text; the page as a whole is not
  replaced by a single "nothing here" message, because "no training assigned" and "no call-tree
  role" and "no plan applies to you" are three separate, individually true facts, not one
  aggregate non-event.
- **Overdue training.** Red text on the due date, matching the module's convention, with the plain-
  language framing from §2 — this is the one state on this screen closest to urgent, and it is
  still phrased as information, not an alarm (this is not the crisis console; a training due date
  does not get siren styling).
- **A failed assessment.** `failed` (assessed, did not clear the pass mark) is distinct from
  "not yet assessed" — the competency line reads "Assessment not passed — speak to your training
  lead", in red, so the person is told a clear next step rather than reading a false "nothing has
  happened yet." This is the code-review defect this state exists to close: a failed assessment
  previously read identically to an unassessed one.
- **Attended, assessment outstanding.** `requires_assessment` is true, the person has attended
  (`completed_at` set) but `assessed` is false — the competency line reads "Attended — assessment
  outstanding", distinguishing this from both "Not yet assessed" (never attended) and a failed
  assessment.
- **Plan not yet acknowledged.** The "I've read this" button is present and primary; once clicked,
  the card updates in place to the confirmed state — no full reload, matching the existing
  acknowledgement route's own behaviour.
- **Multiple plans apply** (a person whose business unit has both a departmental BCP and sits at a
  site with its own site plan). Both listed, most recently effective first — never silently picking
  one.
- **Consent not requested / withdrawn.** Rendered plainly per §2, **never implying the bank is
  failing to reach the person** — the copy states the state, not a value judgement ("Not yet
  requested" rather than "Missing").
- **Verification bounced/invalid.** `red-50` text, "Bounced — please tell your BC coordinator" —
  actionable, plain, no jargon ("bounced" is kept because it is the word a person would use back to
  their coordinator; "invalid" renders as "could not be verified").
- **Permission-denied** (if the eventual permission decision, §8, does gate this behind something
  not every employee holds). Standard 403 — but see the note in the route header: this is exactly
  the scenario the open permission question exists to prevent for the intended audience.
- **Server/network error.** Standard handling.
- **Degraded network.** See §6 — this is the state this screen is designed hardest against, given
  its likely-mobile, possibly-incident-time audience.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Acknowledge a plan | `POST bcms.plans.acknowledge` (existing route, unchanged) | None — matches the existing screen's own lack of confirmation |
| Open the full plan | `Link` to `bcms.plans.show` | None |
| Open readiness tasks | `Link` to `bcms.readiness.mine` (existing) | None |
| Open the call tree / occurrence for more detail | `Link` to the existing call-tree/occurrence screens, permission-gated there as they already are (a person with no `bcms.calltree.view` sees their own card 3 summary here regardless — this page reads their own node directly, not through the register's own permission, matching the plan-acknowledgement precedent that "seeing your own thing" needs no extra grant) | None |

**No self-enrolment action exists on this screen, and none is specified.** Curriculum assignment
is role-resolved (§2.1 of the phase spec — target roles, ultimately AD-group-resolved once Phase
2C lands), not a person's own choice; the phase spec describes no mechanism for a person to enrol
themselves into a curriculum, and inventing one here would create an enrolment path the training-
compliance sufficiency rules were not designed against. If self-enrolment is wanted, that is a
product decision for the compliance-analyst to specify against clause 7.2/7.3's own requirements,
not an addition made by this screen alone.

## 5. Accessibility (WCAG 2.1 AA)

- **Single-column layout at every breakpoint** is itself an accessibility choice as much as a
  mobile one — no reflow surprises between a phone and a desktop render, and a screen-reader user's
  linear reading order matches the visual order exactly.
- **Every status line pairs an icon (where one is used) with text** — no colour-only signal, same
  rule as every other screen in this module.
- **Plain-language labels are the primary text, not the technical term** — "Confirmed you can do
  this" is the accessible name a screen reader announces; "competency assessed" appears only as a
  small muted aside, matching the vendor-portal design rule of using the plainest available
  language for a non-specialist reader.
- **The "I've read this" button has a clear, unambiguous accessible name** ("Confirm you have read
  {plan title}"), not a bare "Confirm."
- **Keyboard/touch path:** header → card 1 (training, one row per curriculum, each with its own
  due-date and status text) → card 2 (plan, acknowledge button) → card 3 (call-tree role, link) →
  card 4 (emergency contact, read-only, no focusable edit controls to skip past) → card 5 (next
  exercise, link to readiness tasks).
- **Contrast:** reuses `red-50/900`, `amber-50/900` exactly; no siren/life-safety styling on this
  screen at all — a training due date is not an evacuation notice, and this screen's visual
  hierarchy must not borrow the crisis console's weight for routine information (the module's own
  "life-safety surfaces are not styled like reminders" rule, applied in reverse: a reminder must
  not be styled like a life-safety surface either).

## 6. Low bandwidth (100 kbps)

- **This is the screen in the whole module most likely to be opened on a genuinely poor
  connection** — a branch employee on a phone, possibly during the incident that made them check
  it. Every design choice here is biased toward that case over visual richness.
- **No chart, no image, no map** — "my role in an emergency" and "my next exercise" are text lines,
  not a diagram of the call tree or a calendar widget.
- **Five small cards, one page load, no per-card lazy fetch** — a person on a bad connection should
  not see four cards render and wait on a fifth; the whole page's data is small (one person's own
  records) and loads together.
- **The acknowledge action is a small, single-field `POST`** with an optimistic-feeling but
  server-confirmed update (standard Inertia round trip, no client-side-only "confirmed" state that
  could disagree with the server).
- **No auto-refresh/polling on this screen** — unlike the crisis console or the EMNS live view,
  this page does not need to stay current to the second, and polling would cost exactly the
  bandwidth this screen's audience is least likely to have spare.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Training rows, attendance/competency lines, due dates | `bcms_training_records` for this `user_id`, joined to `bcms_training_curricula` for name/frequency/pass mark |
| Plan(s) and acknowledgement state | `bcms_plans` where `business_unit_id`/`site_id` match the person's own, joined to `bcms_plan_attestations` for this user |
| Call-tree role | `bcms_call_tree_nodes` where `user_id` = this user, or `contact_id` = this user's own linked `bcms_contacts` row |
| Emergency contact fields, consent state, verification state | `bcms_contacts` — the person's own row, read only |
| Next exercise | `bcms_exercise_participants` where `user_id`/`contact_id` matches, joined to the occurrence's `scheduled_date`, filtered to future dates, earliest first |
| Readiness-task teaser count | `bcms_readiness_tasks` for the person, same source `MyReadiness.jsx` reads, this page shows only whether any exist |

## 8. Deliberately out of scope

- **"My assembly point."** The prompt's own screen list names this, and **no data model for it
  exists anywhere in this build** — `bcms_sites` has no assembly-point field, and no other table
  carries one. This spec does not render a fabricated or placeholder assembly point; it is named
  here as a gap for the architect, not built around with an empty control. If assembly-point
  information is wanted, it needs a schema decision before a screen can honestly show one.
- **Self-service editing of contact details, consent or next-of-kin.** Explicitly Phase 2D's, per
  ADR 0018 §1 — this screen reads and states the current values only.
- **Training self-enrolment.** No mechanism for it is specified anywhere in the phase materials;
  see §4's closing note.
- **A confirmed final permission gate.** §"Route" above states the open question; this spec does
  not resolve it, because doing so is a decision about the platform's baseline grants, not a screen
  design choice.
- **Any content specific to an active incident** (a crisis-mode variant of this page, a "check in
  safe" action, life-safety styling). This is the calm-state personal page; the crisis-specific
  surfaces (EMNS acknowledgement, the roll-call) already exist as their own screens and are not
  duplicated or merged here.
