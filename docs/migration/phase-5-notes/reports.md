# Phase 5.4 — Reports

`Risk/ReportController`, 1,083 lines — the second-largest controller in the
phase. Six views onto Inertia (the library was already Inertia from Phase 2),
two services extracted, one policy, and **three defects**, each of the family
this phase keeps finding: a figure that was zero when it should have been
absent, and two charts describing something other than what they were labelled.

## The board pack reported 0% capital adequacy

`ReportController::board()` set the capital tile to
`round((float) $latestIcaap->car_actual, 1)`.

`car_actual` is **nullable** — a preparer records the balance sheet before
typing a ratio — and `(float) null` is `0.0`. So an ICAAP assessment on file
without a typed ratio rendered **"0%"** on the pack a bank's board reads. The
tile's own `unavailable` flag fired only when there was no assessment *at all*,
so it could not catch this.

WP-08 named this number precisely: *"0% CAR is a specific, catastrophic claim
about a bank's solvency — the one number the screen must never invent."* It had
been removed from the ICAAP screen and, in 5.2, from the quantification
dashboard; the board pack still had it.

It comes through `IcaapService::capitalRatioPercent()` now — computed from
stored capital and RWA, with the preparer's typed figure as a stated fallback —
which also stops the board pack and the ICAAP screen **disagreeing about the
bank's CAR**, as they previously could.

`BoardReportCharacterisationTest` was written against the running Blade first
and failed on exactly the two capital assertions.

## The executive amber KRI tile counted a value nothing writes

It queried `current_status = 'yellow'`. The band vocabulary the measure engine
writes is red / amber / green — `KriMeasureMigrator` emits `'amber'` — and
every other consumer counts both spellings (`BoardPackAssembler`:
`whereIn(['amber', 'yellow'])`). This tile counted the legacy spelling
**alone**, so every KRI the current engine bands as amber was missing from the
executive pack's amber count and from its KRI status chart.

The single `'yellow'`-only read in the codebase was this line.

## The twelve-month trend could only slope upward

Twelve separate `COUNT` queries, each filtering `status = 'active'` — the
risk's status **today** — against `created_at <= end of month`. A risk opened
in January and archived in June was therefore absent from January's bucket as
well, so the series described the current register projected backwards rather
than the register as it stood.

That is 5.1's finding exactly: *a chart labelled "trend" may be reading today's
number for every historical bucket.* 5.1 fixed five of them in the analysis
module; this one was in the executive pack.

`risks` carries no status history, so "was it active that month" is not
knowable. What **is** knowable is when a risk came onto the register, and that
is what the series counts now — `date_identified` where recorded, falling back
to `created_at`, which is the rule 5.1 established for per-bucket existence.
One query, bucketed in PHP: grouping by month in SQL needs driver-specific date
functions and this codebase has been bitten by MySQL-only SQL before.

## Two declined prompt instructions

**`useJobProgress` on the status page.** The prompt asks for the status page's
hand-rolled `setInterval` + `fetch` to become `useJobProgress`. That hook polls
`/risk/jobs/{id}/progress`, which needs a JobRun id — and `GenerateReportJob`
**does not use the `TracksJobProgress` trait**, so no JobRun is ever created for
a report and `generated_reports` carries no `job_run_id`. Wiring one up is a
change to the job pipeline, not a port of a screen.

What replaced the hand-rolled loop is `useReportStatus`, a hook with the same
shape and the same transient-failure behaviour, polling the `status.json`
endpoint that already exists and is unchanged. If report jobs later gain
JobRuns, that hook is the one file to delete.

**`GeneratedReportPolicy::download` asking for `report.export`.** The prompt
names view/generate/export. `report.export` is real — it guards the seven CSV
export routes — and requiring it on the download reads plausibly, since a
download is a document that leaves the platform. But the download route has
always required `report.view`, and tightening it would silently lock the
download for every existing role holding view without export. Same call 5.3
made on the compliance panel: a port does not change which permission a screen
needs.

## Smaller things

- `assertSameTenant()` is `Gate::authorize('view', $report)` now — the check in
  one place, asked the same way from a screen, a form request or a console
  command.
- `categories.*` and `business_units.*` on the custom report were `integer` and
  nothing more, so another institution's ids were accepted into the filter. The
  report query scopes to the organisation anyway, so the effect was an empty
  section rather than a leak — but it is the same class as 5.3's four, and one
  line each closes it.
- `sections` had no rule on its elements, so any string reached the assembler.
  The section list, the report types, the ratings and the formats now live on
  `GenerateCustomReportRequest` — the class that validates them — rather than
  as literals in a Blade template that only happened to agree with it.
- `$a->requested_at?->format(...)` in the board actions: `requested_at` is NOT
  NULL with a database default, so that nullsafe was never reachable. One
  baseline entry dropped.

## Acceptance criteria

- **Criterion 5** — report generation end to end from React:
  `tests/Feature/Reports/ReportGenerationFlowTest.php`, six tests covering
  create → job → progress → download, plus the tenant boundary and the
  generate permission. `git diff --stat resources/views/reports/pdf` is
  **empty**: the PDF templates are untouched.
- **Criterion 6** — `php artisan scramble:export` diffed before and after the
  whole module's changes: **identical**. The API is untouched.

## Numbers

`ReportController` 1,083 → 659 lines. `resources/views/risk/reports/` is gone.
