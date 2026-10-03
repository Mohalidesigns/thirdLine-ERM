# Screen spec — `Bcms/Incidents/StandDown` (stand-down and closure gate)

**Route:** `GET incidents/{incident}/stand-down` → `bcms.incidents.stand-down-form`,
`middleware('permission:bcms.incident.manage')`. Submission: `POST incidents/{incident}/stand-down`
→ `bcms.incidents.stand-down`, same permission. On success, redirects to the crisis room, now in its
closed state (`crisis-room.md` §3), which is where the post-incident review is reached from.

**Reads:** `docs/bcms/phase-10-incident-clause-map.md` §1.1's stand-down row verbatim: "A status
flipped to closed with no all-clear is how staff stay at an assembly point after the incident ends.
ISO 22320 treats stand-down as an act, not an absence of activity" — and the closure row directly
beneath it: "A reportable incident may not be closed with `is_reportable = true` and
`regulator_notified_at` null and no recorded decision that it was not in fact reportable" (translated
under ADR 0020 to: every obligation on `bcms_incident_notifications` for this incident is either
submitted or explicitly recorded as not owed).

**Siblings:** `resources/js/Pages/Bcms/Exercises/Workspace.jsx`'s "End exercise" confirmation modal
(the consequence-naming pattern this screen's gate borrows, made into a full screen rather than a
modal because stand-down has more to check than a single confirm can honestly hold); the AAR
builder's live finalisation checklist (`aar-builder.md` §2, item 1) — this screen's gate list is that
exact pattern: computed server-side, shown live, never a submit-time surprise.

---

## 1. Purpose and the user

The incident commander or a senior officer with `bcms.incident.manage` opens this once the situation
is genuinely over, to declare stand-down formally — the "all clear" a crisis team and anyone
affected are owed, and the act that closes the incident record. This is a small, deliberate screen
with one job: make it structurally impossible to close an incident with an unresolved regulatory
obligation, an open task nobody decided to drop, or no communication telling anyone it is over. In
the first ten seconds the officer must see: is this incident actually clear to close, and if not,
exactly what is blocking it — the same live-gate pattern the AAR builder already uses for
finalisation, applied here to closure instead.

## 2. Layout

`AppLayout`, `PageHeader` title "Stand down — {incident.reference}", subtitle
`{incident.title} · running {elapsed}`.

**Live closure checklist, first thing on the page**, one line per condition, ✓/✗ with text (never an
icon alone, matching the AAR builder's rule):

1. Every task on this incident is `complete` or `cancelled` (names the count still open, links to the
   crisis room's tasks card if any remain).
2. Every regulatory obligation on `bcms_incident_notifications` for this incident is either fully
   submitted (`final` recorded) or the incident has an explicit decision-log entry recording that it
   was reassessed as **not** reportable to that regulator after all — the clause map's closure rule
   made concrete: a reportable incident cannot close silently, and neither can one whose reportability
   was answered `unknown` and never resolved.
3. Neither reportability question is still `unknown`.
4. At least one decision-log entry of type `escalation` or `decision` exists recording the stand-down
   decision itself and containing the all-clear message — **this condition can only ever be satisfied
   by submitting this screen**, so it always shows unmet until the form below is filled and submitted;
   it is listed for the same reason the AAR builder lists conditions the draft itself will satisfy —
   so the officer sees the whole shape of what closing requires, not just what is currently missing
   from earlier steps.
5. If this incident activated any plan (`bcms_plan_activations` rows exist), each has either a
   `deactivated_at` or an explicit statement below that it remains active post-closure (some
   continuity arrangements outlive the incident record, e.g. a relocated team that has not yet
   returned) — a plan left silently "activated forever" on a closed incident is a gap the same way an
   open task is.

**Below the checklist, the stand-down form itself** (always rendered, submit disabled while any of
conditions 1/2/3/5 above are unmet — condition 4 is satisfied by this very submission):

- **All-clear message** (long text, required) — the communication that will be logged and, if the
  officer chooses, dispatched as an EMNS communication to whichever audience groups received earlier
  SitReps on this incident (a checklist of those audiences, pre-selected).
- **Reason / summary of resolution** (short text, required) — feeds the decision-log entry's content.
- Per unresolved-obligation-turned-not-reportable case (only shown if condition 2 was satisfied via
  that path): the reassessment reason is captured here inline rather than requiring a separate prior
  step, so the officer is not sent back and forth between two screens to close one incident.
- **"Dispatch the all-clear as a communication"** checkbox, checked by default when any audience
  group is available, unchecked (and hidden) when none is — closing an incident does not require
  telling anyone if nobody outside the crisis team was ever told it started.

**Footer:** "Stand down and close" (disabled with a tooltip naming the first unmet condition, exact
AAR-builder pattern), and a plain "Back to crisis room" link — no other actions on this screen.

## 3. Every state

- **All conditions met.** Checklist all green, form enabled, button active.
- **One or more conditions unmet.** Checklist shows exactly which, each with a link to where it is
  fixed (tasks card, notification log, the classify control) — this screen never asks the officer to
  guess where to go.
- **Already closed** (reached via a stale link or back-navigation after another tab closed it). Read-
  only: "This incident was already closed at {closed_at} by {closed_by}." with a link back to the
  read-only crisis room — no form rendered.
- **Submission attempted while a condition is unmet** (a race between two tabs). Server refusal
  re-syncs the checklist and highlights the specific failed condition, exactly as the AAR builder's
  equivalent race state — never a bare "cannot close" toast.
- **Validation error** (missing all-clear message or reason). Per-field, `role="alert"`.
- **Submit succeeds.** Redirects to the crisis room's closed state.
- **Permission-denied.** Standard 403; a viewer with `bcms.incident.view` only sees the crisis room's
  header without a "Stand down" link at all (established pattern).
- **Server/network error.** Standard handling; typed message and reason preserved.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Stand down and close | `POST bcms.incidents.stand-down` | The submission **is** the confirmation — a screen with a live gate and an explicit all-clear message does not need a second modal on top of itself; this mirrors the AAR builder's own reasoning for why Finalise still gets a modal (a single click there triggers a lock with no form filled first) versus why this screen does not repeat that pattern (the form itself is the deliberate step, and requiring a further "are you sure" after typing an all-clear message is exactly the alert-fatigue-adjacent friction the module argues against elsewhere). |
| Fix an unmet condition | Links out to the crisis room's relevant card or the notification log | — |

## 5. Accessibility (WCAG 2.1 AA)

- **The closure checklist is a real list** (`<ol>`/`<li>`), each item's met/unmet state carrying icon
  and text together, matching the AAR builder's checklist exactly — same component, reused.
- **Condition 4's "always unmet until this submission" state is explained in its own text**, not left
  for the officer to puzzle over why one line never turns green on its own: "Satisfied by submitting
  this form below."
- **Keyboard path:** checklist (not focusable, read top to bottom) → all-clear message → resolution
  reason → reassessment-reason field (only in the tab order when shown) → audience checklist (only
  when shown) → dispatch checkbox → Stand down button → Back link.
- **Contrast:** identical palette to the AAR builder's gate (red/amber/emerald), no new tone.

## 6. Low bandwidth (100 kbps)

- **One page load, one submit** — no polling, no live metrics bar; by the time an incident reaches
  stand-down, the crisis room's live tiles are no longer the point, and a static checklist costs
  nothing extra to render correctly on a slow link.
- **The all-clear dispatch, if checked, reuses the existing EMNS dispatch path** — its own confirm and
  network behaviour are `Alert.jsx`'s, not duplicated here.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Open task count (condition 1) | `bcms_incident_tasks` where `status not in (complete, cancelled)`, this incident |
| Obligation resolution state (condition 2) | `bcms_incident_notifications`, all rows for this incident — every row either has `submitted_at` for its `final` kind or the incident's decision log carries a "reassessed as not reportable" entry for that regulator |
| Unresolved-reportability state (condition 3) | The same two classification questions the crisis room reads |
| Plan-deactivation state (condition 5) | `bcms_plan_activations` where `incident_id` matches and `deactivated_at is null`, cross-checked against an explicit "remains active" statement recorded on this screen's submission |
| Elapsed time in the subtitle | `now() - declared_at`, computed once at render (this screen is not a live console) |

## 8. Deliberately out of scope

- **Reopening a closed incident.** Not specified in this phase's prompt or clause map; if a bank needs
  to correct facts after closure, that happens through the post-incident review's own findings/CAPA
  machinery, not by reopening the incident record itself.
- **The post-incident review form** — reached only after this screen succeeds, specified separately in
  `pir-post-incident-review.md`.
- **Cancelling an incident that was never real** (a false-alarm declaration) — a distinct action from
  stand-down (which closes a real, resolved incident) and not specified here; if the backend needs it,
  it belongs on the crisis room as its own explicit action, not folded into this gate.
