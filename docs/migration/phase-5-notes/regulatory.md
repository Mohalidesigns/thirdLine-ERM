# Phase 5.3 — Regulatory compliance

`Risk/RegulatoryComplianceController`, 244 lines, eight Blade views and a
scheduled command. Small by the phase's standards and the densest so far in
defects per line: **five**, one of which had never worked at all.

## Scope check

Eight view files, not the prompt's nine — `_taxonomy-node.blade.php` is a
partial, not a screen. `risk.regulatory.circulars` was already Inertia from
Phase 2 (the shared grid), so seven routes flip here.

Property reads across the views were checked against the columns first, the
technique that found the phantom-field defect four times in 5.2. **This module
is clean on that front** — every `$circular->…` and `$deadline->…` in the Blade
resolves to a real column. The defects here are elsewhere.

## Nothing validated a foreign key. Anywhere.

The controller validated the scalar fields and then wrote the ids straight from
the request body:

| Field | Where it lands | What it could point at |
|---|---|---|
| `responsible_id` | the officer the calendar names for a CBN return | another institution's user |
| `assigned_to` | the person answerable for a circular | another institution's user |
| `affected_risk_ids` | a json array on the circular | another institution's risks |
| `parent_id` | the taxonomy tree a node is grafted onto | another institution's tree |

None had a rule of any kind — not even `exists:users,id`. Five Form Requests
now carry tenant-bound `Rule::exists`, which is the shape every other module in
this programme uses. `affected_risk_ids.*` gets its own rule, because **a rule
on an array says nothing about what is in it**.

`RegulatoryTenancyTest` covers each one.

## The circular form loaded every risk and rendered none of them

`createCircular()` has always run `Risk::where(...)->orderBy('title')->get()`
and passed `$risks` to a template that never mentioned it — a full-table query
on every page load. The other half of that: `affected_risk_ids` is fillable,
cast to an array and written by the store path, and **could not be set through
the interface at all**.

The React form renders the picker, so the query stops being dead and the column
stops being unreachable. The show page resolves the ids and lists them; it
previously displayed nothing from that column either.

## Three policies, not the prompt's one

The prompt asks for a single `RegulatoryPolicy`. Laravel discovers a policy
from the **subject's class** — `App\Models\RegulatoryCircular` resolves only to
`App\Policies\RegulatoryCircularPolicy` — so one policy named for the module
would never be reached by any of the three models it covers and every ability
on it would silently return false. That is 3.8's trap and 4.1's. Three models,
three policies.

`RegulatoryDeadlinePolicy` enforces a split the routes have always described
and nothing checked beyond middleware: **filing is not scheduling**.
`regulatory.file` records that a return went to the CBN on a date — a statement
to a supervisor; `regulatory.manage` administers the calendar.

**Assessing a circular's compliance asks for `regulatory.manage`**, which is
what the `update-compliance` route has always required. Tightening it to
`regulatory.file` reads plausibly and would silently lock the compliance panel
for every existing role holding manage without file. Same reasoning as
`EmergingRiskPolicy` asking for `risk.*`: a port does not get to change which
permission a screen needs.

## "0% compliant" over an empty register

`complianceRate` was `$total > 0 ? round($compliant / $total * 100, 1) : 0`,
and the tile printed it green as **"Compliance Rate · 0%"**. An institution
that had recorded no circulars read a claim of total non-compliance made from
no data.

The same defect WP-08 removed from CAR — *"0% CAR is a specific, catastrophic
claim about a bank's solvency"* — and 5.2 removed from the ICAAP capital
add-on. A rate over an empty register is undefined, and the tile says "No
circulars on record".

`RegulatoryDashboardTest` was written against the running Blade screen first;
**exactly one assertion failed**, which is the evidence.

## `regulatory:check-deadlines` had never run. Not once.

Scheduled twice daily (`routes/console.php`, `->twiceDaily(8, 16)`), it queried
four columns that do not exist on `loss_events` and never have:

```
cbn_report_deadline   → the column is cbn_reporting_deadline
cbn_reported          → the column is cbn_notification_sent
nfiu_report_deadline  → there is no such column
nfiu_reported         → the column is nfiu_report_filed
```

Every run died on the first query with `Unknown column 'cbn_report_deadline' in
'where clause'`, verified against the real MySQL database. **Not one alert had
ever been sent** by the alarm that tells a bank its mandatory CBN loss report
is hours from being late.

**A second fatal defect sat underneath.** The insert set `'user_id' => null` on
`notifications_log`, whose `user_id` is NOT NULL with a foreign key — so even
with the column names corrected every alert would have failed on the insert.
And it would have been invisible regardless: the notification bell counts rows
`where('user_id', $user->id)`, so a row belonging to nobody is read by nobody.
Alerts now go to the officer the event names — `responsible_officer_id`, then
`assigned_to_id`, then whoever recorded it — and an event naming nobody is
reported on the console rather than dropped, because "nobody is accountable for
this CBN clock" is worth saying out loud.

**Why no test caught it, and the lesson.** It had none — Phase 4's criterion 5
gap again. But the trap is sharper here: `$this->artisan(...)->run()` returns
**0 even when the command throws**, verified against this command before the
fix. A console test that asserts only success proves nothing. Every test in
`CheckRegulatoryDeadlinesCommandTest` asserts the **alerts**.

**The static analyser had known all along.** Two entries sat in
`phpstan-baseline.neon`:

```
Access to an undefined property App\Models\LossEvent::$cbn_report_deadline.
Access to an undefined property App\Models\LossEvent::$nfiu_report_deadline.
```

PHPStan reported the exact defect, and the baseline suppressed it. Worth
remembering when regenerating a baseline: an `Access to an undefined property`
on a MODEL is not analyser noise about a magic attribute — it is often a column
that is not there. Both entries are gone, along with the thirteen the relation
typing made stale.

### Two gaps recorded rather than filled

- **There is no NFIU deadline column.** `loss_events` carries
  `nfiu_reportable`, `nfiu_report_type`, `nfiu_str_reference` and
  `nfiu_report_filed` — and no date. An NFIU countdown cannot be computed from
  stored data, and inventing one would be fabricating a regulatory clock. The
  NFIU half is removed and a test pins the schema's silence, so a future NFIU
  deadline column arrives with somewhere obvious to start.
- **The command never reads `regulatory_deadlines`.** Despite its name, the
  filing calendar this module maintains has no reminder mechanism at all. A
  test pins that too — not as an assertion that it is right, but so whoever
  adds one deletes the test knowingly rather than discovering the gap from a
  missed return.

## Smaller things fixed in passing

- `auth()->user()->organization_id` throughout → `TenantContext::organizationId()`,
  the abstraction every other module uses.
- The calendar took `month` and `year` straight from the query string into
  `whereMonth`/`whereYear`; they are validated now.
- The taxonomy page ran `RiskTaxonomy::where(...)->get()` **inside the Blade
  template** to build its parent picker.
- `taxonomyIndex()` eager-loaded `children.children` — two levels — while the
  partial recursed to any depth, so every node below the second level cost its
  own query. The whole tree is one query, nested in memory.
- The compliance panel now offers `action_required`, which `updateCompliance()`
  has always written but the Blade form never rendered.
- Typing the four models' relations made **15 phpstan-baseline entries stale**;
  dropped rather than re-homed. PHPStan back at the six red at HEAD.
