# 04. New scenario (the create form)

**Page:** new `resources/js/Pages/Sm/Scenarios/Create.jsx` · **Routes:** `GET /risk/scenarios/create` (`scenario.create`, `permission:scenario.create`, behind `feature:scenario_modelling`) and `POST /risk/scenarios` (`scenario.store`, same permission and flag) · **On success:** redirect to `scenario.show` at `?tab=overview` · **Phase:** 1

Read `01-workspace-shell.md` first: the permission→action table (§3), the shared states (§0.2), the accessibility baseline (§0.3), the low-bandwidth rules (§0.4) and the no-fabricated-numbers rule (§0.5) apply unchanged. Field controls, option sources and validation copy are specified once in `02` §5 and reused here verbatim.

**Authority for the field set:** ADR 0027 Amendment 1 §A1.2 (the frozen `sm_scenarios` columns), §A1.3 (why the owning unit is `business_unit_id` and there is no `entity_id`), §A1.9 (the OP event-type tree) and §A1.11 (the route table). Where this file and the amendment could be read as disagreeing, the amendment wins.

---

## 1. Purpose and the user

**Who.** A holder of `scenario.create` — a Risk Analyst or Operational Risk Manager — who has just decided a risk theme deserves a scenario. They arrive from the index's **New scenario** button, from the nav entry (`05`), or from a Phase-4 emerging-risk promotion (not built yet).

**In the first ten seconds** they must be able to name the scenario, say which entity it belongs to, and be inside the workspace. That is the whole job.

**Design stance — a minimal form, deliberately.** This screen asks for **four** facts and then gets out of the way. Everything else the module collects lives on the Overview & Governance tab, and a create form that duplicated it would be a second copy of fifteen fields with their own requiredness rules, option lists and messages: the two would drift, and the drift would be invisible until a user's answers disagreed depending on which door they came through. That is the exact failure `docs/ui/kri-cycle/00-common.md` §1.4 records for `BandPreview`, and the reason this spec exists separately from `02` at all is to keep the duplication to four fields.

**A page, not a modal.** Two precedents exist in this product and they disagree: `risk.register.create` and `risk.scoping.create` are dedicated pages with their own nav entries (`app/Presenters/NavPresenter.php:84,94`), while `Bcms/Plans/Index.jsx` creates inline on the index. The page wins here for three reasons: `00` §3.1 specifies the index as a pure `DataGrid` with no form region; a page is deep-linkable and survives a session expiry without losing the user's place; and the module needs a nav entry anyway (`05`), which the house reserves for pages.

---

## 2. Props

```
{
  businessUnits: [{id, name}],                                  // 02 §5.3 — business_units, NOT entities (§A1.3)
  opEventTypes: [{id, name, basel_level_1: string|null}],        // 02 §5.4 — no `code` field, §A1.9
  natures:      [{value, label}],                               // 02 §5.5 — Scenario::NATURES
  values: { name: '', business_unit_id: null, nature: 'local', risk_taxonomy_node_id: null },
  errors: {},                                                   // 422 keys = these four codes
  urls: { store, cancel }                                       // cancel = scenario.index
}
```

**(prop decision) The create page sends no reference, provisional or otherwise** — see §4. It also sends **no** `weightings`, `currencies`, `fxRates` or risk-search URL: none of those fields is on this form, and shipping their option lists would be dead bytes on the slowest link in the product (`01` §0.4).

**(prop decision) `values` uses the frozen column names as field codes** (`name`, `business_unit_id`, `nature`, `risk_taxonomy_node_id`) so the 422 keys, the Form Request rules and the workspace's `gate.missing[].field` anchors are one vocabulary across three surfaces. A create form that posts `scenario_name` while the workspace posts `name` is how a module ends up with two validation rule sets.

**(prop decision) `nature` arrives pre-set to `'local'`, not null.** §A1.2 makes the column NOT NULL default `'local'` — "a classification the designer knows at creation, where `local` is the narrowest claim", in the shape of `risks.status` defaulting `'draft'`. The form therefore opens with a real value rather than a "Choose…" that a user must clear, and the default is the narrowest assertion available. §3 gives it help text saying so.

**(prop decision) `opEventTypes[].name` is the whole label, `"OP7 Improper business or market practices…"` verbatim** (§A1.9). `risk_taxonomies` has **no `code` column** — the OP number is part of `name` — so there is no separate code to concatenate and no client-side formatting of it. `basel_level_1` is extracted by the presenter from the node's `description`, which is where §A1.9 puts the mapping ("Basel Level-1: Clients, products & business practices"); it is `null` when a tenant has edited that description, and the picker falls back to a flat list.

---

## 3. Layout

Container `max-w-2xl` — a narrow column, because four fields spread across `max-w-4xl` reads as a form with most of it missing.

*Every reference, name and entity in this sketch is sample data (`01` §0).*

```
┌────────────────────────────────────────────────────────────────────┐
│ Scenario modelling  ›  Scenarios  ›  New scenario                   │  breadcrumbs
│ New scenario                                                        │  h1
│ Name it, place it, and the workspace does the rest.                 │  subtitle
│                                                                     │
│ ┌── card ────────────────────────────────────────────────────────┐  │
│ │ Reference                                                       │  │
│ │   Assigned when you save                                        │  │  text-gray-600
│ │                                                                 │  │
│ │ Scenario name *        [________________________________]     │  │
│ │   Describe the event, not the risk.                             │  │
│ │                                                                 │  │
│ │ Business unit          [ Choose…                        ▾ ]     │  │
│ │                                                                 │  │
│ │ Nature of scenario     [ Local scenario                 ▾ ]     │  │
│ │   Defaults to Local — the narrowest claim. Change it if this    │  │
│ │   is a group or standard scenario.                              │  │
│ │                                                                 │  │
│ │ OP event type          [ 🔍 Search the Basel OP event types ▾ ]  │  │
│ │   Optional now — required before the design can be completed.   │  │
│ └─────────────────────────────────────────────────────────────────┘  │
│                                                                     │
│                     [ Create scenario ]   [ Cancel ]                │
└────────────────────────────────────────────────────────────────────┘
```

**Above the fold at 1440 × 900:** everything, including both buttons. On a phone the two lower fields fall below, which is fine — the required ones are first.

Field order is **required first, optional second**: `name`, then `business_unit_id`, `nature`, `risk_taxonomy_node_id`. Only `name` is required to create (§A1.2 makes it NOT NULL with no default); a user in a hurry fills it and presses Create without discovering they missed something.

Each field renders exactly as `02` §5 specifies it — same control, same help text, same option source, same messages — with one difference: **no field on this form is required at a lifecycle gate**, because there is no lifecycle yet. `risk_taxonomy_node_id` is optional here and becomes required at the `in_design → designed` transition, which §A1.8's frozen guard enforces. Its help text says so in the same words on both surfaces: "Optional now — required before the design can be completed."

`business_unit_id` and `nature` are **not** in that guard (§A1.8 lists four requirements and neither is one), so their help text must not claim they will be required later — a field that warns of a requirement the server does not enforce teaches users to ignore the warnings that are real.

The **Reference** row is first, above `name`, and is read-only text (§4).

---

## 4. The reference row

Renders as a label with a value line beneath, in `text-gray-600`:

> **Reference**
> Assigned when you save

**No number. No placeholder shaped like a number. No `SM-2026-015`.**

The rule is `SM-{YYYY}-{NNN}`, `NNN` = `max(existing) + 1` within the tenant and year (`02` §5.1), stored in `sm_scenarios.reference` under the frozen unique index `sm_scenarios_org_reference_unique (organization_id, reference)` (§A1.2) — **unique per tenant, not globally**, following `bcms_management_reviews`, `risks.risk_code` and `bcms_scenarios.code`. That sequence is read at write time, so any number shown *before* the write is a guess: two designers creating at the same moment are both shown the same provisional reference, and one of them is saved with a different one. The screen would have displayed a reference that never existed — standard §5's defect class in the one field a user is most likely to copy into an email before saving.

`Quantification\ScenarioService::nextReference()` exists and would supply such a number (`app/Services/Quantification/ScenarioService.php:73`). **Do not call it from the create page.** It is the right method for a single-user seeder and the wrong one for a concurrent form.

The unique index is what makes the concurrency safe rather than merely undocumented: §A1.2 records that a collision is **a retry**, not a user-facing error. So the worst case of two simultaneous creates is one silently re-issued reference, and the user still sees a true number — the first one that exists.

After `POST` succeeds, the redirect lands on the workspace, where the real reference is in the header and in the Overview tab's Reference row. The user sees the number once, when it is true.

---

## 5. States

| State | Trigger | What the page shows |
|---|---|---|
| **Loading** | Inertia visit | Progress bar; props synchronous. No skeleton — four fields do not need one, and a skeleton would reserve a height the error state does not share |
| **Empty (the normal state)** | first visit | `name` blank, `business_unit_id` "Choose…", `nature` pre-set to **Local scenario** (§2), `risk_taxonomy_node_id` "Choose…". No red, no error, no warning |
| **Populated** | the user has typed | Dirty tracking begins (§6.2) |
| **Single-unit tenant** | `businessUnits.length === 1` | That unit is pre-selected and the field renders as a read-only row with the note "Your organisation has one business unit." — a select with one option is a control that teaches nothing (`02` §5.3) |
| **No business units configured** | `businessUnits` empty | The control still renders (the column is nullable, §A1.2 row 10) with an amber `role="note"` line beneath: "No business unit is configured for your organisation, so this scenario will be unassigned. Add units under Scoping to give it an owner." **Create is not blocked** — unlike the draft of this spec, which treated the owning unit as required: §A1.8's frozen `in_design → designed` guard does not include it, and blocking creation on it would enforce a rule the server does not have |
| **No OP taxonomy seeded** | `opEventTypes` empty | The OP control does not render; the amber callout from `02` §5.4 appears in its place. This is a **live** gap, not a theoretical one: §A1.9 records that no taxonomy seeder exists today and that new tenants are provisioned outside this repository, so "until then a new tenant's OP picker is empty, which is a visible gap, not a silent one." It does not block Create — the field is optional here |
| **422 on submit** | validation | §6.1 |
| **500 on submit** | server | `01` §0.2. Values kept (`useForm`), banner: "Something went wrong on our side. Nothing was created. Try again." The word is **created**, not *changed* — nothing exists yet |
| **Network loss on submit** | degraded link | `01` §6.4. **Before any retry, the page reloads `only: ['flash']`-style state or navigates to the index to check whether the scenario was created** — a blind retry of a `POST` on a flaky link is how a tenant acquires two copies of the same scenario. See §6.3 |
| **419 / session expired** | — | `01` §0.2's modal: entries kept, **Retry** re-sends. A create form is the worst place to lose four typed fields |
| **403** | no `scenario.create` | The 403 page. The nav entry (`05`) and the index button (`00` §3.1) are both gated, so a 403 means a typed or stale URL |
| **404** | flag off | `feature:scenario_modelling` 404s the route group. No "coming soon" page |
| **Read-only / frozen / permission-denied-in-page** | — | **Not applicable.** This page creates; it has no read-only presentation, no lifecycle and no freeze. A user without `scenario.create` never reaches it |
| **Duplicate submit** | double-click, or Enter twice | The button is `disabled` with `aria-busy="true"` from the first click until the response, so there is nothing to double-click. See §6.3 for the case where the response never arrives |

---

## 6. Behaviour

### 6.1 Submit and validation

**Create scenario** (`btn-primary`) posts `values` to `urls.store`. Errors arrive keyed by field code and render per `01` §6.2: a summary at the top of the card (`h2` "Fix {n} item{s}", `tabindex="-1"`, focused, scrolled into view, one linked entry per error), plus each error under its field with `aria-invalid="true"` and `aria-describedby`.

The messages are `02` §5's, verbatim, and must be the **Form Request's** messages so this page and the workspace cannot disagree:

- `name` → **"Give the scenario a name."** / **"Keep the name to 250 characters or fewer."** (`string(250)`, §A1.2 row 5)
- `business_unit_id` → **"That business unit isn't in your organisation."** (a tenant-bound `Rule::exists`, standard §4 — unreachable from the UI, which offers only units from the payload; it exists for a stale tab)
- `nature` → **"That isn't a valid scenario nature."** (unreachable from the UI — a `select` of `Scenario::NATURES`; it exists for a stale payload)
- `risk_taxonomy_node_id` → **"That event type isn't in your organisation's taxonomy."**

On success the server redirects to `scenario.show` at `?tab=overview`. Focus moves to the workspace's `h1`; a `role="status"` flash reads **"SM-2026-015 created."** — the first moment the reference is spoken or shown anywhere, and it is the server's string, not the client's.

### 6.2 Unsaved work

- **Dirty tracking** compares form state to `values` as sent.
- **The guard fires only when `name` is non-empty.** Leaving a pristine create form is not a loss and must not produce a dialog — a confirmation on an empty form trains users to click through confirmations, which is how the ones that matter get missed. Once a name exists, navigating away (Cancel, a nav link, a breadcrumb, Back, refresh, close) opens `ConfirmDialog`: "You've started a scenario that isn't saved. Leave without saving?" — **Stay** (focused) and **Leave**. `beforeunload` covers refresh/close (precedent `Tprm/Settings/Ai.jsx`).
- **No `sessionStorage` backup on this page.** `01` §6.3's backup exists for hour-long sessions in a fifteen-field instrument. Four fields, one of them a name, is a form a user re-fills faster than they read a restore prompt — and a backup that survives a tab close on a *create* page risks resurrecting a scenario that was in fact created, which §6.3 is about preventing.
- **Cancel** is a plain link to `urls.cancel` (`scenario.index`), intercepted by the same guard. It is `btn-secondary` and sits **after** Create, because the primary action is the one a user came here for.

### 6.3 Duplicate creation on a lost response

The realistic failure on a degraded link: the `POST` commits, the response is lost, and the user is looking at a form that appears not to have submitted. Retrying creates a second scenario with the next reference in the sequence — a duplicate that is invisible until two rows describe the same event, and expensive to merge because both may by then have been edited.

Rules:

1. After a **network error or 5xx** on submit, the page does **not** offer a bare "Try again" that re-posts. It offers **"Check whether it was created"**, which navigates to `scenario.index` sorted by newest. The banner reads: "We couldn't reach the server. Your scenario may or may not have been created — check the list before trying again, so you don't create it twice. Your entries are still on this page."
2. Entries are kept in form state regardless, and the `role="alert"` banner stays until the user navigates.
3. **419 is the exception**: the request was rejected before it reached the write path, so `01` §0.2's **Retry** re-sends safely.
4. Server-side idempotency is the real fix and is out of this spec's hands — flagged in §8.

### 6.4 What this form must not grow

Recorded here because the pressure to add fields to a create form is permanent:

- **No weighting, currency, FX rate, dates or narrative.** They are `02`'s, and the gate (`01` §5.4) is what tells a user they are still missing — with a link to the field, on the tab where it belongs.
- **No "Create and create another".** A team migrating twenty scenarios from a spreadsheet is a Phase-5 Excel-import job (plan §6.4), not a button that makes users re-pick an entity nineteen times. If it is added later, it becomes a two-submit-button form and standard §9 applies: intent in a `useRef`, injected with `transform()`, never `setData` before `post()`.
- **No AI-drafted narrative.** Plan §6.5 puts AI assistance in Phase 3 behind the `ai_intelligence` flag, provenance-marked and draft-until-human-edited. Nothing on this page anticipates it.

---

## 7. Accessibility, low bandwidth, numbers

### 7.1 Accessibility (WCAG 2.1 AA)

- **Headings:** `h1` "New scenario" (`PageHeader`). The card carries no heading of its own; if one is added it is an `h2`. The error summary is an `h2`.
- **Breadcrumbs:** **Scenario modelling** → **Scenarios** (→ `scenario.index`) → **New scenario** (current, no `href`), rendered by `PageHeader`'s `nav aria-label="Breadcrumb"` with `aria-current` on the last crumb.
- **Focus order:** breadcrumb links → `name` → `business_unit_id` → `nature` → OP combobox → **Create scenario** → **Cancel**. The read-only Reference row is text, not a control, and is reached in browse mode; it is not a tab stop.
- **Labels:** every field has a visible `<label for>`; required is the red `*` (`aria-hidden`) plus "(required)" in `sr-only` plus the `required` attribute (`01` §0.3) — on this form, `name` only. Placeholder text is never a label — the OP combobox's "Search the Basel OP event types" is a placeholder *and* the field has a real label above it.
- **Combobox:** Headless UI semantics and keyboard path exactly as `02` §10 — `↑`/`↓`, `Enter`, `Escape`, `Home`/`End`, type-to-filter, and the group headings announced inside the option's accessible name.
- **Not colour alone:** the amber callouts carry words and a `warning` icon (`aria-hidden`); the error summary carries words and links.
- **Contrast:** the sidebar is Navy with white text; on the page, help text is `text-gray-600` (≈7.6:1) and `text-gray-500` (≈4.8:1) is the floor. The Reference row's `text-gray-600` is deliberate — it is not an input, and rendering it in input-black would invite typing.
- **Focus management:** after a 422, focus goes to the summary heading. When an amber callout renders in place of a control (no business units, no taxonomy), focus stays where it was — the page is not rearranged under the user — and the callout is reachable in browse mode directly beneath the field it replaces.
- **Reflow:** single column at 320 px; both buttons full width and 44 px tall below `sm`; no horizontal scroll.
- **Reduced motion:** nothing animates beyond the Inertia progress bar.

### 7.2 Low bandwidth (100 kbps)

- **The lightest payload in the module.** The tenant's `business_units` (§A1.3: departments and divisions — BU-IT, BU-IA, BU-LG, BU-CX, BU-PB, BU-OP in the demo seed, so tens not thousands) + ~17 OP nodes + 3 natures ≈ 5 KB, about half a second. The OP names are long — OP7 is 158 characters (§A1.9, measured against `string(255)`) — so the 17 of them are the bulk of it. No `weightings`, `currencies`, `fxRates`, notes, sign-offs or grid definition (§2).
- **No risk-register search here**, unlike `02` §5.7 — the field is not on this form, so the one genuinely unbounded payload in the module is absent.
- **One write, ~0.3 KB.** In flight: "Creating…" with `aria-busy`; after 15 s the slow-connection line (`01` §0.4).
- **Nothing deferred, nothing polled, no images, no charts, no extra fonts.**

### 7.3 The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| Reference | **Nothing on this page.** Generated server-side as `SM-{YYYY}-{NNN}` at write time (`02` §5.1) and first rendered on the workspace after the redirect | "Assigned when you save" — the only text this row ever carries before save |
| Business-unit option count | `businessUnits.length` — drives the single-unit and no-unit states | The amber note (§5) |
| OP option count | `opEventTypes.length` | The amber callout (`02` §5.4) |
| Error count in the summary | `Object.keys(errors).length` from the 422 | The summary does not render |
| **Deliberately absent: a provisional reference** | — | §4. Showing one would display a number the database has not issued |
| **Deliberately absent: any date** | — | `designed_on` is `02` §5.9's field and is not pre-filled with today anywhere in this module |

---

## 8. Known gaps (also in the HANDOFF)

1. **No idempotency key on `POST /risk/scenarios`.** §6.3's "check whether it was created" flow is a mitigation, not a fix: on a link bad enough to lose responses, a user can still create a duplicate. A `Idempotency-Key` header (or a client-generated create token the server dedupes on) is the real answer and belongs to the backend engineer. Flagged because the failure is silent and the duplicates look legitimate.
2. **No tenant-scoped duplicate-name check.** Two scenarios called "Poor succession planning" in one entity are permitted and will be confused in a board pack. The workbook gives each scenario a reference for exactly this reason, and the reference is unique — but nothing warns a user at create time. Recommend a non-blocking `role="status"` note when `name` matches an existing scenario in the tenant: "A scenario with this name already exists: SM-2026-007." Needs a prop (`existingNameMatch`) that this spec does not require, so it is flagged rather than specified.
3. **~~The URL prefix is unresolved.~~ Resolved by §A1.11.** URLs are `/risk/scenarios/…` inside the existing `Route::prefix('risk')` group, with route **names** prefixed `scenario.` (singular) — deliberately different, because the permission namespace is `scenario.*` and aligning names with it makes `permission:scenario.view` on `scenario.index` read as one vocabulary. The nav section's `prefixes` is `['/risk/scenarios']`, which is what makes the sidebar highlight and auto-expand on every page of the module (`05` §2). This spec's earlier assumption is confirmed; no design change follows.
4. **No "promote from emerging risk" entry point.** Plan §4.4 has `EmergingRisk → Sm` as a one-click promotion to draft scenario. When Phase 4 builds it, this page needs an optional pre-fill (`?from_emerging_risk={id}`) and a visible provenance line — and per plan §6.5 any AI-drafted narrative arriving that way must be provenance-marked and draft-until-human-edited. Not designed here; noted so the page is not rebuilt around it later.
5. **`GET {scenario}/edit` (`scenario.edit`) is in §A1.11's route table and nothing in these specs uses it.** Editing lives inside the workspace (`scenario.show` + `?tab=…`, `01` §6.1), so `scenario.edit` is either a second edit surface — a third copy of the same fields to drift — or it renders the same `Workspace.jsx` in edit mode. Recommend the latter, so there is one URL for "editing this scenario". Needs a ruling before routes are registered; the nav has no entry for it either way (`05` §1), so it is reachable only from the workspace.
