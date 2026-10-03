# Development standard — Atheris ERM

A **delta** over ThirdLine's `DEVELOPMENT_STANDARD.md`, not a replacement. That
document's philosophy, stack, structure, theming, component library and
workflow all apply here unchanged. What follows is only what this product does
differently, and why — every entry is a decision the migration programme had to
make, with the evidence that made it.

Read it alongside `docs/migration/04-migration-strategy.md` and the phase notes
in `docs/migration/phase-*-notes/`, which carry the defects behind most of
these rules.

---

## 1. Presenters, not fat controllers

A controller resolves the request, authorises it, and hands a **Presenter** or a
**Service** the job of shaping what the page receives. `Inertia::render` takes
the result; it does not compute it.

- `app/Presenters/*` — shape data for a screen (`NavPresenter`,
  `FormSchemaPresenter`, `GridPresenter`, `WidgetPayloadPresenter`,
  `WorkflowPresenter`).
- `app/Services/*` — domain work with no HTTP in it.

**Why.** Phase 5.2 extracted five private helpers out of one controller into
`IcaapService` and PHPStan immediately named thirty-odd broken call sites the
characterisation tests had not touched, because those tests exercised one report
of four. Logic in a controller is logic only an HTTP test can reach.

## 2. Every route carries a permission, and the naming is `resource.verb`

`RouteAuthorizationTest` fails the build on a web or api route with neither a
`permission:` nor a `can:` middleware. The allowlist is limited to
unauthenticated auth-flow and health routes and is itself asserted.

Permissions are declared **once**, in `App\Authorization\RiskPermissionCatalog`,
with a description. The seeder and any grant migration read it through
`ThirdLine\Platform\Authorization\SeedsPermissions`.

**Why.** Before Phase 7.1d a permission was written in the seeder for fresh
installs and again in a hand-written migration for deployed ones, with nothing
checking they agreed. ThirdLine has the role-shaped version of the same bug: an
`Audit Supervisor` the application asks for and nothing creates. Spatie's answer
to an unknown role or permission is to grant nothing, silently.

The description is not documentation. `admin.metadata`, `admin.scoring` and
`admin.configuration` all read as "administration" to whoever assigns a role,
and they are three different authorities: reshaping every record in the tenant,
redefining what Critical means, and replacing the whole definition set.

## 3. Authorisation lives in a Policy named for its MODEL

Laravel discovers `App\Models\RiskAssessment`'s policy only as
`App\Policies\RiskAssessmentPolicy`. Under any other name every ability
silently returns false.

- A policy asks: permission → tenancy → domain rule, in that order.
- A Form Request's `authorize()` asserts **what actually guards the route**.
- Lifecycle rules ("only an in-review record can be approved") stay in the
  controller, which answers a wrong status with a flash message rather than a
  403.

**Why.** Phase 4.1 wrote a breach ability onto `KeyRiskIndicatorPolicy`;
`can('acknowledgeBreach', $breach)` looked for a `MeasureBreachPolicy`, found
none, and denied everyone. Phase 7.3 made the mirror mistake — six Form Requests
asked `can('update', $dashboard)` when no `DashboardPolicy` exists, and every
dashboard-builder endpoint returned 403.

The one hand-registered policy in the product is `RcsaPolicy`, bound via
`Gate::policy()` to a stateless subject class because RCSA has no model of its
own. Do not invent an empty model to host a policy.

## 4. No inline validation, and no bare `exists:`

Every controller takes a Form Request. `grep -rn "validate(\[" app/Http/Controllers`
returns nothing, and CI keeps it that way.

Every foreign key is a **tenant-bound** `Rule::exists(...)->where('organization_id', ...)`.
Never the string `exists:table,id`.

**Why.** A bare `exists` across a tenant boundary is at best an existence
oracle: Phase 7.3 found four AI endpoints where validation passed for another
organisation's risk id and failed for one existing nowhere, answering a question
the caller is not entitled to ask, one id at a time.

## 5. A figure on a screen is computed or it is absent

`NoFabricatedNumbersTest` fails the build on a non-zero numeric literal standing
in for a missing metric, an invented risk rating, a magic financial constant, or
an RNG in user-facing code. It covers `resources/js/**` and the PDF templates.

- `?? 0` is permitted — a count of zero is a true statement about an empty set.
- `?? 15.2` is not. Nothing produces 15.2 except a developer typing it.
- A **rate over nothing is undefined, not zero**. An empty register has no
  control effectiveness; say so.

**Why.** An audit found eleven fabricated user-facing figures, including a
capital adequacy ratio rendered as a green tile on the same screen as the
controller's own "No ICAAP assessment is on record". "0% CAR" was then found
four more times during the migration, each an independent copy.

## 6. Check the write path against the table, and the read path too

Before porting an action, check every `create()`/`update()` array against the
migration's column list and `$fillable`. Then check the view's property reads
against the model.

**Why.** Both halves have shipped repeatedly. Writes: `risk_control_mapping`,
`risks.risk_source`, `control_tests.result`. Reads: the RCSA controls table
rendered nine columns of which seven do not exist on `Control` — every one
behind `?? '-'`, so they printed a dash for every row on every tenant, forever,
and nothing failed.

## 7. Tenancy is a global scope, and `bypassTenancy()` is the only way out

Models carry `ThirdLine\Platform\Tenancy\BelongsToOrganization`.
`TenantContext` is resolved once per request, job or command. Every bypass is
logged with a reason.

`ResolveTenant` must run **after** `StartSession` and **before**
`SubstituteBindings`, or a route-model binding can resolve another tenant's
record before the tenant is known.

## 8. Primitives to reuse rather than re-invent

| Need | Use |
|---|---|
| Any list | `GridPresenter` + `GridQuery`/`GridState` + `@thirdline/ui` `DataGrid` |
| Create/edit/show of a configurable object | `FormSchemaPresenter` + `DynamicForm`/`DynamicDetail` + `ValidatesConfiguredAttributes` |
| A dashboard tile | `WidgetPayloadPresenter` |
| A long job | `hooks/useJobProgress` |
| A document | `ThirdLine\Reporting\DocumentRenderer` |
| A digest or comparison over a `json` column's value | `App\Support\CanonicalJson` (see section 15). It is stable on MySQL 8 only for values with no non-integral or out-of-range doubles. Quote floats before storing, as `Tprm\AuditLog::quoteFloats()` does. |

Charts are Chart.js — a deliberate divergence from ThirdLine, settled as
Decision 4 of the migration strategy.

## 9. Two-submit-button forms need a `useRef`, not `setData`

`setData('action', 'reject')` followed by `post()` in the same handler sends the
**previous** value: `setData` is asynchronous. `post(url, { data })` is no
rescue — Inertia assigns `data` after spreading `options`. Keep the intent in a
`useRef` and inject it with `transform()`, as `Rcsa/Worksheet.jsx` does.

**Why.** Phase 4.5's review panels would have **approved a submission when the
reviewer clicked Return for rework**. No test in this repository can catch this
class of bug: the server path is tested and is not what is wrong, and there is
no JS test runner.

## 10. What the test suite does not cover, and what to do about it

Be explicit about this rather than trusting a green run.

- **No JavaScript executes in any test.** A CSP that forbids the application's
  own bootstrap passed the entire suite while every page rendered blank
  (Phase 6.8, fixed in 7.4's predecessor). `npm run build` and a browser are the
  only checks on the front end.
- **No test renders CSS.** A purged Tailwind class is a silent visual
  regression.
- **A test that POSTs a route is not a test of the form in front of it.** Assert
  that what the schema OFFERS is a subset of what the validator ACCEPTS.
- **Pin a characterisation test against the RUNNING screen**, never against what
  the code appears to do.

## 11. Guards that are load-bearing

`RouteAuthorizationTest`, `TenancyIsolationTest`, `NoFabricatedNumbersTest`,
`AdminNavigationTest`, `PreflightRouteGuardTest`, `SecurityHeadersTest`,
`PermissionCatalogCoversRoutesTest`, `NoSelfUpdatingTimestampColumnsTest`,
`CanonicalJsonTest`, `AuditChainCrossEngineTest`, `AuditLogHashRecipeTest`,
`scripts/parity-check.php`.

**When one turns red, find out which of three kinds it is before touching it:**
bookkeeping that is now stale, a check coupled to the renderer rather than the
behaviour, or a genuine regression. Phase 5's criterion 7 turned three red and
they were one of each — one would have shipped a dashboard silently mixing
dated figures with current ones.

Do not weaken a guard to make it pass. Retire it only when the bug it guards is
**impossible** rather than merely absent, and replace it with an assertion of
the fact that made it impossible.

## 12. Deployment

One procedure: `.github/workflows/deploy.yml` → `scripts/deploy.sh`. See
`docs/DEPLOYMENT.md`. `php artisan app:preflight` must exit zero before serving.

## 13. A `timestamp()` column is `->nullable()` or `->useCurrent()`, never bare

An event time the application writes (`*_at` that is not `created_at`/`updated_at`)
is `dateTime()`. `timestampTz()` is not an alternative: on MySQL/MariaDB it
is the same type. `useCurrentOnUpdate()` needs an ADR — on **any** column,
not only a `TIMESTAMP` one; the same implicit `ON UPDATE CURRENT_TIMESTAMP`
clause is exactly as bad on a `DATETIME` column, and the allowlist is empty.
Guarded by `NoSelfUpdatingTimestampColumnsTest`, which scans every column of
every table, of any type. See ADR 0022.

## 14. A `date` cast is a day, not an instant

A column cast `'date'` comes back as midnight at the START of that day, so
`$model->end_date->isPast()` is true for the whole of the last day, and
`where('due_date', '<', now())` has MariaDB widen the DATE to that same
midnight. The register, the command centre and the heatmap decided "this
period is over" that way and showed every user the historic "as at" view on
the last day of every month; the suite was green on 29 September and CI was
red on the 30th. Ask the model — `Period::hasEnded()` — or compare days to
days: `DateBounds::endOfDay()`, `whereDate($col, '<', now()->toDateString())`.
Never `->isPast()`/`->isFuture()` on a date cast, and never a bare `now()`
against a DATE column. The same shape remains on some due-date readers,
where "due today" reads as overdue; they are booked, not exempt.

## 15. A digest over a `json` column hashes the decoded value, never the column's text

MySQL 8 stores `json` as a binary type and returns it re-serialised (keys
re-ordered, spacing and escaping changed). MariaDB returns the stored text
verbatim. Seal and verify through `App\Support\CanonicalJson`.

**Canonicalising is not enough for doubles.** MySQL 8.0.46's JSON parser does
not read every double back exactly. `9.018867924528301` comes back as
`9.0188679245283`, `1.0e25` as `9.999999999999999e24`, and integers above
`2^64` drift too. The server has already stored a different number before
anything reads it, so no canonicaliser can undo the drift. Unrounded ratios
mismatched about 1 time in 10 in the gate-2 measurement. A digest over a MySQL
`json` column is therefore stable only for values with **no non-integral or
out-of-range doubles**. A writer must quote floats (store each float's
`serialize_precision = -1` text as a JSON string) or quantise them **before**
storing. Do it at write time, never inside the digest: quoting at verify time
makes `72.5` and `"72.5"` hash alike and hides a type change. Do not rely on
"we round to 4 dp" either. Nothing enforces it, and an audit trait records raw
attributes, where a `decimal:N` cast has not run. Ints within int64 are exact
on both engines. Guarded by `CanonicalJsonTest`, `AuditChainCrossEngineTest`
and `AuditLogHashRecipeTest`. See ADR 0025 §1 and §2a.
