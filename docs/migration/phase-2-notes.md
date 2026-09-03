# Phase 2 — Data grid, widget engine, dynamic forms: implementation notes

Branch `migration/phase-1-auth-shell` (continued; Phase 2 did not get its own
branch — see "Deviations"), on `c2750b6` (Phase 1). Prompt:
`05-phase-prompts/phase-2-data-grid-widgets.md`.

## What landed

| Area | Delivered |
|---|---|
| Grid pipeline | `app/Grids/GridQuery::apply()` is the one place search, whitelisted filters and sortable-only ordering are applied. `app/Grids/GridState` is the URL-shaped state (`search`, `filters[]`, `sort`, `dir`, `per_page`, `columns`, `view`, `page`); a bare request applies the user's default saved view. `app/Presenters/GridPresenter::present()` shapes every cell server-side (`{text, href, class, level, pct, raw}`) so the React grid never re-implements a formatter. Saved views keep their JSON shape (`SavedViewCompatibilityTest`). |
| Grid endpoints | `risk/grids/{grid}` (JSON), `…/cell` (inline edit, 422 for undeclared/non-editable columns), `…/bulk`, `…/views` (store/destroy), `…/export/{format}` — all under a `view-grid` gate that defers to the definition's own permission. |
| React grid | `Components/DataGrid/{DataGrid,GridToolbar,GridTable,GridBulkBar,Menu}.jsx` + `useGridState` (partial Inertia reloads of the `grid` prop only). |
| 20 grid pages flipped | `Scoping/Index` (pilot) plus `Controls`, `Register`, `Treatments`, `Issues`, `LossEvents/{Index,NearMisses}`, `Kri/{Index,Breaches}`, `Assessments`, `ControlTests`, `Campaigns`, `Questionnaires/{Index,Library}`, `Imports`, `Admin/Users`, `Emerging`, `Reports/Library`, `Approvals/History`, `Regulatory/Circulars`. Each controller's index injects `GridPresenter` and returns `Inertia::render` with a lazy `grid` prop. The 20 Blade index views are deleted; the register's historic "as at" branch moved verbatim to `risk/register/historic.blade.php` (it renders overlay values that exist only in memory, so it stays hand-rolled and Blade until Phase 5). `app/Livewire/DataGrid.php`, `livewire/data-grid.blade.php` and the two `components/data-grid*` partials are gone. |
| Widget engine | Unchanged on the server (`WidgetDataService`, `WidgetContext`, `DashboardResolver`, `DashboardBinding`, the 21 widget types). New: `WidgetController` (`risk.widgets.payload` JSON, `risk.widgets.export` CSV) and `app/Presenters/WidgetPayloadPresenter`, which resolves the envelope's route-named `drilldown` into URLs (`urls.drill/create/payload/export`, `rows[].url`, `cells[].url`) — the one thing Blade did with `route()` that a React page cannot. |
| Widget rendering | `resources/js/widgets/chartConfigs.js` — the 13 Chart.js renderers ported to pure config builders `(data, envelope, tokens, width)` plus `sparklineConfig`; `widgets/renderers/useChart.js` owns the canvas and lazy-loads Chart.js; `widgets/renderers/{ChartWidget,KpiTile,Heatmap,Register,ActivityTable,MeasureTable,Treemap,Network}.jsx` replace the six Blade type partials and the two DOM renderers. `Components/Widget.jsx` is the panel (title, meta, ⋮ Refresh / Export / Drill down; forbidden / error / ok states; register search and paging through `urls.payload`). `Components/WidgetGrid.jsx` is the 12-column grid. `widgets/theme.js` is kept; `widgets/{index,builder,charts}.js` and the widget block of `app.js` are deleted. |
| Business HQ | `HqController@show` → `Pages/Hq/Show.jsx` (`Pages/Hq/Empty.jsx` for an empty graph). Every payload on the active tab is resolved server-side so the page paints with numbers. Props carry the three empty-state causes (`roleBlocked`, `draftsForType` + `createUrl`, `nodeCountForType`), the preview banner (`preview` / `previewRefused`) and the org tree (`Components/OrgTree.jsx`). |
| Dashboard builder | `App\Services\Widgets\DashboardEditor` holds what the Livewire `DashboardBuilder` component did (settings, tabs, widgets, layout, publish with the binding refusal, unpublish, discard, duplicate, palette). `DashboardBuilderController` exposes it as endpoints under `permission:dashboard.manage` (`PATCH risk/dashboards/{d}`, `POST …/layout`, tabs store/update/destroy, widgets store/update/destroy, publish/unpublish/discard/duplicate); `index` and `edit` are Inertia pages (`Pages/Dashboards/{Index,Edit}.jsx`, `Components/DashboardBuilder.jsx`). GridStack is imported on demand and posts `{tab, items: [{position,x,y,w,h}]}` — the same shape `builder.js` always sent. `app/Livewire/Widgets/*` and their views are deleted. |
| Job progress | `GET risk/jobs/{jobRun}/progress` (`permission:job.view`, tenant-checked) + `resources/js/hooks/useJobProgress.js`; `app/Livewire/JobProgress.php` and its view are deleted (nothing rendered them). |
| Dynamic forms | `app/Presenters/FormSchemaPresenter` (`form()` → `{objectType, sections[{code,label,fields[...]}]}`; `detail()`), `app/Http/Requests/Concerns/ValidatesConfiguredAttributes` (rules from the metadata), `Components/DynamicForm.jsx` (+ `initialValues`, `formDataFor`, `isVisible` helpers) and `Components/DynamicDetail.jsx`. `DB::table('object_versions')->insert` in `DynamicForm.php` and `PersistsConfiguredAttributes` became `ObjectVersion::create` (snapshot passed as an array — the model casts it). The Livewire `DynamicForm` component itself stays until Phase 3 mounts the React one on real create/edit pages. |
| Cross-renderer | `Ported::ROUTES` grew by 24 (the 20 grids, `hq.index`, `hq.show`, `risk.dashboards.index`, `risk.dashboards.edit`). The Inertia root view now includes `layouts/partials/branding` so tenant colour overrides apply to React pages too (`AssetResidencyTest` caught it). |

## Deviations from the prompt, with reasons

- **No `migration/phase-2-*` branch.** The work was committed on the Phase 1 branch (`f4fe635` checkpoint plus this commit). Cutting a branch mid-stream would have re-based the checkpoint for no benefit; the phase is still one reviewable commit.
- **Chart.js renderers ported mechanically.** `chartConfigs.js` is `charts.js` with `(mount, …)` replaced by `(…, width)` and `new Chart(canvas, cfg)` replaced by `return cfg`. Nothing about scales, tooltips or the RAG colour rule changed; the canvas and the instance now belong to the `useChart` hook.
- **Register widget search submits on blur/Enter, not on every keystroke.** The Livewire version used `wire:change`, which is the same behaviour.
- **Builder confirmations use `window.confirm`.** `ConfirmDialog` sits on `Modal.jsx`, whose Headless UI `<Transition>` has the same never-completes problem noted for `Dropdown.jsx` in Phase 1. Swap once `Modal.jsx` is fixed (still a watch item).
- **Breadcrumbs on the grid pages are dropped** (matching the Scoping pilot): `PageHeader` renders Inertia `<Link>`s, which cannot target the Blade dashboards they used to point at.
- **`KriController@breaches`** seeds `filters[status]=active` when the request carries neither `filters` nor `view`, replicating the old `initialFilters`.
- **Dashboard editor validation is inline `validate()`** rather than Form Requests: each action validates one or two fields. Form Requests arrive with the Phase 3 module work.
- **Tests adapted**, all with the same reason (Blade text → Inertia props): `DashboardPublishingTest` (endpoints + `assertInertia`), `HqSurfacesTest` (three HQ assertions), the twelve `tests/Feature/Grid/*` (off `Livewire::test('data-grid')`), `AuthPagesTest` (its still-Blade example path is `/risk/dashboard` now), `MfaFeatureGateTest` (`features.mfa_totp` prop instead of the menu text), `NoFabricatedNumbersTest` (two allowlist lines for the deleted builder view). Where a Livewire test asserted a client-side rule (sort flip, "last column survives"), the server test now asserts the server-side half (direction honoured, unknown columns fall back).
- **One real defect found by the tests**: `GridState` echoed a `view=` id that was not the user's back as `state.viewId` while not applying it. Fixed.
- **Left alone**: `app/Services/Graph/ObjectSyncService.php` still writes `object_versions` with `DB::table` (not in this phase's scope); `app/Livewire/DynamicForm.php` and `admin/*` Livewire components stay for Phase 3/6.

## New tests

`Grid/GridPresenterCoversEveryDefinitionTest`, `Grid/SavedViewCompatibilityTest`, `Inertia/ScopingIndexTest`, `Widgets/DashboardLayoutRoundTripTest`, `Widgets/WidgetPayloadEndpointTest`, `Inertia/HqPagesTest`, `Inertia/JobProgressTest`, `Metadata/FormSchemaPresenterTest`.

## Verification (2026-09-03)

- `php artisan test`: 1341 passed, 4 skipped; the four failures in that run (`AssetResidencyTest` branding, `PeriodSelectorTest` ×2, `MfaFeatureGateTest`) were the Inertia root missing the branding partial, a truncated `historic.blade.php` and a Blade-text assertion — fixed, and those classes re-run green (see the commit).
- Five guard tests: 194 passed.
- `vendor/bin/phpstan analyse`: no errors; baseline regenerated 819 → 641 (deleted Livewire classes and views; three real fixes in `DashboardBuilderController`, `GridController`, `GridPresenter`; `@property-read` docblocks on `GraphObject` and `Dashboard`).
- `npm run build`: clean; GridStack and Chart.js are separate chunks loaded on demand.
- Browser: see the end of this file.

### Browser (2026-09-03, dev DB, super-admin)

- `/hq` redirects to the busiest root; `/hq/144` renders `Hq/Show` with the org tree, the "Assessment Context" dashboard chip (composed for Enterprise, v1), eight tab links and three panels (heat map, register, activity table) painted from server-resolved payloads; no console errors.
- Tab "Risk Development Over Time" mounts a Chart.js canvas (1336×877) through `useChart`.
- `risk.widgets.payload` round-trips: plain refresh → `state: ok`; register widget with `filters[_search]` and `filters[_page]=2` → filtered / paged rows. (A `_search` filter sent to a heatmap returns `state: error`, as the engine always did; only the register renderer sends it.)
- `/risk/dashboards` renders `Dashboards/Index` with one live and three draft rows; `/risk/dashboards/2/edit` renders the builder with GridStack initialised over six items, the palette grouped Charts 16 / Numbers 7 / Tables 7, the binding sentence ("Enterprise", 1 node, Preview link) and no console errors.
- Builder round trip on an empty tab: "add widget" from the library → one GridStack item; its Remove button → none. Both went through `risk.dashboards.widgets.store/destroy` with Inertia re-rendering the props.
