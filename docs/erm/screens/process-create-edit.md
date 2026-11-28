# Screen Spec — Process & Sub-Process Create / Edit (`Processes/FormSlideOver` & `Processes/Create`, `Processes/Edit`)

**Phase:** P2 (reused in P3 from `Scoping/Show.jsx` Processes tab)
**Routes:**
- `GET /risk/processes/create` → `risk.processes.create`, `POST /risk/processes` → `risk.processes.store` (`permission:process.create`)
- `GET /risk/processes/{process}/edit` → `risk.processes.edit`, `PUT /risk/processes/{process}` → `risk.processes.update` (`permission:process.update`)
**Form Requests:** `StoreBusinessProcessRequest`, `UpdateBusinessProcessRequest` — tenant-scoped `Rule::exists` on `process_group_id`, `business_unit_id`, `owner_id`, `parent_id` (and optional `entity_id` when invoked from Scoping), plus tenant-scoped `Rule::unique('business_processes', 'code')->where('organization_id', $orgId)`.
**RCSA v2 Invariant (ADR 0028 Decision 3):** Top-level Process stores `parent_id = null`; Sub-Process **requires** `parent_id` pointing to a top-level (`parent_id IS NULL`) process in the same org (strictly 2 levels inside `business_processes`; never 3-deep).

---

## 1. Purpose & User

Used by `chief-risk-officer`, `risk-manager`, `risk-analyst`, and `risk-owner` (`process.create` / `process.update`) to create or update a Business Process or Sub-Process from either the Process Catalogue or an Entity's Processes tab. Must enforce the `parent_id` invariant cleanly so every record works identically in ERM, RCSA v2, and BCMS overlays.

---

## 2. Props & Endpoint Contracts (ADR 0028)

```json
{
  "mode": "create",
  "process": null,
  "context": {
    "default_level": "process",
    "default_parent_id": null,
    "default_entity_id": null
  },
  "options": {
    "processGroups": [{ "id": 2, "code": "PG-002", "name": "Payment Clearing & Settlement", "business_unit_id": null }],
    "businessUnits": [{ "id": 1, "code": "RET", "name": "Retail Banking" }],
    "parentProcesses": [{ "id": 5, "code": "PRC-005", "name": "Payment Clearing", "business_unit_id": 1, "process_group_id": 2 }],
    "owners": [{ "id": 14, "name": "Tunde A." }],
    "criticalities": [
      { "value": "critical", "label": "Critical" },
      { "value": "high", "label": "High" },
      { "value": "medium", "label": "Medium" },
      { "value": "low", "label": "Low" }
    ]
  }
}
```
- **Submit payload (`POST` / `PUT`):** `level` (`'process' | 'sub_process'`, UI control that governs whether `parent_id` is required), `parent_id` (`nullable` for process; `required` + must be a top-level process for sub-process), `process_group_id` (nullable), `business_unit_id` (nullable), `entity_id` (optional hidden/context field when launched from `Scoping/Show.jsx` — attaches via `entity_business_process` with `is_primary = true`), `code` (optional on create — auto-generated `PRC-xxxx` / `SUB-xxxx` if blank), `apqc_code` (nullable `varchar(32)`), `name` (required), `description` (plain text, nullable), `owner_id` (nullable), `criticality` (nullable enum), `is_active` (boolean, default `true` on create; editable on edit).

---

## 3. Layout

Delivered as a shared `ProcessForm` component hosted inside:
- Full page (`Processes/Create.jsx`, `Processes/Edit.jsx`) with `AuthenticatedLayout` + `PageHeader` when visited by URL, **and**
- Edge-anchored slide-over (`max-w-xl`, matching `AddRiskPanel.jsx`) when launched inline from `Processes/Index` or `Scoping/Show.jsx` Processes tab.

Fields (top to bottom):
1. **Hierarchy Type Toggle** (segmented control, disabled on edit if the process already has child sub-processes):
   - `Process (Level 2)` — sets `parent_id = ''` and hides Parent Process selector.
   - `Sub-Process (Level 3)` — reveals and requires `Parent Process` `<select>`.
2. **Parent Process** *(shown and required ONLY when `level === 'sub_process'`)*:
   - `<select required>` populated exclusively with `options.parentProcesses` (server-filtered to `parent_id IS NULL` and `id !== process?.id` so circular or 3-level nesting is impossible).
   - Helper: *"Sub-processes inherit their parent's Process Group unless overridden."*
3. **Process Name** (`<input required maxLength={200}>`).
4. **Code & APQC PCF Code** (2-column grid):
   - `Process Code` (`<input maxLength={32}>`, placeholder `"Auto-generated (e.g. PRC-0029)"`).
   - `APQC PCF Code (optional)` (`<input maxLength={32}>`, placeholder `"e.g. 9.2.1"`).
5. **Process Group (optional)** (`<select>`):
   - Default option `"None (Ungrouped)"` + `options.processGroups`.
6. **Department / Business Unit (optional)** (`<select>`):
   - Default option `"Enterprise / Cross-Departmental"` + `options.businessUnits`.
   - When invoked from `Scoping/Show.jsx`, displays a read-only context chip: `"Will be attached to entity: {entity.entity_code} — {entity.name}"`.
7. **Owner & Criticality** (2-column grid):
   - `Process Owner` (`<select>` optional, `options.owners`).
   - `ERM Criticality` (`<select>` optional: Critical, High, Medium, Low). Helper note: *"Qualitative ERM process criticality. BCMS critical-service designation is managed separately in Business Continuity."*
8. **Description** (plain `<textarea rows={3}>`).
9. **Status** (Edit mode only): checkbox/toggle `Active` (`is_active`).
10. **Footer:** `Cancel` (`btn-secondary`) and `Create Process` / `Save Changes` (`btn-primary`).

---

## 4. Interactive Flows

- **Selecting a Parent Process (Sub-Process mode):** Automatically pre-fills `process_group_id` and `business_unit_id` from the chosen parent's metadata if those fields are currently blank, reducing clicks while keeping both editable.
- **Edit guard on parent processes:** If an existing top-level process already has child sub-processes (`sub_processes_count > 0`), switching it to `Sub-Process` is disabled with helper text `"Cannot convert to a sub-process while child sub-processes exist."`
- **Unsaved changes:** Closing the slide-over with dirty fields prompts `window.confirm("Discard unsaved process changes?")`.

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Submit button disables with `"Saving…"` while `form.processing` is true. |
| **Empty (no parent processes yet)** | If user switches to `Sub-Process` when `options.parentProcesses.length === 0`, the Parent Process select is disabled with inline note `"Create a top-level Process first before adding a Sub-Process."` and Submit is disabled. |
| **Error** | Top error summary banner + per-field `InputError` (including tenant-scoped duplicate code error `"This process code is already in use."`). Preserves all entered values. |
| **Permission Denied** | HTTP 403 without `process.create` / `process.update`. |
| **Low-Bandwidth (100 kbps)** | All dropdown options are compact `{ id, code, name }` tuples (< 10 KB total); native `<select>` elements; zero extra network requests during typing. |

---

## 6. WCAG 2.1 AA Accessibility

- Hierarchy Type toggle uses `role="radiogroup" aria-label="Process hierarchy level"` with `role="radio"` / `aria-checked` buttons (or native radio inputs styled as pills).
- Conditional reveal of `Parent Process` announces cleanly via `aria-live="polite"` helper text; `<label htmlFor>` + `aria-required="true"` on required fields.
- Slide-over traps focus, sets initial focus to `Process Name` (or `Parent Process` when opened via `+ Sub-Process`), and restores focus on close.

---

## 7. Data Provenance & Standard §5 Compliance

- No computed scores or metrics are shown or entered on this form.
- `criticality` is an explicit qualitative enum (`critical|high|medium|low|null`), never defaulted to a fabricated score or synced to `bcms_processes.is_critical_service`.
