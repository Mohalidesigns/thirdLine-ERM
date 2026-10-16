# Screen spec — `Bcms/Scenarios/Show` (review, edit, activate, apply)

**Route:** `GET bcms/scenarios/{scenario}` → `bcms.scenarios.show`, `middleware('permission:
bcms.exercise.view')`, **numeric id** — see `scenario-library.md`'s route-model-binding note; this
table carries no `uuid` column, and that is a deliberate consequence of ADR 0023's zero-table,
one-column freeze, not an oversight to "fix." Editing and activating require `bcms.exercise.manage`.
Applying to an occurrence is reached **from the occurrence** (the execution workspace or the
occurrence's own scenario picker), not from a button on this screen — see §8.

**Reads:** ADR 0023 §2.1 (every guardrail this screen must make visible: provenance flag, editable
draft state, human action required, prompt/output logged), §2.2 landing-state table (row 2:
`bcms_scenarios` lands `is_active = false`), §3.2 (the output contract and its per-field validation
— this screen is the human side of "a rejected field is left empty for the human rather than
repaired by guesswork"), §3.4 (apply-to-occurrence rules: active only, planned/in-progress
occurrences only, refused if the occurrence already has injects).

**Siblings read before writing this:** `resources/js/Pages/Bcms/Bia/Workspace.jsx` (the
`ai_generated` banner wording — "Nothing here is approved by drafting it" — reused verbatim, and the
per-field edit-in-place convention for a draft record); `pir-post-incident-review.md`'s AI-draft
banner note and `aar-builder.md`'s live-checklist pattern for "what's missing before this can be
used" (adapted here to the validator's field-by-field rejections, not an eleven-condition gate);
`Programme.jsx` (a small compact objectives/injects table, the visual weight this screen's inject
table borrows).

---

## 1. Purpose and the user

A facilitator opens this screen in one of three circumstances: straight after generating a variant
(landed `is_active = false`, needs a read-through and maybe a fix before it can be used), browsing
the library and wanting the full detail of a shipped system template before choosing it for an
occurrence, or returning to a tenant-authored draft days later to finish it. In the first ten
seconds they must see: is this AI-drafted or human-authored, is it active (usable) or still under
review, and — for a fresh AI draft — exactly which fields the validator could not accept and left
for them to fill in. This is a working document, not a finished artefact: unlike the AAR builder it
has no eleven-condition finalisation gate, because activation here is a single deliberate act, not a
compliance sign-off.

## 2. Layout

`AppLayout`, `PageHeader` title `{scenario.name}`, subtitle `{category label} · {ladder_level_min
label} · {status_label}`. Header actions: "Activate" (`can.manage`, disabled while `is_active`,
§4), and, for a system template, no edit control at all — see §3's read-only state.

**AI-draft banner**, shown whenever `ai_generated`, directly below the header, the established
`sky-50`/`sky-900` tone: "Drafted by a model on {ai_drafted_at, from the provenance audit row — see
§7}. Nothing here is approved by drafting it. Read what it proposed, change what is wrong, and
activate it yourself." — never merged with the status badge, matching `aar-builder.md`'s rule that
provenance and readiness are two different facts.

**Validator gaps banner**, shown only when one or more contract fields were rejected on landing
(ADR 0023 §3.2), `amber-50`/`amber-900`: "The model's answer for {n} field(s) could not be used and
was left blank for you to fill in: {list of field labels, e.g. 'Objective 3', 'Inject 5 — content'}."
— each named field is a real anchor link scrolling to the empty control below, not a bare count.

**Body, in this order:**

1. **Identity.** Name, code (`{code}` or `{TEMPLATE_CODE}-V{n}`), category, ladder level minimum —
   all editable text/select fields when `can.manage` and the scenario is a tenant row (system rows,
   `is_system_default = true`, render every field read-only — see §3).
2. **Summary and narrative.** Two textareas (`≤600`/`≤1500` chars, live counters matching the
   contract's own limits from ADR 0023 §3.2), each carrying its character budget as a hint so a
   facilitator editing a generated draft sees the same ceiling the model was held to.
3. **Regulatory drivers.** Short free text or tag list (existing field, unchanged shape).
4. **Objectives.** An ordered list, 2–6 items (contract limit), each a `≤300` character text field
   with an "Add objective" / remove-row control, matching the AAR builder's "what worked/what
   failed" plain-list weight rather than a rich editor.
5. **Injects.** A `data-table`: sequence · offset (minutes) · title (`≤250`) · content (`≤400`) ·
   delivery channel · a row-expand for "expected response" (folded from the model's suggestion —
   ADR 0023 §3.2: `expected_response` has no column on `bcms_exercise_injects` and stays in
   `suggested_injects` json, later folded into the applied inject's `content` under a heading). The
   **delivery channel** select offers `in-app` plus the `ChannelKey` vocabulary the execution
   workspace already accepts (`sms`, `whatsapp`, `voice`, `email`, `teams`, `slack`, `push`,
   `ussd`) — this screen inherits that vocabulary rather than inventing its own, per ADR 0023 §3.2's
   instruction. Offsets are validated client-side as non-decreasing and within `duration_minutes`
   (the brief's own value, shown as a caption above the table: "Injects release across
   {duration_minutes} minutes"), with a red inline note on any row that violates it — mirroring the
   deterministic-check convention, never silently reordering the facilitator's own edits.
6. **Provenance** (read-only, shown only when `ai_generated`): "Generated from {template_code},
   requested by {requested_by}, on {ai_drafted_at}." — the audit trail's own facts, not recomputed.

**Footer action bar** (sticky, matching `aar-builder.md`'s pattern): "Save" (`can.manage`, any time),
"Activate" (`can.manage`, disabled while any contract-limit violation is present, with a tooltip
naming the first one), "Cancel" reverting unsaved edits.

## 3. Every state

- **Landed, AI-generated, gaps present.** The default state right after generation: AI banner,
  validator-gaps banner, empty fields highlighted (amber left border, matching the module's
  "editable, not broken" convention rather than a red error before the human has even looked).
- **Landed, AI-generated, no gaps.** AI banner only; every field pre-filled and immediately
  editable.
- **Tenant-authored, not AI-generated, draft.** No AI banner; identical editable layout.
- **System template** (`is_system_default = true`). Every field renders **read-only** (plain text,
  no textarea/select — matching the AAR builder's final-state visual rule), with a small note: "This
  is a shipped template. To customise it, generate a variant from the library." linking back to
  `scenario-library.md`'s generate panel pre-filled with this template selected. There is no "Edit a
  copy" button on this screen — the generate flow **is** the copy-and-edit path, and duplicating it
  here would be a second way to reach the same outcome with a different shape.
- **Active** (`is_active = true`, any origin). Read-only, same visual rule as a system template
  ("Activated {date} by {who}" replaces the Activate button); a facilitator wanting to change an
  active scenario's wording is told plainly: "Active scenarios are not edited in place — generate or
  author a new variant." This is a considered constraint, not a gap: an active template may already
  be referenced by a scheduled occurrence, and silently rewriting it under that occurrence would be
  exactly the kind of drift the module's evidence rules exist to prevent.
- **Activation attempted with an outstanding gap.** Refused with the same field-by-field message
  the footer's disabled tooltip already states; nothing here is a surprise at submit time, matching
  the AAR builder's "the gate is a live status, not a submit-time surprise" rule even though this
  screen's gate is simpler (contract validity, not eleven conditions).
- **Save with a validation error** (e.g. an objective typed past 300 characters). Per-field, beneath
  the control, `role="alert"`.
- **Permission-denied.** Standard 403 for `bcms.exercise.view`; edit controls and the Activate
  button simply do not render for a viewer without `bcms.exercise.manage`.
- **Server/network error.** Standard Inertia handling; typed field values preserved.
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Save edits | `PATCH bcms.scenarios.update({scenario})` | None — reversible by editing again, matching every other draft-edit screen in the module |
| Activate | `POST bcms.scenarios.activate({scenario})` | Lightweight inline confirm, no modal: "Activate this scenario? It becomes available to apply to an occurrence." — this is a one-way state change (no "deactivate" exists) but is not destructive of any data, so it does not earn the module's heavier modal weight; matching the AAR builder's distinction between a text-requirement action and a genuinely destructive one |
| Add / remove an objective or inject row | Client-side, saved on the next Save | None |

Applying a scenario to an occurrence is not an action on this screen — see §8.

## 5. Accessibility (WCAG 2.1 AA)

- **The AI banner and the validator-gaps banner are both static on load**, announced once via the
  page's own heading structure; they do not use `aria-live`, matching `aar-builder.md`'s rule that a
  banner present on load is read as ordinary content, not interrupted-and-announced.
- **Every field the validator rejected carries `aria-describedby` pointing at a short note** ("The
  model's answer for this field could not be used") in addition to the amber visual treatment —
  colour is never the only signal.
- **The injects table uses real `<th>`/`<td>` semantics** inside `data-table`, with each editable
  cell's control carrying a visible label (`sr-only` where the column header already states it, per
  the `ui-audit.mjs` accessible-name rule) rather than a bare input with no name.
- **Keyboard path:** AI/validator banners (not focusable) → header Activate → identity fields →
  summary/narrative → regulatory drivers → objectives list (add/remove buttons after the last
  field) → injects table, row by row, sequence-first → footer action bar (Save, Activate, Cancel).
- **Read-only states (system template, active) remove controls from the tab order entirely**,
  matching the AAR builder's finalised-state rule, rather than leaving disabled inputs a keyboard
  user tabs through for nothing.

## 6. Low bandwidth (100 kbps)

- **This is a text-heavy form, not a media surface** — no chart, no image. The injects table is the
  largest element and is plain markup, comparable in weight to the AAR builder's own tables.
- **Save is a `PATCH` carrying only the form's fields**, not the whole scenario re-serialised with
  every list re-sent from scratch — ordinary `useForm` partial-update behaviour.
- **No autosave** — matching the module's general rule (the AAR builder, the crisis room) that the
  browser's own retained form state is the protection against a dropped connection mid-edit on a
  long-form screen, not a background sync.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| `ai_drafted_at`, `requested_by` in the Provenance section | The `bcms_audit_logs` row `AiProvenance::record()` wrote (`ai.draft_generated`, context `{capability: 'scenario_generator', ...}`) — the **only** place this fact is logged (ADR 0023 §2.3); this screen reads it, never recomputes it |
| Field-level validator gaps | `ScenarioDraftValidator`'s stored verdict, recorded in the same provenance row's `validator: {accepted, rejected}` |
| Injects count, offsets | `suggested_injects` json, read as stored |
| "Activated {date} by {who}" | `updated_at`/`updated_by` at the moment `is_active` was set true (existing columns, no new one) |
| Duration caption on the injects table | The brief's `duration_minutes`, carried in the same provenance context, **not** re-derived from the injects' own offsets (which could disagree with what was asked for) |

## 8. Deliberately out of scope

- **"Apply to occurrence."** Per ADR 0023 §3.4 this action lives with the occurrence, not the
  template — a facilitator planning an exercise chooses a scenario from *within* the occurrence's
  own scenario picker (execution workspace / occurrence setup, specified elsewhere), which then
  calls the apply endpoint with both ids. Putting an "Apply" button on this screen would require it
  to also pick which occurrence, duplicating a control that already exists at the occurrence's end
  of the relationship, and would make it possible to apply a scenario to an occurrence a facilitator
  is not currently looking at.
- **Deactivating an active scenario.** No such action exists in ADR 0023; an active scenario stays
  active, and a superseding scenario is generated or authored fresh instead (§3).
- **Deleting.** Same reasoning as `scenario-library.md` §8.
- **Localised copy** for the scenario's own text. The generator writes English only (ADR 0023 §2 —
  no BCMS AI writes Hausa, Yoruba, Igbo or Pidgin); a scenario's narrative and injects are not
  dispatch copy in the first place, so this gap is smaller than the alert composer's, but it is
  still true and worth stating rather than leaving silent.
