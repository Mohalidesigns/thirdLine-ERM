# ADR 0026 — The departmental KRI cycle: a KRI belongs to a business unit, a draft is not a reading, and a reminder is a row

**Status:** Accepted · **Date:** 2026-11-01 · **Phase:** ERM/Risk — departmental monthly KRI cycle (`plans/kri/KRI-DEPARTMENTAL-CYCLE-PROMPT.md`) · **Author:** architect
**Amended:** 2026-11-01, Amendment 1 (end of file), which closes the gaps ui-designer found in `docs/ui/kri-cycle/` 00–10. Where an amendment item and the body disagree, the amendment wins.
**Requested by:** the user, through the coordinator. Decisions 0.1–0.9 were settled by the user on 2026-11-01 and are recorded here, not reopened.
**Consumers:** backend-engineer **(lead: 7 migrations, 7 models, services, policies, requests, controllers, presenters, 2 mailables, 2 jobs, 2 commands)** · ui-designer · frontend-engineer · integrations-engineer (directory hint reader, §9) · reliability-engineer (scheduler, queue, retry, watchdog, §12) · qa-engineer · code-reviewer · Issues module (`IssueEscalationService`, `Issue::SOURCES`, §8) · measure engine (`KriBreachDetected` dispatch timing, §6.4) · BCMS track (read-only consumer of its directory data, no BCMS change, §9)

## Context

Five parts ship together: a monthly request per department, a Library grouped by
department, a dashboard card with a drill-down and reminders, a red-band
explanation that becomes an Issue, and a live email test. Most of it is new
behaviour on existing objects. The schema questions are the ones that are
expensive to answer twice: how a KRI knows its department, where a draft lives,
what makes a reminder idempotent, and how the explanation reaches the Issue
without a second link mechanism.

Verified in the code, not taken from the brief:

- **Nothing resolves a KRI to a business unit today.** `key_risk_indicators` has
  `entity_id` (→ `entities`) and `node_id` (→ `objects`), and no
  `business_unit_id`. `GraphScope` filters on `entities.hierarchy_path` through
  `entity_id`. It never touches `business_units`. `node_id` was filled once by
  `OrganisationGraphUnifier::resolveNodeIds()`, which says of KRIs that they have
  "no business_unit_id and, in practice, no entity_id either" and so derives the
  node from the KRI's risk. Nothing in `KriService` or `KriController` writes
  `node_id` on create or edit. The node, where present, is an `entity` object, a
  `business_unit` object or a merged one, so it does not reliably name a department. **So
  the graph cannot answer "which department owns this KRI", and the link is a
  column.**
- **The band rule is `MeasureThreshold::bandFor()`.** It walks the band list in
  the order given and returns the first match. `KriMeasureMigrator::bandsFor()`
  orders it `[green, amber, red]` for `higher_worse` and `[red, amber, green]` for
  `lower_worse`. The comparisons are `value < min` / `value >= max` (higher_worse)
  and `value <= min` / `value > max` (lower_worse). Worked through:
  - **On the red boundary is RED in both directions.** higher_worse: amber's
    `max = R` excludes R and red's `min = R` admits it. lower_worse: red's
    `max = R` admits R, because only `value > R` skips it.
  - **On the green boundary is AMBER in both directions.** higher_worse: green's
    `max = G` excludes G. lower_worse: green's `min = G` excludes G (`value <= G`
    skips), and amber's `max = G` admits it.
  - **`KriForm.jsx`'s `BandPreview` legend contradicts the engine at the green
    boundary.** It says "up to G" for green on higher_worse and "G and above" for
    green on lower_worse. Both are wrong at G. The red edge it states is right.
- **`measure_values.status` has a `draft` value that nothing filters on.**
  `kri:check-breaches` (`CheckKriBreaches`) re-bands and reconciles **every**
  value it finds through `valueOf()`, whatever its status. A draft stored as a
  `measure_value` would open a breach and fire `KriBreachDetected` overnight.
- **`KriBreachDetected` is dispatched inside `MeasureService::announceBreach()`,
  mid-write.** One listener (`EscalateRiskOnKriBreach`) is synchronous. The other
  (`SendNotification`) is `ShouldQueue`, and every queue connection has
  `after_commit => false`. A submit that rolls back would already have queued a
  breach notification.
- **`issues` already has the fields the brief needs:** `observation`,
  `action_plan`, `remediation_due_date`, `responsible_owner_id`,
  `business_unit_id`, `department` and `escalation_path` (string 200, written by
  nothing and read by nothing). Two of its columns are NOT NULL with no default: `issue_category`
  (string 50) and `created_by`.
- **`measure_breaches` already has `root_cause` (text) and `linked_issue_id`
  (FK → issues).** Neither needs a migration.
- **Issues already link to objects through the graph.** `HasObjectIdentity::relate()`
  writes `object_relationships` rows, and `derives_from` permits any type to any
  type (`ObjectTypeRegistry::relationshipTypes()`).
- **`IssueEscalationService` picks a rung from `issue_escalation_rules`**
  (priority, nullable source, level, role, days). Production tenants have **no
  rows** in that table: only `EnterpriseGapSeeder` writes any, and only for
  organisation 1. No screen edits it either. With no rules, a KRI-raised Issue
  would never escalate, so decision 0.9 would never take effect.
- **The issue source list is enumerated in eight places, and one of them is data,
  not code.** `object_attributes.enum_options` for `Issue.issue_source` was seeded
  once from `FormFieldRegistry` by `2026_08_13_120005`. Editing the registry
  changes no deployed form. `2026_09_05_100000` is the precedent replay.
- **The directory sync writes `bcms_contacts`, not `users`.** `ad_object_guid`,
  directory department and job title live on BCMS rows linked by
  `bcms_contacts.user_id`. ADR 0018 makes the interface read-only.
- **Users who will be champions hold no KRI permission today.** `kri.view` is held
  by `risk-manager`, `risk-analyst`, `chief-risk-officer` and `super-admin`, and
  all four also hold `rcsa_scope.all_units`. `risk-owner`, the first-line
  business-unit role, holds no `kri.*` permission.
- **MySQL 8.0.46 runs `ONLY_FULL_GROUP_BY`. MariaDB 10.4's default `sql_mode` does
  not.** Every grouped aggregate in this design must select only grouped columns
  and aggregates, or it passes one CI leg and fails the other.

## The settled decisions (user, 2026-11-01), recorded

| # | Decision | As built here |
|---|---|---|
| 0.1 | "Submitted" is an explicit **Submit** that locks the return. A draft is not a submission. A department submits once every due KRI has a value and every red KRI is explained. | `kri_returns.status` ∈ `draft` / `submitted` / `reopened`. Only `submitted` counts on the dashboard (§4, §10). |
| 0.2 | A working day is Mon–Fri in **Africa/Lagos**, minus an **organisation holiday list** kept in ERM. There is no BCMS `BlackoutPeriod` dependency. | `organization_holidays` (§3) and `App\Support\Kri\KriWorkingCalendar` (§11). |
| 0.3 | Due **5 working days** after the request. After that the return is overdue. | `kri_returns.due_on` (§4). |
| 0.4 | Escalation is **tiered by reminder count**: 1st reminder → champions; 2nd adds team leads; 3rd adds unit heads; 4th and later add group heads. **At most one manual reminder per department per day.** | `KriContactLadder` (§7). The once-a-day rule is a unique key (§5). |
| 0.5 | Contacts are a **manual pick per department**. `business_units` are authoritative. The AD department is a hint, and a mismatch is flagged, never applied. | `kri_department_contacts` (§2), with the directory hint read at render time (§9). |
| 0.6 | KRIs with no department go in an **"Unassigned"** tile and do not count on the dashboard. A department with no due KRIs appears in the Library and is **not** in the dashboard denominator. | The denominator is the set of `kri_returns` rows for the period (§4). |
| 0.7 | Red-band screen and storage: as written in brief item 7. | §6. |
| 0.8 | Add **`kri_breach`** to `Issue::SOURCES` and update every list that enumerates sources. | §8.3. |
| 0.9 | Overdue KRI-raised Issues escalate through the **department's KRI contacts**: champion → team lead → unit head → group head. | §8.2. |

## Decision

### 0. Decisions the brief left to the architect

These are recorded first because each one shapes the schema.

| Question | Decision | Why |
|---|---|---|
| Which month a return collects | **The month just ended.** The return opened on the first working day of November collects **October's** readings, against October's `Period`. | A KRI is a level at a point in time, and `KriMeasureMigrator` already treats a period's end as the point it reports. A reading taken on 3 November and filed against November would label October's month-end position as November's. The alternative, the current month, was rejected for that reason. **This is the decision most worth the user's eye**: if they meant the current month, it changes one function (`KriCycleService::reportingPeriodFor()`) and no schema. |
| Where a draft lives | **On the return** (`kri_return_lines`), never in `measure_values`. | `kri:check-breaches` reconciles every `measure_value` regardless of status (Context). A draft there would breach overnight. |
| KRI → department | A nullable **`key_risk_indicators.business_unit_id`**, with a deterministic backfill (§1). | The graph does not resolve it (Context). |
| What the Edit-form explanation attaches to | **The KRI's open red breach if there is one, plus the KRI's single open KRI Issue** (reused, or created). With no open red breach, it attaches to the Issue alone. | §6.3. |
| The Issue ↔ KRI link | **A graph edge, `Issue derives_from KeyRiskIndicator`**, plus `measure_breaches.linked_issue_id` wherever a breach exists. No new column on `issues`. | The brief says to use the graph where it already links issues. §6.2. |
| Issue priority | **`high`, always.** It is never re-derived when the Issue is reused. | A "how far into red" rule needs a scaling constant the KRI definition does not hold. Nothing produces that number except a developer typing it. The Issue's owner can change priority through the Issues module. |
| When KRI Issues escalate | The tenant's `issue_escalation_rules`, **with a built-in floor ladder** for KRI-path Issues: ≥1, ≥7, ≥14, ≥30 days overdue → levels 1–4. | Production has no rules and no screen to add them (Context). Without a floor, 0.9 would never run. 7/14/30 are the cadence `EnterpriseGapSeeder` already uses. The day counts are one constant (§8.2). |
| Library scope escape hatch | A new permission, **`kri.all_departments`**. RCSA's `rcsa_scope.all_units` is not reused. | Standard §2: a description is an authority. "See every unit's RCSA" is not "see every department's KRIs and remind them". |

### 1. `key_risk_indicators.business_unit_id`

| Column | Type | Null | Index / FK |
|---|---|---|---|
| `business_unit_id` | `foreignId` (unsigned bigint), placed after `node_id` | **yes** | FK → `business_units.id` **`nullOnDelete`**; index `kri_org_business_unit_idx (organization_id, business_unit_id)` |

**Backfill, in the same migration.** It runs in PHP in chunks of 500 by `id`, and
each UPDATE targets one KRI by id. No UPDATE reads `key_risk_indicators` in a
subquery (MySQL error 1093). The first rule that yields exactly one unit wins:

1. `node_id` points at an `objects` row with `source_model_type = 'business_unit'`
   → that row's `source_model_id`. A merged unit's object is an `entity` object,
   so it does not match here and falls through to rule 2.
2. `risk_id` → `risks.business_unit_id`, when that is not null.
3. Every `risk_kri_mapping` row for the KRI points at risks that share **one**
   non-null `business_unit_id` → that unit.
4. Otherwise `NULL`. The KRI goes in the "Unassigned" tile.

The migration logs one summary line with counts per rule. It does not guess
between disagreeing links; that is the same refusal `OrganisationGraphUnifier`
makes, for the same reason. `down()` drops the FK and the column.

**Writers:** `StoreKriRequest` and `UpdateKriRequest` accept a nullable
`business_unit_id` with a tenant-bound `Rule::exists('business_units','id')->where('organization_id', …)->whereNull('deleted_at')`.
`KriService::columnsFor()` writes it. The KRI form gets a **Department** select in
Section 1. `node_id`, `entity_id` and `GraphScope` are unchanged.

**Schema freeze test:** the column is additive and nullable. No consumer reads
`key_risk_indicators` with `SELECT *` into a strict shape, and no frozen contract
names it. Nothing has built against its absence.

### 2. `kri_department_contacts`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | `cascadeOnDelete` |
| `business_unit_id` | foreignId → `business_units` | no | `cascadeOnDelete` |
| `user_id` | foreignId → `users` | no | `cascadeOnDelete` |
| `role` | `string(20)` | no | `risk_champion` \| `team_lead` \| `unit_head` \| `group_head`. Cast to `App\Enums\KriContactRole` (backed enum). |
| `assigned_by` | foreignId → `users` | yes | `nullOnDelete` |
| `created_at`, `updated_at` | `timestamps()` | yes | |

- Unique `kri_dept_contacts_unit_user_role_unique (business_unit_id, user_id, role)`.
- Index `kri_dept_contacts_user_role_idx (organization_id, user_id, role)`, which
  answers "which departments am I champion of".
- Index `kri_dept_contacts_unit_role_idx (organization_id, business_unit_id, role)`.
- **Many people per role. One person may hold several roles in one department.**
  When resolving recipients, a person is deduplicated and keeps their lowest
  tier.
- **There is no soft delete.** Removing a contact deletes the row. The audit row
  keeps the history (§13).
- **No AD column is stored.** The directory department, account state and job
  title are read when the screen is drawn (§9). A copy would go stale on the next
  sync.
- `role` is a string with an enum cast, not a DB `ENUM`, so a future tier is not an
  ALTER. The set of four is frozen by this ADR all the same.

### 3. `organization_holidays`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | `cascadeOnDelete` |
| `holiday_date` | `date` | no | A local Africa/Lagos calendar day |
| `name` | `string(150)` | no | |
| `created_by` | foreignId → `users` | yes | `nullOnDelete` |
| `created_at`, `updated_at` | `timestamps()` | yes | |

- Unique `org_holidays_org_date_unique (organization_id, holiday_date)`.
- One row is one date. There is **no recurrence rule**: Nigerian holidays move
  (Eid), and a rule that guesses is worse than a list somebody maintains. Delete
  is a hard delete plus an audit row.
- It is unprefixed and owned by ERM. BCMS `BlackoutPeriod` is untouched and is
  not read.

### 4. The cycle: `kri_cycle_runs`, `kri_returns`, `kri_return_lines`

**`kri_cycle_runs`** is one row per organisation per reporting period. It is the
watchdog's record that the cycle opened.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | cascade |
| `period_id` | foreignId → `periods` | no | cascade. The **reporting month** (`period_type = month`). |
| `run_on` | `date` | no | The Lagos day the run acted as (`--date` or today) |
| `trigger` | `string(16)` | no | `schedule` \| `manual` |
| `forced` | `boolean` default false | no | |
| `status` | `string(16)` | no | `running` \| `completed` \| `failed` |
| `started_at` | `dateTime` | no | Written explicitly (ADR 0022) |
| `finished_at` | `dateTime` | yes | |
| `departments_due` | `unsignedInteger` default 0 | no | |
| `returns_opened` | `unsignedInteger` default 0 | no | Returns this run created |
| `requests_queued` | `unsignedInteger` default 0 | no | |
| `departments_without_champion` | `unsignedInteger` default 0 | no | |
| `error` | `text` | yes | |
| `triggered_by` | foreignId → `users` | yes | `nullOnDelete` |
| timestamps | | yes | |

Unique `kri_cycle_runs_org_period_unique (organization_id, period_id)`. A re-run
reuses the row and does not add one.

**`kri_returns`** is one row per department per reporting period. **This unique
key is the idempotency key for the whole cycle.**

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | cascade |
| `business_unit_id` | foreignId → `business_units` | no | cascade |
| `period_id` | foreignId → `periods` | no | cascade. The reporting month. |
| `kri_cycle_run_id` | foreignId → `kri_cycle_runs` | yes | `nullOnDelete` |
| `status` | `string(16)` default `'draft'` | no | `draft` \| `submitted` \| `reopened`. Cast to `App\Enums\KriReturnStatus`. |
| `requested_on` | `date` | no | The Lagos day the request opened |
| `due_on` | `date` | no | `requested_on` + 5 working days (§11) |
| `opened_at` | `dateTime` | no | |
| `submitted_at` | `dateTime` | yes | |
| `submitted_by` | foreignId → `users` | yes | `nullOnDelete` |
| `reopened_at` | `dateTime` | yes | |
| `reopened_by` | foreignId → `users` | yes | `nullOnDelete` |
| `reopen_count` | `unsignedSmallInteger` default 0 | no | |
| timestamps | | yes | |

- Unique `kri_returns_unit_period_unique (organization_id, business_unit_id, period_id)`.
- Index `kri_returns_org_period_status_idx (organization_id, period_id, status)`
  serves the dashboard card.
- Index `kri_returns_org_status_due_idx (organization_id, status, due_on)` serves
  the overdue query.

**Lifecycle.** `draft → submitted` (Submit). `submitted → reopened` (Reopen).
`reopened → submitted` (Submit again). Nothing else moves it. **Only
`submitted` counts as submitted.** A reopened return is "not submitted" until it
is submitted again.

The badge the screens show is **derived, never stored**: `display_status`. Its
precedence order is `submitted` > `overdue` > `reopened` > `in_progress` >
`not_started`. `overdue` means not submitted and Lagos today > `due_on`.
`in_progress` means at least one line has a value. `days_overdue` counts calendar
days after `due_on`. Every comparison is a day against a day, through
`whereDate` / `toDateString()` (standard §14).

**`kri_return_lines`** holds one row per KRI due in the return. It is the **draft
store**, not a readings table.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | cascade |
| `kri_return_id` | foreignId → `kri_returns` | no | cascade |
| `key_risk_indicator_id` | foreignId → `key_risk_indicators` | no | cascade |
| `period_id` | foreignId → `periods` | no | cascade. **The KRI's own measurement period**: the month for monthly, daily and weekly KRIs, and the quarter (etc.) for the others. It is what `KriMeasureBridge::periodTypeFor()` resolves for the reporting month's end date. |
| `value` | `decimal(28,6)` | yes | The working value, draft or submitted. Same precision as `measure_values.value`. |
| `comment` | `text` | yes | Becomes the reading's `notes` |
| `root_cause` | `text` | yes | Draft of the explanation |
| `action_point` | `text` | yes | |
| `timeline` | `date` | yes | |
| `action_owner_id` | foreignId → `users` | yes | `nullOnDelete` |
| `timeline_change_reason` | `string(500)` | yes | |
| `band` | `string(32)` | yes | Band at the last save. **For display only. Never authoritative.** |
| `measure_value_id` | foreignId → `measure_values` | yes | `nullOnDelete`. Set at submit. |
| `measure_breach_id` | foreignId → `measure_breaches` | yes | `nullOnDelete`. The red breach, if any. |
| `issue_id` | foreignId → `issues` | yes | `nullOnDelete`. The KRI Issue this line created or reused. |
| `saved_by` | foreignId → `users` | yes | `nullOnDelete` |
| `saved_at` | `dateTime` | yes | |
| timestamps | | yes | |

- Unique `kri_return_lines_return_kri_unique (kri_return_id, key_risk_indicator_id)`.
- Index `kri_return_lines_org_kri_idx (organization_id, key_risk_indicator_id)`.

**What makes a KRI due in a return.** All of these must hold. The rule is computed
once in `KriCycleService::dueKrisFor(BusinessUnit, Period $reportingMonth)`, and
nowhere else:
- `business_unit_id` = the department. The KRI is assigned **directly**: there is
  no roll-up of child units' KRIs into a parent.
- It is active (`is_active` true or null, the same as `KriMeasureBridge::activeKris()`)
  and not soft-deleted.
- **`is_automated` is not true.** `KriPublisher` (TPRM) and `ResilienceKriPublisher`
  (BCMS) set it, and asking a champion to type a number a publisher overwrites
  each morning would give one reading two writers.
- **Frequency is honoured:** `PeriodService::resolve(reportingMonth.end_date, bridge->periodTypeFor($kri))->end_date`
  equals `reportingMonth.end_date`. That is always true for monthly, daily and
  weekly KRIs. A quarterly KRI is due only when its quarter ends that month. This
  uses the tenant's period calendar, so a fiscal calendar works without a
  calendar-quarter assumption.

**The line set is synchronised** when the return opens, on every save-draft and
before submit, while the status is not `submitted`. Newly due KRIs gain a line.
A line whose KRI is no longer due is deleted, and if it held a draft value, an
audit row records the discarded value. A submitted return's lines are frozen.

**Drafts never count.** A draft writes only to `kri_return_lines`. Nothing there
is read as a reading by any dashboard, sweep or report. A draft cannot raise a
breach or fire `KriBreachDetected`, because it never reaches the measure engine.

### 5. Notices and the reminder log: `kri_return_notices`, `kri_return_notice_recipients`

One table covers the monthly request and the manual reminders. Both are "an email
about this return to a tier of contacts", and the live test needs evidence for
both.

**`kri_return_notices`:**

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | cascade |
| `kri_return_id` | foreignId → `kri_returns` | no | cascade |
| `business_unit_id` | foreignId → `business_units` | no | cascade. Denormalised for the per-department-per-day key. |
| `kind` | `string(16)` | no | `request` \| `reminder` |
| `sequence` | `unsignedSmallInteger` | no | `0` for the request. `n` for the n-th reminder **on this return**. |
| `tier` | `unsignedTinyInteger` | no | 1–4. The request is always 1. A reminder is `min(sequence, 4)`. |
| `idempotency_key` | `string(80)` | no | See below |
| `sent_on` | `date` | no | The Lagos day of the action |
| `status` | `string(20)` | no | `queued` \| `dispatching` \| `sent` \| `partially_failed` \| `failed` \| `no_recipients` |
| `requested_by` | foreignId → `users` | yes | `nullOnDelete`. NULL means the scheduler. |
| `queued_at` | `dateTime` | no | |
| `recipients_resolved_at` | `dateTime` | yes | When the job resolved who receives it |
| `completed_at` | `dateTime` | yes | |
| timestamps | | yes | |

- Unique `kri_notices_org_key_unique (organization_id, idempotency_key)`.
- Unique `kri_notices_return_seq_unique (kri_return_id, kind, sequence)`. Two
  concurrent reminders cannot both become reminder #2.
- Index `kri_notices_unit_day_idx (organization_id, business_unit_id, sent_on)`.
- Index `kri_notices_status_queued_idx (status, queued_at)` serves the watchdog.

**The idempotency keys are frozen, and the "at most once" rules ARE these keys:**
- Request: `request:r{kri_return_id}`. **A return gets exactly one request, ever.**
  The cycle never resends one. A return whose request ended `no_recipients` gets
  its first champion email from a manual reminder, which is tier 1 = champions.
- Reminder: `reminder:bu{business_unit_id}:{Y-m-d Lagos}`. **At most one manual
  reminder per department per day**, across every period.

A duplicate insert gives a unique-key violation. The service catches it and
reports `already_reminded_today` for that department. It does not 500 the batch.

**`kri_return_notice_recipients`** holds one row per person per notice. These rows
are the delivery outcomes.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `organization_id` | foreignId → `organizations` | no | cascade |
| `kri_return_notice_id` | foreignId → `kri_return_notices` | no | cascade |
| `user_id` | foreignId → `users` | yes | `nullOnDelete` |
| `email` | `string(255)` | no | A snapshot taken at resolution time |
| `name` | `string(200)` | no | A snapshot |
| `contact_role` | `string(20)` | no | The person's lowest tier role in this department |
| `status` | `string(16)` | no | `pending` \| `sent` \| `failed` \| `skipped` |
| `skip_reason` | `string(40)` | yes | `inactive_user` \| `no_email` |
| `attempts` | `unsignedTinyInteger` default 0 | no | |
| `message_id` | `string(255)` | yes | From the mailer's `SentMessage` |
| `error` | `string(500)` | yes | The last transport error, truncated |
| `sent_at` | `dateTime` | yes | |
| `failed_at` | `dateTime` | yes | |
| timestamps | | yes | |

- Unique `kri_notice_recipients_user_unique (kri_return_notice_id, user_id)`.
- Index `kri_notice_recipients_org_status_idx (organization_id, status)`.

**`sent` means "accepted by the mail transport"**, and every screen says exactly
that. Bounces are not tracked. The screens must not imply delivery to an inbox.

**The flow follows the "persist before you dispatch" rule, and recipients are
resolved when the mail is sent:**
1. A request (from the cycle command) or a reminder (from the dashboard) inserts
   the notice row as `queued`, with no recipients. It then dispatches
   `App\Jobs\DispatchKriReturnNotice($noticeId)` **after commit**.
2. The job locks the notice. It returns if the status is not `queued`, so it is
   idempotent. It resolves recipients **now** from `kri_department_contacts` for
   the notice's tier (§7). It inserts recipient rows: `skipped` for an inactive
   user or a missing email, `pending` otherwise. It stamps
   `recipients_resolved_at` and sets the status to `dispatching`. With no
   eligible recipient, the status becomes `no_recipients`.
3. For each `pending` row it dispatches `App\Jobs\SendKriReturnNoticeMail($recipientId)`.
   That job sends one mailable (`App\Mail\KriReturnRequested` or
   `App\Mail\KriReturnReminder`) with `Mail::to()->send()` inside the job, so the
   job is the retry unit. It records `sent_at` and `message_id`, or `attempts`,
   `error` and, on the final failure, `failed_at`.
4. The notice's rolled-up status is computed from recipient counts **read first,
   then written**, which avoids a same-table subquery: all sent → `sent`; some
   failed → `partially_failed`; all failed → `failed`.

The queue is `notifications` unless reliability-engineer places it elsewhere. The
retry count and backoff belong to reliability-engineer.

### 6. The red-band explanation

#### 6.1 One band rule, exported, mirrored and pinned

`App\Support\Kri\KriBandRule` is the **only** server entry point. Every caller
uses it: `UpdateKriRequest`, `RecordKriMeasurementRequest`, the champion
draft/submit requests and every presenter that sends band props.

```php
/** @return array{direction: 'higher_worse'|'lower_worse', bands: list<array{code:string,label:string,color:string,min:?float,max:?float}>} */
public function forReading(KeyRiskIndicator $kri, Period $period): array;
// = MeasureService::activeThreshold($measure, $objectId, $period->end_date) + resolveBands(...): the exact
//   band list and direction bandCodeFor() evaluates. Empty bands when no threshold is in force.

public function bandForReading(KeyRiskIndicator $kri, Period $period, float $value): ?string;
// = MeasureService::bandCodeFor(...). Literally that call, not a reimplementation.

/** Same shape as forReading(). */
public function forBoundaries(string $formDirection, float|int|string|null $green, float|int|string|null $red): array;
// = KriService::bandColumns() fanned out onto an unsaved KRI, then KriMeasureMigrator::bandsFor().
//   bandColumns() moves out of KriService (private today) into this class, and KriService calls it.

public function bandOf(array $rule, ?float $value): ?string;
// = (new MeasureThreshold(['direction' => $rule['direction']]))->bandFor($value, $rule['bands'])['code'] ?? null.
//   A null value is null, never 'green'.

public function isRed(array $rule, ?float $value): bool;  // bandOf(...) === 'red'
```

**Page props always carry the rule as `bandRule: {direction, bands}`**, in the
order the engine iterates. The browser mirror is `resources/js/lib/kri-band.js`.
It exports `bandFor(rule, value)` (a line-for-line port of
`MeasureThreshold::bandFor()`) and `ruleFromBoundaries(direction, green, red)` (a
port of `forBoundaries()`, which the Edit form needs because the user is typing
the boundaries). It is a **display mirror**: the server recomputes on every write.

**Pinned the way RCSA pins its calculator.** `resources/js/lib/kri-band-truth-table.json`
holds cases of `(direction, green, red, value) → band`. `tests/Unit/Kri/KriBandRuleTest.php`
runs every case through `KriBandRule`, and through `kri-band.js` under node. The
node half skips when node is absent, as `RcsaCalculationTest` does. The table must
include, for **both** directions:
- a value on the red boundary → `red`;
- a value on the green boundary → `amber`;
- a value strictly inside each band;
- a null green, a null red, both null (`bandsFor()` returns `[]`, so the band is null);
- inverted boundaries.

**The edge, frozen:** a value exactly on the red boundary is red for
`higher_worse` and for `lower_worse`. A value exactly on the green boundary is
amber for both. **`BandPreview`'s legend text is corrected to match** (higher_worse:
green "below G", amber "G up to but not including R", red "R and above";
lower_worse: green "above G", amber "above R up to and including G", red "R and below"),
and the text is generated from the rule. This is in scope: the brief requires the
legend to be reused on the champion screen beside a live badge, and the two must
not disagree.

#### 6.2 Storage, exactly as decision 0.7

The explanation is three required fields: `root_cause` (text, max 5000),
`action_point` (text, max 5000) and `timeline` (date). There are two optional
fields: `owner_id` and `timeline_change_reason` (max 500).

| Fact | Stored at |
|---|---|
| Root cause | `measure_breaches.root_cause` on the **red** breach (`band_to = 'red'`, status `open`/`acknowledged`) for (measure, object, period). It is also written to `issues.observation`. |
| Action point | `issues.action_plan` |
| Timeline | `issues.remediation_due_date`. `CheckOverdueIssues` reads it, unchanged. |
| Issue ↔ breach | `measure_breaches.linked_issue_id`. It is set when null. **An existing link is never overwritten.** |
| Issue ↔ KRI | A graph edge: `$issue->relate('derives_from', $kri, ['relationship_type' => 'kri_breach'])`. **If `relate()` returns null, the service throws** and the whole transaction rolls back. A missing edge would mean a second Issue next month. |
| Issue ↔ return line | `kri_return_lines.issue_id`, and `measure_breach_id` |

**The Issue, when created:**

| Field | Value |
|---|---|
| `issue_reference` | `ReferenceCodeService::generate('issues', 'issue_reference', 'ISS')` |
| `title` | `KRI {kri_code} in red — {department name \| "Unassigned"} — {period label}`. Champion path: the line's period. Edit-form path: the current Lagos month's `Period`. |
| `description` | The value, the red boundary, the direction in words, the KRI URL, and the return URL where there is a return |
| `observation` | The root cause |
| `action_plan` | The action point |
| `remediation_due_date` | The timeline |
| `responsible_owner_id` | `owner_id`, or by default the submitting champion. On the Edit form the default is `kri_owner_id ?? owner_id`. |
| `business_unit_id`, `department` | The KRI's `business_unit_id` and that unit's name. Both null for an unassigned KRI. |
| `issue_source` | `kri_breach` |
| `issue_category` | `KRI breach` (NOT NULL column, free text) |
| `priority` | `high` |
| `issue_status` | `OPEN` |
| `escalation_path` | `kri_department_contacts` when `business_unit_id` is not null, otherwise null (§8.2) |
| `entity_id` | The KRI's `entity_id` |
| `created_by` | The submitting user |

**"The KRI's open KRI Issue"** is defined once, as
`KriRedBandService::openIssueFor(KeyRiskIndicator $kri): ?Issue`. It is the oldest
Issue with an `object_relationships` edge of type `derives_from` from the Issue's
object to the KRI's object, `issue_source = 'kri_breach'`,
`issue_status NOT IN ('CLOSED','CANCELLED')` and `deleted_at IS NULL`. This is a
plain join with no JSON functions. `PENDING_CLOSURE` and `OVERDUE` count as open.

**Reuse, never duplicate.** When an open KRI Issue exists, it is updated:
`observation`, `action_plan` and `remediation_due_date`. `responsible_owner_id` is
updated only when `owner_id` was posted explicitly. **The status, priority and
escalation level are untouched.** A changed timeline requires
`timeline_change_reason`. It is audited with old date, new date and reason through
`AuditTrailService::recordChanges($issue, $original, $reason)`.

**A timeline must not be in the past** (`>= Lagos today`), **unless** it equals the
reused Issue's current `remediation_due_date`. A champion may confirm that an
overdue action still applies without being forced to move its date. The Issue
stays OVERDUE and keeps being chased.

**Leaving red.** The engine resolves the breach by its existing rules. The Issue
stays open, the breach keeps its `root_cause` and `linked_issue_id`, and the line
keeps its `issue_id`. Explanation fields posted with a non-red value are
**ignored and not stored**.

**Serialisation.** Every writer locks the KRI row (`lockForUpdate`) before calling
`openIssueFor()`. The champion submit locks the `kri_returns` row first, then the
KRIs in ascending id order. One lock order, so there is no deadlock cycle.

`App\Services\Kri\KriRedBandService` is the **one writer**. Its methods assert
`DB::transactionLevel() > 0`; the caller owns the transaction.

```php
final class RedBandExplanation { public function __construct(public string $rootCause, public string $actionPoint,
    public CarbonImmutable $timeline, public ?int $ownerId = null, public ?string $timelineChangeReason = null) {} }

public function applyToReading(KeyRiskIndicator $kri, Period $period, MeasureValue $value,
    RedBandExplanation $e, User $actor, ?KriReturn $return = null): Issue;
public function applyToTarget(KeyRiskIndicator $kri, RedBandExplanation $e, User $actor): Issue;
public function openIssueFor(KeyRiskIndicator $kri): ?Issue;
```

#### 6.3 What the Edit KRI form's explanation attaches to

The Edit form's Target Value is **not a reading**, so no breach is created and no
value is recorded. When `bandOf(forBoundaries(posted direction, green, red), target_value)`
is red, `applyToTarget()` does three things:
1. It writes `root_cause` onto the KRI's **most recent open red breach** in any
   period (`band_to = 'red'`, status `open`/`acknowledged`, latest `breached_at`),
   if one exists. It also sets that breach's `linked_issue_id` if the column is null.
2. It reuses or creates the KRI's open KRI Issue, as in §6.2.
3. With no open red breach, the Issue (with its graph edge) is the whole
   attachment, and the root cause lives only in `issues.observation`.

The KRI update and the explanation share **one transaction** in `KriController::update`.
`KriService::update()` runs inside it.

**The Create form never shows the section.** A target set on a KRI that does not
exist yet has nothing to attach to, and the brief names the Edit form only. The
bulk editor `Kri/Thresholds.jsx` is not touched.

#### 6.4 Where enforcement happens, and atomicity

| Request | Recompute | When red and a field is missing |
|---|---|---|
| `UpdateKriRequest` | `forBoundaries` + `bandOf(target_value)` | 422 on `red_band.root_cause` / `red_band.action_point` / `red_band.timeline` |
| `RecordKriMeasurementRequest` | `PeriodService::resolve(measurement_date, periodTypeFor)` → `bandForReading` | 422, same keys |
| `SubmitKriReturnRequest` (champion submit) | per line: `bandForReading(kri, line.period, value)` | 422 on `lines.{i}.red_band.*` |
| `SaveKriReturnDraftRequest` | per line, **for storage only** | **Never a 422 for a missing explanation.** A draft may be incomplete. Explanation fields are stored only when the line is red. |

Payload key: `red_band: {root_cause, action_point, timeline (Y-m-d), owner_id?, timeline_change_reason?}`.
`owner_id` is tenant-bound with `Rule::exists`. On the champion path it must also
be the champion, a `business_unit_user` member of the department, or one of the
department's KRI contacts.

**`RecordKriMeasurementRequest`'s form must be able to send the fields.** The
record-measurement form on `Kri/Show.jsx` therefore embeds the same red-band
component. A validator that demands fields its own form cannot post makes red
readings unrecordable (standard §10). The ui-designer adds it to the Edit-form
spec. It is not a new screen.

**Submit is one transaction:** every reading through `KriMeasureBridge::recordMeasurement()`
(measurement date = the line period's `end_date`, `entered_by` = the champion,
`notes` = the comment), every breach, every root cause, every Issue and graph
edge, every line pointer, and the return's status. If one KRI fails, nothing is
written.

**`App\Events\KriBreachDetected` implements `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`.**
Outside a transaction it dispatches immediately, as today, so the nightly sweep
and the existing paths are unchanged. Inside one, it waits for commit and is
dropped on rollback. This is the one change to the measure engine. Without it, a
rolled-back submit would already have queued a breach notification.

### 7. The contact ladder (one function, two consumers)

`App\Support\Kri\KriContactLadder::rolesForLevel(int $level): list<KriContactRole>`:

| Level | Roles reached (cumulative) |
|---|---|
| 1 | `risk_champion` |
| 2 | + `team_lead` |
| 3 | + `unit_head` |
| ≥ 4 | + `group_head` |

- **Reminders** use `level = sequence`: reminder #1 is level 1, reminder #5 is
  level 4. The monthly request is level 1.
- **Issue escalation** uses `level = the effective escalation level` (§8.2).
- `recipientsFor(BusinessUnit $unit, int $level): Collection<User>` is resolved at
  send time. Inactive users and users without an email are returned so they can
  be recorded as `skipped`, not hidden.

### 8. Issues module

#### 8.1 What does not change

`CheckOverdueIssues` is unchanged. It marks `OVERDUE`, fires `IssueOverdue` →
`SendNotification`, then calls `escalateOverdueIssues()`. A KRI-raised Issue goes
through it unchanged, and one test proves it.

#### 8.2 The department ladder inside `IssueEscalationService`

One branch, keyed on `escalation_path === 'kri_department_contacts'`, and only
two places differ:

1. **Level selection.** The existing rule query runs unchanged and gives
   `ruleLevel` (with CBN acceleration). For a KRI-path Issue,
   `effectiveLevel = max(ruleLevel ?? 0, KriContactLadder::issueLevelForDaysOverdue($days))`,
   where the floor ladder is the constant `ISSUE_FLOOR_DAYS = [1 => 1, 2 => 7, 3 => 14, 4 => 30]`
   (level => minimum days overdue). Tenant rules can only make escalation
   **faster**, never stop it.
2. **Recipients.** `announce()` resolves `KriContactLadder::recipientsFor($issue->businessUnit, $effectiveLevel)`,
   active users only, and sends through the existing `NotificationService::sendMany`
   call shape. If that set is empty and a rule matched, it falls back to the rule's
   role, which is today's behaviour. Otherwise it logs.

**The invariants are kept, and tests assert them.** No `ESCALATED` status is ever
written. The guard `effectiveLevel <= currentLevel → skip` is unchanged, so no rung
re-fires. The log row is still written, with
`escalated_to_role = 'kri_department_contacts'` (23 chars, within `string(100)`)
and a `reason` naming the tier reached and the days overdue.
`current_escalation_level` is written exactly as today.

#### 8.3 `kri_breach` everywhere the source list is enumerated

| # | Location | Change |
|---|---|---|
| 1 | `app/Models/Issue.php` `SOURCES` | Add `'kri_breach'`. Add `SOURCE_LABELS` (value → label, `kri_breach` → "KRI breach"). |
| 2 | `app/Support/Metadata/FormFieldRegistry.php:275` (`issue_source` enum) | Derive the options from `Issue::SOURCE_LABELS` so they cannot drift again (the `EmergingRisk` precedent). |
| 3 | `object_attributes.enum_options` for `Issue.issue_source` (**data**) | Migration `…_100007` replays the upsert for that one attribute, storage columns only. Tenant-owned label, section, order and visibility are untouched. Precedent: `2026_09_05_100000`. |
| 4 | `app/Http/Requests/Issues/StoreIssueRequest.php:34` | No edit. `Rule::in(Issue::SOURCES)` picks the value up. |
| 5 | `app/Http/Controllers/Risk/IssueController.php:630` (`formOptions.sources`) | No edit. It reads `Issue::SOURCES`. |
| 6 | `app/Grids/Definitions/IssuesGrid.php:138-144` (Source filter) | Label options from `Issue::SOURCE_LABELS` (title-casing gives "Kri Breach"). |
| 7 | `app/Grids/Definitions/IssuesGrid.php:59` (Source badge) | Render the label from `SOURCE_LABELS`. |
| 8 | `resources/js/Pages/Issues/Show.jsx:287` (`titleCase(issue.source)`) | `IssueController::detail()` sends `sourceLabel`, and the page renders it. |
| — | `UpdateIssueRequest:37` (free string), `ApiResourceRegistry:148-157`, `WidgetSourceRegistry:90`, `ExportController:192`, `IssueEscalationService:75` | Data-driven or raw. No edit. |

`kri_breach` is a full member of the list and a user may pick it by hand. Nothing
keys behaviour on the source alone: the ladder keys on `escalation_path`, and the
"Raised from KRI breach" panel keys on the graph edge. So a hand-picked value is
only a label.

**Issue Show** gains the prop `kriOrigin: {kri:{id,code,name,url}, breach:{id,period,url}|null, return:{id,department,period,url}|null} | null`,
resolved from the graph edge, `measure_breaches.linked_issue_id` and
`kri_return_lines.issue_id`. The page shows the "Raised from KRI breach" panel
when it is non-null.

### 9. Directory (AD) data: read-only, at render time, through one interface

Contacts store `users.id` and nothing else. The hint comes through an ERM-side
contract, `App\Contracts\DirectoryProfiles`:

```php
/** @param list<int> $userIds  @return array<int, \App\Support\DirectoryProfile>  keyed by user id; absent = not directory-synced */
public function forUsers(int $organizationId, array $userIds): array;
```

`DirectoryProfile` is a value object: `synced: bool`, `department: ?string`,
`jobTitle: ?string`, `accountEnabled: ?bool`, `lastSyncedAt: ?CarbonImmutable`.
It is bound in `AppServiceProvider`, because ERM has no provider.
**integrations-engineer owns the implementation:** a batched read of BCMS's
directory-sourced `bcms_contacts` by `user_id`. It returns `[]` when
`features.bcms` is off. It never writes, and ADR 0018 stands. The mismatch rule
(directory department text against the unit's name and code) is
integrations-engineer's, and its output is only the prop `department_mismatch: bool`.
**Delivery skips only on `users.is_active = false` or a missing email.** Directory
signals are display flags, unless integrations-engineer amends this ADR.

### 10. Scope rules (who sees and does what)

`App\Support\Kri\KriDepartmentScope`:
- `unitIdsFor(?User): ?list<int>`. **Null** (every unit) for no user (console,
  queue) or a holder of `kri.all_departments`. Otherwise it is the user's
  `business_unit_user` rows, with `includes_descendants` expanded through
  `RcsaScope::subtreeOf()` (the public tree walk BCMS Phase 3 already reuses),
  **unioned with** the units the user is a `risk_champion` of. An empty list means
  nothing. It never means everything.
- `championUnitIdsFor(User): list<int>` is **exact** units where the user is a
  `risk_champion`. It never expands to descendants: a champion of Retail is not
  a champion of its branches.

| Surface | Rule |
|---|---|
| Library landing and department detail, dashboard card, drill-down | `kri.view` + `unitIdsFor()`. The Unassigned tile is visible to every `kri.view` holder (an unassigned KRI is not unit-scoped, as `RcsaScope::reaches(null)` holds). The existing `visibleTo()` (GraphScope) still applies to KRI rows. |
| Flat list | Exactly today's behaviour |
| Complete my KRIs (edit) | `kri.submit_return` + the return's unit in `championUnitIdsFor()` |
| Return read-only view | `kri.view` + the unit in `unitIdsFor()` |
| Remind | `kri.send_reminder` + the unit in `unitIdsFor()` |
| Edit KRI definition, thresholds, direction, owner | `kri.edit`, unchanged. **Champions are not granted it.** A champion holding only `risk-owner` gets 403 from `risk.kri.edit` and `risk.kri.update` through the route middleware. |

Out-of-tenant ids are 404 (the global scope). In-tenant, out-of-scope ids are 403
(the policy). Policies are named for their models (standard §3):
`KriReturnPolicy` (`view`, `complete`, `submit`, `reopen`, `remind`),
`KriDepartmentContactPolicy`, `OrganizationHolidayPolicy`. Lifecycle rules stay in
the controller and answer with a flash message, not a 403 (standard §3). Examples:
submitting a submitted return, or reopening when the period is closed.

**Reopen** is for the champion only. It is allowed while the reporting month's
`Period` and every line's `Period` are not closed. Risk Officers do not reopen;
that would be a second workflow, and nobody asked for it.

### 11. Working days and dates

`App\Support\Kri\KriWorkingCalendar`, with constants `TIMEZONE = 'Africa/Lagos'` and
`DUE_WORKING_DAYS = 5`. **The constants are not in config**, so a published config
cannot silently change a due date the code has never seen.
- `isWorkingDay(CarbonImmutable $day, int $orgId)` is true for Mon–Fri with no
  `organization_holidays` row for that date.
- `firstWorkingDayOf(int $year, int $month, int $orgId): CarbonImmutable`
- `addWorkingDays(CarbonImmutable $from, int $n, int $orgId)`. The start day is not
  counted. A request on Monday 2 November is due Monday 9 November.
- `today(): CarbonImmutable` is `now(TIMEZONE)->startOfDay()`. Every
  "today/overdue/first working day" decision uses it. `config('app.timezone')`
  stays UTC.
- **The reporting period** is `PeriodService::resolve(firstOfLagosMonth - 1 day, 'month', org)`.

### 12. The command, the schedule and the watchdog

```
kri:open-cycle
    {--date=          : Act as though today (Africa/Lagos) were this Y-m-d}
    {--force          : Open even if that date is not the first working day of its month}
    {--dry-run        : Report departments, KRIs due and resolved champions; write nothing, send nothing}
    {--organization=  : Only this organization id}
```

For each active organisation (or the one given), under `TenantContext::actingAs`:
1. Set `day = --date ?? KriWorkingCalendar::today()`. Without `--force`, if
   `day ≠ firstWorkingDayOf(day's month)`, report "not the first working day" and
   skip.
2. Resolve the reporting period (§11). `--dry-run` prints and stops here, before
   any write, including the run row.
3. Write ahead: `firstOrCreate` the `kri_cycle_runs` row on (org, period), with
   status `running` and `started_at`.
4. For each active business unit with at least one due KRI: `firstOrCreate` the
   return on its unique key (`requested_on = day`, `due_on = addWorkingDays(day, 5)`,
   `opened_at = now()`). Synchronise its lines. If the return has no `request`
   notice, insert one (`request:r{id}`) and dispatch after commit. Count the units
   that have no champion.
5. Mark the run `completed` with counts, or `failed` with `error`. Exit non-zero on
   failure. Warn when `MAIL_MAILER=log`, as `tprm:send-scheduled-reports` does.

**Idempotency comes from rows, not from the cron.** A re-run, a forced run or two
servers cannot open a second return (unique key) or send a second request
(unique `idempotency_key`). `--force` never means "send again".

**Schedule** (`routes/console.php`, ERM block). It runs daily, never
`monthlyOn(1)`:

```php
Schedule::command('kri:open-cycle')->dailyAt('07:00')->timezone('Africa/Lagos')->withoutOverlapping()->onOneServer();
Schedule::command('kri:cycle-watchdog')->dailyAt('10:00')->timezone('Africa/Lagos')->withoutOverlapping()->onOneServer();
```

That is 06:00 UTC, before `my:digest` (07:30 UTC), so the digest carries the new
return.

**`kri:cycle-watchdog`** (`{--organization=}`) is a failure when, for an active
organisation that has at least one active, non-automated KRI with a department:
- Lagos today ≥ `firstWorkingDayOf(this month)` and there is no `completed`
  `kri_cycle_runs` row for the reporting period; or
- a run has been `running` for more than 60 minutes; or
- a notice has been `queued` for more than 60 minutes with
  `recipients_resolved_at` null.

It warns (no failure) on recipients `failed` in the last 24 hours. On failure it
logs an error, notifies holders of `kri.send_reminder` in that organisation through
`NotificationService`, and exits 1. reliability-engineer may tighten the
thresholds and the alert channel, not the conditions.

**Rate limiting.** The once-a-day rule is the database key (§5). Separately, the
named limiter `kri-reminders` (in `AppServiceProvider`, 20 POSTs per minute per
user) guards the endpoint against abuse.

### 13. Audit (`risk_audit_trail` via `AuditTrailService`; `action_type` ≤ 20 chars)

| Event | Entity | `action_type` | Field / old → new |
|---|---|---|---|
| Contact added / removed | the **BusinessUnit** (so a department's history is one query) | `kri_contact_added` / `kri_contact_removed` | `field_changed` = role. The value is `user:{id} {name} <{email}>`. |
| Holiday added / removed | `organization_holiday` | `holiday_added` / `holiday_removed` | the date and name |
| Return opened / submitted / reopened | `kri_return` | `return_opened` / `return_submitted` / `return_reopened` | status |
| A resubmitted value changes | `kri_return` | `value_changed` | `field_changed` = `kri:{code}`. Old and new values. |
| A draft line is discarded at sync | `kri_return` | `line_discarded` | `kri:{code}`. The old value. |
| A reading recorded | `key_risk_indicator` | today's `recordChanges` | unchanged |
| Root cause on a breach | `measure_breach` | `update` | `root_cause` |
| Issue created / updated | `issue` | `create` / `recordChanges` | A timeline change carries `change_reason` |

**New morph aliases** in `MorphTypes::map()`: `kri_department_contact`,
`organization_holiday`, `kri_cycle_run`, `kri_return`, `kri_return_line`,
`kri_return_notice`. None of the new models uses `HasObjectIdentity`; they are not
graph objects.

### 14. Permissions (`RiskPermissionCatalog`, granted by migration through `SeedsPermissions`)

| Permission | Description (it is an authority, standard §2) | Granted to |
|---|---|---|
| `kri.submit_return` | Complete and submit your department's monthly KRI return. It reaches only departments where you are configured as risk champion. | `risk-owner`, `risk-manager`, `chief-risk-officer` |
| `kri.send_reminder` | Email a department's KRI contacts about an unsubmitted return. Each reminder reaches one tier further up the department. | `risk-manager`, `chief-risk-officer` |
| `kri.manage_department_contacts` | Choose who is each department's risk champion, team lead, unit head and group head for KRI returns and escalations. | `risk-manager`, `chief-risk-officer` |
| `kri.all_departments` | See every department's KRIs, returns and reminders, not only the business units you are assigned to. | `risk-manager`, `chief-risk-officer`, `risk-analyst` |
| `holiday.manage` | Maintain the organisation's holiday list. It moves the first working day of every month and every KRI return's due date. | `risk-manager`, `chief-risk-officer` |

`super-admin` resyncs to the whole catalogue, following `2026_09_07_100006`.
`roles()` in the catalogue gains the same grants, so the seeder and the migration
agree. `holiday.manage` is resource-named, not `kri.*`, because the list belongs
to the organisation. If a second module reads it, the name already fits.

**A champion needs `kri.submit_return` from their role.** Configuring a contact
grants nothing. The contacts screen flags a champion who lacks the permission
(`has_submit_permission: false`). The live-test user is given `risk-owner`.

### 15. Routes (all inside the existing `risk` prefix group; names explicit)

Register every `kri/…` literal route **before** `kri/{kri}`, as `dashboard`,
`thresholds` and `breaches` already are. `my-return` would otherwise bind as a KRI
id.

| Method | URI (`/risk/…`) | Name | Middleware | Controller → Inertia page |
|---|---|---|---|---|
| GET | `kri` | `risk.kri.index` | `permission:kri.view` | `KriController@index` → `Kri/Index` (departments by default; flat, see below) |
| GET | `kri/departments/unassigned` | `risk.kri.departments.unassigned` | `permission:kri.view` | → `Kri/Department` |
| GET | `kri/departments/{department}` | `risk.kri.departments.show` | `permission:kri.view` | → `Kri/Department` (`{department}` = `BusinessUnit` id) |
| GET | `kri/dashboard` | `risk.kri.dashboard` | unchanged | gains `returnsCard` and `?period=` |
| GET | `kri/returns` | `risk.kri.returns.index` | `permission:kri.view` | `KriReturnController@index` → `Kri/Returns/Index` (drill-down) |
| GET | `kri/returns/{return}` | `risk.kri.returns.show` | `permission:kri.view` | → `Kri/MyReturn` with `readOnly: true` |
| POST | `kri/returns/remind` | `risk.kri.returns.remind` | `permission:kri.send_reminder`, `throttle:kri-reminders` | redirect back with a flash |
| GET | `kri/my-return/{department?}/{period?}` | `risk.kri.my-return` | `permission:kri.submit_return` | `KriMyReturnController@show` → `Kri/MyReturn` (`whereNumber` on both) |
| PUT | `kri/my-return/{return}/draft` | `risk.kri.my-return.draft` | `permission:kri.submit_return` | redirect back |
| POST | `kri/my-return/{return}/submit` | `risk.kri.my-return.submit` | `permission:kri.submit_return` | redirect back |
| POST | `kri/my-return/{return}/reopen` | `risk.kri.my-return.reopen` | `permission:kri.submit_return` | redirect back |
| GET | `kri/settings/contacts` | `risk.kri.contacts.index` | `permission:kri.manage_department_contacts` | → `Kri/Settings/Contacts` |
| GET | `kri/settings/contacts/users` | `risk.kri.contacts.users` | `permission:kri.manage_department_contacts` | JSON user search |
| POST | `kri/settings/contacts` | `risk.kri.contacts.store` | `permission:kri.manage_department_contacts` | redirect back |
| DELETE | `kri/settings/contacts/{contact}` | `risk.kri.contacts.destroy` | `permission:kri.manage_department_contacts` | redirect back |
| GET | `settings/holidays` | `risk.holidays.index` | `permission:holiday.manage` | `HolidayController@index` → `Settings/Holidays` (`?year=`) |
| POST | `settings/holidays` | `risk.holidays.store` | `permission:holiday.manage` | redirect back |
| DELETE | `settings/holidays/{holiday}` | `risk.holidays.destroy` | `permission:holiday.manage` | redirect back |

**The flat list:** `risk.kri.index` renders `layout: 'flat'` (today's grid page,
unchanged) when the query has `layout=flat` or any grid-state parameter
(`search`, `filters`, `sort`, `direction`, `page`, `per_page`, `view`). Otherwise it
renders `layout: 'departments'`. Every existing link carrying grid state keeps
working. `KrisGridTest`'s bare `get(route('risk.kri.index'))` assertions become
stale bookkeeping and move to `?layout=flat`. `KrisGrid` gains a **Department**
column and a `department` filter (`business_unit_id`, plus an `unassigned`
option).

**Navigation** (`NavPresenter`, "KRI Monitoring"). Every permission equals its
route's middleware (`NavigationPermissionGateTest`), and each nav route is added to
`Ported::ROUTES`:
- **Complete my KRIs** → `risk.kri.my-return` (`kri.submit_return`). It is also
  hidden when `championUnitIdsFor($user) === []`, resolved once per request.
- **Department Contacts** → `risk.kri.contacts.index` (`kri.manage_department_contacts`).
- **Holidays** → `risk.holidays.index` (`holiday.manage`).

Form Requests: `StoreKriDepartmentContactRequest`, `StoreOrganizationHolidayRequest`,
`SendKriReminderRequest`, `SaveKriReturnDraftRequest`, `SubmitKriReturnRequest`,
`ReopenKriReturnRequest`, plus the amended `StoreKriRequest`, `UpdateKriRequest`
and `RecordKriMeasurementRequest`. Every FK is a tenant-bound `Rule::exists`
(standard §4).

### 16. Page prop contracts (frozen at this ADR; the ui-designer specs against them)

All counts come from **grouped queries**: `COUNT(*)` / `SUM(CASE WHEN … THEN 1 ELSE 0 END)`
`GROUP BY business_unit_id`, selecting only grouped columns (MySQL 8
`ONLY_FULL_GROUP_BY`). "Latest per group" is `MAX(id)` grouped, then `whereIn`. No
window functions, no CTEs, no JSON functions, no per-row query. A rate over an
empty set is `null`, not `0` (standard §5).

`ReturnSummary = {denominator, submitted, not_submitted, overdue, no_champion}` (ints).
`ReturnRef = {id, status, display_status, due_on, days_overdue|null, url}`.
`PeriodOpt = {id, label}`.

- **`Kri/Index`, `layout: 'departments'`:** `{layout, period: PeriodOpt|null, departments: [{id, code, name, parent_id, kri_count, red, amber, green, unbanded, return: ReturnRef|null, open_kri_issues, overdue_kri_issues, url}], unassigned: {kri_count, red, amber, green, unbanded, url}, scopeNote: string|null, flatUrl}`.
  The colour counts come from `key_risk_indicators.current_status` (the engine's
  mirror), over active, non-deleted KRIs. Departments are the active business
  units in scope, **including those with zero KRIs** (0.6).
- **`Kri/Department`:** `{department: {id, code, name, parent: {id,name}|null}|null (null = Unassigned), summary: {kri_count, red, amber, green, unbanded}, return: ReturnRef|null, openKriIssues: [{id, reference, title, status, due_date, is_overdue, url}], contacts: {risk_champion: Contact[], team_lead: Contact[], unit_head: Contact[], group_head: Contact[]}, grid}`
  (`KrisGrid` with the `department` filter locked). `Contact = {id, name, email, is_active}`.
- **`Kri/Dashboard`** gains the lazy prop `returnsCard: {period: PeriodOpt|null, periods: PeriodOpt[] (periods that have a cycle run, newest first, max 12), summary: ReturnSummary, cycleOpened: bool, drillDownUrl, canRemind}`.
  `?period={id}` selects. Partial reload is `only: ['returnsCard']`.
- **`Kri/Returns/Index`** (drill-down): `{period: PeriodOpt, periods: PeriodOpt[], filter: 'not_submitted'|'submitted'|'all' (default not_submitted), summary: ReturnSummary, rows: [{return_id, department: {id, code, name}, status, display_status, due_on, days_overdue|null, kris_due, kris_completed, champions: Contact[], has_champion, reminder_count, reminded_today, next_tier (1–4), last_reminder: {sent_on, sent_by: {id,name}|null, tier, status}|null, open_kri_issues, overdue_kri_issues, notices: [{id, kind, sequence, tier, status, sent_on, queued_at, sent_by: {id,name}|null, recipients: [{name, email, contact_role, status, skip_reason, sent_at, error}]}], view_url}], canRemind, scopeNote}`.
- **Reminder POST** body: `{return_ids: int[]}` (1–50, distinct, tenant-bound). The
  confirmation is a UI step. Flash `reminderResults: [{return_id, department, outcome: 'queued'|'already_reminded_today'|'submitted'|'out_of_scope', tier|null}]`.
- **`Kri/MyReturn`:** `{readOnly, state: 'ok'|'not_configured'|'not_open'|'empty', departments: [{id, name, url}], department: {id, code, name}|null, period: {id, label, start_date, end_date}|null, return: {id, status, display_status, requested_on, due_on, days_overdue|null, submitted_at, submitted_by: {id,name}|null, reopened_at, period_closed, can_save, can_submit, can_reopen}|null, progress: {completed, total}, lines: [Line], ownerOptions: [{id, name}], openKriIssues: [...as Kri/Department], urls: {draft, submit, reopen}}`.
  `Line = {line_id, kri: {id, code, name, description, unit, frequency, direction, green_boundary, red_boundary, target_value, url|null (only if the user may view the KRI)}, bandRule, value, comment, band, previous: {period_label, value, band}|null, recorded_value|null, red_band: {root_cause, action_point, timeline, owner_id, timeline_change_reason}, open_issue: {id, reference, status, due_date, action_plan, observation, owner: {id,name}|null, is_overdue, url|null}|null}`.
  The UI pre-fills an empty `value` from `recorded_value`. The default with no
  parameters is the champion's first department by name and its most recent
  return.
- **Draft and submit body:** `{lines: [{line_id, value: number|null, comment: string|null, red_band?: {root_cause, action_point, timeline, owner_id?, timeline_change_reason?}}]}`,
  in the order of the props. Errors come back as `lines.{i}.value` and
  `lines.{i}.red_band.*`.
- **`Kri/Edit`** gains `{departments: [{id,name}], bandRule, openKriIssue: {id, reference, status, due_date, action_plan, observation, responsible_owner_id, url}|null, openRedBreach: {id, period_label, root_cause}|null, redBandDefaults: {owner_id}}`.
  It posts `business_unit_id` and `red_band`. **`Kri/Show`** readings gain
  `{root_cause|null, issue: {reference, status, due_date, url|null}|null}` on red
  readings, and its record form gains `bandRule` for the default date's period
  plus the `red_band` payload. **`KriBreachesGrid`** gains a *Root cause*
  (truncated) column and an *Issue* (reference link) column.
- **`Kri/Settings/Contacts`:** `{departments: [{id, code, name, parent_id, contacts: {risk_champion: CContact[], …}, has_champion}], roles: [{value, label}]}`.
  `CContact = {contact_id, user_id, name, email, is_active, has_submit_permission, directory: {synced, department, account_enabled, last_synced_at}|null, department_mismatch}`.
  **User search JSON** (`?q=`, min 2 chars, max 20 results, name or email):
  `[{id, name, email, is_active, home_unit: {id,name}|null, has_submit_permission, directory: …|null}]`.
- **`Settings/Holidays`:** `{year, years: int[], holidays: [{id, date, name, weekday}], firstWorkingDays: [{month, date}]}`.
  The last item shows the effect of the list on the cycle.

### 17. Email, queue and "My responsibilities"

- `App\Mail\KriReturnRequested(KriReturnNoticeRecipient)` and `App\Mail\KriReturnReminder(KriReturnNoticeRecipient)`
  follow `MyResponsibilitiesDigest`. Content: the department, the period, the KRIs
  due (code, name), `due_on`, the days overdue (reminder only) and a deep link to
  `risk.kri.my-return` with `{department}/{period}`. Each is rendered from the
  return at send time. **No address is hard-coded anywhere**, including seeders.
- `MyResponsibilitiesService::for()` gains the section `kri_returns`: for each
  return in `championUnitIdsFor($user)` with status `draft` or `reopened`, an item
  with `due_at = due_on`, a "Overdue" badge when past due, and the my-return URL.
  `my:digest` picks it up with no further change.

### 18. Migrations (backend-engineer creates them; none is edited after merge)

These sort after `2026_10_03_100001`.

| File | Content |
|---|---|
| `2026_11_01_100001_add_business_unit_id_to_key_risk_indicators.php` | §1: the column, FK, index and backfill |
| `2026_11_01_100002_create_kri_department_contacts_table.php` | §2 |
| `2026_11_01_100003_create_organization_holidays_table.php` | §3 |
| `2026_11_01_100004_create_kri_cycle_tables.php` | §4: `kri_cycle_runs`, `kri_returns`, `kri_return_lines`, in that order |
| `2026_11_01_100005_create_kri_return_notice_tables.php` | §5: `kri_return_notices`, `kri_return_notice_recipients` |
| `2026_11_01_100006_grant_kri_department_cycle_permissions.php` | §14, through `SeedsPermissions::grantFromCatalog` plus the super-admin resync |
| `2026_11_01_100007_add_kri_breach_to_issue_source_options.php` | §8.3 row 3: the `object_attributes` replay. `down()` is a documented no-op. |

**Both-engine rules for all seven:**
- No `json` column anywhere in the new tables.
- Every `*_at` the application writes is `dateTime()`. `timestamps()` stays
  nullable (ADR 0022, guarded by `NoSelfUpdatingTimestampColumnsTest`).
- No `useCurrentOnUpdate()`. No DB `ENUM` on new tables.
- Every index name is explicit and ≤ 64 characters.
- No UPDATE or DELETE reads its own table in a subquery.
- Every `down()` drops what its `up()` created, except `100007`.

## Alternatives rejected

- **Resolve a KRI's department through `node_id` / `GraphScope`.** `node_id` is a
  one-time backfill that no writer maintains, and it may name an entity or a
  merged node. `GraphScope` scopes on entities. A department view built on it
  would be wrong for every KRI created since August.
- **Drafts as `measure_values` with `status = 'draft'`.** The nightly sweep does
  not filter on status, so the first draft in red would open a breach and notify
  the owner from a number nobody submitted.
- **A `reminders` table without recipients**, with the recipient list as json.
  Per-recipient delivery outcomes and retries need rows. JSON would also invite
  `JSON_CONTAINS`, which MariaDB 10.4 does not run.
- **A once-a-day rule enforced in PHP only** (`exists()` then `insert`). Two clicks
  race. The key cannot.
- **A `kri_id` column on `issues`.** The brief prefers the graph where it already
  links issues, and the edge is one row with no migration on a shared table. The
  cost is that `relate()` fails soft, so the service treats a null return as fatal.
- **Seeding `kri_breach` rows into `issue_escalation_rules` per organisation.** That
  is tenant configuration written by a migration, and it misses every organisation
  created afterwards. The code floor applies to all of them and stays overridable
  upwards.
- **Reusing `rcsa_scope.all_units` for the Library.** Same holders today, a
  different authority. The two would drift the first time someone narrows one.
- **Reading BCMS `BlackoutPeriod` for holidays.** Decision 0.2 forbids it: the risk
  module must not stop opening returns because BCMS is switched off.
- **`monthlyOn(1)`.** The 1st is often not a working day (the brief, and 2026-11-01
  is a Sunday).

## What this ADR deliberately does not do

- **No automatic reminders.** Reminders are manual (§2.4 of the brief). The cycle
  sends only the opening request.
- **No other channel.** Email only: no SMS, Teams or in-app reminder beyond the
  existing queue and digest.
- **No recurring-holiday rules**, and no holiday import.
- **No roll-up** of child departments' KRIs into a parent's return. No return for a
  unit with no directly assigned due KRI.
- **No review or approval step on a return.** Submit is final until the champion
  reopens it. Risk Officers neither approve nor reopen.
- **No write to Active Directory**, and no automatic AD-department → business-unit
  mapping (ADR 0018, decision 0.5).
- **No red-band section on the Create form or on `Kri/Thresholds.jsx`.**
- **No change** to `Issue` status handling, `CheckOverdueIssues`, or the priority of
  a reused Issue.
- **No bounce tracking.** "Sent" means the transport accepted it.
- **No fix** to two pre-existing defects found on the way, recorded here so they are
  not rediscovered. First, `IssueDashboardService:89` counts
  `issue_source = 'cbn_examination'`, a value `Issue::SOURCES` has never allowed,
  so that tile is always 0. Second, `UpdateIssueRequest:37` accepts any string as a
  source. Both are booked follow-ups, not this work's scope.
- **No module-published KRI is ever put in a return** (`is_automated`), even when a
  department is assigned to it.

## Consequences

- One nullable column on `key_risk_indicators`, six new tables, one `object_attributes`
  data replay and five permissions. No shipped migration is edited.
- `KriBreachDetected` now dispatches after commit when raised inside a transaction.
  Outside one, the behaviour is unchanged.
- `IssueEscalationService` gains one branch, keyed on `escalation_path`. Every
  other Issue is untouched.
- `risk.kri.index` changes its default rendering. Flat links with grid state still
  work, and one test file's bare assertions move to `?layout=flat`.
- A department's history (contacts, returns, reminders, deliveries, Issues) can be
  answered from rows. None of it comes from logs.

## Amendment 1 (2026-11-01)

**Why.** ui-designer specified the screens against this ADR (`docs/ui/kri-cycle/00`–`10`)
and logged the places where the contract cannot carry them. backend-engineer was
already building, so every item below is **additive** unless it says
**CHANGES**. Each item names the section it amends. There is one schema change
(A1.8, a column in a migration that has not been merged). There is one route
middleware change (A1.1).

**A1.1 — BLOCKER: non-champion reminder recipients got a 403. CHANGES §10, §15, §17.**
`risk.kri.returns.show` is already one of the nineteen routes (GET
`kri/returns/{return}` → `Kri/MyReturn` with `readOnly: true`). It was gated
`permission:kri.view`, which team leads, unit heads and group heads usually do not
hold.
- **Route middleware changes** from `permission:kri.view` to **`can:view,return`**.
  `RouteAuthorizationTest` accepts `can:`. No nav entry points at this route, so
  `NavigationPermissionGateTest` is unaffected.
- **`KriReturnPolicy::view(User, KriReturn)`** is true when any of these holds:
  - the user holds `kri.all_departments`;
  - the user holds `kri.view` and the return's unit is in `KriDepartmentScope::unitIdsFor()`;
  - the user is a `kri_department_contacts` row of **any role** for the return's
    `business_unit_id`. The match is exact, with no descendants.

  Permission, then tenancy, then the domain rule (standard §3). Out of tenant is
  still 404.
- **Read-only props for a viewer without `kri.view`:** `Line.kri.url` is null, the
  `openKriIssues[].url` / `open_issue.url` values are null unless
  `can('view', $issue)`, `departments` is `[]` and `urls` are null.
- **Email link (§17), per recipient:** a recipient whose `contact_role` is
  `risk_champion` gets **Complete my KRIs** → `risk.kri.my-return/{department}/{period}`.
  Every other role gets **View the return** → `risk.kri.returns.show/{return}`.
  Both links are always valid for that recipient, because the policy admits every
  contact. The mailable receives the return id through notice → return.

**A1.2 — `Kri/Show` red-band props. Amends §6.2, §16.** `Kri/Show` gains:
- `openKriIssue`, the same shape as `Kri/Edit`'s;
- `ownerOptions: [{id, name}]`, the same source as the KRI form's `users`
  (active users in the tenant);
- `redBandDefaults: {owner_id}`.

The **default owner on both Risk Officer paths, Edit and Record, is
`kri_owner_id ?? owner_id`**. On the champion path it is the champion.
`bandRule` gains an optional **`period: {id, label, start_date, end_date}`** on
`Kri/Show` and on every `Line.bandRule`. The client may show a live band only
while the entered date lies within `period`. For any other date the badge reads
"checked when you save", and **the server's 422 is the authority and the
fallback**. **Yes: the "Bands in force" chips are corrected** to the same wording
as the corrected legend (§6.1), generated from the same helper, so the page has
one wording.

**A1.3 — `Kri/Create` gains `departments: [{id, name}]`. Amends §1, §16.** Its
source and the select are the same as Edit's. `StoreKriRequest` already accepts
`business_unit_id` (§1).

**A1.4 — Never-read KRIs are not green. Amends §16 (`Kri/Index` departments and
unassigned, `Kri/Department.summary`).** A KRI **has a reading** when
`last_measurement_at IS NOT NULL OR last_measurement_date IS NOT NULL OR` it
appears in `LEFT JOIN (SELECT DISTINCT kri_id FROM kri_measurements) km`. That is
a derived table, valid on both engines, with no correlated subquery inside an
aggregate.
- `red`, `amber` and `green` count only KRIs that have a reading, by `current_status`.
- `unbanded` counts KRIs that have a reading and whose `current_status` is not
  one of the three.
- A **new key, `not_read`,** counts KRIs with no reading.
- `kri_count` = red + amber + green + unbanded + not_read.

**A1.5 — Drill-down payload is capped. Amends §16 (`Kri/Returns/Index`), adds a route.**
- `rows[].notices` carries **at most the latest 3 notices**, newest first. Rows
  gain `notices_total`.
- The full history is loaded on expand from **GET `kri/returns/{return}/notices`**,
  named **`risk.kri.returns.notices`**, with middleware `can:view,return`. It
  returns JSON `{notices: Notice[]}` with every notice, newest first, each with its
  `recipients`.
- Server shape: one query for the page's notices, sliced in PHP, then one query for
  the recipients of the kept notice ids. No window function.

**A1.6 — Drill-down rows. Amends §16.**
- Rows gain **`contact_counts: {risk_champion, team_lead, unit_head, group_head}`**.
  Each count is of contacts who **could actually receive** a reminder: the user is
  active and has an email. It comes from one grouped query on
  `(business_unit_id, role)`.
- **Server row order:** `display_status` rank first (`overdue`, `reopened`,
  `in_progress`, `not_started`, `submitted`), then `days_overdue` descending, then
  department name ascending.

**A1.7 — Direction spelling. Amends §6.1, §16.** **Every read prop uses the
engine's spelling, `higher_worse` / `lower_worse`**. That covers `bandRule.direction`,
`Line.kri.direction` and `Kri/Show`. The form field `direction` on Create and Edit
keeps the form spelling, `higher_is_worse` / `lower_is_worse`, because that is
what `StoreKriRequest` and `UpdateKriRequest` validate. `kri-band.js`'s
`ruleFromBoundaries()` takes the form spelling and returns a rule in the engine
spelling, as `KriBandRule::forBoundaries()` does.

**A1.8 — Optimistic locking on returns. SCHEMA, amends §4, §16.**
- `kri_returns` gains **`lock_version` `unsignedInteger`, NOT NULL, default 0**. It
  goes into `2026_11_01_100004_create_kri_cycle_tables.php`, which is unmerged and
  has never been deployed, so editing it does not breach "no edit after merge". If
  100004 has already run on a shared database, it goes into a new
  `2026_11_01_100008_add_lock_version_to_kri_returns.php` instead.
- `return.lock_version` is in the `Kri/MyReturn` props. The draft, submit and
  reopen bodies carry `lock_version`.
- The service compares the posted value with the row it holds under `lockForUpdate`.
  On a mismatch it answers **422 on key `lock_version`** ("Someone else saved
  this return since you opened it. Reload to see their changes; your entries are
  kept on this page."). It is **not a 409**, because Inertia treats a 409 as an
  asset-version reload.
- Each successful draft save, submit and reopen, and each line sync that changes
  lines, increments it. Last-write-wins is rejected.

**A1.9 — Risk Officers and contacts see draft values in the read-only view. Amends §16.**
Yes. Values, comments and the draft red-band text are shown, each marked **Draft
— not submitted**. A draft is still not a reading: nothing counts it (§4).

**A1.10 — Smaller contract items. Amends §10, §13, §15, §16.**
- **Reopen reason.** `ReopenKriReturnRequest` body: `{lock_version, reason}`.
  `reason` is required, a string of 10 to 500 characters. It is stored as
  `change_reason` on the `return_reopened` audit row. There is no new column.
- **`not_open`.** `Kri/MyReturn` gains the top-level `opensOn: 'Y-m-d'|null`. This
  is `firstWorkingDayOf` the month after the requested period, using today's
  holiday list. It is labelled as the expected date.
- **Holidays.** `firstWorkingDays[]` gains `moved_by_holiday: bool`, which is true
  when a holiday moved the first working day off the month's first Mon–Fri.
- **`returnsCard`** gains `scopeNote: string|null`, worded as on the drill-down.
- **POST bodies, confirmed:**
  - contacts: `{business_unit_id, user_id, role}`;
  - holidays: `{holiday_date (Y-m-d), name (max 150)}`.

  Errors come back under those keys.
- **Drill-down query keys, confirmed:** `?period={id}&filter=not_submitted|submitted|all`.
- **Spec assumptions confirmed without change:**
  - `reminderResults` is a flash value, read from `usePage().props.flash.reminderResults`.
  - A reminder to a department with no champion still uses up its daily slot and
    its sequence number. The notice row is written before recipients are resolved
    (§5).
  - For Unassigned, `Kri/Department.contacts` is `null`.
  - A weekend holiday is accepted, and the screen warns.
  - An inactive user may be added as a contact, flagged, and is skipped at send.

**A1.11 — The Edit KRI form does not re-ask on every save. CHANGES §6.3, §6.4
(`UpdateKriRequest` row).** With `newBand = bandOf(forBoundaries(posted), posted target)`
and `oldBand = bandOf(forBoundaries(stored), stored target)`, the explanation is
**required** only when `newBand = red` **and** one of these holds:
- (a) `oldBand ≠ red`, meaning the target moved into red, whether by a new target
  or by new boundaries or direction;
- (b) the target value changed while red, compared as decimals at the column's
  precision;
- (c) the KRI has no open KRI Issue (`openIssueFor()` is null).

Otherwise `red_band` is optional. If it is posted with any non-empty field, all
three required fields are validated, and `applyToTarget()` updates the reused
Issue. If it is absent, no Issue or breach is touched. The client mirrors this rule
from `Kri/Edit`'s initial `target_value`, `bandRule` and `openKriIssue`. The server
is the authority. The champion and record paths are unchanged: every new reading
in red needs the explanation, prefilled from the open Issue.

**A1.12 — Nav prefix. Amends §15.** The "KRI Monitoring" group's `prefixes`
becomes `['/risk/kri', '/risk/settings/holidays']`.

**A1.13 — Reminder send-time rules. Amends §5, §17.**
- **Re-check at send time.** `DispatchKriReturnNotice`, before it resolves
  recipients, and `SendKriReturnNoticeMail`, before each send, reload the return.
  If it is `submitted`:
  - every pending recipient becomes `skipped` with `skip_reason = 'return_submitted'`;
  - the notice becomes **`cancelled`**, a new value of the `status` string column,
    so no schema change.

  The drill-down shows "Not sent: the return was submitted first".
- **Sender identity.** The From address and name are the application's configured
  `mail.from`. There is no per-module From and no Reply-To header.
- **No reply handling.** Replies are not processed. Templates may say "Replies to
  this email are not read." and must give no instruction to reply.

**Already settled, no change:** focus moves to the red-band section on Tab or Enter,
not mid-keystroke. The aria-live announcement fires after 700 ms idle or on blur,
as specs 07 and 08 say.

**Not changed by this amendment:** the remaining ui-designer gaps are copy or
optional, and nobody asked for them here:
- `activeBreachCount` and `parent_name` on the Library (01);
- directory data on `Kri/Department` contacts (02);
- a mismatch verdict in contact-search results (05);
- a sender name and a completed-count line in emails (09, 10).

Raise them as a separate request if wanted.
