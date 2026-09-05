# Phase 5.5 — Exports, imports, documents

Three controllers, three views, and the import pipeline's final step — which
**had never run**.

`Risk/ExportController` (766 lines) is untouched by design, as the prompt says.
Its sixteen CSV routes get a way to be reached; nothing about them changes.

## `processImport()` threw on every call

Mapping the columns of an uploaded spreadsheet and pressing the button ended in
a 500. Two independent defects, both introduced by WP-07 when the row loop moved
onto a queue, and neither caught because **the route had no test**:

1. **`data_imports.status` was an enum of four** — `pending`, `processing`,
   `completed`, `failed` — and the controller writes `queued`. MySQL in strict
   mode answers that with `Data truncated for column 'status'`; SQLite with a
   CHECK violation. Verified against the real database. The job also writes
   `cancelled`, which the column could not hold either.
2. **`DataImport` had no morph alias.** `ProcessDataImportJob::track()` stores
   its subject as a morph, so `getMorphClass()` threw
   `ClassMorphViolationException` on the very next line.

The controller, the job and the processor were all correct. Only the column and
the morph map disagreed with them.

The status vocabulary now lives on `DataImport::STATUSES` and the column is a
plain string — the shape 5.3 gave `RegulatoryDeadline::STATUSES`. A database
enum is a second copy of that list which only one of the two drivers this
product runs on can be altered in place, and keeping the two in step by hand is
exactly what failed here.

## A column mapping could name any fillable column

`processImport()` validated `column_mapping` as `required|array` and nothing
more — **no rule on its keys**. Those keys become the attribute names in
`Model::create()`:

```php
foreach ($mapping as $field => $columnIndex) { $data[$field] = $value; }
Risk::create(array_merge($data, [...]));
```

The mapping screen offers nine fields for a risk import; `Risk::$fillable` has
forty-odd. A caller could name `parent_risk_id`, `entity_id`, `hierarchy_path`
or `created_by` — none of them something a spreadsheet import was meant to set.

### The worry that turned out to be wrong, and is now pinned as such

`organization_id` is fillable on every model an import writes, and
`BelongsToOrganization`'s creating hook deliberately leaves an explicitly-set id
alone — so a mapping naming it looks like a route into another institution's
register. **It is not.** `DataImportProcessor::process()` overwrites
`organization_id` with the import's own organisation *after* the mapping is
applied. One line, easy to miss, and it is the whole difference between a hole
in what a row may CONTAIN and a hole in which bank it lands in.

That is asserted directly (`a_mapping_could_never_have_written_into_another_institution`)
so nobody re-derives the alarming version of it from the same reading.

The fix is 4.6's rule: `DataImport::FIELDS_BY_TYPE` is the one list — the
mapping screen offers it, `ProcessImportRequest` accepts only it, and
`DataImportProcessor` drops anything outside it on the way to `create()`. The
last of those is defence in depth: a mapping stored before this phase, when
nothing validated it, still cannot reach `create()` with a column it should not.

## `DataImportPolicy`

Uploading is not processing, and the seeded set has always said so —
`import.create` and `import.process` are separate permissions carried
separately by the routes. What was missing is anything asking the question about
a *particular* import: `processImport()` checked the tenant by hand and nothing
checked the permission beyond route middleware.

The distinction earns its keep. Uploading stages a file and reads its headers;
processing writes every row into the register, irreversibly and in bulk. A
fifty-thousand-row risk import is the largest single write anyone can make to
this product.

## The exports

Sixteen CSV routes on a controller this phase does not otherwise touch. They
were reachable only from whichever screen happened to link one, so most were
reachable from nowhere at all. `ExportMenu.jsx` lists them grouped, with every
item gated on `report.export` — the permission the routes themselves carry, so
the check in the component is a courtesy that hides a link the server would
refuse, not the enforcement. A route the Ziggy manifest does not carry resolves
to null and its item is dropped.

## The document repository

Read-only by design: every file uploaded anywhere in the platform —
loss-event attachments, control-test evidence, issue attachments — listed
together, with the source tables staying authoritative. Each row's download goes
back through the owning module's own route, which is where the permission check
for that file lives, so the repository never becomes a second way to reach a
file.

The controller's normalised row shape was already Inertia-ready; the port is the
page.

## Numbers

`DataImportController` 157 → 168 lines — it grew, because what it gained is the
two docblocks recording why `processImport()` could not run and a policy check
per action, against a `getFieldsForType()` helper that moved to the model. `resources/views/risk/{imports,documents}`
are gone; `resources/views/risk/` is down to `dashboard.blade.php` (criterion 7,
done in 5.2's commit for quantification but still outstanding for the Command
Centre) and `workflows/designer.blade.php`, which Phase 6 owns.
