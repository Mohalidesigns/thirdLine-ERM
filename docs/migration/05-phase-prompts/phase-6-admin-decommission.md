# Phase 6 — Administration screens, workflow designer, and Livewire/Blade decommission

> Claude Code implementation prompt. Branch `migration/phase-6-admin-decommission`, after Phases 4 and 5. Ends with `livewire/livewire` removed from `composer.json`. Nothing under `resources/views/` survives except `app.blade.php`, `reports/pdf/**`, `emails/**`, `vendor/pagination/**` (delete the last if unused after the grid flip).

## Modules, in order

### 6.1 Users and roles — `Admin/UserManagementController` (201), views `admin/users/*` (4)
`UserPolicy` (admin.users). Pages `Admin/Users/{Index (grid AdminUsersGrid),Create,Edit,Show}`; role assignment as checkbox list from `Role::all()` (removes the last hard-coded role list). Keep `AdminNavigationTest` semantics: Administration entries gate on their route permissions.

### 6.2 Organisation and SSO settings — `Admin/OrganizationSettingsController` (154), `Admin/SsoSettingsController` (212), views `admin/settings/*` (`index` 415 lines)
Pages `Admin/Settings/{General,Sso}.jsx` (ThirdLine `Settings/General.jsx` shape). `OrganizationSettingsRequest` validates the `organizations.settings` JSON (risk overrides per `config/risk.php`, `mfa_required_roles`, reporting currency). SSO settings form per provider with `SsoSettingsRequest`; SAML metadata download link unchanged.

### 6.3 Metadata builders — `app/Livewire/Admin/{ObjectTypeBuilder,AttributeBuilder,RelationshipTypeBuilder,LifecycleBuilder}.php`, `Admin/ConfigurationBuilderController` (58), views `admin/builder/*` (6), `livewire/admin/*`
- Each builder becomes a page + JSON endpoints: `Admin/Builder/ObjectTypes/{Index,Edit}.jsx`, `Attributes/Edit.jsx` (per object type; drag-order list), `RelationshipTypes/{Index,Edit}.jsx`, `Lifecycles/Edit.jsx` (state/transition editor — a table form, not a canvas). Backend: `app/Services/Metadata/*` already hold the rules (`MetadataGuard`); add `app/Http/Requests/Admin/Metadata/*` and `ObjectTypePolicy`/`ObjectAttributePolicy`/`ObjectRelationshipTypePolicy`/`ObjectLifecyclePolicy` (admin.metadata). Deleting a relationship type still archives instances (`RelationshipTypeBuilder` rule) — move that rule into `ObjectRelationshipTypeService::delete()` if it is only in the Livewire class.
- `tests/Feature/Metadata/*` and `tests/Feature/Configuration/*` green.

### 6.4 Scoring profiles — `app/Livewire/Admin/ScoringProfileBuilder.php` (436)
`Admin/ScoringProfiles/{Index,Edit}.jsx`: matrix size, axis labels, bands (colour from `--viz-*` tokens), residual formula (validated server-side by `FormulaEvaluator`; expose `POST admin/scoring-profiles/validate-formula`). `ScoringProfilePolicy` (admin.scoring). `ScoringProfileProvisioner` unchanged.

### 6.5 Workflow designer — `app/Livewire/Admin/WorkflowDesigner.php` (419), `livewire/admin/{workflow-designer,partials/workflow-inspector}`, `risk/workflows/designer.blade.php`
`Workflows/Designer.jsx`: node/edge canvas. Justified dependency: a React flow-diagram library is *not* in ThirdLine; prefer an SVG canvas built on the existing `Network.jsx` from Phase 2 with drag handles, before reaching for a library. Definition JSON contract is `WorkflowGraph` (`app/Services/Workflow/WorkflowGraph.php`) — the designer posts the whole `definition` to `WorkflowController@update` and publishes via the existing `publish` route (`WorkflowPublisher` + `WorkflowDefinitionValidator` run server-side; surface validator errors in an inspector panel). `WorkflowDefinitionPolicy` (workflow.view/manage).

### 6.6 Configuration bundles — `Admin/ConfigurationBundleController` (183), view `admin/configuration/index`
`Admin/ConfigBundles/{Index,Diff}.jsx`; export/import/rollback post to existing routes; diff rendered from `ConfigurationDiffer` JSON. `ConfigBundlePolicy` (admin.configuration).

### 6.7 Integrations — `Admin/{WebhookController (225), ApiTokenController (172), ConnectorController (173), JobRunController (79)}`, views `admin/{webhooks,api-tokens,connectors,jobs}/*`
Policies `WebhookSubscriptionPolicy`, `ApiTokenPolicy`, `ConnectorPolicy`, `JobRunPolicy`. Pages `Admin/{Webhooks/{Index,Deliveries},ApiTokens/Index,Connectors/{Index,Show},Jobs/Index}`; token creation shows the plaintext once (ThirdLine `Modal`); connector run → `useJobProgress`.

### 6.8 Decommission
- Delete: `app/Livewire/**`, `resources/views/livewire/**`, `resources/views/components/**`, `resources/views/layouts/**` (including `app.blade.php` head shims and `partials/sidebar.blade.php`), `resources/views/admin/**`, `resources/views/hq/**`, `resources/views/widgets/**`, `resources/js/app.js`, `resources/js/widgets/{charts,index,builder}.js` (theme.js stays), `app/View/Components/**`, `config/livewire.php`.
- `composer remove livewire/livewire`; `npm uninstall alpinejs gridstack`?? — **no**: GridStack is still used by `DashboardBuilder.jsx`; only remove `alpinejs`.
- `AppServiceProvider`: remove `livewire.inject_assets` (L41), the Livewire update-route override (L88-90), the View composer (L93-110); `EventServiceProvider` unchanged.
- `vite.config.js` input → `['resources/css/app.css', 'resources/js/app.jsx']`.
- `SetSecurityHeaders`: drop `'unsafe-eval'` (the Phase 0 TODO); the guard test written then must now pass.
- `config/features.php`: nothing changes; `NoFabricatedNumbersTest` scope now covers `resources/js/**` instead of Blade — update its file globs.

## Acceptance criteria
1. `composer show livewire/livewire` exits non-zero; `grep -rn "wire:\|@livewire\|<livewire\|x-data\|@php" resources/views` returns matches only under `reports/pdf/**` and `emails/**`.
2. `AdminNavigationTest`, `NavigationPermissionGateTest`, `RouteAuthorizationTest`, `PreflightRouteGuardTest`, `tests/Feature/{Metadata,Configuration,Integrations,Api,Workflow}/*`, `ScimProvisioningTest`, `MachineTokenLifetimeTest`, `McpServerTest` green.
3. Every controller in `app/Http/Controllers/Admin/` has a Policy and Form Requests; `grep -rn "validate(\[" app/Http/Controllers` returns nothing repo-wide (Phase 7 backfills any stragglers — list them in the PR).
4. `tests/Feature/Workflow/DesignerRoundTripTest.php`: a definition saved from the React designer equals the JSON `WorkflowLibrary` ships for `risk_assessment_approval` after normalisation; `WorkflowDefinitionValidator` errors surface as 422 with field paths.
5. CSP enforced without `'unsafe-eval'`; `SecurityHeadersTest` asserts it.
6. `npm run build` output: single `app.jsx` entry; report bundle size (gzip) in the PR against the Phase 0 number.
7. `php artisan app:preflight` and `schema:audit-deprecated` green.
