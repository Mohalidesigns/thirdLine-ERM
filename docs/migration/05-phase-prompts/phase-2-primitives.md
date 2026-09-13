# Phase 2 — Platform primitives: DataGrid, DynamicForm, Widgets, HQ and dashboards

> Claude Code implementation prompt. Branch `migration/phase-2-primitives`, based on Phase 0 (can run in parallel with Phase 1). This phase replaces the Livewire building blocks that 20+ screens depend on. Do it grid-by-grid and widget-by-widget; both old and new must work until the last consumer flips.

## Context

- `app/Livewire/DataGrid.php` (542 lines) is the universal list component: search, sort, filters, per-page, column toggles, row selection, bulk actions, inline cell edit, saved views (`data_grid_views`), CSV/XLSX export. It is driven by 22 `app/Grids/Definitions/*Grid.php` extending `app/Grids/GridDefinition.php` (`name, query, columns, filters, rowActions, bulkActions, defaultSort, perPageOptions, permission, updateCell, editPermission`). `tests/Feature/Grid/*` (12 files) test the definitions and the Livewire behaviour.
- `app/Livewire/DynamicForm.php` (317) renders tenant-configured attributes from `app/Support/Metadata/FormFieldRegistry.php` (522) and persists via `app/Http/Controllers/Concerns/PersistsConfiguredAttributes.php`; `app/View/Components/{DynamicForm,DynamicDetail}.php` wrap it.
- Widgets: `app/Services/Widgets/WidgetDataService.php` resolves 21 widget types to payload envelopes; `resources/js/widgets/{charts.js (904), index.js, theme.js, builder.js}` render them; `app/Livewire/Widgets/{WidgetPanel,DashboardBuilder}.php` host them; `Risk/HqController` and `Risk/DashboardBuilderController` are the pages. Tests: `tests/Feature/Widgets/*`, `HqSurfacesTest`.
- ThirdLine offers `DataTable` (client-side), `FilterBar` (URL-synced), `Pagination`, `StatCard`, `EmptyState` — the visual vocabulary to compose from. It has no server-driven grid, dynamic form or widget engine; these are justified additions to `@thirdline/ui` (see `04 §6`).

## Scope

### 2.1 `GridPresenter` (server) — `app/Presenters/GridPresenter.php`
- `present(GridDefinition $def, Request $r, User $u): array` → `{ name, columns[{key,label,sortable,editable,width,type}], filters[{key,label,type,options}], sort{key,dir}, perPage, perPageOptions, rows{data,links,meta}, selection{mode}, rowActions[{key,label,route,method,confirm,permission}], bulkActions[…], views[{id,name,isDefault,state}], canEdit, canExport{csv,xlsx}, emptyMessage, emptyIcon }`.
- Reuses the *exact* query pipeline `DataGrid::render()` uses today (search → filters → sort → paginate) — move that pipeline out of the Livewire component into `app/Grids/GridQuery.php` so both callers share it (the Livewire grid keeps working until Phase 6).
- Saved-view JSON shape stays identical to what `DataGrid::saveView()` writes so existing `data_grid_views` rows load.
- Endpoints (all under `auth`, permission from `$def->permission()`): `GET risk/grids/{grid}` (Inertia partial reload target — grids live inside pages, so the page controller calls the presenter and passes `grid` as a prop; use `Inertia::lazy`/`optional` for re-fetch), `POST risk/grids/{grid}/cell` (`updateCell`, `editPermission`), `POST risk/grids/{grid}/bulk/{action}`, `POST|DELETE risk/grids/{grid}/views`, `GET risk/grids/{grid}/export.{csv,xlsx}` (delegates to `GridExport`).

### 2.2 React `DataGrid` — `resources/js/Components/DataGrid/`
`DataGrid.jsx` (props = presenter output), `GridToolbar.jsx` (search with 350 ms debounce → `router.reload({ only: ['grid'], data })`, filters via ThirdLine `FilterBar`, per-page, column toggles, saved views menu, export links), `GridTable.jsx` (ThirdLine `.data-table`, server sort on header click, row checkbox, inline edit cell with `axios.post` + optimistic update + error revert), `GridBulkBar.jsx`, `useGridState.js` (URL-synced state; `preserveState`, `preserveScroll`). Empty state via ThirdLine `EmptyState`.

### 2.3 Pilot and flip
Flip `EntitiesGrid` first: `Risk/ScopingController@index` returns `Inertia::render('Scoping/Index', ['grid' => $presenter->present(...)])`. Then flip the remaining 19 (the definitions do not change). For each flip, the module's Index page is created in this phase **as a grid-only page**; module-specific create/show/edit pages follow in Phases 3–5. Keep `resources/views/components/data-grid*.blade.php` until the last flip, then delete with `app/Livewire/DataGrid.php` and `resources/views/livewire/data-grid.blade.php`.

### 2.4 `FormSchemaPresenter` + React `DynamicForm` / `DynamicDetail`
- `app/Presenters/FormSchemaPresenter.php`: `for(string $objectTypeCode, ?GraphObject $object): array` → `{ sections[{code,label,fields[{code,label,type,required,options,validation,value,help}]}] }` from `FormFieldRegistry` / `FormOptionResolver`.
- `resources/js/Components/DynamicForm.jsx` renders the schema into ThirdLine form primitives and exposes `values` for the parent `useForm` (`attributes[code]`); `DynamicDetail.jsx` renders values read-only (replaces `<x-dynamic-detail>` on Risk/Control show pages — keep the `omit` semantics from `app/View/Components/DynamicDetail.php`).
- Validation stays server-side: `app/Http/Requests/Concerns/ValidatesConfiguredAttributes.php` trait that builds rules from `MetadataGuard` — used by the Phase 3+ Form Requests.
- Replace the raw `DB::table('object_versions')->insert` in `PersistsConfiguredAttributes:114` and `DynamicForm:183` with `ObjectVersion::create` (the trait/observer bypass listed in `02 §6.6`).

### 2.5 Widgets in React
- `resources/js/widgets/renderers/*.jsx`: port each of the 14 Chart.js renderers from `charts.js` into a `useChart(canvasRef, buildConfig)` hook that owns the `Chart` instance (create in effect, `chart.destroy()` on unmount/props change) — this retires `destroyDetachedCharts()` and `wrapSizedCanvases` (`resources/js/app.js:106-115`, `layouts/app.blade.php:23-98`). Each renderer owns a fixed-height, `position:relative` container.
- `Treemap.jsx`, `Network.jsx` from `widgets/index.js` L27-165 as React components (SVG).
- `resources/js/Components/Widget.jsx`: takes a payload envelope (`state`, `type`, `data`, `meta`) and dispatches; `state !== 'ok'` → ThirdLine `EmptyState`; theme from `widgets/theme.js` (keep the file; it reads CSS variables).
- `Pages/Hq/{Index,Show}.jsx` (`Risk/HqController`), `Pages/Dashboards/{Index,Edit}.jsx` (`Risk/DashboardBuilderController`): `DashboardBuilder.jsx` wraps GridStack (import dynamically), serialises `{x,y,w,h,id}` exactly as `widgets/builder.js` does, posts to the existing `updateLayout` endpoint (expose `Widgets/DashboardBuilder::updateLayout` as `POST risk/dashboards/{dashboard}/layout` in `DashboardBuilderController`). Tabs, `layout_override` and `published_layout` semantics unchanged (see Wave 2 notes: `version` is the staleness key — do not touch it).
- Delete `app/Livewire/Widgets/*`, `resources/views/livewire/widgets/*`, `resources/views/widgets/types/*`, `resources/views/hq/*`, `resources/views/risk/dashboards/*` when the pages flip.

### 2.6 `useJobProgress` hook
`resources/js/hooks/useJobProgress.js` polling `api/v1/jobs/{jobRun}` (existing, `scope:job.view` — add a session-authenticated twin `GET risk/jobs/{jobRun}/progress` under `permission:job.view` so the SPA does not need an API token). Replace `app/Livewire/JobProgress.php` usages.

## Acceptance criteria
1. `tests/Feature/Grid/*` green against `GridQuery` (refactor the tests to call the presenter where they called the Livewire component; behaviour assertions unchanged).
2. New `tests/Feature/Grid/GridPresenterCoversEveryDefinitionTest.php`: for every class in `app/Grids/Definitions/`, the presenter returns columns/filters/permission matching the definition, `permission()` is a seeded permission, and a user without it gets 403 from every grid endpoint.
3. New `tests/Feature/Grid/SavedViewCompatibilityTest.php`: a `data_grid_views` row written by the old Livewire grid (fixture JSON captured before the change) is applied by the presenter to the same query.
4. `tests/Feature/Widgets/*`, `HqSurfacesTest`, `DashboardResolver` tests green; new `tests/Feature/Widgets/DashboardLayoutRoundTripTest.php` (layout posted from React persists as the same JSON shape).
5. `tests/Feature/Metadata/*` green; new `FormSchemaPresenterTest` covering every field type in `FormFieldRegistry`.
6. `grep -rn "DB::table('object_versions')" app` returns nothing.
7. `NoFabricatedNumbersTest` green (widget payloads unchanged).
8. Visual check list in the PR: one screenshot per widget type from the seeded `erm-hq` dashboard, before and after.
