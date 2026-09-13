# Phase 3.6 — Risk appetite

| Area | Delivered |
|---|---|
| Service | `app/Services/Appetite/AppetiteFrameworkService` — statements, categories still without a statement, `currentPosition()` (recorded position, else the average residual score of the category's active risks, else null), `statusFor()` (the screen's rules, unchanged), `metrics()`, `summary()`, `chart()` (a widget envelope of the new chart type `appetite_position`). |
| Policy | `app/Policies/RiskAppetitePolicy` — `viewAny/view/create/update/delete/approve` on `appetite.view/manage/approve`, then tenancy. Auto-discovered; the page test asserts `Gate::getPolicyFor`. |
| Form Requests | `app/Http/Requests/Appetite/{StoreRiskAppetiteRequest,UpdateRiskAppetiteRequest}` — `Rule::exists('risk_categories')->where('organization_id')`, one statement per category enforced by `Rule::unique` (was an ad-hoc query + flash error), `authorize()` through the policy. |
| Controller | `RiskAppetiteController` — `index` → `Inertia::render('Appetite/Index')`, `store`/`update` through the requests; audit trail calls unchanged. Route names and URIs unchanged. |
| Page | `resources/js/Pages/Appetite/Index.jsx` — banner, KPI row (`KpiCard`), chart (`Widget` + `chartConfigs.appetite_position`), metrics table, version history panel; create and edit forms in `Modal`. |
| Modal | `Components/Modal.jsx` rendered without the Headless UI `<Transition>` (the Phase 1 `Dropdown.jsx` fix applied here; the transition never reached its end state on this build). `ConfirmDialog` inherits the fix. |
| Deleted | `resources/views/risk/appetite/index.blade.php` (and its inline Chart.js script). |
| Tests | `tests/Feature/Characterisation/AppetitePositionTest` (classification table, position fallback, summary), `tests/Feature/Appetite/AppetitePageTest` (page props, viewer vs manager, create-once-per-category, update, cross-tenant category rejected, band-order validation, cross-tenant update refused, view deleted). |

## Deviations, with reasons

- **Fabricated dates removed.** The old banner printed "Board approved: today − 3 months" and "Next review: today + 3 months" when nothing was recorded. The summary now carries `null` and the page says "not recorded" / "not scheduled".
- **Fabricated positions removed.** A category with no recorded position and no active risks used to show `0.0` and count as "within". `currentPosition()` returns null; the row shows "not recorded" and the status stays "within" (the old outcome for a zero) — the count is unchanged, the number is no longer invented. Rows fed by the residual-score average say so in a tooltip (`position_source`).
- **Chart** is a new config builder `appetite_position` in `chartConfigs.js` rather than one of the engine's existing types (none draws bars coloured by a band with two reference lines). The envelope is built server-side; band colours travel in the payload.
- **No Livewire component** was involved; nothing else consumed the view.
- **`risk.export.appetite`** stays a plain link (Blade/file route).

## Assertions adapted

None — no existing test read the appetite Blade page.
