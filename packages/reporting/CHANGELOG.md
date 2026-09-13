# Changelog

All notable changes to `thirdline/reporting`.

## [0.1.0] - 2026-09-06

Initial extraction, migration Phase 7.1c. `DocumentRenderer` moved with
`git mv`, so `git log --follow` reaches the original history.

### Added

- `DocumentRenderer` — PDF, XLSX and CSV output.
- `Contracts\ResolvesDocumentBranding`, so the renderer can be told whose
  document it is without knowing what an organisation is.
- `ReportingServiceProvider`.

### Changed

- `branding()` no longer builds branding from an `Organization`. It delegates
  to the bound resolver and returns an empty array when none is bound — the
  five product-specific columns it used to read, one of them a Nigerian banking
  regulator code, moved to the consuming application.
