# 01 — Reference architecture: ThirdLine Internal Audit (`internalaudit/`)

Audited 2 Sep 2026 against `main` @ `0fced20` (`ci(tests): gate the deploy on the PHP suite as well as parity`). Read-only. Every claim below cites a path in `internalaudit/`; where the codebase and `DEVELOPMENT_STANDARD.md` disagree, the codebase is reported and the disagreement is flagged in §11.

This document is the *target* for the risk product. It is deliberately written as "what ThirdLine actually does", not "what the standard says", because the migration must reproduce working conventions, and several documented conventions are not yet practised in ThirdLine itself (see §11 — those are the ones the risk product can afford to do *better* on from day one).

---

## 1. Stack and versions

| Layer | Package | Constraint | Evidence |
|---|---|---|---|
| Runtime | PHP | `^8.2` | `composer.json` |
| Framework | `laravel/framework` | `^12.0` (**not** 11 — `CLAUDE.md` is stale) | `composer.json`, `bootstrap/app.php` (L11-style) |
| SPA bridge | `inertiajs/inertia-laravel` | `^2.0` | `composer.json`; `app/Http/Middleware/HandleInertiaRequests.php` |
| Frontend | `@inertiajs/react`, `react`, `react-dom` | `^2.0.0`, `^18.2.0` | `package.json` devDependencies |
| Build | `vite` + `laravel-vite-plugin` + `@vitejs/plugin-react` | `^7.0.7`, `^2.0.0`, `^4.2.0` | `vite.config.js` (single input `resources/js/app.jsx`; esbuild `pure` strips `console.*`, VAPT-060) |
| CSS | `tailwindcss` **v3** via PostCSS + `@tailwindcss/forms` | `^3.2.1` | `postcss.config.js`, `tailwind.config.js` (`@tailwindcss/vite ^4` is installed but unused — §11) |
| Headless UI | `@headlessui/react` | `^2.0.0` | `resources/js/Components/Modal.jsx`, `Dropdown.jsx` |
| Auth scaffolding | `laravel/breeze` | `^2.3` | `routes/auth.php` (registration removed, VAPT-018) |
| Tokens | `laravel/sanctum` | `^4.0` | installed; **no `HasApiTokens` usage** |
| RBAC | `spatie/laravel-permission` | `^7.2` | `database/seeders/DatabaseSeeder.php:22-131` |
| Named routes in JS | `tightenco/ziggy` | `^2.0` | `@routes` in `resources/views/app.blade.php:24`; `jsconfig.json` alias |
| Rich text | Editor.js suite (`@editorjs/editorjs ^2.31.6` + header/list/table/quote/code/inline-code/underline/delimiter) | | `resources/js/Components/RichTextEditor.jsx` |
| PDF | `spatie/browsershot ^5.4` (primary) → `barryvdh/laravel-dompdf ^3.0` (fallback) | | `app/Services/ReportEvidenceArchiver.php:129-155` |
| Spreadsheet | `phpoffice/phpspreadsheet ^5.5` | | import/export controllers |
| PDF text | `smalot/pdfparser ^2.12` | | `app/Services/References/Imports/PolicyDocumentExtractor.php` |
| JWT | `firebase/php-jwt ^7.0` | | `app/Services/Licensing/JwtValidator.php` |
| DB | MySQL (tests are MySQL-only because of `enum` ALTERs) | | `phpunit.xml` (`DB_CONNECTION=mysql`, `internalaudit_test`) |
| Queue / cache | database driver by default; `sync`/`array` in tests | | `.env.example`, `phpunit.xml` |
| Static analysis | `larastan/larastan ^3.9` level 5 | | `phpstan.neon`, empty `phpstan-baseline.neon` |
| Style | `laravel/pint ^1.29` | | present; not wired into CI (§9) |
| AI | local Ollama only (`granite4:micro`, `granite-embedding:30m`) | | `config/ollama.php`, `app/Services/OllamaService.php` |

Standing rules that the codebase actually obeys (`DEVELOPMENT_STANDARD.md §2`): no third-party React component library (charts are hand-rolled SVG — `Components/DonutChart.jsx`, `HBarChart.jsx`, `TrendChart.jsx`, `ProgressRing.jsx`); no client router; function components + hooks only.

---

## 2. Folder and layering conventions

### 2.1 Backend layout (counts as found)

```
app/
├── Console/Commands/      11 commands (reports:archive-issued, audit:check-overdue, license:heartbeat …)
├── Casts/                 3  (RichText, RichTextEncrypted, SafeEncrypted)
├── Exceptions/            5
├── Http/
│   ├── Controllers/       68 (60 domain + 8 Auth/ + base); 22,180 lines total
│   ├── Middleware/        7  (HandleInertiaRequests, EnsureLicenseValid, EnsureLicenseFeature,
│   │                          LicenseHeartbeat, EnforceRestrictedRoleScope, LogRequestActivity, SetSecurityHeaders)
│   └── Requests/          13 (only Investigations + Frameworks modules; the rest validate inline)
├── Jobs/                  3
├── Listeners/             1  (LogAuthActivity)
├── Mail/                  2
├── Models/                152 (+ Scopes/EngagementSubEntityScope)
├── Notifications/         1
├── Policies/              4  (AuditFindingPolicy, FrameworkPolicy, InvestigationCasePolicy, ReportPolicy)
├── Providers/             2  (AppServiceProvider, OllamaServiceProvider)
├── Services/              38 (root 14; Licensing/ 10; References/ 6; Regulatory/ 3; Directory/ 2; Icfr/ 1; RichText/ 2)
├── Support/               2  (ReportSectionNormalizer, RestrictedRoleScope)
└── Traits/                1  (LogsActivity)
```

No `app/Http/Resources/` — Inertia props are shaped inline in controllers.

### 2.2 Frontend layout

```
resources/js/
├── app.jsx                     createInertiaApp + resolvePageComponent('./Pages/${name}.jsx') + <LicenseNotice/> wrapper + service worker
├── bootstrap.js                window.axios + X-Requested-With
├── utils.js                    formatDate/DateTime/Currency/Number, classNames, daysUntil, truncate, formatReportSection
├── serviceWorkerRegistration.js
├── Components/                 41 primitives (PascalCase.jsx) — see §7
├── Layouts/                    AuthenticatedLayout.jsx (736 lines), GuestLayout.jsx, ExternalLayout.jsx
├── Pages/                      183 .jsx, one folder per module (see §2.4)
├── hooks/  + Hooks/            useOfflineSync.js (byte-identical duplicate — §11)
├── lib/                        richtext.js, navScope.js, investigationReport.js
├── utils/                      icons.jsx, offlineStorage.js
└── constants/                  8 module option lists (icfr.js, iso27001.js, …)
resources/css/app.css           design tokens + @layer components (235 lines)
resources/views/app.blade.php   the single Inertia root view
```

### 2.3 Feature-module convention (as practised)

A module `KeyRiskIndicators` is discoverable as: route group `Route::prefix('key-risk-indicators')->name('key-risk-indicators.')` in `routes/web.php:961`; controller `app/Http/Controllers/KeyRiskIndicatorController.php`; model `app/Models/KeyRiskIndicator.php`; pages `resources/js/Pages/KeyRiskIndicators/{Index,Create,Show}.jsx`. Ten modules use `Route::resource` (`audit-universe`, `audit-plans`, `audit-engagements`, `investigations`, `findings` ×2, `reports`, `time-entries`); the rest declare verbs explicitly.

### 2.4 Page folders (`resources/js/Pages/`)

`Analytics, Approvals, AuditCommittee, AuditEngagements, AuditPlans, AuditReports(+Management), AuditTemplates, AuditUniverse, AuditeeResponse, Auth, BranchAuditWorkProgram, ControlLibrary, Dashboard.jsx, EvidenceRepository, ExternalAuditee, Findings, FollowUp, HeadOfficeAuditWorkProgram, ICFR, Investigations, Iso20000, Iso20022, Iso22301, Iso27001, Iso45001, ItAuditWorkProgram, ItgcWorkProgram, KeyRiskIndicators, Messages, Ndpa, Notifications, OpinionRatings, Pcidss, Performance, Profile, RCSA, RegulatoryFramework, RegulatoryUpdates, RiskAppetite, RiskAssessments, RiskRegister, Settings(+ActivityLog,+Frameworks), SwiftCscf, TimeEntries, Welcome.jsx, WorkPrograms`.

The seven compliance modules (ISO 27001/22301/20000/45001, PCI DSS, NDPA, ISO 20022) are copies of one template: `Dashboard.jsx + Audits/{Index,Create,Show} + <Catalogue>/Index + Findings/Index + Reports/{Index,Show}`, each with nine tables, nine models and a ~600-line controller. This copy-per-module pattern is *not* one to import into the risk product (see `03-gap-analysis.md`).

### 2.5 The controller shape (the pattern to copy)

`app/Http/Controllers/KeyRiskIndicatorController.php` (178 lines) is the canonical thin controller:

```php
public function index(Request $request)
{
    $query = KeyRiskIndicator::with(['risk', 'riskCategory', 'entity', 'owner']);
    if ($request->filled('search')) { /* LIKE on name / kri_ref */ }
    if ($request->filled('current_status')) { $query->where('current_status', $request->current_status); }
    $indicators = $query->orderBy('kri_ref')->paginate(15)->withQueryString();
    return Inertia::render('KeyRiskIndicators/Index', [
        'indicators' => $indicators,
        'categories' => RiskCategory::where('is_active', true)->get(['id', 'name', 'code']),
        'filters'    => $request->only(['search', 'current_status', 'risk_category_id']),
        'stats'      => $stats,
    ]);
}
public function store(Request $request)
{
    $validated = $request->validate([ /* … */ ]);
    $kri = KeyRiskIndicator::create($validated);
    return redirect()->route('key-risk-indicators.show', $kri->id)->with('success', 'Key Risk Indicator created successfully.');
}
```

Conventions: eager-load; `paginate(15)->withQueryString()`; pass `filters` back; `Inertia::render('Folder/Page', props)`; flash via `->with('success'|'error')`. Where a module was built recently (Investigations, Frameworks — Aug 2026) the *documented* pattern is followed fully: Form Request (`app/Http/Requests/Frameworks/StoreFrameworkRequest.php` with `authorize()` calling `$this->user()->can('create', Framework::class)`), Policy (`app/Policies/FrameworkPolicy.php`), `Gate::authorize()` in the controller (56 call sites across 10 controllers), service for the logic (`app/Services/References/*`).

### 2.6 The page shape

`resources/js/Pages/KeyRiskIndicators/Index.jsx` (294 lines): default-exported function with destructured, defaulted props; `<AuthenticatedLayout header={<PageHeader …/>}>`; `<Head title=…/>`; `StatCard` grid; `.filter-bar`; `.card > table.data-table`; `<Pagination links meta/>`; filters change via `router.get(route('key-risk-indicators.index'), params, { preserveState: true, preserveScroll: true })`. `resources/js/Pages/AuditPlans/Create.jsx` (198 lines) shows the form shape: `useForm({...})`, `post(route('audit-plans.store'))`, `.form-label/.form-input/.form-select`, `<InputError message={errors.x}/>`, `max-w-4xl mx-auto`, `RichTextEditor` for prose fields.

---

## 3. Routing

- One file, `routes/web.php` (1,048 lines, 436 `Route::` calls). `GET /` redirects to login (L62). Everything authenticated sits in one group at L77: `Route::middleware(['auth', 'verified', 'ensure.license.valid', 'role.scope'])`.
- Inside, modules are `Route::prefix()->name()->middleware()` groups. Gates observed: the six-role audit-team gate `role:Super Admin|Chief Audit Executive|Audit Manager|Audit Supervisor|Senior Auditor|Auditor` (27×), leadership `role:Super Admin|Chief Audit Executive|Audit Manager` (11×), `role:Super Admin` (settings/licence), `permission:<name>` on a minority (auditee response, templates, `framework.*`, `export follow-up`), and `ensure.license.feature:<key>` on 16 prefixes (`analytics, performance, compliance, icfr, committee, evidence_repo, swift_cscf, iso_27001, iso_22301, iso_20000, pci_dss, iso_45001, ndpa, iso_20022, risk, ai_assistant`).
- Route-ordering traps are handled inline (`findings/create` before `findings/{finding}` L238-246; `whereNumber()` on numeric params).
- Public group L1040-1046: `throttle:30,1` + `external/auditee-response/{token}` (hashed tokens).
- **No `routes/api.php`.** JSON endpoints (`/api/frameworks/options`, `/ai/*`, `/notifications/unread-count`, `/offline-sync`) live in `web.php` under session + CSRF.
- `routes/auth.php`: Breeze minus registration; throttled password reset/verification.
- Middleware registration in `bootstrap/app.php:25-38`: web stack appends `SetSecurityHeaders, HandleInertiaRequests, AddLinkHeadersForPreloadedAssets, LicenseHeartbeat, LogRequestActivity`; aliases `role, permission, role_or_permission, ensure.license.valid, ensure.license.feature, role.scope`.

---

## 4. Auth, roles and permissions

- **Authentication**: Breeze session auth on the `web` guard; `User implements MustVerifyEmail` (`app/Models/User.php:19`); production password policy `min(12)->mixedCase()->numbers()->symbols()->uncompromised()` (`AppServiceProvider::boot()` L56-62); auth events logged by `app/Listeners/LogAuthActivity.php`. No MFA, no SSO, no SCIM.
- **Roles** (seeded in `database/seeders/DatabaseSeeder.php:63-131`): `Super Admin`, `Chief Audit Executive`, `Audit Manager`, `Senior Auditor`, `Auditor`, `Auditee`, `Observer`, `Internal Control Officer`. A ninth, `Audit Supervisor`, is used in 30+ route gates and `ReportPolicy::AUDIT_ROLES` but is **never seeded with permissions** (§11).
- **Permission naming**: predominantly `"<verb> <resource>"` (`view audit-plans`, `create findings`, `approve reports`, `manage settings`); two dotted families coexist: `approvals.approve_audit_plan|engagement|report` and `framework.view|manage|import|approve_suggestions`. ~100 permissions. They are defined in the seeder *and* re-granted in seven migrations, each commented "keep both in step" (§11).
- **Enforcement layers** (`DEVELOPMENT_STANDARD.md §8.4`, verified): (1) route `role:`/`permission:`/`ensure.license.feature:`; (2) `role.scope` ceiling (`app/Support/RestrictedRoleScope.php` — a route-name allowlist per restricted role, mirrored to the SPA as `auth.navScope` and applied by `lib/navScope.js`); (3) `Gate::authorize` / policy in 10 controllers, ad-hoc `hasPermissionTo` elsewhere (`AuditPlanController::approve` L237); (4) query scoping via `User::canSeeAllEngagements()` / `visibleEngagementIds()` (`config/audit.php enforce_team_scope`, VAPT-012); (5) UI reflection from shared props.
- **Policy shape**: `app/Policies/InvestigationCasePolicy.php` — permission check first, then role/ownership/confidentiality; `ReportPolicy::before()` gives Super Admin a local bypass (there is deliberately no global `Gate::before`, `FrameworkPolicy.php:11`).
- **User domain attributes** distinct from Spatie roles: `audit_role` enum, `reports_to` self-reference, `department_id`; helpers `isLeadership()`, `isOnEngagementTeam()`.

---

## 5. Multi-tenancy, sub-entities, organisation

**ThirdLine is single-tenant per installation.** The only organisation column is `frameworks.organization_id`, documented as "forward-compat only" (`app/Models/Framework.php:19-20,32`). "Organisation" means org-structure lookups: `parent_entities` (`Settings/Organization.jsx`, `SettingsController::organization` L431-470) and `departments`; "Branch / Head Office / IS" is an `audit_group` enum on engagements (`database/migrations/2026_03_06_100001_add_audit_group_to_audit_engagements.php`); engagement-scoped sub-entities are hidden by `app/Models/Scopes/EngagementSubEntityScope.php`. Client separation is intended to happen via **env + git branch** (`config/audit.php:27-28`: "Client branches override via env rather than code"), although no client-named branches exist today (`git branch -a` shows `main`, feature/fix/security branches and remote `mohali/poc-issue1-issue4`).

Consequence for the migration: the risk product's tenancy kernel (`risk copy/app/Support/Tenancy/*`) has no counterpart to converge onto; it must be *kept* and offered back to ThirdLine as shared code (`04-migration-strategy.md §6`).

---

## 6. Licensing integration

The one cross-cutting subsystem ThirdLine has that the risk product lacks.

- Services in `app/Services/Licensing/`: `LicenseManager` (orchestrator, 530 lines: `validate()`, `hasFeature()`, `getStatus()` with server-entitlement overlay, `clientNotice()` for the SPA, `activate()` which classifies short key vs JWT, `deactivate()`), `SyncManager` (HTTP client — `activate/validate/heartbeat/deactivate/isServerReachable` against `{LICENSE_SERVER_URL}/api/v1/licenses/*` with `X-Client-Id`/`X-Client-Secret`), `JwtValidator` (RS256, `iss = thirdline-grc-licensing`, public key `resources/keys/license_public.pem`), `EnforcementEngine` (feature = `claims.feat[key] === true`; modes `normal|grace|read_only|locked`), `LicenseLoader` (encrypted `storage/licensing/license.enc` + HMAC), `DeviceFingerprint`, `TamperDetector`, `GracePeriodManager`, `ExpiryNotifier`, `LicenseAuditLogger` → `license_audit_logs`.
- Middleware: `EnsureLicenseValid` (no-op unless `LICENSE_ENFORCE_VALID=true`, default **false**), `EnsureLicenseFeature` (blocks only when a *valid* licence lacks the feature), `LicenseHeartbeat` (`terminate()`-time heartbeat, applies `updated_entitlements`). Scheduler: `license:heartbeat` hourly (`routes/console.php`).
- Config: `config/licensing.php` — 18 feature keys (`audit, risk, compliance, swift_cscf, iso_27001, iso_22301, iso_20000, iso_45001, iso_20022, pci_dss, ndpa, icfr, ai_assistant, analytics, reporting, committee, evidence_repo, performance`), three plans (`starter/professional/enterprise`).
- UI: `Pages/Settings/License.jsx` (471 lines), `Components/LicenseNotice.jsx` mounted around every page from `app.jsx`; nav items carry `feature:` keys that `AuthenticatedLayout` filters on `license.features`.
- Contract: `LICENSING_API_CONTRACT.md` v1.0 — endpoints, error codes, JWT claims (`feat`, `mu`, `ma`, `plan`, `org{id,name,slug}`, `dvc`), tenancy invariant `license.org_id == api_client.org_id`.
- Tables: `license_stores`, `license_audit_logs` (`2026_03_25_*`).

---

## 7. Shared UI patterns

**Shared props** (`app/Http/Middleware/HandleInertiaRequests.php::share()`): `auth.{user, roles, permissions, navScope, landingRoute}`, `unreadNotifications` (count), `flash.{success,error,warning,info,import_errors}` (lazy closures), `license` (lazy `clientNotice()`, try/catch → null). Note it shares the whole `User` model (§11).

**Layout** (`resources/js/Layouts/AuthenticatedLayout.jsx`): a `navigation` array (L8-334) of `{ name, href: routeName, icon, allowedRoles?, permission?, feature?, children? }`; filtering composes `hasRoleAccess`, `hasPermissionAccess`, `featureEnabled` and `applyScope` (L521-585) and retargets a parent to its first visible child; sticky topbar with search, bell (30 s poll of `notifications.unread-count`), user dropdown; sidebar 260 px ↔ 72 px, gold active indicator.

**Components** (`resources/js/Components/`, 41): layout `PageHeader, Modal, ConfirmDialog, Dropdown, EmptyState`; data `DataTable (client-side sort), Pagination, FilterBar (syncs to URL via router), StatCard, StatusBadge, RatingBadge, TestResultBadge, TimelineBadge, DonutChart, HBarChart, TrendChart, ProgressRing, PeriodSelector`; forms `PrimaryButton, SecondaryButton, DangerButton, TextInput, InputLabel, InputError, Checkbox, RichTextEditor, RichTextRenderer`; app-state `FlashNotification, OfflineIndicator, LicenseNotice`; domain `AiAssistantPanel, AiReportGenerator, DuplicateFindingWarning, FrameworkImportWizard, GenerateExternalLinkModal, RegulatoryReferenceList, RegulatoryReferenceSuggester`.

**Design tokens** (`resources/css/app.css`): `--color-primary #1A365D`, `--color-primary-light/dark`, `--color-secondary #2D7D46`, `--color-accent #D4AF37`, `--color-bg #F7FAFC`, text/error/warning/info/success tokens, `--sidebar-width 260px`; `@layer components` classes `.card/.card-header/.card-body, .stat-card, .page-header/.page-title, .btn-primary|secondary|success|danger|warning, .badge + .badge-critical|high|medium|low, .badge-status-*, .data-table, .form-input|select|label, .filter-bar*, .progress-bar*`; fonts Inter + Roboto Mono (Google Fonts) — with Figtree also loaded from bunny.net in `app.blade.php:20-21` (§11).

**Dashboards**: `DashboardController` (171 lines) computes counts inline → `Pages/Dashboard.jsx`; per-module dashboards (`Analytics/Dashboard.jsx`, `FollowUp/Dashboard.jsx`, `Investigations/Dashboard.jsx` via `InvestigationDashboardService`, …). No configurable/widget dashboards.

**Reports / PDF**: Blade print views (`resources/views/reports/audit-report.blade.php`); `GET reports/{id}/download` returns HTML; PDF is produced only at issuance by `ReportEvidenceArchiver` (Browsershot → dompdf) and filed into the Evidence Repository. Excel via PhpSpreadsheet.

**Uploads**: `local`/`private` disks share `storage/app/private`; typical rule `file|max:51200|mimes:pdf,doc,docx,…`; downloads through `Storage::disk()->download()` behind route gates; an `evidence` disk is defined but unused.

**Notifications**: `app/Services/NotificationService.php::notify()` writes `audit_notifications` and mails `SystemNotificationMail`; domain helpers (`notifyFindingAssigned`, `notifyPlanApproved`, `escalateOverdueFindings`…); Laravel's native channel used only for `VerifyEmailQueued`. Separate "Messages" module (`AuditMessageController`).

**Editor.js**: `RichTextEditor.jsx` (header/list/table/quote/code/inline-code/underline/delimiter) used in 48 pages; JSON stored as strings in text/longtext columns with the `App\Casts\RichText` sanitising cast on 63 models (`RichTextEncrypted` on `AuditFinding`); server render via `app/Services/RichText/EditorJsHtmlRenderer.php`; client helpers `lib/richtext.js`.

**AI**: `OllamaService` + feature services (`AuditReportWriterService`, `AuditDuplicateDetectorService`, `AiRiskClassificationService`, `AuditFindingAnalysisService`, `ReferenceSuggestionService` hybrid FULLTEXT + LLM re-rank); `AiAssistantController` (17 routes under `/ai`, feature-gated `ai_assistant`); interactions logged to `ai_interactions`.

**Settings & frameworks library**: `Settings/{Roles, Organization, Workflows, General, ActivityLog, License, Frameworks}`; framework library tables `frameworks, framework_versions, framework_references (FULLTEXT search_text), framework_imports, framework_suggested_references, framework_reference_finding`; import wizard `FrameworkImportWizard.jsx` → `ProcessFrameworkImport` job → parsers → `ReferenceRowValidator`; `RegulatoryCatalog` federates clause sources across module tables.

**PHP/JS parity gate**: `tests/js/mirror-parity.mjs` (`npm run test:parity`) extracts two intentionally-duplicated rules from source at run time — `ReferenceRowValidator::validate()` (PHP) ⇄ `gradeRows()` in `FrameworkImportWizard.jsx`, and `FrameworkPolicy::delete()` ⇄ the guard in `Settings/Frameworks/Index.jsx` — feeds identical fixtures to both and fails CI on divergence. This is the pattern the risk product should adopt for the assessment-chain preview (`02-current-state-assessment.md §2.8`).

**Approval workflow**: `app/Services/ApprovalWorkflowService.php` (`initiateWorkflow/approve/reject/delegate/escalateOverdue`) with `approval_workflows/steps/requests/actions` tables; seeded for plans, reports, finding actions — but only findings actually enter it; plans and reports use bespoke controller state machines (`AuditReportController::submitForReview/managerApprove/…`).

**Offline**: `public/service-worker.js` + `resources/js/serviceWorkerRegistration.js` + IndexedDB queue (`utils/offlineStorage.js`, `hooks/useOfflineSync.js`) + `OfflineSyncController@sync` replay with per-action field allow-lists (VAPT-005).

**Activity log / security headers**: `App\Traits\LogsActivity` → `activity_logs`; `LogRequestActivity` middleware; `SetSecurityHeaders` (report-only CSP, HSTS).

---

## 8. Data model conventions

99 migrations creating 165 tables (+5 Spatie). Conventions actually followed (`DEVELOPMENT_STANDARD.md §9`, verified in `2026_02_25_18*_create_audit_engagements_table.php` and later): `$table->id()`; a human business key (`audit_id ENG-2025-001`, `FND-`, `AAP-`, `ENT-`, `WP-`) rendered in `font-mono`; `foreignId()->constrained()->nullOnDelete()|cascadeOnDelete()`; `enum('status', [...])->default('draft')` for lifecycles (with `phase` in parallel where needed); `json()` + `array` cast for lists; `timestamps()` always, `softDeletes()` on user-facing records. Seeder order: permissions → roles → reference data → users → demo data (`DatabaseSeeder.php`, 1,157 lines).

Module groups: framework/infra; organisation & universe; planning; engagement execution; findings & follow-up; reporting; approvals & notifications; auditee communication; evidence repository; risk management (`risk_categories, risks, controls, risk_control_mappings, control_effectiveness_assessments, key_risk_indicators, kri_measurements, risk_appetite_statements, rcsa_campaigns, rcsa_responses` — `2026_03_01_*_create_risk_based_audit_tables.php`); regulatory/compliance core; seven copy-template compliance modules; AI; investigations; framework library.

Note the overlap: ThirdLine already carries a *thin* risk register / KRI / appetite / RCSA (single controller each, no scoring engine, no workflow, no tenancy). The risk product's versions are the authoritative ones; see `04-migration-strategy.md §6` for how the two should converge.

---

## 9. Build, environment, testing, deployment

- **Composer scripts**: `setup`, `dev` (concurrently serve + queue + pail + vite), `test`. The `lint/lint:fix/analyze/check` scripts are inside a nested `"scripts"` key and therefore never run (§11).
- **npm**: `build`, `dev`, `test:parity`.
- **CI** — `.github/workflows/deploy.yml` (only workflow): on push to `main`; jobs `parity` (self-hosted runner `[self-hosted, thirdline]`, `node tests/js/mirror-parity.mjs`) and `tests` (MySQL from `secrets.CI_DB_*`, refuses any DB not named `*_ci|*_test`, `php artisan test`), then `deploy` (`needs: [parity, tests]`) runs `/usr/local/bin/thirdline-deploy main` — the deploy script lives on the server. No Pint, PHPStan or `npm run build` step in CI.
- **Deployment** — `DEPLOYMENT_GUIDE.md`: single Ubuntu VPS (`/var/www/thirdLine`, nginx + PHP-FPM + MySQL + Ollama), `composer install --no-dev`, `migrate --force`, `storage:link`; queue worker + scheduler as systemd units per `docs/security/ONPREM_HARDENING.md` ("without which audit-notification emails and licence heartbeats silently never run"); rollback = `git reset --hard <sha>`. The guide's sample workflow (SSH from `ubuntu-latest`) differs from the committed self-hosted workflow.
- **Branch-per-client**: intent only (`config/audit.php:27-28`); no client branches exist; two GitHub remotes (`atherislimited/internalaudit`, `Mohalidesigns/thirdLine`).
- **Tests**: 32 Feature + 10 Unit files (~249 methods) + 1 JS parity script; MySQL-only; `LICENSE_ENFORCE_VALID=false` in `phpunit.xml`, opted back on by `tests/Feature/Licensing/LicenseMiddlewareTest.php`. Notable: `AccessControlHardeningTest`, `ReportSeparationOfDutiesTest`, `SecurityHeadersTest`, `Licensing/*`, `RichText/*`.
- **Env**: `.env.example` lists app/db/session/mail/AWS plus `LICENSE_SERVER_URL, LICENSE_CLIENT_ID, LICENSE_CLIENT_SECRET, LICENSE_ENFORCE_VALID` and the `OLLAMA_*` family; ~15 further keys are read by config but missing from the example (`LICENSE_HEARTBEAT_HOURS`, `AUDIT_ENFORCE_TEAM_SCOPE`, `BROWSERSHOT_*`, …).

---

## 10. What to copy verbatim, what to copy in shape, what to leave

| Copy **verbatim** into the risk product | Copy the **shape**, re-fill with risk content | **Leave** in ThirdLine |
|---|---|---|
| `resources/css/app.css` tokens + component layer | `HandleInertiaRequests::share()` envelope (add `tenant`, `period`, `features`) | Seven copy-template compliance modules |
| `resources/js/Components/*` (41), `Layouts/*`, `utils.js`, `lib/richtext.js`, `lib/navScope.js` | `AuthenticatedLayout` `navigation` array | Engagement-team scoping (`visibleEngagementIds`) — the risk product has `GraphScope` |
| `app/Services/Licensing/*`, `LicenseHeartbeat`, `EnsureLicense*`, `config/licensing.php`, `LicenseController`, `Pages/Settings/License.jsx`, `LicenseNotice.jsx` | Thin controller / Form Request / Policy / service layering | Thin risk register, KRI, appetite, RCSA modules (superseded by the risk product's) |
| `App\Casts\RichText*`, `Services/RichText/*`, `RichTextEditor/Renderer` | Policy shape with permission-then-ownership | Offline service worker (until the PWA work package) |
| `SetSecurityHeaders`, `LogRequestActivity`, `Traits/LogsActivity` (risk has its own audit trail — reconcile) | `tests/js/mirror-parity.mjs` approach | `config/directory.php` hard-coded AD users |
| `vite.config.js`, `jsconfig.json`, `postcss.config.js`, `tailwind.config.js` (subject to the Tailwind decision) | CI: parity + tests gate deploy | `AuditProcedureController` (dead) |

---

## 11. Deviations, quirks and gotchas (things the risk migration must not inherit)

1. `CLAUDE.md` says Laravel 11; `composer.json` is `^12.0`. `DEVELOPMENT_STANDARD.md` is right.
2. Two Tailwind majors installed (`tailwindcss ^3.2.1` active via PostCSS; `@tailwindcss/vite ^4` unused). The risk product is on v4. This is **Decision 3** in `04-migration-strategy.md`.
3. `package.json.dependencies` is polluted with ~150 transitive packages; only `puppeteer` is a real runtime dependency.
4. Nested `composer.json` `scripts.scripts` block → `composer lint|analyze|check` never run; Pint/PHPStan absent from CI.
5. Three font families over two CDNs (Inter + Roboto Mono from Google Fonts in `app.css:1`; Figtree from bunny.net in `app.blade.php:20-21`); the report-only CSP allow-lists only bunny.net. The risk product self-hosts fonts for data-residency (`risk copy/resources/css/fonts.css`) — keep that.
6. `resources/js/Hooks/` and `resources/js/hooks/` are byte-identical duplicates; imports use lowercase.
7. Page folders mix `ICFR`/`RCSA` (caps) with PascalCase; `Components/frameworkBadge.js` is camelCase `.js`.
8. `Audit Supervisor` is gated on in 30+ routes but never seeded with permissions (`DatabaseSeeder.php` omits it; `ActiveDirectoryUsersSeeder.php:24` creates it bare).
9. Form Requests (13) and Policies (4) are the exception across 68 controllers, contrary to `DEVELOPMENT_STANDARD.md §4.3/4.4`; `AuditFindingController` is 1,017 lines.
10. Permissions are defined in the seeder and re-granted in seven migrations ("keep both in step").
11. `EnforcementEngine::hasFeature()` reads JWT claims, not the server overlay — a server-side feature flip shows in the UI before the route gate honours it.
12. `LICENSE_ENFORCE_VALID` defaults false, so an unlicensed install is gated only by the SPA overlay.
13. `HandleInertiaRequests` shares the full `User` model (`employee_id, phone, audit_role, department_id, reports_to, last_login_at`).
14. `local` and `private` disks share one root, so download code probes both.
15. Dead code: `AuditProcedureController` unrouted; `phpoffice/phppresentation` unused; `evidence` disk unused; `laravel/sanctum` installed without `HasApiTokens`.
16. Repository hygiene: ~20 binary/marketing artefacts and static HTML mock-ups at the root; `docs/~$irdLine-User-Guide.docx` lock file committed.
17. Legacy names survive (`auditpulse-cache-v3` service-worker cache, AuditPro docs, `@grcsuite.ng` seed emails).
18. Deployment guide and committed workflow disagree (SSH-from-GitHub vs self-hosted runner); guide mentions both php8.2-fpm and php8.4-fpm.
