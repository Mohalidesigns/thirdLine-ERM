# Screen spec — `Bcms/Compliance/Matrix` (the clause-by-clause evidence matrix)

**Route:** `GET bcms/reports/compliance-matrix` → `bcms.reports.compliance-matrix`,
`middleware('permission:bcms.report.view')`. No write actions live on this screen; every mutation
it triggers (obligation applicability, evidence pack export, AI gap analysis) posts to a route
that already exists or is specified in a sibling file.

**Reads:** `docs/bcms/phase-11-spec.md` §1.1–§1.4 (the clause map and its sufficiency rules), §2
(content packs — curricula codes, KRI codes, the internal-audit checklist), §3.2 (the ISO 22301
pack's nineteen rows and their sufficiency rule, which this screen's cells are a live rendering
of); **`docs/adr/0021-phase-11-asks-for-no-schema-and-the-kri-register-is-adopted-not-invented.md`
— binding, and it changes the matrix from three states to four.** §1 there rules there is no
internal-audit table and never will be one scoped to BCMS: 9.2 programme/results are evidenced by
reference, and the matrix carries **amber** for "an external reference or partial evidence" as a
fourth, distinct state from grey's "not applicable" — 9.2 is **never grey**, because it applies to
every certified BCMS and grey would misread as "not required." §2 rules KRI definitions are
**adopted** into the existing `key_risk_indicators` table the way TPRM's `KriPublisher` already
does it — no `bcms_kri_links` table, the join is `kri_code` directly — and that measurements move
through `KriMeasureBridge::recordMeasurement()`, never a direct write, so a KRI's band and breach
state are trustworthy wherever this screen shows them. §4 confirms no `bcms_report_runs` table:
pack generation is an audit-log event only (relevant to `evidence-pack-export.md`, not this
screen's own state).

**Siblings:** `resources/js/Pages/Bcms/Programme/Index.jsx` (the maturity panel's "dash, not a
one" rule for a clause group with no evidence — this screen's grey state is the same discipline
applied per clause rather than per group, and the `title={s.rationale}` pattern for a hover-visible
rationale is reused, made visible-by-default here rather than hover-only, because an examiner
should not have to discover a tooltip); `resources/js/Pages/Bcms/Bia/Report.jsx` (gaps rendered as
a named list beside the coverage figure, never folded into the rate); `resources/js/Pages/Bcms/
Findings/Index.jsx` (the three-tone badge convention this screen's cells extend to a fourth tone).

---

## 1. Purpose and the user

Three people open this screen and none of them is browsing. A **compliance officer**
(`bcms.report.view`) opens it before a board meeting to see what would embarrass the bank if asked
about today. An **examiner** — CBN, an ISO certification auditor, an internal auditor — opens it
(or is handed a printed/exported version of it) with one clause in mind and expects to click
straight to the row that answers it. A **programme owner** opens it after adding an artefact
(approving a BIA, finalising an AAR) to confirm the cell actually moved, because a matrix that
lags what was just approved is worse than no matrix — it teaches the reader to distrust it.

In the first ten seconds, any of the three must be able to see: how many clauses are green, how
many are amber (a partial or externally-referenced state — 9.2 lives here when it has anything at
all), how many are honestly red, how many are grey-with-reason, and which of the eleven ISO 22301
mandatory records are among the red or amber ones — because a red mandatory record is not one gap
among many, it is the finding an examiner opens the visit with.

This is an examiner-facing document rendered as a screen, in the same register as the TPRM board
pack and `aar-builder.md`'s AAR: judged on completeness and traceability, not on visual polish.

## 2. Layout

`AppLayout`, `PageHeader` title `Compliance & evidence`, subtitle `ISO 22301, ISO 22318 and CBN —
{programme.year}. {green_count} green · {amber_count} partial · {grey_count} not applicable · {red_count} gaps, {mandatory_gap_count} of them mandatory records not fully evidenced.`
Header action: **Export evidence pack** (`bcms.report.export`) — a button, not a link, because it
opens the framework/period picker specified in `evidence-pack-export.md` rather than downloading
directly from here.

**Summary strip**, above the fold, five tiles (matching `Home.jsx`'s tile pattern, not a new
component): Green clauses / Amber clauses / Red clauses / Grey (not applicable) / Mandatory
records not fully evidenced — the last tile in `red-50` whenever it is non-zero, because that is
the number a board minute quotes, and it counts both red **and** amber mandatory rows (an amber
9.2 is still one of the two records the product does not hold outright, per ADR 0021 §1's
"two of eleven mandatory records stay non-green, by decision").

**The matrix**, grouped by standard in a fixed order — ISO 22301 (in clause order, 4→10), ISO
22318, then the CBN/DORA crosswalk as a labelled sub-section (per phase-11-spec §3.4, this is a
view over the ISO rows, not a fourth taxonomy: each CBN/DORA line says *"answered by:"* and links
to the ISO row(s) it reads from, rather than duplicating the artefact check). Each standard is a
collapsible `<section>` (open by default for ISO 22301, collapsed by default for the others,
state preserved in the URL query so a link to "just DORA" is shareable) containing a table:

| Clause | Title | Mandatory? | State | Artefact | Last evidenced |
|---|---|---|---|---|---|

- **Clause** — the `iso_clause_ref` code, monospace, exactly as `IsoClauseRef` spells it (never a
  paraphrase — `docs/DEVELOPMENT_STANDARD.md`'s citation rule and this module's own convention of
  showing the ref as visible text, not a tooltip, established in `aar-builder.md` §2).
- **Title** — from `bcms_clause_refs.title`, the joined reference row, never hard-coded per screen
  (per the enum's own doc comment: "neither is derivable from the other").
- **Mandatory?** — a small badge, shown only where `is_mandatory_record` is true, because an
  unmarked cell already reads as "ordinary" and the badge is what makes a reader stop.
- **State** — the four-tone cell (green / amber / red / grey), see §3. Never a bare percentage or
  a checkmark alone; always icon **and** text, matching `aar-builder.md`'s live-checklist rule.
- **Artefact** — a one-line description of what is backing the cell (`"11 of 11 completed
  occurrences have a finalised AAR"`, `"No internal audit programme is held in this system"`,
  `"Open Banking clauses — institution has no such licence"`), sourced per the sufficiency rule in
  phase-11-spec §3.2's table for that row, **never a number with no noun attached** (development
  standard §5: a figure with no named source is cut, not shown behind a dash).
- **Last evidenced** — the date of the artefact that makes the cell true (an AAR's
  `approved_at`, a BIA's `approved_at`, the maturity assessment's `assessed_at`) — blank, not
  "never", where the cell is grey (not-applicable clauses have no evidence date because none is
  owed).

**Row click → drawer**, not a navigation away from the matrix (an examiner working down a list
should not lose their place): a right-side panel listing every underlying row the sufficiency rule
counted (e.g. clicking 8.5.report opens the list of occurrences and which have/lack a finalised
AAR, each linking out to its own AAR/BIA/finding screen in a new context via `Link`). The drawer's
own empty state for a red cell is the **named gap**, not a spinner: "0 of 4 completed occurrences
in this period have a finalised AAR: {list}."

**KRI section** (clause 9.1), below the matrix rather than inside its own drawer, because
seventeen rows do not fit a drawer meaningfully: a compact table of the seventeen resilience KRIs
**as adopted into the existing KRI module** (ADR 0021 §2 — a `Support` catalogue of definitions,
`adopt()`-ed per tenant the way TPRM's `KriPublisher` adopts its nine, joined purely on `kri_code`,
no BCMS-specific link table). Columns: code, name, target, **current value with its RAG band**
(read from `KeyRiskIndicator`'s own thresholds, never recomputed here), last measured, producing
phase. **This is not a new KRI dashboard** — it links out to the existing KRI module's own detail
screen for a full history/trend/breach view; this table is the 9.1 evidence line for this matrix
and nothing more (see §8). A row whose `kri_code` no longer resolves to a `KeyRiskIndicator` (a
tenant renamed or deleted it after adoption) renders as **"Not linked"**, plainly, rather than
being silently dropped from the table — the summary line above the table reads "{n} of 17
resilience KRIs are not linked" whenever `n > 0`, which is also how a tenant discovers they broke
the join by editing a code (ADR 0021 §2's own stated purpose for this exact sentence).

**Maturity note**, a small callout above the KRI section, not a second scoring surface: "Maturity
score {overall_score}/5, assessed {assessed_at}, method {method_version} — see Programme
governance for the full clause-group breakdown." One `Link` to `bcms.programme.index`, because
`Programme/Index.jsx` already owns that view (§8) and a customer with more than one business unit
sees **one** organisation-level score here, not a per-branch heatmap — the schema (
`bcms_maturity_assessments.organization_id`, no business-unit column) does not carry a per-branch
dimension today, and this screen says so rather than fabricating branch rows.

**AI gap-analyser panel**, collapsed by default under a "Ask the gap analyser" disclosure: a
button that posts to `bcms.reports.gap-analysis.ai` and renders the returned findings as
**drafts**, each in the sky-toned `ai_generated` banner style from `Bia/Workspace.jsx`, each
citing an org node and an elapsed time per phase-11-spec §6 criterion 9, each with an inline
"Raise as a finding" action (posts to the existing `bcms.findings.store`, `source` pre-set to
`gap_analysis` — matching `Findings/Index.jsx`'s own raise form, not a new endpoint) and a
"Dismiss" action that discards the draft without creating anything. **The panel cannot mark a
clause green** — it is display-only over the same read model the matrix already computed; a
finding raised from it changes the *findings* register, and the clause cell moves only when the
next matrix computation re-reads that register.

## 3. Every state

- **Loading.** Skeleton rows per standard section (three placeholder rows each), matching the
  product's existing skeleton convention — never a blank white body, which on a long clause list
  reads as broken rather than loading.
- **Green** (`emerald-50` cell background, `✓` + "Evidenced"): the sufficiency rule in
  phase-11-spec §3.2 for that row is met. Hovering/clicking always still opens the drawer — a
  green cell is not "nothing to see," an examiner is entitled to see the eleven completed
  occurrences even when all eleven have a final AAR.
- **Amber, partial or externally referenced** (`amber-50` cell, a half-filled glyph + a **named**
  reason, e.g. "Findings recorded; no audit report held in the system" or "Internal audit
  programme referenced in the {date} management review — see the review"): the artefact exists but
  falls short of full sufficiency, or is evidenced by reference to a record this product does not
  itself hold. This is the tone **9.2 programme and 9.2 results render in whenever anything at all
  is on file** — see the dedicated rule below — and it is also 7.3's tone for "reach reported, no
  engagement data" per phase-11-spec §1.1.
- **Red, honest gap** (`red-50` cell, `✗` + a **named** reason, e.g. "1 of 12 completed occurrences
  has no finalised AAR"). A red **mandatory** row additionally carries the mandatory badge in red,
  not grey, so it cannot be mistaken for an ordinary gap.
- **Grey, not applicable** (`gray-100` cell, a dash glyph + "Not applicable — {applicability_note}"):
  the institution's `bcms_programme_obligations` row for that clause/framework has `applies =
  false`. **A grey cell must show the rationale text inline, not behind a click** — criterion 3's
  whole point is that "not applicable" is a recorded institutional decision, not a shrug, and an
  examiner reading a printed export must see the reason without an interactive drawer available to
  them.
- **No obligation register loaded yet.** If `bcms_programme_obligations` has never been seeded
  (`Programme/Index.jsx`'s own empty state for this), every clause that could be grey instead
  renders **amber** with "Applicability not yet determined — load the obligations register in
  Programme governance" and a `Link` there, rather than silently defaulting to red (which would be
  a false gap) or green (which would be worse). This is a distinct amber reason from the 9.2 amber
  above; the cell text always says which.
- **9.2 programme / 9.2 results — never grey, per ADR 0021 §1.** These two clauses apply to every
  certified BCMS, so "not applicable" is never a legitimate reading regardless of what
  `bcms_programme_obligations` says about them: the matrix ignores applicability for these two
  rows specifically. State is derived from the **latest approved management review's `inputs`
  snapshot** (§2.4/ADR 0021 §1): where it carries an `internal_audit` block (report reference,
  date, auditor, independence statement, conclusion), the cell is **amber** — "Internal audit
  referenced in the {review title}, {held_on} — see the review" with a `Link` into
  `management-review-inputs.md`'s screen for that review. Where no approved review carries that
  block, the cell is **red** with the exact sentence from ADR 0021 §1: *"No internal audit
  programme is held in this system; ISO 22301 9.2 is evidenced from the internal audit function's
  own records."* — not a paraphrase, because until the escalation path in ADR 0021 §1 is taken up
  (a platform-level `audit_programmes` pair, not a BCMS one), this sentence **is** the artefact.
  **9.2 results** additionally reports linked `bcms_findings` where `source = audit`: present and
  linked to an `issues` row raises the cell from red to amber even without a review block, empty
  and unlinked stays red per the same rule.
- **CSAT crosswalk rows** (`cbn.rcf.csat`): always render **amber**, "Pre-fill available once you
  upload this year's CSAT workbook" with a `Link` to the CSAT screen (`evidence-pack-export.md`
  §CSAT) — never green, because per ADR 0021 §4 the workbook itself is not held in this system and
  a green cell here would assert completion of a submission this product cannot see.
- **AI panel: unavailable.** Same muted note pattern as `Bia/Workspace.jsx`: "Gap analysis is
  unavailable: {reason}." beneath the disclosure, button hidden rather than present-and-broken.
- **AI panel: in flight.** "Analysing…", disabled button, rest of the matrix remains usable —
  matching the non-blocking pattern already established for AI requests in this module.
- **Empty programme** (no `bcms_programmes` row for the year at all). The whole matrix is
  replaced by a single message pointing at `bcms.programme.index` to create one — every clause
  needs a programme to be scoped against, and eighty red cells over nothing is not a finding, it is
  a screen shown too early.
- **Permission-denied.** Standard 403; the AI panel and export button never render for a viewer
  without the respective permission rather than rendering disabled.
- **Server/network error.** Standard handling; the matrix does not partially render — either the
  full computed set arrives or the page shows the standard error state, because a matrix showing
  six of nineteen ISO rows with no indication three failed to compute is a false "compliant" report
  by omission.
- **Degraded network.** See §6.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Open a clause's evidence drawer | Client-side only; drawer content comes from the same page load's data (no extra round trip) | None |
| Toggle a standard section open/closed | Client-side, reflected in the URL query | None |
| Ask the gap analyser | `POST bcms.reports.gap-analysis.ai` | None to request; results are drafts, never auto-applied |
| Raise a finding from an AI suggestion | `POST bcms.findings.store` (existing route, `source=gap_analysis`) | None — matches `Findings/Index.jsx` |
| Dismiss an AI suggestion | Client-side only | None |
| Export the evidence pack | Navigates to the picker in `evidence-pack-export.md` | N/A — no destructive action on this screen |
| Follow a link to Programme governance / the KRI module / a specific AAR/BIA/finding | Standard `Link` | None |

Nothing on this screen writes to a clause, an obligation, or an artefact directly — it is a read
surface over data every other screen produces, by design (phase-11-spec's "you aggregate and
display; the producing phase registers").

## 5. Accessibility (WCAG 2.1 AA)

- **Every state cell carries icon + text**, never colour alone (`✓`/`✗`/`—` plus the word), so a
  screen reader and a colour-blind reader receive the same information a sighted reader gets from
  the tint.
- **The matrix is a real `<table>`** with `<caption>` per standard section naming the standard, `
  <th scope="col">` for the six columns, so a screen-reader user can navigate by column as well as
  by row — a clause-heavy table read linearly is unusable.
- **The drawer is a proper dialog** (`role="dialog"`, `aria-modal="true"` if it overlays, or a true
  `aria-live="polite"` region if it is a non-modal side panel — implementer's choice per the
  product's existing drawer primitive, but whichever is chosen, focus moves into it on open and
  returns to the triggering row on close).
- **Section disclosures use `<button aria-expanded>`**, not a bare clickable `<div>`, so the
  collapsed/expanded state is announced.
- **The AI panel's result list announces via `aria-live="polite"`** once, on arrival — not per
  row, avoiding an examiner's screen reader reading seventeen "new suggestion" announcements in a
  row.
- **Keyboard path:** header (export button) → summary tiles (not focusable, decorative) → each
  standard section's disclosure button → within an expanded section, each row's clickable cell in
  document order → KRI table (links to the KRI module) → maturity link → AI disclosure → AI
  request button → (if results present) each result's Raise/Dismiss pair.
- **Contrast:** the four cell tones reuse `emerald-50/900`, `amber-50/900` (from
  `Programme/Index.jsx`'s own gap banners), `red-50/900`, and `gray-100/700` (lighter than the
  module's other greys, chosen because "not applicable" must not read with the same visual weight
  as an active red gap) — all four already audited pairings in this module, no new shade
  introduced.

## 6. Low bandwidth (100 kbps)

- **One page load carries the whole matrix.** Nineteen ISO rows, the ISO 22318 row, the CBN/DORA
  crosswalk and the KRI table are all text — no image, no chart library, no SVG beyond the
  product's existing tick/cross glyphs. The payload is comparable to `Findings/Index.jsx`'s
  register, which the product already ships at this scale.
- **The drawer's content is included in the initial payload**, not lazy-fetched per click — a
  drawer that fetches on open is a spinner on every row an examiner clicks through, and this
  screen's whole purpose is "answer the question in one click," which a per-drawer round trip
  works against.
- **The AI request is the one genuinely slow action** and is explicit and deferred: nothing calls
  it on page load, the button states "Analysing…" while in flight (matching `aar-builder.md`'s
  AI-draft pattern), and the rest of the matrix remains interactive meanwhile.
- **Export is a deliberate navigation**, never triggered from this page directly (§4) — the picker
  screen carries its own low-bandwidth handling for the actual pack generation.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Green/amber/red/grey counts, mandatory-gap count | Computed server-side from `bcms_clause_refs` joined to each row's sufficiency query (phase-11-spec §3.2, as amended by ADR 0021 §1 for the two 9.2 rows) and `bcms_programme_obligations` for applicability — never re-derived client-side, for the same reason `aar-builder.md`'s live checklist is server-computed: a client re-implementation of nineteen sufficiency rules is exactly where it drifts from the pack generator that must agree with it byte-for-byte |
| Each clause's state, artefact text, last-evidenced date | Per the home/sufficiency-rule columns in phase-11-spec §3.2, read live from the tables named there — `bcms_training_records`, `bcms_bia_assessments`, `bcms_aars`, `bcms_management_reviews.inputs` (the `internal_audit` block, ADR 0021 §1), `bcms_findings`, `bcms_corrective_actions`, `key_risk_indicators`/its measurement history, etc. |
| Applicability and its rationale | `bcms_programme_obligations.applies` / `.applicability_note` — ignored for the two 9.2 rows (ADR 0021 §1) |
| Maturity score, assessed date, method version | `bcms_maturity_assessments` — read only, `MaturityService` is the sole scorer |
| Each KRI's target, current value, RAG band, last measured | The `KeyRiskIndicator` row `adopt()`-ed for that `kri_code` (ADR 0021 §2) plus its measurement history via `KriMeasureBridge` — this phase writes measurements for exactly one KRI, `BCMS-VENDOR-ATTEST`; every other measurement's producing phase is named in phase-11-spec §2.5 and shown here as read-only. A code with no matching row renders "Not linked", never a fabricated zero |
| AI gap-analyser findings | The model's own output, always `ai_generated = true`, never counted into the clause states above until a human raises and the underlying register changes |

## 8. Deliberately out of scope

- **A per-branch maturity heatmap.** The schema (`bcms_maturity_assessments.organization_id`, no
  business-unit column) scores one organisation at a time; a "clause group × org node" grid is not
  buildable honestly today. This screen shows the one score that exists and links to `Programme/
  Index.jsx` for its clause-group breakdown, and names the gap rather than fabricating branch rows.
  Flagged for the architect alongside the phase-11-spec's own open items.
- **A second KRI dashboard.** The seventeen-row table here is the 9.1 evidence line for this
  matrix; a trend chart, threshold-breach alerting or a branch-scoped KRI view belongs to the
  existing KRI module and the existing Dashboards builder (phase-11-spec §5's own instruction —
  "BCMS widgets publish through the existing Dashboards builder"). This screen does not duplicate
  that surface.
- **An internal-audit programme table or screen.** ADR 0021 §1 declines to build
  `bcms_audit_programmes`/`bcms_audit_results` for BCMS specifically; if a design partner needs the
  programme represented inside the product, that is a platform-level `audit_programmes` ADR raised
  by whoever needs it, not something this screen anticipates or half-builds via a red cell that
  secretly expects a table.
- **Editing an obligation's applicability from this screen.** Applicability is set in Programme
  governance (`Programme/Index.jsx`'s obligations panel); this screen reads it. Moving that control
  here would let a reader turn a red gap grey from the matrix itself, which is precisely the
  incentive criterion 3 exists to prevent.
- **A CBN/DORA taxonomy of its own.** Per phase-11-spec §3.4, those rows are a crosswalk view over
  existing `iso22301.*`/`iso22318.*` refs; no `dora.*` clause ref exists and this screen does not
  invent display-only codes that look like ones.
- **Printing/PDF rendering of the matrix itself.** The matrix is a live screen; the artefact an
  examiner keeps is the exported pack (`evidence-pack-export.md`), assembled from stored rows, not
  a screenshot or print of this page.
