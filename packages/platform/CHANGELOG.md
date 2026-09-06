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
