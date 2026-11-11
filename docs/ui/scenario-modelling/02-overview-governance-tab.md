# 02. Scenario workspace — Overview & Governance tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=overview` (the default) · **Route:** `GET /risk/scenarios/{scenario}?tab=overview` (`scenario.show`) · **Writes:** `PUT /risk/scenarios/{scenario}` (`scenario.update`) — a **partial** update, `01` §6.1 · `POST /risk/scenarios/{scenario}/notes` (`scenario.notes.store`, **`permission:scenario.note`**) · **Phase:** 1

Read `01-workspace-shell.md` first: the status-colour table (§2), the permission→action table (§3), the shared states (§0.2), the accessibility baseline (§0.3), the low-bandwidth rules (§0.4) and the no-fabricated-numbers rule (§0.5) all apply here unchanged and are not repeated.

**Authority:** ADR 0027 Amendment 1 — §A1.2 (frozen `sm_scenarios` columns; the field codes below are those column names), §A1.3 (the owning unit is `business_unit_id`; `entity_id` was refused), §A1.6 (sign-off rows), §A1.7 (notes), §A1.8 (vocabularies as consts, and the `in_design → designed` guard), §A1.9 (the OP taxonomy tree), §A1.11 (routes and permissions). Vocabulary strings are verbatim from `plans/scenario-modelling/VOCABULARY-EXTRACTION.md` via §A1.8's const tables.

---

## 1. Purpose and the user

**Who.** The designer, at the start of a session: this is the tab they land on and the one that decides whether the scenario is a governable object or a rumour. Later, the validator and the signer, who read this tab before anything else, because it says who owns the scenario, which entity and business lines it belongs to, which Basel event type it maps to, and when it was designed and validated.

**In the first ten seconds.** The reference, the name, the status, whether it is frozen, and what the lifecycle allows next — all of which are the shell's job (`01` §5). This tab then has to answer: *is this scenario properly identified and governed?*

**Must be able to.** Name and classify the scenario; map it to an OP event type; set its nature, weighting, entity and currency; link it to a risk-register entry; write the background and the rationale; set the design, validation and review dates; read who signed it; add a note to the assumptions/dissent/challenge log.

**Design stance.** Long-form, revisited over weeks, often by more than one person. It is a **document that happens to render in a browser** for the validator and the signer — an examiner should be able to find one fact without asking anybody. That argues for labelled rows in a fixed order over a dense two-column form, and for the read-only presentation being as carefully designed as the edit one.

---

## 2. Why this tab is not a `DynamicForm`

Standard §8 says create/edit/show of a **configurable** object uses `FormSchemaPresenter` + `DynamicForm`/`DynamicDetail`. A scenario's governance fields are not that: they are fixed columns on `sm_scenarios` with cross-field rules, and the module's genuinely configurable vocabularies (cause checklist, exposure scales, data sources) are **vlists delivered as options**, not object-type schemas — ADR 0027 D3 says so explicitly. Four concrete blockers, each verified against the component:

1. **`DynamicForm` cannot render a relationship picker.** `case 'object_ref'` falls into the numeric branch and renders `<input type="number">` (`packages/ui/src/Components/DynamicForm.jsx:278-291`). Four fields here are foreign keys — `business_unit_id`, `risk_taxonomy_node_id`, `fx_rate_id`, `risk_id` — and a form that asks a risk officer to type a foreign key is not a form.
2. **`DynamicForm` cannot express "not answered".** `case 'bool'` renders one `Checkbox` labelled "Yes" and initialises it with `truthy(value)` (`DynamicForm.jsx:252-260`), so `null` and `false` render identically. That is the exact defect standard §5 names — absent read as zero — and it is why the three yes/no fields on `03` are bespoke radios.
3. **Requiredness here varies by lifecycle status**, and a schema is built per object type, not per state. §4's table is the contract; the server enforces it.
4. **`case 'text'` is a fixed `rows={3}` textarea** (`DynamicForm.jsx:229-239`). A scenario's background and rationale run to several hundred words.

So the tab is hand-composed from the same primitives `DynamicForm` uses — `FormField`, `InputLabel`, `InputError`, `Checkbox` — and the read-only state is a `dl` following `DynamicDetail`'s presentation (label above value, `—` for absent). **The field codes, option sources, requiredness and messages in §3–§5 are the contract either way**: if the backend would rather ship a schema, `frontend-engineer` needs `DynamicForm` to grow a combobox type, a three-state bool and per-state requiredness first, and those three changes would benefit every module. That is a conversation for the coordinator, not a quiet deviation.

**Field codes below are the contract.** They are the `name` attributes on the wire, the keys in the 422 error object, and the anchors `gate.missing[].field` points at (`01` §5.4). Each renders with `id="field-{code}"` and `scroll-margin-top` so a gate link can focus it.

---

## 3. Layout

Container `max-w-4xl`. Eight cards in this order — Identity, Classification, Currency, Linked risk, Background & rationale, Dates & review, Sign-off, Notes log — each `bg-white rounded-xl border border-gray-200`, header `px-5 py-4 border-b border-gray-100`, title `text-sm font-semibold text-[#1A365D]` as an `h2`.

*Every reference, name, date, rate and note in this sketch is sample data (`01` §0) — invented to show the shape of the screen, not a claim about any tenant.*

```
┌── Identity ─────────────────────────────────────────────────────────────────┐
│ Reference          SM-2026-014   (read-only, mono)                          │
│ Scenario name *    [___________________________________________]            │
│ Business unit      [ Choose a business unit…           ▾ ]                   │
│   The unit that owns this scenario. Group/standard/local is set by Nature.   │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Classification ───────────────────────────────────────────────────────────┐
│ OP event type *    [🔍 OP5 Loss of key staff/talent management        ▾]     │
│                      └ grouped by Basel Level-1 where the node carries one   │
│ Nature of scenario [ Standard scenario                 ▾ ]                  │
│ Weighting factor   [ Medium-high                       ▾ ]                  │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Currency ─────────────────────────────────────────────────────────────────┐
│ Currency           [ NGN  ▾ ]      Base currency for this tenant: NGN       │
│ Exchange rate      [ 1 GBP = 1 210.44 NGN, effective 01 Nov 2026  ▾ ]       │  ← only when
│                    └ "No GBP rate is on record." when the list is empty       │     currency ≠ base
└─────────────────────────────────────────────────────────────────────────────┘
┌── Linked risk ──────────────────────────────────────────────────────────────┐
│ Risk register entry [🔍 type to search the register…            ▾]           │
│   RSK-0142 · Failure to retain critical talent      [Remove]                │  ← when linked
└─────────────────────────────────────────────────────────────────────────────┘
┌── Background & rationale ───────────────────────────────────────────────────┐
│ Background and context *  [ 8-row textarea ______________________ ]         │
│ Why this scenario was designed *  [ 8-row textarea _____________ ]          │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Dates & review ───────────────────────────────────────────────────────────┐
│ Date of design      [ 2026-11-10 ]   Date of validation [ — stamped by      │
│                                                             the server — ]   │
│ Next review due     [ 2027-11-10 ]   Suggested: 10 Nov 2027 (annual cadence) │
└─────────────────────────────────────────────────────────────────────────────┘
┌── Sign-off ─────────────────────────────────────────────────────────────────┐
│ Source 1  A. Okonkwo · Head of Operational Risk · 09 Nov 2026 · Approved    │
│ Source 2  B. Adeyemi · Second Line — Independent Challenge · 09 Nov · Approved│
│           🏛 Independent challenge                                          │
│ Source 3  — no sign-off recorded —                                          │
│ Conditions and caveats are recorded in the notes log below, as an assumption.│
└─────────────────────────────────────────────────────────────────────────────┘
┌── Notes log ────────────────────────────────────────────── 12 of 31 shown ──┐
│ [Kind ▾] [ textarea: record an assumption, a dissent, a challenge… ]        │
│                                                  [ Add note ]               │
│ ─────────────────────────────────────────────────────────────────────────── │
│ ASSUMPTION  "We assume two analysts leave per year…"                        │
│             A. Okonkwo · 09 Nov 2026 14:22 Lagos time                       │
│ CHALLENGE   "Second line challenged the 18-month replacement…"              │
│             B. Adeyemi · 08 Nov 2026 09:10 Lagos time                       │
│                                        [ Load earlier notes ]               │
└─────────────────────────────────────────────────────────────────────────────┘
                                                    [ Save Overview & Governance ]
```

The **Save** button is at the bottom of the tab panel, per `01` §6.1 — it is this tab's only submit, and it never saves the Qualitative tab.

---

## 4. Requiredness by lifecycle status

**The gate is §A1.8's frozen guard on `in_design → designed`, and nothing more.** It requires exactly four things:

1. the business-line allocation sums to `100.00` (§A1.5, enforced as a decimal comparison),
2. `risk_taxonomy_node_id` is non-null,
3. at least one cause is selected,
4. `background` and `rationale` are non-empty.

Items 1 and 3 live on the Qualitative tab (`03` §7); 2 and 4 are here. The server enforces the guard and recomputes `gate` from it (`01` §5.4); the UI marks a field required **only** where this table says so for the current status. A field that is optional now and required at the gate shows no red `*` until the status demands it — a form covered in asterisks for fields nobody has to fill teaches users to ignore asterisks.

| Field code | Control | `draft` | `in_design` | at the **`designed`** gate | `in_validation`+ |
|---|---|---|---|---|---|
| `reference` | read-only text | system | system | system | read-only |
| `name` | text input | **required** | **required** | **required** | read-only |
| `business_unit_id` | `select` | optional | optional | optional | read-only |
| `risk_taxonomy_node_id` | Combobox | optional | optional | **REQUIRED (§A1.8)** | read-only |
| `nature` | `select` | pre-set `local` | pre-set | not gated — NOT NULL default `'local'` (§A1.2) | read-only |
| `weighting` | `select` | optional | optional | not gated — nullable, no default (§A1.2) | read-only |
| `currency` | `select` | pre-set `NGN` | pre-set | not gated — NOT NULL default `'NGN'` (§A1.2) | read-only |
| `fx_rate_id` | `select`, conditional | optional | optional | not gated | read-only |
| `risk_id` | Combobox (server search) | optional | optional | optional | read-only |
| `background` | textarea, 8 rows | optional | optional | **REQUIRED (§A1.8)** | read-only |
| `rationale` | textarea, 8 rows | optional | optional | **REQUIRED (§A1.8)** | read-only |
| `designed_on` | `input[type=date]` | optional | optional | not gated | read-only |
| `validated_on` | read-only | — | — | — | **stamped by the server** on `validated`; nobody edits it |
| `review_due_on` | `input[type=date]` | optional | optional | not gated | read-only; **required in the sign-off modal** (`01` §5.6.4) |

`returned_for_rework` behaves exactly like `in_design` for editing, and its forward path is `→ in_design` (`01` §5.2).

**Why `validated_on` is never a field.** The workbook has a "Date of Validation" cell, and a designer who can type one can type a date nobody validated anything on. The server stamps it on the `validated` transition and it renders read-only. It shows `—` until then — never today's date, never blank-with-an-asterisk.

**Why `review_due_on` is required at sign-off and not at the gate.** A designer cannot know when the committee will meet; the signer can, and the sign-off modal is the last moment the fact can be captured before the record freezes (`01` §5.6.4). Note this is a **UI-imposed** rule — §A1.8's `validated → signed_off` guard requires only an approved sign-off — and `01` §9.5 recommends the server adopt it.

### 4.1 Fields I recommend adding to the gate, but have not

Recorded separately so nobody mistakes a recommendation for the contract. Each is a field a validator would expect a *designed* scenario to carry, and each is currently ungated because §A1.8 does not list it:

| Field | Why it matters ungated |
|---|---|
| `weighting` | Nullable with no default, so a scenario can reach `signed_off` with no weighting — and Phase 2's aggregation will have nothing to weight it by. §A1.2 refused a default precisely to avoid fabricating one, which is right; the consequence is that the gate should ask. |
| `business_unit_id` | Nullable, so a scenario can be signed off owning to no unit. §A1.3 makes this the *owning* unit — the answer to the workbook's "Entity" — so an unowned scenario has no accountable business. |
| `designed_on` | The workbook's "Date of Design". A signed-off scenario with no design date cannot be placed in time, and an examiner asking "when was this designed?" gets nothing. |
| `reputational_exposure`, `legal_compliance_exposure`, `insurance_risk`, `financial_risk` | All nullable with an explicit "not yet assessed" state (`03` §6). A qualitative assessment with no qualitative answers is thin. The sign-off modal warns about these (`01` §5.6.5) but does not block. |

**These are not in `gate`, and the UI must not enforce them.** A screen that refuses a save the server would accept is a screen that lies about the rules, and it would block designers the freeze deliberately left unblocked. If the coordinator wants any of them gated, that is an amendment to §A1.8, after which `gate` carries them and this section collapses into the table above.

---

## 5. Fields

### 5.1 `reference` — auto-generated, never editable

**Rule:** `SM-{YYYY}-{NNN}`, where `YYYY` is the creation year in Africa/Lagos and `NNN` is a zero-padded sequence per tenant per year, `max(existing) + 1`, starting at `001`. This is the house pattern — `Quantification\ScenarioService::nextReference()` generates `SCN-{YYYY}-{NNN}` the same way (`app/Services/Quantification/ScenarioService.php:73`), and `LossEventController` uses `NM-{YYYY}-{NNN}`.

**The `SM-` prefix must not be `SCN-`.** The Quantification module already owns `SCN-` and its scenarios are a different object (distribution parameter sets, plan §2). Two registers issuing the same prefix is how a board pack cites the wrong scenario.

**On the create form the field is not shown as a number.** It reads:

> Reference — *Assigned when you save*

in `text-gray-600`, with no value. **The create page must not send a provisional reference.** The sequence is `max + 1`, so two designers creating at the same moment are both shown the same provisional number and one of them ends up with a different one — the screen would have displayed a reference that never existed. That is standard §5's defect class in a field nobody would think to check. After the save, the workspace shows the real reference in the header and in this row.

Read-only always. There is no rename, and no `scenario.update` path reaches it.

### 5.2 `name`

Text input, `maxlength` 250 (`string(250)`, §A1.2 row 5). Label "Scenario name", "(required)" in `sr-only`.

The workbook's own example is a phrase, not a title ("Poor succession planning"). Help text: "Describe the event, not the risk. 'Loss of key staff — succession planning failure' rather than 'People risk'."

Messages: required → **"Give the scenario a name."** · too long → **"Keep the name to 250 characters or fewer."**

### 5.3 `business_unit_id`

`select` over `businessUnits: [{id, name}]` — every `BusinessUnit` in the tenant (`BelongsToOrganization` scoped). First option "Choose a business unit…". When the tenant has exactly one unit it is pre-selected and the field renders as a read-only row with the note "Your organisation has one business unit." — a select with one option is a control that teaches nothing.

**This is not an "Entity" field, and must not be labelled one.** §A1.3 refused `entity_id` and made the owning unit `business_unit_id`. The reasoning is worth carrying into the label, because a user who knows the workbook will look for "Entity":

- `entities` is the **scoping** tree (`ScopingSeeder` types GROUP, SUBSIDIARY, DIVISION, DEPARTMENT, REGION, BRANCH, UNIT, PROCESS, SYSTEM) that `GraphScope` walks to answer "which part of the org chart is in scope" — a different question from "which unit owns this scenario".
- `business_units` are the departments and divisions that own work (`DemoDataSeeder`: BU-IT, BU-IA, BU-LG, BU-CX, BU-PB, BU-OP), which is what the workbook's allocation block means by a business line.
- ADR 0026 decision 0.5 already settled that "`business_units` are authoritative", because `key_risk_indicators.entity_id` was a second org FK nothing wrote and `OrganisationGraphUnifier` could not resolve a department from it. Adding `entity_id` here would repeat that defect one ADR later.
- The workbook's "Entity" cell is answered by **`nature` + `business_unit_id`** together. So the field's help text reads: "The unit that owns this scenario. Whether it is a group, standard or local scenario is set by **Nature** below." That sentence is what stops a user hunting for a legal-entity dropdown.

Messages: not in tenant → **"That business unit isn't in your organisation."** (a tenant-bound `Rule::exists`, standard §4 — unreachable from the UI unless the payload is stale).

### 5.4 `risk_taxonomy_node_id` — the OP event-type picker

**Source (§A1.9):** the tenant's `RiskTaxonomy` nodes that are children of the seeded parent **`'Basel OP event types'`** (`framework = 'Basel III'`, `depth = 1`, `sort_order = 1..17`), sent as `opEventTypes: [{id, name, basel_level_1: string|null}]`. ADR 0027 D3 seeds OP1–OP17 as taxonomy nodes so a tenant can extend toward CBN ORMS sub-types; **the picker reads the taxonomy, never a constant**, or an extension would be invisible.

**There is no `code` field, and no client-side formatting.** §A1.9 verified that `risk_taxonomies` has **no `code` column** — the OP number is part of `name`, so a node's name is the whole verbatim string `"OP7 Improper business or market practices leading to breach of laws & regulations (Antitrust, unlicensed activity, money laundering, breach of insurance laws)"`. The picker renders `name` as it arrives. An earlier draft of this spec concatenated a separate `code`; that was wrong and would have rendered "OP7 OP7 …".

**`basel_level_1` comes from the node's `description`.** §A1.9 puts the extraction's Basel Level-1 mapping into each child's `description` ("Basel Level-1: Clients, products & business practices") — chosen over a new column or config file because `description` already exists, is rendered, and is tenant-editable. The presenter extracts the level-1 name into `basel_level_1`; when a tenant has edited the description and it no longer parses, `basel_level_1` is `null` and the picker falls back to a flat list ordered by `sort_order`. **The UI must work with both**, and must never parse `description` in the browser.

**Control:** Headless UI `Combobox` (`01` §0.1 — the sanctioned missing primitive). A `<select>` is wrong here: OP7's label is **158 characters** (§A1.9, measured against `name`'s `string(255)`) and 17 options of that length are unreadable in a closed dropdown.

- **Grouping:** when `basel_level_1` is present, options are grouped under an optgroup-style heading carrying it ("Employment practices & workplace safety"), because a validator checking Basel coverage thinks in the seven Level-1 types. Flat, in `sort_order`, otherwise.
- **Option rendering:** `name` wrapping to as many lines as it needs. **No truncation inside the open list** — hiding words in a list the user is choosing from is how a wrong mapping gets picked, and these labels are near-identical in their first few words ("OP16 Internal vendors misperformance…" vs "OP17 External vendors misperformance…").
- **Closed state:** the full `name`, `truncate`d, with the whole string in `title` **and** in the field's `aria-describedby`, so a screen-reader user gets all 158 characters even when the eye does not.
- **Typing filters** on `name`, case-insensitive substring — so typing "OP7" or "fraud" both work.
- **Clearable:** a small **Clear** text button when a value is set, because the field is optional before the gate and a picker with no empty state traps the user in a choice they did not mean to make.
- **Empty list:** when `opEventTypes` is empty the Combobox does not render. In its place a `role="note"` amber callout: "No Basel OP event types are configured for your organisation. The scenario can be saved without one, but it can't be marked designed until the taxonomy is seeded." **This is a live gap, not a hypothetical one** — §A1.9 records that no taxonomy seeder exists today, that tenants are provisioned outside this repository, and that until provisioning calls `OpEventTypeCatalogue::ensureFor()` (or `php artisan sm:seed-op-event-types`) "a new tenant's OP picker is empty, which is a visible gap, not a silent one." The callout is how it stays visible.

Messages: required at the gate → **"Choose the OP event type this scenario maps to."** · not a Basel III node in this tenant → **"That event type isn't in your organisation's taxonomy."**

### 5.5 `nature` and `weighting`

Two `select`s. Option labels are the workbook's strings verbatim (`VOCABULARY-EXTRACTION.md`), sent as `natures: [{value, label}]` and `weightings: [{value, label}]` — **the front end hard-codes neither list**, because both are module configuration (ADR 0027 D3) and a tenant may rename them.

| `nature` value | Label |
|---|---|
| `group` | Group scenario |
| `standard` | Standard scenario |
| `local` | Local scenario |

| `weighting` value | Label |
|---|---|
| `low` | Low |
| `medium_low` | Medium-low |
| `medium_high` | Medium-high |
| `high` | High |

Help text on `nature`: "A **Group** scenario is designed centrally and run across the group; a **Standard** scenario is a group template applied locally; a **Local** scenario is this organisation's own." On `weighting`: "How much weight this scenario carries in the organisation's operational-risk profile." Neither sentence invents a number or a formula — Phase 1 does not compute anything from the weighting, and the help text must not imply that it does.

**`nature` is never empty.** §A1.2 makes it NOT NULL default `'local'`, so the select opens on "Local scenario" rather than "Choose…". Help text says why: "Defaults to Local — the narrowest claim. Change it if this is a group or standard scenario." A user who never touches it has made the smallest assertion available, which is the opposite of a fabricated value.

**`weighting` is never defaulted.** §A1.2 makes it nullable with no default, because `->default('medium_low')` "would be a fabricated figure on every scenario nobody has weighted" (§5). So this select keeps its "Choose…" empty option and an unset weighting renders `—` in read-only — never "Medium-low", never "Not set" as though it were a value.

⚠ **Name collision, recorded so nobody trips on it (§A1.8):** the Phase-2 scorecard's *component* weighting is a different vocabulary — 1 Very low … 5 Very high, plus an implicit 0 for a blank cell. `Scenario::WEIGHTINGS` (four values) must not be reused for it, and Phase 2's column must not be called bare `weighting`. This tab's field is the scenario's; the label "Weighting factor" is what the workbook calls it and what the read-only view shows, so a reader of the Phase-2 scorecard is not looking at the same word twice.

Messages: `nature` → **"That isn't a valid scenario nature."** · `weighting` → **"That isn't a valid weighting factor."** (both unreachable from the UI — a `select` of server-sent options; they exist for a stale payload).

### 5.6 `currency` and `fx_rate_id`

`currency` is a `select` over `currencies: [{code, name}]` (the tenant's currency list). Beside it, `text-xs text-gray-600`: "Base currency for this tenant: {baseCurrency}." `baseCurrency` is a prop from tenant settings — **never** inferred from the browser locale, even though §A1.2 defaults the column to `'NGN'` because the workbook's sign-off block reads "Local Currency (NGN)". The default is the column's, not the screen's: the select opens on whatever `scenario.currency` holds, which for a new scenario is `NGN` on a Nigerian tenant and whatever the tenant set elsewhere.

**`currency` is never empty.** §A1.2 makes it `char(3)` NOT NULL default `'NGN'` and refuses NULL because "every figure here is money and a currency-less scenario cannot be converted". So no "Choose…" option, and the read-only view always shows a code.

`fx_rate_id` renders **only when `currency !== baseCurrency`**, with `visibleWhen` semantics evaluated in the browser and re-validated on submit. A `select` over `fxRates: [{id, from, to, rate, rate_date}]`, each option rendered `"1 {from} = {rate} {to} · effective {rate_date}"`, newest first.

- **Three columns, one field.** §A1.2 stores `fx_rate_id` (provenance — which `fx_rates` row was chosen) **and** `fx_rate` + `fx_rate_date` (the snapshot), because `fx_rates` rows are per-tenant and a platform rate can be superseded. Picking a rate on this screen writes all three; the UI shows only the picker and then the snapshot. `fx_rate_date` keeps its source column's name rather than the `_on` suffix so it reads against `fx_rates.rate_date` (§A1.2) — an inconsistency the field labels must not paper over, so the read-only row says "Rate effective {rate_date}".
- **`rate` is a decimal string** at `decimal(18,8)` (`01` §4.5). Rendered trimmed of trailing zeros for reading ("1 GBP = 1 210.44 NGN") but never re-rounded into a stored value: §A1.2 chose 18,8 to match `fx_rates.rate` exactly, because "narrower silently rounds a figure an examiner recomputes".
- **Empty list:** the select does not render. A `role="note"` amber callout: "No {currency} exchange rate is on record. The scenario can be saved, but a foreign-currency scenario with no rate cannot be converted." Plus a link to the FX rates screen **only when `urls.fxRates` is non-null** — a link that 403s or 404s must not appear (`01` §3). Note the callout does **not** claim the scenario cannot be marked designed: §A1.8's gate does not require a rate (`02` §4), and saying so would be a rule the server does not have.
- **No converted figure appears anywhere in Phase 1.** There is no amount on this tab to convert. The rate is captured and snapshotted so Phase 2's impact engine has one; rendering "≈ ₦0 equivalent" against an empty impact would be a fabricated number (standard §5).
- **Snapshot semantics, stated on screen:** help text under the field — "The rate you pick is stored with the scenario, so a signed-off figure never moves when the rate table does." That is the user-facing half of §A1.2's snapshot rule and ADR 0027 D1.

Messages: `fx_rate_id` not in tenant → **"That exchange rate isn't available to your organisation."** · `currency` not a valid code → **"Choose the currency this scenario is measured in."**

### 5.7 `risk_id` — the linked risk-register entry

**Optional at every status, including the gate** — §A1.8's guard does not mention `risk_id`, and §A1.2 makes it nullable with `nullOnDelete`. A scenario may be designed ahead of the register catching up, and refusing to save one until somebody files a register entry would push designers back to the spreadsheet.

**Singular, and deliberately so.** §A1.2 refused an `sm_scenario_risks` pivot for Phase 1: "one scenario is designed against one register entry in the workbook, `risks` already carries the unit and category, and a pivot with no screen is a table nobody can test." Many-to-many, if the Phase 4 risk-360 needs it, is an amendment naming Phase 4 — so this control is a single-select and must not grow a multi-select affordance in anticipation.

**Control:** Headless UI `Combobox` with **server-side search**, because a register is thousands of rows and shipping it would cost 60 KB+ — five seconds at 100 kbps for a list most users never open.

- Query `urls.riskSearch` on ≥ 2 characters, debounced 350 ms, `preserveState`, results capped at 20, rendered as `"{risk_code} · {title}"`.
- While searching: "Searching the register…" in `text-xs text-gray-600` with `aria-busy="true"` on the combobox. No spinner-only state — the words are the state.
- No results: "No risk matches '{term}'." with a hint: "You can save the scenario without a link and add one later."
- **Nothing is fetched until the user types** (`01` §0.4: conditional content is not rendered until needed).
- The already-linked risk arrives in `scenario.risk`, so it renders on load with no search: `"{risk_code} · {title}"` as a link opening in a **new tab** (`target="_blank" rel="noopener"`, link text ends "(opens in a new tab)") so an unsaved Overview is not abandoned, plus a **Remove** button.
- When `scenario.risk.url` is `null` (the user lacks `risk.view`) the reference renders as **plain text**, not a dead link. The prop is nullable for exactly that reason.

Messages: not in tenant / not visible to this user → **"That risk isn't in your organisation's register."**

### 5.8 `background` and `rationale`

Two `textarea`s, **8 rows**, with a live character count in `text-xs text-gray-600` only once past 3 000 ("3 412 / 4 000") — a counter visible from the first keystroke is noise on a narrative field.

- `background` — label **"Background and context"**, help text "How the risk materialises: what happens, to whom, over what period. This is the narrative a validator reads first." **Required at the gate** (§A1.8).
- `rationale` — label **"Why this scenario was designed"**, help text "What made this worth assessing — a loss event, a KRI breach, an emerging risk, a regulator's question, a workshop." **Required at the gate** (§A1.8).

**The field is `background`, not `description`.** §A1.2 names the column `background` (`longText`) with `rationale` as `text` — the split `bcms_scenarios` uses for `summary`/`narrative`, because "the workbook's background block is a page of prose". An earlier draft of this spec called it `description`; the column name is the field code, so the props, the 422 keys and the `gate.missing[].field` anchors all read `background`.

**The 4 000-character cap is a UI choice, not a schema limit** — `longText` and `text` hold far more. It exists because an uncapped textarea invites a pasted 40-page document that nobody will read in a validator's browser, and because the character counter needs a denominator. Raising it needs no migration; if a customer needs it, change the constant and the counter.

Both render `whitespace-pre-line` in the read-only state, so a designer's paragraph breaks survive. Both are the fields most likely to hold a pasted-in draft, so the `sessionStorage` backup (`01` §6.3) matters more here than anywhere else in the module.

Messages: required at the gate → **"Describe how this scenario materialises."** / **"Say why this scenario was designed."** · too long → **"Keep this to 4 000 characters or fewer — {n} over."**

### 5.9 The three dates

`input[type=date]` for `designed_on` and `review_due_on`; `validated_on` is read-only (§4).

- Values are `Y-m-d` strings and are **not** run through a timezone conversion (`01` §4.1). Display beside the input in the user's reading form: "Mon 9 Nov 2026", so a user is never left reconciling `2026-11-09` against a sentence.
- `designed_on` help text: "When the design of this scenario was completed." It is **not** pre-filled with today. A date field that silently defaults to today is how a scenario ends up claiming a design date nobody agreed; the signer can see it and the audit trail records it. **It is not gated** — §A1.8's guard does not require it — so an empty `designed_on` never blocks the `designed` transition and the field shows no asterisk (`02` §4.1 records that I recommended gating it and did not).
- `review_due_on` help text: "When this scenario is next due for review. Required at sign-off." The suggestion (`suggestedReviewDueOn`) appears **only in the sign-off modal** (`01` §5.6.4) — this field does not compute or display one, because the browser must not do date arithmetic (`01` §4.1).
- `review_due_on` in the past renders the amber callout from `01` §4.3 above the Dates card, not inside it.
- Future-dated `designed_on`: allowed, no warning. A workshop may agree a design date at the end of a programme, and a client-side opinion about it would be a fabricated rule.
- **`validated_on` is not an input.** It renders as read-only text in the same card so a reader sees all three dates together, with `—` until the server stamps it (§4).

Messages: malformed → **"Enter a real date."** · `review_due_on` before `designed_on` → **"The next review can't be before the design date."** · `review_due_on` missing at sign-off → **"Set the next review date before signing off."** (raised by the sign-off modal, `01` §5.6.4, not by this form)

---

## 6. The sign-off panel

Read-only in Phase 1. Sign-off rows are written by the shell's sign-off modal (`01` §5.6), never here; this panel is where a reader checks who approved the scenario and whether anybody independent did.

**Prop:** `signoffs` (`01` §4), which may hold several rows per source. **Render only the rows where `is_current` is true** — §A1.6 has no unique key on `(scenario, source)`, so a revalidation appends new rows and the current sign-off for a source is the latest by `signed_at`. Superseded rows have no Phase-1 surface at all (`01` §9.8); they live in `risk_audit_trail` until Phase 3's Sign-off & audit tab. Never sort or pick "latest" in the browser (`01` §4.6).

Rendered as a `dl`, one group per source in `Signoff::SOURCES` order — Source 1, Source 2, Source 3:

| Row | Content | Absent |
|---|---|---|
| Signatory | **`signatory_name`** — the NOT NULL snapshot (§A1.6). When `user` is also set and matches, render the name as a link to their profile; when `user` is null, plain text | Never absent — the column is NOT NULL, which is the point: a sign-off always says who signed |
| Capacity | **`role`** — free text ("Head of Operational Risk"). Not a platform role name (`01` §4.7) | Never absent (NOT NULL) |
| Signed | `signed_at` as "09 Nov 2026, 14:22 Lagos time" | **"—, not counted"**: `signed_at` is nullable (§A1.6, standard §13) and only non-null rows count toward the lifecycle. A null row renders muted with the words "Recorded, not signed" so a reader never mistakes it for an approval |
| Decision | `decision` in words: **"Approved"** / **"Returned for rework"** — `Scenario::DECISIONS` has exactly these two (§A1.6 refused `approved_with_conditions`) | Never absent (NOT NULL) |
| Independent challenge | When `is_independent_challenge`: a `badge badge-status-active` chip **"Independent challenge"** with a `gavel` icon | Nothing renders. **The chip never appears as "Not independent"** — the absence of a flag is not an assertion about the signer |
| Comment | `comment`, `whitespace-pre-line` | omitted |

**A `returned_for_rework` decision** renders its row with a red left border (`border-l-4 border-red-600`) and the words in `text-red-700`. Such a row cannot coexist with status `signed_off` — §A1.8's guard requires ≥1 *approved* current sign-off, and a returned one moves the scenario to `returned_for_rework` (`01` §5.6.8) — but the panel must still render it correctly if it appears, because a later re-round can leave an approved current row beside an older returned one for a different source.

**Where "approved with conditions" went.** A signer looking for it will not find it, so the panel carries one `text-xs text-gray-600` line under the three groups: "Conditions and caveats are recorded in the notes log below, as an assumption — so they are written down rather than implied by a decision." §A1.6's reasoning: a third decision value that no transition branches on "is how a workflow acquires a state nobody can leave."

**Empty state** (`signoffs` empty, or no current rows): the card still renders, with `EmptyState`-style text — title **"No sign-off recorded"**, description "Sign-off sources are captured when the scenario is signed off. Until then this scenario carries no approval." No button, no link, no implied action: a user who cannot sign off must not be invited to.

**Build note for Phase 3.** This panel and the notes log below are one self-contained component pair so that Phase 3's **Sign-off & audit** tab *relocates* them rather than growing a second place to read sign-off sources (`01` §9.8). Phase 1 must not add another surface that renders `signoffs` — and Phase 3's tab is where the superseded rows finally become visible.

---

## 7. The notes log

The bias-mitigation trail plan §6.7 approved: a typed note log, no per-participant workshop elicitation app in v1. ISO 31010's "document assumptions" and the Fed FEDS note's dissent/challenge record.

**Props:** `notes`, `notesMeta`, `noteKinds` (`01` §4). **Writes:** `POST urls.notes` with `{kind, body}` — gated by **`scenario.note`**, not `scenario.update` (§A1.10, `01` §3).

**Append-only, and enforced by the absence of routes.** §A1.7: "Phase 1 registers no update and no destroy route. A corrected note is a new note. This is the bias-mitigation trail D4 rests on, and a dissent its author can quietly edit is not a dissent trail." So there is **no edit control and no delete control anywhere on this panel** — not greyed out, not hidden behind a permission, simply absent. If a note is wrong, the correction is another note that says so.

### 7.1 The add-note form

Rendered at the **top** of the card, so adding a note during a workshop does not require scrolling past the history. **Rendered only when `can.note`** — which is a different set of people from `can.update`, and deliberately so: per §A1.10's grants, `risk-analyst`, `risk-owner` and `compliance-officer` hold `scenario.view` + `scenario.note` and nothing else, so a first-line owner can open a scenario they cannot edit and record a dissent against it. One permission for both "would let the designer write the dissent log — the trail D4 exists to keep."

When `can.note` is false the form does not render and no explanatory strip takes its place: the list is still readable, and a "you can't add notes" banner on every read for a user who was never going to is noise.

- **Kind** — a `select` over `noteKinds` (`Note::KINDS`, §A1.7), labels and hints from the server:

  | `value` | Label | Hint (rendered under the select when that kind is chosen) |
  |---|---|---|
  | `assumption` | Assumption | "Something taken as true without evidence. Record it so a validator can challenge it." |
  | `dissent` | Dissent | "Somebody disagreed. Record who and why — a scenario with no recorded dissent was probably not argued about." |
  | `challenge` | Challenge | "A second-line or independent challenge to the design, the figures or the conclusions." |
  | `workshop_minute` | Workshop minute | "What was decided in a session, so the decision outlives the room." |

  Default selection: `assumption`. The hints are the point — they are what makes a risk officer write a useful note instead of "discussed". `kind` is `string(20)`, not a DB `enum`, so a fifth kind is a code change and not an `ALTER TABLE` (§A1.7, after ADR 0026 §2) — which is why the options arrive as data rather than being a JSX constant.
- **Body** — `textarea`, 3 rows, label "Note", `maxlength` 2000.
- **Add note** — `btn-secondary`. In flight: `disabled`, `aria-busy="true"`, "Adding…".
- **Identity is not a field.** The author is the authenticated user, stored as `author_id` **plus an `author_name` snapshot** (§A1.7 — "an anonymous dissent cannot be followed up, which is worse than none"), and the timestamp is the server's. A note whose author can be typed is not evidence.
- On success: the new note appears at the top of the list, the textarea clears, the kind resets, focus returns to the textarea, `role="status"` "Note added." The list reloads via `only: ['notes', 'notesMeta']`.

### 7.2 The list

Descending `created_at`, latest 20, `notesMeta.total` in the card header as "{shown} of {total} shown". Each entry:

- A kind chip: `assumption` → `badge badge-status-pending` "Assumption"; `dissent` → `badge badge-status-overdue` "Dissent"; `challenge` → `badge badge-medium` "Challenge"; `workshop_minute` → `badge badge-status-draft` "Workshop minute". Words always present; colour is the second channel.
- The body, `whitespace-pre-line`.
- **`author_name`** — the snapshot, not a joined user record — then `created_at` as "09 Nov 2026, 14:22 Lagos time", in `text-xs text-gray-600`. The words "Lagos time" appear once per page, on the first timestamp. Because the name is a snapshot, a note survives its author leaving the bank and still says who wrote it.
- A note written by a lifecycle action (a return-for-rework reason, a reopen reason — `01` §5.3, §5.5) renders with a preceding line naming the action: "Returned for rework by {author_name}." That is why the log is trustworthy: the system's own entries are indistinguishable in format from a human's and equally attributable. *(Subject to `01` §5.3's open question about how a return reason is persisted.)*

**Load earlier notes** — when `notesMeta.total > notesMeta.shown`, a `btn-secondary` at the end of the list reloading `only: ['notes', 'notesMeta']`. Not automatic (`01` §4.4).

**Empty state:** title **"No notes yet"**, description "Record an assumption, a dissent, a challenge or a workshop minute. The trail is what an examiner asks for, and it is what makes a signed-off number defensible." The add-note form is above it and remains the primary affordance — an empty log with a visible way to fill it is an invitation, not a dead end. When `can.note` is false the description is the whole panel, and it reads as a statement about the scenario rather than an instruction the user cannot follow.

**Read-only:** the add-note form is hidden when the status is `archived` and when `can.note` is false. It stays visible in every other status **including `signed_off`** — a note is commentary on a frozen record, not a change to it (`01` §5.5).

Messages: `kind` not in the vocabulary → **"Choose what kind of note this is."** · `body` empty → **"Write the note before adding it."** · `body` too long → **"Keep a note to 2 000 characters or fewer."**

---

## 8. Props this tab expects

Shell props are in `01` §4. This tab additionally requires:

```
{
  businessUnits: [{id, name}],                                  // §A1.3 — business_units, NOT entities
  opEventTypes: [{id, name, basel_level_1: string|null}],        // §A1.9 — no `code` column exists
  natures:    [{value, label}],                                 // Scenario::NATURES
  weightings: [{value, label}],                                 // Scenario::WEIGHTINGS
  currencies: [{code, name}],
  fxRates:    [{id, from, to, rate, rate_date}]                  // rate is a decimal STRING (01 §4.5)
}
```

Plus, from the shell (`01` §4): `values` (this tab's edit state, keyed by the §2 field codes), `errors` (422 keys are the same codes), `urls.scenario` (the `PUT`), `urls.riskSearch` (§5.7), `urls.notes`, `suggestedReviewDueOn` (it belongs to the sign-off modal, which is shell-level, but this tab's `review_due_on` field references it), `signoffs`, `notes`, `notesMeta`, `noteKinds`, `baseCurrency`, `can`.

**(prop decision) `values` is a flat object keyed by the frozen column names**, not a nested model dump, so the 422 error keys and the `gate.missing[].field` anchors line up with the form state one-for-one. Anything else forces the front end to hold a mapping between three naming schemes. Its shape:

```
values: { name, business_unit_id, risk_taxonomy_node_id, nature, weighting,
          currency, fx_rate_id, risk_id, background, rationale,
          designed_on, validated_on, review_due_on }
```

`validated_on` is in `values` as read-only context so the Dates card can render it without a second prop; it is never posted (§4).

**(prop decision) There is no `schema` and no `detail`** — §2 records why, and `01` §4 notes the omission is deliberate rather than an oversight.

**(prop decision) `fxRates` is filtered to the selected currency's pair by the server on the save round-trip**, or shipped whole if the tenant's rate table is small. The spec does not require either; it requires only that an option's label names its pair, so a wrong-currency rate is visible as wrong.

**(prop decision) No risk list.** `scenario.risk` carries the current link; `urls.riskSearch` carries everything else (§5.7).

---

## 9. States

| State | Trigger | What the tab shows |
|---|---|---|
| **Loading** | Inertia visit to the tab | The progress bar; props are synchronous. No skeleton — the section cards would reserve a height the read-only state does not share |
| **Empty (a new scenario)** | `draft`, every nullable field null | The full form, with the reference row already carrying its value ("Assigned when you save" is the **create** form's text only, `04` §4). `nature` shows "Local scenario" and `currency` shows the tenant default — both are NOT NULL with defaults (§A1.2), so they are never blank. `weighting` and `business_unit_id` read "Choose…"; textareas are empty; dates are empty. **No field shows `0`, "None" or today's date** |
| **Populated, editable** | status ∈ {`draft`, `in_design`, `designed`, `returned_for_rework`} and `can.update` | §3 |
| **Populated, read-only** | status ∈ {`in_validation`, `validated`, `signed_off`} | Every control replaced by its value as text in a `dl` (label above value). **No disabled inputs** — a disabled field reads as "temporarily unavailable" and invites retrying; text reads as "this is the record". The Save button is absent, not disabled. `in_validation`/`validated` carry the shell's "you can't change this" banner; `signed_off` carries the frozen banner (`01` §5.5) |
| **Read-only after sign-off** | `scenario.frozen` | As above, plus the frozen banner and the "figures do not recompute" sentence (`01` §5.5). The notes form stays (§7.2) |
| **Archived** | status `archived` | Read-only, and the notes form is hidden. A `role="note"` line in the header card: "This scenario is archived. It stays on the audit trail and appears in no live list." |
| **Partial** | some fields unset | Per field: `—` for a null value in read-only; "Choose…" / empty in edit. A read-only row is never blank — `—` is a statement that nobody answered, a blank is a rendering bug |
| **Unanswered three-state fields** | — | Not applicable on this tab (the yes/no fields live on `03`), but the rule travels: `validated_on` null renders `—`, never "Not validated" as though it were a decision, never today's date |
| **Error (500) on load** | server | The standard error page |
| **422 on save** | validation | `01` §6.2 — summary at the top of the panel, focused; per-field errors with `aria-invalid` and `aria-describedby`; "Nothing was saved." |
| **Network loss / 419 / 403 on save** | — | `01` §6.3, §6.4 — entries kept, `sessionStorage` backup, **Copy my entries** |
| **Permission denied** | no `scenario.view` | The 403 page (the nav entry is gated). A `scenario.view` holder without `scenario.update` gets the **read-only presentation with no banner about permissions** — the absence of a Save button is the whole message, and a "you can't edit this" strip on every read for a user who was never going to edit is noise |
| **Missing taxonomy / no FX rate** | §5.4, §5.6 | The amber callouts, in place of the control |
| **Degraded network** | 100 kbps | §11 |

---

## 10. Interactions, keyboard and screen reader

- **Heading order:** `h1` is the shell's scenario name. Each section card title is an `h2` — Identity, Classification, Currency, Linked risk, Background & rationale, Dates & review, Sign-off, Notes log. Field labels are `<label>`, not headings.
- **Focus order** follows the visual order in §3, section by section, and ends on **Save**. Within the notes card: type → body → Add note → list → Load earlier.
- **Comboboxes** (OP event type, linked risk): Headless UI's pattern — `role="combobox"` with `aria-expanded`, `aria-controls` → the listbox, `aria-activedescendant` following the highlighted option. Keyboard: `↑`/`↓` move, `Enter` selects, `Escape` closes and returns focus to the input, `Home`/`End` jump, typing filters. The **Clear** control is a real button in the tab order, not a mouse-only glyph.
- **The OP event-type group headings** are `role="presentation"` inside the listbox with the group name announced via `aria-label` on the option, so a screen-reader user hears "OP5, Loss of key staff/talent management, Employment practices and workplace safety" rather than losing the Basel grouping that sighted users get visually.
- **Announcements:** `role="status"` for "Note added.", "Saved.", the register search's result count ("3 risks match 'talent'", debounced, not per keystroke), and the currency change that reveals or hides the FX field ("Exchange rate is required for GBP."). Nothing else is a live region — a field that announced on every keystroke would make the tab unusable with a screen reader.
- **Labels and errors:** every field has a visible `<label for>`; `aria-describedby` points at the help-text id **and** the error id when present; `aria-invalid="true"` on error. Required is the red `*` (`aria-hidden`) plus "(required)" in `sr-only` plus the `required` attribute — and only where §4 says the current status requires it.
- **Read-only state:** a `dl` with `dt`/`dd` pairs, so a screen reader announces "Entity, Keystone Bank Nigeria" as a term-definition pair rather than as an unlabeled value. Section `h2`s survive, so the document is navigable by heading.
- **Character counter** (§5.8) is `aria-live="off"` and reached by `aria-describedby` on the textarea, so it is available on demand and does not chatter.
- **Contrast:** section titles Navy `#1A365D` (≈12:1); help text `text-gray-600` (≈7.6:1); `text-gray-500` (≈4.8:1) is the floor; `#718096` and `#9CA3AF` are not used for text (`01` §0.3).
- **Reflow:** single column below `md`; the Currency card's base-currency note moves under the select; no horizontal scroll at 320 px. Combobox listboxes are `max-h-72 overflow-y-auto` and never wider than the field.
- **Nothing on this tab is destructive** except **Remove** on the linked risk, which is immediate and reversible (the link can be re-added) and therefore carries no confirmation. Deleting a note is impossible (§7.1).

---

## 11. Low bandwidth (100 kbps)

- **This tab's payload** is small and bounded: the tenant's business units (tens — §A1.3, they are departments and divisions) + 17 OP nodes with long names + 3 natures + 4 weightings + ~6 currencies + a handful of FX rates ≈ 8 KB, plus the shell (`01` §8). Under 1 s. The OP names dominate: OP7 alone is 158 characters (§A1.9).
- **The risk register is never shipped.** `urls.riskSearch` is queried on demand, debounced 350 ms, capped at 20 results (§5.7). This is the single largest bandwidth decision on the tab.
- **Notes are capped at 20** with an explicit **Load earlier** (`01` §4.4).
- **No autosave.** A designer on a degraded link types freely; the `sessionStorage` backup captures every change debounced 1 s; Save is explicit and posts ~4 KB.
- **In flight:** "Saving…" / "Adding…" with `aria-busy`; after 15 s the slow-connection line (`01` §0.4).
- **Offline:** typing works with no connection at all. Save fails loudly, keeps the entries, and offers **Try again**.
- **Deferred:** nothing. There is no chart, image or below-the-fold fetch on this tab.

---

## 12. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| Reference | `sm_scenarios.reference`, generated `SM-{YYYY}-{NNN}` server-side (§5.1) | "Assigned when you save" on the create form only |
| Exchange rate | `fxRates[].rate`, a decimal string at `decimal(18,8)`; on save it is snapshotted into `sm_scenarios.fx_rate` + `fx_rate_date` alongside the `fx_rate_id` provenance (§A1.2, §5.6) | The field is hidden when `currency === baseCurrency`; the amber callout when no rate exists |
| Date of design / Next review due | `designed_on`, `review_due_on` columns | `—` |
| Date of validation | Stamped by the server on the `validated` transition | `—`. Never today's date, never editable |
| Days past review | `review.days_overdue`, computed server-side in Africa/Lagos (`01` §4.2) | The callout does not render. **Never `0`** |
| Suggested next review date | `suggestedReviewDueOn` — today + the tenant's `review_cadence_months`, computed server-side | The sign-off modal shows only the empty required field |
| "{shown} of {total} shown" (notes) | `notesMeta` | The line is omitted when `total <= shown` |
| Character counts | `value.length` against the field's `maxlength` — a derived figure whose inputs are named | Not rendered below 3 000 characters |
| **Deliberately absent: any FX-converted amount** | — | There is no amount in Phase 1 to convert (§5.6). Rendering one would be a fabricated figure |
| **Deliberately absent: a completeness percentage** | — | `gate` reports *which* fields are missing, not a percentage complete. A percentage over a field count implies every field carries equal weight, which §4's table exists to deny |

---

## 13. Known gaps (also in the HANDOFF)

1. **No version history on this tab.** The plan (§4.2) says version history rides the existing audit-trail machinery, and `01` §5.1 defers the **Sign-off & audit** tab to Phase 3 — so in Phase 1 a user cannot see what a field said before the last save. The frozen banner promises the signed-off figures do not move, but nothing on screen lets a reader verify it. Phase 3 must deliver the trail; until then the promise rests on the audit table, not on the UI.
2. **~~`designed_on` has no link to the actual design history.~~ Ruled: it stays a typed date** (coordinator, 10 Nov 2026). So the field remains an input and is **not** stamped by the `designed` transition — unlike `validated_on`, which is. That asymmetry is worth a line of help text (§5.9) and worth noting for Phase 3: the audit trail records when the transition happened, so a typed `designed_on` that disagrees with it is detectable but not prevented.
3. **~~One scenario, one OP event type.~~ Ruled: exactly one** (coordinator, 10 Nov 2026), matching §A1.2's scalar `risk_taxonomy_node_id` and the workbook's single dropdown. A scenario that is genuinely both OP5 and OP3 has no representation in v1; the workaround is the `background` narrative, and if a customer needs a real mapping it is an amendment naming the phase. **The picker must stay single-select** — a multi-select affordance here would collect data the schema cannot store.
4. **`urls.fxRates` may not exist.** If the product has no FX-rates screen to link to, the prop is `null` and the empty-state callout names the gap without offering a way out (§5.6). That is deliberate, per the rule that a control the user cannot use is absent.
5. **Note authors are users, not roles.** A `challenge` note carries no assertion that its author is second-line; only a sign-off row carries `is_independent_challenge`. If an examiner needs the challenge trail itself to be attributable to a function, that needs a role on the note — flagged, not invented.
