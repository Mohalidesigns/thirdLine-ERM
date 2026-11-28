# Screen Spec — Add from Risk Library Picker & Bulk Apply Flow

**Phase:** P3 · **Endpoints:**
- `GET /risk/library/picker-options` → `risk.library.picker-options` (`permission:risk_library.view`) — lazy-loaded active library items + filter options (retired items excluded per ADR 0028 Decision 6)
- `POST /risk/scoping/{entity}/risks/from-library` → `risk.scoping.risks.from-library` (`permission:risk.create`) — single-entity attach (1..N library items onto one entity, optional process, optional owner)
- `POST /risk/library/bulk-apply/preview` → `risk.library.bulk-apply.preview` (`permission:risk.create`) — dry-run preview computing `will_create[]` vs `will_skip[]` (already attached)
- `POST /risk/library/bulk-apply` → `risk.library.bulk-apply` (`permission:risk.create`) — atomic instantiation + `risk_instantiated_from_library` audit entries
**Form Requests:** `AttachLibraryRiskToEntityRequest`, `BulkApplyLibraryRiskPreviewRequest`, `BulkApplyLibraryRiskRequest` (all FKs tenant-bound via `Rule::exists(...)->where('organization_id', $orgId)`).
**Surface:** Edge-anchored slide-over (`fixed inset-0 z-40 flex justify-end`, `max-w-2xl`, matching `RcsaUniverse/AddRiskPanel.jsx` — avoids unverified shared `Modal`).

---

## 1. Purpose & User

Used by `risk-manager`, `risk-analyst`, and `risk-owner` (`risk.create` + `risk_library.view`) in two modes:
1. **Single-Entity Attach** (launched from `Scoping/Show.jsx` Risks tab or Coverage Matrix missing cell): pick one or more active Risk Library items, optionally bind to a process attached to that entity, assign an optional risk owner, and instantiate without fabricating a `business_unit_id` (ADR 0028 §4.3).
2. **Bulk Apply** (launched from `RiskLibrary/Index`, `RiskLibrary/Show`, or Coverage Matrix): apply one (or more) library risk(s) to many entities and/or processes in one atomic operation, with a **mandatory preview step** clearly separating new instances to create from already-attached pairs that will be skipped.

---

## 2. Props & Endpoint Contracts (ADR 0028)

### 2A. Lazy Picker Data (`GET /risk/library/picker-options?entity_id=...&search=...&category_id=...&basel_l1=...`)
```json
{
  "items": [
    {
      "id": 12,
      "code": "RL-0012",
      "title": "Payment Settlement Failure",
      "category_name": "Process Risk",
      "basel_l1_label": "Execution, Delivery & Process Management",
      "current_version": 3,
      "already_attached_to_entity": false
    }
  ],
  "categories": [{ "id": 1, "name": "Process Risk" }],
  "baselL1": [{ "value": "execution_delivery", "label": "Execution, Delivery & Process Management" }],
  "entityProcesses": [{ "id": 5, "code": "PRC-005", "name": "Payment Clearing" }],
  "owners": [{ "id": 14, "name": "Tunde A." }]
}
```
*Note:* Retired library items (`status = 'retired'`) are **never** returned here (ADR 0028 Decision 6).

### 2B. Bulk Apply Preview (`POST /risk/library/bulk-apply/preview`)
Request:
```json
{
  "library_item_ids": [12],
  "target_mode": "entities",
  "entity_ids": [3, 4, 7],
  "process_ids": [],
  "default_owner_id": 14
}
```
Response:
```json
{
  "summary": { "new_count": 2, "skip_count": 1, "total_pairs": 3 },
  "will_create": [
    { "library_code": "RL-0012", "library_title": "Payment Settlement Failure", "target_type": "entity", "target_id": 3, "target_name": "Retail Banking" },
    { "library_code": "RL-0012", "library_title": "Payment Settlement Failure", "target_type": "entity", "target_id": 7, "target_name": "Corporate Banking" }
  ],
  "will_skip": [
    { "library_code": "RL-0012", "library_title": "Payment Settlement Failure", "target_type": "entity", "target_id": 4, "target_name": "Treasury", "existing_risk_code": "RSK-0041", "reason": "Already attached" }
  ]
}
```

### 2C. Bulk Apply Commit (`POST /risk/library/bulk-apply`)
Same payload as preview (plus `confirm: true`). Server re-checks duplicates inside a DB transaction, creates only missing instances (copying definition fields per ADR 0028 §4.1, setting `library_version = item.current_version`, `library_synced_at = now()->utc()`, leaving scores `null` / unrated), writes `risk_instantiated_from_library` to `risk_audit_trail`, and returns a flash summary: `"Created {n} risk instances ({m} already attached, skipped)."`.

---

## 3. Layout

### 3A. Single-Entity Attach Slide-Over (`mode="single_entity"`)
- **Header:** `Add Risks from Library` · subtitle `Target entity: {entity.entity_code} — {entity.name}`. Close button (`aria-label="Close"`).
- **Search & Filter Row:** Search input (`code`/`title`, debounced 300 ms) + `Category` `<select>` + `Basel L1` `<select>`.
- **Optional Context Row:** `Process (optional)` `<select>` (populated from `entityProcesses` attached to this entity) + `Default Risk Owner (optional)` `<select>`.
- **Selectable List:** Scrollable checklist of active library items. Each row shows checkbox, `code` (`RL-xxxx`), `title`, `category_name`, `v{current_version}`. Items where `already_attached_to_entity === true` (for the chosen entity/process pair) render a neutral pill `Already attached` and are unchecked/disabled by default so silent duplicates cannot occur.
- **Footer:** Selected counter (`{n} selected`), `Cancel` (`btn-secondary`), `Add {n} Risk(s)` (`btn-primary`, disabled when `n === 0` or submitting).

### 3B. Bulk Apply Slide-Over (`mode="bulk_apply"`)
Three-step rail (same visual pattern as `RcsaUniverse/AddRiskPanel.jsx`):
1. **Step 1 — Select Library Risk(s):** Pre-filled when launched from a row or `RiskLibrary/Show` (editable chip list); searchable checklist of active library items otherwise.
2. **Step 2 — Select Targets:**
   - Radio toggle: `Apply to Entities` vs `Apply to Processes` (when `Apply to Processes` is selected, each created `risks` row links `process_id` and inherits the process's primary `entity_id` or `business_unit_id` per ADR 0028 §4.3).
   - Searchable multi-select list with `Select all visible` / `Clear` helpers and optional `Default Risk Owner` `<select>`.
   - Primary action: `Preview Changes` (`btn-primary`) → calls `POST /risk/library/bulk-apply/preview`.
3. **Step 3 — Mandatory Preview & Confirm:**
   - Two KPI-style summary pills: `New instances to create: {summary.new_count}` (Forest green `bg-green-50 text-[#2D7D46]`) and `Already attached — will skip: {summary.skip_count}` (neutral slate `bg-gray-100 text-gray-700`).
   - Explicit table of **Will Create** pairs (`Library Code`, `Target`, `Owner`).
   - Collapsible/visible table of **Will Skip** pairs (`Library Code`, `Target`, `Existing Risk Code`, `Reason`).
   - Notice: *"New risk instances are created unrated (no scores copied). Risk owners will assess inherent/residual scores in the Risk Register."*
   - Footer: `Back` (`btn-secondary`) and `Confirm & Create {summary.new_count} Instance(s)` (`btn-primary`, disabled if `summary.new_count === 0`).

---

## 4. Interactive Flows

- **Escape / backdrop click:** Closes drawer immediately if nothing selected; prompts `window.confirm("Discard selection?")` if user has made selections and not submitted.
- **All-skipped preview (`new_count === 0`):** Confirm button is disabled and helper text reads `"All selected targets already have this risk attached. Go back to pick different targets."`
- **Post-submit:** Closes drawer and refreshes parent Inertia page (`preserveScroll: true`) via standard redirect flash so new instances appear immediately in the table.

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Initial lazy-load of picker options renders 5 skeleton rows (`animate-pulse`) inside the drawer body. Preview and Commit buttons show `"Checking…"` / `"Creating…"` and lock controls. |
| **Empty (no active library items)** | `EmptyState` inside drawer: `"No active risks in the Risk Library."` + `Create Library Risk` link (if user has `risk_library.create`) or `"Ask your Risk Manager to populate the Risk Library."` |
| **Empty (search returns 0)** | `"No active library risks match '{search}'."` + `Clear search` button. |
| **Error** | Validation or network errors render in a red banner (`role="alert"`) inside the drawer header without losing current checkboxes or step state. |
| **Permission Denied** | Trigger buttons are hidden when `!can.createRisk`; direct POST without `risk.create` returns HTTP 403. |
| **Low-Bandwidth (100 kbps)** | Picker options are **not** bundled into `Scoping/Show` or `RiskLibrary/Index` initial props — they lazy-load only when the drawer opens, paginated/capped at 50 items per search query (server-side search when org has > 50 library items). |

---

## 6. WCAG 2.1 AA Accessibility

- Slide-over root has `role="dialog" aria-modal="true" aria-labelledby="library-picker-title"`.
- Focus moves to the search input on open and returns to the invoking trigger button on close; Tab cycles within the slide-over; `Escape` closes.
- Step rail buttons carry `aria-current="step"`.
- Checkboxes are wrapped in `<label>` elements so the entire row is clickable and screen readers announce `"RL-0012 Payment Settlement Failure, Process Risk, version 3"`.

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `summary.new_count`, `summary.skip_count` | Server-computed dry-run in `RiskLibraryService::previewBulkApply()` | `0` is a true count; never shows a percentage |
| `already_attached_to_entity` | `EXISTS` check on `risks` (`organization_id`, `library_item_id`, `entity_id`, optional `process_id`) | Boolean |
| Scores on newly created instances | `null` (unrated) | Never pre-populated with fabricated defaults (e.g. never `3 × 3`) |
