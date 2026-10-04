# ADR 0017 — BCMS record visibility is resolved at route binding, not in a policy

**Status:** Accepted · **Date:** 2026-09-12 · **Phase:** BCMS Phase 7.5 (remediation, cross-phase)
**Author:** architect · **Requested by:** two retrospective reviews (Phase 2 BIA, Phase 3 Plans), independently
**Consumers:** all BCMS tracks · qa-engineer · code-reviewer · Phases 9–12 · ERM/RCSA (reads `RcsaScope`, unchanged)

**Amendments:** **1 — 2026-09-14**, §4 point 5 (the named-user arm is a reviewed
map; the anchor-path walk is deliberately not transitive) · **2 — 2026-09-14**,
§1 (the "every list is scoped" premise was never true of findings; scoping them
is in Phase 7.5) · **3 — 2026-09-14**, Consequences (a sentence that was
mechanically false, corrected). All three raised by `code-reviewer` at the Phase
7.5 gate 2, which rejected the phase. The ADR was written before the trait
existed; these are what building it found.

## Context

Two reviewers, on two phases, found the same hole from opposite ends, which is
the strongest evidence there is that it is structural rather than a slip.

**What is actually true today.** ADR 0006 settled org-hierarchy scoping and
Phase 0 delivered `App\Models\Bcms\Concerns\ScopedToOrgHierarchy`. Eight models
use it. Every BCMS **list** goes through it — `BiaController::index`,
`PlanController::index`, `ProcessController::index`, `BiaReportController`,
`PlanLibraryPresenter`, `StrategyRegisterPresenter`, `CallTreeService`,
`CalendarService`. Every BCMS **record** route resolves its model by uuid under
`OrganizationScope` alone and then checks a bare permission string. There are
about 120 such routes across Phases 1–7 and not one of them asks whether this
user may see this row.

> **Amendment 2 — 2026-09-14. "Every BCMS list goes through it" was false, and
> the exception is findings.** The eight call sites named above are the eight
> that were checked; `FindingController` was not among them and does not scope.
> Its `index` runs a bare `Finding::query()`, its `summary()` counts the same
> unscoped set, and two of its picker option lists — `Process::query()` and
> `Plan::query()`, both models that *do* use `ScopedToOrgHierarchy` — are built
> without `visibleTo()`. So a Kano holder of `bcms.finding.view` reads every
> Lagos finding, with its description and its clause ref, from the list screen
> and never needs a uuid. Stating the premise as universal is what let one
> controller sit outside it unnoticed; the premise is now "eight lists were
> checked and scoped, one was missed", which is a claim a reviewer can test.
>
> **Ruling: scoping findings is in Phase 7.5, not deferred.** Three reasons. It
> is the same defect this ADR exists for, one layer up — the record fix would
> otherwise ship beside a list that shows the record's contents anyway, which is
> the remediation certifying itself. It is four call sites in one controller
> against ~24 model declarations already in this pass. And Phase 9 opens against
> the finding register: every producer phase writes to it, so a finding list
> retrofitted later is this cost paid twice, which is the argument §"Severity"
> already makes for doing the whole thing before Phase 9.
>
> **And the fix is not finished when the query is scoped.** `Finding` now
> overrides `orgScopeColumn()` to `affected_business_unit_id` correctly — but
> `grep -rn "affected_business_unit_id" app` shows exactly **one** writer,
> `CallTreeRemediation.php:193`. Findings raised from an AAR, a plan review, a
> BIA, an incident, a DR test or a management review all leave it null, and a
> null unit is visible to the whole tenant by ADR 0006's null arm — deliberately,
> for the group BCP, and accidentally here. So scoping the list changes almost
> nothing on today's data while *looking* like it changed everything, which is
> the worst kind of fix. Phase 7.5 therefore also derives the column at raise
> time in `FindingService`, from whichever source the finding carries
> (`affected_process_id` → process unit; `affected_plan_id` → plan unit;
> `aar_id` → occurrence → definition unit; `call_tree_test_id` → tree unit), and
> leaves it null only when the source genuinely has no unit. A test asserts a
> finding raised from each source lands with the unit its source carries. Owner:
> `backend-engineer`, in the 7.5 work order, before the phase returns to
> `qa-engineer`.
>
> **Out of scope, stated so it is not read as an oversight:** the `users` picker
> on the same screen is a tenant-scoped list of colleagues, not another
> division's continuity data, and it stays as it is.

So a Kano branch manager holding `bcms.bia.complete` can `GET` Lagos's
assessment, change its RTO and submit it. A user assigned to Retail holding
`bcms.plan.view` can open Treasury's departmental plan by uuid and read its
recovery objectives and its crisis team's mobile numbers; with
`bcms.plan.manage` edit it, with `bcms.plan.approve` approve it, with
`bcms.contact.export` download the contact bundle. The list screen they came
from never showed them the record. The URL did.

**The trait's own docblock names the missing piece.** `isVisibleTo()` at
`ScopedToOrgHierarchy.php:121` says it exists "for a policy". There is no
`app/Policies/Bcms` directory, so `isVisibleTo()` has never been called by
anything. A method with no caller is not a safety net; it is a note saying
somebody meant to build one.

**It is worse than uncalled — one arm of it is wrong.** `App\Models\Bcms\Finding`
uses the trait and does **not** override `orgScopeColumn()`, but
`bcms_findings` has no `business_unit_id`; the column is
`affected_business_unit_id`. `Finding::visibleTo()` would raise an unknown-column
error on its first call. It has never been called, so nobody has found out. That
is the exact cost of a contract nothing exercises.

**The existing tests cannot catch any of this, and the reviewers said so.**
`Phase2ScreensTest.php:396` builds a Kano branch manager and asserts the *index*
shows only their own assessment; it stops at the list.
`Phase3ScreensTest.php:461` tests a user with **no** permissions, which proves
the `permission:` middleware is wired and says nothing whatever about a user who
holds the grant and should not see the row. Both tests pass. Both would still
pass with every exploit above working.

**Nothing is exposed today.** `config/features.php` defaults `bcms` to false and
`feature:bcms` aborts 404, so all 168 routes do not exist on any install. The
hole is entirely prospective — which is why this is a remediation with a
deadline and not an incident.

## Decision

### 1. Visibility is enforced at route-model binding. BCMS gets no policy layer for it.

A new concern, `App\Models\Bcms\Concerns\BindsToVisibleRecord`, overrides
`resolveRouteBinding()` and `resolveChildRouteBinding()` to apply the
org-hierarchy filter before the row is fetched. An out-of-scope uuid resolves to
nothing and Laravel raises `ModelNotFoundException` → **404**, not 403.

The two questions stay apart and each keeps the mechanism that already answers
it well:

| Question | Mechanism | Where |
|---|---|---|
| May this **kind of user** do this **kind of thing**? | `permission:` middleware, one per route | `routes/web.php`, asserted by `RouteAuthorizationTest` (standard §2) |
| Is this **record** one of theirs? | the visibility filter at binding | the model, asserted by the new guard test |
| Is the record in a **state** that allows this? | controller, answers with a flash | standard §3 |

**Why binding and not a policy.** The failure mode to design against is a
developer adding a record route and forgetting. A policy has to be *called*, and
the call site is the thing that was forgotten 120 times already. Standard §3's
own two worked examples are both policies that existed and were silently never
reached — `MeasureBreachPolicy` that did not exist under the name the ability
looked for, and six Form Requests asking `can('update', $dashboard)` against no
`DashboardPolicy`. Laravel's answer to a missing or misnamed policy is to fail
open or closed *silently*, in both directions. Binding-level enforcement deletes
the call site: the check runs because the framework resolved the model, and the
only way to skip it is to not type-hint the model, which the guard test in §6
below catches.

The cost is real and is accepted: a reviewer reading `PlanController::update`
sees no authorisation call and has to know the rule lives on the model. The
mitigations are the trait name on the model, the guard test, and this ADR. The
alternative cost — 120 `Gate::authorize('view', $record)` lines that must each be
written correctly and never deleted, across six shipped phases and four unbuilt
ones — is a cost that is paid again on every new route for ever.

**This is a deliberate deviation from development standard §3** ("authorisation
lives in a Policy named for its MODEL"), scoped to BCMS record visibility and to
nothing else. §3 continues to govern: permission order, Form Request
`authorize()`, lifecycle-as-flash. If BCMS later needs a genuine per-record
*domain* rule that is not visibility, that is a policy named for its model, under
§3, and it calls `isVisibleTo()` rather than re-deriving the rule.

### 2. The rule: one anchor, one unit, one answer

> **Every BCMS record is visible through exactly one business unit — its own if
> it carries one, otherwise its anchor's. A record whose unit is null is
> organisation-level and visible to everyone in the tenant.**

Three categories, and every routed BCMS model is in exactly one:

| Category | How it declares itself | Filter applied at binding |
|---|---|---|
| **Anchor** — carries the unit column | `use ScopedToOrgHierarchy` | `scopeVisibleTo()` — its own column, with ADR 0006's null arm |
| **Derived** — reaches a unit only through a parent | `orgAnchorPath(): string`, a dot path to an anchor | `whereHas(path, visibleQuery)` |
| **Organisation-level** — has no unit and should not | listed in the guard test's pinned map, with a reason | none |

Rules for classifying, so Phase 9 onwards does not re-derive this:

1. **A model is an anchor only if its unit column decides who owns the record.**
   `bcms_exercise_participants.business_unit_id` is denormalised for audience
   resolution — it records where the *person* sits, not who owns the exercise —
   so `ExerciseParticipant` is **derived** from `occurrence.definition`.
   Promoting it would let a Retail participant's row make a Treasury exercise
   look like Retail's.
2. **Derived models take the shortest path to an anchor**, and it is a single
   path. Every BCMS child has one: `BiaAssessment → process`,
   `Dependency → assessment.process`, `PlanSection → plan`,
   `CallTreeTestNode → test.callTree`, `ReadinessTask → occurrence.definition`.
   A model needing two anchor paths is a modelling question for the architect,
   not an `orWhere`.
3. **Organisation-level is a claim that must be defensible in one sentence**,
   and the sentence goes in the pinned map. "One per tenant" (`Programme`),
   "spans units by construction, and the assessments inside it are each scoped"
   (`BiaCampaign`), "targeted by audience rule, `bcms_alerts` has no unit column
   by design" (`Alert`) are defensible. "It didn't seem to need one" is not.
4. **A record with a null unit is visible to the whole tenant.** That is ADR
   0006's null arm and it is load-bearing: the group BCP, the enterprise crisis
   plan and the corporate exercise definition are supposed to be readable by
   every branch that has to follow them. Do not "fix" it.

### 3. All verbs, including GET

Visibility is not verb-dependent. An out-of-scope record is 404 for read, write,
approve, export and delete alike.

A read-wider-than-write split was considered and rejected. The exposure the
reviewers found is overwhelmingly a **read** exposure — another division's
recovery objectives, BIA figures, and named crisis staff with their mobile
numbers — so a split would leave the worst of it in place while adding a matrix
of verbs to get wrong. And 404-not-403 is the right answer for the same reason
standard §4 forbids a bare `exists:`: 403 confirms the record exists, which is an
existence oracle over another division's estate, one uuid at a time.

### 4. Three ways to see across a unit boundary, and no fourth

When a legitimate reader sits outside the owning unit, the answer is one of the
mechanisms this product already has:

1. the record is **organisation-level** (`business_unit_id` null) — the group BCP;
2. the user is **assigned** to the unit in `business_unit_user`, with
   `includes_descendants` for a divisional head;
3. the user holds **`rcsa_scope.all_units`** — CRO, Head of ORM, Internal Audit.

Plus one narrow arm, defined here because the alternative is six phases each
inventing it:

**5. A user named on the row itself sees the row.** A model may declare
`orgVisibilityNamedUsers(): array` listing local user columns
(`owner_id`, `assessor_id`, `facilitator_id`) or `relation.column` pairs
(`participants.user_id`). Those are OR'd into `scopeVisibleTo()`, so the list
screen and the record answer identically.

This arm is not optional politeness: without it, Phase 5's core loop breaks. A
Retail user invited to a Treasury exercise receives a T-10 reminder linking to
`occurrences/{occurrence}/confirm-attendance`, and would get a 404 from an
invitation the product itself sent.

The arm is **strictly limited to a user id stored on a row that exists before
the request**. It explicitly does **not** cover audience-rule populations: a
plan's `distribution_rule` (ADR 0003) is resolved by `AudienceResolver`, and
resolving an audience rule inside route binding would put a multi-table
resolution on the hot path of every request and make visibility depend on a rule
somebody can edit. Cross-unit plan *readership* is therefore granted by 1, 2 or
3 above. If Phase 11's training-and-distribution work finds that insufficient,
that is an amendment to this ADR, not a local override.

> **Amendment 1 — 2026-09-14. The arm is a reviewed map across models, and the
> anchor-path walk is deliberately not transitive.**
>
> **The defect gate 2 rejected the phase on.** `ExerciseOccurrence` declares the
> arm (`facilitator_id`, `participants.user_id`) and is itself derived on
> `definition`. `ReadinessTask` is derived on `occurrence.definition`, and
> `BindsToVisibleRecord::constrainAnchorPath()` walks that path as a bare
> `whereHas` chain terminating in the **anchor's** `scopeVisibleTo()` — nothing
> consults the intermediate occurrence's arm. Meanwhile `ReadinessService`
> (`app/Services/Bcms/Reminders/ReadinessService.php:83`) sets every task's
> `owner_id` to `occurrence.facilitator_id`. So a cross-unit facilitator — a
> configuration `ExerciseOccurrence`'s own docblock endorses — opens the
> readiness screen through the occurrence's arm and then gets 404 from
> `bcms.readiness-tasks.complete` and `.override` on every task, **including the
> ones they own**. The screen loads and every button on it fails, which is worse
> than a 404 on the screen: it reads as a broken product rather than a
> permission.
>
> **Decision: the map.** `orgVisibilityNamedUsers()` stays a per-model
> declaration, its specs already support a `relation.column` pair, and a derived
> model reaches a named user on an intermediate **explicitly**. The declarations
> are pinned as one map, asserted whole, with a reason per row:
>
> | Model | Specs | Reason |
> |---|---|---|
> | `ExerciseOccurrence` | `facilitator_id`, `participants.user_id` | Phase 5's core loop, above: the T-10 invitation the product itself sent must not 404. The facilitator runs an occurrence in a unit they may not be assigned to. |
> | `ReadinessTask` | `owner_id`, `occurrence.facilitator_id` | `owner_id`: `ReadinessService:83` makes the facilitator the default owner, and a reassignee must be able to act on their own task. `occurrence.facilitator_id`: the facilitator holds `bcms.readiness.override` and answers for the gate — the person who decides whether the exercise may start must be able to clear what blocks it, including tasks owned by somebody else. |
>
> **Why not the transitive walk.** Three reasons, and the first is the one that
> decides it:
>
> 1. **It makes one model's arm silently grant record visibility on every model
>    downstream of it, and that set grows every phase.** `participants.user_id`
>    is already on `ExerciseOccurrence`; transitively it would hand every
>    participant of a Treasury drill the readiness checklist, and then — as they
>    are built — the evidence rows of ADR 0019, the observer scores, the injects
>    and the timeline. Nobody would have decided that. The path would have
>    decided it, at the moment an unrelated model declared an anchor path.
> 2. **It answers "who can see this row" by walking N models instead of reading
>    one.** This ADR's entire enforcement style is enumeration — the pinned
>    organisation-level map in §2, modelled on `RouteAuthorizationTest`'s pinned
>    allowlist — because the thing that catches a mistake is a reviewer reading a
>    diff with a sentence attached to it. A rule computed across a relation chain
>    cannot be diffed.
> 3. **Its failure mode is silent and permissive.** An intermediate's arm has to
>    be OR'd with the *continuation of the walk*, not with the whole predicate. A
>    closure nested one level out turns that OR into an AND, or the reverse, and
>    nothing fails loudly either way.
>
> **"One anchor, one unit, one answer" is preserved literally.** The anchor path
> is still exactly one — `occurrence.definition` — and the named-user arms are
> still a union on top of it, which is what point 5 always was. What changes is
> only that the union is *declared* per model instead of assumed to propagate.
>
> **The duplication is the accepted cost.** `occurrence.facilitator_id` on
> `ReadinessTask` restates a fact `ExerciseOccurrence` also declares. If the
> facilitator ever moves to a pivot table, two declarations change — and the
> pinned map is exactly what makes the second one visible to whoever changes the
> first.
>
> **The guard test changes shape.**
> `no_model_but_exercise_occurrence_declares_a_named_user_visibility_arm` is
> **retired**: it pinned a fact that was only true because the second model had
> not been written yet, which is a test asserting the state of the work rather
> than a rule. It is replaced by three assertions:
>
> 1. **the map asserted whole** — model → specs → reason — in the shape of §6
>    point 3's organisation-level map;
> 2. **a behavioural test per entry**: the named user gets 200 on a record whose
>    unit they are not assigned to, on a GET **and on that phase's real write
>    verb** — for `ReadinessTask` that is `complete` and `override`, the two the
>    defect broke, not a GET that would have passed throughout;
> 3. **every spec resolves**: a local column exists on the table
>    (`Schema::hasColumn`), and a `relation.column` pair names a real relation
>    whose related table has that column. This is §6 point 2's `Finding`
>    assertion applied to the other half of the trait — a spec naming a column
>    that does not exist throws on first use, and only for the user it was
>    written to help.
>
> **Where else the same hole is, to be closed in this pass rather than found by
> the next gate.** Any model whose `orgAnchorPath()` has two or more segments and
> whose intermediate carries an arm. Today that is `ReadinessTask`; under ADR
> 0019 it will be `bcms_evidence` (`occurrence.definition`), and any Phase 9
> score, inject or timeline route the same. The rule for the implementer, and for
> every phase after: **if a derived model's route is reachable from a screen a
> named user of an intermediate can open, that model needs its own row in the
> map.** A model that does not need one still gets read at review, because the
> map is asserted whole.

### 5. Nested routes bind their children through the parent

No BCMS route group calls `->scopeBindings()`. `plans/{plan}/sections/{section}`
resolves the section globally by id, so a section belonging to another
division's plan can be addressed through a plan you can see. The nested groups
get `->scopeBindings()`; `resolveChildRouteBinding()` then constrains the child
to the parent relation *and* applies the child's own filter.

### 6. The guard test is the enforcement, and it enumerates models, not routes

`tests/Feature/Bcms/BcmsRecordVisibilityTest.php`, in the shape of
`RouteAuthorizationTest` and `BcmsRouteKeyTest`:

1. **Every `bcms.*` route parameter that type-hints a model in
   `App\Models\Bcms` must resolve through the filter** — the model uses
   `BindsToVisibleRecord`, or it is in the pinned organisation-level map.
2. **Every model using `BindsToVisibleRecord` is classifiable**: it either uses
   `ScopedToOrgHierarchy` with an `orgScopeColumn()` that `Schema::hasColumn()`
   confirms exists — which is the assertion that would have caught `Finding` —
   or declares an `orgAnchorPath()` whose relation chain ends on a model that
   uses `ScopedToOrgHierarchy`.
3. **The organisation-level map is pinned and asserted whole**, with a reason
   per entry, exactly as `RouteAuthorizationTest::the_allowlist_only_covers_
   authentication_and_health_routes()` pins its allowlist. Adding a model to it
   is a diff in a test file with a sentence attached, which a reviewer reads.
4. **Behaviour, per anchor family rather than per model**: a user assigned to
   unit A receives 404 on a record anchored to unit B, on a GET and on a write
   verb; the same user receives 200 on an organisation-level record; a user
   with `rcsa_scope.all_units` receives 200 on both.

The enumeration deliberately keys on the **model**, not the route. A new record
route for an already-classified model is safe by construction, and a new model
fails the guard the moment its first route exists — before the screen is
written, not after the pen test.

### 7. Ids arriving in a request body get the same predicate

Binding covers ids in the URL. Seven Form Requests and two controller rules turn
an id in the *body* into a BCMS record. A new validation rule,
`App\Rules\Bcms\VisibleToUser`, applies the same filter — the unit-scoped
analogue of standard §4's tenant-bound `Rule::exists(...)->where('organization_id', ...)`,
for the same reason §4 gives.

Two of those nine are worse than out of scope. `FindingController` lines 109–110
use **bare** `exists:bcms_processes,id` and `exists:bcms_plans,id`, which is a
§4 violation and a **cross-tenant** existence oracle, not merely a cross-unit
one. They are fixed in this pass.

## What this ADR deliberately does not do

- **It does not create `app/Policies/Bcms`.** Not one class. If one appears, the
  reviewer's question is which of §1's three rows it belongs to.
- **It does not add a global scope.** A `VisibleToUser` global scope alongside
  `OrganizationScope` was the tempting version of the same idea and is rejected:
  the scheduler, the queue workers, the watchdogs, the generation engine and
  every aggregate report legitimately read the whole estate, so half the module
  would be written with the scope disabled — and a scope people disable by
  reflex protects nothing. Tenancy can be a global scope because nothing ever
  legitimately crosses it; unit visibility is not that.
- **It does not add middleware or a base-controller concern.** Both re-introduce
  a call site that can be omitted, which is the defect.
- **It does not change a single column, table or index.** The schema freeze
  stands at zero structural changes for Phase 7 and this remediation adds none.
  The whole fix is a trait, ~24 one-line model declarations, `->scopeBindings()`,
  a validation rule and tests.
- **It does not re-open ADR 0006.** `RcsaScope` remains the one engine;
  `ScopedToOrgHierarchy` still resolves nothing itself. The named-user arm is
  added to the trait, not to `RcsaScope`, because being named on a BCMS row is a
  BCMS fact and RCSA must not inherit it.
- **It does not scope `Alert`.** `bcms_alerts` has no unit column by design —
  an alert is targeted by audience rule — so alerts stay organisation-level
  behind the narrow `bcms.alert.*` permissions. The roll-call screen does show
  contacts across units to an EMNS operator. That is recorded as an open
  question for Phase 12 hardening rather than answered here by inventing a
  column; see "Consequences".
- **It does not fix the 54 inline `$request->validate([...])` calls in BCMS
  controllers.** They breach standard §4 and they are a product-wide drift
  (TPRM has them too), not this finding. A separate piece of work, separately
  scoped. Two of them are in scope only because they are the same
  existence-oracle defect this ADR is about.

## Consequences

- **`ScopedToOrgHierarchy` is a Phase 0 frozen contract and this changes it**
  (the named-user arm, and `visibleTo()` now filters more rows for some users).
  That is why this is an ADR and a broadcast rather than a quiet edit. Consumers
  to re-read their list queries: `CalendarService`, `CallTreeService`,
  `SourceResolver`, `GapAnalysisService`, `PlanLibraryPresenter`,
  `StrategyRegisterPresenter`, `ResilienceCalendarPresenter`, `BiaReportController`.
  None of them needs a code change.

  > **Amendment 3 — 2026-09-14.** This bullet used to end: *"all of them will
  > return more rows for a named participant, which is the intended fix to a
  > second, quieter bug — a cross-unit participant could not see their own
  > exercise on their own calendar."* That is mechanically false and is
  > withdrawn. **No list calls `ExerciseOccurrence::scopeVisibleTo()`** — the
  > occurrence is a *derived* model and does not use `ScopedToOrgHierarchy` at
  > all, so there is no scope on it for a named-user arm to widen. And the bug it
  > claimed to fix did not exist: `CalendarService::forUser()`
  > (`app/Services/Bcms/Exercises/CalendarService.php:307–310`) already ORs
  > `facilitator_id`, `participants.user_id` and the definition's
  > `owner_id`/`facilitator_id`, so the cross-unit participant could always see
  > their own exercise on their own calendar. What is true is narrower and worth
  > keeping: **the named-user arm makes the record answer what the list already
  > answered.** Before it, `CalendarService` showed a cross-unit participant
  > their exercise and the record route 404'd the link on it — a list and a
  > record disagreeing, which is the specific inconsistency point 5 exists to
  > remove. The lesson for the rest of this ADR: a consequence asserted about
  > eight call sites should be checked against all eight before it is written
  > down, and this one was not.
- **Test breakage should be near zero, and where it is not, it is the point.**
  No BCMS factory sets `business_unit_id`, so every fixture row is currently
  organisation-level and stays visible. The tests that will move are the ones
  that deliberately set a unit — `Phase2ScreensTest.php:396`,
  `Phase3ScreensTest.php` — and those are the tests that document the hole. Any
  test that starts failing must be fixed by assigning the actor to a unit, never
  by relaxing the filter.
- **A user assigned to nothing sees organisation-level records only**, which is
  already how every list behaves, and `RcsaScope::describe()` already has the
  sentence for it. Record routes will now 404 for them instead of 200. That
  reads as a configuration problem, because it is one.
- **One extra `EXISTS` subquery per derived-model binding.** One row, an indexed
  foreign key, no measurable cost.
- **Unauthenticated and system contexts are unaffected**: `unitIdsFor(null)`
  returns null, so the signed calendar feed, the cascade-acknowledgement token
  routes and every queue/console path resolve exactly as they do today. Their
  credential is the signature or the HMAC and always was.
- **Open question carried to Phase 12 hardening:** whether an EMNS roll-call
  should show every recipient to any holder of `bcms.alert.view`, or whether
  that screen needs a narrower answer. Recorded, not decided, and not a blocker
  for Phase 9.

## Severity, and when this is fixed

**A live cross-division exposure path that no install can reach today.**
`OrganizationScope` holds throughout, so no customer can see another customer's
data through this; what leaks is one division's continuity plans, BIA figures
and crisis-team contact details to another division of the same bank. The module
is dark on every install, so there has been no disclosure and nothing to notify.

It is nevertheless **not** ordinary debt. The customers this module is built for
run divisional structures and populate `business_unit_user` precisely because
divisions are not meant to see each other's operational detail; `bcms_contacts`
holds personal data (mobile numbers, addresses) to which NDPA's need-to-know
applies; and ISO 22301 clause 7.5.3 requires control over the distribution of
documented information, which is the exact thing a shared uuid defeats.

**So: one remediation, not three patches and not six.** It is model-level, so a
single pass closes Phases 1–7 together; a policy-per-controller fix would have
been six separate pieces of work. It runs as **Phase 7.5**, after Phase 7 clears
its gates and **before Phase 9 opens** — Phase 9 adds roughly forty more record
routes, and every one of them retrofitted later is this cost paid twice. It is
a hard prerequisite for turning the `bcms` flag on anywhere, pilot included.

It is not a same-day hotfix, and nobody should be woken up. Sequence:
`backend-engineer` implements the work order in
`docs/bcms/phase-7.5-visibility-remediation.md`, then `qa-engineer`, then
`code-reviewer`, like every other phase.
