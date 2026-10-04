# ADR 0022 — A bare `timestamp()` column rewrites itself on every update, and seventeen of them do

**Status:** Accepted · **Date:** 2026-09-23 · **Phase:** cross-module defect family (found BCMS Phase 10; also ERM workflow, platform config bundles, TPRM, the LLM ledger, BCMS 2C/3/5/7) · **Author:** architect
**Requested by:** Phase 10 backend-engineer (`awareness_at`), via the coordinator
**Consumers:** backend-engineer **(lead — one migration, one meta-test, five factories, one standard entry)** · qa-engineer · code-reviewer · reliability-engineer (production read-only checks, deploy sequencing) · compliance-analyst (evidence integrity: activation times, the incident clock, maturity dates) · TPRM track · ERM workflow · platform (config bundles, LLM usage ledger) · BCMS Phases 2C, 3, 5, 7, 10

## Context

### The mechanism

When `explicit_defaults_for_timestamp` is OFF, MariaDB (and MySQL before 8.0.2)
gives **the first `TIMESTAMP NOT NULL` column in a table that has no explicit
default** an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
Laravel's bare `$table->timestamp('x')` emits exactly `x TIMESTAMP NOT NULL`, so
it gets that shape. After that, **any UPDATE that changes any other column on the
row overwrites `x` with the server's current time**. The UPDATE does not name
`x`, and nothing warns.

I reproduced it on this machine's server (10.4.28-MariaDB,
`@@explicit_defaults_for_timestamp = 0`). I created a scratch table with a bare
`TIMESTAMP NOT NULL`, inserted `2026-01-01 09:00:00`, and updated an unrelated
column. The value became `2026-09-23 17:19:22`. After `MODIFY … DATETIME NOT
NULL` the value stayed put under the same update, and a second `MODIFY` on the
already-converted column was a harmless no-op.

Three properties explain why nothing caught it:

1. **It is not an error.** No query fails, and no test that only checks a write
   succeeded can see it.
2. **Eloquent cannot see it either.** The rewrite happens inside the server, so
   the in-memory model still holds the old value after `save()`.
   `getChanges()` does not include the column, so `BcmsAuditable` and
   `TprmAuditable` never record the change in an audit row. A test that asserts
   on the model it just saved passes. Only a fresh read shows the new value.
3. **The rewritten value is also in the wrong time zone.** The application
   writes UTC strings (`config/app.php` timezone `UTC`). `config/database.php`
   sets no connection `timezone`, so the session runs on the server's `SYSTEM`
   zone (WAT on this machine), and `CURRENT_TIMESTAMP` writes local time. A
   rewritten value is therefore both the wrong moment and one offset ahead of
   UTC. That is the "`finished_at` roughly an hour BEFORE `started_at`" that
   Phase 2C recorded in `2026_09_17_120001_create_bcms_identity_tables.php:90-107`.

### This is at least the fourth time it has been found, and the first sweep

`26a7697` had already fixed the same defect twice, before either of the two
BCMS occurrences below:

- **`measure_breaches.breached_at`**, in
  `2026_08_12_120010_stop_mysql_resetting_breached_at.php`, with
  `MeasureBreachTimestampTest` guarding that one column and a data repair for
  rows the implicit `ON UPDATE` had already reset.
- **`workflow_instances.started_at`**, in
  `2026_08_14_120001_upgrade_workflow_engine_v2.php:131-141`. That same
  migration also altered `workflow_actions` (`upgradeActions()`, adding
  `node_code` and `task_id`) and left `workflow_actions.acted_at` — row 1 in
  the table below — bare; it is the one column of the 17 that has reached
  production.

Then:

- **Phase 2C (committed `c7fdee0`, 2026-09-22)** found it a third time, on
  `bcms_identity_sync_runs.started_at`, fixed that column in its own
  uncommitted migration, and wrote a clear comment. Nobody checked any other
  table.
- **Phase 10 (uncommitted)** found it a fourth time, on
  `bcms_incident_notifications.awareness_at`, the fact ADR 0020's 72-hour
  regulator clock is computed from. It was fixed the same way, and five other
  columns were flagged without being checked.

Four engineers, independently, fixing the one column in front of them —
twice in the same commit that had every opportunity to check its sibling
table — is the pattern this ADR exists to end. This ADR checks every column,
and a guard makes the next one fail CI instead of relying on someone to
notice.

### Why CI did not catch it

The old `main` test configuration (local ref `4c51f90`) used SQLite, which has
no such behaviour. Its `mysql:8.0` CI leg defaults
`explicit_defaults_for_timestamp` to ON, so there the columns come out as a
plain `NOT NULL` with no default and no `ON UPDATE`. **Both legs were blind to
it**, which is the same concealment CLAUDE.md describes for the MariaDB gap.
`origin/main` @ `6e86879` and this branch now run MariaDB 10.4 in both
`phpunit.xml` and `ci.yml`. The behaviour is present in the test database, but
no test asserts that an event time survives an unrelated update, so it is still
invisible.

### What production runs

Production is MariaDB 10.4, per CLAUDE.md. **Its `explicit_defaults_for_timestamp`
setting has not been observed and is unknown.** It is a global the application
does not set, and a shared host may set it either way. MariaDB 10.4's shipped
default is OFF (the default changed to ON in 10.10). Assume OFF until someone
reads it: `SELECT @@explicit_defaults_for_timestamp, @@version, @@time_zone,
@@system_time_zone;` (read-only, user action). This ADR's decision is the same
under either value (§Decision 2).

### `timestampTz()` is not a remedy

On MySQL and MariaDB, Laravel maps `timestampTz()` to plain `TIMESTAMP`
(`MySqlGrammar::typeTimestampTz()` returns `typeTimestamp()`). A non-nullable
`timestampTz('x')` has the identical defect. The rule below does not offer it
as an alternative.

## The sweep

**Method.** A static grep of `database/migrations/*.php` for `->timestamp(` /
`->timestampTz(` without `nullable`, `useCurrent` or `default(` found 15 hits,
plus the dynamic helper in `2026_02_22_200038_align_schema_with_controllers.php:161`.
Every caller of that helper passes `nullable => true`. There are no
`timestampTz` or `useCurrentOnUpdate` uses anywhere.

The live schema is the authority, not the grep. A grep cannot see multi-line
chains, and it cannot see ALTERs that add a column to a table with no earlier
`NOT NULL` timestamp. So I ran `migrate:fresh` into `risk_test_tsaudit_b9babae`
and read `information_schema.COLUMNS`. **Exactly 15 columns carry `ON UPDATE
current_timestamp()`, the same 15 the grep found.** No non-`TIMESTAMP` column
carries `ON UPDATE`. No table has a second bare `NOT NULL` timestamp, which
would have received a zero-date default instead. Comparing with the developer
database `risk` turns up **2 more**: columns whose migrations were fixed in
place after `risk` had already run them.

**Not affected:**
- All 525 `created_at`/`updated_at` columns from `timestamps()` are nullable,
  with `DEFAULT NULL` and no `ON UPDATE`.
- The only non-nullable `created_at` columns are `jobs` and `job_batches` (both
  `int`) and `llm_usage_events` (listed below).
- The five `useCurrent()` columns carry `DEFAULT current_timestamp()` and no
  `ON UPDATE`: `approval_requests.requested_at`, `failed_jobs.failed_at`,
  `issue_escalation_log.escalated_at`, `loss_event_approvals.actioned_at`,
  `risk_audit_trail.changed_at`.

**Production exposure.** Production's deploy stopped at
`2026_08_09_100004_make_risk_audit_trail_append_only`, and 117 migrations after
it never ran. Of the files below, only `2026_03_26_000005` exists in production.

**The list.** "Update path" means code that UPDATEs the row today without
writing this column. That is what triggers the rewrite. Rows 1–15 are committed,
unchanged since first commit, and on `origin/main`.

| # | table.column | Migration (line) | First commit | Prod has it | Update path today | What the column means, and what a rewrite does | Recovery source |
|---|---|---|---|---|---|---|---|
| 1 | `workflow_actions.acted_at` | `2026_03_26_000005_create_workflow_tables` (52) | `5494aa0` | **yes** | none (an immutable action log, create-only) | When an approval or workflow step was acted on. A rewrite would re-date the approval history. **Latent.** | `created_at` (28/28 rows equal in `risk`) |
| 2 | `config_bundle_applications.applied_at` | `2026_08_13_120004_create_config_bundle_tables` (103) | `26a7697` | no | `ConfigurationImporter:164`: rollback stamps `rolled_back_by_application_id` on the original row | When a configuration bundle was applied. **Active:** every rolled-back application is re-dated to its rollback. | `created_at` |
| 3 | `tp_inherent_assessments.assessed_at` | `2026_09_06_110002_create_tprm_engagement_tables` (244) | `726b64e` | no | `TieringService:88`: sets `is_current = false` on supersession | When a vendor was tiered. **Active:** every historical tiering is re-dated to the moment it was superseded, so the tiering history collapses onto its supersession dates. | `created_at` (5/5 equal; `TieringService:102` writes `now()`) |
| 4 | `tp_screening_checks.run_at` | `2026_09_06_110003_create_tprm_due_diligence_tables` (114) | `726b64e` | no | none found | When a sanctions or PEP screen ran. A rewrite makes a stale screen look fresh. **Latent.** | `created_at` |
| 5 | `tp_findings.identified_at` | `2026_09_06_110007_create_tprm_finding_tables` (58) | `726b64e` | no | `FindingService:131,155,192` (status, closure, evidence); `ErmBridge:96,130,155` (ERM sync) | When the finding was raised. It drives ageing, SLA and overdue. **Active:** every status transition and every ERM sync resets the finding's age to zero. | `tp_audit_logs`, the `created` row's `after.identified_at`. `Finding` is `TprmAuditable` and the value is assigned explicitly. **Not `created_at`**: `identified_at` may be back-dated (`FindingRaiser:101`, `FindingService:75`) |
| 6 | `tp_monitoring_signals.observed_at` | `2026_09_06_110008_create_tprm_monitoring_tables` (86) | `726b64e` | no | `AlertEngine:77`: sets `is_processed = true` | When the external event was observed. The `recent` scope and scoring decay read it. **Active:** every processed signal is re-dated to processing time, so old news scores as new. | **Not recoverable** where the source supplied `observed_at` (it is not `ingested_at` and the model is not audited). Internal signals: approximately `ingested_at` |
| 7 | `tp_concentration_analyses.run_at` | same file (206) | `726b64e` | no | none; `static::updating` throws | When a concentration snapshot was taken. **Latent** (query-builder updates bypass the guard). | `created_at`, except where `$asOf` back-dated it |
| 8 | `tp_portal_invitations.expires_at` | `2026_09_06_110009_create_tprm_portal_governance_tables` (113) | `726b64e` | no | `PortalAuthService:134`: sets `accepted_at` | End of the invitation window. **Active, low harm:** accepted invitations are already excluded by the `valid` scope, but the record of the original window is lost. | `created_at + PortalInvitation::TTL_DAYS` (14), if the TTL has never changed |
| 9 | `tp_assessment_delegations.delegated_at` | `2026_09_08_120003_add_tprm_portal_collaboration_tables` (43) | `160f459` | no | the only path (`PortalAssessmentService:199`, `updateOrCreate`) writes it explicitly | When a portal section was delegated. **Latent.** | none needed |
| 10 | `bcms_plan_activations.activated_at` | `2026_09_09_120002_create_bcms_strategy_plan_tables` (151) | `b0f8664` | no | `PlanActivationService:76` (deactivate); `IncidentService:416` (keep-active link) | When a continuity plan was invoked. It is ISO 22301 8.4 evidence, and elapsed-time and RTO comparisons read it. **Active:** deactivation rewrites the activation time to the deactivation time, so the invocation appears to have lasted zero minutes, or minus one offset. | `created_at` (`PlanActivationService:65` writes `now()`); seeded back-dated rows excepted |
| 11 | `bcms_exercise_timeline.logged_at` | `2026_09_09_120003_create_bcms_exercise_engine_tables` (400) | `b0f8664` | no | none found (append-only in practice) | The exercise chronology. **Latent**, but the value is user-supplied (`TimelineService:34`), so a rewrite could not be undone. | none if it ever fires |
| 12 | `bcms_incident_log.logged_at` | `2026_09_09_120005_create_bcms_incident_dr_training_tables` (82) | `b0f8664` | no | none found (supersession appends a new row) | The incident chronology an examiner reads. **Latent**, same caveat. | none if it ever fires |
| 13 | `bcms_maturity_assessments.assessed_at` | `2026_09_10_120001_create_bcms_governance_tables` (187) | `b367086` | no | `MaturityService:110` updates `overall_score` **in the same call that creates the row** | The date of the programme maturity assessment. **Active on every row ever written:** `risk` shows 5/5 rows at +60 min against `created_at`. | `created_at`; `bcms_audit_logs` `created` row |
| 14 | `bcms_plan_attestations.attested_at` | same file (247) | `b367086` | no | both paths (`updateOrCreate`) write it explicitly | The signature time of a board or policy attestation. **Latent.** | `created_at` for a first attestation |
| 15 | `llm_usage_events.created_at` | `2026_09_17_120001_create_llm_usage_events_table` (73) | `68ac841` | no | none (create-only; pruning deletes) | The ledger time. Retention pruning and budget-period attribution read it (ADR 0015). **Latent.** | none needed |
| 16 | `bcms_identity_sync_runs.started_at` | `2026_09_17_120001_create_bcms_identity_tables` (108) | `c7fdee0`, committed already as `dateTime` | no; not on `origin/main` | finishing a run | **Fixed in place before commit.** `risk` (batch 3) ran the earlier draft and still has `TIMESTAMP … ON UPDATE`, and shows `started_at` one hour after `finished_at`. | `created_at` (±1 s); `bcms_audit_logs` `created` row |
| 17 | `bcms_incident_notifications.awareness_at` | `2026_09_19_120001_bcms_phase10_incident_notifications_and_pir` (103) | **uncommitted**, now `dateTime` | no; not on `origin/main` | recording a submission. `NotificationService:264` also **copies** the prior notification's `awareness_at` into each follow-up, so a rewritten value spreads down the chain | Start of the 72-hour regulator clock (ADR 0020). **Fixed in place.** `risk` (batch 4) and 18 `risk_test_*` copies still have `TIMESTAMP … ON UPDATE`. In `risk`, rows 13 and 14 show `awareness_at` = `submitted_at` + 1 h: the clock starts after the notification was sent. | `bcms_audit_logs` `created` row in principle, **but `risk` holds none for this model**. Otherwise `NotificationService::defaultAwarenessAt()`, which is wrong wherever a user supplied the value |

**Seven are active today** (2, 3, 5, 6, 8, 10, 13), plus 16 and 17 on databases
that ran the earlier drafts. **Eight are latent**: correct only because no
current code path updates the row without writing the column, which is the kind
of safety the next feature removes.

## Decision

1. **One new forward migration.** It sorts after `2026_09_19_120001`, for example
   `2026_09_23_120001_convert_self_updating_timestamp_columns_to_datetime.php`.
   It converts all 17 columns to `DATETIME NOT NULL` with no default. **No
   committed migration is edited.** The in-place fixes to 16 (already committed
   that way) and 17 (uncommitted, so legitimately editable) stay as they are.

2. **It is idempotent and converges every environment, including the two
   fixed-in-place columns.** Answering the coordinator's question: **yes, 16 and
   17 are in the list.** Laravel never re-runs a migration by name. `risk` and
   every test database that migrated before the in-place fixes would otherwise
   keep the defect permanently, and would fail the guard in point 5. For each
   `(table, column)`:
   - skip if the driver is not `mysql`/`mariadb`;
   - skip if the table or column does not exist. On `origin/main`, 16 and 17 do
     not exist yet, which is what lets this file land on `main` alone (§Sequencing);
   - read `DATA_TYPE` from `information_schema.COLUMNS` for the current schema.
     If it is `timestamp`, run
     `ALTER TABLE <t> MODIFY <c> DATETIME NOT NULL`. Otherwise skip.

   The trigger is **`DATA_TYPE = 'timestamp'`, not the presence of `ON
   UPDATE`**. On a server with `explicit_defaults_for_timestamp` ON (MySQL 8,
   or production if its host set it), the columns have no `ON UPDATE` today, but
   they must still end in the same shape everywhere. One schema, whatever the
   server variable says.

3. **DDL.** Use raw `ALTER TABLE … MODIFY … DATETIME NOT NULL` through
   `DB::statement`, not `->change()`, so the migration's effect can be read
   without knowing the grammar. The statement is valid on MariaDB 10.4 and on
   MySQL 5.7 and 8. None of the 17 has a column comment. `MODIFY` keeps their
   indexes, and precision is `(0)` on both sides. Each ALTER is a table copy.
   That is trivial on the empty tables production will create, and brief on
   `workflow_actions`.
   - **Values are preserved as the application reads them.** `TIMESTAMP` to
     `DATETIME` converts through the session time zone. The migration runs on
     the application's own connection, under the same `SYSTEM` zone the
     application wrote and reads in, so every value the application reads is
     unchanged. The scratch probe confirmed this. If a production server zone
     observes DST, values in the skipped hour were already altered at write
     time; this migration neither causes nor repairs that. WAT has no DST.
   - **SQLite (the stale local `main` ref, and anyone running tests on
     SQLite):** no-op, by the driver guard. SQLite has no `ON UPDATE` and no
     real TIMESTAMP type, and `->change()` there would rebuild 17 tables with
     foreign keys to fix nothing. `origin/main` itself tests on MariaDB 10.4.
   - **`down()` is a documented no-op.** The previous shape is the defect.
     A rollback that restores `ON UPDATE CURRENT_TIMESTAMP` would re-arm the
     rewrite, and a migration should not do that quietly. (This departs from
     ADR 0014, where `down()` restored a harmless shape.)

4. **No default, deliberately.** Every production insert path writes the column
   explicitly; the "Update path" column above was traced from those same
   writes. So after conversion, a path that omits the column fails loudly with
   `1364 Field doesn't have a default value` under `STRICT_TRANS_TABLES`,
   instead of taking a server-local `CURRENT_TIMESTAMP` that is in a different
   zone from everything the application writes.
   **Five factories omit the column and must be fixed in the same commit:**
   `database/factories/Bcms/{PlanActivation,TimelineEntry,IncidentLogEntry,MaturityAssessment,PlanAttestation}Factory.php`.
   Seeders are checked by `migrate:fresh --seed` on MariaDB.

5. **The guard.** Add `tests/Feature/NoSelfUpdatingTimestampColumnsTest.php` and
   put it in `DEVELOPMENT_STANDARD.md` §11's load-bearing list. It is skipped
   unless the driver is `mysql`/`mariadb`. Across every table in the test
   database it fails on:
   - (a) any column whose `EXTRA` contains `on update`. The allowlist is
     **empty** today, and an entry must cite an ADR;
   - (b) any `DATA_TYPE = 'timestamp' AND IS_NULLABLE = 'NO'` column whose
     default is not `current_timestamp()`. This catches the same declaration on
     a server where `explicit_defaults_for_timestamp` is ON (default `NULL`) and
     the non-first-column case (a zero-date default).

   Together, (a) and (b) mean that the only permitted `TIMESTAMP` shapes are
   *nullable* and *`useCurrent()` without on-update*, whatever the server
   setting. **It must not be able to pass by matching nothing.** It asserts that
   the scan saw a non-trivial number of timestamp columns. It also runs a
   control: `CREATE TEMPORARY TABLE` (a temporary table avoids the implicit
   commit that would break `RefreshDatabase`) holding a bare `TIMESTAMP NOT
   NULL`, and asserts the detector flags it. Temporary tables are not reliably
   listed in `information_schema` on 10.4, so the detector should read
   `SHOW FULL COLUMNS FROM <table>`, which works for both.

6. **Standing rule.** Add a new `DEVELOPMENT_STANDARD.md` entry, landed in the
   same commit:

   > **A `timestamp()` column is `->nullable()` or `->useCurrent()`, never bare.**
   > An event time the application writes (`*_at` that is not `created_at`/`updated_at`)
   > is `dateTime()`. `timestampTz()` is not an alternative: on MySQL/MariaDB it
   > is the same type. `useCurrentOnUpdate()` needs an ADR. Guarded by
   > `NoSelfUpdatingTimestampColumnsTest`. See ADR 0022.

7. **No data is rewritten by the migration.** It changes a type, not business
   data. Recovery is a separate, per-environment question:
   - **Production:** only row 1 exists there, and it has no update path. The
     expectation is zero damage. It must be verified, not assumed. Run these
     two read-only queries before the fix deploys and expect `0` from both:
     - `SELECT COUNT(*) FROM workflow_actions WHERE ABS(TIMESTAMPDIFF(SECOND, acted_at, created_at)) > 2;`
       A non-zero count means something outside the application updated those
       rows. `created_at` then restores the value, because `WorkflowEngine:1244`,
       `WorkflowController:356` and the `2026_08_14_120004` backfill all write
       `acted_at = now()` at insert.
     - `SELECT COUNT(*) FROM workflow_actions WHERE acted_at < '1970-01-02';`
       A zero-date row (`'0000-00-00 00:00:00'`, which this comparison also
       catches, alongside any other pre-epoch placeholder) makes the `MODIFY …
       DATETIME NOT NULL` ALTER itself fail with `1292 Incorrect datetime
       value` under `STRICT_TRANS_TABLES`, before any row's real value is at
       risk. Of the 17 columns, only `workflow_actions.acted_at` exists in
       production today (§Production exposure); repeat the equivalent query
       for any other of the 17 columns once its table exists there. **If
       either count is non-zero: stop, do not run this migration.** Find the
       offending rows' true `acted_at`, or the nearest recoverable proxy in
       the "Recovery source" column, backfill them by hand under
       `bypassTenancy()`-style logging, re-run the count, and only proceed
       once it reads `0`. Do not widen the column or weaken the guard to let
       a zero-date row through — see CLAUDE.md on never "fixing" a defect by
       widening a column.
   - **Developer and test databases:** the migration fixes the type but leaves
     the values that were already rewritten (rows 13, 16 and 17 in `risk`).
     Those are demo data: re-seed `risk` after the fix (the user's call). Test
     databases rebuild through `RefreshDatabase`.
   - **Any pilot or customer database that surfaces:** use the "Recovery
     source" column above. Be honest where it fails. `tp_monitoring_signals.observed_at`
     from external sources, and any user-supplied `awareness_at` with no
     `created` audit row, **cannot be recovered**. An audit `updated` row never
     holds the lost value, because the rewrite was invisible to Eloquent
     (Context, property 2).

## Sequencing

**It lands as its own commit, immediately after the Phase 9+10+11 combined
commit, and not inside it.**

- **Keep it out of the combined commit.** That commit is BCMS-only, Phase 9 has
  passed both gates, and 10 and 11 are in review. This migration changes ERM
  workflow, platform config bundles, TPRM and the LLM ledger. Folding it in
  would re-open gates that have passed on scope they never reviewed. It would
  also tie a cross-module schema fix to three BCMS phases, so reverting either
  would revert the other. Phase 10's own in-place `dateTime` fix to
  `awareness_at` **does** stay in the combined commit, because it is that
  phase's uncommitted migration.
- **It must not wait for the final PR.** The planned order repairs production's
  database privilege and **re-runs the deploy of current `origin/main` before**
  the one branch PR. That deploy would create 13 of these 17 columns' tables in
  their defective shape and start serving traffic, and TPRM status changes,
  signal processing, plan deactivations and config rollbacks would begin
  rewriting production data until the PR arrived. The migration is guarded by
  table and column existence, so it applies cleanly to `origin/main` alone.
  **Recommendation: carry this one commit to `main` as a small PR before the
  production deploy is re-run.** That puts the 117 pending migrations and this
  fix in the same `migrate`, before `deploy.sh` restarts php-fpm, and production
  never serves a request against the defective shape. The alternative is
  acceptable: hold the deploy re-run until the one PR. Re-running the deploy
  now and fixing later is **not** acceptable. Choosing between a hotfix PR and
  "one PR at the end" is the user's decision, because it changes the agreed
  single-PR plan.
- **Schema freeze.** This is a structural migration after the freeze, and this
  ADR is its authority. It is approved on the freeze's own test, not because it
  is small: **no track has built against the shape being removed.** No code
  depends on the implicit default or the self-update; both were accidents.
  Column names and Eloquent `datetime` casts are unchanged, and
  `database/schema/bcms-manifest.php` records names only, so it shows no diff.
  No consumer rebuilds.

## Alternatives rejected

- **Edit the 13 committed migrations.** They have run on `risk`, on every test
  database, and in production (row 1). An edit reaches only fresh databases, so
  environments diverge silently.
- **Set `explicit_defaults_for_timestamp = ON` on the server.** Production is a
  hosted MariaDB whose global settings the application does not control. More
  decisively, `ON UPDATE` is **persisted in the table definition** at creation,
  so changing the variable later removes it from no existing column. It would
  also leave correctness depending on a setting nobody can see from the
  repository.
- **Keep `TIMESTAMP` with an explicit `DEFAULT CURRENT_TIMESTAMP` (no on-update).**
  This stops the rewrite but keeps a database-side default that writes
  server-local time into columns the application fills with UTC. It also keeps
  session-zone conversion and the 2038 ceiling. None of the 17 needs `TIMESTAMP`
  semantics.
- **Make them nullable.** This also stops the rewrite, but gives up a true
  invariant: a plan activation without an activation time is not a record.
- **A static scan of the migration files as the guard.** History cannot change,
  so the scan needs a permanent allowlist of 13 files, and it cannot see what an
  ALTER did to an existing table. The live schema is the fact; the scan would
  only be an opinion about it.
- **Repair data inside the migration.** That means heuristic rewrites of
  business data inside a schema change, and the only production table has
  nothing to repair. Recovery is a data decision made per environment, not a
  side effect of `migrate`.

## What this ADR deliberately does not do

- It does not touch the five `useCurrent()` columns (for example
  `risk_audit_trail.changed_at`). They do not rewrite themselves. Whether a
  database-side `CURRENT_TIMESTAMP` default should write server-local time is a
  real question about the connection's time zone, and it is **not** decided here.
- It does not set a connection `timezone` in `config/database.php`. That would
  change how every existing `TIMESTAMP` column in the product is read, and it
  needs its own ADR.
- It does not touch any nullable `timestamp` column, including the 525
  `created_at`/`updated_at` columns. Nullable columns get `DEFAULT NULL` and no
  `ON UPDATE`.
- It does not change any model, cast, service or screen, apart from the five
  factories that must now supply a value.
- It does not re-seed or repair `risk`, and it builds no recovery command.

## Consequences

- The seven active rewrites stop, and the eight latent ones can no longer fire.
- Any future bare `timestamp()` fails CI on MariaDB, and on MySQL 8 as well.
- An insert that relies on the implicit default fails loudly. That is intended,
  and the suite finds the ones the factory fix misses.
- `down()` does not restore the old shape.
- Rows already rewritten in `risk` stay wrong until re-seeded. Anywhere else,
  the observed-time signals and user-supplied awareness times with no `created`
  audit row are lost for good.
