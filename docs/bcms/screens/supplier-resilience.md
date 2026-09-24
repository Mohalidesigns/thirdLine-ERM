# Screen spec — `Bcms/Suppliers/Resilience` (vendor continuity, exercise participation, concentration)

**Route:** `GET bcms/vendors/continuity` → `bcms.vendors.continuity`,
`permission:bcms.report.view` — the main screen (three tabs/sections, §2). `POST
bcms/vendors/{thirdParty}/attestation` → `bcms.vendors.attestation.store`, checked against
**both** `bcms.report.view` **and TPRM's own authority** (§4) — this route writes a `tp_bcp_tests`
row through TPRM's service, never directly. `GET bcms/vendors/concentration` →
`bcms.vendors.concentration`, `permission:bcms.report.view` — folded into this screen as its third
section rather than a separate page (§2).

**Reads:** `docs/bcms/phase-11-spec.md` §4 in full (the BCMS-relevance filter, the
continuity-currency view, vendor exercise participation via a `bcms_contacts` row rather than a
new column, the concentration read-through with no shareholders'-funds-based utilisation
percentage, and **the seam**: a BCMS dependency names a third party, TPRM's evidence hangs off an
*engagement* — a vendor with more than one qualifying engagement is *evidence-ambiguous* and the
screen must say so rather than guess), §6 criterion 7 (an expired attestation appears on the chase
list and moves `BCMS-VENDOR-ATTEST`); **ADR 0021 §4 — confirms** the vendor-participant-as-contact
design (no `third_party_id` column exists or will exist on `bcms_exercise_participants`) and that
continuity evidence is written to TPRM's own tables through TPRM's own service, never direct from
BCMS.

**Siblings:** `resources/js/Pages/Bcms/Bia/Dependencies.jsx` (how a dependency's target and its
criticality/SPOF flags render, including the "no longer in the register" honest-null state this
screen's vendor list must reuse for a soft-deleted third party); `resources/js/Pages/Tprm/Reports/
BoardPack.jsx` (the `Tile`/`Table` component shapes and the "Not computed" — never a fabricated
zero — treatment for `concentration.hhi`, directly relevant since this screen deliberately shows
**no** utilisation percentage at all, a stronger version of the same caution); `resources/js/Pages/
Bcms/Findings/Index.jsx` (chase-list-as-highlighted-rows, not a bare count).

---

## 1. Purpose and the user

A **BCMS programme owner or compliance officer** (`bcms.report.view`) opens this screen to answer
three related but distinct questions that the vendor register in TPRM does not answer on its own,
because TPRM's own tiering is about vendor risk to the bank generally, not about **which vendors
this continuity programme actually depends on**: (1) which vendors are critical *to our BIA*, not
just critical in TPRM's own model; (2) whose continuity evidence has gone stale and needs chasing;
(3) which vendors are concentrated across more than one Tier-1 process, which is a single point of
failure the BIA's own dependency graph would not show without this cross-cut. In the first ten
seconds: how many BCMS-critical vendors exist, how many have expired or missing continuity
evidence (the chase list, front and centre — matching the register convention of showing the row,
not the count), and whether any vendor is flagged evidence-ambiguous.

This screen deliberately **writes nothing to the vendor register itself**. Its one write action —
recording an attestation — is a thin form that posts into TPRM's own `tp_bcp_tests` table through
TPRM's own service and authority check; a BCMS permission grant is not a licence to write the
vendor register (phase-11-spec §5's own instruction, restated as the screen's central constraint).

## 2. Layout

`AppLayout`, `PageHeader` title `Supplier resilience`, subtitle `{critical_count} vendors are
BCMS-critical — depended on by a Tier-1 or critical-service process.`

Three sections, tabbed (`Continuity` / `Exercise participation` / `Concentration`), state
preserved in the URL query.

### Continuity (default tab)

**Chase-list banner**, above the table whenever non-empty, matching `Findings/Index.jsx`'s
amber/red banner convention: "{n} vendors' continuity evidence is overdue or missing." listing the
worst few by name, `Link`-ing to their row.

Table, one row per BCMS-critical third party (the BCMS-relevance filter — a `bcms_dependencies`
row with `dependable_type = 'tprm_third_party'` against a process that is Tier 1 or
`is_critical_service`, per §4(1) — **not** TPRM's own tiering):

| Vendor | Depended on by | Latest BCP test | RTO achieved vs commitment | Next due | Evidence |
|---|---|---|---|---|---|

- **Vendor** — name from the `dependable` (or "Third party no longer in the register" per
  `Bia/Dependencies.jsx`'s existing honest-null convention if soft-deleted).
- **Depended on by** — the process(es) and their tier, so a reader sees *why* this vendor is on the
  list without leaving the row.
- **Latest BCP test** — `tp_bcp_tests.test_date`/`test_type`/`outcome`, or "No test on file" in
  `red-50` if none exists at all.
- **RTO achieved vs commitment** — `rto_achieved_hours` against the engagement's committed RTO,
  with a breach flagged in `red-50` text, matching the TPRM board pack's own `Tile` treatment for
  an achieved-vs-target figure.
- **Next due** — `tp_bcp_tests.next_due_at`; past-due renders `red-50` and is what puts the row on
  the chase-list banner; within 30 days renders `amber-50`.
- **Evidence** — `our_participation` (boolean, "We participated" / "Vendor-only test") and a `Link`
  to the evidence document if `evidence_document_id` is set, else "No evidence document attached."

**Evidence-ambiguous flag** (§4(5)): where a vendor has more than one TPRM engagement with
`supports_critical_function = true`, the Vendor cell carries a small `amber-50` badge —
"Evidence ambiguous — {n} qualifying engagements" — and the row's "Latest BCP test" column instead
lists **each** qualifying engagement's own latest test as a short stacked list rather than picking
one, because picking one would be a guess about which service the dependency actually depends on,
which §4(5) explicitly forbids ("do not guess").

**Record an attestation** (row action, `bcms.report.view` **and** the TPRM authority check —
modelled deliberately thin): a small inline form — test date, test type, our participation
(checkbox), RTO/RPO achieved, outcome, findings raised (free text), evidence document picker,
next-due date — that posts to `bcms.vendors.attestation.store`. **The form's own copy states what
it is doing**: "This records a BCP test result on {vendor}'s engagement record in Third-Party Risk
Management. It is the same row TPRM's own screens show." — so a user is never confused about which
register they just wrote to.

### Exercise participation

A table of vendor contacts invited onto occurrences: person (a `bcms_contacts` row carrying the
vendor link, per §4(3) and ADR 0021 §4 — **not** a `bcms_exercise_participants.third_party_id`,
which does not and will not exist), vendor, occurrence, role, invitation/attendance status,
performance note (free text captured on the AAR's participant-feedback section, read-only here,
`Link`s to that AAR). An "Invite a vendor contact" action opens the same contact-picker the call-
tree/EMNS screens already use, scoped to contacts flagged as vendor-linked, **not a new picker
component**.

### Concentration

Read-through from `bcms_dependencies`, cross-referenced with TPRM's own `tp_concentration_analyses`
(the `ConcentrationAnalysis` model, immutable per-run snapshots): a table of vendors appearing as a
dependency for more than one Tier-1/critical-service process, with the process list and a count —
**and explicitly no utilisation-percentage or limit-breach figure**, per §4(4): TPRM's HHI/
concentration bands have no shareholders'-funds figure behind them (a named, deliberate go-live
gap), so this view reports **counts and named processes only**. A small permanent note states
this: "This view shows how many of our processes depend on each vendor. It does not show a
concentration limit or its utilisation — TPRM's concentration measure has no shareholders'-funds
figure behind it in this build, so no percentage is computed here." A `Link` to TPRM's own
concentration screen for the HHI/SPOF figures TPRM does compute, rather than duplicating them.

## 3. Every state

- **Loading.** Skeleton rows per tab.
- **No BCMS-critical vendors.** "No vendor currently appears as a dependency of a Tier-1 or
  critical-service process. Link a dependency in the BIA workspace, or check that your Tier-1
  processes have their dependencies mapped." — not a bare empty table, because the likely cause
  (dependencies not yet mapped) is different from "there truly are none," and the message points at
  the fix.
- **Chase list empty.** No banner at all (matching the module's convention of a banner appearing
  only when there is something to act on, e.g. `Programme/Index.jsx`'s RACI-gap banner) — an empty
  chase list is not itself announced as a success tile with a checkmark, because "0 overdue" over a
  vendor set of zero would be exactly the fabricated-positive `NoFabricatedNumbersTest` exists to
  catch; the summary line in the subtitle already carries the honest denominator.
- **Vendor with no BCP test at all.** Red "No test on file" — distinct from "expired": a vendor
  that has simply never been tested is a different, arguably worse, finding than one whose evidence
  lapsed, and the two must not collapse into one "non-current" bucket.
- **Evidence-ambiguous vendor.** The stacked-engagement rendering above; the row cannot be
  collapsed to a single BCP-test summary by design.
- **Attestation form submitted successfully.** Standard flash; the row's Latest BCP test / Next due
  cells update from the same response (the backend returns the refreshed row), no full reload.
- **Attestation form: TPRM authority denied** (the user holds `bcms.report.view` but not the TPRM
  permission the write requires). The row action does not render at all for such a user, matching
  this module's "no apparent means to do the thing you cannot do" precedent — not a disabled
  button, not a 403 discovered on submit.
- **Vendor no longer in the register** (soft-deleted in TPRM). Row renders per
  `Dependency::dependableLabel()`'s existing convention: "Third party no longer in the register" —
  the dependency and its chase-relevant history stay visible; only the live vendor link is gone.
- **Exercise participation: no vendor contacts invited to anything yet.** "No vendor has been
  invited to participate in an exercise. Vendor participation is recorded the same way any
  contact is invited onto an occurrence." with a link to the invite action.
- **Concentration: TPRM has never run an analysis.** "TPRM has not yet run a concentration
  analysis." rather than an empty table with no explanation — this view cannot compute one itself
  (§4(4), no second scorer).
- **Permission-denied.** Standard 403 for the whole screen.
- **Server/network error.** Standard handling.
- **Degraded network.** See §6.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Switch tab | Client-side, URL query | None |
| Record an attestation | `POST bcms.vendors.attestation.store` (writes `tp_bcp_tests` via TPRM's own service; both `bcms.report.view` and TPRM's authority are checked server-side) | None — an attestation record is additive, matching TPRM's own evidence-upload lack of confirmation |
| Invite a vendor contact to an occurrence | Existing contact-picker action, posts to the existing `bcms.exercise-definitions`/occurrence participant route | None, matching the module's existing invite flow |
| Follow a link to a process, an AAR, TPRM's own vendor record, or TPRM's concentration screen | Standard `Link`, server-built URL prop | None |

Nothing here deletes or overrides a TPRM record; the one write is additive and scoped to a single
attestation.

## 5. Accessibility (WCAG 2.1 AA)

- **The evidence-ambiguous badge carries text, not an icon alone** ("Evidence ambiguous — {n}
  qualifying engagements"), and the stacked-engagement list it triggers is a real `<ul>` inside the
  cell, not a comma-joined string a screen reader would read as one undifferentiated block.
  qualifying engagements
- **Tabs are a real tab pattern** (`role="tablist"`/`"tab"`/`"tabpanel"`, arrow-key navigation
  between tabs, matching whatever tab primitive the product already uses elsewhere — Headless UI
  per ADR 0007 — not three separately-styled `<div>`s toggled by unmarked buttons).
- **The "no percentage shown" note in the Concentration tab is static, visible text**, not a
  tooltip on a chart element — there is no chart on this tab at all, so the caveat simply sits
  beside the table.
- **Keyboard path:** header → tab list → (Continuity) chase-list banner links → table rows in
  document order, each row's attestation-form trigger and evidence link reachable → (Exercise
  participation) table and invite action → (Concentration) table and the TPRM-screen link.
- **Contrast:** reuses `red-50/900` (overdue/missing), `amber-50/900` (evidence-ambiguous, due
  soon), matching the module's existing tokens exactly.

## 6. Low bandwidth (100 kbps)

- **Text-and-table throughout**; no chart for concentration (deliberately — see §8) or for RTO
  achievement, which renders as text ("62h achieved vs 48h committed — breach") matching
  `aar-builder.md`'s own choice for the equivalent DR-threshold fact.
- **Each tab's data loads with the page** (three tabs' worth of rows is not large relative to other
  registers this module already ships, e.g. `Findings/Index.jsx`); no per-tab lazy fetch, avoiding
  a user switching tabs on a slow link and waiting again.
- **The attestation form is a small, synchronous `POST`** with no file upload beyond an existing
  evidence-document picker (which itself is a reference to an already-uploaded document, not a new
  upload control here).

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| BCMS-critical vendor count and list | `bcms_dependencies` where `dependable_type = 'tprm_third_party'`, joined to the assessed process's tier/`is_critical_service` — the BCMS-relevance filter, computed here, distinct from TPRM's own tiering |
| Latest BCP test, RTO/RPO achieved, outcome, next due, evidence document | TPRM's `tp_bcp_tests`, read only |
| "Evidence ambiguous" flag | Count of the vendor's `tp_engagements` where `supports_critical_function = true` — more than one triggers the flag |
| Chase-list membership | `tp_bcp_tests.next_due_at` in the past, for a BCMS-critical vendor — this is also what moves `BCMS-VENDOR-ATTEST`, the one KRI measurement this phase writes itself |
| Vendor exercise participation rows | `bcms_exercise_participants` joined to `bcms_contacts` carrying a vendor link, per §4(3) |
| Concentration table | `bcms_dependencies` grouped by vendor across Tier-1/critical processes, cross-referenced against TPRM's `tp_concentration_analyses` (`ConcentrationAnalysis`, read only, latest run) — no recomputation |
| Utilisation percentage | **Never shown.** No shareholders'-funds figure exists behind TPRM's concentration bands (a named go-live gap); this screen reports counts and named processes only |

## 8. Deliberately out of scope

- **A vendor attestation table or form that writes into BCMS.** There is no `bcms_vendor_*` table
  and none is proposed; the write goes to `tp_bcp_tests` through TPRM's own service, full stop.
- **A concentration limit / utilisation percentage.** Not computable honestly without the
  shareholders'-funds figure TPRM does not have; showing a number here would contradict TPRM's own
  documented gap. This is a permanent limitation of the screen, not a phased rollout.
- **A `third_party_id` column proposal on `bcms_exercise_participants`.** Confirmed unnecessary by
  ADR 0021 §4 — the contact-carries-the-link design is final for this phase.
- **Guessing which engagement a dependency "really" means** for an evidence-ambiguous vendor. The
  screen lists all qualifying engagements; it does not pick one, rank one, or hide the ambiguity
  behind an average.
- **A chart for concentration or RTO achievement.** Both render as text/table per §6, consistent
  with the product's inline-SVG-only convention and this screen's genuinely small data volumes not
  justifying one.
