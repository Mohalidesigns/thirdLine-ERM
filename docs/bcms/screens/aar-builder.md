# Screen spec — `Bcms/Exercises/Aar` (the After-Action Report builder)

**Route:** `GET aars/{aar}` → `bcms.aars.show`, `middleware('permission:bcms.exercise.view')`
(read access is broader than edit — anyone who can see the exercise can read its AAR once it
exists). `PATCH aars/{aar}` → `bcms.aars.update`, `permission:bcms.aar.manage`. Route-model-bound
on the AAR's uuid.

**Reads (this screen is a direct render of):** `docs/bcms/phase-9-aar-clause-map.md` §1.2 (the nine
sections and their clause refs), §1.3 (who signs, in what order — the approver-≠-facilitator rule),
§2.1 (the eleven-condition finalisation gate — **shown live, not discovered on submit**), §2.2 (the
`bcms.aar.quantitative.v1` schema, per exercise-type family), §2.3 (`participant_feedback` — role
and unit only, never a name), §3 (findings/CAPA — a producer screen, not a second register), §5
refinements 6, 7, 8, 11, 12 (score-without-evaluator, the amendment path, the T+3/T+7 void rule, the
AI-draft refusals, the export endpoint).

**Siblings:** `resources/js/Pages/Bcms/Bia/Workspace.jsx` (the `ai_generated` banner with its
"nothing here is approved by drafting it" language and its `blocking[]` red banner listing what
must be resolved before submit — **this screen's finalisation gate is that exact pattern, extended
from a handful of blocking issues to eleven named conditions**), `resources/js/Pages/Bcms/Findings/
Index.jsx` (read-only reference for how findings/CAPA rows render — this screen creates findings
inline but never re-implements their register), `resources/js/Pages/Bcms/Plans/Show.jsx` (the
`ai_generated` chip convention on a per-section basis, useful for per-field AI provenance if the
draft only fills some fields).

---

## 1. Purpose and the user

Two very different people open this screen, at two very different moments, and the screen has to
serve both without becoming two screens. The **facilitator** (`bcms.aar.manage`) opens it right
after ending the exercise, to draft: half-remembered facts, an AI-assisted first pass, sections
still empty. The **approver** (`bcms.aar.approve` — often, by the separation-of-duties rule for
`functional`-and-above exercises, a different, more senior person) opens the same URL days later to
read a completed draft and decide whether to sign it. A third reader — an examiner, weeks or months
later — needs the finalised version to answer one question without asking anybody: *did this
exercise actually happen, what went wrong, and what was done about it.* In the first ten seconds,
whichever of the three is looking, they must be able to tell: is this still a draft, is it blocked
from finalising and why, and if it is final, when and by whom.

This is a document that happens to render in a browser, in the same register as the TPRM regulatory
packs — it is judged on completeness and traceability, not on visual polish, and the ISO 22398
section order (§1.2 of the clause map) is not a design choice this screen is free to reorder.

## 2. Layout

`AppLayout`, `PageHeader` title `After-action report — {occurrence.definition_name}`, subtitle
`{scheduled_date} · {exercise_type_label} · {status_label}`. Header actions vary by state (§4).

**Status banner, always the first thing below the header, one of three mutually exclusive states:**

1. **Draft, blocking conditions outstanding** (`red-50`/`red-900`, matching `Workspace.jsx`'s
   `blocking` banner exactly — same component, same wording pattern: "This cannot be finalised
   until {n} {issue is/issues are} resolved," followed by the **live checklist** from §2.1 of the
   clause map, each of the eleven conditions rendered as its own line with a ✓/✗ glyph *and* text
   (never colour alone), computed and shown continuously as the draft is edited — not discovered
   only when Finalise is clicked. This is the single most important layout decision on this screen:
   the gate is a live status, not a submit-time surprise.
2. **Draft, all conditions met, awaiting finalisation** (`amber-50`/`amber-900`): "Every condition
   is met. Finalising will lock the timeline, scores, injects and attendance for this occurrence."
   — stated plainly because finalisation has a real, named consequence (clause map §1.2 note,
   refinement 7), not a silent one.
3. **Final** (`emerald-50`/`emerald-900` — this module's existing "confirmed" tone, not a new one):
   "Finalised by {approved_by} on {approved_at}. Distributed {distributed_at ?? 'not yet'}." — with
   a "Reopen" action for `bcms.aar.approve` holders only (§4, refinement 7).

**AI-draft banner**, shown whenever `ai_generated`, directly below the status banner, in the
established `sky-50`/`sky-900` tone (`Bia/Workspace.jsx`'s exact wording adapted): "Parts of this
report were drafted by a model on {ai_draft_generated_at}. Nothing here is approved by drafting it.
Read what it proposed, change what is wrong, and submit it yourself." — never merged with the
status banner; provenance and finalisation-readiness are two different facts and collapsing them
would make one look like it excuses the other.

**Body — the nine sections in the clause map's exact order, each a card, each carrying its own
`iso_clause_ref` as small muted text in the card header** (never hidden — an examiner's first
question is "what clause is this," and this screen answers it on every section without being
asked):

1. **Exercise identity and design** (read-only, sourced from the occurrence/definition — this
   screen does not let anyone edit the exercise's own record here; a link to
   `bcms.exercise-definitions` for that). Aim, objectives, scope, type, ladder level, scenario,
   date, facilitator, participant count.
2. **Objectives vs outcomes.** A table, one row per objective: text, target, actual, met/not-met
   (colour-plus-text badge), mean score, scores count, and — critically — for every score of 1–2,
   a required disposition control inline: a select of "Linked to finding {ref}" (if one already
   exists) or "Disposition in what-failed" with a short reason field, satisfying condition 6
   directly on the row it concerns rather than as a separate checklist item elsewhere.
3. **Timeline of key events.** Read-only render of `bcms_exercise_timeline`, same chip/type styling
   as the execution workspace, collapsed to the last 10 entries with "show all {n}" — the AAR
   reader needs to see the shape of the record exists (condition 4: ≥1 `milestone` entry) without
   re-scrolling the entire live log; the export (§4) carries the full timeline regardless of what
   is expanded here.
4. **Quantitative results.** Rendered from `quantitative_results`, auto-filled at `complete` time
   per exercise-type family (clause map §2.2 table) — this screen is a **display and light-edit**
   surface for that JSON, not a hand-typed form: every metric key the type requires is shown as a
   labelled field, editable only where the source event genuinely could not capture it, and any
   required-but-absent metric shows an inline "Not measured — state why" control feeding
   `not_measured[]` (condition 11) rather than a silently blank field. For `DRFAILOVER`/`DRTEST`,
   `threshold_breached` renders as **computed, never a typeable checkbox** (clause map §2.2 closing
   note) — shown with the arithmetic beside it ("62 min actual vs 30 min threshold — breached").
5. **What worked / what did not.** Two plain long-text fields, both required non-empty at
   finalisation (condition 3) — rendered side by side on a wide viewport, stacked on narrow.
6. **Participant feedback.** Read-only summary from `participant_feedback` (invited/responded
   counts, per-question distribution or mean), plus the free-text comments list — **each comment
   shown with role and business unit only, literally no name field rendered even if one were
   somehow present in the payload** (clause map §2.3 is the hard rule this screen enforces visually:
   there is no name column to accidentally wire up).
7. **Findings.** A table of findings already raised against this AAR (`source = aar`), each row:
   reference, classification, severity, `iso_clause_ref`, status, and its corrective actions
   inline — this is a filtered, embedded view of the same data `Findings/Index.jsx` shows
   globally, **reusing that screen's row rendering conventions**, not a redesign. Below the table,
   an inline **"Raise a finding"** form matching `Findings/Index.jsx`'s existing raise-form fields
   exactly (source pre-set to `aar`, classification/severity/description/clause), because this is
   the one place Phase 9 is a producer into Phase 1's register (clause map §3.1) — the form posts
   to the *existing* `bcms.findings.store` route, nothing new.
8. **Recommendations → corrective actions.** Inline, under each finding needing one: the same
   "Add action" mini-form already in `Findings/Index.jsx` (`AddAction` component), posting to the
   existing `bcms.actions.store` — not rebuilt here either.
9. **Carried-forward chain.** A table: reference, from-occurrence, disposition (validated /
   still_open / superseded — a required select per row, condition 9), note. Rows come from
   `GET bcms.occurrences.carried-actions` for this occurrence. **A row with no disposition set
   blocks finalisation** and is one of the live checklist's eleven lines. If there is no carry at
   all because there was no next-of-kind occurrence to receive it (clause map §3.3 item 3), this
   section instead shows the "no scheduled exercise will validate this action" note for any action
   that fell into that state — sourced from the programme dashboard's same computed fact, not
   re-derived here.

**Readiness overrides** — not one of the nine numbered sections, but a required condition (8) and
shown as a small sub-panel inside section 1 (exercise identity), listing every override with its
reason and signatory, because an override is a fact about how the exercise was run, which is where
an examiner looks for it.

**Footer action bar** (sticky, matching the module's existing sticky-footer pattern where forms are
long — `Plans/Show.jsx`): Save draft (`bcms.aar.manage`, always available while draft/review),
AI-draft button (§2.2 below), Finalise (`bcms.aar.approve`, disabled with a tooltip naming the first
unmet condition while any remain), Distribute (`bcms.aar.approve`, enabled only once final),
Export (`bcms.report.export`, always available once at least a draft exists — see §4).

## 3. Every state

- **No AAR exists yet for this occurrence** (the occurrence just completed). This route is reached
  only after `POST bcms.occurrences.complete` has created the draft row server-side per the API
  surface's own contract ("spawns corrective actions" language in the prompt refers to finalise, not
  completion — the draft AAR itself is created at `complete`, per the clause map's schema being
  keyed 1:1 to the occurrence). If somehow reached with none, the screen shows a single message and
  a "Create the after-action report" button rather than a blank set of nine empty cards, so a
  facilitator is never staring at nine cards wondering which to fill first without being told one
  exists to be created.
- **Draft, empty.** All nine sections render with their own **section-specific empty state**, never
  a shared blank: e.g. section 5 reads "Nothing recorded yet — what worked well during this
  exercise?" as placeholder-style guidance text above the empty textarea, not a zero or a dash.
- **Draft, partially filled.** The live gate checklist (§2) is the primary signal here — sections
  with content show normally; the checklist tells the facilitator exactly what is missing, in the
  same order as the clause map's eleven conditions, each with its own short reason (not just "3 of
  11 met").
- **Review** (`status = review`, if the product distinguishes an interim reviewer step — if the
  backend does not implement a distinct `review` status beyond `draft`→`final`, this state does not
  apply and the screen has exactly two: draft and final, per the schema's own `status` enum
  `draft|review|final`). Where `review` exists: same as draft, editable, but the header additionally
  shows "Under review" and no new banner beyond the existing draft-gate one.
- **Awaiting the second approver** (separation-of-duties rule, clause map §1.3): if the signed-in
  `bcms.aar.approve` holder *is* the occurrence's `facilitator_id`, and the ladder level is
  `functional` or above, or a readiness override occurred — the Finalise button is disabled with an
  inline explanation: "You facilitated this exercise. At this level, a different approver must
  finalise it (internal audit rule, not an ISO requirement)." — explicitly labelled as a product
  rule, per the clause map's own instruction to say so on the screen. Below `functional`, the same
  condition instead renders as a non-blocking amber note.
- **Final.** Every field renders read-only (no textarea, no select — plain text, matching the
  product's existing read/edit visual distinction), with the emerald banner from §2. The Findings
  and Corrective Actions sections remain interactive in their own right (raising/progressing an
  action is Phase 1's register, not frozen by this AAR being final) but the **AAR's own fields —
  summary, what_worked, what_failed, quantitative_results, participant_feedback — are frozen**,
  matching acceptance criterion 8 exactly. An attempt to edit a frozen field (e.g. a stale tab still
  showing edit controls) is refused server-side and logged; this screen's contribution is simply
  never rendering an edit control for a frozen field in the first place.
- **Reopened** (refinement 7): a `status` transition back to draft by `bcms.aar.approve`, with a
  mandatory reason. Shown as: the draft-gate banner returns, plus a small `slate-50` note at the top
  — "Reopened on {date} by {user}: {reason}. Original approval and distribution history are kept in
  the audit log." A reopened AAR **requires re-approval and re-distribution** before it can be final
  again (clause map refinement 7) — the Distribute button does not carry over an old
  "distributed" state as still current.
- **AI draft requested, in flight.** The AI-draft button reads "Drafting…" and disables (mirroring
  `Bia/Workspace.jsx`'s existing AI-request pattern), with no full-page spinner — the rest of the
  form remains usable, because drafting can take some seconds and the facilitator should not be
  blocked from typing into a different section meanwhile.
- **AI unavailable.** Same small muted note as `Bia/Workspace.jsx`: "AI drafting is unavailable:
  {reason}." beneath the button, button itself hidden/disabled rather than present-and-broken.
- **Validation error on Save.** Per-field, beneath the relevant control, `role="alert"`, matching
  the product-wide `Field` error pattern.
- **Finalisation attempted while a condition is unmet** (a race — two tabs, or a stale client
  state). Server refusal renders as: the live checklist re-syncs from the response and the specific
  failed condition is highlighted, rather than a generic "cannot finalise" toast — the whole point
  of showing the checklist live is that a submit-time failure should never be a surprise, and if
  one somehow occurs, the response is a queue to look at the checklist, not a dead end.
- **Server/network error.** Standard handling; in-progress field values are preserved (Inertia's
  own behaviour on a failed visit), consistent with every other long-form screen in the product.
- **Permission-denied** (viewing without `bcms.exercise.view`, or attempting an edit control without
  `bcms.aar.manage`). Standard 403 for the route; edit controls for a viewer without `manage` simply
  do not render (read-only render of the same nine sections) rather than rendering disabled —
  matching the ai-settings precedent of "no apparent means to do the thing you cannot do."
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Save draft (any field) | `PATCH bcms.aars.update` | None — draft saves are reversible by editing again. |
| Request AI draft | `POST bcms.occurrences.aar.ai-draft` (per the prompt's naming, translated to the shipped `routes/web.php` convention by the backend engineer) | None to *request*; the result lands as an editable draft with `ai_generated` set, never auto-applied without the facilitator seeing it first — matching the three explicit AI refusals in clause map refinement 11: it may not set `outcome`, may not call `FindingService::raise()` itself, and everything it produces (AAR fields, and any finding a human later accepts from its suggestions) carries `ai_generated = true`. |
| Set an objective's low-score disposition (link to finding / disposition note) | Inline field, saved as part of `PATCH bcms.aars.update` | None |
| Set a carried-action's disposition | Inline select, saved via the same `PATCH`, or a dedicated small `POST` if the backend chooses to make disposition its own endpoint — either way, no separate confirmation |
| Raise a finding from the AAR | `POST bcms.findings.store` (existing route, `source` pre-filled `aar`, `aar_id` implied by context) | None — matches `Findings/Index.jsx`'s own lack of a confirm on raise |
| Add a corrective action | `POST bcms.actions.store` (existing route) | None |
| **Finalise** | `POST bcms.aars.finalise` | **Confirmation required, naming the consequence**: "Finalise this report? The timeline, scores, injects and attendance for this occurrence become locked. Corrective actions will be raised from the findings above." — a modal, not a `window.confirm`, because this is the single most consequential action on the screen and deserves the module's heavier pattern (matching `Readiness.jsx`'s override modal, not `CallTrees/Live.jsx`'s lighter `window.prompt`, since the latter is used for the equivalent-weight Abort action which by contrast supplies its own reason inline). |
| Reopen a final AAR | `POST bcms.aars.reopen` (name per convention; the prompt does not list this endpoint explicitly — it is a refinement-7 addition the backend engineer must add, and this spec calls out that it is new) | **Confirmation required with a mandatory reason field** (matches the override-modal shape) — "Reopening requires `bcms.aar.approve` and clears the approval; re-approval and re-distribution will be required." |
| Distribute | `POST bcms.aars.distribute` | Confirmation naming who it goes to if the distribution list is known at send time ("Distribute to {n} recipients?"); otherwise a plain confirm, no `window.confirm` — this is a one-way send, not reversible, and deserves the same modal weight as Finalise. |
| Export | `GET occurrences/{occurrence}/aar/export` → `bcms.occurrences.aar.export`, `permission:bcms.report.export` — **this route does not exist in the prompt's API surface and clause-map refinement 12 calls it a sufficiency gap; this spec treats it as required, not optional**, modelled on `EvidenceExport` (built from stored values, computing nothing new) | Plain download link/button, no confirmation — an export is non-destructive. |

## 5. Accessibility (WCAG 2.1 AA)

- **The live gate checklist is a real list** (`<ol>` or `<ul>` with one `<li>` per condition), each
  item's met/unmet state carrying **both an icon and text** ("✓ Met — every objective has at least
  one score" / "✗ Not met — 2 objectives have no score yet"), read in full by a screen reader
  encountering the list — never an icon-only row.
- **Section headers use real heading levels** (`<h2>` per numbered section, in document order
  matching the clause map's own order), so a screen-reader user can jump section-to-section with
  standard heading navigation rather than scrolling.
- **Every `iso_clause_ref` is visible text**, not a tooltip-only annotation — a screen reader
  encountering a card reads its clause reference as part of the card, matching the product-wide rule
  that nothing evidence-bearing hides its clause behind a hover.
- **The AI-draft banner and the status banner are both static (non-live) on load** — they render
  once; only the *result* of an explicit AI-draft request announces via `aria-live="polite"`
  ("Draft ready — review before saving"), avoiding the alert-fatigue failure of re-announcing a
  banner that has not changed.
- **Finalise/Reopen/Distribute confirmations are true modal dialogs** (`role="dialog"`,
  `aria-modal="true"`, focus trapped, focus returned to the triggering button on close/cancel) —
  matching `Readiness.jsx`'s existing override-modal implementation exactly, not a new pattern.
- **Keyboard path**: status/AI banners (not focusable) → header actions → section 1 (read-only
  identity fields, in tab order only where a control exists — e.g. the readiness-override sub-panel
  has no interactive control and is not in the tab order) → section 2's per-objective disposition
  controls → sections 3–6 in order (edit controls only where `can.manage` and not final) → section
  7's findings table and raise-form → section 8's add-action forms → section 9's disposition
  selects → footer action bar (Save, AI-draft, Finalise, Distribute, Export) in that visual order.
- **Read-only (final) state removes controls from the tab order entirely** rather than leaving
  disabled inputs a keyboard user tabs through for no reason — a finalised report's fields render as
  plain text nodes, which is both the correct accessibility behaviour and the correct visual
  behaviour (§3).
- **Contrast**: the three status banners follow the module's existing three-tone system exactly
  (`red-50`/`900`, `amber-50`/`900`, `emerald-50`/`900`) with no new shade; the AI banner's
  `sky-50`/`900` pairing is likewise an existing, already-audited combination from `Bia/Workspace.jsx`.

## 6. Low bandwidth (100 kbps)

- **This is a long, text-heavy document, not a media-heavy one** — no chart, no image, no icon
  webfont beyond the product's existing glyph set. Every "chart-shaped" fact on this screen (a
  mean score, a completion percentage, an RTO actual-vs-target) is rendered as text or, at most, the
  same lightweight bar treatment `CallTrees/Live.jsx` already uses for tier progress — inline `<div>`
  width percentages, not SVG, and only where a proportion genuinely benefits from a bar (the
  objectives table's met/not-met is text+badge, not a bar).
- **The whole document is one Inertia page load** — no section lazy-loads separately, so there is no
  risk of a partially-rendered report on a slow connection; the trade is a larger initial payload,
  accepted because this document is opened rarely relative to how often it is read once loaded, and
  because splitting an evidentiary document across several requests is itself a risk (a reader on a
  bad connection seeing six of nine sections and not realising three failed to load is worse than a
  single slower load).
- **AI-draft request has no large payload either way** — it triggers a server-side job/synchronous
  call and returns the filled fields; the screen shows "Drafting…" rather than blocking, so a slow
  connection does not read as a hung page (matching `Bia/Workspace.jsx`'s existing behaviour).
- **Export is a deliberate, explicit download**, never prefetched or auto-generated on page load —
  a multi-section evidentiary PDF/document is exactly the kind of payload that must never load
  silently in the background on a metered or degraded connection.
- **Save is incremental** — `PATCH` carries only changed fields where practical (standard `useForm`
  partial-update behaviour), not the entire nine-section payload on every keystroke-adjacent save.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Every field in the nine sections | Per the mapping table in clause map §1.2, restated: occurrence/definition tables (§1), `bcms_exercise_scores` + `quantitative_results.objectives[]` (§2), `bcms_exercise_timeline` (§3), `bcms_aars.quantitative_results` JSON per §2.2's schema (§4), `bcms_aars.what_worked`/`.what_failed` (§5), `bcms_aars.participant_feedback` JSON per §2.3 (§6), `bcms_findings` filtered `source=aar` (§7), `bcms_corrective_actions` against those findings (§8), `carried_to_occurrence_id`/`carried_at` plus `quantitative_results.carried_actions[]` (§9) |
| The eleven live-checklist conditions | Computed server-side against the clause map §2.1 rules and returned as a structured list on every page load and after every save — **never re-derived in JavaScript from raw fields**, for the same reason the ai-settings spec insists on server-computed `master_layer`: a client-side re-implementation of an eleven-condition gate is exactly where it would drift from the server's own `AarService::finalise()` logic, and a screen that shows "ready" when the server would refuse is worse than showing nothing |
| `threshold_breached` (DR types) | Computed server-side from `downtime_minutes` vs `threshold_minutes`, never a typed value — clause map §2.2 |
| Ladder/coverage facts referenced from section 9 | `LadderAdvisor` / the programme dashboard's carry-fallback computation, read, not recalculated here |
| `approved_by`/`approved_at`/`distributed_at` | `bcms_aars` columns directly |
| Findings/CAPA counts and rows | `bcms_findings`/`bcms_corrective_actions`, same source `Findings/Index.jsx` already reads, filtered to this AAR |

## 8. Deliberately out of scope

- **A second findings/CAPA register.** This screen creates findings and actions inline through the
  existing routes and renders them read-back; it does not filter, search, or manage the global
  register — that is `Findings/Index.jsx`, unchanged (clause map §3.1's ownership rule).
- **Editing the exercise definition or occurrence's own record** (dates, participants, objectives
  list) — a link out to the existing exercise-definition screens, not an editable field here.
- **Photo/evidence attachment on the quantitative-results or timeline sections** — same
  architect-ADR gap as the workspace screen (`execution-workspace.md` §7); this screen shows
  whatever `sources[]` already carries (call-tree test id, EMNS alert id, DR test id, each a link
  out to its own record) but no upload control renders here either.
- **NigFinCERT notification drafting UI** — the clause map notes `CYBER` objectives ask whether a
  notification was drafted and reviewed; this screen shows that as a captured fact (a metric field
  under section 4), not a drafting tool of its own.
- **Board-pack assembly** — Phase 11 reads this AAR's data; this screen does not produce a board
  pack.
- **Language localisation of AI-drafted narrative** — out of scope, matching the EMNS gap noted in
  Phase 7's handoff (`ha`/`yo`/`ig` not authored); an AI draft here is assumed English-only until a
  compliance-analyst-owned localisation pass exists.
