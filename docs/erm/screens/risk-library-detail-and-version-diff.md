# Screen Spec — Risk Library Detail, Version Diff & Instance Review Banner

**Phase:** P1 · **Routes:**
- `GET /risk/library/{item}` → `risk.library.show` (`RiskLibraryController@show`, `permission:risk_library.view`)
- `POST /risk/library/{item}/retire` → `risk.library.retire` (`permission:risk_library.retire`)
- `POST /risk/library/{item}/reactivate` → `risk.library.reactivate` (`permission:risk_library.retire`)
- `POST /risk/register/{risk}/library-update` → `risk.register.library-update` (`permission:risk.update`) — instance banner action on `Pages/Register/Show.jsx`
**Policies:** `RiskLibraryItemPolicy` (`view`, `update`, `retire`; `delete` always `false`), `RiskPolicy` (`update`).
**Siblings to match:** `resources/js/Pages/Register/Show.jsx`, `resources/js/Pages/Rcsa/Worksheet.jsx` (Standard §9 `useRef` + `transform()`).

---

## 1. Purpose & User

Two coupled surfaces:
1. **`RiskLibrary/Show`** — Used by risk managers, analysts, and examiners to inspect a master risk definition, audit every immutable version snapshot (`v1 → vN`), compare any two versions side-by-side, view all linked risk instances across entities/processes, and retire/reactivate the definition without deleting history (ISO 31000 §6.7).
2. **Instance Review Banner on `Register/Show.jsx`** — Shown to a `risk-owner` or `risk-manager` when `library_item.current_version > risk.library_version` and `risk.library_update_dismissed_version !== library_item.current_version`. Proves non-silent propagation: the user inspects the exact field-by-field diff and explicitly chooses **Accept Update** or **Keep Current**.

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

### 2A. `RiskLibrary/Show` Props (`RiskLibraryPresenter::show()`)
```json
{
  "item": {
    "id": 12,
    "code": "RL-0012",
    "title": "Payment Settlement Failure",
    "description": "...",
    "category": { "id": 4, "code": "OR-PR", "name": "Process Risk" },
    "risk_source": "process",
    "basel_l1": { "value": "execution_delivery", "label": "Execution, Delivery & Process Management" },
    "basel_l2": { "value": "transaction_capture", "label": "Transaction Capture, Execution & Maintenance" },
    "typical_causes": [{ "cause": "Manual reconciliation error", "cause_category_name": "Process" }],
    "typical_consequences": [{ "consequence": "Regulatory penalty", "impact_domain": "Regulatory" }],
    "suggested_controls": [{ "title": "Automated straight-through matching", "control_id": 8, "control_type": "preventive" }],
    "suggested_kris": [{ "name": "Failed settlement count", "kri_id": null, "metric_unit": "count/day" }],
    "current_version": 3,
    "status": "active",
    "retired_at": null,
    "retired_by_name": null,
    "retirement_reason": null,
    "created_by_name": "Adaeze O.",
    "updated_at": "2026-11-20T10:15:00Z"
  },
  "versions": [
    {
      "version_number": 3,
      "title": "...",
      "description": "...",
      "category_name": "Process Risk",
      "risk_source": "process",
      "basel_l1_label": "...",
      "basel_l2_label": "...",
      "typical_causes": [],
      "typical_consequences": [],
      "suggested_controls": [],
      "suggested_kris": [],
      "change_summary": "Added instant payment clearing failure causes.",
      "created_by_name": "Chidi N.",
      "created_at": "2026-11-20T10:15:00Z"
    }
  ],
  "instances": {
    "total": 5,
    "outdated_count": 2,
    "rows": [
      {
        "id": 91,
        "risk_code": "RSK-0091",
        "entity_name": "Retail Banking",
        "process_name": "Payment Clearing",
        "owner_name": "Tunde A.",
        "residual_rating": "high",
        "library_version": 2,
        "is_outdated": true,
        "library_synced_at": "2026-09-01T08:00:00Z",
        "url": "/risk/register/91"
      }
    ]
  },
  "auditTrail": [
    { "id": 501, "action_type": "library_item_versioned", "actor_name": "Chidi N.", "created_at": "...", "summary": "..." }
  ],
  "can": { "update": true, "retire": true, "bulkApply": true }
}
```

### 2B. `Register/Show.jsx` Additional Prop (`libraryContext`)
```json
{
  "libraryContext": {
    "library_item_id": 12,
    "code": "RL-0012",
    "show_url": "/risk/library/12",
    "status": "active",
    "instance_version": 2,
    "current_version": 3,
    "synced_at": "2026-09-01T08:00:00Z",
    "has_pending_update": true,
    "diff": {
      "from_version": 2,
      "to_version": 3,
      "change_summary": "Added instant payment clearing failure causes.",
      "updated_by": "Chidi N.",
      "updated_at": "2026-11-20T10:15:00Z",
      "fields": [
        { "field": "description", "label": "Description", "before": "...", "after": "..." },
        { "field": "typical_causes", "label": "Typical Causes", "before": ["..."], "after": ["...", "..."] }
      ]
    },
    "suggested_controls": [{ "title": "...", "control_id": 8, "control_type": "preventive" }],
    "suggested_kris": [{ "name": "...", "kri_id": null, "metric_unit": "count/day" }]
  }
}
```
- `POST /risk/register/{risk}/library-update` payload: `{ "decision": "accept" | "dismiss" }` (validated by `ReviewLibraryUpdateRequest`).

---

## 3. Layout

### 3A. `RiskLibrary/Show`
1. **`PageHeader`**:
   - Title: `{item.code} — {item.title}`
   - Subtitle: `Version v{item.current_version} · {item.category.name}` + `StatusBadge` (`active` / `retired`)
   - Breadcrumbs: `Risk Library` → `{item.code}`
   - Actions: `Apply to Entities` (`btn-secondary`, active only, `can.bulkApply`), `Edit (Creates v{n+1})` (`btn-primary`, active only, `can.update`), `Retire` (`btn-danger` outline, active only, `can.retire`) OR `Reactivate` (`btn-secondary`, retired only, `can.retire`), `Export Version History PDF` (`btn-secondary` link to `risk.library.export-item`). **Never a Delete button.**
2. **Retired Callout** (shown only when `item.status === 'retired'`):
   - Neutral amber/slate banner (`bg-amber-50 border-amber-200 text-amber-900`): `"Retired on {formatDate(item.retired_at)} by {item.retired_by_name}. Reason: {item.retirement_reason}. Existing instances remain active and assessable; this item is hidden from new pickers."`
3. **Tabs** (matching `Scoping/Show.jsx` border-bottom tab bar):
   - `Definition` (default)
   - `Version History ({versions.length})`
   - `Linked Instances ({instances.total})`
   - `Audit Trail`
4. **Definition Tab:**
   - Metadata grid (`grid-cols-1 md:grid-cols-3`): Category, Risk Source, Basel L1 / L2 Event Type (`—` if null). Explicit note: *"Library definitions carry no likelihood or impact scores. Scores are assessed on each entity or process instance."*
   - Plain-text Description block (`whitespace-pre-line`).
   - 2×2 card grid: **Typical Causes**, **Typical Consequences**, **Suggested Controls** (links to control record when `control_id` is set), **Suggested KRIs** (links to KRI when `kri_id` is set). Each card shows an inline muted message (`"None listed"`) when empty.
5. **Version History & Side-by-Side Diff Tab:**
   - **Compare selector bar**: Two native `<select>` dropdowns: `Compare from: v{a}` and `to: v{b}` (defaults to `v{current-1}` vs `v{current}`; disabled with note `"Only v1 exists — edit this item to create v2."` when `versions.length < 2`).
   - **Side-by-Side Diff Panel** (`grid-cols-1 lg:grid-cols-2 gap-4`):
     - Left column: `v{a}` snapshot (`bg-red-50/40 border-red-200` on changed fields only).
     - Right column: `v{b}` snapshot (`bg-green-50/40 border-green-200` on changed fields only).
     - Unchanged fields collapsed behind a toggle (`Show unchanged fields ({n})`) so reviewers focus on real deltas.
     - Displays `change_summary`, author, and timestamp of `v{b}` at the top of the diff card.
   - **Version Timeline** (vertical list below diff): reverse-chronological cards showing `v{n}`, `change_summary` (or `"Initial version"` for `v1`), author, timestamp, and a `Compare with current` quick button.
6. **Linked Instances Tab:**
   - Summary line: `{instances.total} linked risk instances ({instances.outdated_count} behind current version v{item.current_version})`.
   - Table: `Risk Code` (link), `Entity`, `Process`, `Owner`, `Residual Rating` (`RatingBadge`), `Version` (`v{n}` + amber pill `Update pending` if `is_outdated`), `Last Synced`.
7. **Audit Trail Tab:**
   - Read-only table of `risk_audit_trail` rows for this item (timestamp, actor, humanised `action_type`, summary).

### 3B. Instance Review Banner & Badges on `Register/Show.jsx`
1. **Header Provenance Pill** (below `PageHeader` subtitle):
   - Link pill `Library: RL-0012 (v2)` → `libraryContext.show_url`.
   - If `libraryContext.status === 'retired'`, appends an amber badge: `Retired in Library` (with tooltip/title explaining the instance stays active).
2. **"Library updated — review changes" Banner** (rendered above KPI cards when `libraryContext.has_pending_update === true` and `can.update`):
   - Gold/amber left-accent banner (`border-l-4 border-[#D4AF37] bg-amber-50/70 p-4 rounded-r-xl mb-6`):
     - Heading: `Library updated (v{instance_version} → v{current_version}) — review changes`
     - Meta: `Updated by {diff.updated_by} on {formatDate(diff.updated_at)} · "{diff.change_summary}"`
     - Button `Review Changes` toggles an inline expandable side-by-side diff panel (showing **only** changed copied definition fields: Title, Description, Category, Risk Source, Basel L1/L2, Causes, Consequences) plus an explicit assurance notice: *"Accepting updates definition fields only. Your inherent/residual scores, owner, controls, treatments, and review dates will not change."*
     - **Two-Submit-Button Form (Standard §9 — MANDATORY):**
       - Uses `useForm({})`, a `const decisionRef = useRef('accept')`, and `form.transform(() => ({ decision: decisionRef.current }))` before `form.post(route('risk.register.library-update', risk.id))`. **Never** `setData('decision', ...)` before `post()`.
       - Primary button (`btn-primary`): `Accept Update (v{current_version})` → sets `decisionRef.current = 'accept'` and submits.
       - Secondary button (`btn-secondary`): `Keep Current (v{instance_version})` → sets `decisionRef.current = 'dismiss'` and submits.
3. **Reference Suggestions Panel** (on `Register/Show.jsx` Controls & KRIs tabs):
   - Collapsible muted card listing live `libraryContext.suggested_controls` / `suggested_kris` as reference prompts (never auto-created).

---

## 4. Interactive Flows & Confirmations

- **Retire Action (irreversible-until-reactivated):** Clicking `Retire` opens a confirmation slide-over/inline form requiring a non-empty `retirement_reason` (min 10 chars, max 500 chars) and displaying: `"This item has {instances.total} linked instances. They will stay active and show a 'Retired in Library' badge."` Submits `POST /risk/library/{item}/retire`.
- **Reactivate Action:** Single confirmation dialog (`"Reactivate {item.code}? It will reappear in Add-from-Library pickers."`) submitting `POST /risk/library/{item}/reactivate`.
- **Accept Update / Keep Current:** Both buttons disable while `form.processing` is true; on success, Inertia reloads `Register/Show`, banner disappears, and a green flash confirms `"Updated definition to v{m}; assessment scores preserved."` or `"Kept v{n}; you will be notified again if v{m+1} is published."`

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Retire/Reactivate and Accept/Keep buttons show inline spinner and disable both actions while `processing` to prevent double-POST. |
| **Empty** | Zero linked instances: `"This library definition has not been applied to any entity or process yet."` + `Apply to Entities` CTA (if `can.bulkApply` and active). Only `v1`: diff panel replaced by single-version notice. |
| **Error** | Validation errors (e.g. missing `retirement_reason`) render via `InputError` beneath the field; server/network errors display an `role="alert"` banner keeping the user's open diff/reason intact. |
| **Permission Denied** | Users without `risk_library.retire` (e.g. `risk-analyst`) never see Retire/Reactivate. Users without `risk.update` on `Register/Show` see a read-only informational note (`"Library v{m} is available — ask the Risk Owner to review."`) with no Accept/Keep buttons. |
| **Low-Bandwidth (100 kbps)** | Version snapshots are compact text/JSON loaded once on `RiskLibrary/Show` (no N+1 per-version XHR fetches); switching `Compare from / to` computes the field diff purely client-side with zero network round-trips. On `Register/Show`, `libraryContext.diff` is only populated by the presenter when `has_pending_update` is true. |

---

## 6. WCAG 2.1 AA Accessibility

- Tabs use `role="tablist"`, `role="tab"`, `aria-selected`, `aria-controls`, and arrow-key navigation.
- Diff columns do **not** rely on red/green colour alone: removed/previous blocks carry a `Previous (v{a})` pill badge (`-`) and updated blocks carry an `Updated (v{b})` pill badge (`+`).
- The `Register/Show` review banner uses `role="region" aria-label="Risk library update available"`; expanding `Review Changes` moves focus to the diff heading.
- Contrast on amber banner: `text-amber-900` on `bg-amber-50` (`> 8:1`).

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| `current_version`, `version_number` | `risk_library_items.current_version` / `risk_library_item_versions.version_number` | Integer `≥ 1` |
| `instances.total`, `instances.outdated_count` | Org-scoped `COUNT` on `risks` where `library_item_id = item.id` | `0` is a true count; no percentage rate rendered |
| `library_synced_at` | `risks.library_synced_at` | Renders `—` if `null` |
