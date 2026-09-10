# Phase 7 — Shared packages, hardening backfill, parity sign-off

> Claude Code implementation prompt. Branch `migration/phase-7-shared-packages`, after Phase 6. This phase touches **both** repositories for the first time: `risk copy/` (source of the packages) and `../thridLine/internalaudit/` (first consumer). Do the extraction as *moves*, not rewrites — `git log --follow` must still work.

## 7.1 Package layout (monorepo-style inside `risk copy/`, path repositories)

```
packages/
├── ui/                       @thirdline/ui (npm workspace)
│   ├── package.json          peerDeps: react ^18, @inertiajs/react ^2, @headlessui/react ^2, chart.js ^4, gridstack ^13
│   ├── css/app.css           tokens + component layer (single Tailwind major per Decision 3)
│   ├── css/fonts.css         self-hosted Inter / Roboto Mono
│   └── src/{Components,Layouts,hooks,lib,widgets,utils.js,index.js}
├── platform/                 thirdline/platform (composer, PSR-4 ThirdLine\Platform\)
│   ├── src/Http/Middleware/{HandleInertiaRequests (abstract share()), SetSecurityHeaders, LogRequestActivity, CheckPermission, EnforceRestrictedRoleScope}
│   ├── src/Licensing/**  + config/licensing.php + migrations
│   ├── src/RichText/{RichText,RichTextEncrypted,SafeEncrypted casts; EditorJsSanitizer; EditorJsHtmlRenderer}
│   ├── src/Activity/{LogsActivity trait, ActivityLogger}
│   ├── src/Authorization/{PermissionCatalog, SeedsPermissions}   ← one definition drives seeder + grant migrations
│   ├── src/Tenancy/{TenantContext, OrganizationScope, BelongsToOrganization, ResolveTenant}   ← opt-in provider
│   ├── src/Support/RestrictedRoleScope.php
│   └── src/PlatformServiceProvider.php (+ TenancyServiceProvider, LicensingServiceProvider)
└── reporting/                thirdline/reporting (composer, PSR-4 ThirdLine\Reporting\)
    └── src/{DocumentRenderer, PdfLayout (blade), BoardPackContracts, Drivers/{Dompdf,Browsershot}}
```

- Root `composer.json`: `"repositories": [{"type":"path","url":"packages/platform"},{"type":"path","url":"packages/reporting"}]`, require `thirdline/platform:@dev`, `thirdline/reporting:@dev`. Root `package.json`: `"workspaces": ["packages/ui"]`, dependency `@thirdline/ui: workspace:*`.
- Update every import in `resources/js/**` from `@/Components/X` to `@thirdline/ui` where the component moved; app-specific pages stay in `resources/js/Pages`.
- `PermissionCatalog`: a PHP array of `permission => description` per module; `SeedsPermissions` trait gives `RolesAndPermissionsSeeder` and a generic `GrantPermissionsMigration` base class one source, and a test that every `permission:` string in `routes/web.php` exists in the catalog (this is `RouteAuthorizationTest`'s missing half, and would have caught ThirdLine's unseeded `Audit Supervisor`).

## 7.2 ThirdLine consumes (`../thridLine/internalaudit/`, branch `feat/thirdline-platform-packages`)

- `composer.json`: path repository pointing at `../../grcsuite/risk copy/packages/platform` for development (switch to the private registry when published); require `thirdline/platform`, `thirdline/reporting`. Replace `app/Services/Licensing/**`, `app/Http/Middleware/{SetSecurityHeaders,LogRequestActivity,LicenseHeartbeat,EnsureLicense*}`, `app/Casts/*`, `app/Services/RichText/*`, `app/Traits/LogsActivity.php`, `app/Support/RestrictedRoleScope.php` with the package classes (delete the local copies; add class aliases only if a migration references an old FQCN).
- `package.json`: `@thirdline/ui` via `file:` link; move `resources/js/{Components,Layouts,hooks,lib}` imports; delete the duplicated `Hooks/` directory; delete the ~150 polluted `dependencies` (keep `puppeteer`); remove `@tailwindcss/vite` or `tailwindcss@3` per Decision 3.
- `HandleInertiaRequests` extends the package base; its `share()` keeps `navScope`/`landingRoute` and **stops sharing the full `User`**.
- Fonts: switch `app.blade.php` to the package's self-hosted `fonts.css`; drop the Google Fonts `@import` and bunny.net link; enforce CSP.
- Do **not** enable the tenancy provider in ThirdLine in this phase.
- Run `php artisan test` + `node tests/js/mirror-parity.mjs`; both must be green.

## 7.3 Hardening backfill (`risk copy/`)

- Any controller still lacking a Policy or Form Request (list from Phase 6 PR) gets them now.
- PHPStan: reduce `phpstan-baseline.neon` to zero entries for `app/Http/**` and `app/Policies/**`; raise level to 6 if green.
- `.env.example`: add every key read by `config/*.php` (`02 §1.5` list: `FEATURE_MFA_TOTP`, `MEASURE_*`, `CBN_RATE_*`, `WEBHOOKS_*`, `WORKFLOW_*`, `CONNECTORS_DRY_RUN_FIRST`, `SSO_*_URL`, `LICENSE_*`).
- Delete `build-deploy.sh` (cPanel path) or `scripts/deploy.sh` — keep one deployment procedure; document it in `docs/DEPLOYMENT.md` in ThirdLine's `DEPLOYMENT_GUIDE.md` format.
- Repository hygiene: remove `risk` (SQLite), `plans/`, `erm-update/`, `files_/`, `_to_delete/`, `Atheris_ERM_Demo_Guide_IT_Risk.docx`, `Competitive_Analysis_Risk_ERM.xlsx.py`, `.~lock.*` from the tree (they were git-ignored in Phase 0; now `git rm --cached` and move planning docs to `docs/history/` if they must be kept).

## 7.4 Parity sign-off

- `docs/migration/parity-checklist.md` (seeded in Phase 0 from `php artisan route:list --json`): every row complete — route · old view (deleted) · page · permission · Form Request · Policy · grid · widgets · exports · tests · flipped-on · signed-off-by.
- Script `scripts/parity-check.php`: for every named route in `routes/web.php` that returns a page, assert (a) the controller method contains `Inertia::render` or a redirect, (b) the referenced page file exists, (c) a test references the route name. Run in CI.
- Write `docs/DEVELOPMENT_STANDARD.md` for this product as a delta over ThirdLine's (Presenters, grid/widget/dynamic-form primitives, tenancy, per-route permissions, `resource.verb` naming, Chart.js allowance) and open a PR against ThirdLine's `DEVELOPMENT_STANDARD.md` §12 adding the shared packages to the scaffolding playbook.

## Acceptance criteria
1. Both repositories green: risk `php artisan test` (SQLite + MySQL) + `npm run build` + PHPStan; ThirdLine `php artisan test` + `npm run test:parity` + `npm run build`.
2. `git diff --stat` in ThirdLine shows only deletions in the replaced directories plus `composer.json`, `package.json`, `HandleInertiaRequests.php`, `app.blade.php`, `app.css` edits.
3. `tests/Feature/Authorization/PermissionCatalogCoversRoutesTest.php` green in the risk product; the same test added to ThirdLine passes only after `Audit Supervisor` is seeded — include that seeder fix in the ThirdLine PR.
4. `packages/*` each have `README.md`, `CHANGELOG.md`, semver `0.1.0`, and a `composer test` / `npm test` script.
5. `scripts/parity-check.php` green; `grep -rl "return view(" app/Http/Controllers` returns only PDF/mail renderers.
6. `docs/migration/parity-checklist.md` has no empty cells; PR links the checklist as the completion evidence.
