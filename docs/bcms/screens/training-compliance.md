# Screen spec — `Bcms/Training/Compliance` (curricula, records, competency and re-certification)

**Route:** `GET bcms/training/compliance` → `bcms.training.compliance`,
`middleware('permission:bcms.training.view')` — the by-role/by-department overdue view. Two
supporting management surfaces on the same page rather than separate screens (§2):
`GET/POST bcms/training-curricula` → `bcms.training-curricula.index`/`.store`,
`permission:bcms.training.view` for the list, `bcms.training.manage` to create; `POST
bcms/training-records` → `bcms.training-records.store`, `bcms.training.manage`; `POST
bcms/training-records/{record}/assess` → `bcms.training-records.assess`, `bcms.training.manage`.

**Reads:** `docs/bcms/phase-11-spec.md` §2.1 (the six seeded curricula, their target roles,
frequency and pass marks — `BC-EMNS` recertifies at six months, only `BC-AWARE-ALL` is
unassessed), §1.1 (the clause map: 7.2 is competence, 7.3 is awareness, and a screen that shows
one column risks reporting the second as the first), §2.2 (awareness campaigns are EMNS alerts —
no new screen), §6 criterion 6 (an exercise occurrence links to a training record automatically,
and the link never sets `competency_assessed` — that stays a human, assessor act);
**`docs/adr/0021-…-kri-register-is-adopted-not-invented.md` §3 — binding: `certificate_id` is
retired in place. There is no certificate upload anywhere on this screen.** The competence record
*is* the assessed row — assessor, date, result — and a provider's certificate PDF is the
provider's assertion, not the bank's evidence.

**Siblings:** `resources/js/Pages/Bcms/Findings/Index.jsx` (the tile-strip-plus-filterable-register
layout this screen borrows directly — overdue-as-a-highlighted-row-not-a-count, the raise-inline-
form pattern reused for "record an outcome"); `resources/js/Pages/Bcms/Bia/Report.jsx` (coverage
stated with its denominator, gaps listed by name beneath it — this screen's by-role/department
breakdown follows the same "the rate is not the whole report" discipline).

---

## 1. Purpose and the user

A **training administrator** (`bcms.training.manage`) opens this screen to see who is overdue and
to record an outcome after a session runs. An **assessor** — often a different person from the
administrator, per the curricula's own `requires_assessment` design — opens it to confirm
competence for a specific person against a specific curriculum, on a specific date, with a result.
A **compliance officer** (`bcms.training.view` only) opens it to answer one question for the
matrix in `compliance-evidence-matrix.md`: is 7.2 actually met, or is the bank counting attendance
as competence? That third reader is why the screen's central design decision exists: **competency
and attendance are two visibly different columns, never one blended "trained %"** — a single
column would let a bank report awareness as competence, which is the one thing clause 7.2 exists
to prevent (phase-11-spec's own instruction to the ui-designer, verbatim).

In the first ten seconds: who is overdue, broken down by role and department (not a single bank-
wide percentage), and for each curriculum, how many of its assigned people are attended-only versus
actually assessed-competent.

## 2. Layout

`AppLayout`, `PageHeader` title `Training & competency`, subtitle `{curricula.length} curricula ·
{overdue_count} people overdue for re-certification or first completion.`

**Summary strip**, tiles matching `Findings/Index.jsx`'s pattern: Overdue (re-cert or first-time,
`red-50` if non-zero) / Assessed this year / Attended-only this year / Awareness campaigns sent
this year (a read-only count from `bcms_alerts` where the template is one of the four seeded
awareness templates — see §8, this is a **count**, not a control; the campaigns themselves are
composed and sent from the existing `Emns/Templates.jsx`/`Emns/Alert.jsx` screens).

**Curricula panel** (`bcms.training.manage` to expand the create form, everyone with
`bcms.training.view` sees the list): a card per curriculum — code, name, target roles (rendered as
chips resolved from platform role names, with a note where a role has zero current holders: "no
current role-holders — check the role name" per phase-11-spec's own honesty rule about AD-group
resolution not being built, §7), frequency in months, **a badge distinguishing "Requires
assessment, pass {pass_mark}%" from "Awareness only — no assessment"** so the one unassessed
curriculum (`BC-AWARE-ALL`) never looks like a lesser version of the other five, it looks like a
different kind of record.

**The compliance table**, the main body, one row per person × assigned curriculum (paginated,
filterable by role, department, curriculum and status):

| Person | Department | Curriculum | Attendance | Competency | Next due | Source |
|---|---|---|---|---|---|---|

- **Attendance** column: `completed_at` date, or "Not attended" in `red-50` text if past
  `next_due_date`, amber if within 30 days of due, plain grey otherwise.
- **Competency** column, **rendered independently of Attendance and never derived from it**: for
  a `requires_assessment` curriculum, "Assessed {score}% by {assessor}, {completed_at}" in
  `emerald-50` tone, or "Attended, not yet assessed" in `amber-50` tone if attendance exists but
  `competency_assessed` is false, or "—" (not "0%", not "fail") if nothing at all is on file yet.
  For `BC-AWARE-ALL` (no assessment required) the column reads "Not applicable — awareness only"
  in plain grey, so a reader never wonders why it is blank.
- **Source** column: "Manual" or, where `occurrence_id` is set, "Linked from {occurrence.name},
  {scheduled_date}" as a `Link` to that occurrence's AAR — the automatic linkage from criterion 6,
  shown as provenance rather than hidden.

**Record an outcome** (`bcms.training.manage`), an inline form beneath the table matching
`Findings/Index.jsx`'s `AddAction` pattern exactly: person, curriculum, completed date, and —
**only if the curriculum `requires_assessment`** — a **read-only "Assessed by: {current user}"
line** (never a picker) and a score field validated against `pass_mark`. **There is no assessor
picker anywhere on this screen** (code-review defect B4: a `bcms.training.manage` holder could name
a colleague as their own assessor, and the server ignored the field regardless — a control that
silently did nothing). `TrainingComplianceService::recordOutcome()`/`assess()` always record the
**acting user** as assessor and refuse self-assessment server-side; the read-only line states that
fact rather than offering a choice the server would discard. This form **replaces** the certificate
concept entirely: there is no file input, no "attach a certificate" control anywhere on this screen
(ADR 0021 §3) — the assessor (the acting user, stated not chosen), date and score are the record.

**Assess an existing attendance-only row**: the Competency cell for an "Attended, not yet assessed"
row carries an inline "Assess now" action (`bcms.training.manage`) opening the same read-only
"Assessed by" line and a score field against that existing row via `POST
bcms.training-records.assess`, rather than creating a duplicate record. The server refuses both
self-assessment and re-assessing a record that already carries a score/assessor (`AssessBcms
TrainingRecordRequest`/`TrainingComplianceService::assess()`) — "Record a new outcome instead of
overwriting it" for the latter.

**Pagination**: the register is paginated server-side (`TrainingController::compliance()`, 25 rows
per page) — the summary tiles are computed over the full, unpaginated row set, so they cannot
disagree with a number a page turn would otherwise hide. The "attended, not assessed at volume"
banner follows the same rule: its `heavy_amber` flag (and the `attended_not_assessed_count` /
`assessed_count` behind it) is computed server-side over the whole viewer-scoped register
(`TrainingComplianceService::attendedNotAssessedByCurriculum()`) and shipped on each `curricula`
entry, so a page turn never changes it (§3). The shared
`Pagination` component renders beneath the table, its links carrying the current curriculum/
department filters, so a page change never drops them.

## 3. Every state

- **Loading.** Skeleton tiles and table rows, matching the module convention.
- **No curricula loaded** (a brand-new tenant before the reference seeder has run, or a tenant that
  disabled it). The curricula panel shows: "No training curricula are configured. The six
  role-based curricula ship as a content pack — load them from module settings." rather than an
  empty table with no explanation, because an empty compliance table here reads as "nobody trained"
  when the true state is "nothing is configured yet," and those demand different actions.
- **Curricula loaded, no records at all.** The compliance table shows every assigned person with
  "Not attended" in red across the board — not hidden, not summarised away, because this is exactly
  the pre-launch state the module must surface honestly rather than defer.
- **Attendance without competency, at volume.** If more than, say, half of a mandatory curriculum's
  records are "Attended, not yet assessed", a banner above the table reads: "{n} people have
  attended {curriculum.name} but have not been assessed. Clause 7.2 requires an assessed record,
  not attendance — assessments are outstanding, not complete." — because a wall of amber cells
  could otherwise be misread as "basically done." The flag is computed on the server over every page of the viewer-scoped register (a mandatory curriculum where more than half the records are attended-but-unassessed), so it reads the same on every page.
- **Overdue for re-certification.** Rows past `next_due_date` sort to the top of their filtered
  view and carry the `red-50` row tint (matching `Findings/Index.jsx`'s overdue-row convention,
  not a separate badge system) — the person and the date are what gets acted on, not a count.
- **Role resolves to nobody.** A curriculum whose `target_roles` matches no current user (AD-group
  resolution not built, phase-11-spec §7) shows "0 assigned — check target roles; automatic
  enrolment from directory groups is not active in this build" beneath its card, rather than
  silently showing zero as if enrolment ran and found nobody.
- **There is no assessor picker.** Both the record-outcome form and the "Assess now" modal show a
  read-only "Assessed by: {current user's name}" line (the shared `auth.user` prop), never a
  select — the server always records the acting user (`TrainingComplianceService::recordOutcome()`/
  `assess()`) and ignores any `assessor_id` submitted, so a picker would be a control that silently
  did nothing (code-review defect B4).
- **Self-assessment attempted** (the signed-in user is both subject and would-be assessor). Refused
  server-side; `flash.error` renders inline beneath the relevant form (`role="alert"`): "You cannot
  assess your own competence."
- **Assess-now attempted on an already-assessed record** (a race between two managers, or a stale
  row). Refused server-side; `flash.error` renders inline in the "Assess now" modal: "This record has
  already been assessed. Record a new outcome instead of overwriting it."
- **Assess-now attempted on a curriculum that does not require assessment.** Refused server-side;
  `flash.error`: "\"{curriculum.name}\" does not require assessment — attendance is the whole
  record."
- **Record saved.** Standard Inertia flash success; `flash.success` renders inline beneath the
  record-outcome form, the row updates in place, no full-page reload.
- **Validation error** (score outside 0–100, score below `pass_mark` treated as a valid **failed**
  assessment, not an error — a failed assessment is still a record with an actor, a date and a
  result, and the form accepts it and renders it as "Assessed 62% by {assessor} — below the 80%
  pass mark" in `amber-50`, not `emerald-50`, and **not** counted as green on the compliance
  matrix's 7.2 sufficiency check, which requires `score >= pass_mark`).
- **Permission-denied.** Standard 403 for the route; the record-outcome form and curricula-create
  form simply do not render for a `bcms.training.view`-only holder.
- **Server/network error.** Standard handling.
- **Degraded network.** See §6.
- **A page other than the first.** The table shows exactly that page's 25 rows; the summary tiles
  and curricula cards are unaffected (§2) — only the "attended, not assessed at volume" banner can
  read differently per page (see the known gap above).

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Filter by role / department / curriculum / status | `GET bcms.training.compliance` with query params, `preserveState` | None |
| Turn a page | `GET bcms.training.compliance` with `page` plus the current filters (shared `Pagination` component) | None |
| Create a curriculum | `POST bcms.training-curricula.store` | None |
| Record attendance/an outcome | `POST bcms.training-records.store` | None — this is additive, not destructive |
| Assess an existing attendance record | `POST bcms.training-records.assess` | None client-side (there is nothing left to confirm once the assessor field is read-only); the self-assessment and already-assessed refusals are enforced server-side only, surfaced as `flash.error` |
| Open the linked occurrence's AAR | `Link` to `bcms.occurrences.aar.show` (or equivalent) | N/A |
| Follow "load the reference curricula" | `Link` to module settings | N/A |

Nothing on this screen is destructive: there is no delete or "unassess" action specified here — a
competence record, once made, is the kind of first-class row `IsoClauseRef`'s docblock describes,
and amending one is a data-correction concern for the backend-engineer/architect to design a
change-of-record path for if a mistaken assessment needs correcting, not silent deletion from this
screen.

## 5. Accessibility (WCAG 2.1 AA)

- **Attendance and Competency are two separate `<th scope="col">` columns**, never merged into one
  cell with two lines a screen reader would read as one run-on sentence — each is announced with
  its own column header on every cell visit.
- **Overdue rows carry text, not colour alone**: the red tint is paired with "Overdue since {date}"
  text in the Next due cell, matching `Findings/Index.jsx`'s own rule.
- **Every server refusal (self-assessment, already assessed, curriculum does not require
  assessment) is a visible, programmatically associated error** (`role="alert"`, rendered beneath
  the relevant form) — there is no picker to disable or exclude an option from any more, so this is
  the only mechanism that communicates the rule.
- **Keyboard path:** header → summary tiles (non-focusable) → curricula panel cards (each a
  `<details>`/disclosure or plain reading order, no interactive control beyond the create-form
  toggle) → filter controls → compliance table (row-by-row, cell-by-cell in document order,
  including the inline "Assess now" action where present) → record-outcome form (the read-only
  "Assessed by" line is not a tab stop, matching `<dd>`-style static text elsewhere in the module) →
  pagination.
- **Contrast:** reuses the module's existing `emerald-50/900`, `amber-50/900`, `red-50/900`
  pairings exactly as `Findings/Index.jsx` does; no new tone introduced for this screen.

## 6. Low bandwidth (100 kbps)

- **Text-and-table screen throughout** — no chart, no per-curriculum progress ring; a completion
  fraction, where shown, is text ("14 of 20 assessed") not a bar or donut.
- **Server-side filtering and pagination**, matching `Findings/Index.jsx` — the whole compliance
  table is not loaded and then client-filtered, which would be the larger payload on a slow link
  for any tenant with thousands of staff (the test dataset alone is 5,000).
- **The record-outcome and assess-now forms are small, synchronous `POST`s** — no file upload
  exists on this screen at all (ADR 0021 §3), so there is no large-payload risk from this form in
  either direction.
- **The curricula panel's content (module lists, descriptions) is static text**, cached the way any
  Inertia page's shared data is, and does not need per-visit refetching.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Overdue count, by role/department | `bcms_training_records.next_due_date` vs today, joined to `bcms_training_curricula.target_roles` and the person's department |
| Attendance date, score, assessor, `competency_assessed` | `bcms_training_records` columns directly |
| "Linked from {occurrence}" | `bcms_training_records.occurrence_id` → `bcms_exercise_occurrences` |
| Curriculum target roles, frequency, pass mark, assessed/unassessed flag | `bcms_training_curricula` |
| Awareness campaigns sent this year (tile) | `bcms_alerts` count where `template_id` is one of the four seeded awareness templates and `sent_at` falls in the current year |
| "0 assigned" per curriculum | A live count of platform users currently holding any of `target_roles`, computed server-side — never a stored enrolment count that could go stale |

## 8. Deliberately out of scope

- **A separate "Awareness campaigns" screen.** Per phase-11-spec §2.2, an awareness campaign is an
  EMNS alert using one of the four seeded `bcms_alert_templates` (`iso22301.7.3`, severity
  `informational`) — `bcms_alerts` already carries `audience_rule`, recipient count and
  acknowledgement tracking through `bcms_alert_recipients`/`bcms_notification_deliveries`, which
  **is** examiner-grade reach-and-engagement evidence. Composing and sending one is done entirely
  in the existing `Emns/Templates.jsx` (author/select the template) and `Emns/Alert.jsx`
  (compose/send/track) screens; this screen only **counts** them, in the tile above, as the 7.3
  evidence line. Building a second campaign-authoring surface here would duplicate a working
  screen for no examiner-visible gain.
- **Certificate upload, storage or display, in any form.** Retired by ADR 0021 §3: no file input,
  no attachment list, no "view certificate" link. If a customer genuinely needs the provider's
  certificate file retained, that is a second-anchor `bcms_evidence` design the architect must
  raise as its own ADR (ADR 0021 §3's stated condition) — this screen does not anticipate it with
  a disabled control or a placeholder.
- **Automatic AD-group enrolment.** `target_roles` resolution against a directory group depends on
  Phase 2C, which is not built; this screen falls back to the platform's own role assignments and
  says so (§3), rather than presenting a roster the underlying sync cannot yet produce.
- **Editing or deleting a competence record.** Not specified here — see §4's closing note.
