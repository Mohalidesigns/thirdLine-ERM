# P6 — bulk download, dashboards and the offline round trip

Section 10, in three parts: the file the bank hands to the regulator, the seven
views over a cycle, and taking an assessment away on a laptop and bringing it
back.

## What landed

| Piece | Where |
|---|---|
| The 23-column workbook | `app/Services/Rcsa/RcsaWorkbookWriter.php` |
| Filters, scoping seam, the log | `app/Services/Rcsa/RcsaExportService.php` |
| Large exports | `app/Jobs/GenerateRcsaExportJob.php` |
| The seven dashboards | `app/Services/Rcsa/RcsaDashboardService.php` |
| The offline round trip | `app/Services/Rcsa/RcsaRoundTripService.php` |
| Model + policy | `RcsaExportJob`, `RcsaExportJobPolicy` |
| Endpoints | `Rcsa\{Export,Dashboard,RoundTrip}Controller` |
| Permissions | `rcsa_export.bulk`, `rcsa_audit.view` + `100005` grant |
| Pages | `RcsaExports/Index.jsx`, `RcsaDashboard/Index.jsx`, `RcsaRoundTrip/Show.jsx`, plus the workspace's working-copy buttons |
| Tests | `ExportTest` (15), `DashboardTest` (11), `RoundTripTest` (10) — 36 |

**P0 had already built the schema.** `rcsa_export_jobs` carries the filters, row
count, IP, expiry and download count §10.2 asks for, and
`rcsa_import_batches` already had `type = assessment` and `assessment_id` for
the §10.4 round trip. P6 needed no migration except the permission grant.

## The export (§10.1, §10.2)

**The column list is the file format**, and `GROUPS` spans it by FIELD NAME
rather than column letter, so inserting a column moves the merge with it instead
of silently mis-spanning the one after. 23 workbook columns under five merged
banners, then eight appended: Assessment Cycle, Assessor, Submitted Date, ORM
Status, Reviewer, Action Plan Status, Days Overdue, Last Review Date.

**Where the group boundaries came from.** §10.1 names five groups; §4 gives the
column table. Read together they put Process at A–I, INHERENT RISK at J–M,
Control assessment at N–P, Residual Risk at Q–T and RISK TREATMENT PLAN at U–W.
**That reading has not been checked against the `.xlsx`,** which is still
unavailable — the same open gap the truth table carries, and it is the one thing
in P6 worth verifying before sign-off.

**Small exports stream, large ones queue, and the screen says which before the
button is pressed.** The row count follows the filters through the same query
the export runs, so what the screen promises and what the file contains cannot
disagree. An export that silently became a background job is one people press
three times and then email about.

**Every export is logged whether or not it is queued**, and the row is written
*before* the file exists. An export that failed and an export nobody recorded
must be distinguishable. A foreign cycle id is refused by the Form Request
rather than merely returning nothing, because on the one screen that hands out
the whole risk profile, "returns nothing" and "is refused" are different facts
for the log.

**Signed AND authorised.** The queued download is a `temporarySignedRoute`, and
the far end still checks the permission, the tenant and the owner. The signature
stops a URL being guessed; it is not authorisation, and
`a_signed_link_is_still_checked_against_the_permission_and_the_owner` pins that
a colleague holding the same link gets a 403. Expiry is checked on read rather
than swept by a job, so a link past its date is refused the moment it is
followed.

**Action plans flatten into U, V and W**, newline-joined, because the workbook
has one cell per risk and this module has many plans per line (defect D5). That
is template parity, not a data model — and the round trip deliberately does not
read those columns back.

## The dashboards (§10.3)

Seven panels over **one cycle at a time**. Summing every cycle the bank has run
would report the same risk five times and call it five risks.

**Every figure is counted in SQL, in ordinary SQL.** No `DATE_FORMAT`, no
`GROUP_CONCAT`, no window functions — grouped counts and averages that MySQL and
SQLite agree on, because the suite runs on SQLite and a dashboard that only
renders on the production driver is one no test can hold. The exception is the
ageing buckets, computed in PHP over the open plans only (a few hundred rows),
because "31 to 60 days late" as portable date arithmetic is not worth it.

**The residual heat map's construction is stated, not implied.** In calculated
mode a residual is a single fractional score with no likelihood/impact pair, so
plotting it on a 5×5 grid means deciding where it goes. The likelihood axis is
unchanged — a control mitigates impact and detection, not the frequency of the
underlying event — and the impact axis moves to what the residual score implies.
A heat map is the most-quoted artefact in a Board pack and its construction
should not be a mystery.

**Drill-through is answered in place**, and the cell is re-derived by the same
code that drew it rather than stored — two implementations of "which cell is
this risk in" would disagree the first time either changed. Only queried when a
cell is actually clicked.

**The action-plan panel is not cycle-scoped**, because §9.3 made the register
outlive the cycle. Filtering it to the current cycle would empty the ageing
panel every quarter, which is exactly how remediation stops being tracked.

**The tests assert arithmetic, not rendering:** the heat map's cells sum to its
total, the above-appetite split adds up to the register, the movement counts add
up to the lines compared. A dashboard whose numbers merely appear is one nobody
can check.

## The offline round trip (§10.4)

Three rules, and all three are tested through a real file — generated by the
writer, opened with PhpSpreadsheet, edited by header label the way a person
would, uploaded back:

1. **It matches on the hidden line id, never on the risk number.** Two units can
   share a numbering scheme and a risk statement can be corrected between the
   download and the upload. The id is the only thing about a row that does not
   change. `__line_id` and `__version` go FIRST, not last — appended to the right
   they would be the first thing an Excel user deletes while tidying up.

2. **It reads back five columns and discards the other eighteen.**
   `ROUND_TRIP_FIELDS` is the whitelist.
   `a_calculated_column_typed_over_in_excel_is_discarded` overtypes the residual
   score, the risk score, the level, the business unit and the risk statement,
   and asserts none of them moved. The grey shading and the sheet protection are
   courtesies — PhpSpreadsheet protection without a password is off in two
   clicks — and the whitelist is the enforcement.

3. **A line somebody else changed is flagged, not overwritten.** The file
   carries the `version` each row was exported at, so a line whose version moved
   was written in between. Those rows stage as conflicts and **default to
   keeping the server's answer**. Silently applying a fortnight-old offline file
   over a colleague's morning is the single worst thing this feature could do.

**It writes through `RcsaAssessmentService::apply()`**, never straight to the
line — so the engine recomputes, the revision trail records, the version bumps,
and P5's rule holds: a line the ORM never reopened is refused with `LOCKED`,
even from a spreadsheet.

**It parses in the request, not on a queue**, unlike the universe import. A
working copy is one assessment, bounded by the risks the unit was given; the
universe import can be five thousand rows somebody assembled by hand. Putting a
spinner between the user and the conflict screen they came for would be the
wrong trade.

## What P6 found

**The v2 dashboard silently deleted the legacy one.** Registering
`rcsa/dashboard` — the legacy module's URI — replaced it in Laravel's route
table, so `route('risk.rcsa.dashboard')` began throwing and four legacy tests
went red with nothing in the v2 code looking wrong. §13's rule is that the module
being replaced stays live and untouched until cutover, and a URI collision is one
of the quieter ways to break it. The v2 route is `rcsa/dashboards`, plural, and
`no_v2_route_shadows_a_legacy_rcsa_route` now asserts the rule directly rather
than leaving it to whichever legacy test happens to call the shadowed route next.

**The working copy came back through its own parser with a phantom blank row.**
`lockCalculatedColumns()` assumed the export's two-row header while the working
copy has one, so it styled one row past the data — and PhpSpreadsheet reports a
styled row as a row. Exactly P2's lesson (*always round-trip a generated file
through its own parser*), caught by the test that opens the file rather than one
that checks its length.

**The conflict screen offered a choice it could not honour.** Found by driving
the page: a locked line showed a Mine/System toggle, and picking "Mine" was
silently dropped by `apply()` afterwards. Locked rows are now marked on the
preview with their own tile and banner, and `resolve()` refuses them — a button
that lies about what it does is worse than one that is absent.

**The export form cannot be an Inertia visit.** The synchronous path streams the
`.xlsx` in the response and Inertia would try to parse the workbook as a page.
It submits a real form; the large-export path still works because its 302 back
is an ordinary redirect.

**`StatusBadge` was missing the export-log states** — `queued`, `processing`,
`ready`, `failed`, `expired` all fell through to draft styling. Third time in
this programme (P1's `published`, P5's `under_review`), same component.

## Carried into P7

- **Business-unit scoping is one method.** `RcsaExportService::reachableUnitIds()`
  returns null — "every unit in the tenant" — which is what every RCSA policy
  currently means too. Every query in the export routes through it, so P7 filling
  it in there is the whole change for the export. `users.business_unit_id` exists;
  what does not exist yet is the assignment model, and making this return the
  user's own subtree before P7 builds one would silently empty the export for
  every user whose unit is null, which is most of them.
- The dashboards are **not** scoped at all yet — they read the whole tenant.
  Same seam, different place: `RcsaDashboardService::lines()`.
- `rcsa_audit.view` is granted and used for the export log. §11's audit VIEWS —
  the workflow trail and `rcsa_line_revisions` as screens — are P7.
