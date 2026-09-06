# Changelog

All notable changes to `thirdline/platform`.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-06

Initial extraction, migration Phase 7.1. Moved from the risk product with
`git mv`, so `git log --follow` reaches the original history.

### Added

- `Tenancy`: `TenantContext`, `OrganizationScope`, `BelongsToOrganization`,
  `ResolveTenant`, behind the opt-in `TenancyServiceProvider`.
- `TenancyConfig`, which replaces the one line that stopped the trait being
  shareable — a hardcoded `\App\Models\Organization` — with a configuration key
  that throws when unset rather than guessing.
- `PlatformServiceProvider`, publishing `config/platform.php`.
- `Licensing`: the ten service classes, five exceptions, two models, three
  middleware, its config and its migration, behind the opt-in
  `LicensingServiceProvider`. `LicensingConfig` replaces the one read that named
  the application — `App\Models\User::count()`, the seats figure sent to the
  licence server.
- `Http\Middleware\HandleInertiaRequests`, an abstract base whose `share()` is
  final: `auth.user` is id, name and email, never the model.
- `Http\Middleware\{CheckPermission,SetSecurityHeaders}`.
- `Authorization\PermissionCatalog`, `SeedsPermissions` and
  `GrantPermissionsMigration` — one declaration of a product's permissions,
  their descriptions and who holds them, read by both the seeder and any grant
  migration so the two cannot drift.
