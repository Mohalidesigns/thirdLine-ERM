# Phase 0 — Foundation: implementation notes

Branch `migration/phase-0-foundation`, on top of `072f4cb` (the dashboard
publishing work that was uncommitted when the programme started). Prompt:
`05-phase-prompts/phase-0-foundation.md`. Decisions applied: Tailwind **v4**
(this product's pipeline; ThirdLine's component layer converted while
copying), permission naming stays `resource.verb`, Chart.js allowed.

## What landed

| Area | Delivered |
|---|---|
| Build | `inertiajs/inertia-laravel ^2`, `tightenco/ziggy ^2`, `firebase/php-jwt ^7.1`, `larastan/larastan ^3.9`; `@inertiajs/react`, `react` 18, `@vitejs/plugin-react`, `@headlessui/react`, `@tailwindcss/forms`, Editor.js packages; `alpinejs` removed from `package.json` (Livewire still ships its own). `vite.config.js` has both entries (`app.js` for Blade/Livewire, `app.jsx` for Inertia) and ThirdLine's `esbuild.pure`. `jsconfig.json` copied. |
| Inertia root | `resources/views/app.blade.php` (`@routes`, `@viteReactRefresh`, `@vite`, `@inertiaHead`, `@inertia`); no CDN. `resources/js/app.jsx` with `LicenseNotice` beside every page. |
| ThirdLine UI | 30 components copied verbatim (audit-specific and offline ones dropped — see below), `Layouts/GuestLayout.jsx`, `utils.js`, `lib/{richtext,navScope}.js`, `Pages/Settings/License.jsx`. |
| Layout | `Layouts/AuthenticatedLayout.jsx`: ThirdLine's shell, sidebar rendered from the `navigation` shared prop, Material Symbols icons, tenant chip, read-only period chip (selector ported in Phase 1), bell, user menu. |
| Nav as data | `app/Presenters/NavPresenter.php`: 4 primary entries, 22 sections (78 routes), Administration (11 entries in 4 groups). Every entry declares the route's `permission:`; filtered per user server-side. |
| Shared props | `app/Http/Middleware/HandleInertiaRequests.php`: `auth.{user(id,name,email),roles,permissions}`, `tenant`, `period`, `features`, `navigation`, `license` (lazy, guarded), `flash.*`, `unreadNotifications`. Registered after `ResolveTenant`/`ResolvePeriod`. |
| Security headers | `SetSecurityHeaders` with an **enforced** CSP (`script-src 'self'`, no external origin). `'unsafe-inline'`/`'unsafe-eval'` stay in `script-src` only while `livewire/livewire` is installed; `SecurityHeadersTest` flips when it is removed (Phase 6 TODO). Vite's dev origin is allowed only when the hot file exists. |
| Licensing | ThirdLine's client imported verbatim: 10 services, 5 exceptions, 3 middleware, `LicenseController`, 2 models, `config/licensing.php` (+ `quantification`, `loss_events`, `regulatory`, `integrations`, `workflows` features; plans updated), migration re-stamped `2026_09_03_100000`, `license:heartbeat` hourly, public key only. Routes `admin/settings/license*` under `permission:license.manage` with a `license-activate` limiter. `license.view`/`license.manage` seeded + grant migration (super-admin). Morph map entries added. `LICENSE_ENFORCE_VALID=false` by default and not applied to any route group. |
| First page | `GET /my` → `Inertia::render('My/Index')`; `resources/views/my/index.blade.php` deleted. |
| Quality gates | `phpstan.neon` (level 5) + `phpstan-baseline.neon` (822 entries); CI: `npm run build` with a manifest check for both entries, PHPStan, `schema:audit-deprecated`, SQLite + MySQL matrix; deploy gated on CI via `workflow_run`. `.gitignore` covers the scratch directories. `composer.json` name → `thirdline/risk`. |
| Docs | `docs/migration/parity-checklist.md` seeded from `route:list` (188 named GET routes). |

## `app.css` token and class conflicts, and how each was resolved

ThirdLine wins on conflict, per the prompt.

| Token / class | This product | ThirdLine | Resolution |
|---|---|---|---|
| `--color-primary` | `#1a365d` (`@theme`) | `#1A365D` (`:root`) | Same value. Kept in `@theme` so `bg-primary` utilities still compile. |
| `--color-secondary` | `#2d7d46` | `#2D7D46` | Same value, `@theme`. |
| `--color-accent` | `#d4af37` | `#D4AF37` | Same value, `@theme`. |
| `--font-sans` | Inter (`@theme`) | Inter (body rule) | Kept in `@theme`; ThirdLine's `body` rule kept too. |
| `--font-mono` | — | Roboto Mono (`.font-mono`) | Added as a `@theme` token so `font-mono` compiles; Roboto Mono is **not** self-hosted yet, so it falls back to the system monospace stack. Add the woff2 under `public/fonts` when a screen needs it. |
| `body` background | `#f7fafc` | `var(--color-bg)` = `#F7FAFC` | Same value; ThirdLine's rule kept. |
| `.badge` | `padding: 2px 10px` | `@apply px-2.5 py-0.5 …` | ThirdLine's; identical padding. |
| `.data-table th/td` | 10px 12px padding, 2px header rule | `px-4 py-3`, `bg-gray-50` header | ThirdLine's. Blade tables using `.data-table` gain 2 px of cell padding and a lighter header. |
| scrollbar | 6px thumb `#cbd5e0` | same + hover | ThirdLine's (adds hover). |
| `.risk-*`, `.kpi-card`, `.tab-*`, `.trend-*`, print rules, `[data-chart-wrap]`, `.material-symbols-outlined`, `[x-cloak]` | product only | — | Kept. |
| `--viz-*` (widgets.css) | product only | — | Kept; `widgets.css` is imported again. |
| `@tailwindcss/forms` | — | base strategy (all inputs) | Imported with `strategy: class` so the ~140 unported Blade forms are not restyled; React inputs opt in with `form-input`. |

## Deviations from the prompt, with reasons

- **Components dropped beyond the listed nine:** `OfflineIndicator.jsx`, `hooks/useOfflineSync.js`, `utils/offlineStorage.js`, `utils/icons.jsx`, `Components/frameworkBadge.js`. Offline is out of scope (`04 §8`), and the last two are only imported by dropped components.
- **`AdminNavigationTest`** reads the Blade sidebar from `/notifications` (granting `notification.view`) instead of `/my`, which is now an Inertia page with no Blade sidebar in its HTML. Its `ADMIN_SURFACES` map gains `license.manage => admin.license`, which its completeness rule requires. The Blade sidebar gains the matching "Platform → License" entry.
- **`HqSurfacesTest`**: the two `/my` cases assert the Inertia props instead of Blade text.
- **`license` shared prop** is `null` when there is no licence file *and* enforcement is off (the shipping state), not ThirdLine's "blocked" notice — otherwise every unlicensed development install would meet the full-screen block.
- **`config/licensing.php`** reads `enforce_valid` through `filter_var`: PHPUnit's `<env value="false">` arrives as an empty string.
- **`TestCase`** calls `withoutVite()` so the suite does not depend on `npm run build`.
- **CSP** carries `'unsafe-inline'` as well as `'unsafe-eval'` in `script-src` until Phase 6: 47 Blade views carry inline `<script>` blocks and Ziggy's `@routes` needs a nonce that the Blade shell cannot yet supply. Both are guarded by the same test.
- **Transcription corrections in `NavPresenter`:** the Blade sidebar's sections were not permission-gated (users saw entries their permissions would 403 on), and its Risk Intelligence section was appended to `$sections`, a variable the template never reads. The presenter gates every entry and includes the section behind `features.ai_intelligence`.

## Confirmed unchanged

No file under `resources/views/risk/**` or `app/Livewire/**` changed. `RouteAuthorizationTest`, `PreflightRouteGuardTest`, `TenancyIsolationTest`, `NoFabricatedNumbersTest` are untouched.

## Verification (2026-09-03)

- `php artisan test`: 1230 passed, 3 skipped, 0 failed (the two `AssetResidencyTest` cases that assert the real Vite tags opt out of the base class's `withoutVite()` stub).
- `vendor/bin/phpstan analyse`: no errors against the committed baseline.
- `npm run build`: both entries in the manifest; gzip 100 kB (`app.js`) + 136 kB (`app.jsx`).
- `php artisan app:preflight --allow-local`: passed.
- Browser: `/my` and `/admin/settings/license` render through Inertia in the new layout; `/risk/dashboard` still renders Blade under the old layout.
- Not addressed: repo-wide `pint --test` was already red before this branch on `app/Grids/*`, `app/Livewire/DataGrid.php`, `tests/Feature/Grid/*` and `NoFabricatedNumbersTest`; those files are out of Phase 0's scope.
