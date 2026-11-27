# Screen Spec — Risk Library Create & Edit (`RiskLibrary/Create`, `RiskLibrary/Edit`)

**Phase:** P1 · **Routes:**
- `GET /risk/library/create` → `risk.library.create`, `POST /risk/library` → `risk.library.store` (`permission:risk_library.create`)
- `GET /risk/library/{item}/edit` → `risk.library.edit`, `PUT /risk/library/{item}` → `risk.library.update` (`permission:risk_library.update`)
**Form Requests:** `StoreRiskLibraryItemRequest`, `UpdateRiskLibraryItemRequest` (tenant-scoped `Rule::exists`).
**Duplicate Check Endpoint:** `GET /risk/library/duplicates?title=...&category_id=...&exclude_id=...` (`risk.library.duplicates`, `permission:risk_library.view`).
**Siblings to match:** `resources/js/Pages/Register/RiskForm.jsx` (numbered section cards, plain `<textarea>` for description).

---

## 1. Purpose & User

Used by `chief-risk-officer`, `risk-manager`, and `risk-analyst` to author a new master risk definition or publish a new version (`v{n+1}`) of an existing one. Must enforce ISO 31000 §6.4.2 discipline: **no likelihood/impact scores, no department, no entity, no owner** — and prevent catalogue bloat via a live duplicate-check panel before save.

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

```json
{
  "mode": "create",
  "item": null,
  "options": {
    "categories": [{ "id": 1, "code": "OR-PR", "name": "Process Risk", "is_operational": true }],
    "riskSources": [
      { "value": "internal", "label": "Internal" },
      { "value": "external", "label": "External" },
      { "value": "people", "label": "People" },
      { "value": "process", "label": "Process" },
      { "value": "systems", "label": "Systems & Technology" }
    ],
    "baselL1": [{ "value": "execution_delivery", "label": "Execution, Delivery & Process Management" }],
    "baselL2": [{ "value": "transaction_capture", "label": "Transaction Capture, Execution & Maintenance", "l1": "execution_delivery" }],
    "causeCategories": [{ "id": 1, "name": "Process" }],
    "impactDomains": ["Financial", "Regulatory", "Operational", "Reputational", "Legal", "Customer"],
    "controlTypes": ["preventive", "detective", "corrective"],
    "existingControls": [{ "id": 8, "code": "CTL-008", "title": "Automated straight-through matching" }],
    "existingKris": [{ "id": 3, "code": "KRI-003", "name": "Failed settlement count" }]
  }
}
```

- **Duplicate check response (`GET /risk/library/duplicates`):**
  ```json
  {
    "matches": [
      { "id": 12, "code": "RL-0012", "title": "Payment Settlement Failure", "category_name": "Process Risk", "status": "active", "current_version": 3, "show_url": "/risk/library/12" }
    ]
  }
  ```
  Returns top 5 closest org-scoped items matching normalized title tokens / category (PHP-side scoring over org items; no raw SQL JSON/CTE).
- **Submit payload (`POST` / `PUT`):** `code` (optional on create — auto-generated `RL-xxxx` if blank; read-only on edit), `title`, `description` (plain text), `category_id`, `risk_source`, `basel_l1_event_type`, `basel_l2_event_type`, `typical_causes[]`, `typical_consequences[]`, `suggested_controls[]`, `suggested_kris[]`, plus `change_summary` (**required on edit**, omitted on create).

---

## 3. Layout

Shell: `AuthenticatedLayout`, two-column desktop layout (`grid-cols-1 lg:grid-cols-3 gap-6`):
- **Left / Main Column (`lg:col-span-2`):** Numbered white section cards (`bg-white rounded-xl border border-gray-200 p-6 mb-6`, `#1A365D` numbered badges matching `Register/RiskForm.jsx`):
  1. **Section 1 — Master Risk Definition:**
     - `Title` (`<input required maxLength={255}>`) — triggers debounced duplicate check (400 ms, min 3 chars).
     - `Code` (optional on create, placeholder `"Auto-generated (e.g. RL-0046)"`; read-only mono badge on edit).
     - `Description` (plain `<textarea rows={4} required>` — explicitly plain text, matching `RiskForm.jsx` so search indexing and version diffs stay clean).
     - `Taxonomy Category` (`<select required>`).
     - `Risk Source` (`<select>` optional).
     - `Basel II/III Level 1 Event Type` (`<select>`, nullable; shows helper prompt `"Recommended for Operational Risk categories"` when selected category has `is_operational: true`).
     - `Basel II/III Level 2 Event Type` (`<select>`, nullable; filtered to children of chosen L1; disabled when L1 is empty; cleared automatically when L1 changes).
     - Subtle info callout: *"Master definitions contain no risk scores, owners, or departments. Those are captured when applied to an entity or process."*
  2. **Section 2 — Typical Causes & Consequences (ISO 31000 §6.4.2):**
     - **Typical Causes repeater:** rows of `{ cause (text input), cause_category_id (optional select) }` with `+ Add Cause` and `Remove` row buttons.
     - **Typical Consequences repeater:** rows of `{ consequence (text input), impact_domain (optional select) }` with `+ Add Consequence` and `Remove` row buttons.
  3. **Section 3 — Reference Controls & KRIs:**
     - Helper text: *"Reference prompts only — instantiating this risk will not auto-create controls or KRIs."*
     - **Suggested Controls repeater:** `{ title (text input, required if row added), control_id (optional link to existing control), control_type (optional select) }`.
     - **Suggested KRIs repeater:** `{ name (text input, required if row added), kri_id (optional link to existing KRI), metric_unit (optional text, e.g. 'count/day', '%') }`.
  4. **Section 4 — Version Change Summary (Edit mode only):**
     - `Change Summary` (`<textarea rows={3} required>`): `"Explain what changed for risk owners reviewing v{current_version} → v{current_version + 1}."`
     - Callout: `"Saving creates version v{current_version + 1}. Existing risk instances will keep v{current_version} and display a 'Library updated — review changes' banner."`
  - **Sticky Action Footer:** `Cancel` (`Link` back to index or show) and `Create Library Risk` / `Publish Version v{n+1}` (`btn-primary`).
- **Right / Sticky Sidebar (`lg:col-span-1`):**
  - **Live Duplicate Prevention Panel** (`card sticky top-6`):
    - Heading: `Similar Existing Risks`
    - Idle state (< 3 chars typed): `"Type a title to check for similar definitions already in the library."`
    - Matches found: compact cards showing `code`, `title`, `category_name`, `StatusBadge`, and `View in new tab` link (`target="_blank" rel="noopener"`), plus amber warning `"Review these before saving to avoid duplicate master risks."`
    - No matches: green check icon + `"No close matches found in the library."`

---

## 4. Interactive Flows

- **Live duplicate check:** Debounced 400 ms `fetch` (with `AbortController` to cancel stale requests) whenever `title` (≥ 3 chars) or `category_id` changes. Never blocks save (advisory prevention), unless the exact `code` collides (server-side unique validation).
- **Basel L1/L2 cascade:** Changing L1 resets `basel_l2_event_type` to `''`. Server validates `BaselEventTypeL2::from($l2)->l1()->value === $l1`.
- **Edit on retired item:** Backend redirects back to `risk.library.show` with flash message `"Reactivate this library item before editing."` (Standard §3 lifecycle rule in controller, not 403).

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Duplicate panel shows subtle `"Checking library…"` text (no layout shift). Submit button disables with `"Saving…"` / `"Publishing v{n+1}…"`. |
| **Empty** | Repeaters start with zero rows (or 1 blank optional row that is stripped if empty on submit) and clear `+ Add` buttons. |
| **Error** | Top error summary banner listing all field errors (clickable to focus field) + per-field `InputError`. If duplicate-check XHR fails, sidebar shows muted `"Duplicate check unavailable right now — you can still save."` without blocking form submission. |
| **Permission Denied** | HTTP 403 without `risk_library.create` (create) or `risk_library.update` (edit). |
| **Low-Bandwidth (100 kbps)** | Duplicate check debounced at 400 ms with `AbortController`, returning at most 5 lightweight records (< 1 KB JSON). `existingControls` / `existingKris` options capped to active org records (`id`, `code`, `title`/`name` only). Zero rich-text editor bundles loaded. |

---

## 6. WCAG 2.1 AA Accessibility

- Every input has an explicit `<label htmlFor>` and `aria-describedby` linking hint + `InputError`.
- Repeater `Remove` buttons carry `aria-label="Remove cause {i+1}"` (never bare `"Remove"`).
- Duplicate panel uses `aria-live="polite"` to announce `"3 similar library risks found"` or `"No close matches found"`.
- Validation summary receives programmatic focus (`tabIndex={-1}`) on failed submit.

---

## 7. Data Provenance & Standard §5 Compliance

- **Zero numeric scores or metrics** appear on this screen (enforced by ISO 31000 §6.4.2 and ADR 0028 §2.2).
- Version number preview (`v{current_version + 1}`) is computed directly from `item.current_version`.
