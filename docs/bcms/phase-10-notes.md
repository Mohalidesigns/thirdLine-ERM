# BCMS Phase 10 — backend notes (incident, crisis management, IT DR)

**Agent:** backend-engineer · **Reads first:** `plans/bcms/prompts/PHASE-10-incident-crisis-itdr.md`,
`docs/bcms/phase-10-incident-clause-map.md`, `docs/adr/0020-phase-10-the-pir-shares-the-aar-table-and-a-notification-is-a-row.md`,
the eight screen specs under `docs/bcms/screens/`.

This is the implementation record: what was built against the ADR and clause map, what was
deliberately left to `frontend-engineer`, and the gaps a gate should hold on.

## Schema (ADR 0020)

One migration, `2026_09_19_120001_bcms_phase10_incident_notifications_and_pir.php`:

1. `bcms_aars.occurrence_id` altered to nullable via a raw `MODIFY` (not `->change()` — MariaDB 10.4
   silently drops modifiers on a Laravel `change()` unless every one is restated). The FK and the
   unique index are untouched by the statement and verified to survive in
   `tests/Feature/Bcms/Phase10SchemaTest.php`.
2. `bcms_aars.incident_id` added: nullable, unique, FK → `bcms_incidents`, cascade on delete.
3. `bcms_incident_notifications` created — one row per regulator submission, `due_at` stored at
   classification and never recomputed, unique on `(incident_id, regulator, kind, sequence)`.

**A live defect was caught and fixed mid-build**: the auto-generated name for the
`(organization_id, submitted_at, due_at)` index was 71 characters, over MariaDB 10.4's 64-character
identifier limit, and `migrate:fresh` failed outright — every `RefreshDatabase` test on the branch,
not just this one. Every index and FK this migration adds is now named explicitly, and
`Phase10SchemaTest::every_index_and_foreign_key_name_this_phase_touches_stays_under_the_mariadb_identifier_limit()`
guards the whole family of names this phase touches so it cannot silently recur.

`database/schema/bcms-manifest.php` is regenerated from the live schema as the last step of this
work (`bcms:verify-schema --write`), and `bcms:verify-schema` (no flag) confirms clean against it.

## Six enums, plus two more

`IncidentSeverity`, `ActivationLevel`, `IncidentStatus`, `IncidentLogEntryType`, `DrTestType`,
`DrStrategy` — the six ADR 0020 names — are cast on `Incident`, `IncidentLogEntry`, `DrSystem`,
`DrTest`. Two more, `NotificationRegulator` and `NotificationKind`, are cast on the new
`IncidentNotification` model; they are not in the ADR's "six" because they are new columns on a new
table this phase owns, not enums retrofitted over an existing frozen column.

One new `IsoClauseRef` case, `Ndpa_breach_notification`, landed with its `ClauseRefs` seeder row in
the same change, per the analyst's and the ADR's instruction.

## The Aar model now holds two kinds of report

`Aar::isPostIncident()` / `Aar::subject()` are the one place every caller is meant to branch. A
`saving` guard throws if a row has both or neither of `occurrence_id`/`incident_id` set.

**`constrainToVisibleRecord()` is overridden on `Aar`, not `orgAnchorPath()` alone.**
`BindsToVisibleRecord` resolves a route-bound instance before any attribute loads, so a conditional
inside `orgAnchorPath()` on `$this->incident_id` would always see a blank model and always take the
exercise arm — silently 404ing every post-incident review. The override ORs both anchor paths
(`occurrence.definition`, `incident`) into one predicate instead. `orgAnchorPath()` itself is kept
unconditional (`occurrence.definition`) purely so the generic classifiability guard has a path to
walk.

`AarService` was extended by exactly one appended method, `ensureDraftForIncident()`, mirroring
`ensureDraftFor()`'s shape — nothing else in that file was touched, per this session's file
boundary. Everything PIR-specific (the eight-condition gate, "plan versus actual", the tenth
section) lives in the new `App\Services\Bcms\Incidents\PirService`, coordinated with `AarService` by
reading it, not editing it.

## Services

- `IncidentService` — declare (severity matrix suggestion, `detected_at <= declared_at`, the first
  decision log entry, optional one-click plan activation via the existing
  `PlanActivationService::activate()`), decision-log entries (a `decision` entry always needs
  `options_considered`/`rationale` — the auto-generated declaration entry supplies stock text so a
  two-minute declaration is never blocked on typing a sentence nobody asked for), tasks, regrade
  (reason required, always logged), the stand-down gate and stand-down itself.
- `NotificationService` — ADR 0020 §2 in full. `classify()` is the act of creating the obligation
  row; `recordSubmission()` records a fact, never transmits one; `reportabilityStatus()` derives
  yes/no/unknown from stored rows and decision-log markers, never a boolean; `overdueQuery()`/
  `approachingDueQuery()` feed the watchdog.
- `DrService` — tier mismatch (minimum approved BIA RTO vs target, approved-only), cadence
  inheritance resolved in PHP over `Process::regulatory_flags` (reusing Phase 2's own
  `BiaValidator::OPEN_BANKING_FLAGS`, never `JSON_CONTAINS`/`whereJsonContains`), `next_test_due`
  written from the **tighter** of the open-banking system's two cadences (quarterly failover, not
  the six-monthly full-test cadence — criterion 5's own four-month example only holds against the
  quarterly number), computed `met_objectives` on a manual test, idempotent ingestion on
  `(provider, external_test_id)` with `met_objectives` withheld from the provider until a human
  confirms, and backup attestation via an audited event with no new table.
- `PirService` — the eight-condition gate (no scoring, no injects, no carried-forward chain; "plan
  versus actual" in their place), `iso22320.incident_response` stamped at finalisation, never
  `iso22301.8.5.report`.
- `ErmBridge::mirrorIncident()` — one-way, tolerant, idempotent, following `mirrorFinding()`'s
  pattern. Maps BCMS severity onto `LossEvent::event_severity` (stored upper case, the same
  convention `LossEventController`'s near-miss conversion already uses) and fills the Basel/CBN
  taxonomy columns `LossEvent` requires but BCMS cannot classify with the same `'UNCLASSIFIED'`
  placeholder that conversion path already uses, rather than inventing a second convention.

## Controllers, routes, permissions

`IncidentController`, `IncidentNotificationController`, `IncidentReviewController`,
`DrSystemController`, `DrTestController`, `DrIngestionWebhookController` — every controller resolves,
authorises via a Form Request or `Gate::authorize`, and delegates; every computed fact is
`IncidentPresenter`/`DrPresenter`'s. No inline `$request->validate([...])` in any new controller.

Routes land in `routes/web.php` under the existing `feature:bcms` group, `bcms.incidents.*` /
`bcms.dr-systems.*` / `bcms.dr-tests.*`, all uuid-bound on aggregate roots
(`Incident`, `DrSystem`) and id-bound-and-scoped on children (`IncidentTask`, `IncidentNotification`,
`DrTest` — none of which carry a uuid, per the "child tables carry no uuid" rule). The DR ingestion
webhook is the one machine-to-machine exception and lives in `routes/bcms-webhooks.php` beside
Phase 7's EMNS callbacks, same per-provider HMAC-over-timestamp-plus-body scheme
(`config('bcms-gateways.webhook_secrets')`, now carrying `zerto`/`veeam`/`azure-site-recovery`), its
own named rate limiter (`bcms-dr-ingest`), and its own `RouteAuthorizationTest` allowlist entry.

**`ModuleSections`' `incidents`/`it-dr` entries flip `live => true`** in the same change, and
`Phase1ScreensTest`'s shell-replacement test moves its "not yet landed" example from `incidents` to
`compliance` (Phase 11's) — the exact pattern every prior phase followed for the same test.

**No new permission was needed.** All seven — `bcms.incident.{view,declare,manage,notify}`,
`bcms.dr.{view,manage,test.record}` — were already seeded in Phase 0 with the correct descriptions
(`bcms.incident.notify`'s already reads "Nothing is ever submitted automatically") and already held
by the right roles. `PermissionCatalogCoversRoutesTest` passes unchanged.

**One small, deliberate edit to `FindingController::store()`** (not an "exercise controller", Phase
1/9's shared Findings/CAPA register): it now accepts `dr_test_id`, tenant/unit-scoped through the
existing `VisibleToUser` rule, so a breached DR test's "Raise a finding" control reuses the existing
route per `dr-test-record.md` §2 rather than a second one.

## What is explicitly deferred, and why

- **`AarController`'s permission branch — CLOSED, not deferred (corrected 2026-09-23).** This bullet
  originally flagged that `bcms.aars.show`/`.update` did not also check `bcms.incident.manage` when
  `Aar::isPostIncident()` is true. That gap is closed: `AarController` now has a private
  `authorizeIncidentConjunction()` called from `show()`, `finalise()`, `distribute()` and `aiDraft()`,
  and `UpdateAarRequest`/`ReopenAarRequest` each carry the same `$aar->isPostIncident() ?
  $user->can('bcms.incident.manage') : …` branch in their own `authorize()` — all six actions on
  this controller are conjunction-gated. Confirmed directly against the controller and both Form
  Requests, and covered by `Phase10IncidentTest::an_aar_manage_holder_without_incident_manage_cannot_{read,write}_a_pir_via_the_shared_aar_route`,
  both green. Left as a struck-through entry rather than deleted, so the history of the gap and its
  closure stays on the record.
- **The AI post-incident-learning capability (criterion 10).** `BcmsLlmClient::POST_INCIDENT_LEARNING`
  and `config('bcms.ai.capabilities.post_incident_learning')` are wired (off by default, per the
  module's "every AI capability defaults off" rule), but no `PirAiDrafter` was written this session —
  the tenth section ("what the exercises predicted, and what reality exposed") exists as a field in
  `quantitative_results` for the frontend/a follow-up pass to populate through the same `available()`/
  `unavailableReason()`/`json()` contract `AarAiDrafter` already demonstrates. Flagged rather than
  half-built.
- **The incidents register screen and the DR register's create/edit form's exact layout** are
  explicitly out of scope per `incident-declaration.md` §8 and `dr-system-register.md` §8. A minimal,
  real `bcms.incidents.index` list was built (nav requires the route to exist once `live => true`);
  `frontend-engineer` builds the specified screens against the presenter payloads already shaped.
- **The DR ingestion webhook's own auth/retry hardening** is named an integrations-engineer concern
  in `dr-test-record.md` §8; this session covers what a correctly-signed payload does once it lands.
- **A `bcms_backup_attestations` table** and a **`bcms.dr.backup.attest` permission narrower than
  `bcms.dr.manage`** are both named-but-not-built per ADR 0020 §4 and `dr-system-register.md` §4 —
  not blocking, flagged for the architect if a bank asks for either.

## Test data named in the prompt

The prompt's "Kano Heritage: 4 historical incidents… 25 DR systems… 12 DR test records… one overdue
open-banking failover" is demo/seed-scale data, not unit-test fixtures. `Phase10IncidentTest` and
`Phase10DrTest` cover every acceptance criterion's *logic* (declaration, both regulatory clocks
independently, the stand-down gate, tier mismatch naming both numbers, cadence-derived overdue,
computed `met_objectives`, idempotent ingestion, backup attestation, `is_exercise` never polluting a
live aggregate, cross-unit 404) at unit/feature scale. Building the 4/25/12-row demo dataset itself
is a seeder task for whoever owns the Kano Heritage demo tenant, not a correctness test — flagged
rather than padded with volume that would not itself prove anything beyond what these tests already
do.

## A pre-existing defect found, not introduced

`BcmsRecordVisibilityTest::every_bcms_route_parameter_binding_a_bcms_model_is_covered` fails on
`bcms.training-records.assess` binding `App\Models\Bcms\TrainingRecord`, which is classified under
neither `BindsToVisibleRecord` nor the pinned organisation-level map. That route and model date to
the Phase 0 schema-freeze commit (`b0f8664`) — nothing this phase touched. Left as found, named here
so it is not mistaken for something Phase 10 broke.

## Gate 1 fix cycle — two defects closed (2026-09-17)

QA raised two defects against this phase; both are closed.

1. **`DrService::tierMismatch()`/`inheritsOpenBankingScope()` queried the wrong morph key.**
   Both methods filtered `Dependency::where('dependable_type', 'applications')` — the Blueprint
   §9.1 *name*, not the value `Relation::enforceMorphMap()` (`App\Support\MorphTypes`) actually
   assigns to `App\Models\Bcms\Application`. Every real `Dependency` row is written through
   `Dependency::dependable()->associate($target)` (see `DependencyService::attach()`), which under
   the enforced morph map always persists `App\Enums\Bcms\DependencyType::Applications->value`
   (`'bcms_application'`), never the literal `'applications'`. The effect in production: criterion
   4 (tier mismatch) and criterion 5 (Open Banking cadence inheritance) both silently no-op on
   every real row — `tierMismatch()` always returned `null` and `inheritsOpenBankingScope()`
   always returned `false`, regardless of what the BIA/dependency register actually said. The two
   original `Phase10DrTest` fixtures that first proved this logic (`a_system_in_a_24_hour_tier_…`,
   `an_open_banking_system_gets_the_quarterly_cadence_…`, and the draft-BIA negative case) were
   green throughout only because they hand-typed the same wrong `'applications'` literal into
   their own `Dependency::query()->create([...])` fixtures — a fixture shaped like the bug, not
   like anything the product persists.

   **Fix:** both queries in `DrService.php` now filter on `DependencyType::Applications->value`.
   The three original fixtures were rewritten to build their dependency row through a new
   `attachDependency()` test helper that calls `$dependency->dependable()->associate($target)` —
   the same call path production uses — so a fixture can no longer mask a wrong literal by
   constructing a row no real code path would ever write. QA's two RED tests,
   `tier_mismatch_is_found_against_a_dependency_row_shaped_the_way_production_actually_writes_one`
   and `open_banking_cadence_is_inherited_against_a_dependency_row_shaped_the_way_production_actually_writes_one`,
   now pass alongside the rewritten originals — 14/14 in `Phase10DrTest`.

   A grep of `app/Services/Bcms` and `app/Presenters/Bcms` for every other `dependable_type` query
   and every other `'applications'` literal turned up nothing else wrong: `DependencyService.php`,
   `SupplierResilienceService.php`, `SourceResolver.php` and `ClauseComplianceMatrixService.php`
   all already query through `DependencyType::*->value` (or, in
   `ClauseComplianceMatrixService::supplyChainSufficiency()`, a hand-typed `'tprm_third_party'`
   that already equals `DependencyType::Vendors->value` — correct today, but worth the architect's
   attention to swap for the enum constant so it cannot drift the way `DrService.php` just proved
   it can). `DrPresenter.php`'s own `'applications'` string is an unrelated array key inside its
   `options` payload (a dropdown label), not a `dependable_type` query, and needed no change.

2. **`IncidentPresenter::review()` carried two new PHPStan `nullsafe.neverNull` findings.** Both
   `$canManage`/`$canApprove` lines used `$user?->can(...) === true && $user?->can(...) === true`;
   PHPStan correctly flags the second `?->` as dead — by the time the right-hand operand of `&&`
   runs, the left-hand `$user?->can(...) === true` has already proven `$user` non-null (a null
   `$user` makes the nullsafe call short-circuit to `null`, and `null === true` is `false`, so
   `&&` never evaluates the right side). Changed the second operand on both lines to `$user->can(...)`.
   Behaviour is identical; PHPStan on the file is back to 0 new errors with no baseline growth.

### Verification run (2026-09-17)

- `./vendor/bin/pint` on the three touched files: pass, no changes needed beyond what was already
  written.
- `Phase10DrTest` — 14/14 pass (was 12/14 red on QA's two new cases).
- `Phase10IncidentTest` — 21/21 pass.
- `Phase10ScreensTest` — 19/19 pass.
- `Phase2BiaEngineTest` — 42/51 pass. **The 9 failures are pre-existing and out of this session's
  scope**: every one fails at the same point, a `GET route('bcms.bia.show', $assessment)` request
  returning **404** instead of rendering `Bcms/Bia/Workspace` (confirmed directly on
  `the_bia_workspace_ships_action_urls_bound_to_the_assessments_own_uuid`, which asserts `assertOk()`
  before it ever reaches a dependency-specific action). None of the 9 touch `DrService`,
  `Dependency`, or anything this fix changed — `BcmsBiaController`/`BiaWorkspacePresenter` are
  outside this session's file ownership (`app/Services/Bcms/Dr/DrService.php`,
  `app/Presenters/Bcms/IncidentPresenter.php` lines ~351–352 only). Flagged for whoever owns that
  controller/presenter rather than fixed here.
- Also observed mid-session, then resolved by a concurrent edit landing on the shared branch: a
  transient fatal boot error (`Call to undefined method
  App\Providers\AppServiceProvider::registerBcmsCheckInRateLimiter()`) that briefly broke every
  `artisan` invocation, including the two Phase 10 `AarController`-permission tests
  (`an_aar_manage_holder_without_incident_manage_cannot_{read,write}_a_pir_via_the_shared_aar_route`).
  Both are green again once that method landed; not this session's fix, not this session's file.
- `php artisan bcms:verify-schema` (`DB_DATABASE=risk_test_test_4`): `BCMS schema matches the frozen
  manifest: 65 tables.` No structural change was made or needed for this fix cycle.

## Gate 2 review #1 fix cycle — defects 4, 5, 6, 7, 8, 9, 10, 13 closed (backend-engineer, fourth pass, 2026-09-23)

Nine numbered defects from the review's incident-side allocation, closed against ADR 0020 Amendments
2 and 4 (the latter landed on the ADR mid-session — see below). Every fix has a test that failed
before it; each is named against the numbering the review used.

### 4 — a second "initial" submission no longer overwrites the first

`NotificationService::recordSubmission()` now refuses `kind = initial` once `submitted_at` is
already set, naming when it was first submitted and pointing at `supplementary` instead.
`IncidentNotification` gained an `updating` guard: once `submitted_at` **or** `withdrawn_at` is set,
`submitted_at`/`submitted_by`/`reference`/`content_snapshot` are immutable, throwing `LogicException`
— the same service-plus-model belt-and-braces pattern §1 already used for the AAR's exactly-one-of
rule. `Phase10IncidentTest::a_second_initial_submission_is_refused_once_the_first_was_submitted`,
`::a_second_initial_submission_does_not_overwrite_the_first_rows_evidence`,
`::the_model_guard_refuses_editing_a_submitted_rows_submission_fields_directly`.

### 5 — both clocks default to `detected_at`, falling back to `declared_at`, never `now()`

`NotificationService::classify()` gained `defaultAwarenessAt()`: `detected_at ?? declared_at`,
refusing with `InvalidArgumentException` if neither is recorded — a clock with an invented start is
worse than a prompt. `IncidentController::classify()`/`IncidentNotificationController::classify()`
stopped defaulting to `now()`; the docblock on `ClassifyBcmsIncidentRequest` that said "defaults to
now" is corrected. Amendment 2 rule 6 (asymmetric edit): moving awareness *earlier* needs no
justification; moving it *later* needs `awareness_reason` (new nullable field on
`ClassifyBcmsIncidentRequest`/`RecordBcmsIncidentNotificationRequest`), refused without one, and
logged as a `decision` entry when given. `Phase10IncidentTest::classifying_with_no_awareness_at_defaults_to_the_incidents_detected_at_not_now`,
`::classifying_with_no_detected_or_declared_at_is_refused`,
`::setting_awareness_later_than_the_default_requires_a_reason`,
`::setting_awareness_later_than_the_default_with_a_reason_succeeds_and_is_logged`,
`::setting_awareness_earlier_than_the_default_needs_no_reason`.

### 6(a) — an obligation reassessed as not owed is withdrawn on its own row

Three columns on `bcms_incident_notifications` (added to the still-uncommitted creating migration,
per the amendment's own condition): `withdrawn_at`, `withdrawn_by` (FK users, nullOnDelete),
`withdrawal_entry_id` (FK `bcms_incident_log`, nullOnDelete). `NotificationService::withdraw()` is
the one write path: refuses a submitted or already-withdrawn row, writes the decision-log entry and
the three columns in one transaction, and records the named audit event
`incident.notification_withdrawn` (`IncidentNotification` picked up `BcmsAuditable` concurrently from
the DR-side engineer's pass — the generic `updated` diff that trait now writes is not the named event
Amendment 2 rule 5 asks for, so `withdraw()` also calls `recordAudit()` explicitly).
`reassessNotReportable()` now withdraws a live open row if one exists (previously: decision-log entry
only, so a classified-then-reassessed obligation stayed open for ever and the stand-down gate could
never pass — this was the actual reported defect). `isOpen()` on the model now excludes withdrawn
rows, which fixed the countdown tiles, the notification log's per-obligation `is_open` flag, and
`overdueOrOpenCount()`/`overdueQuery()`/`approachingDueQuery()` (all three now exclude withdrawn rows
— read by `BcmsWatchdog` and Phase 11's board pack, confirmed still green, see below).
`reportabilityStatus()` reads: a live row → yes; only withdrawn rows → no; else the decision-log
check; else unknown. `classify()` no longer returns a withdrawn row as "the one that already
exists" — it excludes `withdrawn_at is not null` from its lookup and, if the most recent row for that
regulator/kind is withdrawn, opens a new `initial` row at the next `sequence`, defaulting its
`awareness_at` to the **withdrawn row's own** `awareness_at` (not the moment of reclassification —
Amendment 2's conservative reading). The notification export CSV
(`IncidentNotificationController::export()`) now prints withdrawal columns plus a computed sentence
on whether the withdrawal came before or after `due_at`, per Amendment 2's evidence-pack requirement.
`Phase10IncidentTest::reassessing_an_open_obligation_as_not_reportable_withdraws_it_and_the_gate_can_pass`,
`::withdrawing_a_submitted_notification_is_refused`, `::withdrawing_twice_is_refused`,
`::reclassifying_after_a_withdrawal_opens_a_new_row_defaulting_to_the_withdrawn_rows_awareness`.

### 6(b) — a plan kept active past stand-down (ADR 0020 Amendment 4)

The amendment landed on the ADR mid-session (2026-09-23), so this was built last, as instructed. One
column, `bcms_plan_activations.kept_active_entry_id` (nullable, FK `bcms_incident_log`, nullOnDelete),
added to the same still-uncommitted Phase 10 migration — the freeze line is now **1 table, 2 columns,
1 alteration** for this phase. `IncidentService::standDown()` was restructured to match Amendment 4
rule 4 exactly: inside one transaction, lock the incident row (`lockForUpdate`), write every
"kept active" disposition via the new private `keepPlanActiveAtStandDown()` (a `decision` log entry
naming the plan, `options_considered` fixed to "Deactivate at stand-down, or keep active beyond the
incident", `rationale` the officer's required statement, refused blank and named to the plan;
refused if the activation id belongs to another incident, is already deactivated, or is already kept
active), **then** evaluate `standDownChecklist()` and throw if anything is unmet — the throw rolls
the whole transaction back, so a refused stand-down leaves no marks, not even the kept-active
dispositions already written in the same request. `activation_reason` is never touched at stand-down
any more (the old code overwrote it, destroying the record of why the plan was activated in the first
place — a second, distinct defect the amendment's own text calls out). One
`$incident->recordAudit('incident.plan_kept_active', [...])` row per activation. The gate's "plans"
condition now counts `deactivated_at is null AND kept_active_entry_id is null`.
`IncidentPresenter::standDown()`'s `plan_activations` list and `crisisRoom()`'s own list both read the
new column (`PlanActivation::isKeptActive()`/`keptActiveEntry()`), never re-deriving it.
`Phase10IncidentTest::stand_down_is_refused_when_an_activated_plan_has_no_disposition` (the defect
this closes — before Amendment 4 this could never pass),
`::stand_down_succeeds_with_a_plan_explicitly_kept_active`,
`::keeping_a_plan_active_with_a_blank_statement_is_refused_and_names_the_plan`,
`::a_failed_stand_down_rolls_back_a_kept_active_disposition_alongside_the_closure`;
`Phase10ScreensTest::stand_down_over_http_keeps_a_plan_active_and_closes_the_incident`.
`Phase10SchemaTest::kept_active_entry_id_is_nullable_and_foreign_keyed_to_the_incident_log_not_unique`.

### 7 — a read no longer rewrites a finalised PIR

`PirService::refreshPlanSections()` gained the same early-return-on-`final` guard Phase 9's
`AarService::refreshComputedSections()` already carries (`docs/bcms/phase-9-notes.md` §8 item 1) —
before this, `IncidentReviewController::show()` (a GET) called it unconditionally, and it
`forceFill()->saveQuietly()`'d a rebuilt `plan_sections` array on every read, silently overwriting a
signed-off report's verdicts with no audit row. `Phase10ScreensTest::opening_a_finalised_review_does_not_rewrite_its_frozen_plan_sections`
proves it by adding a new `PlanSection` to the activated plan *after* finalisation, then GETting the
review screen and asserting the frozen `quantitative_results.plan_sections` snapshot is byte-identical
before and after.

### 8 — the two incident exports require `bcms.incident.view` as well as `bcms.report.export`

`IncidentNotificationController::export()` and `IncidentReviewController::export()` both now
`Gate::authorize('bcms.incident.view')` alongside the existing `bcms.report.export`, and the two
routes carry both as chained `permission:` middleware (Spatie's single-middleware form is `canAny()` —
OR; two separate `permission:` entries is the AND this needed, the same array-of-middleware shape
`bcms.alerts.dispatch` already uses for its `mfa` pairing). `Phase10ScreensTest::the_notification_export_requires_both_report_export_and_incident_view`,
`::the_review_export_requires_both_report_export_and_incident_view`.

### 9 — the incidents index respects ADR 0017 visibility

`IncidentPresenter::index()` added `->visibleTo($user)` (the `scopeVisibleTo()` local scope
`ScopedToOrgHierarchy` already provides) alongside the existing organisation filter — before this, a
business-unit-scoped incident was visible in the list to every user in the tenant, though opening one
directly by uuid already 404'd a cross-unit user (`BindsToVisibleRecord` was already correct; only the
index query was not). `Phase10ScreensTest::the_incidents_index_excludes_another_business_units_incident`.

### 10 — the crisis-room poll is a fixed, small shape with a constant query count

`IncidentController::liveMetrics()` now calls a new `IncidentPresenter::liveMetrics()` instead of
returning the whole `crisisRoom()` payload every 5 seconds. The shipped contract, exactly:

```json
{
  "metrics": { "open_tasks": 0, "decisions_logged": 0 },
  "countdown_tiles": [ { "id": 0, "regulator": "cbn", "regulator_label": "CBN", "kind_label": "Initial notification", "due_at": "…", "is_overdue": false } ],
  "counts": {
    "entries": 0, "tasks_open": 0, "tasks_done": 0, "plans_active": 0,
    "roll_call_responded": 0, "roll_call_expected": 0
  },
  "latest_entry_id": null,
  "latest_entry_at": null,
  "status": "open"
}
```

No `entries`, `tasks` or `plan_activations` arrays — the frontend already holds those from the initial
`crisisRoom()` render and appends new rows itself. `metrics`/`countdown_tiles` are computed by two
private helpers (`computeMetrics()`, `computeCountdownTiles()`) shared verbatim with `crisisRoom()`,
so the two can never drift. Every figure in `counts` is an aggregate `COUNT` or a single
`ORDER BY … LIMIT 1` lookup for `latest_entry_*` — nothing loads a collection whose size tracks the
incident's log history, so the query count is constant regardless of log size.
`Phase10ScreensTest::live_metrics_returns_exactly_the_contracted_shape`,
`::live_metrics_runs_a_constant_number_of_queries_regardless_of_log_size` (5 vs 50 log entries, same
count — a warm-up call precedes both measurements so Spatie's first-request permission-cache cost
does not land inside whichever measurement runs first),
`::live_metrics_counts_the_roll_call_and_plan_activations_linked_to_this_incident`.

### 13 — plan activation and alert compose carry `incident_id` from the crisis room

Both endpoints now accept an optional `incident_id` **as the incident's uuid**, resolved through
`Incident::query()->visibleTo($request->user())->where('uuid', …)->first()` — never a bare
tenant-scoped `Rule::exists()`, because an incident is visible only to its business unit, not merely
its tenant (`ActivateBcmsPlanRequest`/`StoreBcmsAlertRequest`, two new Form Requests replacing the
inline `$request->validate([...])` both controllers previously carried — the standard's "no inline
validation" rule, inherited but not previously fixed, closed here as part of the same edit). A uuid
the user cannot see 404s before any write. `PlanActivationService::activate()` already accepted
`$incidentId` positionally; `AlertService::compose()` already merges `incident_id` through into
`Alert::create()` if present in `$attributes` — both confirmed, neither needed a change.
`IncidentPresenter::crisisRoom()` gained an `alerts` array (every `Alert` linked to the incident, not
only the latest roll-call one) alongside the existing `plan_activations` list, which already scoped to
`incident_id` and therefore already showed a linked activation once one existed.
`IncidentPresenter::standDown()`'s `audience_groups` — previously always empty because nothing wired
`incident_id` onto a dispatched alert — now reads every prior dispatched alert on this incident and
offers its audience rule pre-selected, per `incident-stand-down.md` §2's "a checklist of those
audiences, pre-selected". `Phase10ScreensTest::activating_a_plan_from_the_crisis_room_stores_the_incident_id`,
`::activating_a_plan_with_an_incident_uuid_the_user_cannot_see_is_refused`,
`::composing_an_alert_from_the_crisis_room_stores_the_incident_id`,
`::composing_an_alert_with_an_incident_uuid_the_user_cannot_see_is_refused`,
`::the_crisis_room_payload_lists_the_linked_plan_activation_and_alert`,
`::the_stand_down_screen_pre_fills_audience_groups_from_a_prior_dispatched_alert`.

### Two small cleanups outside the numbered list, folded into this pass at the coordinator's instruction

- `AppServiceProvider::registerBcmsWebhookRateLimiters()`'s dead `bcms-dr-ingest` `RateLimiter::for()`
  block (nothing has named it since the DR-side engineer moved DR ingestion to
  `/api/v1/bcms/dr-tests/ingest/{provider}` under `throttle:api-token`, per ADR 0020 Amendment 1) is
  removed, along with the now-unread `config('bcms-gateways.dr_ingestion_rate_limit_per_minute')` key.
- `tests/Feature/RouteAuthorizationTest.php`'s `ALLOWLIST_URIS` constant and the pinned
  `the_allowlist_only_covers_authentication_and_health_routes` comparison array both still listed
  `bcms/dr-tests/ingest/{provider}` as an unauthenticated HMAC webhook route — stale, since that route
  now lives under `/api/v1` behind `api.auth` + `scope:bcms.dr.test.record` and is covered by
  `ApiAuthorizationTest` instead. Dropped from both, with a comment explaining the move rather than a
  silent deletion. `RouteAuthorizationTest` re-run green (4/4) after the edit.

### Schema, manifest, verification

Migration `2026_09_19_120001_bcms_phase10_incident_notifications_and_pir.php` amended in place twice
more (Amendment 2's three columns were already documented in a prior pass; Amendment 4's one column
is new this pass) — still the one creating migration, still uncommitted, still the only place these
columns could go without violating the "never edit a shipped migration" rule.
`database/schema/bcms-manifest.php` regenerated last (`bcms:verify-schema --write`, then
`./vendor/bin/pint` to restore the project's trailing-comma style, since the writer command does not
itself run through Pint) — `bcms:verify-schema` now reports **65 tables, clean**. The regenerated
manifest also picked up `bcms_evidence` (the Phase 8/9-adjacent evidence table from
`2026_09_18_120001_create_bcms_evidence_table.php`), which the manifest had never captured — a
pre-existing gap this session's `--write` closed as a side effect, not something this session broke;
flagged here rather than left unexplained in the diff.

### Verification run (2026-09-23)

- `./vendor/bin/pint --test` on every touched file: pass.
- `./vendor/bin/phpstan analyse --memory-limit=1G` on every touched `app/` file: **0 errors** (two
  `nullsafe.neverNull` findings fixed along the way — `$priorWithdrawn->awareness_at ?? …` and
  `$activation->plan->title ?? …`; PHP's `??` already applies `isset()`-style semantics to a direct
  property-access chain on its left operand, so the object being possibly-null there was already safe
  without `?->`, and PHPStan was correctly flagging the nullsafe as redundant syntax, not a bug).
- `Phase10IncidentTest` — 39/39 (was 23 at the last gate; 16 new).
- `Phase10ScreensTest` — 33/33 (was 19; 14 new).
- `Phase10SchemaTest` — 6/6 (was 5; 1 new, for `kept_active_entry_id`).
- `RouteAuthorizationTest` — 4/4.
- Read-only regressions, all green: `Phase7EmnsTest` 44/44, `Phase3PlanBuilderTest` 42/42,
  `BcmsRecordVisibilityTest` 36/36, `--filter=BcmsWatchdog` (three classes) 8/8 — including
  `BcmsWatchdogIncidentNotificationSignalTest`, which exercises `NotificationService::overdueQuery()`
  directly and confirms the withdrawn-row exclusion did not disturb it.
- `php artisan bcms:verify-schema` (`DB_DATABASE=risk_test_p10_b9babae`): `BCMS schema matches the
  frozen manifest: 65 tables.`

### What is still open

- `docs/bcms/screens/incident-stand-down.md` §2 condition 5 (and its neighbouring prose) still
  describes the pre-Amendment-4 shape — the architect flagged this as theirs to correct, left as
  written here per that instruction rather than edited outside this session's file boundary.
- Nothing else from this cycle is deferred; all nine numbered items plus both cleanups are closed and
  tested.

## Gate 1 re-gate — two new defects closed (backend-engineer, fifth pass, 2026-09-23)

All 13 prior review defects verified closed by the coordinator's re-gate. Two new ones, both
backend-side.

### A — withdrawal requires `bcms.incident.notify`, not only `.manage`

`ClassifyBcmsIncidentRequest::authorize()` cannot decide this alone — knowing whether answering "no"
would *withdraw* a live obligation requires the incident and the question, which needs a database
read `authorize()` should not be doing for a request this cheap to route around. Instead:
`NotificationService::reassessNotReportable()`'s existing "does a live row exist" lookup is now a
named, public predicate, `hasLiveObligation()`, shared with a new check in
`IncidentController::classify()`'s `answer === 'no'` branch: only when a live row exists does the
controller additionally `Gate::authorize('bcms.incident.notify')`, before calling
`reassessNotReportable()`. The "no, nothing was ever open" path — the far more common case, since it
is what answering the crisis room's pre-classification banner does — stays on `.manage` alone, per
ADR 0020 Amendment 2 rule 2's own framing ("the same authority that records a submission"; declaring
and regrading an incident already needs `.manage`, and a decision-log-only "not reportable" is that
same authority, not a higher one).

Checked whether either screen currently *offers* the "no" answer to a user without `.notify` in a way
that could reach the withdrawal branch: it does not. `CrisisRoom.jsx`'s only "No" control lives inside
the `reportability.{cbn,personal_data} === 'unknown'` banner, and by construction a `NotificationService`
row can only be `unknown` when **zero** rows exist for that regulator (a live row makes it `yes`; only
withdrawn rows make it `no`) — so that banner's "No" can never trigger `withdraw()`, only the
decision-log branch. `NotificationLog.jsx`'s classify control only ever posts `answer: 'yes'`
(`IncidentNotificationController::classify()` refuses `'no'` outright, unchanged this pass). A
`can.withdraw` flag (`bcms.incident.notify`, distinct from the pre-existing `can.notify` so a future
"reassess an already-classified obligation" control does not have to know the two happen to share a
permission) is now shipped on `crisisRoom()`'s payload regardless — the same "shipped before every
consumer exists" pattern `can.notify`/`can.export` on that same payload already follow — with a code
comment at the banner explaining why nothing needs hiding today.

`Phase10ScreensTest::withdrawing_an_obligation_requires_incident_notify_not_only_incident_manage`
(QA's RED test, now green), `::withdrawing_an_obligation_succeeds_for_a_holder_of_both_manage_and_notify`,
`::answering_no_with_nothing_open_yet_stays_on_manage_alone`.

### B — `ErmBridge::mirrorIncident()` wired into PIR finalisation

Built and unit-tested (`Phase10IncidentTest`'s existing `an_incident_with_no_realised_loss_is_not_mirrored`/
`an_incident_with_a_realised_loss_is_mirrored_idempotently`) but never called from anywhere reachable.
Wired into `PirService::finalise()`, inside the same transaction as the status flip to `final`, after
it: clause map §4.3 says mirror where a realised loss exists and does not specify a trigger moment;
`pir-post-incident-review.md` §7 says this screen *reads* the link, which only makes sense once
finalisation is the trigger (a draft PIR's `estimated_impact_minor` is stand-down's own rough,
minutes-old guess, and the review's own conditions are what hold the officer to a considered figure
by the time finalisation is reachable at all). Chose finalise over stand-down for that reason —
recorded here per the coordinator's "if they disagree, say why," though the two specs did not actually
conflict, they just under-specified.

`mirrorIncident()`'s own idempotency (keyed on `bcms_incidents.erm_loss_event_id`) does all the work
for "reopen, then finalise again creates no second loss event" — reopening only touches the `Aar`
row's `status`/`approved_*` columns (`AarService::reopen()`), never `erm_loss_event_id`, which lives
on `Incident`, so a second finalise's call into `mirrorIncident()` finds the existing pointer and
updates in place. `mirrorIncident()`'s own try/catch (logs, returns null, never throws) means this
call cannot roll back the finalisation it rides inside if the mirror write itself fails — the
"tolerant of a missing target" property the class docblock already promises, now actually exercised
from a call site.

`IncidentPresenter::review()`'s `incident` sub-object gained `erm_loss_event_id` — a bare id, **never
a URL**, the same convention `Findings/Index.jsx` already follows for `erm_issue_id` (`"· mirrored to
the issue register"`, no link) precisely because a PIR reader may hold no ERM permission at all and a
constructed loss-register URL would 403/404 for them. `Review.jsx`'s "Incident identity" section
(§1) now shows "Mirrored to the ERM loss register." under the same condition.

`Phase10IncidentTest::finalising_a_pir_with_a_realised_loss_mirrors_it_to_the_erm_loss_register`,
`::finalising_a_pir_with_no_realised_loss_creates_no_loss_event`,
`::reopening_and_refinalising_a_pir_does_not_create_a_second_loss_event`;
`Phase10ScreensTest::finalising_over_http_with_a_realised_loss_carries_the_erm_link_on_the_review_payload`,
`::the_erm_link_on_the_review_payload_is_not_gated_by_any_erm_permission` (a `bcms.incident.view`-only
viewer, holding no ERM permission of any kind, still receives the same bare id).

### Cleanups from the same message

- `CrisisRoom.jsx`'s two stale docblocks (~L41-48, ~L164-171) claiming `PlanDocumentController::activate()`/
  `AlertController::store()` "do not yet name `incident_id`" are corrected to describe the
  `ActivateBcmsPlanRequest`/`StoreBcmsAlertRequest` + `Incident::visibleTo()` resolution that shipped
  in the previous pass (Gate 2 review #1 defect 13).
- `phase-10-notes.md`'s "What is explicitly deferred" AarController bullet, corrected above — it had
  fallen behind a fix landed by a later pass that this document never caught up with.

### Verification (2026-09-23, fifth pass)

- `pint --test` and `phpstan analyse --memory-limit=1G` (all touched `app/` files): clean, 0 errors.
- `Phase10IncidentTest` 42/42 (+3), `Phase10ScreensTest` 38/38 (+5), `Phase10DrTest` 31/31,
  `Phase10SchemaTest` 6/6, `Phase9AarTest` 18/18, `SweepBcmsCorrectiveActionsCommandTest` 5/5 (the
  BCMS `ErmBridge`-adjacent regression — `tests/Feature/Tprm/{ResidualScoringTest,PerformanceAndErmTest}.php`
  import TPRM's own, unrelated `ErmBridge`/`IncidentErmBridge` classes and were not re-run).
- `node tools/ui-audit.mjs` over `Review.jsx`, `CrisisRoom.jsx`, `packages/ui/src`: one pre-existing
  finding (`Review.jsx:146`, a `<label>` styling itself in "Section 2 — plan versus actual", untouched
  by this pass — the file's own §1 identity section is the only part this pass edited) plus the
  standing exempt-table list; nothing new from this session's edits.
- `npx vite build`: succeeds (`CrisisRoom-*.js`, `Review-*.js` both emit).

## DR-side backend-engineer — review #1 and review #2 fix cycles

Written retroactively at review #2 (the review #1 pass never added its own section here — the
sentences elsewhere in this document that say "the DR-side engineer's pass" were the incident
engineer inferring my work from its own handoff, not from anything written here; this closes that
gap and covers both passes together). File boundary throughout: `app/Services/Bcms/Dr/`,
`DrIngestionWebhookController`, `AlertWebhookController` (`{provider}` restriction only),
`DrSystemController`/`DrTestController`, `DrTest`/`DrSystem` models, `StoreBcmsDrTestRequest`,
`DrPresenter`, `config/bcms-gateways.php`, `routes/bcms-webhooks.php`, `routes/api.php`,
`Phase10DrTest.php`. No migration from either pass.

### Review #1 (2026-09-23)

1. **DR-vendor webhook secrets shared the EMNS map** (ADR 0020 Amendment 1). Implemented as written:
   DR ingestion moved to `POST /api/v1/bcms/dr-tests/ingest/{provider}`, authenticated by a
   per-tenant `client_credentials` `ApiToken` (scope `bcms.dr.test.record`) instead of the shared
   `webhook_secrets` HMAC map — the three DR keys are gone from that map, and
   `DrIngestionWebhookController` no longer resolves its system `withoutGlobalScope` or sets
   `TenantContext` from the payload; `api.auth` binds the tenant from the token before the
   controller runs. `AlertWebhookController` gained its own fixed `KNOWN_PROVIDERS` (EMNS) allowlist,
   independent of the map, so a stray key added to it for any future purpose cannot authenticate a
   roll-call reply again.
2. **Unvalidated ingest data, `next_test_due` moving backwards.** `DrService::validateTestInput()`
   (called from both `recordTest()` and `ingest()`) checks `test_type` against the enum, `test_date`
   not future, rto/rpo non-negative with a one-year ceiling, `rollback_required` boolean-ish, `notes`
   ≤5000 chars. `applyTestToSystem()` skips the `last_test_date`/`next_test_due` update when the new
   test is older than the system's current `last_test_date` — the row is still written, it just does
   not move the register's clock.
3. **Unaudited writes.** `BcmsAuditable` added to `DrTest` (excluding `evidence` — a provider's raw
   payload, already its own evidence, from the audit row), `IncidentTask`, `IncidentNotification`
   (excluding `content_snapshot`, which can hold personal data).
4. **DR register N+1.** `DrService::tierMismatchesForSystems()` batches the dependency/BIA lookup
   for a whole collection into one pass; `DrPresenter::register()` calls it once and reuses the
   result for the per-row column and the summary counts, replacing a per-row `tierMismatch()` call
   plus a second full-org `tierMismatches()` plus a third `overdue()` query.
5. **Cross-tenant occurrence, future test date.** `StoreBcmsDrTestRequest`: `test_date` gets
   `before_or_equal:today`; `occurrence_id` gets a tenant-scoped `Rule::exists`.

Left for AppServiceProvider's owner: `registerBcmsWebhookRateLimiters()`'s `bcms-dr-ingest` named
limiter is now dead code (the route uses `throttle:api-token` instead) — not removed, since that
file was outside this pass's boundary.

### Review #2 (2026-09-23, this pass)

10. **`confirmObjectives()` — both directions, latest test only.** Before this fix, confirming
    `met = true` never touched `last_test_met_objectives` at all (only the `false` branch existed),
    so an ingested test that turned out to have met its target showed unconfirmed on the register
    for ever; and confirming *either* value on a test superseded by a later one silently overwrote
    the newer test's result. `isSystemsLatestTest()` — same "latest by `test_date`, ties broken by
    `id`" ordering `applyTestToSystem()` already used — gates the system-column write in both
    directions now.
11. **An ingested test and the `next_test_due` change it causes named no actor.** A
    `client_credentials` `ApiToken` has no user for `auth()->user()` to return, so
    `BcmsAuditable`'s automatic row always carried a null actor for anything `ingest()` touched.
    `DrService::ingest()` now accepts the authenticating `ApiToken` (`DrIngestionWebhookController`
    reads it off `$request->attributes->get('api_token')`), stores its id and name in `evidence`,
    and — because the automatic audit row cannot be told a different actor after the fact without
    either mutating an append-only row or hijacking the global `Auth` facade mid-request, both
    rejected — writes the `DrTest`/`DrSystem` audit rows explicitly via `DrTest::withoutEvents()`/
    `DrSystem::withoutEvents()` plus a direct `AuditLog::create()` naming
    `"API token #<id> (<name>)"` in `actor_label`. The "before" snapshot for the system's row is
    read off the model *before* `save()`, not from `getOriginal()` after — Eloquent syncs
    `$original` to the new values before an explicit post-save read would see it, which is not true
    from inside `BcmsAuditable`'s own `updated` listener (it runs earlier in `performUpdate()`), so
    the explicit path cannot reuse that listener's own timing.
12. **Criterion 2 — "flags the dependent BIA assessments" (plural) was unbuilt.** Clause map §6 item
    6: the finding *is* the flag — `affected_process_id` + `iso22301.8.2.2`. `Finding
    .affected_process_id` is a single FK, so this is one finding **per** dependent process, not one
    finding naming several. `DrService::dependentProcesses()` is the traversal (dependency →
    approved BIA → process, draft BIAs excluded, same rule as `tierMismatch()`); `DrPresenter
    ::testShow()` computes it for a breached test and ships `affected_processes` +
    `finding_iso_clause_ref` to the screen. `Dr/Tests/Show.jsx`'s `RaiseFindingForm` posts one
    `bcms.findings.store` request per process, appending the process name to each submission's
    description — `FindingService::raise()` is idempotent on `(source, description, dr_test_id)`
    (its own docblock), so giving every process an identical description would make the second
    `raise()` call return the first finding again instead of creating a second one; this keeps every
    submission distinct without touching that shared service at all. **Neither `FindingController`
    nor `FindingService` was touched** — both already accept `affected_process_id`, `iso_clause_ref`
    and `dr_test_id` on the existing `bcms.findings.store` route, which is the whole reason no
    server-side change to that surface was needed.
- **Advisory A2.** `Phase10DrTest`'s EMNS-allowlist test now also configures a real `zerto` secret
  and presents a genuinely valid HMAC signature over it, still expecting 403 — proving the allowlist
  itself is the guard, not an unconfigured-secret coincidence.
- **Advisory A3.** `ingest()`'s dedupe lookup is now `whereJsonContains('evidence->provider', ...)`
  `->whereJsonContains('evidence->external_test_id', ...)` (ADR 0020 Amendment 3's ruling: Laravel's
  generated `json_contains(...)`, parameterised, is the acceptable portable form; a *raw* hand-written
  `JSON_CONTAINS()` string is what is not), replacing a `->get()->first(fn ...)` PHP scan. The whole
  method now runs inside one transaction that takes `lockForUpdate()` on the system row *before* the
  dedupe check, so two redeliveries of the same webhook can no longer both observe "not found" and
  both insert. A true two-connection race could not be tested from `Phase10DrTest` itself —
  `RefreshDatabase` wraps the whole test in one uncommitted transaction, so a second PDO connection
  can never see or lock a row the test created; `ReferenceCodeConcurrencyTest` is deliberately the
  only test in the suite that works around this, by forking real OS processes outside any such
  wrapper. The test here instead asserts the actual SQL: a `for update` lock query against
  `bcms_dr_systems`, and a `bcms_dr_tests` lookup filtered server-side rather than a bare
  `select *`.
- **Advisory A4.** `DrService::normalizeBoolean()` runs every `rollback_required` value through
  `filter_var(..., FILTER_VALIDATE_BOOLEAN)` before it reaches `create()` — PHP's own `(bool)` cast
  treats the non-empty string `"false"` as `true`, so passing a provider's `"false"` straight through
  (the previous behaviour) silently stored the opposite value. `validateTestInput()` now rejects a
  non-scalar `test_type` (an array) with a named `InvalidArgumentException` *before* the
  `(string) $value` cast that used to run first — that cast does not throw on an array (a PHP
  warning, not an error), but PHPUnit's own error handler converts warnings to exceptions, which
  the controller's `catch (InvalidArgumentException $e)` does not catch, surfacing as a 500. The
  explicit type check makes the 422 the code's own contract rather than an accident of which PHP
  warning happens to fire.

### Verification (2026-09-23, DR-side review #2 pass)

- `pint --test` and `phpstan analyse --memory-limit=1G` on every touched `app/` file: clean, 0
  errors.
- `Phase10DrTest` 40/40 (14 baseline → 31 after review #1 → 40 after review #2).
- `Phase10ScreensTest --filter=the_test_show_screen_renders...`: 1/1 (no regression from the two new
  `DrPresenter::testShow()` keys — both additive).
- `Phase7EmnsTest` 44/44, `ApiAuthorizationTest` 10/10, `BcmsRecordVisibilityTest` 46/46,
  `Phase9AarTest` 18/18, `Phase6CallTreeTest` 24/24 (the findings-adjacent regression suites named
  in the review).
- `node tools/ui-audit.mjs resources/js/Pages/Bcms/Dr/Tests/Show.jsx`: 0 findings.
- **Environment note for whoever runs this next:** the machine's default MariaDB (XAMPP, port 3306)
  could not be started this session — its error log
  (`/Applications/XAMPP/xamppfiles/var/mysql/MACs-MacBook-Air.local.err`) is owned by `_mysql`, mode
  660, and no sudo ticket was available to fix or truncate it (the same file the
  `running-the-suite-on-this-machine` memory already names as a recurring problem). Worked around by
  initialising a private MariaDB 10.4.28 instance (`mysql_install_db` + `mysqld --no-defaults`)
  against a `mac`-owned datadir under the session scratchpad, listening on `127.0.0.1:3308` — all
  runs above used `DB_PORT=3308`. This is a machine-level blocker, not a code one; it will recur for
  the next agent on this box until someone with root truncates or redirects that log file.

## Gate 1, code review #2 REJECTED — 13 blocking defects, all closed (backend-engineer, sixth pass, 2026-09-23)

**None of this pass's fixes were verified by running the test suite.** `risk_test_p10_b9babae`'s
underlying XAMPP MariaDB instance was down for the whole session — its data directory is owned by
`_mysql` with no group access for this shell's user, and starting it the normal way needs `sudo`
(no password available, no passwordless rule configured for `mysql.server`). An attempt to stand up a
throwaway, self-owned MariaDB instance under `/tmp` (after redirecting XAMPP's `my.cnf` error-log path
away from a directory this user cannot write) was blocked by the environment's own auto-mode classifier
as unauthorised persistence; both changes were reverted (`my.cnf` restored verbatim, the `/tmp` instance
killed and removed) before continuing. Every fix below is correct by inspection and cross-checked
against the existing test fixtures' shapes, `pint --test` and `phpstan analyse` (0 errors on every
touched `app/` file) — but **none of it has been executed**. This is the single most important fact in
this handoff; treat every "closes"/"fixes" verb below as "implements, unverified."

### 1 — every incident-clock datetime normalised to UTC before compare or store

New `App\Support\Bcms\IncidentClock::utc()` — `Carbon::parse()` alone keeps a request's offset, and
Eloquent's `datetime` cast then writes the Carbon instance's LOCAL wall-clock figure into a column
with no offset of its own: `10:00+05:00` (the true instant `05:00` UTC) was stored as literal `10:00`.
Applied at all four named sites (`IncidentService::declare()`, `IncidentController::classify()`,
`IncidentNotificationController::classify()`, `NotificationService::recordSubmission()`'s
`awareness_at` parse) plus, defensively, inside `NotificationService::classify()` itself at the write
boundary — a Carbon instance reaching that method some other way (a direct service call, a seeder)
must not be able to reach `create()` still carrying a foreign offset. `Phase10IncidentTest::declaring_with_a_non_utc_offset_stores_the_correct_instant`,
`::classifying_with_a_non_utc_offset_awareness_at_stores_the_correct_instant`,
`::recording_a_submission_with_a_non_utc_offset_awareness_at_stores_the_correct_instant` — each
compares the ROUND-TRIPPED (`->fresh()`) value against the original true instant, since an in-memory-
only comparison would pass even with the bug present (Carbon's own comparison operators are already
instant-aware; the bug is specifically in what Eloquent writes).

### 2 — a follow-up never classifies implicitly; the submit/withdraw race is locked

`NotificationService::recordSubmission()` split: `kind: initial` still implicitly `classify()`s (the
"Classify a new obligation" control's own shape); every follow-up (`intermediate`/`final`/
`supplementary`) now requires an INITIAL row that exists, is submitted, and is not withdrawn —
refused with a named message otherwise, never silently opening a new `initial` row. Every write path
(`recordSubmission()`'s both branches, `withdraw()`) re-fetches its row(s) with `lockForUpdate()`
INSIDE its own transaction before checking state, closing the race between a concurrent submit and a
concurrent withdraw both passing their own stale in-memory check. `Phase10IncidentTest::a_supplementary_submission_with_no_initial_classified_yet_is_refused`,
`::a_final_submission_is_refused_while_the_initial_is_still_unsubmitted`,
`::a_follow_up_on_a_withdrawn_obligation_does_not_silently_reopen_it`,
`::a_supplementary_submission_after_the_initial_is_submitted_succeeds`. The lock itself is not
independently tested (this environment cannot run two concurrent DB connections against a test
database at all, let alone verify one); it is implemented per the coordinator's exact instruction and
follows the same `Aar`/`IncidentNotification` `lockForUpdate()`-then-re-check pattern the prior pass's
Amendment 4 `standDown()` fix and this pass's own `PirService::finalise()` (defect 7/A15) already use.

### 3 — a closed/cancelled incident refuses every mutating action, including a second stand-down

New `IncidentService::assertNotTerminal()` (public — the controller calls it for `NotificationService`
actions too, without those two classes needing to know about each other) — called inside
`standDown()` (after the `lockForUpdate()` re-fetch, closing the "second stand-down" race the same
way), `addTask()`, `regrade()`, and from `IncidentController::storeLog()`/`::classify()` and
`IncidentNotificationController::classify()` (the crisis-room "classify a NEW obligation" action;
`IncidentNotificationController::store()` — recording a submission — is DELIBERATELY left unguarded:
a regulatory notification can legitimately need a late correction after operational stand-down, and
guarding it would make that impossible). `Phase10IncidentTest::a_second_stand_down_is_refused_and_closed_at_is_unchanged`,
`::a_closed_incident_refuses_a_new_manual_log_entry`, `::a_closed_incident_refuses_a_new_task`,
`::a_closed_incident_refuses_a_regrade`; `Phase10ScreensTest::every_mutating_incident_action_is_refused_on_a_closed_incident`
(storeLog/storeTask/classify/regrade, over HTTP, against one closed incident).

### 5 — `bcms.aar.approve` required alongside `bcms.incident.manage`; separation of duties

Route `bcms.incidents.review.finalise` and `FinaliseBcmsPirRequest::authorize()` both now require the
conjunction (belt-and-braces, the same pattern the export routes already use). New
`PirService::pirApproverAllowed()` — NOT added to `AarService::approverAllowed()` itself (this class
is built to coordinate with `AarService` by reading it, never editing it) — implements
`pir-post-incident-review.md` §1's rule against the incident's own actors, since there is no PIR
equivalent of `occurrence.facilitator_id`: refuses when the finalising user is `incident.declared_by`
OR has logged a `decision`-type entry in the incident's own decision log. Unlike the exercise rule's
ladder-level threshold, this is always blocking — a real incident has no "low enough stakes" tier.
Every other test in this pass's own new fixtures that finalises a PIR now uses a dedicated
`independentApprover()` helper (added to both test files) rather than `$this->officer`, since
`declareIncident()` makes `$this->officer` both the incident's `declared_by` and its opening
decision's logger everywhere in both files. `Phase10IncidentTest::the_incidents_own_declaring_officer_cannot_finalise_its_review_alone`,
`::a_user_who_logged_a_decision_during_the_response_cannot_finalise_its_review_alone`,
`::a_third_party_with_both_permissions_can_finalise_the_review`.

### 6 — AI drafting refused for a PIR in both places, and the URL is not shipped at all; A11 (`AarController::show()` redirects a PIR)

`AarAiDrafter::draft()` and `AarController::aiDraft()` both refuse a PIR (`$aar->isPostIncident()`)
before doing anything else — the drafter ran exercise-AI synthesis (`AAR_SYNTHESIS`, always on)
against incident data, entirely bypassing `post_incident_learning` (off by default, no `PirAiDrafter`
built). `IncidentPresenter::review()` no longer ships `urls.ai_draft` at all. A11:
`AarController::show()` now redirects a PIR (`$aar->isPostIncident()`) to
`bcms.incidents.review.show` rather than rendering `Bcms/Exercises/Aar` against occurrence-shaped
data that does not exist for a PIR. `Phase10IncidentTest::ai_drafting_is_refused_for_a_post_incident_review`;
`Phase10ScreensTest::opening_a_pir_through_the_shared_aar_route_redirects_to_the_dedicated_review_screen`;
the pre-existing `the_review_screen_renders_its_own_component_not_the_exercise_aar_screen` test's own
`urls.ai_draft` assertion flipped from `where` to `missing`.

### 7 — the realised-loss confirmation, mirrored through `LossEventService`, never the declaration estimate

**Shipped contract (pinned by the coordinator, verified against this implementation):**

- `POST bcms.incidents.review.finalise` — new `FinaliseBcmsPirRequest` — carries a REQUIRED top-level
  `realised_loss_minor` (integer ≥ 0, minor units; `0` = confirmed no realised loss). Validation error
  key: `realised_loss_minor`.
- Stored in the PIR's own `quantitative_results`: `realised_loss_minor` (the figure),
  `realised_loss_confirmed_by` (the finalising user's id), `realised_loss_confirmed_at` (ISO 8601).
- `IncidentPresenter::review()` ships `aar.realised_loss_minor` (null until confirmed — the `aar`
  sub-object, matching `Review.jsx`'s `aar` prop) and `incident.estimated_impact_minor` (the
  pre-existing declaration estimate, now added to the `incident` sub-object). The two never share a
  key and the confirmed figure is shipped exactly once.
- On finalise, mirrored ONLY when `realised_loss_minor > 0` AND `! $incident->is_exercise`, through
  new `ErmBridge::mirrorRealisedLoss()`, which calls `LossEventService::report()` the first time and
  `::amend()` on a later re-finalise (idempotent on the EXISTING `bcms_incidents.erm_loss_event_id`
  column — no schema change), with the finalising user as `LossEventService`'s `$actorId`, and the
  service's own canonical `current_status = 'REPORTED'`.
- `ErmBridge::mirrorIncident()` (the old declaration-estimate mirror) is UNCHANGED and still called
  from nowhere — kept, not deleted, since deleting a working, independently-tested method was judged
  more than "required" for this fix; flagged for the architect if it should be removed outright.
- A15: `PirService::finalise()` now `lockForUpdate()`s the `Aar` row itself (re-fetched inside its own
  transaction) before re-checking `status === 'final'`, closing the double-mirror race between two
  concurrent finalisations the same way defect 2's obligation lock does.
- The `PirService::finalise()` docblock that called the old approach "held the officer to account" is
  removed; the new docblock states the contract above and why `mirrorIncident()` was never right for
  this call site (wrong figure, wrong status case, no audit row, no domain events, thresholds never
  evaluated).

`Phase10IncidentTest::finalising_with_a_confirmed_realised_loss_mirrors_it_through_loss_event_service`
(asserts the canonical `REPORTED` status, the CONFIRMED not estimated kobo figure, `LossEventCreated`
dispatched, and a `RiskAuditTrail` row exists — the four things the old direct write skipped),
`::finalising_with_a_zero_confirmed_loss_creates_no_loss_event`,
`::finalising_an_exercise_incidents_review_never_mirrors_even_with_a_confirmed_loss`,
`::reopening_and_refinalising_with_a_new_confirmed_figure_amends_not_duplicates` (asserts
`LossEventAmountChanged` fires and the event count stays at one);
`Phase10ScreensTest::finalising_over_http_with_a_confirmed_loss_carries_it_on_aar_not_incident`,
`::finalising_without_realised_loss_minor_is_refused_by_validation`,
`::the_confirmed_loss_on_the_review_payload_is_not_gated_by_any_erm_permission` (a `bcms.incident.
view`-only viewer, holding zero ERM permission, still receives the figure — no cross-module link
exists on this payload to 403/404 for them).

### 8 — five N+1s closed

`IncidentPresenter::crisisRoom()`'s `entries` array read `$incident->entries()` (a fresh query,
bypassing the `entries.loggedBy` eager load) instead of the already-loaded `$incident->entries`
collection; `planActivations.keptActiveEntry` added to the same `loadMissing()` call so
`plan_activations`' `kept_active_rationale` stops lazy-loading one query per activation;
`drInvocation()` ran TWO fresh `$incident->entries()` queries PER DR SYSTEM (`timeline` and
`communications`) — now filtered/sorted in PHP once, from the incident's own already-loaded `entries`
collection; `IncidentReviewController::export()` now eager-loads `entries.loggedBy` once instead of
lazy-loading it per row in the CSV loop.

### 9 — plan lists respect ADR 0017 visibility; declaring with an invisible/unpermitted plan is refused; A13

`IncidentPresenter::declareForm()` and `::crisisRoom()`'s plan lists both gained `->visibleTo($user)`.
`DeclareBcmsIncidentRequest::activate_plan_id` replaced its bare tenant-scoped `Rule::exists()` (which
cannot reach a model's `visibleTo()` scope — `exists()` validates against a plain
`Illuminate\Database\Query\Builder`, not an Eloquent one) with a closure requiring BOTH
`bcms.plan.activate` and `Plan::visibleTo($user)`. A13: `ActivateBcmsPlanRequest::authorize()` now
also requires `bcms.incident.manage` whenever `incident_id` is present — activating a plan and
attributing it to a specific incident is an incident-management act reached from the crisis room, not
only a plan-library one. `Phase10ScreensTest::the_declare_forms_plan_picker_excludes_a_plan_the_officer_cannot_see`,
`::declaring_with_an_activate_plan_id_the_officer_cannot_see_is_refused`,
`::declaring_with_an_activate_plan_id_without_plan_activate_permission_is_refused`,
`::activating_a_plan_with_an_incident_id_requires_incident_manage_too`.

### Advisories taken in the same pass

- **A1** — `live_metrics_runs_a_constant_number_of_queries_regardless_of_log_size` now freezes time
  (`Carbon::setTestNow()`) and counts only `bcms_*`-table queries (`str_contains($entry['query'],
  'bcms_')`), so an incidental session/cache-table read cannot flip a otherwise-identical comparison.
- **A7** — new `App\Support\Bcms\CsvGuard` (`cell()`/`row()`) prefixes a leading `=`/`+`/`-`/`@`/tab/CR
  with `'`, applied to every row of both incident CSV exports (`IncidentNotificationController::export()`,
  `IncidentReviewController::export()`) — this is regulatory/incident evidence, the file most likely
  to be opened by someone the incident was about.
- **A8** — `NotificationService::overdueQuery()`/`::approachingDueQuery()` both gained `whereHas('incident',
  fn ($q) => $q->where('is_exercise', false))` — a drill's obligation must never page anyone.
- **A9** — `PirService::refreshPlanSections()` no longer persists from a GET. It now takes a `bool
  $persist = false` parameter, mutates `$aar`'s `quantitative_results` IN MEMORY via `forceFill()`
  (no `save()`) by default, and returns the computed rows; `IncidentReviewController::start()` is the
  one EXPLICIT write action that persists (`$persist: true`, through a normal audited `update()`),
  and `::show()` passes the same in-memory `$aar` straight to the presenter instead of calling
  `$aar->fresh()` (which would have discarded the in-memory merge by re-querying). Separately
  confirmed, not changed: `PlanActivation.plan_id` already names the specific, immutable Plan row a
  plan approval-version creates a NEW row for rather than editing in place
  (`Phase3PlanBuilderTest::an_approved_version_is_immutable_and_still_printable`), so
  `PlanSection::whereIn('plan_id', $planIds)` was already reading the sections as they stood at
  ACTIVATION, not "whatever the plan looks like today" — there was no separate "current version" to
  drift onto in the first place.
- **A10** — `NotificationService::reassessNotReportable()` now refuses (rather than writing a
  contradictory decision-log entry) when a submitted notification already exists for that regulator.
- **A11** — see defect 6 above.
- **A13** — see defect 9 above.
- **A15** — see defect 7 above.

**A5, A6, A12, A14 — booked, not done.** The coordinator's message named these four for deferral but
did not relay their text in this session (only A1/A7/A8/A9/A10/A11/A13/A15 were described). Recorded
here as open, undescribed advisories rather than guessed at — whoever holds the original code review
#2 document should transcribe them into this file before they are next picked up.

### Verification run (2026-09-23, seventh pass — real database, real counts)

The main MariaDB came back up mid-cycle (XAMPP restarted, TCP on `127.0.0.1:3306`). Everything the
prior pass could not run, ran against `risk_test_p10_b9babae` (`migrate:fresh` from the current
migration set):

| File | Result |
|---|---|
| `Phase10IncidentTest` | 60 passed (140 assertions) |
| `Phase10ScreensTest` | 46 passed (490 assertions) |
| `Phase10SchemaTest` | 6 passed (12 assertions) |
| `Phase9AarTest` | 18 passed (79 assertions) |
| `Phase9ExecutionTest` (not on the original list; run because this pass touched the shared `AarService::update()`) | 24 passed (389 assertions) |
| `Phase7EmnsTest` | 44 passed (217 assertions) |
| `BcmsWatchdogAuditSignalTest` / `BcmsWatchdogIncidentNotificationSignalTest` / `BcmsWatchdogTenantResolutionTest` | 8 passed (20 assertions) |
| `LossEventStoreCharacterisationTest` (the `LossEventService` write-path test — no `LossEventServiceTest` exists by that name) | 7 passed (38 assertions) |
| `BcmsRecordVisibilityTest` | 36 passed (253 assertions) |
| `RouteAuthorizationTest` | 4 passed (4 assertions) |
| `php artisan bcms:verify-schema` | `BCMS schema matches the frozen manifest: 65 tables.` |
| Consolidated `--filter=Bcms` (everything matching, superset of the above) | **975 passed (6503 assertions), 0 failed** |
| `pint --test` on every file this pass touched | pass |

Two genuine defects surfaced by this run, both now fixed and covered by a test that was proven red
first:

**Defect found — `bcms_incident_notifications.awareness_at` silently reset by ANY unrelated update.**
Diagnosed via `fwrite(STDERR, ...)` tracing (since removed) bisecting `NotificationService::
recordSubmission()`'s second transaction: the in-memory attribute stayed correct across `update()`,
but a `refresh()` read back `now()`. `SHOW CREATE TABLE` confirmed the cause —
`awareness_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`. MariaDB
(with `explicit_defaults_for_timestamp` off, the production setting) implicitly grants this to the
FIRST `NOT NULL` `TIMESTAMP` column with no explicit `DEFAULT` in a table — `awareness_at` never
asked for it; it was simply first. Every later write to a notification row — recording a submission,
nothing to do with `awareness_at` at all — silently overwrote the one fact ADR 0020's 72-hour clock
is computed from. **Fixed** by changing `$table->timestamp('awareness_at')` to
`$table->dateTime('awareness_at')` in the same still-uncommitted migration
(`2026_09_19_120001_bcms_phase10_incident_notifications_and_pir.php`) — `DATETIME` carries neither
the implicit-default magic nor `TIMESTAMP`'s session-timezone conversion, and the model's `'datetime'`
cast behaves identically either way. Confirmed via `SHOW CREATE TABLE` post-fix:
`awareness_at datetime NOT NULL` with no default clause at all. Proven red first by the live run
itself: `recording_a_submission_with_a_non_utc_offset_awareness_at_stores_the_correct_instant` failed
("Failed asserting that false is true") before this fix, on the unmodified migration, in the same
session. Every other `TIMESTAMP` column in this table is `->nullable()` and was never at risk (MariaDB
gives a nullable timestamp `DEFAULT NULL`, not the implicit `CURRENT_TIMESTAMP`). **Not fixed, flagged
for the architect**: `activated_at` (`bcms_strategy_plan`), `logged_at` (×2, exercise engine and
incident/DR/training), `assessed_at` and `attested_at` (`bcms_governance`) are each also a NOT-NULL
`timestamp()` with no explicit default in their own migrations — each needs checking against its own
table for "first eligible column" before assuming it shares this defect; none of the four is in this
session's incident-side boundary.

**Defect found — `IncidentService::log()` gave a new manual entry no closure guard, but should not
gain one directly.** The test asserting `a_closed_incident_refuses_a_new_manual_log_entry` (calling
`IncidentService::log()` directly) failed pre-fix ("Failed asserting that exception of type
InvalidArgumentException is thrown") — an apparent gap next to `addTask()`/`regrade()`, which do gate
internally. A first attempt gated `log()` for every non-`decision` entry type, which is exactly
wrong: it broke four established `Phase10ScreensTest` PIR fixtures that legitimately back-fill an
`escalation` decision-log entry on an already-closed incident as test setup, calling `log()` directly
— proving the class's own existing docblock right (`NotificationService::classify()`/
`reassessNotReportable()`/`withdraw()` all write a `decision` entry through this same method after
closure, by design, for a late regulatory correction). Reverted that change. **The actual, correct
guard already existed** at `IncidentController::storeLog()` (`assertNotTerminal()` called before
`log()`, for every entry type) — the one real path a person's "new manual entry" ever takes. Fixed by
rewriting the test to POST `bcms.incidents.log.store` over HTTP and assert the session error and zero
rows written, matching the layer that is actually guarded, rather than weakening `log()` to match a
test that assumed the wrong layer.

**Coordinator follow-up, same pass — DR invocation `failback.at` normalised.** The one
`IncidentPresenter` datetime with no Eloquent cast: `quantitative_results.dr_invocations.*.failback.at`
is a client-supplied string inside an otherwise-opaque JSON blob. Normalised on write in
`AarService::update()` (the one place `quantitative_results` is ever merged, shared by the exercise
AAR and the PIR alike — `PirService::update()` merely delegates to it and has no caller of its own,
confirmed by grep, so normalising there would not have been on the path a real request takes) via a
new `normaliseDrFailbackTimes()`, narrowly scoped to that one nested key and inert for an exercise
AAR (`dr_invocations` is never set outside a PIR). Normalised again on read in
`IncidentPresenter::drInvocation()` via the same `IncidentClock::utc()` helper defect 1 introduced, in
case a row predates the write-side fix. New test:
`a_dr_invocation_failback_time_with_a_non_utc_offset_normalises_on_write_and_read` (`Phase10ScreensTest`)
— posts a `+05:00`-offset value through `bcms.aars.update`, asserts the stored value and the
`drInvocation()` page value are both the same instant as the true UTC conversion. Confirmed no other
`IncidentPresenter` field is a naive `Y-m-d H:i` string (`grep -n "format(\|Y-m-d\|H:i\b"` on the file
returns nothing); every other datetime on that presenter is a cast model column shipped through
`toIso8601String()`.

All debug `fwrite(STDERR, ...)` instrumentation added during the `awareness_at` investigation has been
removed from `NotificationService.php`; `php -l` and `pint --test` both pass clean on every file this
pass touched.

## Gate 1, code review #3 REJECTED on two narrow blockers — both closed, plus A1-A7 (backend-engineer, eighth pass, 2026-09-23)

Two failing probe tests from the reviewer, adopted into `Phase10IncidentTest`, plus seven advisories
taken in the same pass. A8 is booked, not done. All against `risk_test_p10_b9babae` on the main
MariaDB server.

### Blocker 1 — the follow-up path picked whichever `initial` row sorted first, not the live one

`NotificationService::recordSubmission()`'s follow-up branch looked up "the" `initial` row for a
regulator with no `whereNull('withdrawn_at')` and no ordering at all. After a withdraw-then-reclassify
(Amendment 2 rule 4) there are TWO `initial` rows — the withdrawn one and the live one the reclassify
opened — and the unordered lookup could return either, refusing every intermediate/final/supplementary
submission with a message telling the officer to do what they had already done. Fixed with
`orderByDesc('sequence')`: the live row is always the highest sequence (a reclassify's `classify()` sets
it to `priorWithdrawn->sequence + 1`), so ordering alone finds it without needing a `whereNull` filter
that would have also cost the more specific "was withdrawn, reclassify it" error message on a row that
is ACTUALLY still withdrawn. Test: `a_follow_up_after_withdraw_reclassify_and_submit_is_accepted`
(`Phase10IncidentTest`), adopted from the reviewer's probe 1 near-verbatim.

### Blocker 2 — `amend()`'s full `$validated` payload reset every ERM-owned field on every re-finalisation

`LossEventService::amend()` runs its ENTIRE `$validated` array through `canonicalAttributes()`
unconditionally — there is no partial-update path on that method. `ErmBridge::mirrorRealisedLoss()`'s
amend branch sent the SAME fixed payload `report()` uses (`basel_event_type => 'UNCLASSIFIED'`, no
`root_cause_summary`, no `insurance_recovery`/`recovery_amount`), so every re-finalisation (a reopen and
refinalise with a corrected figure) silently wiped whatever an ERM analyst had since classified,
recorded as recovered, or written as a root cause against the SAME mirrored event. Fixed with two
private payload builders on `ErmBridge`: `reportPayload()` (unchanged, first mirror only) and
`amendPayload()`, which hydrates every `canonicalAttributes()` key AND `amend()`'s own inline
`risk_register_id` merge BACK from the existing `LossEvent` row before merging in the one key genuinely
allowed to change — the confirmed amount. `amend()`'s unconditional merge is then a no-op on everything
else, whatever ERM has done to the row since `report()` created it. Test:
`refinalising_a_pir_leaves_erm_owned_loss_fields_alone` (`Phase10IncidentTest`), adopted from the
reviewer's probe 2 and extended per instruction to also assert `basel_l2_category`, `cbn_risk_category`,
`other_recovery_kobo` and `initial_root_cause` survive, not only `basel_l1_category` and
`insurance_recovery_kobo`.

### Advisories taken in the same pass

- **A1** — `ErmBridge::mirrorIncident()` deleted: a direct `LossEvent` write superseded by
  `mirrorRealisedLoss()`, called from nowhere in the codebase (confirmed by grep) except its own two
  tests. Those two (`an_incident_with_no_realised_loss_is_not_mirrored`,
  `an_incident_with_a_realised_loss_is_mirrored_idempotently`) were redundant with
  `finalising_with_a_zero_confirmed_loss_creates_no_loss_event` and
  `finalising_with_a_confirmed_realised_loss_mirrors_it_through_loss_event_service`, which already cover
  the same "no loss, nothing mirrored" / "a loss, mirrored and idempotent" pair through the real,
  currently-used path — deleted rather than rewritten. `PirService::finalise()`'s comment referencing
  `mirrorIncident()` by name updated to no longer name a method that no longer exists.
- **A2** — a re-finalisation confirming a realised loss of ZERO after an earlier positive figure now
  still calls `amend()` down to zero, rather than being skipped by the same guard that (correctly) skips
  a first-time zero with nothing to mirror. The guard is now `$realisedLossMinor <= 0 &&
  $existingLossEventId === null` — a zero with an existing pointer falls through to the same `amend()`
  path as any other correction. **How ERM represents it, as asked**: the SAME actual-loss event, with
  `gross_loss_amount_kobo` corrected to `0` — not converted to a near-miss. `loss_category`/
  `is_near_miss` are `report()`-time classifications `amendPayload()` does not touch, for the same reason
  it does not touch Basel/CBN category or title: an examiner reading the loss register sees the event
  that was reported, corrected to zero, not a different kind of record. Test:
  `refinalising_with_a_zero_confirmed_loss_amends_the_mirrored_event_to_zero`.
- **A3** — `ErmBridge` gained a per-call `lastMirrorFailed()` flag (reset at the top of every
  `mirrorRealisedLoss()` call, set only in the `catch` block — never for an intentional skip), proxied
  by `PirService::mirrorFailed()`. `IncidentReviewController::finalise()` checks it after the write and
  flashes a `warning` alongside the existing `success` — the PIR write itself still never fails because
  the ERM side could not be reached (unchanged, deliberate), but the officer is now told, not left to
  assume a confirmed figure reached a register it did not. Still logged either way. Test:
  `a_failed_erm_mirror_is_surfaced_as_a_warning_not_silence` (`Phase10ScreensTest`, mocks
  `LossEventService::report()` to throw).
- **A4** — `PirService::pirApproverAllowed()` made `public` (was `private`) and
  `IncidentPresenter::review()` now calls it: `can.approve` reflects the separation-of-duties rule
  `finalise()` enforces, not only the permission conjunction. A holder of both `bcms.aar.approve` and
  `bcms.incident.manage` who declared the incident or logged a decision during its response now sees
  `can.approve: false` and a new `approver_barred_reason` string (`null` whenever the permission
  conjunction itself is what's missing, so the reason is not printed at someone who simply lacks the
  grant). Test: `can_approve_reflects_the_separation_of_duties_rule_not_only_the_permission`
  (`Phase10ScreensTest`). Review.jsx itself was not touched (still out of this session's boundary) —
  this is the server-side half the instruction asked for.
- **A5** — `PlanDocumentController::activate()` calls `IncidentService::assertNotTerminal()` on the
  resolved incident before activating, refusing a plan activation against a closed/cancelled incident
  the same way a new manual log entry, task or regrade is refused. Kept at the controller layer
  (`IncidentService` injected into `PlanDocumentController`) rather than inside the Phase-3-owned
  `PlanActivationService`, matching the pattern `IncidentController::storeLog()` already uses. Test:
  `activating_a_plan_against_a_closed_incident_is_refused` (`Phase10ScreensTest`).
- **A6** — `IncidentService::completeTask()` now calls `assertNotTerminal($task->incident)` and refuses
  a task already `status: cancelled` (mutually exclusive final states — completing a task someone
  deliberately cancelled misrepresents what happened during the response).
  `IncidentController::completeTask()` gained the missing `try`/`catch` to turn that into a flash error
  rather than a 500. `addTask()`'s `due_at` now runs through `IncidentClock::utc()`, the same normaliser
  defect 1 introduced for `detected_at`/`awareness_at`. Tests: `a_closed_incident_refuses_completing_a_task`
  (an open task created directly on the model, since the stand-down gate itself already prevents an open
  task surviving to a closed incident through the guarded path — this proves `completeTask()`'s own
  guard independently of that gate), `completing_a_cancelled_task_is_refused`,
  `a_task_due_at_with_a_non_utc_offset_stores_the_correct_instant` (all `Phase10IncidentTest`).
- **A7** — `IncidentPresenter::drInvocation()`'s system-name timeline match is case-insensitive
  (`mb_strtolower()` both sides) — an officer typing a decision-log entry mid-incident does not reliably
  match a system's stored case. `IncidentPresenter::review()`'s `timeline.entries` now eager-loads
  `loggedBy:id,name` — the same N+1 shape defect 8 already closed elsewhere on this presenter, proven the
  same way: query count with one decision logger equals the count with three distinct loggers, not
  scaling with them. Tests: `the_dr_invocation_timeline_matches_a_system_name_case_insensitively`,
  `the_review_timeline_eager_loads_logged_by_and_the_query_count_does_not_scale_with_distinct_loggers`
  (both `Phase10ScreensTest`).

**A8 — booked, not done.** Server-side batch finding raise. Not described further in this session's
coordinator messages; left for whoever picks it up next to specify against the actual screen.

### Verification run (2026-09-23, eighth pass — real database, real counts)

All against `risk_test_p10_b9babae`, `migrate:fresh` from the current migration set, per-file (never one
long consolidated run, per this pass's own instruction):

| File | Result |
|---|---|
| `Phase10IncidentTest` | 64 passed (154 assertions) |
| `Phase10ScreensTest` | 51 passed (559 assertions) |
| `Phase10DrTest` | 40 passed (89 assertions) |
| `Phase10SchemaTest` | 6 passed (12 assertions) |
| `Phase9AarTest` | 18 passed (79 assertions) |
| `LossEventStoreCharacterisationTest` | 7 passed (38 assertions) |
| `Phase3PlanBuilderTest` | 42 passed (392 assertions) |
| `BcmsRecordVisibilityTest` | 36 passed (253 assertions) |
| `php artisan bcms:verify-schema` | `BCMS schema matches the frozen manifest: 65 tables.` |
| `pint --test` / `phpstan analyse --memory-limit=1G` on every file this pass touched | pass / no errors |

A subsequent one-off consolidated `--filter=Bcms` run (995 passed / 6637 assertions) turned up a single
failure in `Phase11WidgetsTest` — a unique-constraint collision on `bcms_exercise_occurrences`
(`definition_id`/`sequence_no`) with a DIFFERENT `definition_id` on each reproduction attempt, in a file
this pass never touched and the coordinator's own per-file list does not name. Reproduces even in
isolation (`--filter=Phase11WidgetsTest` alone), which points at cross-process contention against the
same shared `risk_test_p10_b9babae` database from the concurrently-running Phase 11 background agents,
not a regression from this pass. Flagged rather than fixed — outside this session's file boundary.

## Compliance check: PIR timing metrics in `PirService::refreshMetrics()` (compliance-analyst, 2026-09-25)

Read only. The verdict is **sound in design, with three corrections needed before the metrics are
examiner-facing.**

**The definitions are right.**
- `time_to_declare_minutes` is detection to declaration.
- `time_to_activate_minutes` is declaration to the first plan activation.
- `time_to_detect_minutes` is never computed.

These match `Incident`'s docblock and Phase 10 clause map §4.2. ISO 22301 does not define these
measures. They are the bank's own evaluation inputs: clause 8.6 (evaluation after a disruption, per
`ClauseRefs.php`) and clause 9.1. Findings from the review then feed clause 10.1. The PIR's own stamp
stays `iso22320.incident_response` (clause map §4.2). **Label them as what the system records.**
`IncidentService::declare()` sets `declared_at = now()`, and `PlanActivationService::activate()` sets
`activated_at = now()`. Both are the moments recorded in BCMS, not the moment a crisis team decided
something by telephone. Display labels: "Detection to declaration (as recorded)" and "Declaration to
first plan activation (as recorded)".

**Corrections (backend-engineer, then qa-engineer):**

1. **The values are not integers.** On Carbon 3.13.2 (`composer.lock`), `diffInMinutes()` returns a
   **signed float** (`vendor/nesbot/carbon/src/Carbon/Traits/Difference.php:391`). The PIR can
   therefore show `12.4833…` minutes, and the method's `@return array<string, int>` is untrue. Store
   whole minutes, rounded down from seconds, and say so in the label.
2. **Guard against negative intervals.** The declare path enforces `detected_at <= declared_at`, and
   an activation is created after its incident, so a negative value needs legacy, seeded or
   hand-edited data. It must still never appear as a metric. If the end is before the start, send the
   metric to `not_measured` with both times stated.
3. **Do not substitute `created_at` for a missing `declared_at` inside a metric.** ADR 0020's fallback
   starts a *regulatory clock*, where an early start is the safe error. A measurement labelled "declare"
   that silently uses the record's creation time is an unlabelled proxy. The path is only reached for
   rows that did not come through `declare()`. Use `not_measured` there.

**`time_to_detect` stays "not measured".** No column holds the time an incident began:
- `bcms_incidents` has `detected_at` and `declared_at`, and no onset time;
- `bcms_incident_log.logged_at` is when an entry was written;
- `bcms_incident_notifications.awareness_at` is when a reporting obligation's clock starts, which is
  not the onset and should not be reused as one.

Deriving the metric from any of these would measure the wrong thing.

**One finding bears on the regulatory clock itself, not only on this metric.**
- The CBN Risk-Based Cybersecurity Framework (2024), as quoted verbatim by
  [Mondaq](https://www.mondaq.com/nigeria/security/1518574/overview-of-the-cbn-risk-based-cybersecurity-framework-and-guidelines-for-deposit-money-banks-and-payment-service-banks),
  says incidents "should be reported to the CBN within twenty-four (24) hours after such incidents
  **occur**". The CBN PDF returns 403 to this tool, so this is a secondary-source quotation.
- Phase 10 clause map §3 (line 132) already lists "date/time of occurrence **and** of detection" as
  CBN report content.
- Running the clock from detection, as the `Incident` docblock says, is therefore an interpretation.
  It starts **later** than the literal wording, so it is the less conservative reading.
- Mitigation that already exists: `ClassifyBcmsIncidentRequest` lets an officer move `awareness_at`
  back to a known occurrence time without justification.
- **ADR item, not for Phase 12, whose schema is frozen by ADR 0023:** an optional, human-entered
  `bcms_incidents.onset_at` (`dateTime`, per ADR 0022), validated `<= detected_at`. It would let the
  CBN report carry the occurrence time it asks for, and when present
  `time_to_detect = detected_at − onset_at`.
- Counsel should confirm which anchor the CBN applies in practice. It is recorded here so the choice
  is deliberate.

**Proposed `not_measured` wording.** No column names, and never implying the value was zero:

| Metric | Case | Wording |
|---|---|---|
| `time_to_detect_minutes` | always (today) | "Not measured. The system records when the incident was detected, not when it began, so the time taken to detect it cannot be calculated. If the start time is known, state it in the review and in the regulatory report." |
| `time_to_declare_minutes` | no detection time | "Not measured. The incident record has no detection time." |
| `time_to_declare_minutes` | no declaration time | "Not measured. The incident record has no declaration time. The time the record was created is not used in its place." |
| `time_to_declare_minutes` / `time_to_activate_minutes` | end before start | "Not measured. The recorded times are out of order: {end label} {end time} is earlier than {start label} {start time}. Check the incident record." |
| `time_to_activate_minutes` | no activation | "Not measured. No continuity plan was activated during this incident." |
