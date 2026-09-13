# RCSA v2 — cutover runbook

The operational procedure for moving one tenant off the legacy RCSA module.
Written for whoever runs it at 07:00 on a Saturday, not for whoever built it.

**Read this first:** the legacy RCSA module **has no tables of its own**. It is
four screens computed over `risks`, `controls` and `risk_control_mapping`, plus
one write path that files worksheet submissions into `campaign_responses`. That
single fact changes three things you might otherwise expect:

- There is no legacy schema to move. Master data is **derived** from the
  enterprise risk register.
- `risks` and `controls` **must not** be made read-only. They are the enterprise
  register — the Risk Register, KRI, the control library and the board pack all
  read them. Locking them takes half the product down.
- What actually closes at cutover is **one controller action**:
  `risk.rcsa.worksheet.store`.

---

## Before the day

| # | Step | Command | Done when |
|---|---|---|---|
| 1 | Take the inventory | `php artisan rcsa:migrate-legacy --organization=N --step=inventory` | You have read the readiness counts and know how many risks have no business unit |
| 2 | Fix what the readiness counts flagged | (data work in the app) | `risks_without_business_unit` and `risks_without_title` are zero, or you have decided each is genuinely unmigratable |
| 3 | Dry-run the migration | `php artisan rcsa:migrate-legacy --organization=N --step=migrate` | The counts look right and you have read the exceptions |
| 4 | Agree the exceptions | (a person) | Somebody has signed that each exception is genuinely unmappable rather than a bug |
| 5 | Run the parallel cycle | (in the app) | One full RCSA cycle has run in both modules for a pilot unit, compared line by line — §13 step 5 |

**Do not skip step 5.** It is the only step in §13 that no command can do for
you, and it is the one that finds a scoring disagreement before a regulator does.

---

## On the day

### 1. Migrate

```bash
php artisan rcsa:migrate-legacy --organization=N --step=migrate --commit
```

Writes `storage/app/private/rcsa/migration/N/` — the migration report and, if
there were any, the exceptions report. **Keep both.** They are the evidence of
what moved.

Safe to re-run: every row is keyed on a legacy id, so a second run completes
what a first left and changes nothing else.

### 2. Reconcile

```bash
php artisan rcsa:migrate-legacy --organization=N --step=reconcile
```

Read the verdict:

- **Blockers** stop cutover. Each one is a whole business unit present in the
  legacy register and absent from v2 — that is a migration that skipped a unit,
  not a rounding difference.
- **Triage items** do not stop cutover but need a person. A legacy response
  whose risk was deleted years ago can never be migrated by anybody.
- **Row counts and the residual distribution are expected to differ.** The
  universe deduplicates, and the figures are recomputed by the v2 engine from
  the migrated inputs rather than copied. The report says so; make sure whoever
  signs has read that sentence.

### 3. Cut over

```bash
php artisan rcsa:cutover --organization=N            # dry run, shows the verdict
php artisan rcsa:cutover --organization=N --commit
```

The command re-runs the reconciliation and **refuses** if there are blockers.
`--force` exists for the case where you know something the code does not —
record why in the change record when you use it.

Recorded as `organizations.settings['rcsa']['cutover_at']`. **Per tenant**, not
per environment: a shared install cuts its tenants over one at a time.

### 4. Turn the flag on

`FEATURE_RCSA_V2=true` in the environment, then `php artisan config:clear`.

Per **environment**, and a different decision from cutover: the flag says the
module exists here, cutover says this bank has stopped using the old one.

### 5. Tell people

The unit heads and anybody holding `rcsa.submit`. After cutover the old
worksheet refuses submissions with a message pointing at RCSA → My Assessments,
but somebody arriving at a refused form is somebody who did not get the email.

---

## What changes at cutover, exactly

| | Before | After |
|---|---|---|
| Legacy worksheet **submit** | Works | **Refuses**, with a message naming where work goes now |
| Legacy dashboard / worksheet / matrix / controls **screens** | Work | **Still work** — §13 keeps them for at least one audit cycle |
| `risks`, `controls`, `risk_control_mapping` | Read/write | **Unchanged.** Not legacy tables |
| Campaign module | Works | **Unchanged.** Migration copies rcsa campaigns, it does not move them |
| v2 screens | Behind the flag | Behind the flag |

**Nothing is dropped and nothing is truncated.** §13: the regulator may ask.

---

## Rollback

Valid for **30 days**, and the window is a policy rather than a lock — past it
the commands still run, but the assumption behind the window (that nobody has
built on the migrated data yet) stops holding.

### Re-open the legacy module

```bash
php artisan rcsa:cutover --organization=N --reverse --commit
```

The legacy worksheet accepts submissions again. **This does not move work back**
— anything filed in v2 since cutover stays in v2.

### Remove the migrated data as well

```bash
php artisan rcsa:rollback-legacy-migration --organization=N            # dry run
php artisan rcsa:rollback-legacy-migration --organization=N --commit
```

Removes **only** rows the migration created — every one is selected by a
non-null legacy id. A universe risk somebody typed in, a cycle somebody opened,
an assessment a unit is filling in: all have null legacy ids and none are
reachable from here.

**It keeps a migrated risk that a real cycle has since assessed**, and says how
many. Deleting those would take a live assessment's provenance with them. That
is the difference between a rollback and a truncation.

Then turn `FEATURE_RCSA_V2` back off if this was the only tenant using it.

---

## If something goes wrong mid-migration

The migration runs in **one transaction per tenant**. A failure rolls the whole
tenant back — there is no half-migrated state to reason about. Re-run it.

If it fails repeatedly, run the inventory again: the readiness counts are where
a data problem shows up, and the exceptions report names the specific rows.

---

## Retention

Per §13 step 6, keep for **at least one audit cycle** after cutover:

- the legacy screens (routable, read-only in practice);
- `campaign_responses` under `rcsa` campaigns — the legacy module's own data;
- `storage/app/private/rcsa/migration/N/` — every report from the day.

Do not drop anything. The question a regulator asks is "what did the old system
say", and the only acceptable answer is to show them.
