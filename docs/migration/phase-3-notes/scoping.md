# Phase 3.1 — Scoping / entities: implementation notes

Branch `p3/scoping`, cut from `9c133a2` (Phase 2). Prompt: `05-phase-prompts/phase-3-core-risk.md` §3.1; common brief: Phase 3 per-module recipe.

## What landed

| Area | Delivered |
|---|---|
| Characterisation | `tests/Feature/Characterisation/EntityRiskScoreTest.php` — written against the Blade controller first (view data), then re-pointed at the Inertia props with the same expected numbers. Pins the two DIFFERENT risk-score formulas the screens have always shown: dashboard `(5C+4H+3M+2L)/total`, sub-entity table on the detail page `(5C+4H)/total`. Both carried unchanged. |
| Policy | `app/Policies/EntityPolicy.php` — `viewAny/view/create/update/delete` on `entity.view/create/edit/delete`; then tenancy (`organization_id`); then node scope: a user pinned to a node (`scope_entity_id`, outside `authorization.full_org_roles`) reaches only entities whose `hierarchy_path` starts with `GraphScope::rootPathFor()`; a missing scope node fails closed. Discovered by convention (test asserts `Gate::getPolicyFor(Entity::class)`); no `Gate::define` closure belonged to this module, none deleted. `EntityPolicy::subtreePathFor()` is also what the service uses to narrow lists. |
| Form Requests | `app/Http/Requests/Scoping/StoreEntityRequest.php`, `UpdateEntityRequest.php` (extends Store; `archived` allowed; `parent_id` may be neither the entity itself nor anything beneath it). Every FK is `Rule::exists(...)->where('organization_id', TenantContext::organizationId())` (entity_types, entities, users). Configured attributes via `ValidatesConfiguredAttributes`, keyed by the object type the CHOSEN entity type maps to (`ObjectTypeRegistry::legacyEntityTypeMap()`, fallback `BusinessUnit`) — `StoreEntityRequest::objectTypeCodeFor()`. |
| Service | `app/Services/Scoping/EntityService.php` — `dashboard()`, `tree()`, `detail()`, `present()`, `formOptions()`, `create()`, `update()`, `delete()` (returns the refusal message or null), `nextEntityCode()`. The dashboard's per-entity risk query (N+1) is one grouped query; figures unchanged (characterised). Every list is narrowed to a pinned user's subtree. |
| Controller | `ScopingController` constructor-injects `EntityService` + `FormSchemaPresenter`; each action authorises (`Gate::authorize` / Form Request) → service → `Inertia::render` or redirect with flash. Uses `PersistsConfiguredAttributes` after create/update. `index` unchanged from Phase 2 (plus `viewAny` authorisation). |
| Pages | `Pages/Scoping/{Dashboard,Create,Edit,Show}.jsx`, shared `Pages/Scoping/EntityForm.jsx` (with `toPayload`/`seedConfigured` helpers), `Components/EntityTree.jsx`. Dashboard/Show figures are plain props; the two charts use the house SVG `DonutChart` (no Chart.js). KPIs through `KpiCard`. Description is a `RichTextEditor` (rendered by `RichTextRenderer` on Show). Configured fields: `DynamicForm` section 5 on Create/Edit fed from `schemas[entity_type_id]` (one schema per active entity type, column-backed fields stripped — the bespoke form owns those inputs), `DynamicDetail` "Additional Fields" card on Show (`hideEmpty`). |
| Cross-renderer | `Ported::ROUTES` += `risk.scoping.dashboard/create/show/edit`. `Scoping/Index.jsx` action buttons are now `<Link>`s. Risks / issues / KRIs rows on Show are plain `<a href>` (still Blade). |
| Deleted | `resources/views/risk/scoping/{dashboard,create,edit,show,_tree-node}.blade.php` (directory gone). No Livewire component was scoping-only. |
| Model typing | Return types + generics on `Entity` and `EntityType` relations, `@property-read` for the `withCount` aggregates; return types on `Risk::category()`, `Risk::riskOwner()`, `Issue::responsibleOwner()` so the service's eager loads pass larastan. Behaviour unchanged. |
| Tests | `tests/Feature/Scoping/{ScopingTestCase,EntityPolicyTest,EntityPagesTest,EntityRequestsTest}.php` + the characterisation test above. |

## Deviations from the prompt, with reasons

- **"Exceeding Appetite" KPI is `null`, rendered as "Not computed".** The Blade dashboard hard-coded `$exceedingAppetite = 0` — a number nothing measured. `KpiCard` already has an `unavailable` state for exactly this; a zero that reads like a measurement is what `NoFabricatedNumbersTest` exists to stop.
- **`description` max length 5,000 → 20,000.** The field is now a `RichTextEditor` (the prompt's default for `description`) and Editor.js JSON is larger than the plain text it replaces.
- **Parent choice excludes descendants, not just the entity itself.** The old `update` only refused `parent_id == id`; choosing a sub-entity as parent would have made `refreshHierarchyPath()` recurse forever. Enforced in `UpdateEntityRequest` and mirrored in the edit page's `parentEntities` list.
- **Lists narrowed for pinned users.** The old dashboard and create/edit lookups showed the whole organisation to a node-scoped user while `NodeScopeEnforcementTest` narrows every other module's lists. The dashboard KPIs, tree, heatmap, type distribution, recent activity and the parent-entity lookup all use the policy's subtree path. Risk-derived figures use `Risk::visibleTo()`.
- **Unset category appetites are dropped** rather than stored as `{"credit": null, ...}`; `regulatory_frameworks` is re-indexed. Display is identical.
- **Delete confirmation uses `window.confirm`**, as the Phase 2 builder did — `ConfirmDialog` sits on the unverified Headless UI `<Transition>` (Phase 1 watch item); not touched here.
- **Configured-attribute schema is keyed by entity type id** (one `FormSchemaPresenter::form()` per distinct object type code, de-duplicated) because an entity's object type depends on the entity type chosen on the form. `Create`/`Edit` re-seed `configured_attributes` when the type changes; only the active schema's fields are posted (`toPayload`).
- **Other modules' models touched (additive only):** return types on `Risk::category()`, `Risk::riskOwner()`, `Issue::responsibleOwner()`. Risk / Issue / KRI *columns* are read through `data_get()` in `EntityService::detail()` rather than adding `@property` docblocks to models that Phases 3.2+ own.
- **Home breadcrumb dropped** (as on every ported page): `PageHeader` renders Inertia `<Link>`s and `/risk/dashboard` is still Blade.

## Test assertions adapted

- `tests/Feature/Characterisation/EntityRiskScoreTest.php` (new this phase): `viewData()` → `inertiaProps()`; expected figures unchanged.
- No pre-existing test asserted Blade text on these four routes. `Inertia/ScopingIndexTest`, `Grid/EntitiesGridTest`, `Widgets/HqSurfacesTest` (entity search URL) untouched and green.

## Not done / watch items

- `nextEntityCode()` keeps the old `ENT-%04d` from `max(id)+1` within the tenant; not moved to `ReferenceCodeService` (which has no entity prefix) — a code collision is theoretically possible under concurrent creates, exactly as before.
- Configured fields are validated for the object type of the chosen entity type only; switching type on Edit drops values typed for the previous type's fields (they were never valid for the new type).
- `npm run build` not run (per brief); JSX syntax-checked with esbuild.

## Verification

See the commit message / final report for the test run and file list.
