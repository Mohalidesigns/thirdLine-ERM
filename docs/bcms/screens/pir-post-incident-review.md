# Screen spec — the post-incident review (an AAR-builder variant, not a new screen)

**Route: the same route as the exercise AAR, because it is the same table.** `GET aars/{aar}` →
`bcms.aars.show`, `middleware('permission:bcms.exercise.view')` **plus** the incident-side check —
see §1's permission note below, which this spec treats as required and names as new. `PATCH aars/
{aar}` → `bcms.aars.update`, `permission:bcms.aar.manage`. Creating one:
`POST incidents/{incident}/review` → `bcms.incidents.review.start` (name per convention — new,
not in the prompt's API surface, added for the same reason `bcms.aars.reopen` was added in Phase 9:
the flow needs it and it is a small, obvious addition), `permission:bcms.incident.manage`, reached
from the crisis room's closed-incident banner (`crisis-room.md` §3). It creates the `bcms_aars` row
with `incident_id` set and `occurrence_id` null (ADR 0020 §1), then redirects to `bcms.aars.show` —
**the same URL an exercise AAR uses**, disambiguated server-side by `Aar::subject()`.

**This document does not edit `aar-builder.md`.** That file specifies the exercise AAR in full and
belongs to another agent's pass this session. Everything below is a **delta**: what is identical to
that spec (most of it), and exactly what differs, per `docs/adr/0020-...md` §1 and
`docs/bcms/phase-10-incident-clause-map.md` §4.2's comparison table, which this document restates as
the authoritative source for every difference rather than re-deriving it.

**Reads:** ADR 0020 §1 in full (the schema decision, the `saving`-guard exactly-one-of rule, the
clause-stamp rule); clause map §4.1 (why the table had to change), §4.2 (the ten-row delta table —
read that table before this document, it is this document's real content), §4.3 (the ERM bridge's
`mirrorIncident()`).

---

## 1. Purpose and the user

Everything `aar-builder.md` §1 says about its three readers — the person who opens it right after the
event to draft, a more senior approver who reads it later to sign, and an examiner who reads the
finalised version months on — applies here unchanged, with one substitution: there was no exercise,
so there is no facilitator/approver separation-of-duties question tied to *ladder level* (clause map
§4.2 row 2 — nobody was scoring, so the facilitator-approver rule that exists to stop someone marking
their own exercise does not have an equivalent to enforce here in the same shape). What replaces it:
the same principle applied to a real event — the person who ran the incident response should not be
the sole signer of the review that judges it, and this spec requires the AAR builder's
approver-≠-facilitator check to run against `incident.declared_by`/the crisis-room officers who logged
decisions, in place of `occurrence.facilitator_id`, wherever the base screen's code branches on
`aar.subject()`.

**Permission note, named because it is a gap the base spec does not have to close and this one
does:** `aar-builder.md`'s route is gated on `bcms.exercise.view`/`.aar.manage`/`.aar.approve`, which
correctly govern *AAR* actions but say nothing about whether the viewer may see a **post-incident**
review specifically — an incident's PIR can contain personal data about staff and sometimes customers
(clause map §5, register addition 1) in a way an exercise AAR by construction does not. This spec
requires the read route to **also** check `bcms.incident.view` when `aar.isPostIncident()` is true,
and the manage/approve actions to **also** check `bcms.incident.manage` — an examiner reading the
catalog should find both grants named on this path, not just the AAR ones, because a PIR is
incident-shaped evidence wearing the AAR's clothes, and the incident permission is what actually
governs who gets to see personal data about a real event.

## 2. Layout — identical to `aar-builder.md` §2, with these substitutions

Read `aar-builder.md` §2 in full; this section states only what changes, in the same order that
document's own body appears, per the clause map §4.2 table:

- **Header subtitle** reads `{incident.reference} · {incident.title} · {status_label}` in place of
  `{occurrence.definition_name} — {exercise_type_label}`.
- **The AI-draft banner's wording changes one word**: "Parts of this review were drafted by a model,"
  matching the base pattern exactly otherwise (`ai_generated`, `ai_draft_generated_at`, same tone,
  same refusal language).
- **Section 1 (identity)** reads: incident reference, type, severity, activation level, site/unit,
  detected/declared/closed timestamps, declaring officer — sourced from `bcms_incidents`, not an
  occurrence/definition pair. No readiness-override sub-panel (nothing here was overridden into
  running; there was no readiness gate to override).
- **Section 2 (objectives vs outcomes) does not exist in this shape.** Per clause map §4.2 row 2,
  there were no pre-set objectives and no observer scores. **What replaces it, in the same
  card position:** "Plan versus actual" — one row per activated plan **section** (from
  `bcms_plan_sections`, scoped to the plan(s) this incident activated): the section's stated
  procedure, a held/did-not-hold verdict (a required select, same weight as the base screen's
  met/not-met badge), and a note. A section marked "did not hold" is this variant's equivalent of a
  low score, and — mirroring the base screen's disposition control on a 1–2 score — **requires** the
  same inline disposition: link to an existing finding or a short reason, because a plan section that
  failed under a real event and generates no finding is the incident-review version of the defect the
  base screen's condition 6 exists to catch.
- **Section 3 (timeline) reads from `bcms_incident_log`, not `bcms_exercise_timeline`** — same
  chip/type rendering, same "last 10, show all {n}" collapse, condition on ≥1 relevant entry existing
  (a `decision` or `escalation` entry, in place of the base's `milestone` requirement, since a PIR has
  no exercise milestones).
- **Section 4 (quantitative results)** — same JSON display-and-light-edit surface, populated from the
  incident-metrics list the clause map names in full (§4.2 row "Quantitative results"): time to
  detect/declare/activate/first-internal-comms/first-customer-comms/regulator-notification (the last
  compared against **both** the CBN and NDPC deadlines where both obligations exist, reading
  `bcms_incident_notifications` — never against a single merged clock), actual outage vs RTO/MTPD,
  data loss vs RPO, customers/transactions affected, financial impact, headcount accounted for. For a
  DR-flavoured incident, `threshold_breached`-style figures render exactly as the base screen renders
  them for `DRFAILOVER`/`DRTEST` — computed, arithmetic shown, never a typeable checkbox — because
  `dr-failover-failback-record.md`'s own §2 item 3 is this section's source, not a second computation.
- **Section 5 (what worked / what did not)** — identical, unchanged.
- **Section 6 (participant feedback) becomes "Responder debrief"** — same JSON shape, same
  no-attribution rule (role and unit only, never a name), sourced from whoever responded to the
  incident rather than exercise participants.
- **Section 7 (findings)** — identical mechanism, `source = incident`, default clause
  `iso22320.incident_response` in place of the base's finding defaults; `dr_test_id` is deliberately
  **never** set from here (a PIR's findings point at the incident/AAR, not at a test row, per
  `dr-failover-failback-record.md`'s own out-of-scope rule).
- **Section 8 (corrective actions)** — identical mechanism.
- **Section 9 (carried-forward chain) does not apply.** Per clause map §4.2's explicit rule,
  `carried_to_occurrence_id` is the exercise engine's own column and this variant never writes it. In
  its place, this card shows a plain statement where relevant: "An action from this review may be
  validated at the next exercise that covers this scenario, once that occurrence is scheduled — carried
  automatically by the exercise programme when it opens, not by this review." — informational only, no
  select, no disposition control, because there is nothing on this screen to carry.
- **The extra, mandatory tenth section — "What the exercises predicted, and what reality exposed"**
  (Blueprint §12(8), clause map §4.2's own "extra mandatory section" row): a two-column comparison,
  each row one thing a prior exercise's AAR either correctly predicted about this kind of failure or
  did not test at all. Always `ai_generated = true` when populated by the AI capability, editable,
  with the same draft-provenance banner language as every other AI surface in this module — "Nothing
  here is approved by drafting it." This section has no equivalent anywhere in `aar-builder.md` and is
  this variant's one genuinely new card, positioned last, after corrective actions, because it is the
  section that justifies the whole exercise programme to a board and reads best once the reader has
  already seen what actually happened.
- **The finalisation gate.** The base screen's eleven conditions apply **minus** conditions 5, 6 (the
  scoring conditions — nothing was scored) and the inject-count condition, per clause map §4.2's
  closing paragraph. The live checklist therefore shows eight conditions for a PIR, not eleven, and
  states which three are dropped and why directly in the checklist's own heading ("Post-incident
  reviews are not scored against pre-set objectives, so the scoring and inject conditions do not
  apply here") — stated on screen, not left for a reader to notice the count is different and wonder
  if something is broken.
- **Clause stamp.** Every place the base screen shows `iso22301.8.5.report` as the AAR's own stamp,
  this variant shows **`iso22320.incident_response`** instead — the single most important visible
  difference between the two, per ADR 0020's adoption of the analyst's reasoning verbatim: stamping a
  real incident 8.5 would put it into the exercise-programme evidence pack and overstate the testing
  programme to an examiner. This is not a cosmetic label change; the export (§4 below) must carry the
  correct stamp too.

## 3. Every state — identical to `aar-builder.md` §3, with these additions

- **No PIR exists yet for a closed incident.** Reached only via `bcms.incidents.review.start` from
  the crisis room, which creates the draft row and redirects — mirroring the base screen's own "no
  AAR exists yet" state exactly, substituting "the closed incident" for "the completed occurrence."
- **The due-date default for a PIR action differs from the base screen's**, and this is worth its own
  state note because a reader checking a due date needs to know why: "This corrective action has no
  next-exercise anchor — its due date is set from severity alone, or from a regulator's own stated
  remediation deadline where one was given, whichever is sooner." (clause map §4.2's closing line).
- **Every other state** — draft/empty, draft/partial, awaiting the second approver, final, reopened,
  AI-draft in flight/unavailable, validation error, race on finalisation, server/network error,
  permission-denied, degraded network — is **identical in shape** to `aar-builder.md` §3; this
  document does not restate them, per the instruction not to duplicate that file's content.
- **Realised financial loss not yet confirmed.** The Finalise button (`can.approve`) stays disabled,
  independently of the eight-condition gate, until the officer either states a whole-naira figure or
  explicitly checks "No realised loss" (which submits `0` — never left blank and never inferred). The
  declaration-time `incident.estimated_impact_minor` is shown as a hint beside the field, never
  prefilled — finalising restates the actual, it does not rubber-stamp the guess. This is a
  screen-side completeness check, not one of `PirService::conditions()`'s own rows.
- **Finalise rejected for a missing or invalid `realised_loss_minor`.** The server's own validation
  error renders inline under the field (`errors.realised_loss_minor`), the same convention
  every other form on this screen already uses — no separate toast, no silent failure.
- **A final PIR.** The confirmed figure renders read-only, beside the finalisation gate, as
  "Realised financial loss: ₦{amount}" (or "₦0" for the explicit no-loss case) — never editable once
  final, matching every other finalised field on this screen.
- **Criterion 10 (AI draft) stays "unavailable."** This screen does not offer a "Draft with AI"
  action or call an AI-draft route at all in this phase — `ai.available` is always `false` and
  section 10 states the reason instead, per the base screen's own AI-refusal convention, not a
  button that would call nothing.
- **Barred by separation of duties.** `can.approve` already reflects `PirService::
  pirApproverAllowed()` (§1: the incident's declarer, and anyone who logged a decision entry, cannot
  sign the review alone) — this screen never re-derives the rule, only renders its outcome. Two
  distinct cases, not one collapsed "no Finalise button":
  - **Holds the permission, barred this time.** `approver_barred_reason` (a string) is set. The
    Finalise button stays **visible but disabled**, with the reason rendered as visible text
    immediately above it and associated to the button via `aria-describedby` — never a control that
    quietly does nothing, and never a reason a screen-reader user would miss because it wasn't
    programmatically tied to the control it explains.
  - **Genuinely lacks the permission.** `approver_barred_reason` is `null` — the Finalise button (and
    the realised-loss capture beside it, §7) do not render at all, matching this module's standing
    "no apparent means to do the thing you cannot do" rule; a separation-of-duties message is not
    printed at someone the message would not even apply to.
  In neither barred case does the realised-loss capture field render — there is nothing to submit if
  the button it feeds cannot be pressed.
- **Finalised, but the ERM mirror failed.** The finalise write itself never fails because the loss
  register is unreachable (`ErmBridge` is tolerant by design) — `flash.warning` renders as its own
  amber banner alongside (never replacing) the existing green `flash.success` banner: "The review was
  finalised, but the confirmed realised loss could not be mirrored into the ERM loss-event register.
  This has been logged — the loss register may need a manual entry." An officer must be told the
  review is done AND that a follow-up is owed, not left to assume a silent success covered both.

## 4. Interactions — identical to `aar-builder.md` §4, with these differences

| Action | Difference from the base screen |
|---|---|
| Save draft, Reopen, Distribute | Same routes, same confirmation weights, same modal patterns — unchanged. |
| Finalise | `POST bcms.incidents.review.finalise` now additionally requires top-level `realised_loss_minor` (integer, minor units, `>= 0`) in the body — the whole-naira field beside the finalisation gate, converted on submit (§3, above). The eight-condition gate and this field are both required; the button is disabled until both are satisfied. |
| Request AI draft | **Not offered on this screen this phase.** Criterion 10's `PirAiDrafter` does not exist yet (`phase-10-notes.md`, "explicitly deferred"); `ai.available` is always `false`, and this screen does not call an AI-draft route — there is no `urls.ai_draft` on this payload to call. The base screen's own AI-draft action does not apply here until that capability ships. |
| Set a plan-section verdict's disposition | New, in place of the base screen's per-objective disposition control — same shape, same requirement (a "did not hold" verdict needs a linked finding or a reason), saved as part of the same `PATCH bcms.aars.update`. |
| Export | `GET incidents/{incident}/review/export` → `bcms.incidents.review.export`, `permission:bcms.report.export` — **a distinct route from the exercise AAR export**, because the two packs must never be assembled by the same URL that could confuse which evidence pack a given link produces, even though both are built from the shared `Aar` model and (per ADR 0020's consequences) the same underlying presenter. Contents mirror the base export's shape (§5 of `aar-builder.md`) with the tenth section included and the `iso22320.incident_response` stamp throughout. |

## 5. Accessibility — identical to `aar-builder.md` §5

Every rule there (real heading levels, visible clause refs, non-live static banners with a live
result announcement, true modal dialogs for Finalise/Reopen/Distribute, the specific keyboard path,
read-only-removes-from-tab-order, the three-tone contrast system) applies unchanged. The one addition:
**the finalisation checklist's heading itself must state which conditions are dropped and why**
(§2, above) as visible text read by a screen reader before the list of remaining conditions, so a
screen-reader user does not have to count list items against a remembered "eleven" to notice three are
missing.

## 6. Low bandwidth — identical to `aar-builder.md` §6

Same reasoning throughout (one Inertia load, no lazy per-section fetch, incremental `PATCH`, export
never prefetched). No PIR-specific low-bandwidth difference exists.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Everything in the base screen's §7 table | Unchanged in kind, substituted in source per §2 above (incident tables in place of occurrence/definition tables, `bcms_incident_log` in place of `bcms_exercise_timeline`, plan-section verdicts in place of objective scores) |
| Regulator-notification timing metric | `bcms_incident_notifications` — compared against **each** open/closed obligation separately, never a single merged figure, per the clause map's own two-clocks rule |
| The tenth section's comparison rows | The AI capability's output over prior `bcms_aars` rows (`occurrence_id` not null) for exercises covering the same scenario/process, cross-referenced against this incident's actual timeline — draft only, `ai_generated = true` |
| Realised financial loss | Stated by the officer at finalisation, whole naira in the field, top-level `realised_loss_minor` (minor units) on the wire — **not** derived from `incident.estimated_impact_minor`, which is shown only as a declaration-time hint beside the field, never prefilled |
| Loss mirroring | `ErmBridge::mirrorIncident()` (per clause map §4.3), called at finalisation, mirrors the just-confirmed realised figure — not the declaration-time estimate — into `bcms_incidents.erm_loss_event_id`; this screen reads that link if present as a "Mirrored to the loss register" note, same convention `Findings/Index.jsx` already uses for `erm_issue_id` |

## 8. Deliberately out of scope

- **Everything `aar-builder.md` §8 already excludes** — a second findings/CAPA register, editing the
  incident's own core record from here (that is the crisis room's job), board-pack assembly,
  localisation of AI narrative.
- **`carried_to_occurrence_id`** — never written from this variant (§2).
- **A distinct PIR-overdue reminder ladder.** Per ADR 0020's consequences: "Phase 5's T+3/T+7
  AAR-overdue rungs stay exercise-only in this phase... a PIR-overdue chaser is Phase 10's own work
  with its own anchor." This screen does not specify that chaser; it is a reliability-engineer /
  scheduler concern, not a screen.
- **Re-deriving anything the exercise AAR's export already computes.** Where this variant's export
  and the exercise export share a presenter (ADR 0020's consequences section), this spec requires that
  sharing rather than a second implementation that could drift.
