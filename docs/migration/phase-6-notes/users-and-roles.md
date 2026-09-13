# Phase 6.1 — Users and roles

`Admin/UserManagementController`, 201 lines, three Blade views. The index had
been on the shared grid since Phase 2; create, edit and show flip here.

Three defects, and the first of them is the most serious thing this programme
has found.

## `admin.users` was a path to super-admin

The create and update rules ended:

```php
'roles' => 'required|array|min:1',
```

No rule on the elements. The controller then did `$user->syncRoles($validated['roles'])`.

`super-admin` is a seeded role, and `AppServiceProvider` line 110 says:

```php
Gate::before(fn (?User $user, string $ability) => $user?->hasRole('super-admin') ? true : null);
```

Every ability, answered true, before any policy runs. So an account holding
nothing but the `admin.users` permission could post `roles[]=super-admin` — to
a colleague, or to **itself** through the edit form, which was not guarded for
self-action the way `destroy` and `toggleActive` were — and hold the whole
platform one request later. Not a privilege escalation across a boundary: a
straight walk through the front door of the screen that exists to manage
permissions.

Three things now stand between the request and `syncRoles()`:

- `roles.*` is `Rule::in($this->assignableRoles())` — the roles that exist, less
  `super-admin` unless the actor holds it.
- `UserPolicy::grantSuperAdmin()` is where that "unless" is decided, so the
  console, a future API and the form all get the same answer.
- `UpdateUserRequest::withValidator()` refuses any change to your own roles,
  which closes the self-promotion route even for a role that is otherwise
  assignable.

The form offers what the validator accepts — the rule from 4.6 — because
`formOptions()` and `assignableRoles()` compute the list the same way. A test
pins both halves; if they ever drift, the offering side fails first.

**This also removes the last hard-coded role list in the product.** The form
now renders `Role::all()`.

## The other two

`business_unit_id` was `exists:business_units,id` — the bare form this
programme has been replacing since Phase 3, no tenant filter, so an account
could be created into another institution's business unit. Now
`Rule::exists(...)->where('organization_id', $orgId)`.

`staff_id` was `unique:users` — globally, across every institution on the
installation. Two banks could not both employ staff number 001. There is no
unique index behind it either, so it was validation-only and inconsistently
enforced. Now unique within the organisation. `email` stays globally unique
deliberately: it is the identity SSO and SCIM provision against.

## What was already sound

`User` uses `BelongsToOrganization`, so route-model binding never resolves
another institution's account: these routes 404 rather than 403, and
`another_institutions_account_is_not_reachable` pins that. `UserPolicy` checks
the tenant anyway — the belt to that brace, the same reasoning
`ReportController::assertSameTenant()` carries — so a caller reaching a `User`
by some other route still cannot act on it.

## Parity, not improvement

The profile screen renders what the Blade rendered, including the four fields
the first draft of the port dropped (MFA status, password-changed date, lock
expiry, organisation name) and the **effective** permission grouping. That last
one is `getAllPermissions()`, not `roles.permissions`: a user may hold a
permission directly as well as through a role, and the Blade showed the union.
Grouping stays server-side because the `action module` shape of a permission
name is the server's business.

Every destructive control on the profile is rendered from a policy answer the
server sent — `canDeactivate` is false on your own account — and the policy
gives the same answer if the request arrives regardless.
