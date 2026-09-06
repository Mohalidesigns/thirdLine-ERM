# Phase 7.2 — dry run: ThirdLine adopting the packages

**Nothing was pushed and nothing was committed in the ThirdLine repository.**
The work sits as uncommitted changes on a local branch,
`feat/thirdline-platform-packages`, in
`../thridLine/internalaudit` (remote `Mohalidesigns/thirdLine.git`, tip still
`0fced20`).

Both suites are green at the end of it:

| | Before | After |
|---|---|---|
| ThirdLine | 352 passed | 352 passed |
| Risk product | 1988 passed / 4 skipped | unchanged |

## What was actually adopted

- `thirdline/platform` via a path repository at `../../riskerm/packages/platform`
- The whole licensing cluster: **20 files deleted** from ThirdLine — 10 services,
  5 exceptions, 2 models, 3 middleware — and 11 files repointed at the package
- `HandleInertiaRequests` extends the packaged base

Not attempted, and it cannot be: `app/Casts/**`, `app/Services/RichText/**`,
`app/Traits/LogsActivity.php` and `app/Support/RestrictedRoleScope.php`. The
phase prompt says to replace them "with the package classes", and **the package
does not contain them** — they exist only in ThirdLine, so they have to be
extracted INTO the package first, which is 7.1 work that could not be done from
the risk repository alone. Their blast radius there is large: `App\Casts` alone
is referenced by 66 files.

## Four defects in my package, all invisible until a second consumer tried it

Fixed in `b9693aa`. Every one of them would have been found on day one of real
adoption and none of them by any test in the risk product.

**A hardcoded consumer route name — twice.** `EnsureLicenseValid` redirected to
`route('admin.license')`, `EnsureLicenseFeature` to `route('risk.dashboard')`.
ThirdLine calls those screens `settings.license` and `dashboard`, so every
unlicensed request threw `RouteNotFoundException`. Six failures immediately.

This is the exact rule 7.1e wrote for `@thirdline/ui` — *a component that
hardcodes a route name cannot be shared, because Ziggy throws on a name the
other product never registered* — and I had broken it in the PHP package while
writing it for the JavaScript one. `redirect()->route()` is the same hazard as
`route()` in JSX.

**A version pin copied from one consumer.** The package required
`spatie/laravel-permission ^6.0` because that is what this repo has. ThirdLine
is on `^7.2`; composer refused to resolve at all. A shared package's constraint
should describe what its code needs, not what its author's application happens
to have installed.

**A migration that assumed a virgin database.** The package ships
`create_license_tables`. ThirdLine created the same two tables in March 2026,
months before the package existed, with live rows in them. Opting in would have
failed that install on *table license_stores already exists*, with nothing the
operator could do short of editing a vendor file. Guarded with `hasTable()` now
— something an application migration never needs and a package migration always
does.

**A base class with no room in it.** The packaged `HandleInertiaRequests` owns
the whole `auth` bag, and ThirdLine adds `navScope` and `landingRoute` to it.
Without a hook, the only way in was to override `auth` outright and re-specify
`user` — the one shape the class exists to keep safe. There is an
`additionalAuthProps()` hook now, and it **strips `user`** from what a subclass
returns: a hook that can be used to defeat the rule it is attached to is not a
hook, it is a loophole.

## What ThirdLine stops leaking

`auth.user` was `$user` — the whole Eloquent model, serialised by Inertia into
the HTML of every page. `$hidden` kept out `password` and `remember_token`.
Everything else shipped: `employee_id`, `job_title`, `audit_role`,
`department_id`, `reports_to`, `phone`, `avatar`, `is_active`, `last_login_at`
— readable in view-source and in any cached or proxied copy, and growing with
the table, since any column a later migration adds would have joined them.

It is id, name and email now.

**One thing broke, and no test could have told me.** The sidebar renders
`{user.audit_role || user.email}` and would have silently fallen back to showing
the user's email address under their name. Found by grepping `resources/js` for
reads of `auth.user`, because ThirdLine's suite executes no JavaScript either.
`audit_role` is back as `auth.audit_role` through the hook — a display label
about the user, for the user — and the layout reads it from there. `auth.user`
stays exactly three fields in every product.

The profile form also reads `auth.user`, but only `name` and `email`, so it was
unaffected. `Settings/Roles.jsx` reads the same field names off its own page
prop, not the auth bag.

## The `Audit Supervisor` role: real, but not quite as billed

The phase prompt says the permission-catalog test "passes only after
`Audit Supervisor` is seeded". What is actually there:

- `ReportPolicy::AUDIT_ROLES` names `Audit Supervisor` as a role with access to
  every report.
- The canonical role seeding creates eight roles and **`Audit Supervisor` is not
  among them**.
- `ActiveDirectoryUsersSeeder` — a demo/AD-import seeder — does
  `Role::firstOrCreate` for it as a side effect of provisioning users.

So whether the role exists depends on which seeders an install ran. On one that
skipped the AD seeder, `ReportPolicy` names a role nobody can hold and the
entry is silently dead. Fail-closed, so not a hole — but a capability that
looks granted and is not, which is precisely the drift `PermissionCatalog`
exists to make impossible. Porting the catalog into ThirdLine would surface it
as a declaration error rather than as nothing at all.

## What is left before this can be a real PR

1. **Extract the four ThirdLine-only class groups into the package** (Casts,
   RichText, LogsActivity, RestrictedRoleScope) — 7.1 work, ~87 referencing
   files in ThirdLine.
2. **`@thirdline/ui` adoption** — not started. ThirdLine declares **144
   dependencies + 23 dev**; the prompt calls for removing ~150 of them, which
   needs its own verification pass and its `node tests/js/mirror-parity.mjs`.
3. **Fonts and CSP** — switch to the package's self-hosted `fonts.css`, drop the
   Google Fonts and bunny.net links, enforce the policy. Note the risk product's
   own lesson here: enforcing without a nonce for Ziggy's inline `@routes` makes
   every page render blank while the whole suite stays green.
4. **The path repository is temporary scaffolding.** It points at an absolute
   local path, so the branch only builds for someone with both repositories side
   by side. It must become a published package or a git repository before this
   merges.
5. **The permission catalog** for ThirdLine, which is where the `Audit
   Supervisor` finding gets a test.
