# P2 — the template generator and the bulk upload pipeline

A workbook generated per tenant, and an import that stages everything and
writes nothing until a person has looked at it.

## What landed

| Piece | Where |
|---|---|
| Template generator | `app/Services/Rcsa/RcsaTemplateWriter.php` |
| Normaliser | `app/Services/Rcsa/RcsaImportNormaliser.php` |
| Validator (§7.3) | `app/Services/Rcsa/RcsaImportValidator.php` |
| Parser / stager | `app/Services/Rcsa/RcsaImportProcessor.php` |
| Publisher | `app/Services/Rcsa/RcsaImportPublisher.php` |
| Annotated error workbook | `app/Services/Rcsa/RcsaErrorWorkbookWriter.php` |
| Queued job | `app/Jobs/ProcessRcsaImportJob.php` |
| Models | `app/Models/Rcsa/{RcsaImportBatch,RcsaImportRow}.php` |
| Controller | `app/Http/Controllers/Rcsa/ImportController.php` |
| Form Requests | `app/Http/Requests/Rcsa/{StoreImportBatch,PublishImportBatch,UpdateImportRow}Request.php` |
| Routes | seven, under `rcsa.imports.*`, behind `feature:rcsa_v2` |
| Preview screen | `resources/js/Pages/RcsaUniverse/ImportPreview.jsx` |
| Upload card | `RcsaUniverse/Index.jsx` — the two header buttons are live |
| Tests | `tests/Feature/Rcsa/Import{TestCase,ValidationTest,PublishTest,ScreensTest}.php` — 40 tests |

## Acceptance criteria

> *A 1,000-row file validates in under 60s; every rule in §7.3 fires correctly
> on a deliberately broken fixture; nothing reaches live tables before confirm.*

- **Every rule fires.** `every_rule_in_the_catalogue_fires_on_a_broken_file`
  builds one workbook with sixteen rows, each carrying exactly one violation,
  and asserts the status *and* the specific `field.rule` for each. It also
  asserts the severity split is meaningful: a warning row still writes, an
  error row does not, a duplicate defaults to skip.
- **Nothing reaches live tables.** `staging_a_file_writes_nothing_to_the_universe`
  is the whole design in one assertion. `RcsaImportPublisher` is the only class
  in the pipeline that touches `rcsa_register_risks`, and it runs after a human
  has seen the preview.
- **Throughput** is not asserted with a timed test. Timing assertions are
  flaky on shared CI and tend to be deleted rather than fixed. What is done
  instead is structural: the normaliser loads its four lookup tables once per
  batch rather than per row, the validator loads the published row-hash index
  once, and staging inserts in chunks of 500. The 17-row fixture stages in
  ~70 ms locally, and none of the work is per-row-query-bound, which is the
  property that actually decides whether 1,000 rows fit in a minute.

## What the round-trip test found

**A downloaded template, re-uploaded unmodified, was rejected.**

`SpreadsheetReader` reads the workbook's **active sheet**. The generated
template opens on `Instructions` so that a user sees them first — so the parser
read the instructions as a header row and answered every upload with "this file
is missing required columns: Business Unit, Potential Risk, Risk Category", on
a file the product itself had produced ten seconds earlier.

It would have hit every real user too, and for a second reason: Excel saves a
workbook with whichever tab was last selected, so even a single-sheet mental
model breaks the moment somebody clicks `Risk Matrix` before saving.

`SpreadsheetReader::rows()` now takes an optional sheet name and prefers it when
the workbook has it, falling back to the active sheet — additive, so the
existing `DataImport` consumers are untouched. `RcsaImportProcessor` names
`RCSA Universe`.

The test that found it is
`the_generated_template_round_trips_through_the_importer`: download the real
template over HTTP, fill row 2, feed it back to the parser. That is the
strongest single claim available about a generated file format, and it is worth
having for every generator this module grows.

A second, smaller one from the same test: `template_version` was written as a
general value, so PhpSpreadsheet stored `"1.0"` and handed back the float
`1.0`. The upload check would have compared a float to a string and rejected
every file the product generates. Written explicitly as a string now.

## Decisions worth knowing

- **One row per CONTROL, and rows merge by risk.** The template asks for a risk
  repeated across rows when it has several controls, and the publisher groups by
  `row_hash` before writing. This is the most consequential thing in the file
  format: writing each line as its own risk would triple the register on the
  first upload. `rows_repeating_a_risk_become_one_risk_with_several_controls`
  pins it.
- **Three severities, and the difference is what gets written.** An *error*
  blocks the row; a *warning* publishes it with less than the user asked for (an
  unresolved control owner becomes unassigned, an unknown system is not linked);
  a *duplicate* defaults to skip. Getting this split wrong in either direction
  is how a bulk upload fails — too many errors and the bank abandons it after
  one attempt, too few and the universe fills with rows nobody meant.
- **Fuzzy matching warns, never decides silently.** "Retail Bankng" resolves to
  "Retail Banking" at 96% similarity and the row is marked `warning` saying so.
  A silent fuzzy match that attached sixty risks to the wrong business unit
  would be far worse than sixty rejected rows. The threshold is 85%, which
  tolerates a transposition in a short name while refusing to guess between
  "Treasury" and "Trade Finance" (46%).
- **In-file repetition is not a duplicate.** Two rows with the same risk are a
  risk with two controls — that is what the template asks for. Only a row
  matching something *already published* is flagged.
- **`prepareForValidation` is not used here; `raw` is kept forever.** The
  annotated error workbook writes the user's own spelling back, not the
  normalised value. Telling somebody "row 42 is wrong" while showing them a
  value they never typed is how a bulk upload loses whatever trust it had.
- **The whole file comes back, not only the failures.** A workbook of the six
  bad rows out of two hundred cannot be corrected and re-uploaded; one with all
  two hundred can, which is the point of handing it back.
- **Publishing is one transaction.** A half-published batch would leave the user
  unable to tell which rows landed, so they would upload again and duplicate
  them. `a_publish_that_fails_part_way_writes_nothing` breaks the third row's
  foreign key deliberately and asserts zero rows written.
- **Updating adds controls rather than replacing them.** An update from a
  partial file must not delete controls the file simply did not mention;
  re-uploading the same file does not double them either, because controls are
  matched on their description.
- **An update keeps the existing risk number.** It may already appear in a board
  paper, and renumbering a risk because somebody re-uploaded a file would break
  every reference to it.
- **Imported risks arrive as DRAFTS.** The import fills the universe; publishing
  a row into future assessments is still the separate, permissioned act of P1.
  Two gates, and the screen says so.
- **Uploading and publishing are different permissions.** `rcsa_universe.import`
  gets you as far as the preview; `rcsa_universe.publish` writes.
  `uploading_and_publishing_are_different_authorities` pins it. This is §14 Q8
  again, and the same answer P1 gave.

## Deviations from the plan

### 1. The marker cells are written and checked, but do not yet reject a file

§7.1 asks for `template_version` and `tenant_id` markers "used to reject stale
or foreign templates at upload". Both are written, to a **very hidden** sheet,
and the round-trip test asserts them. The upload does **not** yet refuse a file
whose `tenant_id` names another organisation.

The reason is that refusing on the marker alone would refuse every CSV and every
file a user rebuilt by hand — both of which this pipeline is meant to accept,
and both of which have no markers at all. The rule that makes the marker useful
is "reject when a marker is PRESENT and names another tenant", and it wants the
preview screen to explain itself rather than a bare 422. It is a small piece of
work and it is listed for P2.1 rather than half-done here.

What already protects against the cross-tenant case: every lookup the normaliser
performs is inside the tenancy scope, so a file from Bank A uploaded to Bank B
resolves no business unit on any row and every row comes back as an error naming
the unit it could not find.

### 2. `SpreadsheetReader` gained a parameter

Covered above. Additive and defaulted, so `DataImportProcessor` and
`DataImportController` are unchanged.

### 3. No assessment template yet

§7.1's second variant — the 23-column assessment template with J/K/O open and
the computed columns formula-locked — belongs with the offline round-trip in
§10.4, which the plan schedules for P6. `RcsaTemplateWriter` is shaped for it
(`universeTemplate()` is one method, not the class's whole purpose).

## Verification

- `php artisan test --filter=Import` — 40 passed.
- Full suite: **2,105 passed, 4 skipped** (2,063 before this phase).
- PHPStan clean on the new paths; Pint clean.
- Driven end to end in a browser against MySQL and a real Redis queue: template
  downloaded (a 146 KB file whose first two bytes are `PK`), a four-row CSV
  uploaded, `queue:work` ran the job, the preview showed 4 / 2 ready / 2 errors
  with per-row messages, Publish was disabled until "publish the valid rows
  only" was ticked, and publishing created **one** risk with **two** controls
  from the two repeated rows and skipped the two broken ones.

## What P3 needs from this

- `RcsaImportProcessor` and `RcsaImportPublisher` are universe-shaped. The
  assessment round-trip of §10.4 reuses the batch/row tables (`type` is already
  `universe|assessment` and `assessment_id` is already on the batch) but needs
  its own normaliser field set and its own publisher.
- `RcsaRegisterRisk::assessable()` is what a cycle provisions from, and imported
  rows are drafts — so a cycle opened straight after an import sees nothing
  until someone publishes the rows. That is intended; P3's cycle-opening screen
  should say so when it finds an empty universe.
