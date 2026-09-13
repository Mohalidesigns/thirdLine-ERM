# @thirdline/ui

The shared React interface layer for the ThirdLine GRC products: primitives,
the application shell, the data grid, the widget engine and the
metadata-driven form.

Extracted from the risk product in migration Phase 7.1e — 67 files, ~6,800
lines, moved with `git mv`.

## Install

Root `package.json`:

```json
{
    "workspaces": ["packages/ui"],
    "dependencies": { "@thirdline/ui": "*" }
}
```

npm workspaces, not the `workspace:*` protocol — that is pnpm and yarn syntax
and npm resolves it as a literal version.

## Importing

Both forms work. The barrel is the convenience:

```js
import { PrimaryButton, DataGrid } from '@thirdline/ui';
```

and the subpath keeps a page from pulling the whole interface layer into its
chunk:

```js
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
```

The risk product uses the subpath form throughout, because it was a mechanical
rewrite of `@/Components/X` and it preserves the existing code splitting — the
built bundle is chunk-for-chunk identical to before the extraction.

## What stayed behind, and the rule

**A component that hardcodes one of the product's route names is not shared.**
Ziggy throws on a name it has never heard of, so `route('risk.periods.select')`
inside a shared package is a runtime error in every other product.

By that rule these stayed in the risk application: `PeriodSelector`,
`SearchBox`, `EntityTree`, `LicenseNotice`, `Reporting/ExportMenu`,
`Quantification/*` and `useAssessmentPreview`.

It is a better test than vocabulary. Several packaged components mention risk in
a docblock and are perfectly generic; `ExportMenu` is a hardcoded list of eight
`risk.export.*` routes and is not.

## The shell has slots for exactly that reason

`AuthenticatedLayout` used to import `SearchBox` and `PeriodSelector` directly,
and both name product routes. They are `search` and `periodSelector` props now:

```jsx
<AuthenticatedLayout search={<SearchBox />} periodSelector={<PeriodSelector />}>
```

The layout keeps the decisions that are the same everywhere — where they sit in
the topbar, that search appears only with `search.view`, that the period chip
appears only when a period is resolved. The application supplies what goes in
them. The risk product wraps this in `resources/js/Layouts/AuthenticatedLayout.jsx`
so that its 157 pages did not change an import.

## Tailwind

Consumers must scan this package's sources. Tailwind 4's automatic detection
already does when the package sits inside the project, but the failure mode if
it ever stops is silent — class attributes with no rules behind them — so the
risk product's `app.css` names them explicitly.

## Testing

There is no JS test runner in this repository yet, so `npm test` here is a
no-op that says so rather than pretending. The verification for this package is
the consuming application's `npm run build` — which fails loudly on an
unresolved import or a missing export — and its Inertia page tests, which
assert the props every page receives.
