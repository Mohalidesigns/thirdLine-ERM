# Screen spec — `Bcms/Reports/EvidencePack` (the ISO 22301 / CBN regulator evidence pack, and CSAT pre-fill)

**Route:** `GET bcms/reports/regulatory-evidence` → `bcms.reports.regulatory-evidence`,
`permission:bcms.report.export` — the picker and the export log described below. Generation is
`POST bcms/reports/regulatory-evidence` (same permission) with `framework` (`iso22301` |
`cbn_csf` | `cbn_open_banking` | `dora`) and a period (`from`/`to`, or `year` for the board-pack-
style annual case). Collection is a signed download:
`GET bcms/reports/regulatory-evidence/{pack}/download` (`signed` middleware, `permission:
bcms.report.export`, plus ownership/tenant check in the controller — the RCSA export pattern
exactly, §3). CSAT pre-fill is a distinct, smaller flow on the same page:
`POST bcms/reports/csat-prefill` (`bcms.report.export`, multipart upload + streamed workbook
response).

**Reads:** `docs/bcms/phase-11-spec.md` §3.1 (the rule that makes a pack evidence: built from
stored rows, recomputes nothing, failures exported as prominently as successes, generation
recorded as an audit event), §3.2–§3.4 (the ISO 22301 pack's nineteen sections in clause order
with an index page, the `cbn_csf`/`cbn_open_banking` packs' own examiner-ordered sections, the
DORA crosswalk), §3.5 and **ADR 0021 §4** (CSAT: label-mapped onto a customer-uploaded workbook,
unmatched questions on a cover sheet, no invented cell coordinates — reuse RCSA's
`RcsaTemplateWriter`/`RcsaWorkbookWriter`/`RcsaImportProcessor`, `openpyxl` does not exist in this
repository), §6 criteria 1, 2, 10, 12, 13 (one-click, reproducible/byte-identical on a re-run over
the same period, PDF **and** PPTX with Atheris branding, a silently-omitted mandatory record is the
failure mode this screen exists to prevent, every export writes a `pack.exported` audit entry).
**ADR 0021 §4 — binding: there is no `bcms_report_runs` table.** The "pack" a user downloads is a
generated file plus an audit-log row, not a first-class database record with its own lifecycle —
this changes what the "export log" on this screen can honestly show (§2, §7).

**Siblings:** `resources/js/Pages/RcsaExports/Index.jsx` and its controller
(`App\Http\Controllers\Rcsa\ExportController`) — **this is the direct model for the
generate/collect flow**: a live row-count/section-count preview before committing, a plain
threshold rule ("small runs synchronously and streams back; large ones queue and you get a signed
link"), a native-form POST for the synchronous path (Inertia cannot consume a binary response), and
a log of what has been generated, by whom, with a signed, expiring, single-tenant-checked download
link. `resources/js/Pages/Tprm/Reports/BoardPacks.jsx`/`BoardPack.jsx` (the "a pack is a snapshot
and the screen says so" framing, and the branded-PDF download button pattern); `resources/js/Pages/
Bcms/Bia/Report.jsx` (gaps rendered as a named list, never folded into a rate — this screen's
sufficiency-by-mandatory-record list follows the same rule).

---

## 1. Purpose and the user

One person, one moment, one very specific piece of pressure: a **compliance officer or programme
owner** (`bcms.report.export`) is on a call with a CBN examiner, or preparing for a board pack
deadline, or responding to an ISO 22301 surveillance audit's document request — and needs a
complete, dated, defensible bundle **now**, not a report they have to assemble from six screens.
The examiner test in phase-11-spec §6 criterion 2 is the design brief in one sentence: from a cold
start, "show me your last DR test report and the corrective actions arising" is answered in under
sixty seconds and three clicks, with a downloadable pack. Every choice on this screen is in service
of that: the framework and period are the only decisions the user makes, and everything else is
computed.

The second reader is **whoever receives the pack** — an examiner, an external auditor, a board
member — who was not in the room when it was generated and needs the pack itself to say what
period it covers, when it was produced, and whether every mandatory record it should contain is
actually inside it.

## 2. Layout

`AppLayout`, `PageHeader` title `Regulatory evidence packs`, subtitle `One-click, period-scoped
bundles — ISO 22301, CBN and DORA. Every export is logged.`

**Generation form**, above the fold, matching `RcsaExports/Index.jsx`'s layout exactly:

- **Framework** select: ISO 22301 (full clause bundle) / CBN Cyber Resilience Framework / CBN Open
  Banking / DORA (benchmark crosswalk). Selecting one updates the live preview beneath it (debounced
  `GET`, same pattern as the RCSA preview endpoint) showing: **section count**, **how many of the
  eleven ISO 22301 mandatory records fall inside this framework and how many of those are currently
  green/amber/red** (read from the same computation `compliance-evidence-matrix.md` renders — this
  screen does not recompute sufficiency independently, it calls the same service), and — for
  `cbn_open_banking` specifically — whether the institution's obligation register marks Open
  Banking clauses as not applicable, in which case the preview says so plainly ("This institution
  has no Open Banking licence — the pack will state this and generate the CBN CSF/DORA sections
  only, if selected separately") rather than silently producing an empty Open Banking pack.
- **Period**: `from`/`to` date pickers, defaulting to the current calendar year — every pack is
  period-scoped per phase-11-spec §3, never "everything ever recorded."
- **Generate** button, disabled while the preview is computing, label reflecting the size decision
  exactly as `RcsaExports/Index.jsx` states it: *"Generates immediately"* under the sync threshold,
  or *"Over {n} sections — this runs in the background and you'll get a link"* above it. The
  **evidence-pack equivalent of the RCSA sync/queue threshold is section/artefact count**, not row
  count — a full-year ISO 22301 pack across nineteen sections with a year of occurrences, findings
  and reviews behind it is exactly the shape of export that "inherited a 20-second timeout no
  extraction has finished inside" (this repository's own recent defect) would break on if forced
  synchronous, so this screen defaults to the **queued** path for `iso22301` and `cbn_csf` and
  reserves the synchronous path for the smaller, single-quarter `cbn_open_banking` pack — stated to
  the user, not hidden.
- **Queued generation** uses `useJobProgress` against the dispatched job's id (the same primitive
  named in the product's shell inventory), rendered as a determinate or indeterminate progress
  note ("Assembling section 7 of 19: exercise programme…") beneath the button rather than a bare
  spinner, so a long-running pack does not read as a hung page.

**Export log**, beneath the form, table: Framework · Period · Requested by · Requested at ·
Status · Sections (n green / n amber / n red of 19) · Download. **Because ADR 0021 §4 confirms
there is no `bcms_report_runs` table, this log is not a first-class register with its own retention
policy — it is a rendering of `bcms_audit_logs` rows where `event = pack.exported`, filtered to
this tenant, `after` decoded for the framework/period/section-count summary.** The screen says
this plainly in a caption beneath the table: "This log is built from the audit trail, not a
separate report register — every export leaves this trace whether or not the file itself is still
downloadable." A generated file has a **retention window** (matching the RCSA export's expiry
model) after which the row still shows *that* a pack was produced, with no live download — a "Link
expired — the audit entry still proves this export happened" state, not a vanished row, because
"is this the pack you gave the CBN in March" (§3.1) must be answerable from the log even after the
file itself has rolled off storage.

**CSAT pre-fill**, a separate, clearly delineated card below the log (not merged into the
framework picker — it is a different shape of artefact, a workbook the customer already owns
rather than one this product assembles from nothing): an upload control ("Upload this year's CBN
CSAT workbook"), a **"Fill and return"** button, and — once returned — a summary: "{n} questions
matched and filled by label, {m} left untouched. See the cover sheet for the {m} unmatched
questions." with the download. **No cell-coordinate input exists anywhere on this card** — the
mapping is entirely by question label, per ADR 0021 §4, and the screen never asks the user to
confirm or correct a coordinate, because the product does not claim to know one.

## 3. Every state

- **Loading (preview).** "Checking sections…" beside the framework/period selection, matching
  `RcsaExports/Index.jsx`'s "Counting…" pattern exactly.
- **Preview: framework has zero applicable sections** (every clause in it is marked not-applicable
  in the obligation register — e.g. Open Banking for a bank with no such licence). Generate button
  stays enabled — a pack that says "not applicable, here is why" for every section is still a valid,
  useful artefact, per phase-11-spec's own grey-cell rule — but the preview text says so plainly:
  "Every clause in this framework is marked not applicable. The pack will say so, not appear empty."
- **Generating (synchronous path).** Button reads "Generating…", disabled, matching the module's
  established non-blocking-elsewhere pattern; the rest of the page (log, CSAT card) remains usable.
- **Generating (queued path).** `useJobProgress` note with a named current section where the
  backend can report one; a "Cancel" is **not** offered (matching this module's general absence of
  mid-flight cancellation for evidence-producing jobs — an abandoned pack should still be traceable
  in the log as attempted, not vanish).
- **Generated, ready to download.** Row appears/updates in the log immediately (Inertia flash or a
  poll-driven refresh for the queued path), Download link active.
- **Generated with gaps** (the common and expected case — nine of eleven mandatory records green,
  two red per ADR 0021 §1). The pack itself states the gaps per §3.1's "failures exported as
  prominently as successes"; **this screen does not hide or soften that in the log row** — the
  section-count summary column shows the red count in the same `red-50` text used elsewhere, so a
  user does not have to open the pack to learn it has two known gaps.
- **Generation failed** (a section's query errored, a rendering step failed). Log row shows
  "Failed — {reason}", **no partial file is offered for download** — a pack six sections short of
  nineteen with no indication three failed is the exact false-completeness this phase exists to
  prevent (phase-11-spec §6 criterion 12), so a failure is all-or-nothing from the reader's side.
- **Download link expired.** "Link expired — the audit entry still proves this export happened. Generate a new pack for the same period if you need the file again — it will be reproducible, per §6 criterion 2." with a one-click "Regenerate for this period" action pre-filling the form.
- **CSAT: no workbook uploaded yet.** "Fill and return" disabled with the upload prompt.
- **CSAT: workbook uploaded, processing.** "Matching questions…", non-blocking.
- **CSAT: returned, with unmatched questions.** The summary state above — **always shown**, even
  when `m = 0`, because "0 unmatched" is itself the fact an examiner-facing artefact should state
  rather than omit.
- **CSAT: upload rejected** (wrong file type, corrupt workbook, virus-scan gap — per the product's
  three deliberate go-live gaps, **no virus scanning exists**, so the upload card carries a small,
  permanent note: "Uploaded files are not scanned for malware in this build — a deliberate,
  documented gap. Only upload a workbook from a source you trust." rather than a false assurance of
  safety).
- **Permission-denied.** Standard 403; the whole screen (form, log, CSAT card) requires
  `bcms.report.export` — there is no partial "view the log without generating" tier specified here,
  matching the single permission the phase-11-spec's own routing table assigns to every
  `reports/*` route.
- **Server/network error.** Standard handling; an in-flight queued job's status is recoverable on
  reload via the log, not lost.
- **Degraded network.** See §6.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Change framework/period (updates preview) | `GET` preview endpoint, debounced | None |
| Generate (synchronous) | Native form `POST bcms.reports.regulatory-evidence` (not an Inertia visit — the response is a binary file, matching `RcsaExports/Index.jsx`'s own documented reason) | None — generation is additive and reproducible, not destructive |
| Generate (queued) | `POST bcms.reports.regulatory-evidence` (Inertia visit, dispatches a job, returns immediately) | None |
| Download a ready pack | `GET bcms.reports.regulatory-evidence.download` (signed, expiring) | None |
| Regenerate for an expired period | Pre-fills and re-submits the form | None |
| Upload a CSAT workbook | `POST bcms.reports.csat-prefill` (multipart) | None |
| Download the filled CSAT workbook | Streamed response from the same endpoint's success, or a follow-up signed download if the backend chooses to persist it briefly | None |

Nothing on this screen is destructive. There is no "delete this pack" action — the log is an audit
trail, and audit trails are not edited from the screen that reads them.

## 5. Accessibility (WCAG 2.1 AA)

- **The preview's section counts and gap counts are real text**, never a bare number with a colour
  swatch — "9 green, 2 red (of 11 mandatory records)" is what a screen reader announces.
- **The queued-generation progress note updates via `aria-live="polite"`**, at a throttled rate (not
  on every poll tick if the backend reports sub-section granularity) so it does not flood a screen
  reader with rapid-fire announcements — matching the general alert-fatigue discipline this module
  applies everywhere else.
- **The log table has `<caption>` "Evidence pack export log"** and `<th scope="col">` per column,
  same convention as every other register table in this module.
- **The no-virus-scanning notice is static text beside the upload control**, not a tooltip or a
  modal that must be dismissed — a screen reader encountering the upload field reads the caveat as
  part of it.
- **Keyboard path:** framework select → period fields → Generate button → (once present) log table
  in document order, each row's Download link reachable → CSAT upload input → Fill-and-return
  button → (once present) CSAT result summary and its download link.
- **Contrast:** reuses `emerald-50/900` (green sections), `amber-50/900`, `red-50/900` exactly as
  `compliance-evidence-matrix.md` does — the two screens must agree visually on what "two red
  mandatory records" looks like, since they are describing the same computed fact.

## 6. Low bandwidth (100 kbps)

- **The preview call is small** (counts and labels only, no artefact content) and debounced, so
  changing the framework/period selection repeatedly on a slow link does not queue a pile of
  requests.
- **Generation itself is the deliberate exception to "nothing loads silently."** A multi-section
  PDF/PPTX bundle is exactly the payload class this product's low-bandwidth rule exists to gate —
  which is why it is **never triggered on page load**, always an explicit click, and why the
  queued path exists at all: a user on a poor connection who triggers a large pack is told plainly
  it will arrive as a link rather than watching a browser tab appear to hang for two minutes.
- **`useJobProgress` polls at its default 2-second interval**, tolerant of transient failures
  (backs off to 3× the interval rather than erroring the whole screen on one dropped poll) — its
  own documented behaviour, reused unchanged.
- **The CSAT upload is bounded** (one workbook, customer-supplied, not a batch) and the returned
  file is a download the user explicitly requests, never auto-fetched.
- **The download itself is a signed link the user clicks when ready** — nothing pre-fetches the
  actual pack bytes before that click.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Section count, green/amber/red split in the preview and the log | The same sufficiency computation `compliance-evidence-matrix.md` renders, called as a service — not recomputed independently, so the two screens cannot disagree about whether a given pack would show two red mandatory records or three |
| "Not applicable" framework detection | `bcms_programme_obligations.applies` for the clauses in that framework's `export_packs` grouping |
| Export log rows | `bcms_audit_logs` where `event = pack.exported`, decoding `actor`, `after.framework`, `after.period`, `after.section_summary` — **there is no `bcms_report_runs` row behind any of this** (ADR 0021 §4) |
| Download availability / expiry | The generated file's own storage location and a time-to-live analogous to the RCSA export's `expires_at`, computed at generation time, not stored on a dedicated report-run table |
| CSAT match/unmatched counts | Computed at fill-time by the workbook writer, from the shipped `clause ref → CSAT question label` mapping content pack against the customer's uploaded sheet — never a stored expectation of what the workbook should contain |
| Pack reproducibility (criterion 2) | Guaranteed by §3.1's rule that every section reads stored, terminal rows only (approved BIAs, finalised AARs, stored maturity scores) — this screen shows no number of its own here, it is a property of the generator the qa-engineer tests by regenerating and diffing |

## 8. Deliberately out of scope

- **A first-class report-run register with its own retention/lifecycle screen.** ADR 0021 §4 is
  explicit: no `bcms_report_runs` table. This screen's "log" is a view over the audit trail and is
  named as such on the page, not presented as a report-management system in its own right.
- **Editing a generated pack's content.** A pack is a snapshot the moment it is produced; there is
  no in-place edit, matching the TPRM board pack's "prepare a new period rather than recomputing
  this one" rule and this phase's own reproducibility requirement.
- **Inventing CSAT cell coordinates or a coordinate-correction UI.** ADR 0021 §4 forecloses this
  entirely — the mapping is by label, period, and the unmatched-questions cover sheet is the
  product's honest limit, not a control surface for the user to patch around it.
- **Virus scanning the CSAT upload.** A named, deliberate go-live gap (one of three in TPRM/BCMS);
  this screen states it rather than working around it.
- **The board pack itself.** `reports/board-pack?year=&format=` and its preview screen are a
  sibling deliverable (Screens list item 3, "Board pack preview") not covered by this spec —
  see the phase HANDOFF for what remains owed.
