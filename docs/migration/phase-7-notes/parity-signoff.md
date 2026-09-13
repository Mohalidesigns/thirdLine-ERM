# Phase 7.4 — Parity sign-off

`scripts/parity-check.php` is green over 198 named GET web routes and runs in
CI. `docs/migration/parity-checklist.md` has no empty cells across 196 rows.
`grep -rl "return view(" app/Http/Controllers` was already empty.

## The script found a regulatory export that had never run

`risk.export.loss-events.cbn-orms` — the loss-event return a Nigerian bank files
with the Central Bank — returned a 500:

```
SQLSTATE[HY000]: no such function: MONTH
```

`whereIn(DB::raw('MONTH(date_of_loss)'), $months)`. `MONTH()` is MySQL's;
SQLite has no such function. So the export worked on the production driver and
crashed on every other one, which is precisely why **no test had ever requested
it** — and why nobody noticed. It is `whereMonth()` now, which is portable.

A sweep for the rest of the family — `YEAR(`, `DATE_FORMAT`, `DATEDIFF`,
`IFNULL`, `GROUP_CONCAT`, `STR_TO_DATE` inside `DB::raw` — found no others.

Five of its six siblings (`basel`, `nfiu`, `management`, `trends`, `full`) were
equally untested and are fine; they are covered now.

## What "covered" had to mean, after two false starts

The check the phase prompt asks for is "a test references the route name". Taken
literally it reported **41 problems, of which 33 were false**:

- Plenty of tests call `get('/search')` rather than `get(route('search.index'))`.
  A route reached by its own URI is no less covered for it, so the check counts
  either.
- Three "renders no page and returns no file, JSON or redirect" were my
  pattern list being too narrow: `PeriodController@select` returns
  `$this->backTo()`, a private redirect helper, and `ReportController@download`
  returns `$disk->download()`. A list of recognised terminal expressions grows
  every time somebody writes a helper, and every gap in it is a false alarm in
  CI. It now asserts only that the method **returns something** and never
  `return view(...)`.

That left **33 genuinely untested routes**. 24 take no parameters and are now
covered by `RouteParitySmokeTest`, which enumerates them from the router rather
than listing them — so a route added without a test is caught the moment it
exists. The other 9 take bindings and are covered by
`ParameterisedRouteSmokeTest` with real fixtures.

Because the smoke test derives its list, no amount of grepping for names would
find that coverage. The script treats a parameterless GET route as covered and
**asserts the smoke test still exists and still enumerates** — a rule that leans
on a test is only honest while it can tell that test is still there.

## What the smoke tests assert, and what they do not

The route resolves, the binding resolves, authorisation passes, and the response
is not a 5xx — **on a tenant with no data**. The empty tenant is the point
rather than a shortcut: every recurring defect this migration found lives at
that boundary. A rate over no rows returning 0. A division by a count of
nothing. A `first()` that is null and then dereferenced. A screen that only
works once somebody has entered data is a screen that breaks on a customer's
first day.

They say nothing about whether the figures are right. That is what the module
tests are for, and the checklist's preamble says so.

A 404 is a pass for the two attachment downloads: the record exists, the stored
file does not, and reaching a 404 proves the route, the binding, the tenancy
check and the authorisation all ran.

## CI had been broken since Phase 6.8

Found while adding the parity guard next to it. The build step asserts the Vite
manifest contains `resources/js/app.js` — the Livewire entry that **6.8 deleted
in the same commit**. Any CI run since would have failed on a check asserting
the presence of a file that commit removed.

It now asserts `app.jsx` and `app.css` are present **and that `app.js` is
absent**, so the entry cannot come back unnoticed.

## The checklist, and one honest admission

1,014 empty cells were filled, derived from the router and the controllers by
`parity-check.php --checklist` rather than typed. Half say `n/a`, and the
preamble now explains what each `n/a` means — a route that never had a Blade
view, a controller that returns a file, a screen with no grid. An `n/a` nobody
can interpret is worse than a blank.

**"Signed off by" says `parity-check.php (Phase 7.4)` on all 196 rows.** No
human has walked 196 screens, and writing a name there would imply a review that
did not happen. Recording the actual verifier is more useful and more honest. A
person's sign-off belongs in a column added when that review is done.

The claim the table makes is therefore narrow and true: every route is
reachable, guarded, rendered by a component that exists, and touched by a test.

## Development standard

`docs/DEVELOPMENT_STANDARD.md` is a **delta** over ThirdLine's, not a
replacement — twelve sections covering only what this product does differently,
each with the defect that made it a rule. Section 10 is the one worth reading
first: it states what the test suite does *not* cover (no JavaScript executes,
no CSS renders, a POST test is not a test of the form), because a green suite
here has twice meant an unusable application.

The PR against ThirdLine's own §12 scaffolding playbook belongs with 7.2, which
is the commit that touches that repository.
