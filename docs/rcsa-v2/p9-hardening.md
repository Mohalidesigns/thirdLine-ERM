# P9 — hardening

The last phase. Section 12's acceptance: *UAT sign-off; p95 grid render under
1.5s at 500 lines*, with a 2,000-line assessment as the stress case.

## What landed

| Piece | Where |
|---|---|
| Tenancy guard | `tests/Feature/Tenancy/NullOrganizationRowsAreDeclaredGlobalTest.php` (5) |
| Performance budget | `tests/Feature/Rcsa/WorkspacePerformanceTest.php` (4) |
| N+1 fix | `RcsaAssessmentService::outstanding()` |
| Payload cap | `AssessmentController::cap()` |
| Parallel-run harness | `RcsaParallelRunComparer`, `rcsa:parallel-run`, `ParallelRunTest` (5) |
| One-page guide | [user-guide.md](user-guide.md) |
| Administrator's guide | [admin-guide.md](admin-guide.md) |

## Performance

Measured against MySQL with realistic rows — prior lines and action plans on
every line, which is what a **second** cycle looks like:

| | 500 lines | 2,000 lines |
|---|---|---|
| p95 render | **329 ms** | 773 ms |
| Queries | 34 | 34 |
| Payload (raw) | 1.1 MB | 3.2 MB |
| Payload (gzipped) | 30 KB | **70 KB** |

Comfortably inside the 1.5s budget.

### The N+1 that only appears in the second quarter

`RcsaAssessmentService::outstanding()` loaded its own copy of the lines with
`actionPlans` alone, then called `movedMaterially()` on each — which reads
`priorLine`. One query per line: **522 for a 500-line assessment**.

It was invisible until now for a precise reason: `prior_cycle_line_id` is null
until a cycle has a predecessor, and Eloquent short-circuits a `belongsTo` with
a null foreign key without querying. **A bank would have met this in their
second quarter, on the largest assessment they had**, and never in testing.

Fixed by eager-loading `priorLine` there: **522 → 24 queries**, median render
597 → 350 ms.

Honest about the size of it: on a local socket the p95 barely moved. The durable
win is the query count — on a networked database, 500 extra round trips per
render is where it bites, and it grows linearly with lines.

### The payload

`outstanding` was **297 KB of a 3.6 MB page** — an issue per line on an
assessment nobody had started, sent to render a panel that shows forty. The
server now caps the list at fifty and sends the true total as `issue_count`;
the screen reads the count for its messages and the list for its rows. The
submission gate is untouched — it calls `blockers()` server-side and still sees
every issue.

P3's decision that the **whole assessment travels** stands: keyboard navigation
cannot paginate. What was removed is a list the screen never renders.

**Gzip is worth more than any of this.** 3.2 MB becomes 70 KB — 45×, on exactly
the branch connections §10.4's offline round-trip exists to work around. It is
the first thing in the deployment section of the admin guide.

### What the test asserts, and why it is not the clock

Wall-clock in CI is a coin-flip; a shared runner will fail a 1.5s budget a
laptop meets in 329 ms, and a flaky performance test is one people re-run until
it passes. So the timing check is deliberately generous and the real assertions
are structural: **the query count does not grow with the number of lines**, and
**the payload is bounded where it can be**.

## Two guards that guarded nothing

Both caught before they shipped, and both worth recording because the failure
shape is the same:

**The tenancy suite passed green having examined nothing.** No `RefreshDatabase`,
so `Schema::hasTable()` was false for every table and each loop skipped its whole
body — four green tests over zero tables. It now asserts, first, that discovery
found the models *and* their tables exist.

**The N+1 guard could not detect an N+1.** Its fixture had no prior lines and no
action plans, so the relations it was meant to protect were never touched:
removing either eager load from the controller left the query count unchanged.
Proved by removing them and watching the test still pass. The fixture now gives
every line a predecessor and a plan — and with that, it immediately found the
real N+1 above.

The pattern in both: *a test that cannot fail is worse than no test*, because it
is a claim that something is checked. Each guard here was verified by
reintroducing the defect it exists to catch and watching it go red.

## The parallel run (§13 step 5)

The one step of the cutover no command can complete — it needs a real unit to
assess the same quarter twice. What `rcsa:parallel-run` does is the part that
cannot be done reliably by eye.

**The classification is the whole value.** A parallel run produces differences on
almost every row, and nearly all of them are people answering a question
differently — which is what a self-assessment is *for*. A report that treated
those as failures would be red every time and nobody would read it.

Exactly one shape is a defect: **identical inputs reaching different scores**. No
human judgement explains it, so it is the only thing that blocks a sign-off, and
the command exits non-zero on it so a pipeline notices.

The other two readings are reported and do not block: *judgement* (the assessor
disagreed with the standing register figure) and *no baseline* (a risk born in
v2, with nothing to compare against — which is not the same as a match).

Exercised end to end against the migrated demo tenant, including forcing an
engine disagreement and confirming it is named precisely: *"Both sides scored
3 × 3, and reached 99 and 9."*

## Documentation

**The one-page guide is an acceptance criterion, not a courtesy.** §15 says the
module is done when a risk champion can do five specific things *"without
training beyond a one-page guide"* — so [user-guide.md](user-guide.md) is
structured as those five things and is one page.

[admin-guide.md](admin-guide.md) is everything behind it: the permission
splits and why each exists, business-unit scoping, the three methodology
settings the bank must confirm, the per-tenant settings, the scheduled commands
(**and that they do nothing without a scheduler running**), the deployment notes,
and a symptom-to-cause table.

## What P9 could not do

- **UAT sign-off** needs the bank. The harness, the guides and the runbook are
  what it needs to happen; the sign-off itself is theirs.
- **The training deck** is a client-facing artefact that should be built from
  the guides once UAT has shown which five things people actually get stuck on.
  Writing it before that is guessing.
- **The `.xlsx` verification remains open** — the truth table (P0) and the
  export's group-header spans (P6) are both read off the plan rather than the
  workbook. It is the oldest outstanding item in the programme and the only one
  that has survived every phase. `~/Downloads` is unreadable from this
  environment; the file needs copying into the repo.
