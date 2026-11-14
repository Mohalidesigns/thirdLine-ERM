# 01. Scenario workspace — shell, lifecycle and the two shared tables

**Page:** new `resources/js/Pages/Sm/Scenarios/Workspace.jsx` · **Route:** `GET /risk/scenarios/{scenario}` (`scenario.show`, `permission:scenario.view`, behind `feature:scenario_modelling`) · **Phase:** 1 · **Tabs specified here:** the shell only. Overview & Governance is `02-overview-governance-tab.md`; Qualitative is `03-qualitative-tab.md`; the create form is `04-scenario-create.md`; the sidebar entry is `05-nav-entry.md`.

**Read this file first.** It holds the status-colour table (§2) and the permission→action table (§3) that `00`, `02` and `03` reference and do not restate, plus the shared states, accessibility baseline and low-bandwidth rules for the whole module.

**Sources of truth:** `docs/adr/0027-scenario-modelling-reproduces-the-workbook-math-exactly.md` — **Amendment 1 is authoritative and overrides the body and the plan wherever they differ**: §A1.2 (frozen `sm_scenarios` columns), §A1.3 (`business_unit_id`, not `entity_id`), §A1.6 (sign-off rows), §A1.7 (notes), §A1.8 (`STATUS_TRANSITIONS`, the vocabularies, the transition guards), §A1.10 (six permissions), §A1.11 (routes, flag, nav). Then `plans/scenario-modelling/SCENARIO-MODELLING-PLAN.md` §1, §4.5, §4.6, §6 · `plans/scenario-modelling/VOCABULARY-EXTRACTION.md` (verbatim workbook strings) · `docs/DEVELOPMENT_STANDARD.md` §3, §5, §8, §9, §14.

---

## 0. How to read these five specs

`00` index · `01` shell (this file) · `02` Overview & Governance tab · `03` Qualitative tab · `04` create form · `05` nav entry.

- **Page props named here are the contract.** ADR 0027 freezes the computation contract but not the Inertia payloads, so these specs name them and the backend engineer matches them. Anything marked **(prop decision)** is load-bearing: changing it changes what a screen can say.
- **Component props are proposals** for `frontend-engineer`. Rename freely if the behaviour is kept.
- **No application code in these files.** Class names quoted from existing pages (`btn-primary`, `badge`, `data-table`, `card`) are references to what already ships.
- **Vocabulary is the workbook's, verbatim.** Where `VOCABULARY-EXTRACTION.md` and the plan's prose disagree, the extraction wins and the divergence is flagged in the spec that carries it.
- **Every figure inside an ASCII layout sketch is sample data**, invented to show the shape of a screen — references, dates, percentages, exchange rates, counts. No number in a sketch is a claim about the product, and none may be copied into a test fixture or a demo tenant. Where a screen renders a real number, §"The numbers on the screen" in that spec names its source; a figure with no source there does not render.
- Read before specifying: `resources/js/Pages/Tprm/ThirdParties/Show.jsx` (tabbed record workspace), `resources/js/Pages/Bcms/Suppliers/Resilience.jsx` (`role="tablist"` precedent), `resources/js/Pages/Register/Index.jsx` (`DataGrid` + `useGridState`), `resources/js/Pages/Quantification/Scenarios/{Index,Show}.jsx` (the neighbouring scenario module — **different concept**, plan §2), `packages/ui/src/Components/{DataGrid/DataGrid,GridToolbar,DynamicForm,DynamicDetail,ConfirmDialog,PageHeader,StatusBadge}.jsx`, `app/Presenters/GridPresenter.php`, `app/Grids/{Column,GridDefinition}.php`, `docs/ui/kri-cycle/00-common.md` (the house spec format this directory matches).

### 0.1 Shells and primitives to reuse

| Need | Use | Note |
|---|---|---|
| Page frame | `AuthenticatedLayout` + `PageHeader` (`title`, `subtitle`, `breadcrumbs`, `actions`) | As `Tprm/ThirdParties/Show.jsx` |
| Any list | `GridPresenter` + `DataGrid` | Screen `00` only; standard §8 |
| Configurable-object form / read view | `FormSchemaPresenter` + `DynamicForm` / `DynamicDetail` | Screen `02`; standard §8 |
| Confirmation | `ConfirmDialog` (single string) or `Modal` (when the body needs a list or a field) | Both are Headless UI `Dialog`: focus trapped and restored |
| Empty state | `EmptyState` | Always with a "what to do" line |
| Inline errors | `InputError` from `@thirdline/ui` | Extended so the message has a stable `id` the field's `aria-describedby` points at (the existing `Field` in `KriForm.jsx` does not do this; these screens must) |
| Searchable single-select | Headless UI `Combobox` | The sanctioned source of a missing primitive (`docs/ui/kri-cycle/00-common.md` §1.3). Used for the OP event-type picker and the business-line picker |
| Charts | **None in Phase 1.** No chart on any Phase-1 screen | Standard §8 names Chart.js, but nothing in `resources/` imports it and no Phase-1 screen has a figure to draw. Phase 5's dashboard is where that decision is taken |

**Bespoke surfaces, justified once each:**

1. **`Components/Sm/ScenarioStatusChip.jsx`** — the lifecycle chip. `StatusBadge`'s `statusMap` cannot express the signed-off treatment (§2: navy fill + `lock` icon), and adding eight module-specific keys to a component five modules share is how a shared component acquires one module's vocabulary. Three screens need the chip and a copy would drift.
2. **`Components/Sm/CauseChecklist.jsx`** (`03`) — a grouped multi-select with a per-item note; `DynamicForm` has no repeater-with-children primitive.
3. **`Components/Sm/AllocationEditor.jsx`** (`03`) — a repeater carrying a live invariant (percent sum = 100). Same reason.
4. **`Components/Sm/NotesLog.jsx`** (`02`) — a list with its own write path (`POST` a note). A list is not a field, so it cannot be a schema entry.

Everything else is an existing primitive. If `frontend-engineer` needs a fifth bespoke surface, that is a conversation, not a decision.

### 0.2 Shared states (every screen in this module; not repeated per file)

| State | Behaviour |
|---|---|
| **Navigating** | The existing Inertia progress bar. The previous page stays interactive until the response arrives. No full-page spinner |
| **Network failure on a visit** | Stay put. `role="alert"` banner: "Couldn't reach the server. Check your connection and try again." + **Try again** repeating the visit |
| **Non-Inertia response** (429 / 502 / HTML) | Intercept Inertia's `invalid` event; same banner with the matching sentence (429: "Too many requests. Wait a minute, then try again."). Never let Inertia's debug modal render |
| **Session expired (419/401)** | On a form screen: **do not discard state**. Modal: "Your session has expired. Sign in again in a new tab, then come back and press Retry. Your entries are kept." Retry re-sends (Inertia reads the refreshed `XSRF-TOKEN`). Second 419 offers a full reload as a last step. On read-only screens a redirect to sign-in is acceptable |
| **Permission denied (403)** | The application's 403 page. `NavigationPermissionGateTest` keeps the nav entry hidden without `scenario.view`, so a 403 means a typed or stale URL. **In-page, a control the user cannot use is absent, not disabled** — the single exception is §5.4's `aria-disabled` lifecycle button, which teaches why it cannot be used yet |
| **Not found (404)** | Out-of-tenant ids are 404, never 403 (standard §7). The standard 404 page |
| **Server error (500)** | Standard error page. Forms keep their values (`useForm`) and show "Something went wrong on our side. Nothing was changed. Try again." |
| **Lifecycle refusal** | A transition the status does not allow answers with a **flash message in a `role="status"` banner**, not a 403 — the user is allowed to try, the machine says no and says why (e.g. "This scenario is in validation. It can't be signed off until it is validated."). Standard §3: the rule lives in the controller |
| **Feature flag off** | `feature:scenario_modelling` 404s the whole route group. There is no "coming soon" screen: a flag that is off is off |

### 0.3 Shared accessibility baseline (WCAG 2.1 AA, in addition to each screen's own section)

- One `h1` (the `PageHeader` title). Sections `h2`, cards `h2`/`h3`, nothing skips a level.
- Everything operable by keyboard; no keyboard trap except modals (Headless UI traps and restores).
- Visible focus: the existing `focus:ring-2` Navy ring on every interactive element.
- Icons (`material-symbols-outlined` / `Icon`) are `aria-hidden="true"`; the meaning is always also in words. Each sits in a fixed-size `overflow-hidden` box so a failed font load cannot reflow the layout.
- `role="status"` (polite) for state changes. `role="alert"` only for something that has just failed after a user action, never on page load.
- Errors: `aria-invalid="true"` on the control, `aria-describedby` → the error element's id **and** any help-text id.
- Every input has a visible `<label for>`. Placeholder text is never the label. Required is the red `*` (`aria-hidden`) **plus** `required`/`aria-required` **plus** "(required)" in `sr-only`.
- Reflow (1.4.10): no horizontal page scroll at 320 CSS px. Tables are the only exception, inside `overflow-x-auto`.
- Touch targets ≥ 44×44 CSS px for primary actions below `md`.
- Colour tokens and contrast: the table in `docs/ui/kri-cycle/00-common.md` §3 is this product's and applies unchanged — `text-gray-600`/`-500` for secondary text, `#718096` and `#9CA3AF` are **not** text colours, Gold `#D4AF37` is never text and never the sole indicator.
- **Colour is never the only signal.** Every chip in §2 carries words, and two carry an icon as well.

### 0.4 Shared low-bandwidth rules (100 kbps ≈ 12 KB/s, so 100 KB ≈ 8 s)

- No new library, image, web font or chart in Phase 1.
- **Nothing polls.** Anything that changes after load (notes, gate readiness) is refreshed by an explicit control that reloads only the prop that changed (`only: [...]`).
- Below-the-fold and conditional content is not rendered until needed.
- Every write has a visible in-flight state ("Saving…", button `disabled` **with** `aria-busy="true"`) and, after 15 s, the line "Still working. Your connection is slow. Don't close this page."
- **No client timeout gives up on its own.** A write that might have landed must not be retried blind: after a network error on a write the screen first reloads the affected prop to learn the truth (§6.4).

### 0.5 The "numbers on the screen" rule (standard §5)

Each spec ends with a table of every figure, its named source, and what renders when the source is absent. A figure with no named source is cut, not rendered behind a fallback. `?? 0` is allowed only where zero is a true statement about an empty set (a count). It is **not** allowed for a rate, a percentage, a score or a day count:

- An unset date renders **`—`**, never "0 days". (`GridTable.jsx:7` already does `cell.text ?? '—'`.)
- An unanswered yes/no renders **"Not assessed"**, never "No".
- An empty allocation renders **"Not allocated"**, never "0%".
- A scale nobody has answered renders **"Not yet assessed"**, never the lowest grade.

---

## 1. Purpose and the user

**Who opens it.** An operational-risk designer in the second line (a Risk Analyst or Operational Risk Manager) who is turning a risk theme into an assessable scenario, and later a validator and a signer who are not the person who wrote it. They arrive from the index (`00`) or from a link a colleague sent them.

**In the first ten seconds** they must know: which scenario this is (reference and name), what state it is in, whether it is frozen, what they are allowed to do to it, and which section they are looking at.

**Design stance.** This is a long-form instrument, not a record page. A designer sits in it for an hour. The workspace mirrors the LS workbook tab for tab so a risk team moving off the spreadsheet recognises its own structure — but the workbook's *presentation* (one sheet, everything visible, "bold the accurate causes") is not carried over where a screen can do better, and every such departure is stated with its reason.

**What must be true of the shell:**
- The lifecycle is visible and the next legal action is one control, not a hunt.
- Read-only is unambiguous. A user must never type into a field and discover on Save that it was frozen.
- Unsaved work is never lost silently, and a tab switch is treated as navigation.
- The client holds **no** lifecycle state machine. It renders what the server sends.

---

## 2. Status-colour table — canonical, defined once here

Eight lifecycle statuses (plan §4.6). The chip is `Components/Sm/ScenarioStatusChip.jsx` (§0.1.1), driven by `scenario.status` with `scenario.status_label` as its words.

| `status` | Words on the chip | Classes | Icon (`aria-hidden`) | Reads as |
|---|---|---|---|---|
| `draft` | Draft | `badge badge-status-draft` (`bg-gray-100 text-gray-700`) | — | Nobody has started |
| `in_design` | In design | `badge badge-status-pending` (`bg-blue-100 text-blue-700`) | — | The first line is working on it |
| `designed` | Designed | `badge badge-status-active` (`bg-green-100 text-green-700`) | — | Design complete, not yet queued for validation |
| `in_validation` | In validation | `badge badge-medium` (`bg-yellow-100 text-yellow-800`) | — | In a reviewer's hands; it may come back |
| `validated` | Validated | `badge badge-status-completed` (`bg-purple-100 text-purple-700`) | — | Cleared validation, awaiting sign-off |
| `signed_off` | Signed off | `badge bg-[var(--color-primary)] text-white` | `lock` | **Frozen.** White on Navy `#1A365D` ≈ 12:1 |
| `returned_for_rework` | Returned for rework | `badge badge-status-overdue` (`bg-red-100 text-red-700`) | `priority_high` | Sent back; editable again |
| `archived` | Archived | `badge badge-status-draft` | `inventory_2` | Out of circulation |

**Why these pairings.** Gray = nothing happening (draft, archived — separated by words and by the archive icon). Blue = being worked. Green = this stage's work is done. Yellow = in someone else's hands. Purple = cleared a gate. Red = came back. Navy = terminal and frozen: signed-off is the one state an examiner scans for, so it is the only chip that inverts, and it uses the theme's own `--color-primary` rather than a new shade (role rule: reference existing tokens).

`StatusBadge`'s own map is **not** extended: it has no treatment for the navy chip or for icons, and `validated` already means purple there, which this table keeps.

**Two extra markers, rendered beside the chip, never instead of it:**

| Marker | Prop | When | Presentation |
|---|---|---|---|
| **Frozen** | `scenario.frozen === true` | `signed_off`, `archived` | A `lock` icon inside the chip plus the workspace banner (§5.5) |
| **Previously signed off** | `scenario.previous_signoff` set | A scenario that left `signed_off` by revalidation and is now editable | `text-xs text-gray-600` line under the chip: "Signed off {date} by {names}; reopened for revalidation." This is the only place the earlier sign-off survives on screen, and it must survive — a validator re-reading a reopened scenario needs to know it was once approved |

**Overdue review is not a status.** It is a separate flag (§4.3) so a signed-off scenario can be both frozen and past its review date. The two never collapse into one chip.

---

## 3. Permission → action table — canonical, defined once here

**Six permissions, frozen by ADR 0027 Amendment 1 §A1.10** — not the nine plan §4.1 proposed. `PermissionCatalogCoversRoutesTest::every_permission_in_the_catalog_is_held_by_some_role` fails on a declared permission no role holds, so declaring Phase 2–5 permissions now would grant authority over routes that do not exist, on a role-assignment screen an administrator reads as real. Declared in a new `'Scenario modelling'` block in `RiskPermissionCatalog::modules()`, after `'Quantification'`.

| Permission | §A1.10's description (an authority, not a label) | Gates in Phase 1 |
|---|---|---|
| `scenario.view` | "See operational-risk scenarios, their causes, allocations and sign-offs." | The index, the workspace read-only, and the index grid's CSV/XLSX export — `routes/web.php:297` puts the whole `risk.grids.{grid}` group behind `can:view-grid,grid`, which resolves to `ScenariosGrid::permission()` |
| `scenario.create` | "Design a new scenario and give it a reference." | **New scenario** on the index; the create form (`04`) |
| `scenario.update` | "Change a scenario's identity, qualitative report, causes and business-line allocation." | `PUT scenario/{scenario}`, `…/causes`, `…/allocations` — every edit on both tabs, while the status is editable (§5.5) |
| `scenario.validate` | "Move a scenario through validation — return it for rework or mark it validated." | **`POST scenario/{scenario}/status` — every lifecycle transition, including `draft → in_design` and `in_design → designed`** (§5.2) |
| `scenario.sign_off` | "Record a sign-off against a scenario as one of its three sources." | `POST scenario/{scenario}/signoffs` |
| `scenario.note` | "Add an assumption, dissent, challenge or workshop minute to the scenario record." | `POST scenario/{scenario}/notes` |

**Not declared in Phase 1** (§A1.10, with the phase that needs each): `scenario.design` → **Phase 2** — nothing in Phase 1 is quantitative, and `scenario.update` covers the qualitative tabs; `scenario.manage_actions` → Phase 3; `scenario.run_export` and `scenario.manage_library` → Phase 5. Adding them later costs no migration, because `scripts/deploy.sh` runs `RolesAndPermissionsSeeder` whose `syncCatalog()` is additive. **`scenario.delete` is refused outright** — soft delete goes through the policy's `delete` ability under `scenario.update`.

Two consequences of this table that the UI must reflect, because both are surprising:

- **`scenario.note` is separate from `scenario.update`, deliberately.** §A1.10's reasoning: editing a scenario is first-line work, recording a dissent against somebody else's is second-line work, and one permission for both lets the designer write the dissent log the trail exists to keep. So the notes panel (`02` §7) is gated on `can.note`, **not** `can.update` — and per §A1.10's role grants, `risk-analyst`, `risk-owner` and `compliance-officer` hold `scenario.view` + `scenario.note` **only**. A risk owner can open a scenario they cannot edit and record a dissent against it. That is the module's separation of duties made visible, and `02` §7.2 renders the add-note form on `can.note` alone.
- **Every lifecycle move needs `scenario.validate`, including the first two.** With §A1.10's grants, only `risk-manager`, `chief-risk-officer` and `super-admin` hold it. So a designer holding `scenario.update` alone can fill in every field and **cannot advance the scenario out of `draft`**. The header must therefore never render a transition button for a user who lacks `scenario.validate` (§5.2), and when an editable scenario's user holds `update` but not `validate`, the readiness strip says so in plain words: "Ready to submit — ask a risk manager to move it forward." Silence there would read as a broken button.

**(prop decision)** `can: {view, create, update, validate, sign_off, note}` is sent to the workspace and `can: {create}` to the index. `can` decides whether an *editing affordance* renders. **It never decides which lifecycle buttons appear** — `transitions` does (§5.2), because the server has already filtered that list by permission *and* by status. A client that re-derives the machine from `can` will eventually offer a transition the controller refuses.

---

## 4. Props the workspace expects

```
{
  scenario: {
    id, uuid, reference, name, status, status_label,
    frozen: bool,
    business_unit: {id, name}|null,                             // §A1.3 — NOT an entity
    nature: string|null, nature_label: string|null,             // "Group scenario" | "Standard scenario" | "Local scenario"
    weighting: string|null, weighting_label: string|null,       // column is `weighting`, not `weighting_factor`
    op_event_type: {id, name, basel_level_1: string|null}|null, // §A1.9 — `name` carries the "OP7 …" prefix
    currency: string,                                           // NOT NULL default 'NGN', so never absent
    fx: {fx_rate_id: int|null, rate: string|null, rate_date: 'Y-m-d'|null,
         from: string|null, to: string|null}|null,              // §A1.2 rows 13-15 — three columns, one object
    risk: {id, risk_code, title, url}|null,
    designed_on: 'Y-m-d'|null, validated_on: 'Y-m-d'|null, review_due_on: 'Y-m-d'|null,
    created_by: {id, name}|null, updated_by: {id, name}|null, updated_at,
    previous_signoff: {signed_at, sources: [string]}|null       // §2 marker
  },
  review: {due_on: 'Y-m-d'|null, days_overdue: int|null}|null,  // §4.3 — server-computed over `review_due_on`
  gate: {ready: bool, missing: [{tab: 'overview'|'qualitative', field: string, label: string}]}|null, // §5.4
  transitions: [ {to: string, label: string, variant: 'primary'|'secondary'|'warning'|'danger',
                  requires_reason: bool, confirm: string|null, opens_modal: 'sign_off'|'reason'|null} ], // §5.2
  tab: 'overview'|'qualitative',
  tabs: [ {key, label, url, live: bool} ],                      // §5.1 — Phase 1: two live entries only
  roadmapNote: string|null,                                     // §5.1 deferred-tab disclosure
  can: {view, create, update, validate, sign_off, note},        // §3 — six, no `design`
  qualitativeSummary: {          // ALWAYS sent — the sign-off modal is shell-level (§5.6.1)
    causes_selected: int,
    allocation_lines: int,
    allocation_total: string|null,        // decimal string, §4.5 — null = not allocated, never "0.00"
    reputational_label: string|null,      // the server's words, incl. "Impact: not yet assessed"
    legal_label: string|null,
    insurance_risk_label: string|null,
    financial_risk_label: string|null,
    eligible_to_transfer_label: string    // never null — the column is NOT NULL (§A1.2 row 25)
  },
  suggestedReviewDueOn: 'Y-m-d'|null,     // sign-off modal §5.6.4 — server-computed, never in the browser
  values: object|null,        // Overview tab ONLY — edit state keyed by the 02 §2 field codes
  qualitative: object|null,   // Qualitative tab ONLY — the full shape is 03 §2
  errors: {},                 // 422 keys: 02 §2 field codes, or 03 §2's causes/allocations/exposures keys
  signoffs: [ {id, source: 'source_1'|'source_2'|'source_3', source_label,
               signatory_name: string, role: string,             // snapshots, NOT NULL (§A1.6)
               user: {id, name}|null,                            // nullable — the snapshot is the evidence
               decision: 'approved'|'returned_for_rework',       // TWO values only (§A1.6)
               is_independent_challenge: bool, comment: string|null,
               signed_at: string|null, is_current: bool} ],      // §4.6 latest-row-per-source
  notes: [ {id, kind, kind_label, body, author_name: string, created_at} ],  // `kind`, and a name snapshot (§A1.7)
  notesMeta: {shown: int, total: int},                            // §4.4 capping
  noteKinds: [ {value, label, hint} ],                            // `assumption`|`dissent`|`challenge`|`workshop_minute`
  baseCurrency: string,                                           // tenant setting — never inferred from the browser
  urls: {index, scenario, causes, allocations, status, signoffs, notes, notesEarlier, riskSearch, fxRates: string|null}
}
```

**(prop decision) Field names are the frozen column names** (§A1.2): `designed_on` / `validated_on` / `review_due_on` — not the plan's `designed_at` / `validated_at` / `review_due_date`. §A1.2 renamed them because `_on` is this product's suffix for a Lagos calendar day and `_at` for an instant (ADR 0026), and because "§14's defect was a `date` read with `->isPast()`, and a column named `_at` invites exactly that." The props carry `Y-m-d` strings and are formatted per §4.1. Likewise `background` (not `description`), `weighting` (not `weighting_factor`), `business_unit_id` (there is no `entity_id` — §A1.3 refused it), and `reputational_exposure` / `legal_compliance_exposure`.

**(prop decision) `urls` mirrors §A1.11's route table exactly** — `scenario` (`PUT`), `causes` (`PUT`), `allocations` (`PUT`), `status` (`POST`), `signoffs` (`POST`), `notes` (`POST`). The Qualitative tab writes **three** endpoints, not one; see `03` §2 and §5.6 for why that shapes the tab's Save buttons.

**(prop decision) `schema`/`detail` are deliberately absent.** Standard §8 points configurable objects at `FormSchemaPresenter` + `DynamicForm`, but `02` §2 records four verified blockers (no relationship picker, no three-state bool, per-status requiredness, fixed 3-row textareas) and specifies the Overview tab as a hand-composed form instead. If the coordinator reverses it, `schema`/`detail` return and `02` §2 is rewritten — the field codes, option sources and messages in `02` §3–§5 are the contract either way.

**(prop decision) `qualitativeSummary` is always sent, on both tabs.** The sign-off modal recaps the qualitative position (§5.6.1) and can be opened from either tab, but the full `qualitative` payload arrives only on the Qualitative tab (§4). Without this small summary the modal would either ship the whole checklist everywhere or show a recap that is empty on the Overview tab. Every label in it is the **server's** wording — the client never derives "not yet assessed" from a null, because that derivation is exactly the judgement the module's whole §0.5 discipline exists to keep on one side of the wire.

**(prop decision) `urls.riskSearch` is a shell prop**, not an Overview-tab prop, so a linked risk can be searched from any tab if a later phase needs it (Phase 4's register 360). `02` §5.7 is its only Phase-1 consumer.

**(prop decision) The server sends only the active tab's data.** `values` arrives when `tab='overview'` (`02` §8); `qualitative` arrives when `tab='qualitative'` (`03` §2); neither arrives for the other. Rationale: the Qualitative payload (the 23-code checklist + every business-unit option + allocation lines) is the largest thing on the page, and a user landing on Overview at 100 kbps should not pay for it (§8). `qualitativeSummary` is the small exception, for the reason above.

**(prop decision) `signoffs` and `notes` arrive on both tabs**, because the frozen banner and the sign-off panel are shell-level and a validator reading Qualitative must still see who signed. They are small and capped (§4.4).

### 4.1 Dates

`designed_on`, `validated_on`, `review_due_on` are `Y-m-d` strings. Format them **without a timezone conversion** — split the string, or format from `Date.UTC(y, m-1, d)` with `timeZone: 'UTC'`. `new Date('2026-11-09')` plus local formatting can shift the day, and every date here is date-against-date (standard §14). Display form: **"Mon 9 Nov 2026"**. §A1.2 named them `_on` for exactly this reason: `_on` is a Lagos calendar day, `_at` an instant (ADR 0026), and "§14's defect was a `date` read with `->isPast()`, and a column named `_at` invites exactly that."

Timestamps (`updated_at`, `signed_at`, note `created_at`) are shown in **Africa/Lagos** with the words "Lagos time" in a tooltip or caption on first use per page. Any client-side "is this date past" check means past in `Africa/Lagos` via `Intl.DateTimeFormat` with that `timeZone` — never `new Date().toISOString()`, which is yesterday for the first hour of a Lagos day. §A1.2 gives the server-side form of the same rule: compare `review_due_on` with `whereDate(..., '<', now()->toDateString())`.

### 4.2 Why `review.days_overdue` exists at all

The index (`00`) shows **no** day count: a `DataGrid` row carries only `{id, href, cells, actions}` (`GridPresenter::row()`), so a per-row day count would need a bespoke table, and computing it in the browser would break §4.1. The workspace header can carry it because the controller computes it server-side, in `Africa/Lagos`, against the day boundary (standard §14). `null` means "no review date set" and renders `—` — it never renders `0`.

### 4.3 Overdue review, as the user sees it

When `review.days_overdue > 0`, an amber callout under the header (`bg-amber-50 border-amber-200 text-amber-900`, warning icon, `role="note"` — **not** `alert`; it is page content, not a live event):

> "This scenario was due for review on Mon 9 Nov 2026 — {n} day{s} ago. {A signed-off scenario stays in force until it is reviewed.|Review it and update the next review date.}"

The second sentence varies by `scenario.frozen`. When `review.due_on` is set and not past: no callout; the date shows in the Overview tab's Dates & review section. When `review.due_on` is null: no callout, and the Overview tab says "No review date set" (never "0 days", never "Current").

### 4.4 Notes capping

`notes` carries the **latest 20** in descending `created_at` order, with `notesMeta.total`. When `total > shown`, the panel ends with a **Load earlier notes** button that reloads `only: ['notes', 'notesMeta']` and prepends. Nothing auto-loads: a scenario workshopped over a year can carry hundreds of notes and the panel is above the fold on the Overview tab.

### 4.5 Decimals arrive as strings

`allocation_total`, allocation percents and `fx.rate` are **decimal strings**, not JSON floats. §A1.5 requires the sum-to-100 comparison to be made "as decimals at the column's precision (string/`bccomp`, never float `==`)", and `percent` is `decimal(5,2)`. A float that reaches the browser has already been through `json_encode`, so `100.00` can arrive as `100` and `33.33` as `33.32999999999999829…`; comparing or summing those in JS reintroduces exactly the artefact the server was told to avoid. `03` §5.2 gives the matching client-side rule.

### 4.6 Sign-offs are latest-row-per-source

§A1.6 puts **no unique key on `(sm_scenario_id, source)`**, deliberately: sign-off freezes and post-sign-off edits require revalidation, so source 1 signs, the scenario is returned, and source 1 signs *again* — a unique key would force an UPDATE over the record the table exists to protect. **The current sign-off for a source is the latest row by `signed_at`.**

So `signoffs` may carry several rows for one source. Each has `is_current`, the server's determination, and `02` §6 renders **only the current rows** in the panel; superseded rows are history and belong to Phase 3's Sign-off & audit tab. The client never sorts or picks "latest" itself — with `signed_at` nullable (§A1.6, standard §13) and only non-null rows counting toward the lifecycle, that logic is the server's, and duplicating it in JS is how two surfaces come to disagree about who signed.

### 4.7 Sign-off names and roles are snapshots, not joins

§A1.6 makes `signatory_name` (`string(200)`) and `role` (`string(100)`) **NOT NULL snapshots**, with `user_id` nullable and `nullOnDelete`, following ADR 0026 §5's freeze of `email`+`name` on `kri_return_notice_recipients`: "a sign-off whose signatory has left must still say who signed it. 'Signed by (deleted user) 4' is not evidence."

Two consequences for the screens:

- The sign-off modal must **collect a name and a role as text**, not merely pick a user (`01` §5.6.2). Picking a user may pre-fill them, but the stored evidence is the typed snapshot.
- **`role` is a free-text capacity, not a platform role name** — "Head of Operational Risk", "Second Line — Independent Challenge". §A1.6 refuses Spatie role names because they are an implementation detail and renaming one "would silently falsify every historical sign-off storing it". So the field is a text input with suggested values, **never** a `select` bound to `Role::pluck('name')`. A select of platform roles would look tidier and would be wrong in five years.

`notes[].author_name` is a snapshot for the same reason (§A1.7): "an anonymous dissent cannot be followed up, which is worse than none."

---

## 5. Layout

### 5.1 The tab strip and the deferred tabs

Canonical tab order, mirroring the workbook (plan §4.5 item 3):

**Overview & Governance · Qualitative · Frequency · Impact · Insurance · Scorecard · Variants · Actions · Sign-off & audit**

**Live in Phase 1: Overview & Governance, Qualitative.** The other seven are Phase 2 (Frequency, Impact, Insurance, Scorecard), Phase 3 (Variants, Actions, Sign-off & audit).

**Decision: deferred tabs are absent from the strip, and their existence is disclosed once in prose.**

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Scenario modelling  ›  SM-2026-014                                            │  breadcrumbs
│ Loss of key staff — succession planning failure        [Signed off 🔒]        │  h1 + chip (§2)
│ Group scenario · OP5 Loss of key staff/talent management · Keystone Bank Nigeria│  subtitle
│ ────────────────────────────────────────────────────────────────────────────│
│ ⓘ This scenario was signed off on … and is frozen. Changing it requires      │  frozen banner (§5.5)
│   revalidation.                                          [Reopen for revalidation]
│ ────────────────────────────────────────────────────────────────────────────│
│  Overview & Governance │ Qualitative                                          │  nav, 2 links
│ ────────────────────────────────────────────────────────────────────────────│
│  ⓘ This workspace will gain Frequency, Impact, Insurance, Scorecard, Variants │  roadmapNote (§5.1)
│    and Actions sections in later releases. Quantitative entry is not          │
│    available yet: this scenario carries governance and qualitative analysis   │
│    only.                                                                      │
│                                                                               │
│  ┌──────────────────────────── tab panel ────────────────────────────────┐    │
│  │                                                                        │    │
│  └────────────────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Reasoning, because "disabled tabs" was the obvious alternative:**

1. Nine tabs of which two work is worse than two tabs and a sentence. Seven disabled tabs are seven dead tab stops on every visit, for every keyboard and screen-reader user, and WCAG offers no way for a disabled tab to say *when* it becomes enabled.
2. "Phase 2" is internal vocabulary that means nothing to a customer's risk team. The note says what they can and cannot do, in their words.
3. Absent tabs cannot be deep-linked, so no `?tab=frequency` URL exists to render empty or 404.
4. Standard §5's discipline applies to capability as well as to numbers: a strip of greyed-out tabs implies a quantitative module the product does not have. The note states the true position.
5. When Phase 2 lands, tabs appear. There is no "was disabled, now enabled" state for a user to misread as newly broken.

**`roadmapNote` is a server-sent string**, rendered verbatim in a `role="note"` strip, so the copy can change per phase without a front-end release. When it is `null` the strip does not render.

**Tabs are links, not buttons.** `<nav aria-label="Scenario sections">` of `<a href>`, the current one carrying `aria-current="page"`. Each tab is a URL (`/scenarios/{id}?tab=qualitative`) because a design session lasts hours and a validator must be able to send a colleague "the qualitative tab of SM-2026-014" — with `useState` tabs that URL does not exist (the `Tprm/ThirdParties/Show.jsx` pattern), and a reload loses the section. This also means a tab switch is a **navigation**, so the unsaved-changes guard (§6.3) intercepts it for free instead of needing a hand-rolled equivalent.

**Unknown or deferred `?tab=`:** ignored. The server resolves it to `overview` and sends `tabs` with `live: true` only for the two real ones. A bookmark from a later phase or a hand-typed URL therefore lands somewhere useful rather than 404ing. When the requested tab was not `overview`, the server sends a `flash` the shell renders once as `role="status"`: "That section isn't part of this release yet. Showing Overview & Governance."

### 5.2 Header actions — the lifecycle buttons

`PageHeader`'s `actions` slot renders `transitions` **verbatim, in the order sent**, as buttons. The shell holds no state machine (standard §3: lifecycle rules stay in controllers). §A1.8 freezes the map as `Scenario::STATUS_TRANSITIONS`, a real const on the model — assertable by a test, unlike `LossEventController`'s inline `$allowedTransitions`, whose docblock cross-reference §A1.8 records as pointing at a const that does not exist.

**Every transition posts to one route:** `POST /risk/scenarios/{scenario}/status` (`scenario.status`), gated by **`scenario.validate`** — including `draft → in_design`. There is no per-transition permission and no `scenario.design` in Phase 1 (§3).

The frozen map, and the button each edge renders as:

| From | To | Button words | `variant` | Confirmation |
|---|---|---|---|---|
| `draft` | `in_design` | **Start design** | `secondary` | none |
| `draft` | `archived` | **Archive** | `danger` | `ConfirmDialog variant="danger"` — "Archive a scenario that was never designed? It stays on the audit trail and leaves every live list." |
| `in_design` | `designed` | **Design complete** | `primary` | `Modal` — the gate pre-flight (§5.4) |
| `in_design` | `returned_for_rework` | **Return for rework** | `warning` | `Modal` with a required reason (§5.3) |
| `designed` | `in_validation` | **Submit for validation** | `primary` | `ConfirmDialog variant="primary"` — "Submitting hands this scenario to a validator. You won't be able to change it until it is validated or returned." |
| `designed` | `in_design` | **Reopen design** | `secondary` | none — it is a step backwards into an editable state, and undoing it is one click |
| `in_validation` | `validated` | **Validate** | `primary` | `ConfirmDialog variant="success"` — names the scenario and says the validator is recorded against it. The server stamps `validated_on` |
| `in_validation` | `returned_for_rework` | **Return for rework** | `warning` | `Modal` with a required reason (§5.3) |
| `validated` | `signed_off` | **Sign off** | `primary` | `Modal` — the sign-off flow (§5.6). **Blocked by §A1.8's guard until ≥1 current sign-off has `decision = approved`** |
| `validated` | `returned_for_rework` | **Return for rework** | `warning` | `Modal` with a required reason (§5.3) |
| `signed_off` | `returned_for_rework` | **Reopen for revalidation** | `warning` | `Modal` (§5.5) — the editable path out |
| `signed_off` | `in_validation` | **Send back into validation** | `secondary` | `ConfirmDialog variant="warning"` — the read-only path (§5.5) |
| `signed_off` | `archived` | **Archive** | `danger` | `ConfirmDialog variant="danger"` |
| `returned_for_rework` | `in_design` | **Resume design** | `primary` | none |

`archived` has **no** outgoing edges — `Scenario::STATUS_TRANSITIONS['archived'] = []`, so it is terminal in v1 (§9.1). The labels above are `config('sm.transition_labels')`, keyed by target status except for `returned_for_rework`, whose word depends on where it came from (`from>to`). A label the server does not send does not render: the shell has no fallback vocabulary.

Plus, always when `can.update` and the status is editable: **Save** inside the tab panel (§6.1), never in the header. The header carries lifecycle only, so a user never has to wonder whether the header button saves their typing.

**Two edges deserve a word, because they are not obvious:**

- **`designed → in_validation` and `in_design → designed` are separate.** A designer may finish several scenarios and hand them to a validation committee as a batch: `designed` is "my work is done", `in_validation` is "it is in the queue". Collapsing them removes the batch case and makes the designer's assertion and the hand-off one click.
- **`signed_off` has two ways out, and they are different acts.** `Scenario::STATUS_TRANSITIONS['signed_off'] = ['returned_for_rework', 'in_validation', 'archived']`. **Reopen for revalidation** (`→ returned_for_rework`) makes the scenario *editable* so the person who spotted the problem can fix it. **Send back into validation** (`→ in_validation`) keeps it *read-only* and puts it in front of a validator — the right move when the concern is "is this still right?" rather than "this is wrong, change it". One button cannot serve both, and merging them would either freeze a record that needs editing or let a signer edit without a second pair of eyes. §5.5 gives each its own copy, because a user who picks one and gets the other's behaviour will think the button failed.

**Guards the server enforces (§A1.8) and the UI must not fake:** `in_design → designed` requires the sum-to-100 allocation, a non-null `risk_taxonomy_node_id`, ≥1 selected cause, and non-empty `background` and `rationale` — that is `gate` (§5.4). `validated → signed_off` requires ≥1 current sign-off with `decision = approved`. A wrong-status attempt answers with a **flash message, not a 403** (`LossEventController::updateStatus()` is the precedent §A1.8 names).

**Button mechanics — standard §9.** Every transition button issues its own `router.post(urls.status, { to, reason? })` with the target passed **as an argument to that call**. They do **not** share a form and do **not** use `setData('to', …)` before posting: `setData` is asynchronous, and `post(url, { data })` is no rescue because Inertia assigns `data` after spreading `options`. Standard §9 records the exact failure this prevents — *"Phase 4.5's review panels would have approved a submission when the reviewer clicked Return for rework."* With fourteen edges now sharing one route and one `to` parameter, that bug would approve a scenario when the user clicked Archive. If any Phase-1 form does carry two submits, it uses the `useRef` + `transform()` pattern from `Rcsa/Worksheet.jsx`.

### 5.3 Return for rework — the reason modal

`Modal` (`maxWidth="lg"`), title **"Return SM-2026-014 for rework?"**. Available from `in_design`, `in_validation` and `validated` — three edges in §A1.8's map lead to `returned_for_rework`, and one modal serves all three.

- A required `textarea`, label **"What has to change?"**, help text "The designer sees this as the reason the scenario came back, and it is recorded against the scenario permanently."
- Buttons **Cancel** (focused first) and **Return for rework** (`btn-warning`). In flight: both `disabled`, "Returning…".
- On success the workspace reloads at `returned_for_rework`, focus moves to the status banner, `role="status"`: "Returned for rework. It can be edited again."
- Empty reason: inline error under the field, "Say what has to change. A return with no reason gives the designer nothing to work from."

**(prop decision) The reason must be persisted, and how is unresolved.** The trail only works if the reason survives, and §A1.7 makes notes append-only rows written by their own route (`POST {scenario}/notes`, `scenario.note`). Two options, and the UI is specified for the first:

1. **Preferred:** `POST {scenario}/status` accepts `{to, reason}` and the server writes the `challenge` note in the same transaction. One request, atomic, and a return can never be recorded without its reason.
2. If the status route takes no reason, the modal must post twice — status, then note — and a failure on the second leaves a returned scenario with no recorded reason. That is a real defect, so the modal would have to surface it: "Returned, but the reason wasn't saved. Add it as a challenge note." Awkward, and the reason it is awkward is the argument for option 1.

§A1.11's route table does not say which. **Flagged for the backend engineer.**

**No independent-challenge checkbox here.** This spec's first draft had one; §A1.6 puts `is_independent_challenge` on a **sign-off row**, not on a status change, and there is no column to hold it on a transition. A returned scenario's challenge is recorded as a `challenge` **note** (§A1.7) and, when the challenger is also a signer, as the flag on their sign-off row (§5.6.3). Conflating the two would put a governance flag where no schema exists to store it.

### 5.4 The gate pre-flight — `gate`

`gate` mirrors **§A1.8's frozen guard on `in_design → designed`** and nothing else: the allocation summing to 100.00, a non-null `risk_taxonomy_node_id`, at least one selected cause, and non-empty `background` and `rationale`. It is computed by the server on every save and every transition attempt, across **both** tabs, so the shell can tell the truth about readiness without either tab knowing the other's fields. The per-field detail is `02` §4 and `03` §7; §A1.8 is the authority and where the two could be read as differing, it wins.

- **While the status is editable and `gate.ready === false`**, a `role="note"` strip under the tab strip: "Not ready to mark designed — {n} required field{s} still missing." with a `<details>` disclosure listing them, each a link to its tab and field: "OP event type (Overview & Governance)", "Business-line allocation (Qualitative)". Links navigate (`?tab=…#field-…`) and move focus to the field.
- **Design complete** opens a `Modal` rendering `gate.missing` as a checklist. When `gate.ready === false` the confirm control is `aria-disabled="true"` (**still focusable**) rather than `disabled`, so a keyboard user is not left facing a dead control with no reason; activating it moves focus to the first missing field (navigating tabs if needed) and announces "OP event type is required before this scenario can be marked designed." This is the `Kri/MyReturn` submit rule (`docs/ui/kri-cycle/07-complete-my-kris.md` §3.6) applied to a lifecycle gate.
- When `gate.ready === true` the same modal is a plain confirmation: "Mark SM-2026-014 as designed? A validator will check it before it can be submitted for validation."

**(prop decision)** `gate.missing[].field` must be a `02` §2 field code or a `03` panel anchor, and `tab` must match a `tabs[].key` — the shell builds `#field-{field}` from it and cannot resolve anything else.

**The strip is not a completeness score.** Four requirements, each met or not; `gate` reports *which* are missing and never a percentage. A percentage over four fields implies they carry equal weight, which the frozen guard denies — a missing OP mapping blocks the transition exactly as hard as a missing allocation, but "25% complete" reads as though three of the four could be skipped.

### 5.5 The frozen banner and sign-off freeze semantics

**What becomes read-only, and when.** From `in_validation` onward the scenario is read-only to everyone except through a lifecycle transition:

| Status | Overview & Governance | Qualitative | Notes | Lifecycle |
|---|---|---|---|---|
| `draft`, `in_design`, `designed`, `returned_for_rework` | editable (`can.update`) | editable (`can.update`) | add allowed (`can.note`) | per §5.2 |
| `in_validation` | **read-only** | **read-only** | add allowed (`can.note`) | validator's transitions only |
| `validated` | **read-only** | **read-only** | add allowed (`can.note`) | sign-off, return, or reopen |
| `signed_off` | **frozen** | **frozen** | add allowed (`can.note`) — a note is commentary, not a change | reopen / archive |
| `archived` | **frozen** | **frozen** | **read-only** | per §5.2 and §9.1 |

The Notes column is gated on **`can.note`, not `can.update`** — §3 and §A1.10 make them different authorities, and the whole point is that a first-line owner who cannot edit a scenario can still dissent against it. The one place notes close is `archived`, where the record is out of circulation and a comment nobody will read is worse than no comment box.

**Why `in_validation` is already read-only**, before any sign-off: a validator reading a record the designer can still change is validating nothing. The screen says so rather than just refusing — the banner under the tab strip reads "In validation. You can't change this scenario until it is validated or returned for rework."

**The frozen banner** (`signed_off`), `role="note"`, navy-tinted (`bg-[#1A365D]/5 border border-[#1A365D]/20`), `lock` icon:

> **Signed off — this scenario is frozen.**
> Signed off on Mon 9 Nov 2026 by {Source 1 signatory_name} (Source 1, {role}), {Source 2 signatory_name} (Source 2, {role} — independent challenge).
> Every field on this workspace is read-only, and the figures it carries are the ones recorded at sign-off — they do not recompute. To change anything, reopen it for revalidation; it then travels design → validation → sign-off again. It is not eligible for capital quantification while it is open.
> **[Reopen for revalidation]**  **[Send back into validation]** *(each only when `transitions` carries it)*

Names in that sentence are `signatory_name` **snapshots** (`§4.7`), not joined user records, so the banner still reads correctly after a signatory leaves the bank. Only `is_current` rows are named (§4.6).

The "figures do not recompute" sentence is the user-facing half of ADR 0027 D1 / plan §4.3: the engine stores input snapshots so a signed-off scenario's numbers never silently move. Phase 1 has no computed figures on screen, but the sentence is written now because Phase 2 renders into the same banner and the promise must not change.

**Two ways out of `signed_off`, and the banner offers both** (§5.2). They differ in one respect the user must be able to see before choosing: one makes the scenario editable, the other does not.

**Reopen for revalidation** → `returned_for_rework`. `Modal` (`variant="warning"`):

> **Reopen SM-2026-014 for revalidation?**
> The scenario becomes **editable** and leaves `signed_off`. It must be marked designed, validated and signed off again before it counts as approved, and it is not eligible for capital quantification while it is open. The signed-off version, its figures and its sign-off sources stay on the audit trail unchanged.
> Reason (required): [textarea]
> **[Cancel]  [Reopen for revalidation]**

On success: status `returned_for_rework`, the **Previously signed off** marker appears (§2), the tabs become editable, focus moves to the status banner, `role="status"`: "Reopened for revalidation. You can edit this scenario again." The reason is persisted as a `challenge` note (§5.3's flagged decision).

**Send back into validation** → `in_validation`. `ConfirmDialog variant="warning"`, because it needs no field:

> "Send SM-2026-014 back into validation? It leaves `signed_off` and a validator re-reads it as it stands. **It stays read-only** — if it needs changes, use Reopen for revalidation instead. It is not eligible for capital quantification while it is open."

On success: status `in_validation`, the **Previously signed off** marker appears, the tabs stay read-only, `role="status"`: "Sent back into validation. It stays read-only until a validator returns it or validates it."

The two confirmations name each other, because picking the wrong one is otherwise only discovered after the click — and the difference is whether the user can then do anything about what they found.

**The editable path out, stated once so a designer is not stranded:** `signed_off → returned_for_rework` (**Reopen for revalidation**) → `in_design` (**Resume design**) → `designed` → `in_validation` → `validated` → `signed_off`. Four transitions. That is deliberate friction on unfreezing an approved record, and the readiness strip plus the banner are what make it legible rather than mysterious.

**`archived` is terminal** — `STATUS_TRANSITIONS['archived'] = []`. An archived scenario's only affordance is reading it. §9.1 records the ruling and what it costs.

### 5.6 The sign-off modal

`Modal` (`maxWidth="xl"`), title **"Sign off SM-2026-014"**. Two sequential writes (§5.6.7–5.6.9), each with a single submit control, so standard §9's two-button trap does not arise — but see §6.1 for where it does.

**Two writes, in order, and the modal is explicit about both.** §A1.8's guard on `validated → signed_off` requires *≥1 current sign-off with `decision = approved`* — so the sign-off rows must exist **before** the status can move. Recording them and moving the status are two routes (`POST {scenario}/signoffs` under `scenario.sign_off`, then `POST {scenario}/status` under `scenario.validate`). The modal runs them as **two visible steps**, never as one silently chained pair: on a degraded link a lost response after the first write must not leave the user guessing whether the scenario is signed off.

1. **A read-only recap** of what is being approved: reference, name, business unit, nature, OP event type (from `scenario`), then the qualitative position from `qualitativeSummary` — "{causes_selected} cause{s} selected", "{allocation_lines} business line{s}, allocation {allocation_total}%" or **"Not allocated"** when `allocation_total` is `null`, "Reputational impact: {reputational_label}", "Legal and regulatory: {legal_label}", "Insurance risk: {insurance_risk_label}", "Financial / market risk: {financial_risk_label}", "Eligible to transfer: {eligible_to_transfer_label}". Every value is a prop and every label is the server's wording; **nothing is recomputed or re-derived in the browser**, and the recap renders identically whichever tab the signer opened the modal from.
2. **Sign-off sources** — up to three rows, `Source 1` / `Source 2` / `Source 3` (`Signoff::SOURCES`, §A1.6; the workbook's own block). Per row, in this order:
   - **Signatory name** — text input, **required**, `maxlength` 200. Stored as `signatory_name`, a snapshot (§4.7).
   - **Signer** — an *optional* `Combobox` over the tenant's users. Choosing one **pre-fills** the name field; the typed name remains editable and is what is stored. `user_id` is nullable, so a sign-off by somebody without an account (an external committee member, a group CRO) is still recordable — and the snapshot is what survives if that user is later deleted.
   - **Role / capacity** — text input, **required**, `maxlength` 100, free text: "Head of Operational Risk", "Second Line — Independent Challenge". **Not** a select of platform roles (§4.7 — §A1.6 refuses Spatie names because renaming one would silently falsify every historical sign-off). A short datalist of previously used capacities on this tenant may be offered as suggestions; suggestions are not a vocabulary.
   - **Decision** — a two-option radio: **Approve** (`approved`) / **Return for rework** (`returned_for_rework`). §A1.6 refuses a third `approved_with_conditions`: "no transition branches on it, and a condition is a `sm_scenario_notes` row of kind `assumption` … A third value nothing reads is how a workflow acquires a state nobody can leave." The modal says so where a user would look for it: under the radio, `text-xs text-gray-600`, "Conditions and caveats go in the notes log as an assumption, so they are recorded rather than implied."
   - At least Source 1 is required. A row left entirely empty is not posted.
3. **Independent challenge** — a `checkbox` on each row: "This signer is independent of the team that designed this scenario." Stored as `is_independent_challenge` on the row. **At least one checked row is required to sign off.** Plan §6.7 approved independent-challenge sign-off as the module's bias mitigation, and a control that only exists in the UI is not a control: the server must refuse too. Message when none is checked: "At least one signer must be independent of the team that designed this scenario. Tick the box beside that signer."
4. **Next review due** — a date field for `review_due_on`, pre-filled from `scenario.review_due_on` or from `suggestedReviewDueOn` (server-computed). **Required in this modal.** A signed-off scenario with no review date never comes back for its annual refresh — the lifecycle-governance gap the plan's capability scorecard #10 names, and §A1.2 indexes `review_due_on` specifically "for the refresh prompt". **(prop decision)** `suggestedReviewDueOn` is sent by the server; the browser never computes it (no `+12 months` in client code — standard §5). Note this is a **UI-imposed** requirement: §A1.8's `validated → signed_off` guard lists only the approved sign-off, so the server does not currently refuse a sign-off without a review date. Flagged in §9.
5. **Warnings, not blocks** — a `role="note"` list, shown only when true, each with its source. **Bullets 1–5 are the recorded set**; bullet 6 is an advisory with no code:
   - "No challenge or dissent is recorded in the notes log." (`notes` has no entry of `kind` `challenge` or `dissent`)
   - "No cause is selected on the Qualitative tab."
   - "Business-line allocation is not 100%." / "No business line is allocated." *(both unreachable once the `designed` guard has passed, but a scenario can be reopened and returned, so the modal must still check)*
   - "Reputational impact is not yet assessed." / "Legal and regulatory risk is not yet assessed."
   - "Insurance risk is not assessed." / "Financial or market risk is not assessed."
   - *(advisory only)* "This scenario is recorded as **not eligible to transfer**, and that field has no 'unanswered' state — if nobody has checked the cover, say so in a note before signing." (§A1.2 makes `eligible_to_transfer` NOT NULL default `false`; see `03` §6.4.)

   Under the list: "You can still sign off. These are recorded with the sign-off so a reviewer can see what was outstanding." **That sentence is true of bullets 1–5 only**, and the backend contract behind it is correspondingly scoped: the outstanding items travel with the sign-off record in `warnings_acknowledged`, and §A1.6's original column list has no place for them (§9).

   **Bullet 6 has no code and is not written to `warnings_acknowledged`** — ADR 0027 Correction 1 refused a ninth warning code, because `false` is the column's *default*, so recording it would attach the same warning to most sign-offs in the product and train signers to skip the list. Its remedy stays where `03` §6.4 put it: the permanent help line on the field, this modal advisory, and the Phase-2 data consequence already written down there. Because bullet 6 renders in the same list as the coded five, its wording must stay **imperative** ("say so in a note before signing") rather than a bare state assertion — that is what keeps the closing sentence honest for a signer who reads the list top to bottom, and it points at the one action that *would* leave a record.
6. **Comment** — optional `textarea`, "Anything the next reader should know." Stored as `comment`.
7. **Step 1 buttons:** **Cancel** (focused first) and **Record sign-offs** (`btn-primary`). In flight: both `disabled`, "Recording…". Posts to `urls.signoffs`.
8. **Step 2, in the same modal after step 1 succeeds.** The modal body is replaced by the resulting position and one action:
   - If every recorded decision is `approved` and ≥1 row is an independent challenge: "Sign-offs recorded for {n} source{s}. Move this scenario to **Signed off**? It will be frozen." → **Sign off** posting `urls.status` with `{to: 'signed_off'}`.
   - If any decision is `returned_for_rework`: "Source {n} returned this scenario for rework. Move it to **Returned for rework**?" → **Return for rework** (`variant="warning"`) posting `{to: 'returned_for_rework'}`. The **Sign off** control is absent, not disabled — §A1.8 has no `validated → signed_off` edge that a returned sign-off could satisfy, and the server would refuse it anyway.
   - **Close** is always available, and the modal says what closing means: "Your sign-offs are recorded. The status is still {current status} — move it later from the header." Recording a sign-off and moving the status are genuinely separate acts, and a signer who is not the person that moves the status is a normal case, not an error.
9. **If step 2 fails** (network, 422, lifecycle refusal), the modal does **not** roll back and does not pretend to: "Your sign-offs are recorded. The status change didn't go through. Press Retry to move it to Signed off, or close and do it from the header." Retrying re-posts only `urls.status`. This is the same discipline as §6.4 — after a lost response on a write, learn the truth before retrying, and never re-post the write that may have landed.

On success: reload at the new status, focus to the frozen banner heading, `role="status"`: "Signed off. This scenario is now frozen."

**Re-signing after a revalidation.** Because §A1.6 has no unique key on `(scenario, source)`, a second round **appends** new rows rather than updating the first. The modal must therefore pre-fill from the current rows (§4.6's `is_current`) and say what it is doing: "Source 1 last signed on {date}. Recording a new sign-off supersedes it; the earlier one stays on the audit trail." Without that sentence a signer will assume they are editing history, and with it the append-only model is legible from the screen.

**422 handling:** errors arrive keyed `signoffs.{i}.signatory_name`, `signoffs.{i}.role`, `signoffs.{i}.user_id`, `review_due_on`, `comment`. The modal stays open on the step that failed, an error summary appears at its top (`h2` "Fix {n} item{s} before recording", `tabindex="-1"`, programmatic focus), each entry a link to its field, and each error also renders under its field with `aria-invalid` and `aria-describedby`.

---

## 6. Behaviour

### 6.1 Per-tab save

Each tab is **its own form with its own Save** (plan §4.5 item 3). Overview and Qualitative never share a submit: they are different field sets with different validation rules, and one Save that spans both would report a Qualitative error while the user is looking at Overview.

**The Qualitative tab has three Saves, not one**, because §A1.11 gives it three routes: `PUT {scenario}` (the exposure columns on `sm_scenarios`), `PUT {scenario}/causes` and `PUT {scenario}/allocations`. `03` §5.6 makes each card save itself, which is what the routes imply and what a workshop needs — see there for the reasoning.

**(prop decision) `PUT {scenario}` must be a partial update: only the keys present are written.** Both tabs post to it, and neither loads the other's fields (`§4` sends one tab's data at a time). A whole-object `PUT` from the Qualitative tab would therefore null `background`, `rationale` and every governance field — silently, on save, with a 200 response. This is standard §6's write-path rule in its most dangerous form: the columns exist, the write "succeeds", and the data is gone. The Form Request needs `sometimes` semantics (or the equivalent) and a test that a Qualitative save leaves `background` untouched.

- Save button: bottom of the tab panel or card, `btn-primary`, "Save {section name}" — the section is named in the button so a user who has scrolled a long way knows what they are saving.
- Beside it, `text-xs text-gray-600`: "Last saved {hh:mm} Lagos time" once a save has succeeded in this session; nothing before that (no "Never saved" — the record obviously exists).
- Dirty state is **per save region** (§6.3) — one on Overview, three on Qualitative (`03` §5.6).
- On success: `preserveScroll`, `preserveState`; the affected props reload; `role="status"` "Saved."; focus stays on **Save**.
- `gate` and `scenario` come back with every save, so the readiness strip (§5.4) and the header subtitle update without a second request.

### 6.2 Validation errors (422)

- Errors key on the field code (`02` §2) or the qualitative keys (`03` §2): `causes.{code}.note`, `allocations.{i}.percent`, `allocations` (the sum rule, §A1.5), `reputational_exposure`, `legal_compliance_exposure`.
- An error summary appears at the top of the **save region that failed** (`h2` "Fix {n} item{s}", `tabindex="-1"`, focused, scrolled into view), one linked entry per error.
- Each error also renders under its field: `text-red-700` + icon, `aria-invalid="true"`, `aria-describedby` → the error's id.
- **"Nothing was changed."** A banner above the summary: "Nothing was saved. Your entries are still on this page." Each save region is one transaction — and because the Qualitative tab has three, a failure in one leaves the other two untouched and still dirty on screen. The banner names the region: "The allocation wasn't saved. Your causes and exposure answers are unaffected."
- Where an error belongs to the *other* tab (only possible from the gate pre-flight, never from Save), the summary entry names the tab and links to it.

### 6.3 Unsaved work is never lost silently

- **Dirty tracking** compares form state to the last loaded/saved values, **per save region** — so saving the causes card clears only that card's dirty flag.
- **Switching tabs, navigating away, Back, refresh, close:** a `beforeunload` guard (precedent `Tprm/Settings/Ai.jsx`) plus an Inertia `before` listener that opens `ConfirmDialog`: "You have unsaved changes{on {tab name}|in {n} sections}. Leave without saving?" — **Stay** (focused) and **Leave**. The only navigation that bypasses the guard is the post-save redirect.
- **Local backup:** on every change, debounced 1 s, the region's form state goes to `sessionStorage` under `sm-scenario-{id}-{region}` (`overview`, `causes`, `allocations`, `exposures`), cleared on that region's successful save. On load, if a backup differs from the props, a `role="status"` strip offers: "We kept entries you hadn't saved from {hh:mm}. **Restore them** · **Discard**". `sessionStorage`, not `localStorage`, so entries do not outlive the tab on a shared computer. Values only — no tokens.
- **Frozen statuses never write a backup.** A read-only tab has no form state to lose, and offering "restore your entries" on a signed-off scenario would invite exactly the confusion §5.5 exists to prevent.
- **Copy my entries:** inside the session-expired and 403 states, a button copying a plain-text summary of the dirty regions to the clipboard — the last resort when work cannot be saved.

### 6.4 Save failure, session expiry, network loss

- **Network error / 5xx on Save:** `role="alert"` banner: "Couldn't save. Check your connection and try again. Your entries are still on this page." + **Try again**. A `PUT` is safe to re-send.
- **On a lifecycle transition or a sign-off write, never retry blind.** Before any retry the shell reloads `only: ['scenario', 'transitions', 'gate', 'signoffs']` to learn whether the first request landed; if `scenario.status` already moved, it renders the success state instead. A lost response after a committed write is the realistic failure on a degraded link, and the two writes here are both append-only: a blind retry of `POST signoffs` appends a **second** set of rows for the same sources (§4.6 — there is no unique key to stop it), and a blind retry of `POST status` re-fires a transition the map may now refuse. The `POST` paths get "Check what happened" (§5.6.9, `04` §6.3); only `PUT` gets a bare **Try again**.
- **419:** the shared modal (§0.2); entries kept; **Retry** re-sends — safe, because §A1.11's `EnsureFeatureEnabled`/auth middleware rejects before the write path.
- **403 on save** (permission withdrawn mid-session): banner "You no longer have permission to change this scenario." Entries kept and offered via **Copy my entries**. A 403 on `POST notes` gets its own wording, because the permission that was withdrawn is then `scenario.note` specifically: "You no longer have permission to add notes to this scenario."
- **Lifecycle refusal:** flash in a `role="status"` banner; the page re-renders from the new props (§0.2, §A1.8's "flash message, not a 403").

---

## 7. Accessibility of the shell

- **Headings:** `h1` = the scenario name (`PageHeader` title). The reference is in the subtitle, not a heading, so a screen reader gets "Loss of key staff — succession planning failure, heading level 1" and then the breadcrumb trail separately. Tab panel content starts at `h2`.
- **Landmarks:** `<main>` from the layout; the tab strip is `<nav aria-label="Scenario sections">`; the frozen banner is `role="note"`; the lifecycle actions sit in the header's action region, which is `role="group" aria-label="Lifecycle actions"` so a screen-reader user can jump to it as one unit.
- **Tab strip semantics:** links with `aria-current="page"`, not `role="tab"`. A `tablist` promises that activating a tab does not navigate; these do, and the URL is the point (§5.1). Announced as "Overview & Governance, current page, link".
- **Tab order:** breadcrumbs → header actions (in `transitions` order) → roadmap note (skippable, it is text) → tab strip → tab panel → Save.
- **Focus management:** after any transition, focus moves to the status banner heading (`tabindex="-1"`), never to `body`. After a modal closes, focus returns to the button that opened it. After a 422, focus goes to the error summary heading. After a tab switch, focus goes to the panel's first heading.
- **Chips:** `ScenarioStatusChip` renders words; the `lock` and `priority_high` icons are `aria-hidden`. The chip's accessible name is the words alone — "Signed off", not "lock Signed off".
- **Not colour alone:** every chip in §2 has words; the two icon-bearing ones have icons as a *third* channel; overdue review is a callout with a sentence, not a red row.
- **Live regions:** `role="status"` for save/transition results and the readiness count. The readiness strip is **not** re-announced on every keystroke — it updates after a save.
- **Contrast:** the navy signed-off chip is white on `#1A365D` (≈12:1). Yellow `bg-yellow-100 text-yellow-800` ≈ 5.4:1. All §2 pairs pass AA for `text-xs` semibold.
- **Reduced motion:** no animation beyond the Inertia progress bar.

---

## 8. Low bandwidth

- **One payload per tab visit**, and only the active tab's data (§4). Estimated: shell + scenario + gate + transitions + signoffs + 20 notes ≈ 6 KB; Overview adds the schema ≈ 4 KB; Qualitative adds the checklist (≈30 rows) and business-unit options (a bank's units are tens, not thousands — 200 × ~40 B ≈ 8 KB) ≈ 12 KB. Worst case ≈ 22 KB, under 2 s at 100 kbps.
- **Tab switching costs one reload.** That is deliberate (§4): the alternative — shipping both tabs always — costs the same bytes on every visit instead of only on switch, and a designer sits in one tab for most of a session.
- **No polling, no autosave.** Autosave on a flaky link produces half-saved states and conflicts; Save is explicit and small.
- **Notes are capped at 20** with an explicit **Load earlier** (§4.4).
- **In flight:** "Saving…" / "Signing off…" with `aria-busy`; after 15 s the slow-connection line (§0.4).
- **Offline typing works.** With the connection gone a designer can keep typing; entries go to the `sessionStorage` backup; Save reports failure instead of hanging; **Try again** re-sends when the link returns.
- **No images, no fonts beyond what the app already ships, no charts.**

---

## 9. Known gaps (also in the HANDOFF)

1. **~~`archived` — the coordinator's ruling and the frozen transition map disagree.~~ RESOLVED: `archived` is terminal in v1.** `App\Models\Sm\Scenario::STATUS_TRANSITIONS['archived'] = []`, and its docblock records the reversal of §A1.8's proposed `archived → in_design` edge. The annual refresh off `review_due_on` therefore acts on a **signed-off** scenario, through **Reopen for revalidation** or **Send back into validation** (§5.5) — not on an archived one. Coordinator ruling 10 Nov 2026: revisit post-v1. **What it costs, so the decision is informed:** an archived scenario can only be revived in the database, and the chip carries no affordance hinting otherwise. If a tenant archives a scenario by mistake, or archives one that later becomes relevant again, there is no screen path back. The UI needs no change either way — the shell renders `transitions` verbatim and holds no state machine (§5.2), so reinstating the edge later is a one-line const change plus a `config/sm.php` label.
2. **Last-write-wins is accepted for Phase 1** (coordinator ruling, 10 Nov 2026), recorded here as a documented risk rather than a defect to fix. Two users can hold the same editable scenario and the second Save silently overwrites the first. The mitigation is free and worth taking when convenient: `scenario.updated_at` and `updated_by` are already in the payload (§4), so the header can show "Last changed by {name} at {hh:mm} Lagos time" — which does not prevent the collision but makes it visible afterwards. A stale-write refusal remains the real fix and is deferred.
3. **The sign-off warnings column is pending an ADR addendum.** §5.6.5 promises the outstanding items travel with the sign-off record, and §A1.6's frozen column list has no place for them. The coordinator reports (10 Nov 2026) that a column is being confirmed in an addendum and that §5.6's warnings should stand as specced. **Until it lands, the warnings are shown and then discarded** — a signer sees "no challenge recorded" and the record does not. If the addendum does not arrive before Phase 1 code, §5.6.5's closing sentence must be struck, because promising a reviewer can see what was outstanding and then not storing it is worse than not promising.
4. **How a return-for-rework reason is persisted is unresolved** (§5.3): `POST status` accepting `{to, reason}` and writing the `challenge` note in one transaction is preferred; two sequential posts leave a window where a scenario is returned with no recorded reason. Needs a backend ruling.
5. **`review_due_on` is required by the sign-off modal but not by the server.** §A1.8's `validated → signed_off` guard requires only an approved sign-off. So a UI-only rule, which a stale tab or a direct `POST` bypasses. Recommend the guard gain it — a signed-off scenario with no review date never returns for its annual refresh, and `sm_scenarios_org_review_due_idx` exists precisely to power that prompt (§A1.2).
6. **Separation of duties is thin in the default role grants.** §A1.10 gives `risk-manager` all six permissions, so one person can create a scenario, edit it, mark it designed, validate it and sign it off — and `scenario.sign_off`'s own description is "record a sign-off … as one of its three sources", not "as somebody independent of it". The only structural check is §5.6.3's requirement that ≥1 signer be flagged `is_independent_challenge`, and that flag is **self-declared by the person signing**. This is a governance observation for the coordinator, not a UI defect: the UI implements the control the schema provides and says plainly what it is asking. Worth noting that §A1.10 refused `scenario.sign_off` for `risk-analyst` on exactly this reasoning, so the concern is already live in the ADR — it just is not fully closed by a self-declared checkbox.
7. **No notification wiring specified.** Plan §4.6 says reuse `ApprovalRequest`/notification plumbing "where it fits". Whether **Submit for validation** notifies a queue, and who is in it, is not in these props — and with `scenario.validate` held by only three roles (§3), a submission nobody is told about can sit in `in_validation` indefinitely. Phase 1 leaves it to the flash message; flagged.
8. **The `Sign-off & audit` tab is deferred to Phase 3**, but Phase 1 already collects sign-off rows and notes. `02` §6 therefore specifies the sign-off panel as a **self-contained component** (`NotesLog` + a read-only current-source list) so Phase 3 relocates it rather than rewriting it. Phase 1 must not grow a second place to read sign-off sources — and note that superseded rows (§4.6) have **no** Phase-1 surface at all, so a re-signing history is currently invisible outside `risk_audit_trail`.
9. **`GET {scenario}/edit` (`scenario.edit`) is in §A1.11's route table and no spec uses it** (`04` §8.5). Recommend it render the same `Workspace.jsx`, so there is one URL for "editing this scenario" rather than two surfaces to drift.
