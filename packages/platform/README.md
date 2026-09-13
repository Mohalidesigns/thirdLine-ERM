# thirdline/platform

Platform primitives shared by the ThirdLine GRC products.

Extracted from the risk product in migration Phase 7.1. Nothing here is
specific to risk, audit or compliance: these are the mechanisms those products
were each about to implement a second time.

## Install

```json
{
    "repositories": [{ "type": "path", "url": "packages/platform" }],
    "require": { "thirdline/platform": "@dev" }
}
```

`PlatformServiceProvider` is auto-discovered.

## Tenancy — opt in, deliberately

`ThirdLine\Platform\Tenancy` is **not** loaded by the auto-discovered provider.
A single-tenant application must be able to depend on this package without
acquiring a global scope that filters every query it makes, so registering it
is the application's decision:

```php
// bootstrap/providers.php
ThirdLine\Platform\Tenancy\TenancyServiceProvider::class,
```

```php
// config/platform.php
'tenancy' => ['organization_model' => App\Models\Organization::class],
```

There is no default for `organization_model`. Defaulting to
`App\Models\Organization` — which is what both current applications happen to
call it — would let the package appear to work in an application that had not
configured it, until the first `->organization` returned a relation to a class
that does not exist. It throws instead, naming the key.

What you get:

| Class | Role |
|---|---|
| `TenantContext` | one resolved tenant per request / job / command |
| `OrganizationScope` | the global scope filtering reads to it |
| `BelongsToOrganization` | trait: the scope, a `creating` stamp, and `bypassTenancy()` |
| `ResolveTenant` | middleware resolving the tenant from the signed-in user |

`bypassTenancy()` is the single audited escape hatch for system work. Every
call is logged by `TenantContext`.

Middleware ordering matters: `ResolveTenant` must run **after** `StartSession`
(so the user is known) and **before** `SubstituteBindings` (so a route-model
binding cannot resolve another tenant's record).

## Testing

`composer test` runs the package's own unit tests. They cover the primitives in
isolation; the behavioural coverage that matters — 141 assertions that no query
crosses a tenant boundary over HTTP — lives in the consuming application's
`tests/Feature/TenancyIsolationTest.php`, because that is where the boundary
actually exists.
