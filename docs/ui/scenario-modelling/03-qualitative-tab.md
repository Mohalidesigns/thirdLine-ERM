# 03. Scenario workspace — Qualitative tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=qualitative` · **Route:** `GET /risk/scenarios/{scenario}?tab=qualitative` (`scenario.show`) · **Writes — three, not one:** `PUT /risk/scenarios/{scenario}/causes` and `PUT /risk/scenarios/{scenario}/allocations` (both `permission:scenario.update`) and `PUT /risk/scenarios/{scenario}` for the exposure columns (also `scenario.update`, and a **partial** update — `01` §6.1) · **Phase:** 1

Read `01-workspace-shell.md` first: the status-colour table (§2), the permission→action table (§3), the shared states (§0.2), the accessibility baseline (§0.3), the low-bandwidth rules (§0.4) and the no-fabricated-numbers rule (§0.5) apply here unchanged and are not repeated.

**Authority:** ADR 0027 Amendment 1 — §A1.2 (the exposure columns, including which are nullable), §A1.4 (`sm_scenario_causes`), §A1.5 (`sm_business_line_allocations`, and the refusal of `business_processes`), §A1.8 (`ScenarioCause::CHECKLIST`'s 23 frozen codes, the two exposure scales, and the `in_design → designed` guard). Vocabulary strings are verbatim from `plans/scenario-modelling/VOCABULARY-EXTRACTION.md` via §A1.8.

---

## 1. Purpose and the user

**Who.** The designer, in a workshop or immediately after one, turning a discussed risk theme into the workbook's qualitative half: what causes it, which business lines it hits, how bad the non-financial exposure is, and whether any of it can be transferred. Later the validator, who reads this tab to decide whether the scenario was argued about or merely written down.

**In the first ten seconds.** Whether the causes were actually chosen (not left blank), whether the allocation adds up, and what the exposure view is. This tab is where a validator forms an opinion about the *thinking*, so it must show the difference between "we decided there is no reputational impact" and "nobody looked".

**Must be able to.** Tick the accurate causes and say something about each; allocate the scenario across business lines and see the sum as they type; pick a reputational and a legal exposure from a described scale; answer the three yes/no risk questions honestly, including "we haven't assessed that".

**Design stance.** The workbook's instruction on this sheet is *"Bold the accurate(s) cause of the risk"* — a multi-select expressed as typography, on paper, in a file that gets emailed. Everything here is that instruction made operable: multi-select becomes checkboxes, "bold" becomes selection, and the margin-note a workshopper scribbles becomes a per-cause note field. The screen must also be **forgiving mid-workshop**: a half-filled qualitative tab is the normal state, not an error state, and it must render as work in progress rather than as a form full of complaints.

---

## 2. Props this tab expects

Shell props are in `01` §4; this tab's arrive under `qualitative` and are sent **only when `tab === 'qualitative'`** (`01` §4).

```
qualitative: {
  causes: {
    checklist: [ { code: string,                    // one of §A1.8's 23 frozen codes
                   side: 'internal'|'external',
                   cause_group: 'people'|'process'|'system'|'external',
                   group_label: string,             // 'People' | 'Processes' | 'Systems' | 'External'
                   label: string,                   // the workbook's wording, verbatim
                   requires_note: bool,             // true for the four "Other(s)" and for ext_unknown
                   sort: int } ],
    selected: { [code]: { note: string|null } }      // absent code = not selected (§A1.4: rows are sparse)
  },
  allocation: {
    lines:   [ { key: string, business_unit_id: int, label: string, percent: string|null } ],
    options: [ { id: int, name: string } ],          // business_units ONLY — §A1.5 refused processes
    saved_total: string|null                         // decimal STRING; null when there are no lines
  },
  exposures: {
    reputational_exposure:      { value: string|null, unset_label: string,
                                  options: [ {value, label, description: string|null} ] },
    legal_compliance_exposure:  { value: string|null, unset_label: string,
                                  options: [ {value, label, description: string|null} ] },
    insurance_risk: bool|null,
    financial_risk: bool|null,
    eligible_to_transfer: bool                        // NOT NULL — no unset state (§A1.2 row 25)
  },
  yesNo: { yes: 'Yes', no: 'No', unset: 'Not assessed' }   // labels are config, not hard-coded
}
```

**Post bodies** — three, one per endpoint:

```
PUT {scenario}/causes       { causes: { [code]: { note: string|null } } }   // absent code = deselected
PUT {scenario}/allocations  { allocations: [ { business_unit_id: int, percent: string } ] }
PUT {scenario}              { reputational_exposure: string|null, legal_compliance_exposure: string|null,
                              insurance_risk: bool|null, financial_risk: bool|null,
                              eligible_to_transfer: bool }
```

**(prop decision) The cause checklist, both exposure scales and the yes/no labels all arrive as data.** The authority is split, per **addendum-3 to Amendment 1** (recorded in `config/sm.php`'s header), which narrowly reverses §A1.8's refusal of a publishable config file:

- **`config/sm.php` supplies the WORDING** — `label`, `description`, `group_label`, `requires_note`, `sort`, `unset_label`.
- **The model consts supply the legal VALUE SET** — `ScenarioCause::CHECKLIST`, `Scenario::REPUTATIONAL_EXPOSURES`, `Scenario::LEGAL_COMPLIANCE_EXPOSURES`. Every `value`/`code` in the config must be one of them, asserted by `tests/Feature/Sm/ConfigMatchesFrozenVocabularyTest.php`.

So a tenant can rename "External Salesforce / Distribution" or the affiliate wording without a front-end release, but **cannot make the database hold a value no code knows**. The presenter reads the config and sends it as props; the front end hard-codes none of it. The tables in §4 and §6 below are the **shipped config values and the contract for the presenter**, not JSX constants.

Two consequences the config header attributes to this spec, both restated here so they are not lost:
- **The ordinal of each scale lives in the `options[]` array order and nowhere else** (§12.8). Anything sorting by exposure server-side reads that order rather than inventing a stored ordinal.
- **`requires_note` is config with no Phase-1 editing surface** (§12.3), so the Form Request enforces "note required when a `requires_note` cause is selected" with §7's message.

**(prop decision) `causes.selected` is an object keyed by code, and so is the posted body.** An array of `{code, note}` would make a deselect-and-reselect reorder the payload and turn `causes.{code}.note` error keys into positional ones. Keyed by code, the payload is idempotent and every error key is stable. This also matches §A1.4's sparse storage and its unique key `sm_causes_scenario_code_unique (sm_scenario_id, code)`.

**(prop decision) `side` and `cause_group` are never posted.** §A1.4: they are "derived from `code` by the service at insert, never accepted from the request — a client posting them is how a row arrives `side = external` with `code = people_other`." The UI sends `code` and `note` only, and reads `side`/`cause_group` from the checklist for grouping.

**(prop decision) Allocation targets `business_units` only, with no `kind` discriminator.** §A1.5 **refused** `business_processes` as an allocation target: the workbook's rows are *business lines* (Property and Casualty / Life and Savings / Health / Investment / Pensions — insurer lines, so the FK must be to the tenant's own tree, never a fixed list); `business_processes` is a *process catalogue* consumed by RCSA and mirrored again by BCMS's `bcms_processes`, so pointing allocations at it "puts two org trees on one screen"; and `risks` already models the pair as `business_unit_id` + `process_id`, where the unit is the owning level and the process the refinement. A nullable `business_process_id` refinement is **deferred**, not refused forever. This supersedes the earlier draft of this spec, which offered both with a `kind` field.

**(prop decision) `allocation.lines[].key` is a server-supplied stable string** (the row's id, or a uuid for a row never saved). React keys and the undo affordance (§5.3) need identity that survives a re-render; an array index does not.

**(prop decision) `allocation.saved_total` and every `percent` are decimal strings** (`01` §4.5), because §A1.5 requires the comparison to be made "as decimals at the column's precision (string/`bccomp`, never float `==`)" against `decimal(5,2)`. `saved_total` is `null` when there are no lines, never `"0.00"` — §5.4 explains why that distinction is the whole point.

**(prop decision) `exposures.*.options` contain only the affirmative answers** — six each. The unset option is rendered from `unset_label` and posts `null`. One component, two scales, no special cases: the reputational config seeds `unset_label: "Impact: not yet assessed"` (the workbook's own string), the legal config `"Not yet assessed"`. See §6.1 for why the unset state is a real, selectable radio rather than merely "nothing chosen".

**422 error keys:** `causes.{code}.note` · **`allocations`** (the sum-to-100 rule — §A1.5 answers 422 on that exact key) · `allocations.{i}.business_unit_id` · `allocations.{i}.percent` · `reputational_exposure` · `legal_compliance_exposure` · `insurance_risk` · `financial_risk` · `eligible_to_transfer`.

`allocations.{i}` indexes **the posted array**. The UI must map those errors against the order it posted, which is the order still held in form state after a 422 (`useForm` does not reset on error). **Consequence: the allocation list is not re-sortable in Phase 1.** A drag-to-reorder would silently move the errors onto the wrong rows, and there is no test in this repository that could catch it (standard §10: no JavaScript executes in any test).

---

## 3. Layout

Container `max-w-4xl`. Four section cards, in this order, each an `h2`.

*Every tick, percentage, business-line name and note in this sketch is sample data (`01` §0) — invented to show the shape of the screen. §11 names the source of each figure the tab really renders.*

```
┌── Causes ───────────────────────────────────────────────────── 4 selected ──┐
│ Tick every cause that applies. Add a note where the tick alone would be     │
│ ambiguous — a validator reads the notes, not the ticks.                     │
│                                                                             │
│ ┌─ Internal ────────────────────────┐ ┌─ External ─────────────────────────┐│
│ │ PEOPLE              2 of 6 selected│ │ EXTERNAL          1 of 10 selected ││
│ │ ☑ Inappropriate level of resources │ │ ☐ Natural environment              ││
│ │   └ Note (optional) [___________]  │ │ ☐ Technological                    ││
│ │ ☑ Inadequately skilled people      │ │ ☐ Cultural                         ││
│ │   └ Note (optional) [___________]  │ │ ☐ Regulatory                       ││
│ │ ☐ Inadequately managed people      │ │ ☐ Others *                         ││
│ │ ☐ Intentional act                  │ │ ☐ Supplier                         ││
│ │ ☐ Unintentional acts               │ │ ☐ Customer                         ││
│ │ ☐ Other *                          │ │ ☐ External Salesforce / Distribution│
│ │ PROCESSES           1 of 4 selected│ │ ☐ Group/affiliate entity           ││
│ │ ☑ Lack of process                  │ │ ☐ Unknown/yet to be determined *   ││
│ │   └ Note (optional) [___________]  │ │                                     ││
│ │ SYSTEMS             1 of 3 selected│ │                                     ││
│ │ ☑ Inadequately maintained systems  │ │                                     ││
│ │   └ Note (optional) [___________]  │ │                                     ││
│ └────────────────────────────────────┘ └─────────────────────────────────────┘│
│                                                             [ Save causes ] │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Business-line allocation ─────────────────────────────── Adds to 100% ✓ ──┐
│ How much of this scenario lands on each business line. Must add to 100%.    │
│ ┌───────────────────────────────────────────┬──────────┬───────┐            │
│ │ [🔍 Retail Banking                    ▾] │ [ 40.00 ]│ Remove│            │
│ │ [🔍 Corporate & Investment Banking    ▾] │ [ 35.50 ]│ Remove│            │
│ │ [🔍 Treasury                          ▾] │ [ 24.50 ]│ Remove│            │
│ └───────────────────────────────────────────┴──────────┴───────┘            │
│                                            [ + Add a business line ]        │
│ (empty state: "Not allocated — no business line carries this scenario yet.")│
│                                                     [ Save allocation ]     │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Exposure ─────────────────────────────────────────────────────────────────┐
│ Reputational impact                                                         │
│   ○ Impact: not yet assessed          ← selected when the value is null     │
│   ○ No impact                                                               │
│   ◉ Moderate                                                                │
│       Concerns becoming broader and more vocalised within the industry      │
│   ○ Major …                                                                 │
│                                                                             │
│ Legal, regulatory and compliance risk                                       │
│   ○ Not yet assessed   ○ No   ○ Insignificant   ○ Minor   ○ Moderate …      │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Risk transfer ────────────────────────────────────────────────────────────┐
│ Insurance risk                  ○ Yes  ◉ No  ○ Not assessed                 │
│ Financial / market risk         ○ Yes  ○ No  ◉ Not assessed                 │
│ Eligible to transfer            ○ Yes  ◉ No        (no "not assessed")      │
│   ⓘ Defaults to No. If nobody has checked the cover, record that as an      │
│     assumption — Phase 2's netting engine reads No as "nothing transferable".│
│                                                [ Save exposure answers ]    │
└─────────────────────────────────────────────────────────────────────────────┘
```

Three Save buttons, one per write route — see §5.6. No page-level Save.

No field on this tab carries a red `*`: §A1.8's gate requires only "≥1 cause" and "allocation sums to 100", and both are properties of a *group* of controls rather than of one field, so the requirement is stated in each card's help text and enforced by the shell's readiness strip (`01` §5.4) rather than by asterisks that would sit on fields the server does not require.

Below `lg` the two cause columns stack (Internal above External). Everything else is already single-column.

---

## 4. Causes checklist

`Components/Sm/CauseChecklist.jsx` (`01` §0.1.2). Renders `causes.checklist`, grouped by `side` then `cause_group`, in `sort` order. **The list is data**: §4.1's table is `ScenarioCause::CHECKLIST`'s frozen content and the contract for the presenter, not a JSX constant.

### 4.1 The seeded checklist — `ScenarioCause::CHECKLIST`, 23 frozen codes

§A1.8 freezes these as `code => [side, group, label]` in workbook row order (LS cols B/C rows 32–48), and states: **"This list *is* the checklist; a code not in it is a 422."** The presenter sends them as `causes.checklist`; the codes below are the contract for both sides.

Codes are **group-prefixed** so `code` is globally unique, which is what makes §A1.4's `sm_causes_scenario_code_unique (sm_scenario_id, code)` work: the workbook has an "Other" in each of People, Processes and Systems, and three rows sharing `code = 'other'` would make one unique key impossible.

**Internal — People** (`cause_group: 'people'`)

| `code` | `label` | `requires_note` |
|---|---|---|
| `people_inappropriate_resources` | Inappropriate level of resources | |
| `people_inadequately_skilled` | Inadequately skilled people | |
| `people_inadequately_managed` | Inadequately managed people | |
| `people_intentional_act` | Intentional act | |
| `people_unintentional_acts` | Unintentional acts | |
| `people_other` | Other | ✔ |

**Internal — Processes** (`cause_group: 'process'` — singular, per §A1.4)

| `code` | `label` | `requires_note` |
|---|---|---|
| `process_inadequately_designed` | Inadequately designed process | |
| `process_ineffective` | Ineffective process | |
| `process_lack_of` | Lack of process | |
| `process_other` | Other | ✔ |

**Internal — Systems** (`cause_group: 'system'` — singular)

| `code` | `label` | `requires_note` |
|---|---|---|
| `system_inadequately_designed` | Inadequately designed systems | |
| `system_inadequately_maintained` | Inadequately maintained systems | |
| `system_other` | Other | ✔ |

**External** (`side: 'external'`, `cause_group: 'external'` — one flat group; the workbook has no sub-groups here)

| `code` | `label` | `requires_note` | Note |
|---|---|---|---|
| `ext_natural_environment` | Natural environment | | |
| `ext_technological` | Technological | | |
| `ext_cultural` | Cultural | | |
| `ext_regulatory` | Regulatory | | |
| `ext_other` | Others | ✔ | |
| `ext_supplier` | Supplier | | |
| `ext_customer` | Customer | | |
| `ext_salesforce_distribution` | External Salesforce / Distribution | | |
| `ext_group_affiliate` | Group/affiliate entity | | The workbook's cell reads "Leadway Assurance company" — the reference artefact's own tenant. §A1.8 records this as **Amendment 1's only departure from a verbatim workbook string**: "Shipping one customer's insurer name into every tenant's checklist would put another bank's competitor on their screen." A tenant renames it in Phase 5's label overlay |
| `ext_unknown` | Unknown/yet to be determined | ✔ | |

Thirteen internal items in three groups, ten external items in one. The two columns balance well enough that no third grouping is invented to even them up.

**`group_label` is what renders as the group header, not `cause_group`.** The stored groups are singular (`process`, `system`) because they are column values; the headers read "Processes" and "Systems" because that is the workbook's wording. The presenter supplies both, and the UI renders `group_label`.

⚠ **Divergence from plan §1's prose, flagged.** The plan summarised People as four items ("inappropriate resources, inadequately skilled/managed, intentional, unintentional") and gave Processes and Systems no "Other". The workbook — and therefore §A1.8 — has six, four and three. The extraction is the evidence; this spec follows it.

### 4.2 Interaction

- Each cause is a **checkbox** with a visible label. Selecting it reveals a note field directly beneath, indented, in DOM order immediately after the checkbox so Tab reaches it naturally.
- **Focus does not move when a box is ticked.** Moving focus into the note would hijack a user tabbing down the list to tick four causes quickly — the single most common action on this tab. The note is the next tab stop and carries `aria-label="Note for {cause label} (optional)"`.
- **`requires_note` causes** render the note as **required**, with the `*` and "(required)" in `sr-only`, and the help text: "Say what the other cause is — 'Other' on its own tells a validator nothing." The same sentence covers "Others" and "Unknown/yet to be determined", whose whole content is the note.
- **Group headers** carry a live count: "PEOPLE — 2 of 6 selected", `text-xs text-gray-600`, updated as boxes change. The card header carries the total: "{n} selected". Both are counts of a set, so `0` is a true statement and is printed ("0 of 6 selected").
- **No select-all, at group or card level.** One click that ticks four causes is how a scenario ends up asserting causes nobody in the room discussed — and this tab is the evidence an examiner reads for "was this argued about". The count gives the group-level view without the group-level shortcut.
- **A note on an unticked cause is not sent.** Un-ticking keeps the typed note in component state for the rest of the session (so a mis-click does not destroy a paragraph) but the posted body omits the code entirely. If the cause is re-ticked, the note returns. On save, only selected codes are persisted.
- **No search or filter over the checklist.** Twenty-three items in four visible groups fit on one screen at 1024×768; a filter would hide the groups a workshop is meant to sweep, and "bold the accurate causes" is a review-everything instruction.

### 4.3 Read-only presentation

Selected causes only, as a list grouped the same way, each with its note beneath in `text-gray-600`. Unticked causes are **not** rendered — a read-only view listing 19 things that are not true is unreadable. A group with nothing selected is omitted entirely.

Empty: "No cause is recorded for this scenario." — never a ticked-nothing checkbox grid, which would invite a reader to think the checkboxes were merely unchecked by the renderer.

---

## 5. Business-line allocation

`Components/Sm/AllocationEditor.jsx` (`01` §0.1.3).

### 5.1 Rows

Each row: a business-line picker, a percent input, a **Remove** button.

- Each row stores a `business_unit_id`; `sm_business_line_allocations` has no `business_process_id` (§A1.5 refused it, §2), so the picker offers **business units only** and needs no `kind` marker. Should the deferred process refinement arrive later, it arrives as a second, optional field on the row — not as a mixed list where the user has to know which tree a name came from.
- **Picker** — Headless UI `Combobox` over `allocation.options`. Search filters on `name`, case-insensitive substring. **Options already used by another row are removed from the list**, because §A1.5's unique key `sm_allocations_scenario_unit_unique (sm_scenario_id, business_unit_id)` makes a duplicate a database error, and the UI should make it impossible rather than merely rejected. When every option is used, the **Add a business line** button is absent and a `text-xs` line says "Every business line is already allocated."
- **Percent** — `<input type="number" min="0" max="100" step="0.01" inputmode="decimal">`, right-aligned, `tabular-nums`, visually suffixed with `%` outside the input (a `₦`-style absolute span, as `DynamicForm`'s `money` case does — never a placeholder, which is not a label). **0 to 100, up to 2 decimal places**, matching `decimal(5,2)`. A `0` allocation is allowed and meaningful ("this line is in scope but carries none of the impact") and is distinct from a row that does not exist.
- **Remove** — `text-gray-500 hover:text-red-700`, `aria-label="Remove {line label}"`.
- **Add a business line** — `btn-secondary`, below the rows.

### 5.2 The live sum

A line at the card's right, and the card's most important element:

| Condition | Rendered | Treatment |
|---|---|---|
| No rows | **"Not allocated"** | `text-gray-600`, plus §5.4's sentence |
| Sum of the rows' current percents **is exactly 100.00** | **"Adds to 100%"** with a `check_circle` icon | `text-green-700` |
| Sum is anything else | **"Adds to {sum}%"** with a `warning` icon, then "Business-line allocation must add to 100%." | `text-red-700` |
| A row's percent is empty | **"Adds to {sum}% — {n} {row|rows} still need a percentage"** | `text-red-700`. An empty percent is treated as *missing*, not as `0`, so the sum shown is never a lie about what has been entered |

**How the sum is computed — load-bearing.** Sum the values **after rounding each to 2 decimal places**, and compare the rounded total to `100.00` — the same rule §A1.5 imposes on the service, which compares "as decimals at the column's precision (string/`bccomp`, never float `==`)". Percents arrive as **decimal strings** (`01` §4.5), so the client should sum them as scaled integers (parse to cents: `Math.round(Number(p) * 100)`, total the integers, compare to `10000`) rather than adding floats. Summing raw floats and comparing to `100` gives the classic `33.33 + 33.33 + 33.34 = 100.00000000000001` and `0.1 + 0.2 ≠ 0.3` failures, and a screen that says "Adds to 100%" while the server returns 422 on `allocations` destroys trust in both.

**When the sum is enforced — and this is the part that is easy to get wrong.** §A1.5: the sum-to-100 rule is enforced "at the **`in_design → designed` transition** and not on every draft save — a draft legitimately sums to 60% mid-entry, and refusing to save it pushes users into one big form."

So on this tab the ≠100 state is **advisory, not blocking**:

- **Save allocation** succeeds with any sum, including an incomplete one. The sum line shows the mismatch in red so the user can see it, but the button is a normal, enabled `btn-primary`.
- The block happens at the **Design complete** transition, where the shell's gate pre-flight (`01` §5.4) lists "Business-line allocation adds to {sum}%" as a missing item and the modal's confirm control is `aria-disabled`.
- The server still answers **422 on key `allocations`** if a transition is attempted with a bad sum, and the tab renders that error on the allocation card.

A screen that refused to save 60% would be enforcing a stricter rule than the server has — which is its own kind of lie, and the one that pushes designers back to the spreadsheet mid-workshop.

The sum is a **derived figure with named inputs** (the rows' percent values), which standard §5 permits. It is not a measurement of anything in the world, and it is labelled as an arithmetic statement, not a metric.

### 5.3 Removing a row

**Remove is immediate and undoable, with no confirmation modal.** Removing a row destroys no data until Save: the row is kept in component state and a `role="status"` line replaces it for the rest of the session —

> "Removed Retail Banking (40%). **Undo**"

**Undo** restores the row at its original position with its percent. A modal for a reversible, one-field action would be the third dialog in a workshop session and users would start clicking through them.

### 5.4 The empty allocation

When there are no rows: the rows area shows a dashed-border block (`border-dashed border-gray-300 p-6 text-center`):

> **Not allocated**
> No business line carries this scenario yet. Add the lines it would hit and the share of the impact each would carry.
> **[ + Add a business line ]**

and the sum line reads **"Not allocated"**.

**It never reads "0%".** Standard §5: a rate over nothing is undefined, not zero, and `?? 0` is permitted only where zero is a true statement about an empty set. "0% allocated" is a claim that the scenario has been allocated and nothing landed anywhere — a different and false statement, and exactly the class of figure the audit behind §5 found rendered as a green tile. The read-only presentation of an empty allocation is the same two words, **"Not allocated"**, so a validator reading a signed-off scenario sees the gap rather than a number that reads as an answer.

### 5.5 No equal-split button

Deliberately absent. Splitting three rows into `33.33 / 33.33 / 33.34` would assert a distribution nobody stated — a fabricated figure in the exact sense of standard §5 — and it would need a rounding decision (which row gets the extra 0.01, and on what principle) that no UI should make silently. It also does not solve the user's problem: the sum rule is about the *real* distribution, and a designer who wanted an even split would notice it is wrong the moment they read it back.

### 5.6 Three save regions, not one Save button

§A1.11 gives this tab **three** write routes, so it has three save regions, each with its own button, dirty state and last-saved time (`01` §6.1, §6.3):

| Card | Button | Route | Body |
|---|---|---|---|
| Causes | **Save causes** | `PUT {scenario}/causes` | `{causes: {[code]: {note}}}` |
| Business-line allocation | **Save allocation** | `PUT {scenario}/allocations` | `{allocations: [{business_unit_id, percent}]}` |
| Exposure + Risk transfer | **Save exposure answers** | `PUT {scenario}` | the five exposure columns only |

**Why three rather than one Save that fires three requests:**

1. **A partial failure stays honest.** One Save issuing three PUTs can succeed on causes and 422 on allocations. The truthful report is per-region, which is exactly what three regions give for free — and "Nothing was saved" (`01` §6.2) would be a lie.
2. **It matches the workshop.** Causes are ticked by the people in the room; allocation percentages are often negotiated afterwards with finance. Independent saves mean one person's unfinished work does not hold the other's hostage — the same reasoning §A1.5 gives for not enforcing the sum on every draft save.
3. **It is cheaper on a bad link.** Three ~1 KB writes, each retriable alone, beat one 3 KB write that must be retried whole (§6.4 of `01`: a `PUT` is safe to re-send).

The two lower cards (**Exposure**, **Risk transfer**) share one button because they are one route — the five columns all live on `sm_scenarios`. They are drawn as two cards for reading order (a scale question and then three yes/no questions) but save together, and the button sits under the second card with the label naming both: **Save exposure answers**.

Each button sits at the bottom-right of its card, `btn-primary`, with the "Last saved {hh:mm} Lagos time" line beside it. The tab has no page-level Save, and must not grow one: a single button that silently writes three endpoints reintroduces problem 1.

**`PUT {scenario}` must be a partial update** (`01` §6.1). This region posts five keys and must leave `background`, `rationale`, `name` and every governance field untouched — the Overview tab owns those, and this tab never loads them. A whole-object write from here would null the Overview's fields with a 200 response.

---

## 6. Exposure scales

### 6.1 The control, and why the unset state is a real radio

Each scale is a `fieldset` with a `legend` (`h3` inside) and a vertical list of radios — **all options visible**, not a `select`. A six-point scale with a sentence of description per point cannot be chosen from a closed dropdown: the descriptions are the scale, and hiding five of them behind a click invites a user to pick "Moderate" because it sounds middling.

**The first radio is the unset state** (`unset_label`, posting `null`):

- Reputational: **"Impact: not yet assessed"** — the workbook's own string.
- Legal: **"Not yet assessed"**.

**Why an explicit unset radio rather than simply leaving nothing selected.** A native radio group cannot be un-selected: a user who ticks "No impact" by mistake has no way back to "I haven't answered this" except reloading and losing the rest of the tab. Without an unset option, a mis-click silently converts an unanswered field into an affirmative assessment — the precise inversion standard §5 forbids, and worse here because "No impact" is the answer that makes a scenario look safe. The unset radio is also what lets the read-only view and the sign-off warnings distinguish *unanswered* from *answered no*.

Each graded radio renders its `label` in `font-medium` and, when `description` is non-null, the description on the next line in `text-sm text-gray-600`, associated with the radio by `aria-describedby`. **When `description` is null no description line renders** — no placeholder, no invented sentence.

Keyboard: native radio behaviour — arrows move within the group and select, `Tab` enters and leaves the group as one stop. Screen reader: "Reputational impact, radio group, 7 options, Moderate selected, 4 of 7. Concerns becoming broader and more vocalised within the industry."

### 6.2 Reputational impact — seeded options

Seven radios: the unset state plus six affirmative answers. Descriptions verbatim from `VOCABULARY-EXTRACTION.md` (workbook dropdown `[55,13]–[61,13]`).

| Order | `value` | `label` | `description` |
|---|---|---|---|
| 1 | *(null)* | Impact: not yet assessed | — *(no description line)* |
| 2 | `no_impact` | No impact | — |
| 3 | `insignificant` | Insignificant | Minor isolated stakeholder concerns / impacts |
| 4 | `minor` | Minor | Serious segmented stakeholder concerns/incidents |
| 5 | `moderate` | Moderate | Concerns becoming broader and more vocalised within the industry |
| 6 | `major` | Major | Negative public exposure with significant impact |
| 7 | `severe` | Severe | Dramatic loss of stakeholder confidence undermining business viability. Extensive negative public exposure |

⚠ **Two divergences, flagged not silently resolved** (the extraction file's own instruction):

1. **This is a seven-option control, not a six-point scale.** Plan §4.2 says "reputational impact (None → Severe 6-point scale)". The workbook separates **"No impact"** — an affirmative answer — from **"Impact: not yet assessed"** — the unset state. Modelling it as six points would collapse the two and force an unanswered field to display as "None", i.e. as a clean bill of health. The extraction is the evidence; the freeze should record **5 graded levels + `no_impact` + NULL-as-not-yet-assessed**.
2. **The Severe description is longer than the brief's.** The workbook's cell continues past "undermining business viability" with "Extensive negative public exposure". The full string is used.

### 6.3 Legal, regulatory and compliance risk — seeded options

Seven radios: the unset state plus six affirmative answers.

| Order | `value` | `label` | `description` |
|---|---|---|---|
| 1 | *(null)* | Not yet assessed | — |
| 2 | `no` | No | — |
| 3 | `insignificant` | Insignificant | — |
| 4 | `minor` | Minor | — |
| 5 | `moderate` | Moderate | — |
| 6 | `major` | Major | — |
| 7 | `severe` | Severe | — |

⚠ **Divergence, flagged.** The task brief for this spec supplied the five graded descriptions ("Minor isolated stakeholder concerns" and so on) for the legal scale as well. **Those strings are the reputational dropdown's**; the workbook's legal/regulatory/compliance dropdown (`[64,13]–[69,13]`) is a bare list of six labels with no descriptions. Copying the reputational descriptions onto the legal scale would put words in the artefact's mouth about *legal* exposure that it never says — a stakeholder-confidence sentence is not a statement about litigation or regulatory penalty. **The legal scale ships labels only**, with `description: null`, and a tenant that wants descriptions configures them. If the coordinator has a legal-scale description set from elsewhere, it belongs in the seeder, and this table changes to match it.

Field label: **"Legal, regulatory and compliance risk"**, help text "Exposure to litigation, regulatory action and compliance failure arising from this scenario."

### 6.4 The three yes/no risk questions — two of them are three-state, one is not

Labels:
- `insurance_risk` — **"Insurance risk"**, help text "Does this scenario give rise to an insurance claim or a risk-transfer question?"
- `financial_risk` — **"Financial / market risk"**, help text "Does this scenario carry a financial or market-risk exposure as well as an operational one?"
- `eligible_to_transfer` — **"Eligible to transfer"**, help text "Could the impact of this scenario be transferred under cover the organisation holds or could buy?"

**`insurance_risk` and `financial_risk` are three-state radios:** Yes · No · **Not assessed** (the third posting `null`). §A1.2 makes both columns **nullable booleans** precisely so the unset state survives: the workbook's dropdowns are Yes/No *and* offer a blank ("unset = NULL", extraction), and `->default(false)` "would render 'not yet assessed' as 'No' on a governance screen". §A1.2 also confirms the cast is safe — a nullable boolean under Laravel's `boolean` cast reads back `null`, not `false`, because `boolean` is in `HasAttributes::$primitiveCastTypes` — so `?bool` is real and standard §5's "absent, not zero" survives the model layer.

⚠ **`eligible_to_transfer` is two-state: Yes · No. It has no "Not assessed" option.** §A1.2 makes it **NOT NULL default `false`**, on two grounds: its workbook dropdown is only `0`/`1` with no unset state, and Phase 2's netting formula (`transferable = Σ type_total × eligible_flag`, spike §4) "needs a value — NULL would be a third case in a two-case formula".

This **overrules** the earlier draft of this spec, which specified a three-state radio here and argued that a toggle silently reads "off = no". The argument was right about the risk and wrong about the remedy: the schema has no third state to render, and a UI that offered one would be collecting a value it cannot store. So:

- The control is a **two-option radio** (Yes / No), never a `Switch` — a switch still reads "off = no" and hides that the field was answered rather than defaulted.
- On a new scenario it renders as **No**, because that is the column's default and the truth about what is stored.
- It carries a permanent help line, `text-xs text-gray-600`, that says what the default means: **"Defaults to 'No'. If nobody has checked the cover yet, record that as an assumption in the notes log — Phase 2's netting engine will treat 'No' as 'nothing is transferable' and compute a fully net impact."**
- The sign-off modal repeats the point as a warning whenever the value is `false` (`01` §5.6.5), because that is the last moment a signer can notice that an unchecked field is about to become a computed zero.

That help line is the honest mitigation available: the field cannot say "unanswered", so the UI must say what "No" will be taken to mean, and put it in front of the person who can still do something about it. **The consequence to record for Phase 2:** a scenario where nobody considered transferability is indistinguishable in the data from one where cover was checked and there is none, and the netting engine will treat both as `transferable = 0`. If that ever matters to a customer, the fix is a nullable column in a later amendment, not a client-side third state.

Under all three fields, one `role="note"` line:

> "'Not assessed' is recorded as unanswered for insurance risk and financial/market risk. It is never treated as 'No'. Eligible to transfer has no unanswered state — it defaults to No, so say so in the notes if nobody has checked the cover."

Both those promises bind consumers beyond this screen: **Phase 5's Excel import** must read a blank workbook cell as `null` for the two nullable flags and as `false` for `eligible_to_transfer` (matching the workbook's own `0`/`1`), and **Phase 2's engine** must never coerce the two nullables.

**Two reconciliations with `config/sm.php`, which shipped against an earlier draft of this section:**

1. `signoff.warning_messages` carries an **`eligible_to_transfer_not_assessed`** code. It is **unreachable** — the column is NOT NULL, so there is no unanswered state to warn about, and its wording ("cannot treat an unanswered field as 'no'") describes the three-state design this section no longer has. It should be dropped, or repurposed to the warning §6.4 actually specifies: *"Eligible to transfer is 'No'. If nobody checked the cover, record that as an assumption before signing."* — which is a true statement about a real risk, unlike a warning about a state the schema forbids.
2. The same map has **no `financial_risk_not_assessed`**, though `financial_risk` *is* nullable and `01` §5.6.5 warns on it. Add it, or the sign-off modal silently omits one of the two flags it should surface.

Neither changes this spec's screens; both change what the sign-off modal can truthfully say, which is why they are written down here rather than left to code review.

### 6.5 Read-only presentation

Each scale renders as one line: the label in `font-medium` and, when there is a description, " — {description}" after it. `null` renders the `unset_label` in `text-gray-600` — for the reputational scale that is the workbook's own **"Impact: not yet assessed"**, for the legal scale **"Not yet assessed"**. Never the lowest grade, never `—` (which would lose the distinction between "unanswered" and "not rendered"), never "None".

`insurance_risk` and `financial_risk` render "Yes" / "No" / **"Not assessed"**, the last in `text-gray-600`.

`eligible_to_transfer` renders **"Yes"** or **"No"** — there is no third state (§6.4). Because "No" here covers both "checked and there is no cover" and "nobody checked", the read-only line carries a trailing qualifier when the scenario has no note of kind `assumption` or `challenge` mentioning transfer: `text-xs text-gray-600`, "(no note records how this was determined)". That is not a fabricated fact — it is a true statement about the notes log — and it is the only place a reader of a signed-off scenario can see that the answer may be a default rather than a decision.

---

## 7. Requiredness by lifecycle status

**§A1.8's frozen guard on `in_design → designed` requires exactly two things from this tab:** at least one selected cause, and an allocation summing to `100.00`. (Its other two requirements — a non-null `risk_taxonomy_node_id`, and non-empty `background` and `rationale` — are `02` §4's.) The guard is enforced **at the transition, not on save** (§5.2), and surfaced through `gate` (`01` §5.4).

| Field | `draft` | `in_design` | at the **`designed`** gate | `in_validation`+ |
|---|---|---|---|---|
| Causes | optional | optional | **≥ 1 selected (§A1.8)** | read-only |
| Allocation | optional | optional | **≥ 1 line summing to exactly 100.00 (§A1.8, §A1.5)** | read-only |
| Note on a `requires_note` cause | required when that cause is selected | ← | ← | read-only |
| `reputational_exposure` | optional | optional | **not gated** | read-only |
| `legal_compliance_exposure` | optional | optional | **not gated** | read-only |
| `insurance_risk` | optional | optional | **not gated** | read-only |
| `financial_risk` | optional | optional | **not gated** | read-only |
| `eligible_to_transfer` | defaults `false` | defaults `false` | **not gated** — and cannot be unanswered (§6.4) | read-only |

**None of the five exposure fields is gated, and the UI must not gate them.** An earlier draft of this spec required the two scales and both nullable flags at the gate, on the reasoning that a field with an affirmative "no" answer can always be answered. §A1.8 does not list them, so requiring them would block a transition the server allows — a screen that enforces a stricter rule than its backend is a screen that lies about the rules, and it would strand designers the freeze deliberately left unblocked. They are instead surfaced as **warnings in the sign-off modal** (`01` §5.6.5), which is where a signer can act on them. If the coordinator wants them gated, that is an amendment to §A1.8, after which `gate` carries them and this table changes.

**Messages** — and these must be the Form Request's messages, not client-side paraphrases, because standard §4 puts validation in Form Requests and a message that exists only in JSX is a message a non-JS 422 or a stale tab will not carry:

| Rule | Message |
|---|---|
| No cause selected, at the gate | **"Select at least one cause. A scenario with no stated cause can't be validated."** |
| No allocation lines, at the gate | **"Allocate this scenario to at least one business line."** |
| Sum ≠ 100.00, at the gate (422 key **`allocations`**, §A1.5) | **"Business-line allocation adds to {sum}%. It must add to exactly 100%."** |
| A row's percent empty, at the gate | **"Enter a percentage for {line label}, or remove the line."** |
| `causes.{code}.note` empty on a `requires_note` cause | **"Say what the other cause is — 'Other' on its own tells a validator nothing."** |
| `allocations.{i}.percent` out of range | **"A share must be between 0 and 100."** |
| `allocations.{i}.percent` more than 2 dp | **"Use at most 2 decimal places."** |
| `allocations.{i}.business_unit_id` duplicated (unreachable — §5.1 removes used options) | **"{line label} is already allocated."** |
| `reputational_exposure` / `legal_compliance_exposure` not in the const | **"That isn't a value on this scale."** (unreachable from the UI; it exists for a stale payload) |
| A cause `code` not in `ScenarioCause::CHECKLIST` | **"That isn't one of this organisation's causes."** — §A1.8: "a code not in it is a 422" |

The gate-blocking messages appear in the **Design complete** modal's checklist (`01` §5.4), not on Save — because Save succeeds with a 60% allocation, by design (§5.2).

---

## 8. States

| State | Trigger | What the tab shows |
|---|---|---|
| **Loading** | Inertia visit to the tab | The progress bar. Props are synchronous; no skeleton |
| **Empty (nothing answered)** | `causes.selected` empty, `allocation.lines` empty, both scales `null`, both nullable flags `null` | The full tab in its initial state: every checkbox unticked, the allocation card in its §5.4 dashed "Not allocated" block, both scales on the unset radio, `insurance_risk` and `financial_risk` on "Not assessed", and `eligible_to_transfer` on **"No"** — which is the column's default, not an answer somebody gave, and is labelled as such by §6.4's help line. **No red, no error, no warning.** This is a scenario somebody has just opened, not a form that has been failed. The only readiness signal is the shell's readiness strip (`01` §5.4), which is a note and not an alarm |
| **Partial** | the normal state mid-workshop | Causes ticked with some notes empty; two allocation rows and a third percent blank; reputational answered, legal not. Each field renders its own truth: the sum line says "Adds to 75.5% — 1 row still needs a percentage", the unanswered scale sits on its unset radio. **Saving in this state succeeds** (§5.2) — a draft that sums to 60% is a legitimate draft. **No field shows `0` or "None" for an absence** |
| **Populated** | everything answered | §3 |
| **Sum valid / invalid** | §5.2 | The sum line's two treatments. Invalid does **not** disable Save allocation |
| **Read-only** | status ∈ {`in_validation`, `validated`, `signed_off`} | Every control replaced by text: the selected-causes list (§4.3), the allocation as a two-column `dl` of line → share with the total beneath ("Total {saved_total}%") or **"Not allocated"** when `saved_total` is `null`, each scale as one line (§6.5), each flag as one word. **No disabled inputs and none of the three Save buttons** (`01` §5.5, `02` §9) |
| **Read-only after sign-off** | `scenario.frozen` | As above, under the frozen banner. The allocation total shown is the one recorded at sign-off; nothing recomputes (`01` §5.5) |
| **Archived** | status `archived` | Read-only, plus the shell's archived note |
| **Permission denied (403)** | no `scenario.view` | The 403 page. A `scenario.view` holder without `scenario.update` gets the read-only presentation with no permission commentary (`02` §9) — note that `scenario.note` is irrelevant here, since the notes log lives on the Overview tab |
| **422 on save** | validation | `01` §6.2 — a summary at the top of **the card that failed**, with one linked entry per error; per-field errors with `aria-invalid` and `aria-describedby`; "Nothing was saved. Your entries are still on this page." The other two regions are untouched and still dirty. `allocations.{i}` errors map by **posted index** (§2) |
| **422 on the `designed` transition** | guard failed (§A1.8) | The shell's gate modal already blocks it client-side (`01` §5.4); if the server refuses anyway (a stale page), the flash renders as a `role="status"` banner and the relevant card shows the `allocations` error. Not a 403 — §A1.8 follows `LossEventController::updateStatus()`: "an illegal target answered with `back()->with('error', …)`" |
| **Network loss / 419 / 403 on save** | — | `01` §6.3, §6.4 — entries kept per region, `sessionStorage` backups under `sm-scenario-{id}-{causes|allocations|exposures}`, **Copy my entries** |
| **Checklist not configured** | `causes.checklist` empty | The Causes card renders no checkboxes and a `role="note"` amber callout: "The cause checklist isn't configured for your organisation. The scenario can be saved, but it can't be marked designed until it is." A real go-live gap stated plainly, not an empty grid that reads as "no causes apply" (`01` §0.5). Unlikely in practice — §A1.8 freezes the 23 codes as a PHP const, so an empty list means a presenter bug, and the callout is how that bug becomes visible instead of silent |
| **No business units exist** | `allocation.options` empty | The allocation card shows the same amber callout pattern: "No business units are available to allocate to, so this scenario can't be allocated." plus a link to the relevant admin screen **only if** the shell sends a URL for it; otherwise the sentence stands alone with no dead link. §A1.5's unique key and NOT NULL `business_unit_id` mean there is no allocation without a unit |
| **Degraded network** | 100 kbps | §10 |

---

## 9. Accessibility (WCAG 2.1 AA)

- **Headings:** the shell owns `h1`. This tab's four card titles are `h2` — Causes, Business-line allocation, Exposure, Risk transfer. Each scale's `legend` is an `h3`, and the cause group headers (People / Processes / Systems / External) are `h3`. Nothing skips a level.
- **Causes** — a real `<fieldset>`/`<legend>` per group, checkboxes with visible `<label for>`. The group count is `aria-live="off"` and read on demand via `aria-describedby` on the group's `fieldset`, so ticking six boxes in a row does not produce six announcements. The revealed note field is `aria-label`ed with the cause name, because a bare "Note (optional)" repeated eleven times is useless in a list.
- **Allocation** — the rows are a `role="table"`-free plain list of labelled controls (a real `table` would be defensible but the picker + input + button combination reads better as a labelled group). Each row is `role="group"` with `aria-label="{line label}, {percent}%"` — updated on blur, not per keystroke. The picker's Combobox semantics are as `02` §10. The percent input's label is "Share of {line label} (%)" and is **visible** above the input at `xs` widths and `sr-only` beside it at `md` and up, where the row's own picker supplies the context visually.
- **The sum line** is `id`-referenced by the allocation card's Save button's `aria-describedby`, so a screen-reader user who tabs to it hears "Save allocation — Adds to 75.5%, business-line allocation must add to 100% to be marked designed". It is **not** an `aria-live` region: announcing on every keystroke of a numeric field makes the tab unusable. The one live announcement is the **valid ↔ invalid transition**, debounced to blur: a `role="status"` region that speaks "Allocation adds to 100%" or "Allocation adds to {sum}%; it must add to 100% before this scenario can be marked designed" only when the state changes, not when the number does.
- **Scales** — `fieldset` + `legend`, native radios, arrows within the group, one Tab stop per group (§6.1). Descriptions are `aria-describedby` on each radio, so the accessible name of option 5 is "Moderate — Concerns becoming broader and more vocalised within the industry".
- **The flags** — same construction. `insurance_risk` and `financial_risk` are `Yes`/`No`/`Not assessed`; `eligible_to_transfer` is `Yes`/`No` with its help line attached to the group's `aria-describedby` so a screen-reader user hears "Defaults to No; if nobody has checked the cover, record that as an assumption" as part of the group, not as stray text. "Not assessed" is never rendered as a disabled or dimmed option; it is a real answer with the same visual weight, because dimming it would push users toward guessing.
- **Not colour alone:** the sum line's icon and words carry the state; the scale selection is the radio's own state; the flags are words. No meaning is encoded in colour alone anywhere on this tab.
- **Contrast:** descriptions and help text `text-gray-600` (≈7.6:1); the `text-gray-500` floor (≈4.8:1); `#718096`/`#9CA3AF` are not text colours (`01` §0.3). Radio outlines use `border-gray-500` or darker for 1.4.11 non-text contrast, and every focused control gets the 2px Navy ring.
- **Focus management:** after a region's Save, focus stays on that button. After a 422, focus goes to that card's error summary heading, and the summary's links jump to fields (`#field-{cause code}`, `#allocation-row-{key}`, `#field-reputational_exposure`). After **Undo**, focus returns to the restored row's percent input. After removing the last row, focus moves to **Add a business line** — never to `body`.
- **Reflow:** single column at 320 px; the cause columns stack below `lg`; the allocation row becomes picker / percent / Remove stacked with the button full width; no horizontal scroll. Percent inputs are ≥16 px font on mobile so iOS does not zoom.
- **Reduced motion:** nothing animates beyond the Inertia progress bar.

---

## 10. Low bandwidth (100 kbps)

- **One payload, once.** The checklist is 23 rows × ~90 B ≈ 2 KB. Business-unit options are the only scaling risk, and §A1.3 bounds it: `business_units` are departments and divisions (BU-IT, BU-IA, BU-LG, BU-CX, BU-PB, BU-OP in the demo seed), so tens — 100 × ~40 B ≈ 4 KB. If a tenant ever carries thousands, the picker becomes server-searched like `02` §5.7's risk Combobox — flagged in §12, not built now, because nothing today needs it.
- **Both scales and all three flags are in the same payload**, so the whole tab is answerable with no further requests.
- **Everything computes locally.** The sum, the group counts and the valid/invalid state are browser arithmetic over values already on the page — typing works with the connection fully down. There is no server round-trip per row and no revalidation on blur.
- **No autosave, and three small explicit writes** (§5.6): causes ~1 KB, allocation ~0.5 KB, exposures ~0.2 KB. Each is independently retriable, which on a degraded link is the difference between re-sending 200 bytes and re-sending the lot. `sessionStorage` backups are per region, debounced 1 s (`01` §6.3).
- **Nothing is deferred and nothing polls.** No chart, no image, no extra font, no below-the-fold fetch.
- **In flight:** "Saving…" with `aria-busy` on the button that was pressed, and only that card's controls disabled — the other two regions stay usable while one write is in flight, so a slow save on the allocation cannot block a workshop from continuing to tick causes. After 15 s the slow-connection line (`01` §0.4).

---

## 11. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| "{n} selected" (causes) | Count of keys in `causes.selected` — a count of a set, so `0` is true and is printed | Never absent |
| "{a} of {b} selected" (group) | Count within the group / `checklist` rows in that group | Never absent |
| Allocation share per row | `allocation.lines[].percent` | The input is empty; the row is counted as *missing*, not as `0` (§5.2) |
| "Adds to {sum}%" | Sum of the rows' current percent values, each rounded to 2 dp, compared to `100.00` (§5.2). A derived figure whose inputs are named | **"Not allocated"** when there are no rows. Never `0%` |
| "Total {n}%" (read-only) | `allocation.saved_total`, the server's sum of the saved lines | **"Not allocated"** when `null`. Never `0%` |
| Reputational / legal exposure | `exposures.reputational_exposure.value` / `.legal_compliance_exposure.value` against their `options` | The unset radio is selected; read-only renders `unset_label` ("Impact: not yet assessed" / "Not yet assessed"). Never the lowest grade, never `—` |
| Insurance risk / financial risk | `exposures.insurance_risk`, `.financial_risk` — nullable booleans | **"Not assessed"**. Never "No" |
| Eligible to transfer | `exposures.eligible_to_transfer` — **NOT NULL boolean, no unset state** (§A1.2, §6.4) | Renders "No", which is the stored default. The help line and the read-only qualifier are what stop that reading as a decision nobody made |
| **Deliberately absent: a qualitative severity score or band** | — | Nothing in Phase 1 computes a score from these fields, and no weighting of the scales exists in the artefact. A "qualitative rating" tile here would be a fabricated figure (standard §5). Severity enters the module in Phase 2 as the Typical/Serious/Extreme anchors, computed by `ScenarioComputationService` |
| **Deliberately absent: an allocated amount** | — | There is no impact amount in Phase 1 to allocate. The percentages are shares, and the screen never multiplies them by anything |
| **Deliberately absent: an equal-split figure** | — | §5.5. Splitting would invent a distribution nobody stated |

---

## 12. Known gaps (also in the HANDOFF)

1. **The allocation cannot be sorted** (§2), because `allocations.{i}` error keys index the posted array. A drag-to-reorder needs errors keyed by `allocation.lines[].key` instead. Cheap to change now, expensive after Phase 1 ships.
2. **~~No "impacted activities" field.~~ Ruled: not in Phase 1** (coordinator, 10 Nov 2026). The workbook's separate free-row list ("HR" with an "R" marker) has no table in §A1.1's five-table boundary, and this spec invents none. If it returns, it is a schema-freeze item with its own screen, not a UI addition here.
3. **The cause checklist has no tenant-editing surface in Phase 1, and §A1.8 makes it a frozen PHP const.** Plan §4.5 screen 7 (Admin) covers "cause checklist taxonomy" and is not in Phase 1, so a tenant needing a 24th cause needs a code change — and `sort_order` on `sm_scenario_causes` exists precisely so a later extension "cannot reshuffle a signed-off scenario's qualitative report". `requires_note` in particular is const configuration with no UI.
4. **`eligible_to_transfer` cannot express "unanswered".** §A1.2 made it NOT NULL default `false` for a sound reason — Phase 2's netting formula is two-case — and §6.4 mitigates it with a help line, a sign-off warning and a read-only qualifier. But the mitigation is *disclosure*, not *data*: a scenario nobody assessed is indistinguishable in the database from one assessed as ineligible. If a customer's auditors ask the difference, the fix is a nullable column in a later amendment. **Recorded so nobody discovers it during a Phase 2 netting review.**
5. **Business-unit options are shipped whole.** Fine for tens (§10, §A1.3). Recommend the backend cap the option list at 500 and send a `truncated: true` flag the UI can turn into "Too many business lines to list — search instead", rather than letting the payload grow silently.
6. **No linkage from a cause to a frequency driver.** Plan §4.2 gives `sm_frequency_drivers` a "cause type", and the workbook feeds a driver from a cause. Phase 2 will want to know which ticked cause a driver row belongs to; `causes.checklist[].code` is the only stable handle, and §A1.8's frozen codes are what makes that possible. Flagged so the Phase 2 designer does not re-key the checklist.
7. **`is_selected = false` rows are not creatable from this screen.** §A1.4 gives `sm_scenario_causes.is_selected` a default of `true` but says the column "exists so 'considered and rejected, see note' is expressible — which ISO 31010's document-your-assumptions rule needs". Phase 1's UI is binary (ticked = a row, unticked = no row), so that capability is stored-but-unreachable. It belongs to Phase 3's workshop capture; if it is wanted in Phase 1 it needs a third state per cause and this spec's §4.2 rewritten.
8. **`exposures.*.value` is a string, not an ordinal.** Sorting scenarios by reputational exposure, or any future aggregation, needs the option order — which lives in `options[]` array order and nowhere else. §A1.8's consts define the order, so the server can supply it; the UI must not assume it. Not a UI problem, but worth saying before somebody sorts on the string and gets "major" before "minor".
