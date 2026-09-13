# Changelog

All notable changes to `@thirdline/ui`.

## [0.1.0] - 2026-09-06

Initial extraction, migration Phase 7.1e. 67 files moved with `git mv`.

### Added

- `Components/**` — primitives, charts, the data grid, the widget engine, the
  metadata-driven form and detail renderers.
- `Layouts/{AuthenticatedLayout,GuestLayout}`.
- `hooks/{useJobProgress,useReportStatus}`, `lib/**`, `widgets/**`, `utils.js`.
- `src/index.js`, a barrel of named exports alongside the existing default
  exports, so both the barrel and subpath import forms work.

### Changed

- `AuthenticatedLayout` takes `search` and `periodSelector` as slots instead of
  importing them. Both hardcoded product route names, which a shared shell
  cannot: Ziggy throws on a name the consuming product has never registered.
