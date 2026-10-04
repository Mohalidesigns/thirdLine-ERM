# Phase 7.5 — Record visibility is resolved at route binding

**Branch:** `integration/bcms-remaining` · **Schema changes:** 0 tables, 0 columns
(ADR 0017 §"What this ADR deliberately does not do")
**Remediation, cross-phase.** Runs after Phase 7 clears its gates and before
Phase 9 opens. Implements `docs/adr/0017-bcms-record-visibility-is-resolved-at-route-binding.md`
(as amended 2026-09-14) at route binding; ADR §7's body-id predicate is delivered at
two of its nine sites — the other seven are tracked in §7 below. Read the ADR first — this is the work order it points to, filled in.

---

## 1. What this phase is

Every BCMS **list** screen has always asked "which business units may this
user see" before showing a row. No BCMS **record** route ever asked "is this
particular row one of the ones this user may see" — it asked only "does this
kind of user hold this permission", which is a different question. A Kano
branch manager holding `bcms.bia.complete` could `GET` Lagos's assessment and
submit it. This phase closes that gap at exactly one layer — route-model
binding — rather than by adding a policy call to a hundred and twenty
controller methods, for the reasons ADR 0017 gives at length: a policy has to
be *called*, and the call site is the thing that gets forgotten.

## 2. What was built

**`App\Models\Bcms\Concerns\BindsToVisibleRecord`**, overriding
`resolveRouteBindingQuery()` — not `resolveRouteBinding()` or
`resolveChildRouteBinding()` separately. Laravel's own
`resolveChildRouteBindingQuery()` already constrains a scoped nested parameter
to its parent relation and then calls the **child model's**
`resolveRouteBindingQuery($relationship, ...)`, handing it a `Relation` in
place of a `Builder`. Overriding that one method covers a plain top-level
`{model}` segment and a `->scopeBindings()` nested segment with the same code,
which is what ADR 0017 §5 asks for without a second method to keep in step
with the first.

**Sixteen models were given the trait by this phase** — five anchors (carry their own
unit column) and eleven derived (reach a unit only through a relation); Phases 2C, 9
and 10 added more in the same window, so the tree holds more than sixteen — the guard
test, not this table, is the authoritative count:

| Category | Models | How it declares itself |
|---|---|---|
| Anchor | `Plan`, `Process`, `ExerciseDefinition`, `CallTree`, `Finding` | `use ScopedToOrgHierarchy` + `implements ScopedToOrgHierarchyContract` |
| Derived | `PlanSection`, `PlanActivation` → `plan`; `CorrectiveAction` → `finding`; `Strategy`, `BiaAssessment` → `process`; `Dependency` → `assessment.process`; `ExerciseOccurrence` → `definition`; `ReadinessTask` → `occurrence.definition`; `CallTreeNode`, `CallTreeTest` → `callTree`; `CallTreeTestNode` → `test.callTree` | `orgAnchorPath(): string` |

**Models pinned organisation-level** (§6.3's whole-map assertion in
`BcmsRecordVisibilityTest` — the test's count is authoritative, fourteen at the time of
writing once Phases 2C, 10 and 11 added theirs): `Programme`, `ProgrammeObligation`,
`ManagementReview`, `ExerciseProgramme`, `BiaCampaign`, `Alert`,
`AlertRecipient`, `AlertTemplate`, and — landed in the same window rather than
before it — Phase 2C's `IdentitySyncRun` and `IdentitySyncChange` (ADR 0018
§8, whose own class docblocks already state the organisation-level reasoning
this phase's map records).

**Twelve routes now carry `->scopeBindings()`**: the five nested groups ADR
0017 §5 names — `plans/{plan}/sections/{section}` (update, destroy),
`plans/{plan}/activations/{activation}/deactivate`,
`call-trees/{call_tree}/nodes/{node}` (update, destroy, reparent, deputy),
`call-tree-tests/{test}/nodes/{node}` (ack, failure, fix-contact, finding),
and `bia/assessments/{assessment}/dependencies/{dependency}` (destroy).
Without it, e.g. a section belonging to another division's plan could be
addressed through a plan the user can see, by id, regardless of which plan the
URL names.

**The named-user arm** (§4 point 5, as amended: a reviewed map, today `ExerciseOccurrence`
and `ReadinessTask` — see §5) lives as `orgVisibilityNamedUsers(): array`
on `ExerciseOccurrence` — `['facilitator_id', 'participants.user_id']` — which
is what keeps Phase 5's T-10 confirm-attendance link working for a participant
invited across a unit boundary. The OR-in-place is shared code
(`Concerns\AppliesNamedUserVisibility`), used by both `ScopedToOrgHierarchy`
(for an anchor's own `scopeVisibleTo()`, which is also what list screens
call) and `BindsToVisibleRecord` (for a derived model's binding-time
predicate).

**`Finding::orgScopeColumn()` is fixed.** `bcms_findings` has no
`business_unit_id`; the override returns `affected_business_unit_id`. Before
this, `Finding::visibleTo()` would have raised an unknown-column error on its
first call — uncaught until now because nothing had ever called it.

**`App\Rules\Bcms\VisibleToUser`** (ADR 0017 §7) applies the same predicate to
an id arriving in a request body, by delegating to
`BindsToVisibleRecord::constrainToVisibleRecord()` rather than re-deriving the
rule. Applied to the three `FindingController::store()` fields that name a
record directly — `affected_process_id`, `affected_plan_id` (the ADR's own
two, previously a bare `exists:bcms_processes,id` / `exists:bcms_plans,id`, a
cross-*tenant* oracle and not merely a cross-unit one) and `aar_id` (added by
Phase 9's own concurrent work on the same controller, using the identical
rule).

**Correction (gate 2 round 1, defect 4): not all of the other body-id sites
are tenant-bound `Rule::exists`.** The first version of these notes said they
were. `CallTreeTestController::store():62` validates `occurrence_id` as a bare
`['nullable', 'integer']` — no `exists:` at all, bare or otherwise — inside an
inline `$request->validate([...])`. That is standard §4's violation *and* ADR
0017's own excluded category at once: the ADR's "What this ADR deliberately
does not do" already declines to fix BCMS's 54 inline `validate()` calls as a
product-wide drift, separately scoped. This one is recorded here rather than
silently swept into "already correct" a second time.

## 3. The PHPStan-shaped decision nobody asked for

`ScopedToOrgHierarchy` is a **trait** — `method_exists($this, 'scopeVisibleTo')`
is true at runtime and invisible to static analysis, which means PHPStan
cannot tell an anchor from a derived model either, and duck-typed dispatch
across sixteen classes sharing one generic trait method produced sixteen
"undefined method" reports (Larastan re-analyses a shared trait method once
per using class, and does not prune branches on a runtime `method_exists()`
guard).

The fix is `App\Models\Bcms\Concerns\ScopedToOrgHierarchyContract`, a real
interface every anchor now `implements` alongside `use ScopedToOrgHierarchy`.
`BindsToVisibleRecord` narrows on `instanceof ScopedToOrgHierarchyContract`
and calls `$anchor->scopeVisibleTo($query, $user)` **directly**, not through
the magic `$query->visibleTo()` scope-forwarding — `instanceof` is the one
form of "does this class have this method" PHPStan can actually verify, and a
direct call to an ordinary public method needs no forwarding to check.
`scopeVisibleTo()`'s interface signature carries its own per-call
`@template TModel of Model`, not `Builder<static>`: every call site reaches it
through an `instanceof`-narrowed `Model&ScopedToOrgHierarchyContract` variable,
which is not a concrete class PHPStan can bind `static` to, and `Builder`'s
own template parameter is invariant — `Builder<Model>` does not accept
`Builder<Plan>` merely because `Plan extends Model`.
`BcmsRecordVisibilityTest::every_model_using_binds_to_visible_record_is_classifiable()`
now also asserts every `ScopedToOrgHierarchy` user `implements` the contract,
so a sixth anchor forgetting the interface fails the suite rather than
PHPStan alone.

The whole PHPStan-attributable diff for this phase is now zero new errors
(checked against an ambient count taken before this work started, on the same
branch, with a concurrent unrelated agent's work already in the tree).

## 4. What this phase deliberately left alone

Everything ADR 0017 itself lists under "What this ADR deliberately does not
do" — no `app/Policies/Bcms`, no global scope, no middleware, no schema
change, `RcsaScope` unchanged, `Alert` unscoped by design, the 54 inline
`$request->validate([...])` calls elsewhere untouched. `CalendarService`,
`CallTreeService`, `GapAnalysisService`, `PlanLibraryPresenter`,
`StrategyRegisterPresenter`, `ResilienceCalendarPresenter` and
`BiaReportController` — the consumers ADR 0017's Consequences section names —
were read, not edited; none of them call `scopeVisibleTo()` in a way this
phase's changes affect, per that section's own claim (Amendment 3 corrects one
sentence of that claim about `CalendarService` specifically — see §5 below —
without changing what this phase touched).

## 5. Gate 2, round 1: four defects, three fixed here and one an ADR amendment

Gate 2 rejected the phase first pass on four points. Recorded here in the
ADR's own style — the defect, the fix, the test — because a phase note that
only describes the shipped shape hides exactly the reasoning a reviewer needs
to trust it the second time.

**Defect 1 (ADR amendment, not a code defect): the named-user arm's
non-transitivity.** `ReadinessTask` is derived on `occurrence.definition`, and
`constrainAnchorPath()` walks that as a bare `whereHas` chain consulting only
the anchor's `scopeVisibleTo()` — nothing on the walk consults the
*intermediate* occurrence's named-user arm. A cross-unit facilitator
(`ExerciseOccurrence`'s own arm exists exactly so they can reach the
occurrence) got 404 from `readiness-tasks/{task}/complete` and `.override` on
every task, including ones `ReadinessService.php:83` had made them the owner
of. Architect's ruling (ADR 0017 Amendment 1): the arm is a **reviewed map**,
not a fact inherited transitively — widening the walk to consult every
intermediate's arm was rejected (three reasons in the ADR: it makes one
model's arm silently grant visibility on every model downstream of it, forever;
it answers a security question by walking N models instead of reading one,
defeating the whole enumerate-and-pin style of this ADR; and its failure mode
is silent and permissive, not loud). `ReadinessTask::orgVisibilityNamedUsers()`
now declares `['owner_id', 'occurrence.facilitator_id']` directly —
`AppliesNamedUserVisibility`'s `relation.column` form already supported this,
so the trait itself needed no change. The map is now
`BcmsRecordVisibilityTest::NAMED_USER_VISIBILITY`, asserted whole exactly like
the organisation-level map, with the `no_model_but_exercise_occurrence_...`
test retired (it pinned a fact that was only true because `ReadinessTask` had
not been written yet) and replaced by three assertions per §6 below.

**Defect 2: the null arm applied to anchors but not to derived models, and the
derived branch gained a predicate the trait was never supposed to add.**
`constrainToVisibleRecord()`'s anchor branch delegated to `scopeVisibleTo()`,
which already returns the query untouched when `RcsaScope::unitIdsFor($user)`
is `null` (system context, or `rcsa_scope.all_units`). The derived branch had
no equivalent check: it wrapped the query in `whereHas($path, ...)`
unconditionally, and `whereHas` **requires the related row to exist** —
regardless of what the visibility predicate inside the closure says. A
soft-deleted anchor (`OccurrenceGenerator.php:147` does this on a reschedule)
would silently vanish every one of its derived rows from a queue worker or a
`rcsa_scope.all_units` holder, contradicting the ADR's own Consequences
("unauthenticated and system contexts are unaffected") and repeating the exact
defect `app/Support/Authorization/GraphScope.php:66-71`'s docblock names and
guards against with `isSubtreeLimited()`. Fixed by the same shape: an early
return from `constrainToVisibleRecord()` when
`app(RcsaScope::class)->unitIdsFor($user) === null`, before the `whereHas`
wrapper is ever built.

**Defect 3: the anchor-path terminal fails OPEN, not loud, when it forgets the
interface.** `constrainAnchorPath()`'s leaf checked
`if ($anchor instanceof ScopedToOrgHierarchyContract)` and did nothing in the
`else` — meaning a model using `ScopedToOrgHierarchy` but not `implements
ScopedToOrgHierarchyContract` (`Contact` and `Site` were exactly that shape)
would, the day some future derived model's `orgAnchorPath()` terminated on it,
apply **no predicate at all**: a silent unscoped bind, not a thrown error.
Fixed by throwing in the `else`, matching the top-level classification
throw's own loud-not-open philosophy. `Contact` and `Site` now `implements
ScopedToOrgHierarchyContract` (they already `use ScopedToOrgHierarchy`; this
was the missing half). The guard test that only checked this for models
*also* using `BindsToVisibleRecord` themselves (§6 old point 2) would not have
caught `Contact`/`Site`, since neither is currently a `BindsToVisibleRecord`
user — replaced by `every_scoped_to_org_hierarchy_user_implements_the_contract`,
which runs over every `ScopedToOrgHierarchy` user regardless of whether
today's derived models happen to reach it.

**Defect 4: `Finding` was the one anchor no list scoped, and the fix would
have been hollow against today's data.** ADR 0017 Amendment 2: `FindingController
::index()`'s `Finding::query()`, its `summary()` counts and its `processes`/
`plans` picker lists (used by `store()`, the raise-a-finding form) ran
unscoped — the one gap in the "every list already scoped" premise the original
ADR stated, found by gate 2 itself. All four now carry `->visibleTo($user)` (or,
for the derived `CorrectiveAction` counts in `summary()`,
`whereHas('finding', fn ($q) => Finding::visibleQuery($q, $user))` — the
static, closure-safe form, matching `BiaController::index()`'s own precedent
rather than the magic `$q->visibleTo()` call a bare `Builder` in a closure
cannot resolve). The `users` picker is unchanged — a tenant-wide colleague
roster, not continuity data, per the ADR's own "out of scope" note.

Scoping the query alone would have changed almost nothing on real data:
`grep -rn affected_business_unit_id app` showed exactly one writer,
`CallTreeRemediation.php:193`, and ADR 0006's null arm makes every other
finding — raised from an AAR, a plan review, an incident, a DR test, a
management review, gap analysis or audit — visible tenant-wide regardless of
scoping, because the column was always null. `FindingService::raise()` now
derives `affected_business_unit_id` at raise time when the caller did not
already supply one: a named `affected_process_id`/`affected_plan_id` (however
it arrived — picked by hand, or via `plan_review`'s own `sourceLink`) takes
that record's unit; an `aar` finding takes its AAR's `occurrence.definition`
unit; a `call_tree_test` finding takes its tree's unit. Every other source
(`incident`, `dr_test`, `management_review`, `audit`, `gap_analysis` with
nothing named) is left null — a genuine organisation-level fact under ADR
0006, not an oversight. **No backfill**: existing rows are untouched, per the
ruling.

## 6. Tests

`tests/Feature/Bcms/BcmsRecordVisibilityTest.php`, in the shape of
`RouteAuthorizationTest` and `BcmsRouteKeyTest` (ADR §6), now 32 tests:

1. every `bcms.*` route parameter binding an `App\Models\Bcms` model uses
   `BindsToVisibleRecord` or is in the pinned map — enumerates **routes**;
2. the twelve nested routes actually call `->scopeBindings()`;
3. every model using `BindsToVisibleRecord` is classifiable — enumerates
   **models**, via `glob(app_path('Models/Bcms/*.php'))`, independent of
   whether a route currently binds it;
4. **(new)** every model using `ScopedToOrgHierarchy` — not only
   `BindsToVisibleRecord` users — `implements ScopedToOrgHierarchyContract`
   (defect 3);
5. the pinned organisation-level map is asserted whole, fourteen entries at
   the time of this pass (this phase's ten, plus Phase 2C's three and Phase
   10's two, landed concurrently — plus Phase 11's `TrainingRecord`, added by
   that phase's own owner once this guard flagged it unclassified, exactly as
   §6's enumeration is designed to happen);
6. **(new)** the named-user visibility map (defect 1 / Amendment 1) asserted
   whole — model, specs, reason — plus every spec's column resolution checked
   (`Schema::hasColumn`, and for a `relation.column` pair, the relation exists
   and the related table has the column) and a behavioural test per entry on
   the phase's real write verb: a cross-unit `ReadinessTask` owner completes
   their own task and a bystander gets 404; a cross-unit facilitator overrides
   a blocking task and a bystander gets 404; a cross-unit `ExerciseOccurrence`
   participant confirms attendance and a bystander gets 404;
7. per anchor family (`Plan`, `CallTree`, `Process`, `Finding`,
   `ExerciseDefinition`): a Kano-assigned user opening a Lagos-assigned record
   by uuid gets 404 on a write verb as well as GET where one exists, and
   changes nothing; a Lagos-assigned user succeeds;
8. the three sanctioned cross-unit paths, each demonstrated once (an
   org-level null-unit record; a divisional head assigned to the parent unit
   with `includes_descendants`; a holder of `rcsa_scope.all_units`);
9. the nested-binding defect itself: a section belonging to a *different*
   plan cannot be addressed through a plan the user can see, even though both
   plans are in the user's own unit — and the matching cross-unit case;
10. **(new, defect 2)** a null-user (system/queue) derived binding and an
    `rcsa_scope.all_units` holder's derived binding are each unaffected by a
    soft-deleted anchor;
11. **(new, defect 4)** the findings index shows a Kano user no Lagos finding;
    its `processes`/`plans` pickers offer only the Kano user's own units'
    records; its summary counts only the Kano user's own unit; a finding
    raised against a named process, a named plan, from an AAR, and from a
    call-tree test each lands with the unit its source carries; a finding
    raised from a unit-bearing-nothing source (`audit`, naming neither
    process nor plan) leaves the unit null.

Ran scoped, on `DB_DATABASE=risk_test_test_3`, by file path (not `--filter`,
for reasons below): 32 passed, 225 assertions.

Existing BCMS suites re-run scoped and green on the same database:
`Phase1GovernanceTest`, `Phase1ScreensTest` (60 passed), `Phase2ScreensTest`,
`Phase3ScreensTest`, `Phase5CountdownTest`, `Phase5ScreensTest` (64 passed
together), `Phase4CalendarEngineTest`, `Phase4ScreensTest` (50 passed),
`Phase6CallTreeTest`, `Phase6ScreensTest` (48 passed), `Phase7ScreensTest`,
`Phase7EmnsTest` (67 passed) — 0 regressions from this pass's changes.
`RouteAuthorizationTest`, `PermissionCatalogCoversRoutesTest`,
`BcmsRouteKeyTest` — green. `ModuleActionUrlRouteKeyTest` has one failure
(`resources/js/Pages/Bcms/Dr/Tests/Show.jsx:102`) that is Phase 10 frontend
work landing concurrently, not this phase's backend diff — see §7.

The dev-tenant demo data (business unit 11, site 1) was not touched and no
reseed is required: every fixture this phase's own tests use is created
in-test, matching the pattern `Phase2ScreensTest`/`Phase3ScreensTest` already
established.

## 7. Findings recorded, not fixed, because they are outside this phase's lane

Items surfaced during this pass that are not this phase's to fix —
named here rather than silently worked around, per the same principle ADR
0017 §6 states for a new unclassified model: a reviewer should be able to read
one diff with a sentence attached, not infer that a gap was noticed and
quietly left.

**`resources/js/Pages/Bcms/Dr/Tests/Show.jsx:102` feeds a numeric `.id` to a
prop named `testId`**, caught by `ModuleActionUrlRouteKeyTest`. This is Phase
10's DR module frontend, landing concurrently; nothing in this phase touches
JSX, and the fix (ship a URL, not an id, per the test's own guidance) belongs
to whoever owns that screen.

**`bcms.training-records.assess` bound `App\Models\Bcms\TrainingRecord`
unclassified** when this pass's guard test first ran against the tree —
Phase 11 work landing concurrently. Left unresolved deliberately rather than
guessed at: `TrainingRecord` has no unit column and its two relations are a
system-wide curriculum and a platform `User` (which does not use
`ScopedToOrgHierarchy`), so no defensible one-sentence organisation-level
claim was available without asking Phase 11 what it intends. Phase 11's own
owner has since added `TrainingRecord` to the pinned map directly (citing ADR
0021), which is the map doing exactly the job §6 describes — a diff with a
reason, in the open, in the same shared test file.

**`tests/Feature/Bcms/Phase2cIdentitySyncTest.php` intermittently fatals
PHPUnit's discovery pass.** Phase 2C, concurrent, in-flight: a `swap()`
override narrower than its parent's visibility is a PHP fatal at class-load
time, and it takes down every `--filter` run in the suite, not only that
file's own. Every command in §6 above was run by **file path**
(`php artisan test tests/Feature/Bcms/X.php`) rather than `--filter` for this
reason — it only autoloads the named file's own dependencies. By the final
run this was no longer reproducing (the concurrent work had moved past it),
but the path-based invocation is recorded as the correct way to run a scoped
BCMS test regardless, immune to a declaration error in a sibling file that
`--filter` is not.

**`Phase1GovernanceTest::carried_to_occurrence_id_is_exposed_and_is_not_populated_by_this_phase`
was failing earlier in this pass, from Phase 9/10 work
(`AarExportService.php`, `CarriedActionService.php`) landing concurrently in
the same tree; it passed again by the final run** once that work moved past
the point that tripped it. Nothing in this phase's diff ever touched that
column or either file — recorded because it was reported as a defect against
this phase in an earlier round and is worth confirming resolved rather than
silently dropped.

**`Aar`, `Evidence` and `ExerciseInject` (Phase 9) declare `orgAnchorPath():
'occurrence.definition'` and no named-user arm** — the same shape as the
`ReadinessTask` defect gate 2 rejected in round 1, on three more models: a
cross-unit facilitator reaches the occurrence through its arm and then 404s on
the AAR, inject-release and evidence routes. ADR 0017 Amendment 1 assigns these
to Phase 9; the fix is a row each in `NAMED_USER_VISIBILITY` plus
`orgVisibilityNamedUsers()` on each model, with a write-verb test per entry.

**ADR 0017 §7 at two of nine sites.** `VisibleToUser` is applied only in
`FindingController::store()`. Four pre-existing Form Requests still turn a body
id into a BCMS record with tenant scoping only (`StoreBcmsStrategyRequest`,
`StoreBcmsScopeItemRequest`, `StoreBcmsProcessRequest`,
`StoreExerciseDefinitionRequest`), and `FindingController`'s corrective-action
`owner_id` is a bare `exists:users,id`. The §2 Correction's claim that these are
"already tenant-bound `Rule::exists`" is the reasoning §7 exists to reject; they
are a tracked follow-up, not closed.
