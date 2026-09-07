# P8 — migration and cutover

Section 13. Getting the bank off the old module without losing anything, and
being able to undo it.

## What landed

| Piece | Where |
|---|---|
| Inventory | `app/Services/Rcsa/RcsaLegacyInventory.php` |
| The split | `app/Services/Rcsa/RcsaLegacyMigrator.php` |
| Reconciliation | `app/Services/Rcsa/RcsaMigrationReconciler.php` |
| The three steps, in order | `rcsa:migrate-legacy` (`--step`, `--commit`) |
| Cutover state | `app/Support/Rcsa/RcsaCutover.php`, `rcsa:cutover` |
| Rollback | `rcsa:rollback-legacy-migration` |
| Provenance | `2026_09_07_100007` — `legacy_*_id` on four tables |
| Runbook | [cutover-runbook.md](cutover-runbook.md) |
| Tests | `LegacyMigrationTest` (17), `CutoverTest` (10) — 27 |

## The finding that reshaped the phase

**The legacy RCSA module has no tables of its own.** It is four screens computed
over `risks`, `controls` and `risk_control_mapping`, plus one write path filing
worksheet submissions into `campaign_responses` under an `rcsa` campaign.

§13 is written as though there were a legacy schema to move. There is not, and
three of its instructions change meaning as a result:

- **"Map and backfill" is a derivation, not a copy.** `rcsa_register_risks` is
  built *from* the enterprise risk register. Nothing leaves `risks`.
- **"Legacy tables become read-only" cannot be applied literally.** `risks` and
  `controls` are the enterprise register — the Risk Register, KRI, the control
  library and the board pack all read them. Locking them would take half the
  product down. What is genuinely legacy, and what actually closes, is
  `risk.rcsa.worksheet.store`: one controller action.
- **"Inventory first" earns its place twice over**, because without it the above
  is invisible. The inventory reports it as its headline finding and marks every
  source table `shared: true|false`.

`the_inventory_reports_that_the_legacy_module_has_no_tables_of_its_own` pins all
of this, including that the dependency list names the write path as the thing
that must refuse.

## The migration

**Provenance came first.** `legacy_risk_id`, `legacy_control_id`,
`legacy_campaign_id` and `legacy_response_id` were added before a line of
migration logic, because the reconciliation, the exceptions report and the
rollback all need to know which v2 row came from which legacy row. `null` means
"born in v2" and is exactly the set the rollback must not touch.

**It is idempotent**, and that is not a nicety: a one-off command that cannot be
run twice is one nobody dares run once. Every write is keyed on a legacy id, so
a second run completes what the first started.

**A dry run is a real run inside a transaction that is rolled back.** Counting
what *would* happen with a second implementation is how a dry run comes to
disagree with the real thing — precisely when somebody is relying on it to
decide whether to commit.

**The figures are recomputed, not copied.** The migration carries the *inputs*
— likelihood, impact, the control rating — and `RcsaCalculationService` derives
the rest, exactly as it would for a line typed in today. Filing legacy numbers
under a v2 methodology would produce a register whose residuals do not follow
from its inputs, and every heat map after that would be reading two engines'
output as one. The consequence is deliberate and reported: a migrated residual
may differ from what the legacy screen showed, which is what the reconciliation's
distribution comparison is *for*.

**Migrated cycles and assessments arrive closed.** History, not work. A closed
cycle makes `acceptsEdits()` false for everything under it, so nothing offers to
edit a figure the bank reported years ago — and it stops the migration being
mistaken for provisioning a live cycle, which is the one way it could do real
damage. Assessments land in `closed` rather than `validated`, because claiming
an ORM review that never happened would be a lie in the audit trail.

**Nothing is silently dropped.** A risk with no business unit, a response whose
risk was deleted, a campaign with no period — each lands in the exceptions
report with its identity and the reason, written to
`storage/app/private/rcsa/migration/N/`.

## The reconciliation

**A reconciliation that only ever says "matched" is decoration.** Two of the
three comparisons are *expected* to differ and the report says so: row counts
because the universe deduplicates by `row_hash`, and the residual distribution
because the figures are recomputed. The one that must match exactly is
**per-business-unit coverage** — a total that matches while the units do not is
the failure a total alone cannot see.

**Blockers and triage are separated**, and that distinction was the last thing
P8 changed. A legacy response whose risk was deleted years ago can never be
migrated by anybody; making it a permanent blocker would produce a verdict that
is always red, which is a verdict every operator learns to override. Blockers
(a whole unit missing) stop cutover; triage items are surfaced for a human to
agree.

## Cutover and rollback

**Cutover is per tenant; the feature flag is per environment.** Different
decisions, so not a shared switch: the flag says the module exists in this
deployment, cutover says a particular bank has finished its parallel run. A
shared install cuts its tenants over one at a time, months apart. It is stored
as a *date* in `organizations.settings['rcsa']['cutover_at']`, beside P7's
BU-approval setting and merged rather than replacing it — because "when did this
bank cut over" is what the retention period, the rollback window and every audit
conversation actually ask.

**`rcsa:cutover` re-runs the reconciliation and refuses on a blocker.** §13 step
4 says sign off before cutover, and a step written only in a runbook is a step
somebody skips at 2am. `--force` exists because an operator may know something
the code does not, and it says loudly what it is overriding.

**The rollback removes only what the migration created, and keeps what has been
built on.** A migrated universe risk that a *real* cycle has since assessed is
left in place and counted, because deleting it would take a live assessment's
provenance with it. That is the difference between a rollback and a truncation.

## What P8 found

**`risk_control_mapping.organization_id` is NULL on every row.**
`RiskControlMapping::using()` stamps it; a pivot written by a plain `attach()`
or a seeder insert does not. The inventory counted through that column and
reported *zero* mappings while the migration, joining through the risk, found
twelve — an inventory that disagrees with the migration it exists to precede is
worse than no inventory. The inventory now counts through the risk and reports
the null-organisation rows as a readiness finding. **The underlying data defect
is not fixed here**; it is in whatever writes the pivot, and it affects any
other consumer that filters on that column.

**`RcsaRegisterControl` has no status constants of its own** — it shares the
register risk's lifecycle. Found by the first real run.

## Carried into P9

- **§13 step 5, the parallel run, is the one step no command can do.** One full
  cycle in both modules for a pilot unit, compared line by line. The runbook
  says not to skip it; P9's UAT is where it actually happens.
- The legacy read screens stay routable indefinitely. Somebody should decide,
  after the retention period, whether they are removed or left — that is a
  business decision with a regulator in it, not a technical one.
- The **truth table and the export's group-header spans are still unverified
  against the `.xlsx`**. That gap has been open since P0 and P6 and is now the
  oldest thing outstanding in the programme.
