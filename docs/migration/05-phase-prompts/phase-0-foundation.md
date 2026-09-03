# Phase 0 — Foundation: Inertia + React alongside Blade, layout, shared props, licensing, CI

> Claude Code implementation prompt. Run from the `risk copy/` repository root on a fresh branch `migration/phase-0-foundation`. Read `docs/migration/01-reference-architecture.md`, `03-gap-analysis.md` and `04-migration-strategy.md §4` first. The sibling repository `../thridLine/internalaudit/` is the pattern source — copy from it, do not re-invent.

## Context

This repository is a Laravel 12 app rendered with Blade + Livewire 3. We are migrating it to the ThirdLine architecture (Inertia 2 + React 18, thin controllers, Form Requests, Policies, ThirdLine design system, ThirdLine licensing client) as a **strangler**: Inertia and Livewire coexist until Phase 6. This phase installs the new presentation stack, ports **one** page as proof, and adds the guards that keep the rest of the programme honest. No existing screen changes behaviour.

Decisions already taken (confirm in `04-migration-strategy.md §8` before starting): Tailwind major = ___ ; permission naming stays `resource.verb`; Chart.js allowed.

## Scope

### 0.1 Dependencies and build
- `composer require inertiajs/inertia-laravel:^2.0 tightenco/ziggy:^2.0` ; `composer require --dev larastan/larastan:^3.9`.
- `npm i -D @inertiajs/react@^2 react@^18.2 react-dom@^18.2 @vitejs/plugin-react@^4 @headlessui/react@^2 @tailwindcss/forms`. Keep `chart.js`, `gridstack`. Remove the unused `alpinejs` direct dependency (Alpine still ships inside Livewire until Phase 6).
- `vite.config.js`: add `react()`; inputs become `['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.jsx']` (both entries live until Phase 6). Copy the `esbuild.pure` console-stripping from `internalaudit/vite.config.js`.
- `jsconfig.json` from `internalaudit/jsconfig.json` (`@/* → resources/js/*`, `ziggy-js`).
- Tailwind per Decision 3. If v4: keep `@tailwindcss/vite`, convert ThirdLine's `@layer components` block to v4 syntax while copying. If v3: add `postcss.config.js` + `tailwind.config.js` from ThirdLine and drop `@tailwindcss/vite`.

### 0.2 Inertia root, entry and layout
- New `resources/views/app.blade.php` modelled on `internalaudit/resources/views/app.blade.php` (`@routes`, `@viteReactRefresh`, `@vite(['resources/css/app.css', 'resources/js/app.jsx'])`, `@inertiaHead`, `@inertia`). **Do not** load any font CDN — keep `resources/css/fonts.css` self-hosted (data residency; see `resources/views/layouts/app.blade.php:9-14` comment).
- `resources/js/app.jsx` copied from `internalaudit/resources/js/app.jsx`: `createInertiaApp`, `resolvePageComponent('./Pages/${name}.jsx', import.meta.glob('./Pages/**/*.jsx'))`, `<LicenseNotice/>` wrapper, `<FlashNotification/>`. Omit `registerServiceWorker()` (offline is out of scope).
- Copy verbatim: `internalaudit/resources/js/Components/*` (all 41; drop `AiAssistantPanel`, `AiReportGenerator`, `DuplicateFindingWarning`, `FrameworkImportWizard`, `GenerateExternalLinkModal`, `RegulatoryReferenceList`, `RegulatoryReferenceSuggester`, `TestResultBadge`, `TimelineBadge` — audit-specific), `Layouts/{AuthenticatedLayout,GuestLayout}.jsx`, `utils.js`, `lib/richtext.js`, `lib/navScope.js`, `hooks/` (lowercase only — the `Hooks/` duplicate is a ThirdLine defect).
- `resources/css/app.css`: merge ThirdLine's tokens + component layer (`internalaudit/resources/css/app.css`) with this repo's existing `app.css`, `widgets.css` (keep every `--viz-*` token — `resources/js/widgets/theme.js` reads them) and `fonts.css`. ThirdLine tokens win on conflict; document each conflict in the PR.
- `AuthenticatedLayout.jsx`: replace ThirdLine's `navigation[]` with this product's sidebar, transcribed from `resources/views/layouts/partials/sidebar.blade.php` L40-343 (`$navSections`) and L457-575 (Administration). **Every** item gets `permission: '<the permission: middleware of its route>'` — read them from `routes/web.php`. Sections get `permission` = any-of their children (the layout's `filterNav` already retargets parents). Add topbar slots for the tenant name and the period selector (ported in Phase 1; render placeholders now).

### 0.3 Shared props
- `app/Http/Middleware/HandleInertiaRequests.php` (shape from `internalaudit/app/Http/Middleware/HandleInertiaRequests.php`) sharing:
  - `auth.user` = `['id','name','email']` only (**not** the model — ThirdLine gotcha §11.13), `auth.roles`, `auth.permissions`.
  - `tenant` = `['id','name','code']` from `TenantContext::organizationIdOrNull()` (never call `organizationId()` here — it throws on public pages).
  - `period` = current `PeriodContext::get()` summary or null.
  - `features` = `config('features')` booleans.
  - `license` = lazy `LicenseManager::clientNotice()` in try/catch → null.
  - `flash.{success,error,warning,info}` lazy closures.
  - `unreadNotifications` lazy — reuse the two queries in `AppServiceProvider::boot()` L93-110 and **remove that View composer** in Phase 6 (leave it now; Blade pages still need it).
- Register in `bootstrap/app.php` web group **after** `ResolveTenant`/`ResolvePeriod` (they must run first so `tenant`/`period` are bound). Keep the `SubstituteBindings` re-ordering intact.

### 0.4 Licensing client (import from ThirdLine)
- Copy `internalaudit/app/Services/Licensing/*` (10 classes), `app/Http/Middleware/{EnsureLicenseValid,EnsureLicenseFeature,LicenseHeartbeat}.php`, `app/Http/Controllers/LicenseController.php`, `app/Models/{LicenseStore,LicenseAuditLog}.php`, `config/licensing.php`, the two `2026_03_25_*` migrations (re-stamp as `2026_09_xx`), `app/Console/Commands/LicenseHeartbeat*.php`, `resources/js/Pages/Settings/License.jsx`, `resources/keys/license_public.pem` (**never** the private key). Add `license:heartbeat` hourly to `routes/console.php`.
- `config/licensing.php available_features`: keep ThirdLine's keys, add `quantification`, `loss_events`, `regulatory`, `integrations`, `workflows`. Plans: `professional` = `risk, analytics, reporting, loss_events, workflows`; `enterprise` = all.
- Routes: `GET/POST settings/license*` under `admin` prefix, `permission:license.manage`; seed `license.view`, `license.manage` in `database/seeders/RolesAndPermissionsSeeder.php` and a grant migration (`super-admin`), mirroring `2026_08_16_120002_grant_dashboard_permissions_to_roles.php`.
- Middleware aliases `ensure.license.valid`, `ensure.license.feature`; `LicenseHeartbeat` appended to web group. **Do not** add `ensure.license.valid` to any route group yet; `LICENSE_ENFORCE_VALID` stays false. Add the four `LICENSE_*` keys to `.env.example`.
- Add the two `LicenseStore`/`LicenseAuditLog` models to `app/Support/MorphTypes.php` (enforced morph map — an unmapped model is fatal, see Wave 2 notes).

### 0.5 Security headers
- Copy `internalaudit/app/Http/Middleware/SetSecurityHeaders.php`; because this product serves no CDN assets, set CSP to **enforced** (`script-src 'self'`, `style-src 'self' 'unsafe-inline'`, `img-src 'self' data:`, `font-src 'self'`). Livewire needs `'unsafe-eval'` until Phase 6 — add it with a `// TODO(phase-6)` comment and a test that fails if it survives after `livewire/livewire` is gone.

### 0.6 First ported page
- Port `GET /my` (`Risk/MyResponsibilitiesController@index` → `resources/views/my/index.blade.php`) to `Pages/My/Index.jsx` using `PageHeader`, `StatCard`, `.card`, `StatusBadge`. Controller returns `Inertia::render('My/Index', [...])` with the same data `MyResponsibilitiesService` already produces. Delete the Blade view in the same commit.

### 0.7 Quality gates
- `phpstan.neon` (level 5, Larastan, paths `app config database routes`), generate a baseline, commit it.
- `.github/workflows/ci.yml`: add `npm ci && npm run build`, `vendor/bin/phpstan analyse`, `php artisan schema:audit-deprecated`, and a MySQL matrix job in addition to SQLite. `.github/workflows/deploy.yml`: `needs: ci` (use `workflow_run` or merge into one workflow).
- `.gitignore`: `plans/`, `erm-update/`, `files_/`, `_to_delete/`, `risk` (SQLite), `*.tgz`, `*.zip`. Do not delete the directories in this PR.
- Update `composer.json` `name` from `laravel/laravel` to `thirdline/risk`.

## Files to create / modify (summary)
`composer.json`, `package.json`, `vite.config.js`, `jsconfig.json`, `resources/views/app.blade.php`, `resources/js/app.jsx`, `resources/js/{Components,Layouts,hooks,lib}/**`, `resources/js/utils.js`, `resources/css/app.css`, `app/Http/Middleware/{HandleInertiaRequests,SetSecurityHeaders,EnsureLicenseValid,EnsureLicenseFeature,LicenseHeartbeat}.php`, `app/Services/Licensing/**`, `app/Http/Controllers/LicenseController.php`, `app/Models/{LicenseStore,LicenseAuditLog}.php`, `app/Support/MorphTypes.php`, `config/licensing.php`, `database/migrations/2026_09_*_{create_license_tables,grant_license_permissions_to_roles}.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `routes/web.php` (license routes + `/my`), `routes/console.php`, `bootstrap/app.php`, `resources/js/Pages/{My/Index,Settings/License}.jsx`, `phpstan.neon`, `phpstan-baseline.neon`, `.github/workflows/*.yml`, `.gitignore`, `.env.example`.

## Acceptance criteria
1. `php artisan test` — all ~950 existing tests pass; `RouteAuthorizationTest`, `AdminNavigationTest`, `PreflightRouteGuardTest`, `TenancyIsolationTest`, `NoFabricatedNumbersTest` unchanged and green.
2. `npm run build` succeeds; both `app.js` and `app.jsx` bundles present in `public/build/manifest.json`.
3. `/my` renders through Inertia inside `AuthenticatedLayout`; every other route still renders Blade under the old layout; `wire:navigate` still works on Blade pages.
4. New tests:
   - `tests/Feature/Inertia/InertiaSharedPropsTest.php` — `auth.user` has exactly `id,name,email`; `tenant.id` equals the acting user's org; a user from org B never sees org A's `tenant`/`unreadNotifications`; `license` is null when unlicensed and does not throw.
   - `tests/Feature/Inertia/NavigationPermissionGateTest.php` — parses `navigation[]` (export it from `resources/js/nav.js` so PHP can read it as JSON, or emit it from a `NavPresenter` PHP class — prefer the PHP class so there is one source) and asserts for every item that `permission` equals the `permission:` middleware of the named route (`Route::getRoutes()->getByName()`), and that every route in the 22 risk sections appears exactly once. This generalises `AdminNavigationTest` to the whole sidebar.
   - `tests/Feature/Inertia/MyPageTest.php` — `assertInertia(fn ($p) => $p->component('My/Index')->has('tasks')->…)`.
   - `tests/Feature/Licensing/*` copied from ThirdLine and passing (SQLite + MySQL).
   - `tests/Feature/SecurityHeadersTest.php` (from ThirdLine) adapted to the enforced CSP.
5. `vendor/bin/phpstan analyse` green against the committed baseline.
6. `php artisan app:preflight` green.
7. PR description lists every `app.css` token conflict and its resolution, and confirms no file under `resources/views/risk/**` or `app/Livewire/**` changed.
