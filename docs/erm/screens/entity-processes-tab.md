# Screen Spec — Entity Drill-Down (`Scoping/Show`) — 6 Tabs, Processes Tab & Risks Tab CTA

**Phase:** P3 · **Routes:**
- `GET /risk/scoping/{entity}` → `risk.scoping.show` (`ScopingController@show`, `permission:scoping.view`)
- `POST /risk/scoping/{entity}/processes/attach` → `risk.scoping.processes.attach` (`permission:process.update`) — attaches existing `business_process_id` via `entity_business_process` pivot
- `DELETE /risk/scoping/{entity}/processes/{process}` → `risk.scoping.processes.detach` (`permission:process.update`) — detaches pivot row only (never deletes the `business_processes` record)
- `POST /risk/processes` (`permission:process.create`) with `entity_id` — creates a new Process or Sub-Process and attaches it to this entity (see `process-create-edit.md`)
- `POST /risk/scoping/{entity}/risks/from-library` (`permission:risk.create`) — attaches 1..N Risk Library items to this entity (see `add-from-library-picker.md`)
**Existing File Extended:** `resources/js/Pages/Scoping/Show.jsx` (currently 5 tabs; expanded to 6 tabs).
**Deprecation Note (ADR 0028 Decision 3):** `Scoping/Create.jsx` hides the legacy `PROCESS` entity type from new entity creation; existing `PROCESS`-type entities remain readable/editable.

---

## 1. Purpose & User

Used by `chief-risk-officer`, `risk-manager`, `risk-analyst`, and `risk-owner` drilling into an organisational entity (Subsidiary, Division, Department, Branch) to manage both its **business processes** (reusing the canonical `business_processes` table shared with the Process Module, RCSA v2, and BCMS) and its **assigned risks** (instantiated directly from the Risk Library without fabricating a `business_unit_id`).

---

## 2. Inertia Props & Endpoint Contracts (ADR 0028)

Additions to `ScopingController@show` (shaped via `ScopingEntityPresenter`):

```json
{
  "entity": { /* existing entity props */ },
  "processes": [
    {
      "id": 5,
      "code": "PRC-005",
      "apqc_code": "9.2.1",
      "name": "Payment Clearing",
      "is_sub_process": false,
      "parent_name": null,
      "process_group_name": "Payment Clearing & Settlement",
      "owner_name": "Tunde A.",
      "criticality": "critical",
      "sub_processes_count": 3,
      "linked_risks_count": 4,
      "is_primary": true,
      "show_url": "/risk/processes/5"
    }
  ],
  "risks": [
    {
      "id": 91,
      "code": "RSK-0091",
      "title": "Payment Settlement Failure",
      "category": "Process Risk",
      "rating": "high",
      "owner": "Tunde A.",
      "library_code": "RL-0012",
      "library_version": 2,
      "library_has_update": true,
      "library_is_retired": false,
      "process_name": "Payment Clearing",
      "url": "/risk/register/91"
    }
  ],
  "can": {
    "update": true,
    "delete": false,
    "create": true,
    "createRisk": true,
    "viewLibrary": true,
    "createProcess": true,
    "attachProcess": true
  }
}
```
- **Lazy attach-existing options (`GET /risk/scoping/{entity}/processes/attachable?search=...`, `permission:process.view`):** returns active org `business_processes` not yet in `entity_business_process` for this entity (`id`, `code`, `name`, `is_sub_process`, `parent_name`, `process_group_name`, `criticality`).

---

## 3. Layout

Matches `resources/js/Pages/Scoping/Show.jsx` (`AuthenticatedLayout`, `PageHeader`, summary card, white rounded-top tab bar).

### 3A. Updated 6-Tab Bar
Tab order (exact):
1. `Overview` (`overview`)
2. `Risks ({risks.length})` (`risks`)
3. `Processes ({processes.length})` (`processes`) — **NEW**
4. `KRIs ({kris.length})` (`kris`)
5. `Issues ({issues.length})` (`issues`)
6. `Sub-Entities ({subEntities.length})` (`sub_entities`)

URL sync: reading/updating `?tab=...` via `window.history.replaceState` (no full page reload) so bookmarks and post-action redirects land back on `processes` or `risks`.

### 3B. `Processes` Tab
- **Card Header:** Title `Processes for {entity.name}` · right-aligned actions (gated by `can`):
  - `Attach Existing Process` (`btn-secondary`, `link` icon — when `can.attachProcess`) → opens the Attach Existing slide-over.
  - `New Process / Sub-Process` (`btn-primary`, `add` icon — when `can.createProcess`) → opens the shared `ProcessForm` slide-over (`process-create-edit.md`) pre-bound to `default_entity_id = entity.id`.
- **Table** (same `Table` component as `Scoping/Show.jsx`):
  - Columns: `Code` (`Link` to `show_url`), `Name` (with muted `↳ {parent_name}` when `is_sub_process`), `Process Group` (`process_group_name ?? 'Ungrouped'`), `Owner` (`owner_name ?? '—'`), `Criticality` (`RatingBadge` or `—`), `Linked Risks` (integer count; amber `0` pill when `0`), `Actions` (`+ Sub-Process` on top-level rows if `can.createProcess`; `Detach` button if `can.attachProcess`).
- **Attach Existing Process Slide-Over (`max-w-lg`):**
  - Search input (`code`/`name`, debounced 300 ms) + optional `Process Group` filter.
  - Checklist of attachable active `business_processes` (both Processes and Sub-Processes, clearly badged `Process` vs `Sub-Process`).
  - Footer: `Cancel` and `Attach {n} Process(es)` (`btn-primary`) → `POST /risk/scoping/{entity}/processes/attach` (`{ business_process_ids: [...] }`).

### 3C. `Risks` Tab (Updated)
- **Card Header:** Title `Risks Assigned to {entity.name}` · right-aligned actions:
  - `Add from Risk Library` (`btn-primary`, `library_add` icon — when `can.createRisk && can.viewLibrary`) → opens Single-Entity Attach slide-over (`add-from-library-picker.md`).
  - `Create Custom Risk` (`btn-secondary`, `add` icon — when `can.createRisk`) → `Link` to `risk.register.create` with `?entity_id={entity.id}`.
- **Table Columns:** `Code` (link), `Title` (with compact mono badge `RL-xxxx` if linked, plus amber `Update available` pill if `library_has_update` or slate `Retired in Library` pill if `library_is_retired`), `Process` (`process_name ?? '—'`), `Category`, `Rating` (`RatingBadge`), `Owner`.
- **Empty State (when `risks.length === 0`):**
  - Replaces the bare single-line table row with `@thirdline/ui` `EmptyState`:
    - Title: `"No risks assigned to {entity.name} yet"`
    - Body: `"Start from your organisation's master Risk Library to keep risk definitions consistent across entities, or create a bespoke risk."`
    - Primary CTA: `Add from Risk Library` (`btn-primary`, opens picker slide-over; shown when `can.createRisk && can.viewLibrary`).
    - Secondary CTA: `Create Custom Risk` (`btn-secondary`, shown when `can.createRisk`).

---

## 4. Interactive Flows & Confirmations

- **Detach Process:** Clicking `Detach` prompts `window.confirm("Detach {process.code} — {process.name} from {entity.name}? The process itself and its linked risks will NOT be deleted.")`. Sends `DELETE /risk/scoping/{entity}/processes/{process}` and reloads `Scoping/Show?tab=processes`.
- **Create Process / Sub-Process from Tab:** Submitting `ProcessForm` with `entity_id = entity.id` creates the `business_processes` row + `entity_business_process` pivot (`is_primary = true`), logs `process_created` and `process_attached_to_entity`, and immediately renders the row in this tab (and in Process Catalogue & RCSA Universe).

---

## 5. States

| State | Behaviour |
|---|---|
| **Loading** | Lazy-loaded Attach Existing slide-over shows 5 skeleton rows while fetching `/attachable`. Detach/Attach buttons disable while submitting. |
| **Empty (`Processes` tab)** | `@thirdline/ui` `EmptyState`: `"No processes linked to {entity.name} yet."` with two CTAs: `Attach Existing Process` (`btn-secondary`) and `New Process / Sub-Process` (`btn-primary`), gated by `can`. |
| **Empty (`Risks` tab)** | Structured `EmptyState` with prominent `Add from Risk Library` primary CTA (above). |
| **Error** | Flash/inline `role="alert"` banner inside the active tab or slide-over without switching tabs or losing selections. |
| **Permission Denied** | Users without `process.create`/`process.update` see the `Processes` tab read-only (no Attach/New/Detach buttons). Users without `risk.create` see the `Risks` tab read-only (empty state omits CTAs and reads `"No risks are currently assigned to this entity."`). |
| **Low-Bandwidth (100 kbps)** | Attachable processes and Risk Library picker items are **not** sent in the initial `Scoping/Show` payload — both lazy-load only when the user clicks the button, capped at 50 results per search query. Switching between the 6 tabs is instant client-side. |

---

## 6. WCAG 2.1 AA Accessibility

- Tab bar uses `role="tablist"`, each button has `role="tab"`, `aria-selected={activeTab === tab.key}`, `aria-controls="panel-{tab.key}"`, and Left/Right arrow navigation; each panel has `role="tabpanel" id="panel-{tab.key}"`.
- `Detach` button uses `aria-label="Detach {process.name} from {entity.name}"`.
- Status/library pills (`Update available`, `Retired in Library`) include explicit text labels and meet ≥ 4.5:1 contrast.

---

## 7. Data Provenance & Standard §5 Compliance

| Displayed Figure | Authoritative Source | Empty / Null Rule |
|---|---|---|
| Tab counts (`Risks`, `Processes`, `KRIs`, `Issues`, `Sub-Entities`) | Exact array lengths from `ScopingEntityPresenter` | `0` is a true count |
| `linked_risks_count` per process | `COUNT(*)` on `risks` where `process_id = bp.id` (org-scoped) | `0` is a true count |
| Existing `Overview` KPI cards | Unchanged (`riskStats.*`); no fabricated rates added | `0` is a true count |
