# The 403 a super-admin got on every RCSA v2 screen

Reported during P2 as "the CRO profile doesn't see everything in RCSA — 403
Unauthorized action."

## What was actually broken

Not the CRO. `chief-risk-officer` held all six `rcsa_universe.*` permissions
throughout and was verified reaching every screen. **`super-admin` was the role
being refused**, and the account the report came from holds it.

`super-admin` held 121 of the catalog's 127 permissions — short by exactly the
six `rcsa_universe.*` added in P1.

## Why

The product says "a super-admin can do everything" in three places:

1. `AppServiceProvider` — `Gate::before(fn ($user) => $user->hasRole('super-admin') ? true : null)`.
2. `RiskPermissionCatalog` — `'super-admin' => ['*']`, resolved to the whole catalog.
3. `HandleInertiaRequests::permissionsFor()` — hands super-admin **every**
   permission in the page props, so the sidebar renders every link. Its docblock
   says why it does not read the grant table for that role: "a role can be
   created by hand".

And one place said otherwise: the `permission:` middleware. In this application
that alias is **not Spatie's** — it is
`ThirdLine\Platform\Http\Middleware\CheckPermission`, and it asked
`hasPermissionTo()`, which reads the grant table and never sees the gate.

So the sidebar drew a link the route then refused, with `abort(403,
'Unauthorized action.')`.

The grant table falls behind on its own, by design of the seeding strategy: the
catalog is applied by `RolesAndPermissionsSeeder`, a seeder never re-runs on a
deployed tenant, and every `grant_*_permissions_to_existing_roles` migration
therefore lists the roles it affects. **Each of those migrations has left
super-admin out**, on the assumption that the gate covered it — including
P1's `2026_09_06_100006`, where the omission was even commented as deliberate:
*"super-admin holds '*' in the catalog; nothing to grant."* That reasoning is
wrong for this application.

So this was not new in RCSA v2. RCSA v2 is simply the first module since the
seeding whose permissions a super-admin actually needed, so it is where the
gap became visible. TPRM, or any later phase, would have hit it identically.

## The fix, in two halves

Either half alone leaves a hole, so both landed together.

**The mechanism.** `CheckPermission` now lets a super-admin through without
consulting the grant table, matching `Gate::before` and matching what
`HandleInertiaRequests` in the same package already does. This is what stops a
future module reopening it.

**The data.** `2026_09_06_100007_resync_super_admin_to_the_permission_catalog`
grants super-admin everything the catalog defines. Idempotent, forward-looking
(it syncs whatever the catalog holds when it runs, so it repairs any gap, not
only this one), and it never revokes — a permission granted by hand and not in
the catalog is left alone, and `down()` is empty because rolling back must not
be able to lock an administrator out.

It matters beyond the middleware: `getAllPermissions()`, the role-administration
screen and anything else reading the grant table would otherwise show "121 of
127" for the role that is meant to hold all of them.

## What keeps it shut

`tests/Feature/Authorization/SuperAdminReachesEveryScreenTest.php`:

| Test | Holds |
|---|---|
| `the_permission_middleware_lets_a_super_admin_through_a_permission_they_have_not_been_granted` | the mechanism — the exact state a deployed tenant is in the day a module adds a permission |
| `a_user_without_the_permission_is_still_refused` | that the bypass is the role, not a hole |
| `super_admin_holds_every_permission_the_catalog_defines` | the data, so the admin screen agrees with the catalog |
| `every_route_guard_names_a_permission_the_catalog_defines` | that no route is guarded by a permission nobody creates — `CheckPermission` fails closed on an unknown one, which would make a screen unreachable for everybody with nothing failing at boot |

## For the next phase

Grant migrations from here on do not need to list super-admin, because the
middleware no longer depends on it — but they should still run the resync if
they add permissions, so the admin screen stays truthful. The simplest habit:
add the permissions to `RiskPermissionCatalog` and let
`super_admin_holds_every_permission_the_catalog_defines` tell you whether the
grant table needs a nudge.

## Not a bug, for the record

The reporting account also redirected (302) rather than loading. That is the
30-minute inactivity logout in `EnsureAuthenticated` — its `last_activity_at`
was three hours old. Logging in again clears it.
