# Screen Spec — Process Groups Admin (`ProcessGroups/Index` & Create/Edit Slide-Over)

**Phase:** P2 · **Routes:**
- `GET /risk/process-groups` → `risk.process-groups.index` (`ProcessGroupController@index`, `permission:process.view`)
- `POST /risk/process-groups` → `risk.process-groups.store` (`permission:process_group.manage`)
- `PUT /risk/process-groups/{processGroup}` → `risk.process-groups.update` (`permission:process_group.manage`)
- `POST /risk/process-groups/{processGroup}/deactivate` → `risk.process-groups.deactivate` (`permission:process_group.manage`)
**Policy:** `App\Policies\ProcessGroupPolicy` (`viewAny`, `view`, `create`, `update`, `delete`).
**Form Requests:** `StoreProcessGroupRequest`, `UpdateProcessGroupRequest` (tenant-scoped `Rule::exists` on `business_unit_id`, `owner_id`; tenant-scoped `Rule::unique('process_groups', 'code')->where('organization_id', $orgId)`).

---

## 1. Purpose & User

Used by `chief-risk-officer` and `risk-manager` (`process_group.manage`) to maintain the top tier of the APQC PCF-aligned process hierarchy (`Process Group → Process → Sub-Process`). All other roles with `process.view` (`risk-analyst`, `risk-owner`, `compliance-officer`, `board-member`, `risk-champion`) can browse the list read-only. Supports both department-scoped groups and enterprise-wide / cross-departmental groups (`business_unit_id = null`, ADR 0028 Decision 5).

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

```json
{
  "counts": { "active": 6, "inactive": 1, "total": 7 },
  "grid": { /* Standard GridPresenter payload (ProcessGroupsGrid) */ },
  "options": {
    "businessUnits": [{ "id": 1, "code": "RET", "name": "Retail Banking" }],
    "owners": [{ "id": 14, "name": "Tunde A." }]
  },
  "can": { "manage": true }
}
```
- **Row shape in `grid.rows`:** `id`, `code` (`PG-001`), `apqc_code` (nullable, e.g. `9.2`), `name`, `description` (nullable), `business_unit_id` (nullable), `business_unit_name` (or `null` → `"Enterprise / Cross-Departmental"`), `owner_id` (nullable), `owner_name`, `processes_count` (int), `sort_order` (int), `status` (`'active' | 'inactive'`).
- **Write payload (`POST` / `PUT`):** `code` (optional on create — auto-generated `PG-xxx` if blank), `name` (required, max 200), `description` (nullable plain text), `business_unit_id` (nullable), `apqc_code` (nullable, max 32), `owner_id` (nullable), `sort_order` (integer `0–65535`, default `0`), `status` (`'active' | 'inactive'`).

---

## 3. Layout

Shell: `AuthenticatedLayout` (`title="Process Groups"`), `<Head title="Process Groups" />`.

1. **`PageHeader`**:
   - Title: `Process Groups`
   - Subtitle: `{counts.active} active groups · {counts.inactive} inactive`
   - Breadcrumbs: `Processes` → `Process Groups`
   - Actions: `Process Catalogue` (`btn-secondary` link to `risk.processes.index`), `New Process Group` (`btn-primary`, `add_circle` icon — shown only when `can.manage`; opens Create slide-over).
2. **Status Quick-Filter Pills:** `Active ({counts.active})` (default), `Inactive ({counts.inactive})`, `All ({counts.total})`.
3. **Filter Bar (`useGridState`):** Search (`code`, `apqc_code`, `name`), `Department` `<select>` (with `"Enterprise / Cross-Departmental"` option for `business_unit_id IS NULL`).
4. **`@thirdline/ui` `DataGrid` Columns:**
   1. **Code** (`PG-xxx`, `font-semibold text-[#1A365D]`, muted `APQC {apqc_code}` below if set)
   2. **Group Name** (`name` + single-line truncated `description`)
   3. **Department** (`business_unit_name ?? 'Enterprise / Cross-Departmental'` with subtle neutral badge for enterprise-wide groups)
   4. **Owner** (`owner_name ?? '—'`)
   5. **Processes** (`processes_count` integer; links to `risk.processes.index?filters[process_group_id]={id}` when `> 0`)
   6. **Order** (`sort_order`)
   7. **Status** (`StatusBadge`: `active` / `inactive`)
   8. **Actions** (when `can.manage`): `Edit` (opens slide-over pre-populated with row), `Deactivate` (active rows) / `Activate` (inactive rows via `PUT` with `status: 'active'`).
5. **Create / Edit Slide-Over** (`fixed inset-0 z-40 flex justify-end`, `max-w-lg`, matching `AddRiskPanel.jsx`):
   - Fields: `Group Name` (required), `Code` (optional on create; editable with unique check on edit), `APQC PCF Code (optional)`, `Department / Business Unit (optional)` (`"Enterprise / Cross-Departmental (All Departments)"` default), `Owner (optional)`, `Display Order` (number input, default `0`), `Description` (plain `<textarea rows={3}>`), `Status` (`Active` / `Inactive` select on edit).
   - Footer: `Cancel` (`btn-secondary`), `Create Group` / `Save Changes` (`btn-primary`).

---

## 4. Interactive Flows & Confirmations

- **Deactivate Group:** Clicking `Deactivate` opens a confirmation prompt: `"Deactivate {code} — {name}? Its {processes_count} linked process(es) will remain active and unaffected, and this group will be hidden from new process selectors."` Submits `POST /risk/process-groups/{id}/deactivate`, writing `process_group_deactivated` to `risk_audit_trail`.
- **Save:** On successful `POST`/`PUT`, closes slide-over and reloads `ProcessGroups/Index` via Inertia flash (`"Process group saved."`).

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Grid dims (`opacity-60`, `aria-busy="true"`) on filter visits; slide-over Save button disables with `"Saving…"`. |
| **Empty (zero groups)** | `EmptyState`: `"No process groups defined yet."` + body `"Group related business processes into value chains (e.g. Customer Onboarding & KYC, Payment Clearing & Settlement)."` + `New Process Group` CTA (if `can.manage`). |
| **Empty (filtered)** | `"No process groups match the current filters."` + `Clear filters` button. |
| **Error** | Validation errors render inline via `InputError` inside the slide-over (e.g. duplicate `code`); network/server failures show `role="alert"` banner inside the slide-over without clearing fields. |
| **Permission Denied** | Users without `process.view` get HTTP 403. Users with `process.view` but without `process_group.manage` (e.g. `risk-analyst`, `risk-owner`) see the list read-only with no Create/Edit/Deactivate controls; direct `POST`/`PUT` returns HTTP 403. |
| **Low-Bandwidth (100 kbps)** | Slide-over uses props already on `ProcessGroups/Index` (`options.businessUnits`, `options.owners` — compact tuples), so opening Create/Edit requires **zero extra HTTP requests**. Grid paginates at 25 rows/page. |

---

## 6. WCAG 2.1 AA Accessibility

- Slide-over carries `role="dialog" aria-modal="true" aria-labelledby="pg-drawer-title"`; focuses `Group Name` on open; restores focus to trigger button on close; `Escape` closes.
- Every form control has an explicit `<label htmlFor>` and `InputError` linked via `aria-describedby`.
- Contrast meets ≥ 4.5:1 across `#1A365D` headers, status badges, and muted helper text (`text-gray-600`).

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `counts.active`, `counts.inactive`, `counts.total` | `COUNT(*)` on `process_groups` (org-scoped, non-deleted) | `0` is a true count |
| `processes_count` | `COUNT(*)` on `business_processes` where `process_group_id = pg.id` & `parent_id IS NULL` | `0` is a true count |
| `sort_order` | `process_groups.sort_order` | Integer `≥ 0` |
