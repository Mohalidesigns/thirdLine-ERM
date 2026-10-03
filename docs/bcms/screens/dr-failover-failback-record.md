# Screen spec — `Bcms/Dr/Invocations/Show` (a real failover/failback, assembled as evidence)

**Route:** `GET incidents/{incident}/dr-invocation/{system}` → `bcms.incidents.dr-invocation.show`,
`middleware('permission:bcms.incident.view')` — reached from the incident's crisis room or its closed
view, never from the DR register directly, because this record exists only in the context of a real
incident and has no life of its own outside one. Read-only: there is no store/update route on this
screen. `permission:bcms.dr.view` is **also** required (a user who cannot see the DR register should
not see its systems' invocation evidence either), so access is the conjunction of both.

**Reads:** `docs/bcms/phase-10-incident-clause-map.md` §3.4 in full — the seven things an auditor asks
for after a real invocation, in order, which is this screen's own table of contents; ADR 0020 §4
("A real invocation is never written into `bcms_dr_tests`... the actuals live in the PIR's
`quantitative_results` with the activation in `bcms_plan_activations`").

**Siblings:** `dr-test-record.md` (the screen this one is deliberately **not** — same numbers, RTO
and RPO actual-vs-target, entirely different provenance and a different table); `crisis-room.md`
(the decision log this screen reads its timeline from, read-only here); `pir-post-incident-review.md`
(the quantitative-results section this screen's actuals are sourced from, once the PIR exists).

---

## 1. Purpose and the user

An auditor, a regulator's examiner, or the bank's own second line asks, after a real IT disruption:
*what actually happened to this system, how was the failover decision made, how long was it really
down, and what came back verified.* This screen exists to answer that question from stored data in
one place, without anyone reconstructing it by hand from the incident's decision log, the plan-
activation record and the PIR separately — while never pretending the event was a scheduled test. It
is a **read-only assembly**, not a form: nothing is entered here, because everything on it already
exists elsewhere and duplicating the write path is exactly the risk ADR 0020 refused. If this screen
existed with its own store route, it would be indistinguishable in the schema from the planned-test
register, which is the one failure mode this whole area of the spec is built to prevent.

## 2. Layout

`AppLayout`, `PageHeader` title `{system.name} — real invocation during {incident.reference}`,
subtitle "This is not a scheduled DR test. It is the record of what happened during a live incident."
— stated as the very first thing on the page, in the header itself, not buried in a footnote, because
this is the single fact a reader must not be able to miss.

**Seven sections, in the clause map's own order, each a card with its own source line in small muted
text** (matching the AAR builder's rule that every section states where its facts come from):

1. **Authorisation.** Who authorised the failover, when, against which runbook version — read from
   `bcms_plan_activations` (`activated_by`, `activated_at`, `activation_reason`, and the linked plan's
   `failover_runbook_plan_id` join back to the plan actually activated, with its approved-version
   date).
2. **Timeline.** The decision to fail over and the decision to fail back, plus everything between —
   rendered as the same reverse-chronological list `crisis-room.md` uses, filtered to entries relevant
   to this system (matched on content mentioning the system, or a tag if the backend engineer adds
   one — this spec does not require a new column for this filter and accepts a simple text/tag
   match), read-only here (this screen never writes a log entry; corrections happen from the crisis
   room itself).
3. **Actual outage vs RTO, and data loss vs RPO, with how each was measured.** Sourced from the PIR's
   `quantitative_results` once the PIR exists (see §3 for the state before it does) — displayed with
   the same "actual vs target, arithmetic shown" treatment as the DR test record and the AAR builder,
   so a reader does not have to learn two different presentations for the same kind of number
   depending on whether the recovery was planned or real.
4. **Failback.** Whether failback happened, when, and whether anything written at the DR site was
   lost in the return — same source, same fields, its own sub-card so a reader who only needs to know
   "did they fail back" does not have to read through the outage narrative to find it.
5. **Data-integrity verification.** Verified usable, not merely present — a short text field from the
   PIR's quantitative results (or a decision-log entry, if the backend records it there instead) making
   the same distinction the clause map insists on.
6. **Communications.** Customer, regulator and staff communications, with what was said and when — a
   read-through of the incident's `communication`-type decision-log entries and, for regulator
   communications specifically, a link into `incident-notification-log.md` rather than a duplicate
   rendering of the same submission rows.
7. **Corrective actions and their verification.** Findings raised with `dr_test_id` **absent** and
   `incident_id` (or `aar_id` once the PIR exists) present, for this system — the same row rendering
   `Findings/Index.jsx` and the AAR builder already use, not reinvented.

## 3. Every state

- **Incident still open, PIR not yet created.** Sections 3–5 (which depend on `quantitative_results`)
  show: "Not yet recorded — these figures are captured in the post-incident review, created once the
  incident is closed." rather than a blank card — an absent PIR is a stated fact, not silence. Sections
  1, 2, 6 and 7 render fully regardless, since they read live data that exists from the moment of
  activation.
- **PIR exists but not yet finalised.** Sections 3–5 show the PIR's current draft values with a small
  "draft — not yet finalised" note, matching the PIR screen's own draft language, so this assembly
  never looks more authoritative than its source.
- **PIR finalised.** All seven sections fully populated, sourced from locked data.
- **No failback recorded at all** (an outage that never returned to the primary site, or is still in
  progress). Section 4 states plainly: "No failback recorded. This system is still running from
  {dr_site}." — not an empty section, a stated operational fact.
- **This incident never actually invoked this system's DR arrangement** (reached by a stale or
  mistaken link — e.g. a system chosen from a picker but no plan activation exists linking it to this
  incident). The whole screen shows one message instead of seven empty cards: "No DR invocation is
  recorded for {system.name} against this incident." with a link back to the incident.
- **Permission-denied.** Standard 403; requires both `bcms.incident.view` and `bcms.dr.view` — a user
  holding only one sees the standard denial with the crisis room or the DR register (whichever they
  do hold) remaining reachable.
- **Degraded network.** Standard handling; this is a document, not a live console — no polling.

## 4. Interactions and their server actions

There are **no write actions on this screen.** Every link is read-only navigation: into the crisis
room's decision log (filtered view), into the notification log, into the findings register, into the
PIR. This is stated as a rule, not an omission: adding a store route here would recreate exactly the
second recording path ADR 0020 closed off.

| Action | Route | Confirmation |
|---|---|---|
| Follow any section's "view full record" link | Plain navigation to the relevant existing screen (crisis room / notification log / findings / PIR) | None |
| Export | `GET incidents/{incident}/dr-invocation/{system}/export` → `bcms.incidents.dr-invocation.export`, `permission:bcms.report.export` — built from stored values across the four source tables, computing nothing new, same principle as the AAR/evidence exports | Plain download, no confirmation |

## 5. Accessibility (WCAG 2.1 AA)

- **The header's "this is not a scheduled test" statement is plain text, not a dismissible banner** —
  it does not need `aria-live` because it is present on every load and never changes state; a screen
  reader announces it as part of the page title region, first, every time.
- **Each of the seven sections uses a real heading level** (`<h2>`), in the clause map's own order, so
  a screen-reader user can jump straight to "Communications" or "Corrective actions" the way the AAR
  builder's nine sections are navigable.
- **The "not yet recorded" and "no failback recorded" states are stated as full sentences**, not a dash
  or an icon, matching the module-wide rule that an absence must read as a fact.
- **Keyboard path:** header (not focusable) → each section in order (its own "view full record" link,
  where present, as the section's one interactive element) → export link.
- **Contrast:** no new tone introduced; the "draft — not yet finalised" note uses the same sky/amber
  treatment the AI-draft and draft-AAR banners already use elsewhere in the module.

## 6. Low bandwidth (100 kbps)

- **One page load, entirely text**, assembled server-side from four existing tables — no client-side
  aggregation, no polling, no chart.
- **Export is explicit and deliberate**, never prefetched, matching every other evidentiary export in
  the module.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Authorisation, runbook version | `bcms_plan_activations` (`activated_by`, `activated_at`, `activation_reason`) joined to the activated plan |
| Timeline | `bcms_incident_log`, filtered to this system, read-only |
| Actual outage vs RTO, data loss vs RPO, integrity verification, failback facts | The PIR's `quantitative_results` JSON (once the PIR exists) — **never** `bcms_dr_tests`, which this incident's invocation is structurally barred from writing |
| Communications | `bcms_incident_log` `communication`-type entries; regulator submissions specifically via `bcms_incident_notifications`, linked not duplicated |
| Corrective actions | `bcms_findings` where `incident_id` (or the PIR's `aar_id`) matches and `dr_test_id` is null, with their `bcms_corrective_actions` |

## 8. Deliberately out of scope

- **Any write path.** By design — see §4.
- **A list of all real invocations across incidents** — this screen is reached per-incident, per-
  system; an aggregate "how many real invocations has this system had" view is a Phase 11 board-pack
  or KRI concern, not this screen's.
- **The DR test register's own screens** — entirely separate; see `dr-system-register.md` and
  `dr-test-record.md`. A reader arriving here from the register (rather than from an incident) is the
  one navigation path this screen deliberately does not offer, because there is nothing to show
  without an incident context.
