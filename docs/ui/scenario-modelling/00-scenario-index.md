# 00. Scenario index

**Page:** new `resources/js/Pages/Sm/Scenarios/Index.jsx` · **Route:** `GET /risk/scenarios` (`scenario.index`, `permission:scenario.view`, behind `feature:scenario_modelling`) · **Grid:** new `App\Grids\Definitions\ScenariosGrid` · **Phase:** 1

Read `01-workspace-shell.md` first. The status-colour table (§2 there) and the permission→action table (§3 there) are canonical and are **not** restated here; the shared states, accessibility baseline, low-bandwidth rules and the "no fabricated numbers" rule are §0.2–§0.5 there.

**Field and route names follow ADR 0027 Amendment 1** — §A1.2 for the frozen columns (`business_unit_id`, `weighting`, `designed_on`, `validated_on`, `review_due_on`; §A1.3 refused an `entity_id`) and §A1.11 for the route table (name prefix `scenario.`, singular; URL prefix `/risk/scenarios`).

---

## 1. Purpose and the user

**Who.** An Operational Risk Manager or Risk Analyst with `scenario.view` — the person who owns the scenario pipeline — and, less often, a validator or signer arriving to find the scenario somebody sent them. They open it from the nav ("Scenario Modelling", `05`), from a bookmark, or from a link in a flash message.

**In the first ten seconds** they must see: what is waiting on them, what has come back, what is past its review date, and how to open one. This is a **work queue**, not a folder. An alphabetical list of scenarios is a filing cabinet; the thing that makes it a queue is that the statuses and the review dates are on the surface.

**Must be able to:** open a scenario's workspace; start one; narrow the list by status, OP event type, nature and business unit; save the narrowing as a view they come back to; export what they can see.

**Not on this screen:** pipeline charts, exposure totals, heatmaps. Plan §4.5 puts those on the Phase-5 **Dashboard**, and the index has no computed figure to show that a dashboard would not show better. See §7 for the two counts that *are* here and why they are counts and not rates.

---

## 2. Props the index expects

```
{
  grid,                        // GridPresenter payload for ScenariosGrid — the whole list surface
  totals: {
    scenarios: int,            // rows in scope, ignoring search/filters
    signed_off: int,
    in_validation: int,
    returned: int              // status = returned_for_rework
  },
  reviewCounts: {
    overdue: int,              // review_due_on < today (Africa/Lagos), status not archived
    undated: int               // review_due_on IS NULL, status not archived and not draft
  },
  can: { create: bool },
  createUrl: string|null,
  roadmapNote: string|null
}
```

**(prop decision) `totals` and `reviewCounts` ignore search and filters; `grid.rows.meta.total` does not.** The subtitle describes the register, the grid describes the current view, and the two numbers must be allowed to differ without looking like a bug. §3.1 labels them so they cannot be confused.

**(prop decision) The prop is `reviewCounts` here and `review` on the workspace** (`01` §4), and the two have different shapes on purpose: this one is register-wide counts, that one is a single scenario's `{due_on, days_overdue}`. Same word, different object — named apart so a shared presenter cannot serve one where the other is meant.

**(prop decision) `reviewCounts.overdue` and `.undated` are computed server-side**, in `Africa/Lagos`, against the day boundary (standard §14). The browser never works out whether a date is past — `new Date().toISOString()` is yesterday for the first hour of a Lagos day (`01` §4.1).

**(prop decision) `reviewCounts.undated` excludes `draft` and `archived`.** A draft has no review cadence yet and an archived scenario has none any more; counting either would inflate a number whose only job is to say "these approved scenarios will never come back for review".

`can.create` and `createUrl` are separate because a `scenario.create` holder still needs the route to exist (the feature flag can be off for one tenant and on for another). When `createUrl` is `null` the button does not render.

---

## 3. Layout

### 3.1 Above the fold (1440 × 900)

*Every count, reference, name and date in this sketch is sample data (`01` §0) — invented to show the shape of the screen. §7 names the source of each figure the page really renders.*

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│ Scenario modelling                                                                  │  breadcrumb
│ Scenario Modelling & Analysis                                    [ + New scenario ] │  h1 + action
│ 24 scenarios · 9 signed off · 3 in validation · 2 returned for rework               │  subtitle
│ ⚠ 4 are past their review date · 2 have no review date set                          │  review strip
│ ⓘ Quantitative sections (frequency, impact, insurance) arrive in a later release.   │  roadmapNote
│                                                                                     │
│ ┌─ grid toolbar ──────────────────────────────────────────────────────────────────┐ │
│ │ [🔍 Search…      ] [Status ▾] [OP event type ▾] [Nature ▾] [Business unit ▾] Clear │ │
│ │                                        [🔖 Views ▾] [▦ Columns ▾] [⬇ Export ▾] [25/page ▾] │
│ └───────────────────────────────────────────────────────────────────────────────────┘
│ ┌──────────────────────────────────────────────────────────────────────────────────┐
│ │ Reference    │ Scenario          │ OP event type   │ Nature  │ Status    │ Review …│
│ │ SM-2026-014  │ Loss of key staff…│ OP5 · Loss of k…│ Group … │ ●Signed off│ 09 Nov…│
│ │ SM-2026-013  │ NIBSS switch outa…│ OP11 · System d…│ Standard│ ●In valid…│ —      │
│ └──────────────────────────────────────────────────────────────────────────────────┘
│                                              Showing 1–25 of 24   ‹ 1 ›             │
└────────────────────────────────────────────────────────────────────────────────────┘
```

1. **`PageHeader`** — title **Scenario Modelling & Analysis**; breadcrumb **Scenario modelling** (no `href`, it is the current section root); action **New scenario** (`btn-primary`, `add_circle` icon) when `can.create && createUrl`.
   Subtitle, all four figures from `totals`:
   `"{scenarios} scenarios · {signed_off} signed off · {in_validation} in validation · {returned} returned for rework"`.
   Each is a count of a set — a true `0` is allowed (standard §5, and `?? 0` is permitted for a count). A clause whose count is `0` is **omitted** rather than printed as "0 in validation": the subtitle is a work queue, and "0 returned for rework" is noise. `{scenarios}` is always printed, including when it is `0`.
2. **Review strip** — one line, rendered only when `reviewCounts.overdue > 0 || reviewCounts.undated > 0`, `role="note"`:
   - `overdue > 0`: `warning` icon, `text-red-700 font-semibold` on the count: "**{overdue}** {is|are} past {its|their} review date".
   - `undated > 0`: `text-amber-800`: "**{undated}** {has|have} no review date set".
   - Both, joined by " · ".
   - Neither: the strip does not render. It is **not** replaced by "All reviews current" — the absence of a problem is not a measurement, and a green "all current" line would be the first thing somebody pastes into a committee paper (the `Bcms/Plans/Index.jsx` "PLANS CURRENT IS BLANK" rule).
3. **`roadmapNote`** — verbatim in a `role="note"` info strip (`bg-blue-50 border-blue-100 rounded-lg px-4 py-3 text-sm text-gray-700`) when non-null. The same prop the workspace sends (`01` §5.1), so the copy lives in one place on the server and a user reads one consistent statement of what this release does and does not do; the server may send a shorter variant for the index, as the sketch shows. Not rendered when `null`.
4. **`<DataGrid grid={grid} />`** — the whole list surface. No bespoke table, no second filter bar, no pill row (standard §8; §3.4 below for why no pills).

### 3.2 Grid columns

Column order below. "Default" is visibility on first load; every column is always in the **Columns** chooser, and `GridController::export` exports **the currently visible columns only** — so a user exporting for a committee must first tick what they need. That is existing behaviour (`app/Http/Controllers/Risk/GridController.php:150`), and it is worth telling them: the Export menu carries the line "Exports the columns you can see." (§3.5).

| # | Key | Label | Type | Default | Sort | Search | Cell content and absent value |
|---|---|---|---|---|---|---|---|
| 1 | `reference` | Reference | text + `linkTo(workspace)` | ✔ | ✔ | ✔ | Mono. `SM-2026-014`. The row's primary link |
| 2 | `name` | Scenario | text + `linkTo(workspace)` | ✔ | ✔ | ✔ | `str()->limit(60)`. Never null — `string(250)` NOT NULL (§A1.2) |
| 3 | `op_event_type` | OP event type | text | ✔ | ✔ (node `name`) | ✔ | `using()` → the taxonomy node's **`name` verbatim**, `str()->limit(45)`, full text in the cell `title`. §A1.9: `risk_taxonomies` has **no `code` column** — the name already begins "OP5 ", so the cell is not a concatenation and needs no formatting. **`—` when unmapped** — a scenario with no OP mapping is a real state, not an error |
| 4 | `nature` | Nature | `badge` | ✔ | ✔ | — | Verbatim workbook labels: "Group scenario" / "Standard scenario" / "Local scenario" (`Scenario::NATURES`, §A1.8). Map all three to `bg-gray-100 text-gray-700` — nature is a classification, not a health signal, and colouring it would compete with Status. Never null (NOT NULL default `'local'`) |
| 5 | `status` | Status | `badge` | ✔ | ✔ | — | The `01` §2 colour language, via the badge map in §3.3. Words from `status_label` |
| 6 | `weighting` | Weighting | `badge` | hidden | ✔ | — | "Low" / "Medium-low" / "Medium-high" / "High" (`Scenario::WEIGHTINGS`). `bg-gray-100 text-gray-700` for all four — same reasoning as nature: a weighting is not a RAG rating and must not read as one. **`—` when unset** — §A1.2 makes it nullable with no default precisely because a default "would be a fabricated figure on every scenario nobody has weighted" |
| 7 | `business_unit.name` | Business unit | text | hidden | ✔ | ✔ | `—` when unset. §A1.3: the owning unit is `business_units`, and `entity_id` was refused — so this column is a business unit and must not be labelled "Entity" |
| 8 | `designed_on` | Designed | `date('d M Y')` | hidden | ✔ | — | "09 Nov 2026". **`—` when null** — `GridTable.jsx:7` already renders `cell.text ?? '—'` |
| 9 | `validated_on` | Validated | `date('d M Y')` | hidden | ✔ | — | Same. Stamped by the server on the `validated` transition; never authored by the designer (`02` §5.9) |
| 10 | `review_due_on` | Review due | `date('d M Y')` | ✔ | ✔ | — | **`—` when null.** Never "0 days", never "Current". Indexed for exactly this query (`sm_scenarios_org_review_due_idx`, §A1.2) |
| 11 | `review_state` | Review status | `badge` | ✔ | ✖ | — | Derived; see §3.3. Not sortable (`GridQuery` sorts a plain column, `app/Grids/GridQuery.php:59`, and this is computed) |

Review due is deliberately **two** columns, because the date and the judgement about the date are different things, and merging them would put a red word where a user expects a number.

`defaultSort()`: `['updated_at', 'desc']` — most recently worked first. That is the work-queue behaviour, and `updated_at` is a real column. `review_due_on` ascending is *not* the default because both engines put `NULL` first on `ASC`, which would park every undated scenario at the top of the queue ahead of the ones that are actually overdue. Nulls-last would need an expression sort, which `Column::orderByColumn()` (a column name string) cannot express — §8 gap 3.

### 3.3 The two badge maps

`Column::badge()` renders `{text: humanise(value), class: map[value] ?? map['*']}`, so a grid cell carries **classes and words only — no icon and no React component** (`GridPresenter::cell()`). Two consequences, both accepted:

- **The index chip has no `lock` / `inventory_2` icon.** `ScenarioStatusChip` (`01` §0.1.1) renders those in the workspace header, where a component can. Words and colours are identical in both places, so the `01` §2 colour language holds across the module; the icon is a *third* channel available only on the workspace. Colour is never the sole signal either way — the words are always there — so 1.4.1 is satisfied on the index without it.
- **The navy signed-off chip needs a real CSS class.** `badge bg-[var(--color-primary)] text-white` declared only inside a PHP array is a purge risk: the `@source` globs in `resources/css/app.css` are `../**/*.blade.php`, `../**/*.js`, `../**/*.jsx` — **`app/**` is not scanned** — and standard §10 records that "a purged Tailwind class is a silent visual regression" with no test able to catch it. So Phase 1 adds one line beside the existing badges in `resources/css/app.css`:

  ```
  .badge-status-frozen { @apply bg-[var(--color-primary)] text-white; }
  ```

  `ScenariosGrid`'s map and `ScenarioStatusChip` both use that class name. A class defined in the stylesheet cannot be purged away.

**Status map** (values are `sm_scenarios.status`; the words come from `status_label`, which the server humanises so a tenant can rename a stage without a front-end release):

```php
'draft'               => 'bg-gray-100 text-gray-700',      // badge-status-draft
'in_design'           => 'bg-blue-100 text-blue-700',      // badge-status-pending
'designed'            => 'bg-green-100 text-green-700',    // badge-status-active
'in_validation'       => 'bg-yellow-100 text-yellow-800',  // badge-medium
'validated'           => 'bg-purple-100 text-purple-700',  // badge-status-completed
'signed_off'          => 'badge-status-frozen',            // navy, see above
'returned_for_rework' => 'bg-red-100 text-red-700',        // badge-status-overdue
'archived'            => 'bg-gray-100 text-gray-700',
'*'                   => 'bg-gray-100 text-gray-700',
```

The `'*'` fallback is required: an unmapped status must not fall through to no class at all, and it must not be styled as an error.

**Review-state map.** `using()` runs before the badge map (`GridDefinition::valueOf()` honours `->using()`, then `cell()` maps on the result), so the closure returns the *key* and the map colours it. `humanise()` supplies the words:

| `using()` returns | Rendered words | Class | When |
|---|---|---|---|
| `overdue` | Overdue | `bg-red-100 text-red-800` | `review_due_on` < today (Africa/Lagos) and status not `archived` |
| `due` | Due | `bg-yellow-100 text-yellow-800` | `review_due_on` set, today or later, status not `archived` |
| `no_date` | No date | `bg-gray-100 text-gray-600` | `review_due_on` null and status not `draft`/`archived` |
| `not_applicable` | Not applicable | `bg-gray-100 text-gray-600` | status `draft` or `archived` |

**No day count on this screen.** "Overdue by 12 days" would need either a per-row prop the grid's row shape does not carry (`{id, href, cells, actions}`) or a browser-side date subtraction that breaks the timezone rule (`01` §4.1). The exact date is in the adjacent **Review due** column, one cell to the left; the workspace header carries the day count where the controller can compute it properly (`01` §4.2). Cutting the number is the standard's instruction, not a compromise: a figure with no safe source is absent.

### 3.4 Filters

Four, in this order, all from `ScenariosGrid::filters()`, rendered by `GridToolbar` as `<select>`s with `sr-only` labels:

| Key | Label (also the "all" option) | Options source |
|---|---|---|
| `status` | All statuses | The eight lifecycle statuses, in `01` §2's order, labelled with `status_label` |
| `op_event_type` | All OP event types | `RiskTaxonomy` nodes under the tenant's `'Basel OP event types'` parent (§A1.9), labelled with `name` verbatim and ordered by `sort_order`. Tenant extensions (CBN ORMS sub-types) appear because the query reads the taxonomy, not a constant |
| `nature` | All natures | `group` → "Group scenario", `standard` → "Standard scenario", `local` → "Local scenario" |
| `business_unit` | All business units | `BusinessUnit::where('organization_id', TenantContext::organizationId())->orderBy('name')->pluck('name','id')` — the same source `RisksGrid`'s business-unit filter uses |

Plus `DataGrid`'s own search box, which covers the columns marked searchable: `reference`, `name`, `op_event_type`, `business_unit.name`.

**The fourth filter is a business unit, not an entity.** §A1.3 refused `entity_id` for `sm_scenarios` and made the owning unit `business_unit_id`: `entities` is the *scoping* tree that `GraphScope` walks for "which part of the org chart is in scope", while `business_units` are the departments and divisions that own work, and ADR 0026 decision 0.5 settled that "`business_units` are authoritative". The workbook's "Entity" cell is answered by `nature` + `business_unit_id`. So this filter — and column 7 — must say **Business unit**; labelling it "Entity" would invite a user to look for a legal entity the schema does not store.

**No status pill row.** `Register/Index.jsx` puts quick-filter pills above its grid; this screen does not, for one reason: two controls filtering the same column is how a page ends up saying "24 scenarios" above a grid showing 3, and then the pill and the filter disagree about which is active. The grid's **Status** filter is the single control, and the subtitle carries the counts. `useGridState` is therefore not needed here — `DataGrid` owns its own state.

**No bulk actions, so no row checkboxes.** `GridPresenter` sets `selection.mode = 'multiple'` only when `bulkActions()` is non-empty. Phase 1 declares none: a bulk lifecycle transition applied across a selection that happens to include a signed-off scenario is precisely the irreversible-by-accident case the module's freeze rule exists to prevent, and there is no bulk operation on a scenario list that a committee would want to perform without opening the record.

### 3.5 Saved views, columns, export, paging

All four are `GridToolbar`'s existing behaviour and need no new design:

- **Views** — save the current search/filters/sort/visible columns/per-page as a named view, optionally as the default; delete a view. Views are per user (`GridPresenter::views($definition, $user)`). Empty state inside the menu is the existing "No saved views yet." Three views worth seeding in the module's own docs, not the UI: *Past review* (`review_state` — not filterable, so: sort by Review due), *Awaiting validation* (`status = in_validation`), *Returned to me* (`status = returned_for_rework`).
- **Columns** — the chooser, with the last visible column's checkbox disabled (existing behaviour).
- **Export** — CSV and XLSX as plain anchors (they are downloads, not visits). Gated by `scenario.view`, **not** `scenario.run_export`: `routes/web.php:297` puts the entire `risk.grids.{grid}` group behind `can:view-grid,grid`, which resolves to `ScenariosGrid::permission()`. `scenario.run_export` is reserved for the Phase-5 LS-workbook and board-pack documents (`01` §3). The menu carries one added line, `text-xs text-gray-500`: "Exports the columns you can see." — because exporting the *visible* columns is existing behaviour and silently omitting Weighting from a committee pack is the kind of surprise worth one sentence.
- **Per page** — `perPageOptions()` default (25/50/100).

### 3.6 Row actions

`RowAction::make('Open', 'visibility', fn (Scenario $s) => route('scenario.show', $s))->can('scenario.view')` — one action, because the row's Reference and Scenario cells already link to the same place and a second edit action would be a third route into the same workspace. `ScenariosGrid::emptyIcon()` → `'assessment'` (the icon the risk register uses; no new glyph).

---

## 4. States

| State | Trigger | What the page shows |
|---|---|---|
| **Loading** | Inertia visit | The progress bar only. Props are synchronous; no skeleton, because a skeleton that reserves grid height would jump when the toolbar wraps at narrow widths |
| **Empty: nothing in the register** | `totals.scenarios === 0` **and** no search, no filter, no view active | `EmptyState` **in place of the grid**: title "No scenarios yet". Description: "A scenario is one operational-risk event assessed end to end — its causes, the business lines it hits, its exposure, and what you decided about it. Create one, or wait for the scenario library in a later release." Action **New scenario** when `can.create && createUrl`. Detectable from the payload: `grid.state.search === ''` && every `grid.state.filters` value empty && `grid.state.viewId == null` |
| **Empty: filters exclude everything** | rows empty but the register is not | The grid's own empty state: `ScenariosGrid::emptyMessage()` → **"No scenarios match those filters."** plus the toolbar's **Clear** control, which is already visible whenever a filter is set |
| **Empty: a saved view returns nothing** | `viewId` set, rows empty | Same grid empty state, plus one `role="status"` line above it: "The view '{view name}' has no matches right now." The view is **not** silently dropped — a view that empties and then refills is normal, and deleting it would lose the user's setup |
| **Populated** | rows present | §3 |
| **Partial** | some rows unmapped / undated / unset | Per cell: `—` for a null date, `—` for a missing OP mapping, business unit or weighting, "No date" / "Not applicable" for review state. A row is never blank and never `0` |
| **Error (500)** | server | The standard error page |
| **Network failure / non-Inertia response / session expired** | — | `01` §0.2. This page has no form and no write, so a 419 cannot originate here |
| **Permission denied (403)** | no `scenario.view` | The 403 page. The nav entry is hidden by `NavigationPermissionGateTest`, so a 403 means a typed or stale URL |
| **404** | out-of-tenant id in a bookmarked grid URL | The standard 404 page (standard §7: out-of-tenant is 404, not 403) |
| **Feature flag off** | `feature:scenario_modelling` | The route group 404s. No "coming soon" screen |
| **Read-only-after-sign-off** | — | **Not applicable to this screen.** The index is a list; freezing is a workspace concern (`01` §5.5). A signed-off scenario appears here with a navy **Signed off** chip and the same single **Open** action |
| **Degraded network** | 100 kbps | §6 |

---

## 5. Interactions, keyboard and screen reader

**Everything on this page is `DataGrid`'s existing interaction model**, which is already built and already accessible; this section names only what the module adds or constrains.

- **Tab order:** breadcrumb → **New scenario** → grid search → the four filter selects → **Clear** (when present) → Views → Columns → Export → per-page → column headers (sort buttons) → rows in visual order (each row's primary link, then its **Open** action) → pagination.
- **Open a scenario:** click the Reference or Scenario cell link, or the row's **Open** action, or Enter/Space on the focused link. Plain Inertia navigation to `scenario.show`.
- **Search:** 350 ms debounce (existing `GridToolbar`), then a server visit. `sr-only` label "Search". The `type="search"` input's native clear button works; `Escape` in the field clears it.
- **Filter:** each select fires a visit on change. `sr-only` label from `Filter::label`. The first option *is* the "all" state, so there is no separate reset per filter — **Clear** resets everything.
- **Sort:** column header buttons, `aria-sort` on the header cell (existing `GridTable`). Announced by the browser as "sorted ascending".
- **Saved views:** the Views menu is a `Menu` (Headless UI), `aria-expanded` on its button; Enter/Space opens, `Escape` closes and returns focus to the button, arrows move within the menu. Saving a view posts and the menu closes on success.
- **Announcements:** after any grid visit settles, `DataGrid`'s existing "Showing {from}–{to} of {total}" line is the announced result. The page adds no live region of its own — a second one would double-announce every filter change.
- **Screen-reader notes.** The table has `<caption class="sr-only">` ("Scenarios in this organisation") and `scope="col"` headers (existing `GridTable`). A row reads as: "SM-2026-014, link. Loss of key staff — succession planning failure, link. OP5 Loss of key staff talent management. Group scenario. Signed off. 09 Nov 2026. Overdue. Open, link." The **Review status** cell's words carry the judgement, so a user who never sees the colour still learns the row is overdue.
- **Focus is preserved across grid visits** (`preserveScroll`/`preserveState` in `useGridState`); a filter change does not drop focus to `body`.
- **Nothing on this page is destructive or irreversible.** Deleting a saved view is the only write, and it is a view, not a record.

---

## 6. Low bandwidth (100 kbps ≈ 12 KB/s)

- **The grid is server-paged**, so the payload does not grow with the register: 25 rows × ~0.35 KB ≈ 9 KB, plus the column/filter/view definitions ≈ 4 KB. ~13 KB, about 1 s. A 500-scenario tenant costs the same as a 20-scenario one.
- **Nothing is deferred and nothing polls.** There is no below-the-fold content, no chart, no image, no extra font.
- **The filter option lists are the only thing that scales badly**: `op_event_type` (17 nodes whose labels are long — OP7 is 158 characters, §A1.9) and `business_unit`. Budget ~6 KB for both. If a tenant's business-unit list grows past a few hundred, that filter becomes a `Combobox` with server-side search — flagged, not designed here, because `business_units` are departments and divisions (§A1.3: BU-IT, BU-IA, BU-LG, BU-CX, BU-PB, BU-OP in the demo seed), so tens, not thousands.
- **While navigating:** the previous page stays interactive with the Inertia progress bar. A filter change on a slow link therefore never shows an empty grid — it shows the stale rows until the new ones arrive, which is the right trade for a read-only list.
- **Export links are plain anchors**, so a download starts in the browser's own queue and a slow link cannot make the page look hung.

---

## 7. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| `{scenarios} scenarios` | `totals.scenarios` — count of `sm_scenarios` rows in the tenant and in the user's scope, ignoring search/filters | Printed even when `0`; the empty state replaces the grid but keeps the subtitle |
| `{signed_off} signed off`, `{in_validation} in validation`, `{returned} returned for rework` | `totals.*` — grouped counts by `status` over the same set | Clause omitted at `0` (§3.1) |
| `{overdue}` past review | `reviewCounts.overdue` — `review_due_on < today` in Africa/Lagos, status not `archived`, computed server-side | Strip not rendered at `0` |
| `{undated}` no review date | `reviewCounts.undated` — `review_due_on IS NULL`, status not `draft`/`archived` | Strip not rendered at `0` |
| Per-row Review due date | `sm_scenarios.review_due_on` via `Column::date()` | `—` |
| Per-row Review status | Derived on the server from `review_due_on`, today in Africa/Lagos, and `status` (§3.3) | "No date" / "Not applicable" |
| "Showing 1–25 of {n}" | `grid.rows.meta` — the filtered count | The grid's own empty state |
| **Deliberately absent: a "% reviewed" or "scenarios current" rate** | — | Cut. A rate needs a denominator, and "which statuses count as in force" reads differently to two people; the count answers the work-queue question directly. Plan §4.5 puts pipeline measurement on the Phase-5 Dashboard, where the denominator can be stated once and audited. Standard §5: a rate over nothing is undefined, not zero |
| **Deliberately absent: "Overdue by N days"** | — | Cut on this screen; see §3.3. Present on the workspace header, where the controller computes it (`01` §4.2) |

---

## 8. Known gaps (also in the HANDOFF)

1. **`review_state` is not filterable.** `Filter` maps to a column and this is derived, so "show me only the overdue ones" needs either a `review_due_on` range filter or a materialised flag. A user can sort by Review due and read down, which is adequate for Phase 1 and inadequate for a 500-scenario tenant. Recommend the backend add a `review` filter with a `whereDate` branch in `ScenariosGrid::query()` if the coordinator wants it in Phase 1.
2. **No "returned to me" scoping.** `totals.returned` counts every returned scenario in the tenant, not the ones this user designed. `sm_scenarios.created_by` exists, so the prop is cheap; the UI copy would change to "{n} returned to you". Left out of Phase 1 because the module has no assignment concept yet — nobody is *assigned* a scenario, and implying otherwise would be a fabricated relationship.
3. **Sorting Review due ascending puts undated rows first** on both MySQL 8 and MariaDB 10.4 (NULL sorts first on ASC). Nulls-last needs an expression sort; `Column::orderByColumn()` returns a column name and `GridQuery::apply()` calls `orderBy()` with it (`app/Grids/GridQuery.php:59`). Not fixable in the grid definition alone — flagged to the backend engineer, and the reason `updated_at desc` is the default.
4. **The index cannot show whether a scenario has ever been signed off and reopened** (`scenario.previous_signoff`, `01` §2). That marker exists only in the workspace payload. If a committee needs to spot reopened scenarios from the list, it needs a column, which needs a grid cell type that can carry two facts. Deferred.
