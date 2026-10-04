# Phase 9 — Exercise execution workspace & After-Action Reports

**Branch:** `integration/bcms-remaining` · **Schema changes:** 1 table (`bcms_evidence`, ADR 0019), 0 columns on existing tables, 2 columns retired in place
**Agent:** backend-engineer · **Reads:** `plans/bcms/prompts/PHASE-09-execution-aar.md`,
`docs/bcms/phase-9-aar-clause-map.md`, `docs/adr/0019-phase-9-evidence-has-a-home-and-finalise-locks-it.md`,
the four screen specs in `docs/bcms/screens/`.

---

## 1. What landed on this pass

Most of the service layer — `AarService`, `OccurrenceExecutionService`,
`TimelineService`, `InjectService`, `ScoringService`, `CheckInService`,
`CarriedActionService`, `EvidenceService`, `AarExportService`, `AarAiDrafter`,
the `bcms_evidence` migration and model, `FindingSeverity`, and
`CorrectiveActionDueDateCalculator` — were already on disk from a previous
session and are described in their own docblocks; this pass read every one of
them before writing a line, per the clause map's own instruction. What this
pass added:

| Layer | Files |
|---|---|
| Controllers | `ExecutionController` (workspace, start, complete, timeline, injects, manual check-in, live-metrics, carried-actions, the AAR export), `ScoreController`, `CheckInController` (unauthenticated QR/SMS), `AarController`, `EvidenceController` |
| Form Requests | `StoreTimelineEntryRequest`, `StoreExerciseScoreRequest`, `StartOccurrenceRequest`, `CompleteOccurrenceRequest`, `UpdateAarRequest`, `ReopenAarRequest`, `StoreEvidenceRequest`, `CheckInByCodeRequest`, `ManualCheckInRequest` |
| Routes | ~25 new `bcms.*` routes in `routes/web.php`, plus four unauthenticated check-in routes alongside the existing cascade-ack pair |
| Models | `BindsToVisibleRecord` + `orgAnchorPath()` added to `Aar` and `ExerciseInject` (both now route-bound); `FindingSeverity` cast wired onto `Finding::severity` |
| Retirement | `ReadinessController`/`ReadinessService` stop accepting a bare `evidence_file_id` integer; a readiness task's evidence requirement is now answered from `bcms_evidence` |
| Tests | `Phase9ExecutionTest` (8 tests), `Phase9AarTest` (10 tests) |
| Docs | `RouteAuthorizationTest`'s allowlist gained the two check-in URIs, with the same reasoning as the cascade-ack pair |

## 2. Decisions this pass made, not inherited

**`Aar` and `ExerciseInject` needed `BindsToVisibleRecord`.** Neither had it —
`Aar` predates ADR 0017's route-binding sweep, `ExerciseInject` never had a
route before this phase. Both anchor through `occurrence.definition`, the same
path `Evidence` and `ReadinessTask` already take. `ExerciseInject` route-keys
on its plain `id` (no `HasBcmsUuid`, no uuid column on the table) — the
nested route `occurrences/{occurrence}/injects/{inject}/release` carries
`->scopeBindings()` so an inject cannot be addressed through the wrong parent.

**Evidence upload requires `bcms.exercise.facilitate` OR `bcms.exercise.evaluate`.**
ADR 0019 §4 names both roles as "actually present at an exercise"; the route
carries `permission:bcms.exercise.facilitate|bcms.exercise.evaluate` (Spatie's
`canAny()`), not a single permission with an OR'd check buried in the
controller — `RouteAuthorizationTest` and `PermissionCatalogCoversRoutesTest`
both parse the pipe form correctly.

**No server-rendered QR image.** The QR poster screen spec (§2) asks for a
server-generated PNG the facilitator can print. This product carries no QR
image library, and `CheckInService`'s own design (already on disk) is
**per-participant** tokens, not one shared assembly-point code — a hard
constraint the screen spec's "one QR for the whole poster" framing does not
quite anticipate. `ExecutionController::checkInPoster()` instead ships, per
participant, the check-in URL and the human-typeable short code; rendering
either as a QR image (client-side, or via a new composer dependency) is left
to frontend-engineer, flagged explicitly in the handoff rather than resolved
silently.

**`bcms.findings.store` gained `aar_id` and `objective_text`.** The existing
route/controller (Phase 1) never accepted either — a finding raised with
`source=aar` never actually recorded which AAR it came from, and there was no
way to link a low-scored objective to the finding raised against it (clause
map condition 6). Both are optional, tenant-scoped (`VisibleToUser(Aar::class)`),
and additive: nothing about the existing register screen changes.

**The finding→CAPA due-date rule lives in `FindingController::storeAction()`,
not in a new endpoint.** `CorrectiveActionDueDateCalculator` was already built
but wired nowhere. Per clause map §3.3, an action raised from an AAR finding
with no due date supplied now gets the computed one (severity default,
floored and capped against the next occurrence) — the *existing*
`bcms.actions.store` route, one `if` added at the top, per the "nothing here
is new code in Phase 1's services" rule.

**`FindingSeverity` is now cast on `Finding::severity`.** ADR 0019 §6 approved
this as needing no ADR of its own (an enum over an existing column). Wiring it
surfaced two real bugs in code that pre-dated this phase — `ErmBridge::priorityFor()`
matched the column against string literals, which would have silently
fallen through to `'low'` for every finding once the cast landed, and two
read paths (`FindingController::shape()`, `AarExportService::build()`,
`AarController::show()`) serialised the raw column into JSON. All three are
fixed alongside the cast in this commit; none is Phase 9's own feature, they
are the cast's necessary companions.

## 3. The evidence gap, closed

ADR 0019 built the table this phase needed; the retirement half (§3) still
needed doing. `ReadinessController::complete()` no longer validates a bare
`evidence_file_id` integer — it accepts an optional file upload, stores it
through `EvidenceService` with `owner_type = readiness_task`, and
`ReadinessService::complete()`'s "does this task have evidence" check now
queries `bcms_evidence` instead of the retired column. The column stays on
the table, unwritten, exactly as the ADR specifies — dropping it is a
separate, later decision.

## 4. What is deliberately not built here

- **A second findings/CAPA register.** The AAR builder screen creates
  findings and actions through the *existing* routes; `FindingController`
  and `Findings/Index.jsx` are untouched beyond the two additive fields
  above.
- **PDF rendering of the AAR export.** ADR 0019 §5 asks for JSON now, a PDF
  through `ThirdLine\Reporting\DocumentRenderer` as a follow-on; the JSON
  already answers the sufficiency question the compliance analyst set.
- **EMNS-channel delivery of injects.** In-app only, per the prompt's own
  `[verify at integration]` marker at the W11 window — `InjectService`
  releases through the timeline, nothing else.
- **The QR image itself** — see §2 above.

## 5. One Phase 1 guard test needed updating for Phase 9's own arrival

`Phase1GovernanceTest::carried_to_occurrence_id_is_exposed_and_is_not_populated_by_this_phase()`
asserts nothing outside the exercise engine writes `carried_to_occurrence_id`
— written when nothing did, its own docblock says the column "is written
EXCLUSIVELY by the exercise engine in Phase 9." Now that Phase 9's
`CarriedActionService::carryForward()` is the legitimate writer, and
`AarExportService::build()` legitimately reads the same column into the
export's CAPA status, both matched the test's regex (which cannot distinguish
an array literal built for `forceFill()` from one built for a JSON report).
Both files are now named explicitly in the test with the reasoning above,
rather than the assertion being loosened generally — a future third file
matching the pattern still fails the guard.

## 6. Two failures observed at handoff time, neither Phase 9's

Running the wider guard suite surfaced two unrelated red tests, both from
concurrent Phase 10 work landing on this same branch while this session was
open:

- `BcmsRecordVisibilityTest::every_bcms_route_parameter_binding_a_bcms_model_is_covered`
  fails on `bcms.training-records.assess` binding `App\Models\Bcms\
  TrainingRecord`, which has neither `BindsToVisibleRecord` nor a pinned
  organisation-level entry. That route and model are untouched by this
  session (`git diff HEAD` shows the route was added by another session
  mid-flight); it is not part of Phase 9's diff and is left for its own
  phase to close.
- `Phase1ScreensTest::the_live_sections_replaced_their_shells_and_kept_their_route_names`
  expects `bcms.incidents.index` to still render the generic `Bcms/Section`
  shell, per its own comment ("`incidents` is Phase 10's"). It now renders
  `Bcms/Incidents/Index` — Phase 10 replacing its shell exactly as the test's
  own pattern anticipates for every other section, which makes the test's
  choice of example stale rather than the section wrong. Updating the
  example to a still-unlanded section is Phase 10's (or that test's owner's)
  call, not this session's to make unilaterally.

Both were confirmed, by inspecting `git diff HEAD`, to be untouched by any
file this phase's diff carries.

## 7. Gate 1 defects closed (backend-engineer, second pass)

QA's two failing tests plus one Gate 2 finding carried over from Phase 7.5,
closed in one pass:

1. **`Phase9ExecutionTest::every_evidence_artefact_carries_an_iso_clause_ref`.**
   `EvidenceService::upload()` now derives `iso_clause_ref` from `owner_type`
   per the clause map (§1.2) when a caller supplies none —
   `occurrence`/`readiness_task` → `iso22301.8.5.exercise`, `score` →
   `iso22398.exercise_evaluation`, `aar` → `iso22301.8.5.report` —
   and `StoreEvidenceRequest` validates a caller-supplied value against
   `App\Enums\Bcms\IsoClauseRef` (`Rule::enum`) rather than trusting a free
   string. Every row created through the real HTTP route is now stamped.
2. **`Phase9AarTest::participant_feedback_comments_can_smuggle_a_user_identifier…`.**
   `UpdateAarRequest` now validates `participant_feedback` against the
   published `bcms.aar.feedback.v1` contract (clause map §2.3): nested rules
   for `schema`/`invited`/`responded`/`questions[]`/`comments[].{text,role,
   business_unit}`, and `user_id`/`name`/`email` on a comment are `prohibited`
   — present at all is a validation error, not a silent drop.
   `AarService::update()` additionally normalises any `participant_feedback`
   it is given to that same shape (`AarService::normaliseFeedback()`), so an
   import or the AI drafter — any caller that never passes through the Form
   Request — cannot smuggle an identifier through a different door.
3. **Acceptance criterion 9 / clause-map refinement 8 — the T+3/T+7 AAR
   chasers.** `ReminderScheduleBuilder::voidTemplates()` is a new, narrowly
   scoped sibling of the existing `voidUnsent()` — same void-not-delete path,
   restricted to a caller-named set of template keys. `AarService::finalise()`
   calls it with `exercise.aar_due`/`.aar_overdue`/`.aar_escalation` the
   moment `status` reaches `final`, inside the same transaction; `reopen()`
   calls `ReminderScheduleBuilder::build()` (the same regenerate-the-ladder
   path a rescheduled occurrence already uses), which finds the same rows by
   their unchanged idempotency key and flips them `voided → pending` with a
   freshly computed `send_at`. New test:
   `Phase9AarTest::finalising_the_aar_voids_its_overdue_chasers_and_reopening_resumes_them`.
4. **PHPStan.** `CheckInController.php` and two spots in `AarService.php`
   carry narrow `@phpstan-ignore-next-line nullsafe.neverNull` comments with
   the reason (Larastan resolves a `BelongsTo` on a NOT NULL FK column as
   always-present, which is not true of a soft-deleted or orphaned row) —
   the one at `conditions()`'s condition 1 turned out to be fixable outright
   rather than merely suppressible: `$occurrence?->x !== null &&
   $occurrence?->y !== null` re-uses the nullsafe operator on the same
   variable twice in one boolean expression, which is a documented PHPStan
   corner case (confirmed with `\PHPStan\dumpType()`) that resolves the
   second use's type as `mixed` — rewritten as a single leading `$occurrence
   !== null &&` guard followed by plain `->`, which is both correct and
   clean under analysis. `Aar` gained the missing `@property-read
   ?ExerciseOccurrence $occurrence` doc tag. `AarAiDrafter`'s dead `if
   ($attributes !== [])` guard (two unconditional keys are set immediately
   above it) is removed. `OccurrenceExecutionService::start()`'s stale
   `@return array{allowed: bool, reason: ?string}` docblock — copied from a
   neighbouring method — now says what the method actually returns. Zero new
   errors on every touched file; no baseline entries added.
5. **Gate 2 finding (Phase 7.5 review, ADR 0017 Amendment 1 assigns it
   here): `Aar`, `Evidence` and `ExerciseInject` had no named-user arm.**
   All three anchor through `occurrence.definition`, exactly `ReadinessTask`'s
   shape — and `constrainAnchorPath()`'s walk down that path only consults
   the *definition's* own `scopeVisibleTo()`, never the occurrence's own
   `facilitator_id` arm, so a cross-unit facilitator who could open the
   workspace still 404'd on the AAR, an inject release or the exercise's
   evidence. All three now declare `orgVisibilityNamedUsers()`:
   `Aar` and `ExerciseInject` → `['occurrence.facilitator_id']`; `Evidence` →
   that plus `uploaded_by` (upload requires `bcms.exercise.facilitate` OR
   `.evaluate`, and an evaluator/observer is not necessarily an
   `ExerciseParticipant` row at all, so there is no `participants.user_id`
   fact to name — the fact this row does carry is who captured it).
   **`Aar` needed a second change**: it overrides `constrainToVisibleRecord()`
   wholesale for ADR 0020 §1's dual occurrence/incident anchor, so the
   generic `BindsToVisibleRecord` body that would otherwise read
   `orgVisibilityNamedUsers()` automatically never runs for it — the override
   now ORs the named-user arm in explicitly, or declaring the method alone
   would have been a no-op. `BcmsRecordVisibilityTest::NAMED_USER_VISIBILITY`
   gained all three rows (the map's own count assertion moved with it), and
   each has its own behavioural test against the real write verb
   (`bcms.aars.update`, `bcms.occurrences.injects.release`,
   `bcms.evidence.destroy` — not `.store`, which never binds an `Evidence`
   model at all) with a same-unit bystander control proving the arm, not a
   plain unit match, is what let the named user through.

## 8. Gate 2 REJECTED, closed (backend-engineer, third pass)

Gate 2 (code-reviewer) rejected the Phase 9 execution/AAR pass on seven
numbered defects plus a set of advisories. Each numbered defect got a test
that failed before the fix and passes after; the advisories are folded into
the same commit. Read the fix in the code first — this is the "what and why
it needed a test" summary.

1. **`refreshComputedSections()` rewrote a FINAL report on every read.**
   Called from `conditions()` (itself called on every `AarController::show()`
   and again at `finalise()`) and from `AarExportService::build()`, it
   re-derived attendance, ladder warnings, CALLTREE metrics and every other
   computed section from LIVE data with no `status === 'final'` guard, then
   `saveQuietly()`'d the result — no audit row, and no refusal either. A
   manual check-in recorded after finalisation, or simply opening the AAR
   builder or running the examiner export, silently rewrote a signed-off
   report. Fixed with one early return at the top of the method; `conditions()`
   and the export both call it first, so both now read the frozen snapshot
   for a final report without any change of their own.
   `Phase9AarTest::a_final_aars_quantitative_results_is_byte_identical_across_a_check_in_attempt_a_render_and_an_export`.
2. **`CheckInService::checkIn()` had no frozen-state guard and discarded
   `$recordedBy`.** A facilitator could mark a straggler present on a
   completed occurrence whose AAR was already final, and `ExerciseParticipant`
   carried no `BcmsAuditable` at all, so nothing recorded who did it. Fixed by
   reusing `TimelineService::assertNotFrozen()` (the same guard `TimelineService`
   and `ScoringService` already apply, not a second one) and adding
   `BcmsAuditable` to `ExerciseParticipant`, plus an explicit `recordAudit()`
   call carrying the method and the actor (`'self'` on the unauthenticated
   QR/SMS path, where there is no signed-in user for the trait's own
   `auth()->user()` to find). `ExecutionController::checkIn()` and both
   `CheckInController` write actions now catch the resulting
   `InvalidArgumentException` and answer with the usual flash, never a 500.
   `Phase9ExecutionTest::a_check_in_is_refused_once_the_aar_is_final` and
   `::a_check_in_is_recorded_with_the_actor_and_the_method`.
3. **Evidence's `owner_id` was never checked against the route's own
   occurrence.** `StoreEvidenceRequest` validated it as a bare positive
   integer, and `EvidenceService::upload()` never confirmed the named score,
   readiness task or AAR actually belonged to the occurrence the upload was
   nested under. A facilitator on occurrence A could upload evidence with
   `owner_type=readiness_task`/`owner_id=<a blocking task on occurrence B>`,
   and `ReadinessService::complete()`'s "does this task have evidence" query
   matched on `owner_type`+`owner_id` alone with no occurrence filter — task B
   would read as evidenced by a file nobody uploaded against it. Fixed with
   `EvidenceService::assertOwnerBelongsToOccurrence()` (refuses at the one
   write path) and, belt-and-braces, `ReadinessService::complete()`'s own
   query now also filters on `occurrence_id`.
   `Phase9ExecutionTest::evidence_naming_a_readiness_task_on_a_different_occurrence_is_refused_and_the_task_stays_blocking`.
4. **The unauthenticated check-in routes carried no rate limit, and the
   short-code lookup was unbounded.** `CheckInService::participantForShortCode()`
   fetched every participant of every in-progress occurrence, tenant boundary
   included, on every attempt against a route with no throttle — an 8-hex
   (32-bit) code is small enough that this is a guessing surface as well as a
   cost. Fixed with a named `bcms-check-in` limiter
   (`AppServiceProvider::registerBcmsCheckInRateLimiter()`, `config('bcms.
   check_in_rate_limit_per_minute')`, keyed on ip — the same pattern the EMNS
   webhook limiters already use, registered at boot for the same route-caching
   reason) applied to both check-in route groups, and a bounded lookup: one
   indexed subquery selecting only `id`, capped at `MAX_SHORT_CODE_CANDIDATES`,
   with the matching participant fetched once after the tag comparison rather
   than every candidate row hydrated up front.
   `Phase9ExecutionTest::the_check_in_routes_are_rate_limited_per_ip` and
   `::short_code_lookup_issues_one_bounded_query_against_participants`.
5. *(Not assigned in Gate 2's own numbering — the review's list ran 1–4 then
   6–7; nothing is missing here.)*
6. **Re-scoring an objective wrote no audit trail.**
   `ScoringService::score()`'s `updateOrCreate()` silently overwrote the
   previous score and commentary. Fixed with `BcmsAuditable` on
   `ExerciseScore` — the automatic `updated` diff is now the trail; no service
   change was needed.
   `Phase9ExecutionTest::re_scoring_an_objective_writes_an_audit_row_with_the_old_and_new_score`.
7. **N+1 queries on the five-second poll and on every AAR-adjacent read.**
   `OccurrenceExecutionService::liveMetrics()` resolved `loggedBy` per
   timeline row (up to 50 extra queries per poll) and counted attendance by
   loading every participant row instead of two `COUNT` aggregates;
   `ExecutionController::show()` did the same for its 200-row timeline and
   every inject's `releasedBy`; `AarExportService::build()`'s timeline section
   and `AarController::show()`'s ten-row timeline both lacked the same eager
   load every other section on those two methods already carries. All four
   now eager-load `loggedBy:id,name`/`releasedBy:id,name` and the live poll's
   attendance is two aggregates.
   `Phase9ExecutionTest::live_metrics_stays_within_a_bounded_query_count_with_fifty_timeline_rows`.

**Phase 10 Gate 1 finding, folded into this pass at the orchestrator's
request** because it lives in files this pass already owns
(`AarController`, `UpdateAarRequest`, `ReopenAarRequest`): the shared AAR
routes checked only exercise-side abilities (`bcms.exercise.view`,
`bcms.aar.manage`/`.approve`), never the incident-side ones ADR 0020 §1 and
`pir-post-incident-review.md` §1 require for a post-incident review — a
holder of `bcms.aar.manage` alone (the `risk-owner` catalog role's actual
grant) could open and edit any incident's PIR through this one shared
surface. Fixed with `AarController::authorizeIncidentConjunction()`, called
from `show()`/`finalise()`/`distribute()`/`aiDraft()`, and the same
`bcms.incident.manage` check added to `UpdateAarRequest`/
`ReopenAarRequest::authorize()` — the conjunction `IncidentPresenter::
review()` already computes for its own `can.manage`/`can.approve` flags,
now enforced at the route rather than only reflected in a display flag. Two
tests Phase 10's own session had already written RED
(`Phase10IncidentTest::an_aar_manage_holder_without_incident_manage_cannot_
read_a_pir_via_the_shared_aar_route` and `..._cannot_write_...`) are GREEN
without modification.

**Advisories folded in the same pass:**

- `Evidence::$fillable` no longer carries `hash`, `locked_at` or `locked_by`
  (ADR 0019 §2) — all three are written only by `forceFill()`, in the one
  service that owns each write; `EvidenceService::upload()` now builds the
  model unsaved and `forceFill()`'s the hash before the single `save()` (the
  column is `NOT NULL` with no default, so it cannot be added in a second
  write after the row exists). Two direct `Evidence::create()` calls in
  `BcmsRecordVisibilityTest` needed the same shape.
- `EvidenceService`'s class docblock said `update()`/`destroy()` refuse a
  locked row; the method is `delete()` — corrected, and it now says plainly
  that there is no `update()` on this service at all.
- Condition 8 (`8_overrides`) compared a count against a count derived from
  the exact same rows a moment earlier in `refreshComputedSections()` —
  always equal, never able to fail. Replaced with an identity comparison
  (every currently-overridden task's title against the stored list, sorted),
  which is a real check once fix #1 above means a final report's stored list
  can genuinely diverge from a later live count.
- `not_measured[]` entries now require a non-blank `reason` to count towards
  condition 11 — clause map §2.2's own schema note ("a missing number and a
  number nobody measured are different facts" only holds if the difference is
  actually stated).
- `CheckInController::codeStore()` now redirects on success instead of
  rendering `CheckIn` directly from the POST — the one thing the class's own
  "post then redirect" rule exists to forbid, since a refresh of a rendered
  POST response would have resubmitted the code. The not-recognised branch
  still renders directly, because that is not a successful submission to
  protect from a refresh.
- `AarService::refreshComputedSections()`'s objective loop now issues one
  `ExerciseScore` query for the whole occurrence, grouped by objective in
  PHP, instead of one query per objective.

**Booked as a tracked follow-up, not fixed in this pass**:
`FindingController::storeAction()` (Phase 1, not Phase 9) validates
`owner_id` with a bare `exists:users,id` — the standard's own "no bare
`exists:`" rule, across a tenant boundary. Out of scope for this pass's Phase
9 files; raised for whoever next touches `FindingController`.

Pint clean and PHPStan zero new errors (no baseline growth) on every file
touched this pass. `Phase9ExecutionTest` (16), `Phase9AarTest` (18),
`Phase9ScreensTest` (9), `BcmsRecordVisibilityTest` (36), `Phase5CountdownTest`
(20), `Phase1GovernanceTest` (36) and `RouteAuthorizationTest` all green;
`Phase10IncidentTest`'s two previously-RED tests are GREEN.

## 9. A live hazard this session ran into, for the record

Partway through this pass, the shared branch's `database/migrations/` briefly
carried a Phase 10 migration (`bcms_phase10_incident_notifications_and_pir`)
with an index name over MySQL/MariaDB's 64-character identifier limit,
blocking every migration — and therefore every `RefreshDatabase` test, on
every phase — until the owning session corrected it. It is noted here only
because it is exactly the failure mode ADR 0020 §1 (the `Aar` model's new
`incident_id`/`occurrence_id` exclusivity) makes this phase's own tests
sensitive to: `Aar::constrainToVisibleRecord()` now unconditionally queries
`bcms_incidents`, so any test resolving an `Aar` by uuid depends on that
migration being present. Both `Phase9ExecutionTest` and `Phase9AarTest` were
run clean against a fully-migrated schema before handoff; if either shows a
`bcms_aars.incident_id` column-not-found error at integration, it means the
schema was caught mid-edit by a concurrent session, not a Phase 9 defect —
re-run after a fresh `php artisan migrate:fresh`.

## 10. Addendum (backend-engineer, BCMS Phase 11 pass) — `exercises` flipped live

`App\Support\Bcms\ModuleSections`'s `exercises` entry ("Exercises & AAR")
stayed `live => false` after this phase shipped the execution workspace,
scoring and the AAR — an oversight rather than a decision. Flipped to `true`;
the shell's own route name is kept (`bcms.exercises.index`), now redirecting
to `bcms.exercise-programmes.index`, the section's actual landing page,
exactly as `it-dr.index` already redirects to `dr-systems.index`. See
`docs/bcms/phase-11-notes.md` for the paired `compliance` flip and the test
fallout (`ModuleShellTest`/`Phase1ScreensTest` needed a synthetic non-live
section once every real one had landed).

## 11. Gate 2 review #2 — two blocking findings, closed; T-0 distribution assessed, not built

Review #2 rejected on two blocking findings plus four advisories whose text
was lost with the previous session (this pass re-reviewed the three files
from scratch rather than guess at them — see the last subsection).

### Finding A — no screen showed a participant's own code to anyone

After the poster fix (§9 above; `checkInPoster()`'s addendum), the poster
carries one QR to the code-entry *form*, never a per-participant token —
correct, but it left **nothing** showing a participant's short code or
check-in link to anybody, including the facilitator who is supposed to relay
it. `ExecutionController::show()`'s `attendance.not_checked_in[]` rows
carried only `id`/`name`. Acceptance criterion 3 (a participant checks in by
QR or short code) was unmeetable in the product as built — there was no
route by which a code ever reached a human.

**Fixed exactly to the contract given**: each `not_checked_in` row gains
`short_code` (`CheckInService::shortCodeFor()`) and `check_in_url`
(`route('bcms.check-in.show', CheckInService::tokenFor())`) **only** when
`$request->user()->can('bcms.exercise.facilitate')` is true; the keys are
**absent**, not null, for anyone else — a viewer holding only
`bcms.exercise.view` gets neither key on the wire at all. There is no
checked-in list in this payload to mirror the same treatment onto (attendance
only ever exposes a count for the checked-in side; see `metrics`/`attendance`
in `show()`).

Both computed values are pure HMAC arithmetic (`hash_hmac` over the
participant id), not a query, so this adds no N+1 to a screen that already
polls itself every five seconds.

New tests (`Phase9ScreensTest`):
`a_facilitator_sees_the_check_in_credential_for_every_not_checked_in_participant_and_the_url_resolves`
proves the URL is live, not just present — it `GET`s the server-built
`check_in_url`, asserts the real `CheckIn` screen renders, then `POST`s to it
and asserts the named participant is actually checked in.
`a_viewer_without_facilitate_never_sees_the_check_in_credential` asserts both
keys `missing()` for a `bcms.exercise.view`-only user. The existing poster
test (`the_check_in_poster_ships_no_participant_tokens_or_short_codes`)
gained `missing('attendance')`/`missing('short_code')`/`missing('check_in_url')`
as an explicit regression against this same fix leaking onto the
unauthenticated poster.

### Finding B — one ip-keyed limiter on both check-in routes defeated the drill it protects

The single `bcms-check-in` limiter (60/min, keyed on ip alone) sat on **both**
the short-code form and the per-participant token routes. Two hundred people
behind one office NAT, each scanning their **own, distinct** token during an
evacuation drill, shared the same bucket a token-guessing attacker would —
request 61 was locked out regardless of whose token it was. The limiter was
defeating the exact scenario the feature exists for.

**Split into two named limiters**, registered at boot in
`AppServiceProvider::registerBcmsCheckInRateLimiters()` (renamed from the
singular form):

- `bcms-check-in-code` — unchanged in shape from the old limiter: per-ip,
  `config('bcms.check_in_rate_limit_per_minute')` (default 60). This is the
  genuine guessing surface — an 8-hex code typed by a human — so it stays
  ip-keyed. Applied to `GET/POST bcms/check-in` (the short-code form).
- `bcms-check-in-token` — **per-token**, `config('bcms.check_in_token_rate_limit_per_minute')`
  (default 10/min per token), **plus** a per-ip ceiling,
  `config('bcms.check_in_ip_rate_limit_per_minute')` (default 600/min),
  returned as an array of two `Limit`s from the same closure — Laravel
  enforces every limit in the array, so either can trip first. Applied to
  `GET/POST bcms/check-in/{token}`. Two hundred people from one ip, each
  hitting their own token once, all clear both limits; the 11th attempt
  against the *same* token within the minute is refused regardless of ip.

`routes/web.php`'s single check-in group is now two groups, one per
limiter name, in the same declaration order (the literal `bcms/check-in`
route still precedes the parameterised one). `config/bcms.php` gained the
two new keys with the reasoning above; `check_in_rate_limit_per_minute` keeps
its name and default since it now names only the code-form limiter, not
"the" check-in limiter.

New tests (`Phase9ExecutionTest`):
`two_hundred_participants_behind_one_ip_all_check_in_by_their_own_token_within_a_minute`
(inserts 200 participant rows directly — `user_id` is nullable and the
`(occurrence_id, user_id)` unique index tolerates many nulls — and checks
every one in from one IP inside the test's own minute);
`the_eleventh_attempt_on_the_same_token_within_a_minute_is_refused` (same
token, 11 GETs, the 11th is 429). The old
`the_check_in_routes_are_rate_limited_per_ip` test is replaced with
`the_short_code_form_is_rate_limited_per_ip`, rewritten honestly against the
route that is still genuinely ip-keyed rather than adapted to a claim the new
design no longer makes.

### Advisory pass (four advisories whose text was lost — re-reviewed from scratch)

Nothing rose to a blocking defect on this re-read of `CheckInController`,
`CheckInService` and `ExecutionController`. Worth recording rather than
silently dropping:

- `CheckInByCodeRequest::rules()` validates `code` as `required|string|max:16`
  — it does not enforce the 8-hex-character shape at the Form Request layer;
  a malformed submission burns a `bcms-check-in-code` limiter slot before
  `CheckInService::participantForShortCode()`'s own regex rejects it. Not a
  security defect (the service still refuses it, and the limiter is the
  actual protection against a scripted attempt), but a tighter Form Request
  rule would give a clearer 422 instead of a generic "not recognised" and
  save the limiter budget for genuine attempts. Left as-is: out of this
  pass's assigned findings, flagged for whoever next touches that request.
- `CheckInService::participantForShortCode()`'s candidate scan has no
  tenant filter at all (by design — the token/code IS the credential, and
  tenancy is not yet resolved on this unauthenticated route) and is capped
  at `MAX_SHORT_CODE_CANDIDATES = 5000`. Already documented in the class as
  "not expected to bind in ordinary use"; re-confirmed correct, not
  reopened.
- The new per-token limiter key (`'bcms-check-in-token:'.$token`) uses the
  raw route-parameter string as the cache key, unhashed. Tokens are always
  the fixed `chk-{id}-{16 hex}` shape in practice (~30 characters); even a
  hostile, arbitrarily long path segment is bounded by ordinary URL-length
  limits before it reaches this closure, so this is not treated as a cache
  key exhaustion risk, but is worth a hashed key (`hash('sha256', $token)`)
  if this pattern is ever reused somewhere the input is less constrained.
- `CheckInController::codeStore()`'s not-recognised branch still renders the
  `CheckInCode` component directly, not via redirect — correct, per the
  class's own docblock (it is not a successful submission to protect from a
  refresh), reconfirmed rather than changed.

### T-0 distribution — assessed, not built

`qr-checkin.md` §1 assumes each participant is texted/emailed their own
code at exercise start. Assessed against the real EMNS layer
(`App\Services\Bcms\Emns\*`) before writing anything, per the brief's own
"assess first, build only if contained" instruction — **not built**, because
it is not contained:

- The audience grammar already has exactly the right leaf type —
  `AudienceRule::make('occurrence_participants', ['id' => $occurrence->id])`
  (`app/Support/Bcms/AudienceRule.php`) — so composing an alert scoped to one
  occurrence's participants needs nothing new.
- But **no per-recipient variable ever reaches a rendered message today.**
  `TemplateRenderer::render()` does accept an `array $variables` parameter
  and substitutes `{{key}}` placeholders — the mechanism exists — but its
  **one caller**, `AlertDispatcher::sendOne()`, passes a hard-coded `[]` on
  every send:
  `$this->renderer->render($alert, $channel, $lang, [], $this->tokenFor($recipient))`.
  There is no path anywhere from `AlertService::release()` →
  `DispatchAlertChunkJob` → `AlertDispatcher::dispatchBatch()` →
  `sendOne()` that carries a *per-recipient* value in. Wiring
  `short_code`/`check_in_url` through would mean changing `sendOne()`'s
  signature and its one caller, `dispatchBatch()`, and its caller
  `DispatchAlertChunkJob::handle()` — none of which are files this pass is
  scoped to touch.
- It would also need to resolve, for every `AlertRecipient` in the batch,
  *which* `ExerciseParticipant` row it corresponds to. `bcms_alert_recipients`
  carries `contact_id`, not a participant id, and `bcms_exercise_participants`
  has no FK to `bcms_contacts` beyond the same `user_id` a `Contact` also
  carries — so the join is `contact.user_id = participant.user_id AND
  participant.occurrence_id = alert.occurrence_id`, correct but another piece
  of logic with nowhere obviously right to live inside the frozen schema and
  the file boundary given.
- No new column and no new table would be needed (the token/short code are
  already derived, not stored), but a new `AlertTemplate` row authored for
  this scenario likely would be, and `database/seeders/Bcms/Reference/
  AlertTemplates.php` is mid-edit by a concurrent session on this branch
  right now (see the working tree at handoff time) — touching it here risks
  a collision independent of scope.

Altogether: real work across `AlertDispatcher`, `DispatchAlertChunkJob`, and
plausibly `AlertService`, none of them in this pass's file boundary, well
past the ~150-line contained-fix bar, and touching a file another session
currently has open. **Not built.** The gap: today, T-0 distribution of a
participant's own code is not automated — a facilitator reads it from the
workspace (finding A's fix, above) and relays it by hand, exactly as the
poster's own addendum in §9 already says the SMS/email path is assumed to do,
without that path yet existing. This is the honest state to hand to whoever
picks up T-0 distribution as its own piece of work, not "finished."

### Suite run at handoff

`Phase9ExecutionTest` (18, was 16), `Phase9AarTest` (18), `Phase9ScreensTest`
(11, was 9), `Phase1ScreensTest` (17), `RouteAuthorizationTest` (4),
`PermissionCatalogCoversRoutesTest` (6) — all green. Pint clean and PHPStan
zero new errors on every file this pass touched.

### Addendum — Gate 1 defect (review #3): a throttled check-in used to dead-end

`qr-checkin.md` §3's "Rate-limited" state promises a plain "Too many
attempts — wait a moment and try again," on the same unauthenticated shell
that would otherwise have answered the request. Before this fix, tripping
either `bcms-check-in-code` or `bcms-check-in-token` fell through to
Laravel's default 429 page instead — `text/html`, no product shell, no
`X-Inertia` header — a dead end for a participant standing at an assembly
point with nothing but a phone, and exactly the kind of surface this
module's own Definition of Done says never dead-ends.

**Fixed in `bootstrap/app.php`**, in the same `withExceptions()` closure that
already scopes a 419 handler to the guest auth screens, following the same
shape: a new `render()` callback typed on
`Illuminate\Http\Exceptions\ThrottleRequestsException`, matched on the
request's route NAME against exactly the four check-in routes
(`bcms.check-in.code`, `bcms.check-in.code.store`, `bcms.check-in.show`,
`bcms.check-in.store`) and `return null` (falling through to Laravel's
default handling) for every other route in the application, including every
other throttled surface.

The Inertia/plain split reuses the SAME discriminator the 419 handler above
already uses, `$request->expectsJson()`, rather than checking for the
`X-Inertia` header directly:

- A real browser's first load of a check-in link (no `X-Inertia` header at
  all) and the SPA's own client-side Inertia navigation once it has booted
  (which DOES send `X-Inertia: true`, but an `Accept` header Inertia's own
  axios client sets to `text/html, application/xhtml+xml`, never
  `application/json`) are BOTH `expectsJson() === false` — so both fall into
  the same branch, and `CheckInController::renderThrottled()`'s own
  `Inertia::render()` call still answers each correctly once `->toResponse()`
  runs: the full HTML shell with no `X-Inertia` request header, the JSON
  page-morph with one.
- Only a request that explicitly wants JSON — `Accept: application/json`, or
  ajax-without-pjax accepting any content type — trips `expectsJson()` and
  gets the plain-text branch instead: a bare 429 with the same sentence,
  `Content-Type: text/plain`, never Laravel's stack page.
- This was the thing the earlier draft of this fix got backwards: checking
  `X-Inertia` directly on the way IN sends every ordinary browser request
  (which never carries that header, even on a genuine first load) to plain
  text, and — because this test suite's `assertInertia()` helper only
  reads a rendered VIEW (`TestResponse::assertViewHas('page')`; it has no
  path for the JSON variant at all), a test that forced `X-Inertia: true` to
  reach the Inertia branch could never pass `assertInertia()` either. Both
  problems went away once the branch was keyed on `expectsJson()`, exactly
  as the 419 handler already does two dozen lines above it.

`CheckInController` gained one method, `renderThrottled(string $routeName,
?string $token = null)`, called from the new handler rather than
duplicating page-building logic there. For the two code-form routes it
builds the same `['error' => ..., 'code_form_url' => route(...)]` shape
`codeStore()`'s own not-recognised branch already builds. For the token
pair it calls the controller's existing private `resolveToken()` and
`render()` — unchanged — and adds the `error` prop via `Inertia\Response
::with()`, so a still-open, unchecked-in participant's throttled page
carries the same `check_in_url` and "THIS IS AN EXERCISE" banner an
un-throttled request to the same URL would have shown; only a genuinely
unrecognised or already-closed token collapses to that branch's own
`ok: false` shape, exactly as it would outside a throttle event.

The `Retry-After` header (and its `X-RateLimit-*` siblings) the throttle
middleware already computed on `$e->getHeaders()` is carried onto the
replacement response rather than recomputed, on both branches.

**Advisory closed in the same pass**: Gate 2 review #2's 200-participants
test worked out exactly at the per-ip ceiling — 200 people × 3 requests
each (GET the link, POST "I'm here", the redirect-GET that follows) is
exactly 600, the ceiling `check_in_ip_rate_limit_per_minute` shipped with —
so one nervous double-tap from anyone in the drill would have tripped it.
Raised to 1200 in `config/bcms.php`, with the arithmetic recorded in the
config comment rather than left implicit; the per-token ceiling
(`check_in_token_rate_limit_per_minute`, unchanged) still catches a script
hammering one specific token regardless.

New tests (`Phase9ExecutionTest`, 18 → 21):
`a_throttled_browser_request_to_the_short_code_form_renders_check_in_code_not_the_stock_429_page`,
`a_throttled_browser_get_on_a_token_page_renders_check_in_not_the_stock_429_page`
and `a_throttled_json_expecting_request_gets_plain_text_429_not_the_stock_error_page`
— all three proven failing against the pre-fix handler (temporarily
short-circuited to `return null`, never committed in that state) before the
fix landed. The four pre-existing rate-limit tests in the same file —
`the_short_code_form_is_rate_limited_per_ip`,
`two_hundred_participants_behind_one_ip_all_check_in_by_their_own_token_within_a_minute`,
`the_eleventh_attempt_on_the_same_token_within_a_minute_is_refused` and
`short_code_lookup_issues_one_bounded_query_against_participants` — were
untouched and stayed green; none of them inspect the 429 body, so the
change of branch underneath them was invisible to their assertions, exactly
as it should be.

Suite run for this addendum: `Phase9ExecutionTest` (21, was 18) green,
`Phase9ScreensTest` (11) green. Pint clean and PHPStan zero new errors on
`bootstrap/app.php`, `app/Http/Controllers/Bcms/CheckInController.php`,
`config/bcms.php` and the test file.

### Addendum — code-reviewer rejection #3, fix cycle: `renderThrottled()` still leaked valid-vs-invalid

**BLOCKING DEFECT 1.** The Gate 1 review #3 fix above (previous addendum) reused
`resolveToken()`/`render()` for the token pair's throttled page "so the page
gets its usual `check_in_url`/... props ... exactly as an ordinary request
would build them." That was the defect: a request that had already tripped
`bcms-check-in-token` still ran the participant lookup and the
occurrence/site/definition/user loads (~5 queries) the ceiling exists to
stop, and answered a valid token with `ok: true`, the participant's NAME,
exercise, site and a working `check_in_url`, a guessed token with
`ok: false` — distinguishing valid from invalid at unlimited speed once
over the limit. That is exactly what `qr-checkin.md` §3(B)'s "Unknown or
expired token" rule forbids ("the page never reveals which exercise a
token *does* belong to, which would leak information to someone probing
tokens") and it breaks ADR 0016 §4 — "a ceiling is only a ceiling on the
work that happens after it." It also dead-ended into a shape `CheckIn.jsx`
never reads an `error` prop from at all, on the branch that matters most
(the token pair, not the short-code form).

**Fixed** by no longer calling `resolveToken()`/`render()` on the token
branch of `CheckInController::renderThrottled()` at all. It now returns
exactly the same minimal shape `render()` already answers with for a null
participant — `['ok' => false, 'headline' => $error, 'detail' => null]` —
which `CheckIn.jsx` already mounts correctly with no code change needed
(`exercise` absent falls back to rendering `headline`; `name`/`site`/
`check_in_url` are simply not read when absent). A valid token and a
guessed one are now indistinguishable once throttled, by construction —
there is no branch left that could tell them apart.

`the_..._get_on_a_token_page_renders_check_in_not_the_stock_429_page` in
`Phase9ExecutionTest` is rewritten to assert this: `ok` false, the sentence
as `headline`, `name`/`exercise`/`site`/`check_in_url` all MISSING; the
same props for a valid token and a made-up one; and zero queries against
`bcms_exercise_participants` on the throttled request itself (`DB::
enableQueryLog()`/`getQueryLog()`, the pattern the same file already uses
for the bounded-query tests). Proven failing against the pre-fix body
first — reinstating the old two-line `resolveToken()`/`render()`->`with()`
body and re-running just this test reproduces the exact query the fix
removes:

```
select * from `bcms_exercise_participants` where ... limit 1
```

**Advisories closed in the same pass:**

- **Advisory 2** — the per-ip `Limit` on `bcms-check-in-token` was provably
  untestable: both `config(...)` reads in `AppServiceProvider::
  registerBcmsCheckInRateLimiters()` were captured by `use(...)` at BOOT, so
  `config()->set()` in a test could not lower the ceiling, and deleting that
  `Limit` from the returned array would have left the suite green. Fixed by
  reading `config(...)` INSIDE each closure, at call time, rather than
  closing over it — documented in both the provider's own docblock and a
  new paragraph in `config/bcms.php`. The stale `600` fallback on the
  per-ip `Limit` (the pre-Gate-1-review-#3 value; the config default moved
  to `1200` but this one closure default did not) is corrected to match.
  New test: `the_per_ip_ceiling_on_the_token_route_trips_across_distinct_
  tokens_from_one_ip` — lowers the ceiling to 3 with `config()->set()`,
  proves three DISTINCT tokens (each nowhere near its own per-token limit)
  pass and a fourth from the same ip is refused.
- **Advisory 3** — `CheckInService::TOKEN_PATTERN` was unanchored (`\b...\b`)
  and case-insensitive (`/i`), so `CHK-1-<tag>`, `x.chk-1-<tag>` and
  `chk-1-<tag>-junk` all resolved to the same participant while presenting
  a DIFFERENT string to the `bcms-check-in-token` limiter (keyed on the
  raw `{token}` path segment) — decoration was a free way to sidestep the
  per-token ceiling. Anchored (`^...$`) and case-sensitive now; `tagFor()`
  only ever emits lowercase hex, so no genuine token needed the `/i` flag.
  Checked for a sibling SMS-body parser first: there isn't one — check-in
  has no inbound SMS handler at all, unlike `CascadeEngine`/
  `InboundResponseHandler`, which read a token out of a message body and
  genuinely need the `\b` word-boundary shape for that reason. New test:
  `a_decorated_token_is_refused_even_though_the_undecorated_token_resolves`.
- **Advisory 5** — `a_viewer_without_facilitate_never_sees_the_check_in_
  credential`'s two `missing()` assertions could pass vacuously against an
  empty `not_checked_in` array; `->has('attendance.not_checked_in', 1)`
  pins it to the one participant the test actually sets up. Separately, the
  facilitator credential test's POST ran while the test client was still
  authenticated as the facilitator (`actingAs()` persists for the rest of
  a test method) — proving the route accepts a POST, not that a genuinely
  relayed link works with no session. A second participant is now checked
  in after `Auth::logout()` + `flushSession()` (+ `assertGuest()` to prove
  the state actually changed), using a credential read from a second,
  authenticated workspace fetch — the same shape a facilitator relaying a
  link to someone else actually produces.
- **Advisory 6** — `CheckInController::store()`/`codeStore()` called
  `checkIn()` with no third argument, so a signed-in facilitator opening a
  copied link (exactly what the workspace's own `check_in_url` credential,
  Gate 2 review #2 finding A, is *for*) was recorded as `recorded_by:
  'self'` — indistinguishable from the participant's own scan. Both routes
  now pass `$request->user()` through (`store()` gained a `Request`
  parameter for it); the ordinary case — no session at an assembly point —
  is unaffected, since `checkIn()`'s own null-to-`'self'` fallback still
  applies then. New test:
  `a_facilitator_relaying_a_copied_link_is_recorded_as_the_actor_not_self`.
- **Advisory 7a** — the short-code FORM submission (`codeStore()`) was
  recorded as `check_in_method: 'sms'`, which never happened: nothing in
  this module parses an inbound SMS reply into a check-in. `check_in_method`
  is a plain `string(20)` column (migration `2026_09_09_120003_create_
  bcms_exercise_engine_tables.php:302`) with no enum constraint at the
  database or the model (`ExerciseParticipant`'s own `@property ?string`),
  so `'code'` was free to use and reads honestly. The migration's own
  inline comment (`// qr|sms|manual|geo`) is now stale — out of this pass's
  file boundary to fix; noted here for whoever next touches that file.
  `check_in_works_by_token_by_short_code_and_manually_and_is_idempotent`'s
  assertion is updated to match (`'sms'` → `'code'`); grepped the rest of
  the app for a hard-coded `'sms'` comparison against `check_in_method` —
  none exists (`AarService`/`AarExportService` both read the column
  generically).

**Booked, not done this pass** (advisories 7b and 7c, explicitly out of
this pass's scope): 7b, the live poll not refreshing the not-checked-in
list; 7c, a check-in token surviving in the session's `_previous.url` and
the accepted-pattern question that goes with it. Both need their own pass
against the workspace polling code and the session/redirect chain
respectively — neither is a contained fix inside this file boundary.

Suite run for this addendum: `Phase9ExecutionTest` (24, was 21) green,
`Phase9ScreensTest` (11, unchanged count — two existing tests strengthened,
none added) green, `Phase9AarTest` (18, unchanged) green. Pint clean;
PHPStan zero new errors (`--memory-limit=1G`) on
`app/Http/Controllers/Bcms/CheckInController.php`,
`app/Services/Bcms/Exercises/CheckInService.php`,
`app/Providers/AppServiceProvider.php` and `config/bcms.php` (phpstan.neon
does not analyse `tests/`, so the test files were checked with Pint only).
`bootstrap/app.php` is unchanged — the earlier draft of this addendum
updated one of its comments to stay accurate about the new `renderThrottled()`
shape, but that file is outside this pass's file boundary, so the edit was
reverted; its comment now slightly over-states what the token branch
carries once throttled (`check_in_url`/"THIS IS AN EXERCISE" are no longer
there) — a one-paragraph correction for whoever next has that file open.
