# Phase 3 — the common brief for one module

Every Phase 3 module follows the same shape. It was settled by 3.1 (scoping), 3.6 (appetite) and 3.7 (workflow); read one of those three notes beside this file for a worked example, and `docs/migration/05-phase-prompts/phase-3-core-modules.md` for the phase's own scope.

## Where you work

One git worktree per module under `../riskerm-wt/<module>`, branch `p3/<module>`, based on the Phase 2 commit `9c133a2`. The integration branch is `migration/phase-3-core-risk` in the main checkout; a finished module gets there by `git cherry-pick`.

`vendor/` in the worktree must be a real copy, not a symlink:

```bash
rsync -a vendor/ ../riskerm-wt/<module>/vendor/
```

A symlinked `vendor` autoloads the main checkout's `app/`, so the worktree's own new classes are invisible and its tests fail with "class not found".

## What one module delivers

1. **Characterisation test first.** Before touching the controller, pin every number and label the current Blade screen produces, in `tests/Feature/Characterisation/<Thing>Test.php`. Port afterwards and re-point the same assertions at the Inertia props. If a figure changes, that is a decision to write down in the notes, not a diff to absorb quietly.
2. **A Policy per model** in `app/Policies` — permission, then tenancy, then any domain rule. Auto-discovered; assert it with `Gate::getPolicyFor` in the module's page test. Delete any inline `Gate::define` the module owned.
3. **Form Requests** in `app/Http/Requests/<Module>` — `authorize()` goes through the policy, and every foreign key is a tenant-bound `Rule::exists(...)->where('organization_id', TenantContext::organizationId())`. Never the string form `exists:table,id`; a test asserts the string does not appear.
4. **A Service** holding whatever the controller computed inline (the figures, the fallbacks, the grouping). This is what the characterisation test drives.
5. **A thin controller**: authorize, call the service, `Inertia::render`. Route names and URIs never change.
6. **React pages** under `resources/js/Pages/<Module>/`, reusing the Phase 2 primitives — `DataGrid` for lists, `DynamicForm`/`DynamicDetail` for configurable objects, `KpiCard`, `Modal`, `Pagination`, `Widget` for chart envelopes.
7. **Delete the Blade views in the same commit** that ports them, and add every ported route name to `App\Support\Migration\Ported::ROUTES`.
8. **Notes** at `docs/migration/phase-3-notes/<module>.md`: what landed, every deviation with its reason, and every existing assertion you adapted.
9. **Parity checklist**: fill the row for each route in `docs/migration/parity-checklist.md`.

## Green means

- The module's own tests, plus the five guard tests: `RouteAuthorizationTest`, `TenancyIsolationTest`, `NoFabricatedNumbersTest`, `AdminNavigationTest`, `PreflightRouteGuardTest`.
- `vendor/bin/pint` on every file you touched.
- `vendor/bin/phpstan analyse --memory-limit=2G` on every PHP file you touched, with **no new errors and no baseline entries added**. If you add a relation return type, give it generics — a bare `: BelongsTo` degrades every downstream read to `Model` and produces more errors than leaving the method untyped:

```php
/** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Related, $this> */
public function related(): \Illuminate\Database\Eloquent\Relations\BelongsTo
```

- JSX syntax-checked with `node_modules/.bin/esbuild <file> --loader:.jsx=jsx`. Do not run `npm run build` in a worktree.

`AssetResidencyTest` cannot pass in a worktree — it needs `public/build/manifest.json`, which only the main checkout builds. Four failures there are expected and unrelated to your module.

## Integrating

Cherry-pick onto `migration/phase-3-core-risk`. Two files conflict every time:

- `app/Support/Migration/Ported.php` — keep **both** Phase 3 route blocks.
- `docs/migration/parity-checklist.md` — per route row, keep the side whose cells are filled.

Then run the module's tests and the five guard tests on the phase branch.
