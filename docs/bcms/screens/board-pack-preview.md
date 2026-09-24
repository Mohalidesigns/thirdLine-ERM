# Screen spec — `Bcms/Reports/BoardPack` (the resilience board pack, sectioned preview and export)

**Route:** `GET bcms/reports/board-pack?year=` → `bcms.reports.board-pack`,
`permission:bcms.report.view` for the preview; generating/downloading either format requires
`permission:bcms.report.export` — same split as `evidence-pack-export.md`, and for the same
reason: a reader is entitled to see the pack's shape and whether this year is currently green or
not before anybody commits to producing the branded document. `GET
bcms/reports/board-pack/{pack}/download?format=pdf|pptx` (`signed`, `bcms.report.export`,
tenant/ownership check).

**Reads:** `docs/bcms/phase-11-spec.md`'s scope section, "Board pack" (one-click, period-scoped:
resilience posture summary, maturity trend, exercise programme completion, plan currency, top RTO
gaps, open nonconformities, incident summary, KRI dashboard, management-review inputs — branded
PDF **and** PowerPoint export), §3.1 (the rule that makes any pack evidence: built from stored
rows, recomputes nothing, failures exported as prominently as successes, generation recorded as an
audit event — this pack is built the same way the regulatory evidence pack is, not differently),
§6 criterion 10 (PDF and PPTX with Atheris branding intact); **ADR 0021 §4 — binding: there is no
`bcms_report_runs` table, and the same holds for a `bcms_board_packs` table — grep confirms only
`tp_board_packs` (TPRM) exists.** This screen therefore does **not** work the way TPRM's board pack
does (see Siblings) — there is no draft/in-review/signed-off lifecycle and no persisted, editable
narrative for BCMS. This pack is generated on demand from live, terminal data and logged as a
`pack.exported` audit event, exactly like `evidence-pack-export.md`'s regulatory packs.

**Siblings — read for contrast, not for imitation:** `resources/js/Pages/Tprm/Reports/
BoardPacks.jsx`/`BoardPack.jsx` — TPRM's board pack has its own table, a prepare/review/sign-off
state machine and an editable, attributed narrative the CRO amends before sign-off. **BCMS has
none of that schema**, so this screen borrows only the pieces that do not depend on it: the
Tile/Table layout language, the "figures render from the snapshot, nothing recomputes" discipline,
and the branded-PDF download button. It does **not** borrow the draft/sign-off workflow or the
narrative editor — see §8. `docs/bcms/screens/evidence-pack-export.md` (the actual generation
mechanics — sync/queue threshold, signed download, the audit-log-only export log — this screen's
Export action reuses that machinery rather than inventing a second one). `resources/js/Pages/Bcms/
Strategy/Gap.jsx` (the source and exact shape of the "top RTO gaps" section — ranked by shortfall,
a process with no strategy shown with a reason, not omitted). `docs/bcms/screens/
compliance-evidence-matrix.md` (the KRI table this pack's KRI section reuses, read-only, same
adopted-KRI data).

---

## 1. Purpose and the user

A **programme owner or compliance officer** (`bcms.report.view`) opens this screen before a board
or risk-committee meeting to see, in one place, everything the board needs to know about
resilience posture for the year — and to decide whether it is ready to hand over. A **board
member or committee chair**, later, reads the exported document itself, not this screen; this
screen's job is to let the preparer catch a problem (a section with nothing in it, a KRI in
breach, a maturity score that fell) before it reaches a boardroom. In the first ten seconds: is
this year's posture improving or not, is anything in the pack currently a named gap rather than a
number, and is the pack ready to export.

Because there is no persisted BCMS board-pack record, **this screen has no "draft I come back to
tomorrow" state** — every visit computes the current preview fresh from stored, terminal rows
(approved BIAs, finalised AARs, stored maturity assessments, closed incidents), and reruns of the
same year produce the same preview content, which is what makes the eventual export reproducible
(phase-11-spec's own reproducibility expectation, carried over from `evidence-pack-export.md`).

## 2. Layout

`AppLayout`, `PageHeader` title `Board pack`, subtitle `{year} · assembled from stored, approved
records — nothing on this page is recomputed by opening it.` Header controls: a **Year** select
(defaulting to the current year, populated from years with any BCMS activity) and the **Export**
action (`bcms.report.export`), which opens the same generate/download flow as
`evidence-pack-export.md` (§4), parameterised `framework` is not applicable here — it is simply
`GET bcms/reports/board-pack?year=&format=pdf` or `...&format=pptx`, and the button offers **both**
formats explicitly (two buttons, "Export as PDF" / "Export as PowerPoint"), not a single button
with a format dropdown buried inside it, because criterion 10 treats the two as equally first-class.

**Sections, in the blueprint's own order, each a card matching `Programme/Index.jsx`'s card
styling:**

1. **Resilience posture summary.** A short, **templated, non-editable** paragraph assembled from
   the figures below it (e.g. "Maturity is {score}/5, {up/down/unchanged} from the last
   assessment. {n} of {m} exercises this year completed on schedule. {n} nonconformities remain
   open."). **There is no free-text narrative box on this screen** — unlike TPRM's board pack,
   there is no table to hold an edited narrative in, and inventing one here would be exactly the
   "while we're here" schema creep ADR 0021 refuses elsewhere. If a prepared, human-authored
   narrative is wanted, it is written into the exported document by whoever assembles the board
   paper around this pack — outside this screen (§8).
2. **Maturity trend.** A small table/sparkline-as-text of `bcms_maturity_assessments.overall_score`
   over the year's assessments (dates on the x-axis, rendered as a text list "Mar: 2.4, Jun: 2.8,
   Sep: 3.1" — no chart library per this product's inline-SVG-only convention, and a maturity trend
   with at most a handful of points a year does not earn a chart). **Reads `MaturityService`'s
   stored rows only — no second scorer**, matching `compliance-evidence-matrix.md`'s own rule
   exactly.
3. **Exercise programme completion.** From the year's `bcms_exercise_programmes` (`total_planned`
   as approved) against completed occurrences — a fraction with its denominator stated, never a
   bare percentage (development standard §5).
4. **Plan currency.** The same `plans_current_rate` computation `BcmsHomePresenter` already makes
   — null, not zero, over an empty plan register — reused, not recomputed differently for this pack.
5. **Top RTO gaps.** The top five rows of the strategy gap analysis (`Strategy/Gap.jsx`'s own data,
   `bcms.strategy.gap`'s service), ranked by shortfall — a process with **no strategy at all**
   appears here too, with its reason shown instead of a number, exactly as that screen already does
   it; this pack does not re-rank or filter differently.
6. **Open nonconformities.** `bcms_findings` where `classification = nonconformity` and
   `status != closed`, listed by age, each with its `iso_clause_ref` and linked corrective actions'
   status — reusing `Findings/Index.jsx`'s row rendering, not a new one.
7. **Incident summary.** Count, severity mix and notification timeliness for the year's
   `bcms_incidents`, per phase-11-spec's own board-pack line — read from Phase 10's incident
   register.
8. **KRI dashboard.** The same seventeen-row adopted-KRI table `compliance-evidence-matrix.md`
   renders (code, name, target, current value with RAG band, last measured), reused as a component
   rather than re-queried with different logic — a board pack and the compliance matrix must never
   show two different numbers for the same KRI.
9. **Management-review inputs.** A summary of the **latest approved** review for the year — held
   date, approver, and a condensed read of its snapshot (linking into
   `management-review-inputs.md`'s full record for that review) — never a live re-capture; if no
   review has been approved in the selected year, this section says so (§3).

**Index note**, a single line beneath the section list, matching the ISO 22301 pack's own
"clause → artefact → page" index convention: "This pack maps directly to the sections above; the
exported document carries the same order and an index page."

## 3. Every state

- **Loading.** Skeleton cards per section.
- **No BCMS activity for the selected year at all** (a tenant just enabled the module, or picked a
  future year). The whole page is replaced by a single message: "No BCMS records exist for {year}.
  Select a year with activity, or this is simply too early to produce a board pack." rather than
  nine empty cards.
- **Section 1 (posture summary) with partial data.** The templated sentence omits any clause it has
  no figure for rather than inserting a placeholder — e.g. if no maturity assessment exists yet,
  the maturity clause of the sentence is dropped entirely, not rendered as "Maturity is —/5."
- **Section 2 (maturity trend), no assessments this year.** "No maturity assessment has been run in
  {year}." with a `Link` to `Programme/Index.jsx`'s re-assess action — not a flat line at zero.
- **Section 3/4 (exercise completion / plan currency), no denominator.** Null-rendered exactly as
  `BcmsHomePresenter` already does it — "No plans on record, so currency is undefined" — never 0%
  or 100%.
- **Section 5 (top RTO gaps), no gaps.** "No process currently has a strategy shortfall against its
  required RTO." — stated as the finding it is, not a blank table, matching `Strategy/Gap.jsx`'s
  own honest-zero framing.
- **Section 6 (nonconformities), none open.** "No nonconformity is currently open." — again framed
  as a fact, not a green tick with no context, because a board reading a pack needs to know whether
  "none" means "resolved" or "never exercised enough to find one."
- **Section 7 (incidents), none this year.** "No incident was declared in {year}." — plain
  statement, not a celebratory tone; an incident-free year and an untested one look identical on a
  pure count, and the pack does not claim more than the count supports.
- **Section 9 (management review), none approved this year.** "No management review has been
  approved for {year}. Clause 9.3 requires an annual review; none is on record yet." — the honest,
  named gap, exactly the tone `compliance-evidence-matrix.md`'s red cells use, because this is the
  same underlying fact viewed from the board pack rather than the matrix.
- **Generating (PDF or PPTX).** Same states as `evidence-pack-export.md` §3: synchronous for a
  small/short-period pack, queued with `useJobProgress` for a full-year pack across a large
  estate — the same threshold logic, not a second implementation of it.
- **Generated, ready to download.** Same signed-link pattern.
- **Generation failed.** Same all-or-nothing rule: no partial pack offered.
- **Permission-denied.** Standard 403; Export controls absent for a `bcms.report.view`-only holder,
  who still sees the full preview (this screen's read/export split is deliberate, unlike a register
  that hides content behind an export permission).
- **Server/network error.** Standard handling.
- **Degraded network.** See §6.

## 4. Interactions

| Action | Route | Confirmation |
|---|---|---|
| Change year | `GET bcms.reports.board-pack?year=` | None |
| Export as PDF | Same generate/download flow as `evidence-pack-export.md` §4, `format=pdf` | None — reproducible and non-destructive |
| Export as PowerPoint | Same flow, `format=pptx` | None |
| Follow a link into a section's source screen (Strategy gap, Findings, Programme governance, a specific management review) | Standard `Link`, server-built URL prop | None |

Nothing on this screen writes anything; it is a pure read, plus the export action which is
additive (a new generated file each time, never an edit to a prior one — there is nothing to edit,
per §1).

## 5. Accessibility (WCAG 2.1 AA)

- **Every section is a real `<h2>`**, in the blueprint's own fixed order, navigable by heading
  exactly as `aar-builder.md`'s nine sections are.
- **The maturity trend's "sparkline" is a text list, not an image or an unlabelled SVG** — per this
  screen's own choice not to introduce a chart for a handful of points, there is no accessibility
  gap to bridge here at all.
- **Null/honest-gap states are static visible text**, read by a screen reader as ordinary body
  content, never conveyed by an icon alone.
- **Keyboard path:** header (year select, then the two export buttons) → sections 1–9 in order,
  each section's internal links (to Strategy gap, Findings, Programme governance, a review)
  reachable in document order.
- **Contrast:** reuses the module's existing tones exactly — no new palette introduced for this
  screen, consistent with the product-wide Navy/Forest/Gold theme.

## 6. Low bandwidth (100 kbps)

- **The preview is entirely text and small tables** — nine sections, comparable in weight to
  `Programme/Index.jsx`'s own page, no chart, no image beyond the product's branding assets used
  only inside the exported document itself, never on this screen.
- **Changing the year is a full page reload** (a `GET` with a query param) rather than a
  client-side recomputation of nine sections' worth of data — acceptable because a board pack is
  opened rarely relative to how often any one visit is read, matching `aar-builder.md`'s reasoning
  for its own single-load approach.
- **Export is the deliberate exception**, exactly as in `evidence-pack-export.md` — a
  multi-section, branded PDF/PPTX is a real payload, generated only on explicit request, queued
  above a size threshold, never triggered by opening this screen.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Maturity score and trend | `bcms_maturity_assessments`/`_scores` — `MaturityService`, read only |
| Exercise programme completion | `bcms_exercise_programmes.total_planned` (approved) vs completed `bcms_exercise_occurrences` for the year |
| Plan currency | Same computation as `BcmsHomePresenter.plans_current_rate` |
| Top RTO gaps | The strategy gap-analysis service behind `Strategy/Gap.jsx` — ranked by `shortfall_hours`, unprotected processes included with a reason |
| Open nonconformities | `bcms_findings` where `classification = nonconformity`, `status != closed` |
| Incident summary | `bcms_incidents` for the year, plus their notification records |
| KRI dashboard | The same adopted `KeyRiskIndicator` rows and measurement history `compliance-evidence-matrix.md` reads |
| Management-review inputs summary | The latest **approved** `bcms_management_reviews` row for the year, its stored `inputs` snapshot — never re-captured for this pack |
| Posture-summary sentence | Templated, computed entirely from the figures above — no field on this screen is authored free text |

## 8. Deliberately out of scope

- **A persisted `bcms_board_packs` record, or any draft/in-review/signed-off lifecycle for it.**
  Confirmed absent by ADR 0021 §4 and by grep (only `tp_board_packs` exists). This screen is a
  live, reproducible preview over stored data plus an export action logged as a `pack.exported`
  audit event — not a stateful object a committee "signs off" inside this product. If a bank needs
  a persisted, attributed sign-off record for the BCMS board pack specifically, that is a new ADR
  (a BCMS `board_packs` table, weighed the same way ADR 0021 weighed the audit-programme request),
  not something this spec pre-empts.
- **An editable, human-authored narrative.** TPRM's board pack has one because it has a table to
  hold it in; BCMS's does not, and inventing a narrative field with nowhere durable to store an
  edit would produce a "save" button that silently discards on the next preview. The
  posture-summary sentence is templated and read-only for this reason, not as a lesser feature.
  Prepared remarks a CRO wants to add are written into the board paper that wraps this export.
- **A second KRI computation, a second maturity scorer, or a second findings register.** Every
  section reuses an existing screen's own source, per §7 — this screen assembles, it does not
  recompute.
- **Comparing this year's pack to a prior year's inside the screen.** Each visit shows one selected
  year; a cross-year trend beyond the maturity sparkline is out of scope, matching the absence of a
  persisted pack to diff against.
