# Cutting DemoFI over to RCSA v2 — operator note

**Decision (RCSA-02, 2026-10-05): cut over and label the legacy module.** This is
the short form of `cutover-runbook.md` for one tenant on the production VPS.
It is a production action: **run by a person, not by a deploy.**

## Why

Two RCSAs are live. The legacy one (Dashboard / Worksheet / Risk Matrix) reads
the enterprise risk register; v2 reads the cycle tables. Users saw two
"Dashboard"s with different numbers. Until a tenant cuts over, the legacy menu
entries are relabelled **Register RCSA (legacy)**, **Legacy worksheet**,
**Legacy risk matrix**, and every legacy screen carries a notice pointing at
RCSA → Cycles / RCSA Dashboard. After cutover those three entries leave the menu.

## Before you run it

1. `FEATURE_RCSA_V2=true` must already be set on the VPS (it is, for this
   release). With the flag **off**, cutover changes nothing in the menu — the
   legacy module is then the only RCSA the install has.
2. Find DemoFI's organisation id. In `/var/www/thirdLine-ERM`:

   ```bash
   php artisan tinker --execute="echo App\Models\Organization::withoutGlobalScopes()->where('name','like','%DemoFI%')->get(['id','name']);"
   ```

   Use the id below as `N`. If two rows come back, stop and ask which one.
3. The legacy module has no tables of its own and `risks` / `controls` are the
   enterprise register — **cutover does not touch them.**

## Run it

```bash
cd /var/www/thirdLine-ERM
php artisan rcsa:cutover --organization=N            # dry run: prints the verdict, changes nothing
php artisan rcsa:cutover --organization=N --commit   # records the cutover
```

If DemoFI has never been migrated, the dry run's reconciliation is the check:
**blockers** (a business unit present in the legacy register and absent from v2)
stop the cutover; **triage items** are shown and do not. To migrate first, see
step 1-3 of `cutover-runbook.md` (`rcsa:migrate-legacy --organization=N --step=migrate --commit`).
Do not use `--force` without writing down why.

Then `php artisan optimize:clear` is not required — the menu reads the tenant
setting on every request — but harmless.

## What it checks

`rcsa:cutover` re-runs `RcsaMigrationReconciler` for the tenant and prints a
verdict. It refuses (exit code 1) while there are blockers. The dry run also
lists exactly what the commit will do.

## What changes (for DemoFI only)

- The three legacy entries disappear from the **RCSA** menu section. The v2
  entries (Universe, Cycles, My Assessments, ORM Review, Action Plans, RCSA
  Dashboard, Export) stay.
- The legacy worksheet **refuses submissions** and its Submit/Save buttons are
  hidden; it says work is filed under RCSA → My Assessments.
- `/risk/rcsa/dashboard`, `/worksheet`, `/matrix`, `/controls` still load by URL
  for the audit cycle (§13), with the "kept for audit history" notice.
- Recorded in `organizations.settings['rcsa']['cutover_at']`. Other tenants are
  untouched.

## Also run: the score repair (RCSA-01)

Lines that hold likelihood, impact and control rating but no stored scores may
exist on the server from before the autosave fix, including on submitted
assessments. Repair them **before** the cutover commit:
`rcsa:recompute-scores --org=N --dry-run`, then the real run, then
`--include-locked` for submitted assessments. The steps, what it changes and
the verification query for "no unscored lines" are in
[`cutover-runbook.md`](cutover-runbook.md#repairing-lines-that-have-answers-but-no-scores-rcsarecompute-scores).

## Check it worked

Sign in as a DemoFI user: the RCSA menu shows no legacy entries; opening
`/risk/rcsa/worksheet` shows the closed message.

## Reverse it (valid for 30 days; a policy, not a lock)

```bash
php artisan rcsa:cutover --organization=N --reverse --commit
```

The legacy entries come back (labelled legacy) and the worksheet accepts
submissions again. It does **not** move work filed in v2 back. To remove
migrated data too: `rcsa:rollback-legacy-migration --organization=N --commit`.
