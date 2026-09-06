# Phase 6.6 — Configuration bundles

`ConfigurationBundleController`, 183 lines, one Blade view. The narrowest grant
in the product: an import rewrites the tenant's whole definition set — object
types, fields, lifecycles, relationship types, scoring profiles — in one
transaction.

**This is the cleanest module Phase 6 has touched.** The controller already
delegated every operation to the same three services the artisan commands use,
already verified a bundle's checksum before applying it, already demanded an
explicit confirmation, already took a snapshot first, and already asserted
tenant ownership on every bound model. There was nothing here that saved
nothing, nothing that read a column that does not exist, and nothing that
crossed a tenant boundary.

Two things changed.

## The whole diff went through the session

```php
return back()
    ->with('configuration-diff', $result['diff'])
    ->with('configuration-diff-bundle', $validated['bundle_id'] ?? null);
```

A configuration-sized document in the session store, written and read back for
the sake of one redirect. On the database session driver this deployment uses
that is a large blob per dry run in `sessions.payload`; on a cookie driver it
would simply have failed above a few kilobytes.

The dry run renders `Admin/ConfigBundles/Diff` as its response now.
`the_dry_run_renders_the_diff_rather_than_flashing_it` pins it.

## `bundle_id` was a bare `exists:`

```php
'bundle_id' => 'nullable|integer|exists:config_bundles,id',
```

Across every organisation on the installation. **It was not exploitable**, and
it is worth being exact about why: `payloadFrom()` resolves the row through the
tenant-scoped model and asserts ownership, so another institution's bundle 404s
rather than being read. What the bare rule did leak is the difference between
"no such bundle" and "not yours", which is a distinction worth not publishing —
and a later refactor that trusted the validated id would have inherited a hole
rather than found one. It is tenant-bound now.

## What moved rather than changed

`assertOwned()` — the controller's private check, with its own reasoning about
route-model binding being worth checking twice — became `ConfigBundlePolicy` and
`ConfigBundleApplicationPolicy`, so a console command or an API asks the same
question the screen does.

`ExportBundleRequest` deliberately carries **no** uniqueness rule on `code`:
re-exporting the same code produces a new version, which
`re_exporting_the_same_code_produces_a_new_version` pins, and a unique rule here
would break it.

The uploaded-file path is still a shape check rather than a schema check, and
that is deliberate: a diff never writes, so what a malformed payload produces is
a bad diff — which is exactly the thing the operator is reading before they
decide.

## The screen

`Index` holds the export form, the dry-run form, the bundle list and the
history. `Diff` lists conflicts **first**, field by field: a conflict is a row
that changed on both sides since the last apply — the bundle's author edited it,
and so did somebody here — so applying through one is how a local edit
disappears silently. The apply form sits at the bottom of the diff, which is the
order the operation should happen in.

## Still Blade after this phase

The integrations group: webhooks, API tokens, connectors and jobs (6.7).
