# BCMS Phase 3 — continuity strategy and the plan builder

**Track:** A · **Weeks 6–8** · **Lead:** backend-engineer · **Depends on:** Phase 2's BIA

Built in the `riskerm-wt/bcms` worktree on `feature/bcms-module`.

---

## 1. What landed

| Layer | Files |
|---|---|
| ADR | `0011` — **five columns, no new tables, and one table Phase 3 decided not to add** |
| Schema | `2026_09_12_120001_add_bcms_plan_builder_columns.php`; manifest regenerated in the same commit |
| Enums | `StrategyType` (7 ISO 22331 options), `PlanSectionSource` (8 bindings), `FindingSource::PlanReview` |
| Value objects | `PlanBinding` (validated binding grammar), `PlanTemplates` (12 templates, 127 sections) |
| Domain | `Plans\SourceResolver`, `Plans\PlanAssembler`, `Plans\PlanService`, `Plans\PlanDriftDetector`, `Plans\OfflineBundleBuilder`, `Plans\PlanDocumentRenderer`, `Plans\PlanAcknowledgementService`, `Plans\PlanActivationService`, `Plans\PlanAiDrafter`, `Strategy\StrategyService`, `Strategy\GapAnalysisService` |
| HTTP | `StrategyController`, `PlanController`, `PlanDocumentController`, 3 presenters, 3 form requests, 31 routes |
| Front end | `Strategy/Index`, `Strategy/Gap`, `Strategy/Compare`, `Plans/Index`, `Plans/Show`, `Plans/Stale`, `CostRtoScatter`, `BoundSection` |
| Print | `reports/pdf/bcms-plan.blade.php` + `bcms/bound-section.blade.php`, through the existing `ThirdLine\Reporting\DocumentRenderer` |
| Console | `bcms:check-plan-drift`, daily at 05:30 |
| Seeds | 18 strategies across 11 processes with **3 deliberate RTO gaps**; 16 plans — 1 group BCP (approved), 3 departmental, 1 CMP, 1 IT DRP, 1 pandemic, 8 branch plans; process→department assignment for the whole catalogue |
| Tests | `Phase3PlanBuilderTest` (39), `Phase3ScreensTest` (19), `BcmsRouteKeyTest` (1, module-wide guard) |

## 2. The eight acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | A BCP is generated from BIA data, approved, versioned, and downloads as a PDF *and* an offline bundle | **Pass** |
| 2 | A bound section renders the current RTO; changing it flags the plan and highlights that section | **Pass** — and a source that changes and changes *back* clears the flag |
| 3 | An approved v1 is immutable; editing produces v2 with a supersession link; v1 stays retrievable and printable | **Pass** — see §3, this is the phase's central design decision |
| 4 | Gap analysis lists Tier-1 processes whose achievable RTO exceeds required, with the shortfall in hours | **Pass** — including the processes with *no* strategy, which a naive join drops |
| 5 | The offline bundle generates and validates against the bundle schema | **Pass for what is testable now** — airplane mode on a phone is **[verify at integration]**, W14 |
| 6 | A plan past `next_review_date` appears in the stale list and moves the "plans current" KRI | **Pass** — and the KRI is `null`, never 100%, when there is nothing to measure |
| 7 | Reader acknowledgement recorded per user with a timestamp, exportable as clause 7.4 evidence | **Pass** — on `bcms_plan_attestations` with `attestation_type = 'read'`, see §4 |
| 8 | AI draft assembles a coherent BCP that lands in draft and cannot self-approve | **Structurally pass, never run against a live model** — same standing gap as Phase 2 |

### Criterion 5, honestly

The prompt itself marks end-to-end airplane mode as `[verify at integration]` at
the W14 window, because the PWA service worker is Phase 12. What is built and
tested now is the bundle: it is produced from the same `document()` the PDF uses,
it carries the plan, sections, call tree, contacts, sites and a generated-at
stamp, it records whether it contains personal data, and a validator asserts that
every key the offline viewer reads is present. `the_bundle_validator_catches_a_
bundle_the_viewer_could_not_render()` proves the validator is not a rubber stamp.
The half that is not claimed is that a phone in a lift can open it.

### Criterion 8, honestly

The drafter is wired, gated behind three switches that all default off, and
tested to refuse cleanly rather than throw when they are. It writes **prose only**
— never a recovery time, never a dependency — because the bound sections are
already correct and a model has nothing to add to an RTO except doubt. It has
never been run against a live model. Phase 12 should.

## 3. The decision that reconciles criteria 2 and 3

They contradict each other and this is the most important thing in the phase.

Criterion 2 says a bound section shows the **current** recovery objectives.
Criterion 3 says an approved v1 is **immutable** and stays printable. Both cannot
be true of one rendering, and a product that quietly picked one would either
print a plan that changes under an auditor, or a plan that lies about today.

So they are two renderings of two different things:

- A **draft** renders live. That is what a working document is for.
- On **approval** the render is frozen into `bcms_plans.content` — precisely what
  that column was created for in Phase 0 — and from then on the approved version
  prints exactly what was approved, for ever.
- Drift is **still detected against the approved version**, and what it produces
  is a review flag, a highlighted section and a finding. Never a silent edit.
- The plan screen shows the frozen section and what it *would* say today, side by
  side. That comparison is how somebody decides whether a v2 is needed, and it is
  the only place in the product where the two appear together.

`PlanAssembler::document()` is where this lives, and its docblock is the argument.

## 4. The table Phase 3 did not add

ADR 0009 closed by asking whoever wrote the next ADR whether the freeze was
holding. It is, and the question forced a rejection that is worth recording.

Criterion 7 wants per-reader acknowledgement, and the obvious move was a
`bcms_plan_acknowledgements` table. It was drafted — `plan_id`, `user_id`,
`acknowledged_at`, a snapshotted name and role, an IP address, a unique pair —
and then compared against `bcms_plan_attestations`, which Phase 1 built with
every one of those columns for the same reason. Its docblock talks about boards;
its *schema* says "a dated, signed attestation of a plan", and a read
acknowledgement is exactly that. `attestation_type` exists to tell the kinds
apart and already documented itself as `board|executive|owner`.

**Reader acknowledgement is `attestation_type = 'read'`.** The reuse also buys
the annual cycle: the unique key includes `period_year`, so re-acknowledging in a
new year records a new act rather than overwriting last year's — which a bare
`unique(plan_id, user_id)` would have quietly prevented.

**The cost is a rule anything counting attestations must follow:** filter on the
type. A board-attestation count that silently includes every branch manager who
ticked "I have read this" is a governance number that is wrong, and wrong in the
flattering direction. `an_acknowledgement_never_counts_as_a_board_attestation()`
is the guard.

## 5. The other decisions worth not re-litigating

**Nothing falls back to everything.** A binding whose scope resolves to no
processes renders an empty section carrying the *reason* it is empty. A
departmental plan that silently printed the whole group's recovery objectives
would be worse than a blank page, because somebody would act on it. The empty
reason names the scope that came back empty, not just the fact — "no site is
assigned to this department" sends somebody to the binding, where the fix is;
"no sites match" sends them looking for missing sites.

**Drift is a fingerprint, not a watermark.** Each bound section stores the
SHA-256 of the canonical payload it last resolved to. Detection is a re-resolve
and a string compare. An `updated_at` watermark on eight upstream tables cannot
see a *deleted* dependency, fires on a change that was reverted, and needs new
code in every module a binding touches.

**The gap is stored as a snapshot and recomputed live, and both are shown.**
`Strategy.gap_vs_required_hours` is the gap at the moment the strategy was
assessed, against the assessment it was judged on, because the required RTO moves
with each BIA cycle and a derived gap would silently rewrite last year's approved
strategy paper. The gap-analysis screen asks a different question — "is there a
gap *today*" — and the difference between the two numbers is itself the list of
strategies that need reassessing.

**Templates are code, not a table.** A template is consumed once, at plan
creation; from that moment the plan owns its sections and nothing reads the
template again. A table would be a tenant-editable copy of code that nothing
downstream depends on, plus a seeder to keep it current, plus a migration every
time a standard changes.

**`review_required` is derived.** `EXISTS(sections WHERE needs_review)`. Storing
it creates two facts that can disagree, and the one that would be wrong is the
one the dashboard reads.

**The offline bundle is gated on `bcms.contact.export`, not `bcms.plan.view`.**
It carries mobile numbers off the platform onto a phone — that is its whole
purpose — and a bulk personal-data export under the NDPA is what it is however
the button is labelled. Acknowledging, by contrast, needs no grant beyond seeing
the plan: requiring one would mean the people a plan is distributed to could not
produce the evidence that it was.

**The bulk review-cycle action counts from `effective_from`, never from today.**
The library lets somebody set the cycle on twenty plans at once, and the obvious
implementation — add N months to now — would make it a "clear the past-review
list" button. That is precisely the gaming a staleness KRI invites, and the
reason a lot of GRC tools' currency figures are worthless. The new date is
derived from the date the plan became effective, so shortening a cycle pulls a
plan's date closer and lengthening it cannot outrun the approval it is measured
from. A plan that has never been approved gets the frequency and no date, and the
screen says why.

**A section route carries its plan.** Every `{section}` route also takes
`{plan}` and the controller checks the second owns the first. Tenancy would stop
a cross-tenant edit anyway; this stops the one *inside* an organisation, which is
the scoping bug that survives a tenancy test.

### Where this differs from the prompt's API sketch

The prompt lists a REST surface (`GET /api/v1/bcms/plan-templates`, and so on).
This module is Inertia, not a JSON API (ADR 0007 deviation 1), so every one of
those became a named web route with the same verb and the same meaning — except
`plan-templates`, which is a prop on the plan library rather than an endpoint of
its own. Nothing fetches it separately: the template picker is on the create form
and the list is twelve rows of static content. An endpoint would be a second way
to read a constant.

`GET /plans/{id}/offline-bundle` exists as well as the POST, because the PWA has
to be able to fetch a cached bundle in Phase 12 without regenerating it.

## 6. Defects and gaps this phase found in earlier work

- **`bcms_findings` has no `title` and no `owner_id`.** Writing either is
  silently dropped in a request and **throws in a seeder**. Caught before it
  shipped — the same family as TPRM's `date_identified`.
- **Five of the demo's four divisions had children that did not exist.**
  `DIVISIONS` referenced `BU-RB`, `BU-CB`, `BU-RM`, `BU-CO` and `BU-FN`; the
  organisation actually has `BU-RT`, `BU-IB`, `BU-ERM`, `BU-CIC` and `BU-FC`. So
  those departments were silently left at the top of the tree. Nothing depended
  on the hierarchy until a departmental plan started walking down from its own
  unit; an orphaned department is a plan with an empty recovery-objectives table.
- **Forty of fifty processes had no business unit.** Only the eight linked to the
  organisation's own catalogue inherited one. Fine for a register, useless for a
  departmental plan. `assignProcessUnits()` fixes the demo estate.
- **`NoFabricatedNumbersTest` caught a `?? 12` in the approval form.** A plan
  whose owner never declared a review cycle has not got a twelve-month one, and
  pre-filling the form with a figure nobody chose is how an invented cycle
  becomes a date on a board pack. The guard is worth its maintenance.
- **`RcsaScope` gained `subtreeOf()`.** BCMS is the second module to need the
  business-unit tree walk, and `ScopedToOrgHierarchy`'s own docblock forbids a
  second implementation. It is deliberately a **separate method** from
  `unitIdsFor()`: one is an authorisation boundary and the other is a data scope,
  and a caller that confused them would widen the wrong one.

### Every BCMS detail link in the module was broken, in every phase

Found by clicking one, in a browser, after the tests were green.

`HasBcmsUuid::getRouteKeyName()` returns `uuid` — deliberately, so a customer's
URLs do not enumerate their estate. The cost is that `route('bcms.plans.show', 12)`
builds `/plans/12`, which resolves to nothing. **Nothing fails loudly**: the
server 404s, Inertia swallows it, and the user sees a link that does not work.

It is invisible to a feature test, because a test writes
`route('bcms.plans.show', $plan)` — passing the model, which always uses the
route key and is always right. The screens pass `plan.id`, which never is.

**Twenty-four call sites across Phases 1, 2 and 3** were affected: the BIA
assessment link and its workspace, the findings and corrective-action buttons,
the policy approve/supersede/attest buttons, and everything Phase 3 added. All
are fixed, and six presenters now emit the route key beside the numeric id.

`BcmsRouteKeyTest` is the guard. It reads the route table, works out which route
parameters bind a model whose route key is not its primary key, then greps the
BCMS screens for `tryRoute('bcms.…', x.id)` against one of them. It is the only
kind of test that could have caught this, because the defect lives in the gap
between what a test passes and what a page passes.

### A structured column that is null on the first row crashed the PDF

The printed table derives its columns from the rows. `resource_requirements` on
a strategy is null on one row and an array on the next, and a header built from
row zero alone lets that column through and then fatals on the row that has data
in it — a 500 on a download button rather than a wrong number. Found by printing
the demo estate; not found by printing a two-row fixture. Every row is scanned
now, and `the_pdf_prints_a_section_whose_structured_column_is_null_on_the_first_
row()` fails against the old code.

### The BC policy was being counted as a plan

The policy is a `bcms_plans` row on purpose (ADR 0008), but it has its own
screen, its own clause, its own board attestation and no sections at all. It was
appearing in the plan library as a document with "0 sections" and — worse —
moving a KRI called "plans current". `PlanService::libraryQuery()` excludes it.
Policy currency is a governance number reported on the programme screen; plan
currency is an operational one. Two numbers, not one average.

## 7. Known gaps handed forward

- **Call-tree bindings resolve to nothing until Phase 6.** `call_tree` and
  `contacts.crisis_team` render their empty state with an honest reason. The
  offline bundle's `call_tree` and `contacts` blocks are therefore null in the
  demo estate. Phase 6 fills them and nothing here needs to change.
- **`vendors.critical` reads through `bcms_dependencies`, not TPRM's own
  criticality.** TPRM scores a vendor across the whole relationship; a continuity
  plan needs the vendor *this plan's processes* cannot run without. A vendor can
  be strategically important and continuity-irrelevant, and the reverse. If TPRM
  later exposes a continuity-specific criticality, this is the seam.
- **`bcms_sites` has no assembly point.** The `sites.assembly` binding renders
  addresses, headcount and the designated recovery location, and says in the
  section that the muster point is authored below the table. Adding a column is
  an ADR; printing a guess would send people to the wrong car park.
- **The AI drafter has never met a model.** Three switches, all off.
- **Plan activation has no incident to point at until Phase 10.** The service and
  the read views are built; `incident_id` and `occurrence_id` stay null.
- **A drifted approved plan raises an OBSERVATION, not a nonconformity.** A
  fingerprint mismatch is evidence that something moved; whether that makes the
  plan non-compliant is a judgement, and a new process joining a unit drifts a
  section without making the plan wrong. Nonconformities also cannot be closed
  without a verified corrective action, so raising one nightly from a hash
  comparison would fill the register with items nobody can clear. Observations
  are not mirrored to the ERM issue register, by `ErmBridge`'s existing rule.
- **`PlanDriftDetector::sweep()` runs on BIA approval inside the approval
  transaction, guarded.** A failure logs and does not undo the approval. At a few
  thousand plans this is fine; if a tenant reaches tens of thousands it should
  become a queued job.

## 8. HANDOFF

- **Phase 9 (exercises)** tests these plans. `PlanActivationService::activate()`
  with `isExercise: true` is the call, and `bcms_plan_activations.is_exercise`
  is what stops a rehearsal being reported as a battle-tested plan.
- **Phase 10 (incidents)** activates them. Same service, `incidentId` set. It
  must not write `bcms_plan_activations` directly.
- **Phase 11 (reporting)** wants `PlanService::currency()` for the "plans
  current" KRI and `GapAnalysisService::analyse()` for the board-pack gap
  section. Both already return the shape a pack needs, and `currency()` returns
  `null` rather than 100% when there is nothing to measure — do not "fix" that.
- **Phase 12 (PWA)** consumes `OfflineBundleBuilder::build()`. The schema version
  is `OfflineBundleBuilder::SCHEMA_VERSION`; bump it if the shape changes, so an
  old cached bundle is recognisable rather than silently mis-rendered.
