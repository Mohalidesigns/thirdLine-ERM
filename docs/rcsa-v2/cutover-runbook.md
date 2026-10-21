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

**Run `rcsa:migrate-legacy` as `www-data`** (`sudo -u www-data php artisan rcsa:migrate-legacy …`),
here and on the day. It writes its reports to the `local` disk
(`storage/app/private`, `Storage::disk('local')->put()`), and that disk is
configured with `'throw' => false`: when the running user cannot write there,
`put()` returns `false` and the command still prints "Written to
storage/app/private/…". Run as `deploy` or root it can therefore lose the
report without an error, or leave files owned by that user that php-fpm
(`www-data`) cannot read. `rcsa:parallel-run` writes to the same disk and has
the same rule. `rcsa:cutover` writes no file and needs no special user.

| # | Step | Command | Done when |
|---|---|---|---|
| 1 | Take the inventory | `sudo -u www-data php artisan rcsa:migrate-legacy --organization=N --step=inventory` | You have read the readiness counts and know how many risks have no business unit |
| 2 | Fix what the readiness counts flagged | (data work in the app) | `risks_without_business_unit` and `risks_without_title` are zero, or you have decided each is genuinely unmigratable |
| 3 | Dry-run the migration | `sudo -u www-data php artisan rcsa:migrate-legacy --organization=N --step=migrate` | The counts look right and you have read the exceptions |
| 4 | Agree the exceptions | (a person) | Somebody has signed that each exception is genuinely unmappable rather than a bug |
| 5 | Run the parallel cycle | (in the app) | One full RCSA cycle has run in both modules for a pilot unit, compared line by line — §13 step 5 |

**Do not skip step 5.** It is the only step in §13 that no command can do for
you, and it is the one that finds a scoring disagreement before a regulator does.

---

## On the day

### 1. Migrate

```bash
sudo -u www-data php artisan rcsa:migrate-legacy --organization=N --step=migrate --commit
```

Writes `storage/app/private/rcsa/migration/N/` — the migration report and, if
there were any, the exceptions report. **Keep both.** They are the evidence of
what moved.

Safe to re-run: every row is keyed on a legacy id, so a second run completes
what a first left and changes nothing else.

### 2. Reconcile

```bash
sudo -u www-data php artisan rcsa:migrate-legacy --organization=N --step=reconcile
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
| Legacy entries in the RCSA **menu** (flag on) | Shown, labelled "Register RCSA (legacy)", "Legacy worksheet", "Legacy risk matrix" | **Hidden.** The URLs still work (RCSA-02) |
| Legacy screens' banner (flag on) | "Register-based RCSA kept for audit history" | Same, plus "worksheet closed" on the worksheet |
| Legacy dashboard / worksheet / matrix / controls **screens** | Work | **Still work** — §13 keeps them for at least one audit cycle |
| `risks`, `controls`, `risk_control_mapping` | Read/write | **Unchanged.** Not legacy tables |
| Campaign module | Works | **Unchanged.** Migration copies rcsa campaigns, it does not move them |
| v2 screens | Behind the flag | Behind the flag |

**Nothing is dropped and nothing is truncated.** §13: the regulator may ask.

---

## Repairing lines that have answers but no scores (`rcsa:recompute-scores`)

**Why it exists (RCSA-01).** Before the line save became a locked
compare-and-swap, three autosaves in quick succession (likelihood, impact,
control effectiveness) could each compute from a snapshot holding only their own
field. The three answers were stored; the inherent score, residual score,
residual band and treatment were not. The submission gate read the empty band as
"not above appetite", so an above-appetite risk with no action plan could be
filed. Lines in that state may already exist on the server, including on
assessments that were then submitted. This command repairs them. **Run it as part
of the release that carries the fix, before cutover.**

It recalculates through the same engine the workspace uses, from the answers the
assessor already gave. It never changes an answer, never changes who assessed
the line, and never deletes or rewrites an audit row.

```bash
cd /var/www/thirdLine-ERM
php artisan rcsa:recompute-scores --org=N --dry-run         # 1. what WOULD be repaired; writes nothing
php artisan rcsa:recompute-scores --org=N                   # 2. repair open (unlocked) lines
php artisan rcsa:recompute-scores --org=N --include-locked  # 3. ALSO repair lines of submitted assessments
```

(`--org` is optional; without it every organisation is processed, each in its own
tenant context. Always run the dry run first and read the table: it lists, per
assessment, how many lines are stuck, how many would be repaired and how many
locked lines are skipped.)

**`--include-locked` is REQUIRED to repair lines on already-submitted
assessments.** A submitted assessment's lines are locked, and what was filed is
evidence, so without the flag they are reported as "locked, skipped" and left
exactly as they are. Step 2 therefore leaves a submitted assessment's broken
lines broken. Whoever runs step 3 is deciding that the stored scores of filed
assessments may be corrected on the record; say so to the Head of ORM first.
Submitting an assessment never repairs a locked line either — a returned
assessment's un-flagged lines stay as they were filed, and they no longer block
its resubmission.

**What a repair changes — derived scores only.** Per repaired line:
`inherent_score`, `inherent_level`, `ce_modifier`, `residual_score`,
`residual_level`, `risk_treatment` and `appetite_status`, recomputed from the
likelihood, impact and control rating already on the line. Each of the line's
tracked fields that moves — in practice `residual_score` and `risk_treatment` —
gets a revision (`rcsa_line_revisions`) and an audit-trail row, both with **no
user** (the repair is the system's, not a person's) and the reason
**"Recomputed by rcsa:recompute-scores"**; the line's `version` goes up by one
(so a workspace open on that line sees a conflict and reloads, rather than
overwriting the repair). The command is idempotent: a second run repairs nothing.

A line whose control rating matches nothing in the methodology (an import typo,
say "Partly Achieved") has no residual to compute and is **not** repaired. It
will still show in the verification query below; correct the rating in the
workspace (if the line is open) or raise it with the Head of ORM.

**Verification — the Definition of Done is that this returns 0.** Portable SQL
(MariaDB 10.4 and MySQL 8), run against the application database:

```sql
SELECT COUNT(*) AS unscored_lines
FROM rcsa_assessment_lines
WHERE organization_id = N                       -- drop this line to check every tenant
  AND deleted_at IS NULL
  AND inherent_likelihood IS NOT NULL
  AND inherent_impact IS NOT NULL
  AND control_effectiveness IS NOT NULL
  AND (inherent_score IS NULL OR residual_score IS NULL OR residual_level IS NULL);
```

To see which lines are left (and whether they are locked, i.e. whether step 3
is still outstanding):

```sql
SELECT id, assessment_id, risk_no, control_effectiveness, locked_at
FROM rcsa_assessment_lines
WHERE organization_id = N
  AND deleted_at IS NULL
  AND inherent_likelihood IS NOT NULL
  AND inherent_impact IS NOT NULL
  AND control_effectiveness IS NOT NULL
  AND (inherent_score IS NULL OR residual_score IS NULL OR residual_level IS NULL)
ORDER BY assessment_id, id;
```

A non-zero count with `locked_at` set means `--include-locked` has not been run;
with `locked_at` NULL and an unrecognised `control_effectiveness`, it is the
typo case above.

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
