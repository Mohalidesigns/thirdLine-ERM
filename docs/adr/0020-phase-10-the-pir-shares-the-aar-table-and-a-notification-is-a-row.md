# ADR 0020 — A post-incident review is an AAR row, and a regulatory notification is a row of its own

**Status:** Accepted · **Date:** 2026-09-13 · **Phase:** BCMS Phase 10 (Incident, crisis & IT DR) · **Author:** architect
**Requested by:** compliance-analyst, `docs/bcms/phase-10-incident-clause-map.md` §4.1, §6 gaps 1 and 2
**Consumers:** ui-designer · backend-engineer · frontend-engineer · reliability-engineer (watchdog) · compliance-analyst · qa-engineer · code-reviewer · Phase 9 (shares `bcms_aars`) · Phase 11 (board pack, CBN pack) · Phase 12

**Amendments (all 2026-09-17, raised by `code-reviewer` at the Phase 10 gate 2, which rejected the phase):**
**1** — §3, DR ingestion authenticates with the product's per-tenant machine token, not a shared HMAC secret; the route moves to `/api/v1`.
**2** — §2, an obligation reassessed as not owed is *withdrawn* on its own row (three columns on the not-yet-shipped table); both clocks default to `detected_at`, never `now()`; a submitted row is immutable.
**3** — §4, a sentence about `JSON_CONTAINS` on MariaDB that was wrong, corrected.
**4** (2026-09-23, code-reviewer defect 6) — a plan kept active past stand-down is one pointer column on `bcms_plan_activations`; the gate runs after the dispositions are written.

## Context

Two blocking gaps, both verified in the schema rather than taken on report.

**Gap 1.** `bcms_aars.occurrence_id` is `foreignId()->unique()->constrained(...)->cascadeOnDelete()`
— **NOT NULL, UNIQUE, with an FK** — and the migration comment calls it "the single
edge between the two tables". So the prompt's design, "a post-incident review is
an AAR with `incident_id` instead of `occurrence_id`", cannot be built. Criteria
1 and 9 are unbuildable as written.

**Gap 2.** `bcms_incidents` carries one `reporting_due_at`, one
`regulator_notified_at` and one `cbn_reference`. The CBN's 24-hour clock and the
NDPA s.40 72-hour clock run **beside** each other, from **different trigger
events**, to **different regulators**, with **different content**; DORA's
initial/intermediate/final structure needs several submissions against one
obligation. One column set cannot hold any of that, and a single "regulator
notified" tick satisfies neither regulator.

Two things already in the schema shape the answer. `bcms_incidents` **already
has `occurrence_id`** — an incident declared inside a crisis simulation — and
`is_exercise`. And the ERM bridge exists one way: `erm_loss_event_id` →
`loss_events`, which carries its own `is_regulatory_reportable`,
`regulatory_body` and `reporting_deadline`. BCMS owns the operational clock; ERM
owns the loss. Neither register writes the other's deadline.

## Decision

### 1. `bcms_aars` holds both kinds. One new column, one altered column.

| | |
|---|---|
| `occurrence_id` | `unsignedBigInteger`, **nullable**. The FK and the **unique index stay.** |
| `incident_id` | new, nullable, unique, FK → `bcms_incidents`, `cascadeOnDelete`. |
| Exactly one of the two | enforced in `AarService` **and** in a `saving` guard on the `Aar` model, which throws. |

**The unique index survives the nullability change and still means what it
meant.** MariaDB treats NULLs as distinct in a unique index, so
`unique(occurrence_id)` continues to enforce one AAR per occurrence while
permitting any number of PIR rows whose `occurrence_id` is null — and
`unique(incident_id)` does the same job from the other side. That is the whole
reason this fits in two columns instead of a table, and it is a MariaDB-specific
fact, so it is written down here rather than assumed by the next person.

**The guard is on the model, not a CHECK constraint.** MariaDB 10.4 would enforce
`CHECK ((occurrence_id IS NULL) <> (incident_id IS NULL))`, and it is still the
wrong instrument: this repository has no CHECK constraints anywhere,
`bcms:verify-schema` compares **columns** so the constraint would be invisible to
the freeze guard that exists to notice exactly this kind of change, and a
violation would surface as a `QueryException` instead of a message. A `saving`
guard catches a service, a seeder and a tinker session alike, which is more
coverage than the service alone and less machinery than raw DDL.

**Rejected: `bcms_post_incident_reviews`.** It would duplicate the whole Phase 9
structure — nine sections, the finalisation gate, the export, the CAPA
linkage — and criterion 9 asks explicitly that a PIR reuse the AAR/CAPA
machinery. Two tables means two finalisation paths, and the second one gets the
lock wrong.

**Rejected: replacing both FKs with a polymorphic `subject_type`/`subject_id`.**
Tempting and worse. It discards both foreign keys and both unique indexes — the
database would no longer prevent two AARs on one occurrence — and it would
require rewriting every existing reader of `occurrence_id` (`ExerciseOccurrence`,
`Finding`, `MaturityService`, the Phase 9 export and gate) for no capability that
two nullable columns do not already give. Two kinds is not "many kinds".

**Clause stamp: a PIR is `iso22320.incident_response`, never
`iso22301.8.5.report`.** The analyst's reason is adopted verbatim because it is a
correctness argument, not a taxonomy preference: 8.5 is the *exercise programme*
record, and stamping real incidents with it would inflate the testing programme
an examiner measures delivery against. The two are separate evidence packs.

**Every existing reader of `Aar` must be audited for the new null arm**, and this
is where the cost of this decision actually lands: `ExerciseOccurrence::aar()`,
`Finding`, `MaturityService` (which scores the exercise programme and **must not
count PIRs**), and Phase 9's export and finalisation gate. Phase 9's
finalisation conditions 5, 6 and the inject condition do not apply to a PIR —
nobody was scoring and reality supplied the injects — and the gate must branch on
which edge is set, not on a guess. Likewise **Phase 5's T+3/T+7 AAR-overdue
rungs stay exercise-only in this phase**: they are anchored on an occurrence, and
a PIR-overdue chaser is Phase 10's own work with its own anchor. Do not widen the
ladder's query to pick up rows with a null occurrence.

### 2. `bcms_incident_notifications` — one new table, and three columns retired

One row per **submission to a regulator**, which is the grain an examiner asks
questions at.

```
bcms_incident_notifications
  id                                   no uuid — a child of the incident, addressed nested
  organization_id                      FK, BelongsToOrganization
  incident_id                          FK bcms_incidents, cascadeOnDelete
  regulator (40)                       cbn | ndpc | other        (enum)
  basis_clause_ref (60)                the IsoClauseRef value the obligation comes from
  kind (20)                            initial | intermediate | final | supplementary
  sequence (unsigned smallint, 1)      supplementary submissions repeat; the others do not
  awareness_at (timestamp)             THE TRIGGER EVENT. Not created_at.
  due_at (timestamp)                   stored, fixed at awareness — see below
  submitted_at (nullable)              null = open obligation
  submitted_by (nullable FK users)
  reference (120, nullable)            the regulator's acknowledgement
  content_snapshot (json, nullable)    what was submitted, as submitted
  created_by, updated_by, timestamps
  unique (incident_id, regulator, kind, sequence)
  index (organization_id, incident_id) · (organization_id, submitted_at, due_at)
```

Four decisions inside that:

1. **The notification row *is* the personal-data-breach flag. No boolean, no
   `ndpc_reference`, no `data_subjects_affected` column.** Classifying an incident
   as involving personal data *is* the act of creating an NDPA obligation row with
   its awareness time, and the screen shows two countdowns because there are two
   rows. This is the same principle ADR 0019 §6 applied to plan and BIA review —
   an obligation is a row, not a boolean — and it makes the analyst's criterion-5
   test fall out naturally: an incident detected yesterday and classified as a
   breach today gets `awareness_at = yesterday`, so the deadline is yesterday +
   72 h and the countdown shows what is left, not a fresh 72 hours. A boolean plus
   a derived deadline would have restarted the clock at classification, which is
   the defect the test exists to catch. The affected-subject count lives in the
   submission's `content_snapshot` and in the PIR's `quantitative_results`, where
   the analyst's incident-metrics list already puts it.
2. **`due_at` is stored, and that is deliberate against the usual rule.** ADR 0013
   argues staleness must be computed; this is the opposite case. A deadline is a
   fact fixed at the moment of awareness: if the configured window is ever
   corrected, every historical deadline must stay exactly where it was, because
   what a bank owed in March is not re-derived in July. Same reasoning as ADR
   0018's `requires_ack`.
3. **The windows are deployment-wide config, not tenant settings.** 24 hours and
   72 hours are law. They go in `config/bcms.php` beside the NFR values, for the
   same reason Phase 7 put the dual-approval thresholds there: a customer must not
   be able to set their own regulatory deadline to something roomier.
4. **The product records a notification as submitted; it never submits one.**
   `bcms.incident.notify`'s own catalogue description already says "Nothing is
   ever submitted automatically", and standing rule 4 says an AI draft reaches no
   regulator without a recorded human action. There is no outbound integration in
   this table and no route that transmits anything.

**`reporting_due_at`, `regulator_notified_at` and `cbn_reference` are retired in
place** — the pattern ADR 0019 §3 set for `evidence_file_id`, applied a second
time. Nothing writes them, the Form Requests stop accepting them, a guard test
asserts no BCMS `create()`/`update()`/`fill()` names them, and they stay in the
manifest until a single later cleanup migration drops them. They are not
denormalised into an "earliest open deadline": the watchdog scan is
`where submitted_at is null order by due_at`, which the second index above
serves, so there is nothing to keep in step. `is_reportable` **stays** — it is
the human judgement that obligations exist at all, and it is not derivable from
the absence of rows somebody has not created yet.

`BcmsWatchdog` gains one check: an open notification past `due_at`, and one
approaching it. An overdue regulatory clock is the most expensive silent failure
in this module.

> **Amendment 2 — 2026-09-17. A reassessed obligation is withdrawn on its own
> row; both clocks start at detection; a submitted row does not change.**
>
> **The defect.** `NotificationService::reassessNotReportable()` already exists
> and writes a decision-log entry — and changes nothing else. So an incident
> classified personal-data "yes" (creating the NDPC row) and reassessed "no"
> keeps an open row for ever: `reportabilityStatus()` returns `yes` whenever
> *any* row exists, checked before the "no" decision is even looked at;
> `overdueOrOpenCount()` counts it, so the stand-down gate — whose own label
> reads "submitted **or reassessed as not owed**" — can never pass; the watchdog
> pages indefinitely; and the only way out is to record a submission that never
> happened. A control whose one exit is a false regulatory record is a control
> that teaches people to write false regulatory records.
>
> **Decision: three columns on `bcms_incident_notifications`.**
>
> | Column | |
> |---|---|
> | `withdrawn_at` | timestamp, nullable |
> | `withdrawn_by` | FK users, nullable, `nullOnDelete` |
> | `withdrawal_entry_id` | FK `bcms_incident_log`, nullable, `nullOnDelete` — the decision-log entry that carries the reason |
>
> **Why columns, and why these three.**
>
> - **Not a closing row kind.** A `withdrawal` kind would need an `awareness_at`
>   and `due_at` it does not have, and it turns "is this obligation open" into a
>   correlated query — *no submission and no later withdrawal row for the same
>   regulator* — across every watchdog scan and every stand-down check. That is
>   the predicate that is broken today; making it harder to write is the wrong
>   direction. The obligation's state belongs on the obligation's row, where
>   `whereNull('submitted_at')->whereNull('withdrawn_at')` stays one indexed
>   table read.
> - **Not a disposition enum.** Open, submitted and withdrawn are already fully
>   determined by which timestamp is set. A stored enum beside them is a second
>   copy of that state which can disagree with it — ADR 0013's argument.
> - **A pointer to the reason, not a `withdrawal_reason` text column.** An
>   incident's decisions already have a mandated home and shape — the ISO 22320
>   decision log, with `options_considered` and `rationale` — and
>   `reassessNotReportable()` already writes one. A free-text column beside it
>   would be two answers to "why was this not reported", and an examiner who
>   finds them disagreeing has found the one inconsistency that matters. The
>   withdrawal and its log entry are written in one transaction.
> - **`nullOnDelete` on both FKs, deliberately.** Deleting an incident cascades
>   to its log entries and its notifications as siblings, and MariaDB does not
>   promise an order; a `RESTRICT` from notification to log entry fails that
>   cascade depending on which sibling goes first. Nothing in the module deletes
>   a log entry on its own — entries are superseded, never removed — so the null
>   arm is only reachable when the whole incident goes.
>
> **The rules that go with them:**
>
> 1. **Only an unsubmitted row can be withdrawn.** Once the regulator has been
>    told, it cannot be untold. A later conclusion that the incident was not
>    reportable after all is itself a `supplementary` (or `final`) submission
>    saying so — the regulator hears it from the bank, rather than the bank's
>    own system quietly closing the matter.
> 2. **Withdrawal requires `bcms.incident.notify`**, the same authority that
>    records a submission, which the catalogue already places with the CRO.
>    Deciding *not* to notify a regulator is the most consequential call in an
>    incident, and it is not a lower authority than deciding to.
> 3. **Every open-obligation read excludes withdrawn rows**:
>    `overdueOrOpenCount()`, `overdueQuery()`, `approachingDueQuery()`, and
>    therefore the stand-down gate and the watchdog. `reportabilityStatus()`
>    reads: a live row → `yes`; only withdrawn rows → `no`; else the existing
>    decision-log check; else `unknown`.
> 4. **Re-classifying after a withdrawal opens a new `initial` row** (next
>    `sequence`), and `classify()` must stop returning the withdrawn row as
>    "the one that already exists". The new row's `awareness_at` defaults to
>    **the withdrawn row's `awareness_at`**, not to the moment of
>    re-classification. That is the conservative reading — the bank was aware of
>    the facts from the first classification — and moving it later is rule 6's
>    case, not a default.
> 5. **The audit event is `incident.notification_withdrawn`** (32 characters,
>    inside ADR 0014's 60).
>
> **What the evidence pack shows for a withdrawn obligation — all of it, never
> filtered out.** Regulator, basis clause, `awareness_at`, `due_at`, who opened
> it and when, `withdrawn_at`, `withdrawn_by`, and the decision-log entry's
> rationale and options considered. **Plus whether the withdrawal came before or
> after `due_at`** — a comparison of two stored timestamps, not a derived
> figure, and a materially different fact: a withdrawal after the deadline had
> passed means the bank missed the clock and *then* concluded it did not apply,
> which is exactly what an examiner will look for. The pack prints that
> sentence; it does not leave the examiner to do the arithmetic.
>
> **Both clocks default `awareness_at` to the incident's `detected_at`, falling
> back to `declared_at` — never to `now()`.** The reviewer was right that `now()`
> is wrong: it starts the clock when somebody clicked, which on a busy night is
> hours after the fact. But **`declared_at` is not the right default either, and
> this corrects the reviewer's proposal on the evidence.** The compliance
> analyst's clause map §2.1 records the CBN clock as running "within 24 hours of
> the incident occurring", and names detection as its start; `detected_at` is the
> product's closest stored fact to that. `declared_at` is when the bank formally
> *decided* to declare, which the bank controls — a default that let the bank
> buy regulatory time by delaying a decision is the error to design out. The
> analyst's criterion-5 test already assumes `detected_at` for the NDPC clock.
>
> 6. **Awareness is editable, asymmetrically.** Setting it *earlier* than the
>    default needs no justification — it only shortens the bank's own window.
>    Setting it *later* requires a decision-log entry with the reason (for the
>    NDPC, the honest case is "the personal-data element was not known until"),
>    because that is the direction that buys time.
> 7. **If both `detected_at` and `declared_at` are null, classification is
>    refused** with a message to record when the incident was detected. A clock
>    with an invented start is worse than a prompt.
>
> **A second `initial` submission is refused once `submitted_at` is set.**
> Confirmed, and the current code is worse than the reviewer's description:
> `recordSubmission()` with kind `initial` calls `update()` on the existing row,
> **overwriting `submitted_at`, `reference` and `content_snapshot`** — so
> re-recording destroys the evidence of when the regulator was first told and
> what they were told. Corrections and additions are `supplementary` rows. The
> refusal lives in the service *and* in an `updating` guard on the model that
> throws if `submitted_at`, `submitted_by`, `reference` or `content_snapshot`
> changes on a row whose `submitted_at` was already set — the same
> service-plus-model pattern §1 used for the AAR's exactly-one-of rule, so a
> seeder or tinker session cannot rewrite a submission either. A withdrawn row is
> equally immutable in those fields.
>
> **Schema: this is not a post-freeze migration, and that is conditional.**
> `bcms_incident_notifications` is created by
> `2026_09_19_120001_bcms_phase10_incident_notifications_and_pir.php`, which at
> the date of this amendment is **uncommitted and exists on no branch but
> `integration/bcms-remaining`** — no deployed database has the table. The three
> columns therefore go into the creating migration, the manifest is regenerated
> in the same change, and every per-agent test database is rebuilt with
> `migrate:fresh`. **If that migration has been committed or merged anywhere by
> the time this is implemented, that stops being true** and the columns arrive as
> a second migration instead — the rule from `2026_09_07_130001`'s docblock, that
> an applied migration is never edited to claim a schema deployed databases do
> not have. The Phase 10 freeze line is unchanged: **1 table, 1 column, 1
> alteration**; the table is simply wider when it ships.

### 3. Three smaller rulings, so nobody has to ask twice

- **`ndpa.breach_notification` is approved as a clause ref — and it is the
  compliance-analyst's to publish, not mine to grant.** Orchestration §5 makes the
  `iso_clause_ref` taxonomy theirs; this ADR records the addition and the coupling
  that bites: the enum case and the `ClauseRefs` seeder row land **in the same
  commit**, or `Phase0FoundationsTest::every_clause_ref_case_is_seeded()` fails.
  `Ndpa_breach_notification = 'ndpa.breach_notification'` matches the existing
  `Ndpa_*` naming and `sourceLabel()` already maps the `ndpa.` prefix, so nothing
  else changes.
- **The six missing enums need no ADR.** `IncidentSeverity`, `ActivationLevel`,
  `IncidentStatus`, `IncidentLogEntryType`, `DrTestType`, `DrStrategy` are enums
  over existing string columns — not structural. Cast every one on its model: an
  uncast enum column is a shipped BCMS defect family, and `sev1` beside `SEV-1`
  in a column that feeds a regulator pack is the version of it that costs money.
- **Provider DR results go in `bcms_dr_tests.evidence` (json). No table, and this
  does not contradict ADR 0019.** ADR 0019 refused a json attachment column for
  *human-uploaded artefacts* that need a hash, a lock, an uploader and a
  permissioned download. A provider's webhook payload is a machine record stored
  verbatim with its own content hash and idempotency key — `provider`,
  `external_test_id`, `received_at`, raw payload, hash — and `(provider,
  external_test_id)` is the idempotency pair, so a re-delivered webhook writes
  nothing. Inbound authentication reuses `docs/bcms/phase-7-inbound-token-contract.md`
  rather than inventing a second scheme, and the endpoint never accepts
  `met_objectives` from the provider: whether the objective was met is our
  judgement against our target. **If Phase 10 later wants an uploaded DR
  artefact**, it adds `incident` (or `dr_test`) to `bcms_evidence`'s kind
  registry with an answer to how that kind resolves visibility — ADR 0019 §1
  deliberately left the door open and deliberately did not walk through it.

> **Amendment 1 — 2026-09-17. DR ingestion authenticates with the product's
> per-tenant machine token. The sentence above that sent it to Phase 7's inbound
> contract picked the wrong precedent, and is withdrawn.**
>
> **The defect.** `config/bcms-gateways.php:150–154` put `zerto`, `veeam` and
> `azure-site-recovery` into the same `webhook_secrets` map the EMNS gateways
> use, and both `DrIngestionWebhookController::signatureOk()` and
> `AlertWebhookController::signatureOk()` accept any key in that map. Two
> consequences. **A DR vendor's secret can forge an EMNS roll-call reply** —
> "SAFE" on behalf of somebody who is not. And **one global secret per provider,
> shared by every tenant on the deployment, lets its holder post results against
> any tenant's DR system**, because the controller resolves `dr_system_uuid` with
> `withoutGlobalScope(OrganizationScope::class)` and sets `TenantContext` *from
> the payload*. The tenant was decided by the thing the caller wrote.
>
> **Why Phase 7's scheme was the wrong precedent.** Phase 7's per-provider map is
> right for Phase 7: Termii, Africa's Talking and Meta are third-party services,
> each holding **one account for the whole deployment**, signing in **their**
> format, which the product does not control. A DR result is the opposite case on
> every count: the caller is **one tenant's own estate** — its replication
> appliance or a script beside it — posting **a payload format the product
> defines**. This product already has a credential for exactly that: a
> tenant-bound machine token.
>
> **Decision.**
>
> | | |
> |---|---|
> | **Credential** | `App\Models\ApiToken`, `token_type = client_credentials`, scope **`bcms.dr.test.record`** — an existing permission, already issuable (`StoreApiTokenRequest::assignableScopes()` offers every seeded permission). Issued from the existing admin integrations screen or `php artisan api:token`. |
> | **Route** | `POST /api/v1/bcms/dr-tests/ingest/{provider}` in `routes/api.php`'s v1 group — `api.auth`, `throttle:api-token`, `idempotency` — plus `feature:bcms` and `scope:bcms.dr.test.record`. `ApiAuthorizationTest` then covers it with no new guard. It leaves `routes/bcms-webhooks.php`. |
> | **Tenant** | Decided by the **token**: `AuthenticateApiToken` binds the tenant before the controller runs. `dr_system_uuid` is resolved **inside** that tenant's `OrganizationScope` — the `withoutGlobalScope` call is deleted. A valid token for Kano Heritage presenting another bank's system uuid gets the same 404 as an unknown uuid. |
> | **`{provider}`** | Data, not authentication. Constrained to a DR-provider enum, stored on the row, and half of the `(provider, external_test_id)` idempotency key. A token holder naming the wrong provider writes a row in their own tenant and nowhere else. |
> | **Rotation** | The token lifecycle that already exists: issue a second token, move the integration to it, watch the first one's `last_used_at` go quiet, revoke it. Machine tokens expire by construction — `MACHINE_DEFAULT_LIFETIME_DAYS = 365`, capped at `MACHINE_MAX_LIFETIME_DAYS = 730` — and cannot hold `*`. |
> | **Expiry failure** | Not silent, and needs no new watchdog: a lapsed token stops results arriving, and the DR register's existing `next_test_due` overdue signal is what says so. |
>
> **Answering the questions this was raised with, directly.**
>
> - **Where the secret lives:** in `personal_access_tokens`, hashed, as every
>   inbound machine credential in the product already does. **No column and no
>   table.** An encrypted secrets column on `bcms_settings` was the other option
>   and fails on one requirement alone: the tenant must be identified **before**
>   any secret is read, so an unauthenticated request cannot make the server
>   decrypt every tenant's settings row looking for a match. That needs an
>   indexed lookup key per credential — a row, not a column — plus revocation,
>   expiry and last-used, which is a new table that would duplicate
>   `personal_access_tokens` line for line.
> - **How the tenant is identified without trusting the payload:** by the token.
>   Not a per-tenant URL segment (a URL is configuration that has to change on
>   every rotation, and ends up in proxy logs), and not a key-id header plus HMAC
>   (a second credential scheme beside the one the product already audits).
> - **Rotation:** as above — overlapping tokens, revoke on `last_used_at`.
> - **The global config map:** **does not survive, not even as a development
>   fallback.** A fallback that activates when a tenant has no token *is* the
>   cross-tenant forge, reintroduced by a deploy that forgets one `.env` line.
>   Local development issues a token with `php artisan api:token` like every
>   other integration. The backend's interim separate `dr_ingestion_secrets` map
>   is acceptable as the immediate fix only because the module is dark, and **it
>   must not exist when Phase 10 re-enters gate 1**.
>
> **What HMAC gave up, stated so it is a decision and not an oversight.** A body
> signature adds integrity against a TLS-terminating intermediary and a replay
> window. Replay is neutralised here by the `(provider, external_test_id)`
> idempotency key — a replayed result writes nothing — and by the v1 group's
> `idempotency` middleware. Integrity beyond TLS is lost, and the trade is taken
> because the HMAC secret and the bearer token would sit in the same place, the
> integration's configuration, so a thief of one is a thief of the other; and a
> replication appliance's webhook feature can far more often send a static
> `Authorization` header than compute a per-request MAC over a string the product
> defines, so HMAC in practice required a customer-built shim.
>
> **Phase 7 hardening that rides with this fix**, because the backend is already
> in the file: with the DR keys gone, `webhook_secrets` holds gateways only, and
> `AlertWebhookController` constrains `{provider}` to the gateway allowlist on
> both routes, so that a future key added to the map for any other purpose cannot
> quietly authenticate a roll-call reply again.

### 4. Endorsed without change, so the boundary is on the record

- **A real invocation is never written into `bcms_dr_tests`** (analyst §3.4).
  Enforcement is structural: `DrTestService` gets no path from an incident, and
  the actuals live in the PIR's `quantitative_results` with the activation in
  `bcms_plan_activations`. A row in the test register means "a test we planned and
  ran"; using it for an unplanned outage inflates the register and slides
  `next_test_due` forward.
- **No `is_open_banking` on `bcms_dr_systems`** (§3.3). Scope inherits
  process → dependency → application → system from `bcms_processes.regulatory_flags`,
  resolved in PHP over a scoped fetch — **not** with `JSON_CONTAINS`, which
  MariaDB 10.4 largely does not have and which `CalendarService.php:410`
  documents as the trap.

  > **Amendment 3 — 2026-09-17. The sentence above is wrong about why, and is
  > corrected.** `CalendarService.php:409–415` says the opposite of what it was
  > cited for: `JSON_CONTAINS` **exists on MariaDB** and not on SQLite, and
  > Laravel's `whereJsonContains()` is the *correct*, portable spelling — the trap
  > was a **raw** `JSON_CONTAINS` in a suite that then ran on SQLite. The suite
  > now runs on MariaDB 10.4, so `whereJsonContains()` is acceptable here, and
  > `NotificationService::reportabilityStatus()`'s use of it is **not** a defect.
  > The ruling itself stands for a different reason: regulatory scope inherits
  > across four tables, and resolving it in PHP over a scoped fetch keeps the
  > inheritance in one readable method rather than a JSON predicate inside a
  > four-way join. The error mattered because a wrong reason gets cited, and the
  > next reviewer would have filed a correct call site as a MariaDB defect.
- **Backup attestation needs no table** (§3.5): the service writes
  `last_backup_verified_at` and emits a `bcms_audit_logs` row with
  `event = backup.attested`, the actor and the statement text in `after`. That is
  actor, time and statement, which is the minimum an attestation means. A
  `bcms_backup_attestations` table would be better; it is not blocking, and it is
  not this phase's.
- **Roll-call stays Phase 7's dispatcher** (§6.9). Phase 10 dispatches an alert
  with `incident_id` set and writes no channel code.
- **`carried_to_occurrence_id` stays the exercise engine's column.** A PIR action
  that should be validated at the next exercise is carried by Phase 9's mechanism
  when that occurrence opens. Phase 10 never writes it.
- **`is_exercise = false` belongs in every aggregate**, with one test per
  aggregate rather than one test overall (§6.11). A crisis simulation declares a
  real-looking incident, and a KRI that forgets reports drills as outages.

## What this ADR deliberately does not do

- **It does not add a PIR table, a breach boolean, an NDPC reference column, a
  data-subject count, a DR-provider table or a backup-attestation table.** Six
  tempting tables, one approved.
- **It does not touch `bcms_incidents`' structure.** Three columns are retired in
  place; none is added, altered or dropped.
- **It does not write ERM's reportability fields.** `loss_events` keeps its own
  `is_regulatory_reportable`, `regulatory_body` and `reporting_deadline`. Two
  registers each asserting a deadline is two answers to one examiner's question.
  `ErmBridge` gains `mirrorIncident()` following `mirrorFinding()`'s tolerant
  pattern — a deployment with no loss register must still run an incident.
- **It does not open an `/api/v1/bcms/...` surface.** Routes go in
  `routes/web.php` behind `feature:bcms` (ADR 0007 deviation 2). The DR ingestion
  webhook is the one machine-to-machine exception and sits with Phase 7's inbound
  conventions.
- **It does not write the NDPA register.** §5's five additions — incident records
  as personal data, "describe categories, never paste records", the two
  non-mergeable clocks, data-subject communications resolved through
  `ContactResolver`, and the GAID 2025 re-verification — are compliance-analyst's,
  before the gate.

## Consequences

- **Freeze delta: 1 new table, 1 new column (`bcms_aars.incident_id`), 1 altered
  column (`bcms_aars.occurrence_id` → nullable), 3 columns retired in place.** The
  line reads 15 (P1) → 8 (P2) → 5 (P3) → 1 (P4) → 0 (P5) → 1 (P6) → 0 (P7) → 0
  (P7.5) → 2C (3 tables, 1 column, 1 index) → P9 (1 table) → **P10 (1 table, 1
  column, 1 alteration)**. `bcms:verify-schema --write` regenerates the manifest
  in the same commit as the migration.
- **The nullability change is the riskiest line in the migration.** On MariaDB
  10.4 a `->change()` must restate the column fully or it silently loses
  modifiers, and the column carries both an FK and a unique index. Alter the
  column only — do not drop and re-add the FK — and assert afterwards, in a test,
  that the FK and **both** unique indexes still exist and that two AARs on one
  occurrence are still refused by the database. A migration that quietly dropped
  that index would leave the "one AAR per occurrence" rule enforced by nothing.
- **Phase 9 and Phase 10 now share a table, a service and an export**, which is
  the intent and the risk. Every branch on "which edge is set" is a place the two
  phases can drift; put the branch in one method on `Aar` (`subject()`,
  `isPostIncident()`) rather than in each caller, and let the export read the
  timeline from `bcms_exercise_timeline` or `bcms_incident_log` behind one
  presenter — the analyst's §6.8 point, and the reason to build both packs from
  one presenter now rather than reconciling them in Phase 11.
- **Two open obligations per incident is the normal case, not the exception.** The
  crisis screen shows two countdowns from hour one of a cyber incident with a
  personal-data element, and the watchdog can wake somebody for either. That is
  the feature.
- **An examiner's question becomes answerable from stored data:** "when did you
  know, when did you tell the CBN, when did you tell the NDPC, what changed as a
  result" is four columns on two notification rows plus the PIR's findings. The
  analyst holds that gate at the Phase 10 sufficiency review, and with this ADR
  implemented it can be met.

## Amendment 4 — 2026-09-23. A plan kept active past stand-down

> **The defect** (code-reviewer, defect 6, second half). `standDownChecklist()`
> (`IncidentService.php:264–272`) treats every activation with `deactivated_at`
> null as blocking, and `standDown()` runs that gate (`:282`) *before* the
> `plans_remaining_active` loop (`:291–299`), which only overwrites
> `activation_reason`, a column the gate never reads. So when a recovery plan
> legitimately outlives its incident, stand-down cannot pass. The only way
> through is to deactivate a plan that is still running, which is a false record
> (Amendment 2's shape). The overwrite is its own defect: it destroys the record
> of why the plan was activated, which is the first thing the PIR asks.
>
> **Decision: one column on `bcms_plan_activations`**, added in
> `2026_09_19_120001_bcms_phase10_incident_notifications_and_pir.php` beside the
> `withdrawn_*` columns, under Amendment 2's same condition and manifest rule.
>
> | Column | |
> |---|---|
> | `kept_active_entry_id` | `unsignedBigInteger`, nullable, FK → `bcms_incident_log`, `nullOnDelete`. When set: at stand-down this activation was kept active, for the reason in that entry. |
>
> **Why nothing existing carries it, and why one column rather than three.**
> Setting `deactivated_at` would say the plan stopped, and it has not.
> `activation_reason` says why the plan started. Nulling `incident_id` would cut
> the link the PIR reads. A log entry with no pointer would leave the gate
> parsing `content` or `attachments` to find it; Amendment 2 refused that
> correlated predicate. The audit log is never the state a gate reads. There is
> **no `kept_active_at` or `kept_active_by`**: `withdrawn_at` is stored because
> a regulatory clock is compared against it, but nothing is compared against
> this moment, so the two columns would only copy the entry's `logged_at` and
> `logged_by` and could come to disagree with them (ADR 0013). `nullOnDelete`,
> because an activation outlives a deleted incident while its log entries
> cascade away; `RESTRICT` would make such an incident undeletable.
>
> **Against the freeze.** This alters a Phase 0 table. Every reader of it
> (`openActivation()`, `history()`, the plan screen, the crisis-room card)
> defines "active" as `deactivated_at is null` and does not read the new
> column, so no answer changes. Without it, stand-down cannot pass in the
> ordinary case. **The P10 line becomes 1 table, 2 columns, 1 alteration**; this
> supersedes Consequences on that count only.
>
> 1. **Who.** Anyone with `bcms.incident.manage`, as part of the stand-down
>    submission and nowhere else. `bcms.plan.activate` is not required: keeping
>    a plan running is not activating it.
> 2. **What is written for each kept-active activation.** A `decision` entry
>    naming the plan and version as remaining active after stand-down. Its
>    `options_considered` is "Deactivate at stand-down, or keep active beyond the
>    incident", and its `rationale` is the officer's statement, which is required.
>    `kept_active_entry_id` is then set to that entry. `activation_reason` is
>    never written. A blank statement is refused with a message naming the plan,
>    not skipped. An id is refused if it belongs to another incident, is already
>    deactivated or is already kept active.
> 3. **The gate** counts `incident_id = ? and deactivated_at is null and
>    kept_active_entry_id is null`. `log()` already refuses a decision entry with
>    a blank rationale, so every kept-active row carries a reason by construction.
> 4. **Ordering.** Inside one transaction, `standDown()` locks the incident row
>    (`lockForUpdate`), writes the dispositions, **then** evaluates
>    `standDownChecklist()` and throws if any condition is unmet. The throw rolls
>    the dispositions back, so a refused stand-down leaves no marks. On the GET
>    screen, condition 5 reads "N activation(s) need a disposition below"
>    (condition 4's pattern); the server's evaluation is the authority.
> 5. **Audit.** One `$incident->recordAudit('incident.plan_kept_active', …)` row
>    per activation, with `activation_id`, `plan_id`, `entry_id` and the
>    statement (25 characters, inside ADR 0014's 60). `PlanActivation` does not
>    become `BcmsAuditable`.
>
> **The record afterwards.** The incident is `closed`. The plan still shows as
> **active**, which is true, with "kept active at stand-down of {reference}:
> {rationale}" read through the pointer. It is deactivated later through the
> existing `plans.deactivate` route, and the row then carries both facts. The PIR
> lists every activation on the incident as either deactivated at T, or kept
> active with its rationale (and, if since deactivated, when). This is read live
> from the activation and its entry, and nothing is copied onto the AAR row.
>
> **What this refuses.** A `kept_active` boolean, enum or reason column (a second
> copy of what the pointer already says). Setting the column anywhere except at
> stand-down. A watchdog for long-running kept-active plans (that is a PIR and
> management-review finding, not a page). Relaxing `openActivation()`: a new
> incident that needs a plan kept active from an earlier one gets the existing
> "already active" refusal. Whether one activation may serve two incidents needs
> its own ADR.
