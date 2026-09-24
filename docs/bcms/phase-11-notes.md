# Phase 11 — Training & competency, supply-chain resilience, reporting & evidence

**Branch:** `integration/bcms-remaining` · **Schema changes:** 0 tables, 0 columns, 0 indexes
(ADR 0021). One column retired in place: `bcms_training_records.certificate_id`.
**Agent:** backend-engineer. **Reads:** `plans/bcms/prompts/PHASE-11-training-reporting-compliance.md`,
`docs/bcms/phase-11-spec.md`, `docs/adr/0021-phase-11-asks-for-no-schema-and-the-kri-register-is-adopted-not-invented.md`,
the seven screen specs in `docs/bcms/screens/`.

---

## 1. Built, in ADR 0021's order

### 1. The Phase 6 remediation
`App\Services\Bcms\CallTrees\TreeHealthService::mirrorKris()` now records
through `App\Services\KriMeasureBridge::recordMeasurement()` instead of
writing `KriMeasurement::updateOrCreate()` directly. A null reading is still
skipped and said out loud; an unlinked `kri_code` is still skipped rather
than invented. Test: `Phase11ComplianceTest::a_call_tree_kri_files_a_real_breach_not_just_a_measurement_row`
constructs a must-reach call-tree node with no deputy (BCMS-CT-DEPUTY-GAP's
target is zero), calls `mirrorKris()`, and asserts a `MeasureBreach` row
exists against the `Measure` for that code — not just a measurement.

### 2. KRI adoption
- `App\Support\Bcms\ResilienceKris` — the seventeen definitions from spec §2.5,
  modelled on `App\Support\Tprm\KriCatalogue`.
- `App\Services\Bcms\ResilienceKriPublisher` — `adopt()` (idempotent on
  `kri_code`, never overwrites a tuned threshold), `status()` (the "n of 17
  not linked" line), `recordVendorAttestation()` — the one measurement this
  phase writes itself.
- `App\Console\Commands\AdoptBcmsResilienceKris` (`bcms:kri:adopt`) — not
  scheduled, by design: creating seventeen KRIs in a bank's register is a
  change to their risk framework, invoked explicitly (module enable or an
  administrator), matching TPRM's own "never adopt silently" precedent.
- No `bcms_kri_links` table: the join is `kri_code` directly, per ADR 0021 §2.
  `grep -rn "bcms_kri\|bcms_metric" database/migrations/` returns nothing.

### 3. `captureReviewInputs()` extended once
`App\Services\Bcms\ProgrammeService::captureReviewInputs(ManagementReview
$review, array $manual = [])` now also computes `previous_review_actions`,
`incidents`, `exercise_evaluation_outputs`, `call_tree_and_emns_performance`,
`dr_achievement` and `supplier_continuity`, `bia_and_risk_changes` and
`improvement_opportunities` (counts), and carries two **manual** sections —
`internal_audit` and `interested_party_feedback` — that are preserved across
a re-capture when `$manual` omits them, so refreshing the computed sections
never blows away a hand-entered audit block.
`App\Http\Controllers\Bcms\ProgrammeController::captureReviewInputs()` now
reads those two (plus `context_changes`) from the request when present. A new
`GET bcms/reviews/{review}` (`bcms.reviews.show`) / `showReview()` renders the
record; `bcms.report.view` is sufficient to read an approved review.

### 4. The clause-compliance matrix — four states
`App\Services\Bcms\Compliance\ClauseComplianceMatrixService::build()` computes
every `IsoClauseRef` row's state (green/amber/red/grey) against the
sufficiency rules in phase-11-spec §3.2, joined to `bcms_programme_obligations`
for applicability. 9.2 programme/results never render grey (ADR 0021 §1) and
derive their state from the latest approved review's `internal_audit` block
plus linked `source = audit` findings. An empty obligation register renders
every non-9.2 clause amber ("applicability not yet determined") rather than a
false red or a false green. The CBN/DORA rows are a crosswalk over the ISO
rows they are "answered by", per §3.4 — `cbn.rcf.csat` always renders amber
(ADR 0021 §4). `App\Services\Bcms\Compliance\GapAnalyserService` turns every
red/amber **mandatory** row into a citation naming the org node and the
elapsed time since the last artefact — **a deterministic, templated draft**,
not an LLM call; wiring this through `BcmsLlmClient` for softer phrasing is a
legitimate frontend/Phase-12 follow-up over data this class already computes
honestly, not something invented here as AI-authored when it was not.
`App\Http\Controllers\Bcms\ComplianceController` serves the matrix, the KRI
status (`metrics/resilience-kris`), a maturity-heatmap read (`reports/
maturity-heatmap`, explicitly `per_branch_available: false` — the schema has
no business-unit dimension on `bcms_maturity_assessments`), the gap-analyser
endpoint, and raising a draft as a finding through the existing
`App\Services\Bcms\Findings\FindingService::raise()` (`source = gap_analysis`),
never a second creation path.

### 5. Training & competency
- `bcms_training_curricula`/`bcms_training_records` (already frozen, Phase 0)
  and their models (already present) are unchanged.
- **Content pack replaced**: `Database\Seeders\Bcms\Reference\TrainingCurricula`
  now ships the six curricula from spec §2.1 (`BC-AWARE-ALL`, `BC-WARDEN`,
  `BC-CRISIS`, `BC-EMNS` at six months, `BC-CHAMPION`, `BC-ITDR`), replacing
  the four-curriculum Phase 0 placeholder. No test asserted the old codes or
  count (checked by grep before changing it).
- Four awareness-campaign templates added to
  `Database\Seeders\Bcms\Reference\AlertTemplates` (`BCAWAREWEEK`,
  `LESSONSBULLETIN`, `NEWJOINERBC`, plus tagging the existing `CONTACTVERIFY`
  as the one KRI-linked campaign), all `category = awareness`,
  `iso_clause_ref = iso22301.7.3`. `BcmsReferenceSeeder::seedAlertTemplates()`
  now passes `iso_clause_ref` through (it did not before).
- `App\Services\Bcms\Training\TrainingComplianceService` — curricula list,
  live role-holder resolution (`'*'` = every active user, else a Spatie role
  name — AD-group resolution is Phase 2C's and not built), the compliance
  table (attendance and competency **as two separate facts, never blended**),
  `recordOutcome()`/`assess()` (assessor-≠-subject enforced, a failed
  assessment is still a valid record), and `linkOccurrenceParticipants()` —
  criterion 6: a present participant's occurrence links to a training record
  automatically, **without** setting `competency_assessed`.
- `App\Console\Commands\LinkBcmsTrainingFromOccurrences`
  (`bcms:training-link-exercises`, scheduled daily 06:00, idempotent) is the
  mechanism that makes criterion 6 automatic. **Deliberately not a hook on
  `ExerciseOccurrence`'s own completion path** — that code is Phase 9's, being
  built concurrently in this same tree, and this phase does not edit it. A
  daily sweep (the same shape as `SweepBcmsCallTreeHealth`) is the zero-touch
  alternative; its lookback window means a missed day is caught by the next
  run.
- No certificate field anywhere (ADR 0021 §3) — `StoreBcmsTrainingRecordRequest`
  has no such field, and the service never writes `certificate_id`.
- `App\Http\Controllers\Bcms\TrainingController` + three form requests.

### 6. Evidence packs
- `App\Services\Bcms\Reports\EvidencePackService` — `sectionsFor($framework)`
  filters the matrix's rows by `bcms_clause_refs.export_packs` (`iso22301`,
  `cbn_csf`, `cbn_open_banking`) or, for `dora`, a hard-coded crosswalk list
  (§3.4 — no `dora` export-pack key). `generate()` renders
  `resources/views/reports/pdf/bcms-evidence-pack.blade.php` through the
  product's own `ThirdLine\Reporting\DocumentRenderer` (dompdf), and every
  export writes a `bcms_audit_logs` row (`event = pack.exported`,
  `auditable_type = 'bcms_report_pack'`) — the entire "export log"; there is
  no `bcms_report_runs` table.
- `App\Support\Bcms\CsatQuestionMap` + `App\Services\Bcms\Reports\CsatPrefillService`
  — the CSAT pre-fill matches a customer-uploaded workbook **by question
  label**, using PhpSpreadsheet (`RcsaTemplateWriter`'s own library), writes
  the answer into the adjacent cell, and always adds a cover sheet listing
  every unmatched question, even when none. No cell coordinate is ever
  invented.
- `App\Http\Controllers\Bcms\EvidencePackController` — index/preview/store/
  csatPrefill.
- **Deliberate scope reduction, named rather than silently built partway**:
  generation is **synchronous only**. The screen spec's queued path
  (`useJobProgress`, a background job, a signed download link, a
  retention-windowed log entry with an expiring link) for a full-year
  ISO 22301/CBN CSF pack is not built. Every pack in this build's clause
  count generates and streams back inside a normal request; a customer whose
  estate makes that time out is the signal that turns the deferred queue path
  into real work, not a reason to fake one now. `verify at integration`.
- No virus scanning on the CSAT upload — a named, deliberate go-live gap (one
  of three across TPRM/BCMS), not worked around.

### 7. Board pack
- `App\Services\Bcms\Reports\BoardPackService` — the nine sections from the
  blueprint's own order, each reusing an existing source (`GapAnalysisService`
  for RTO gaps, `ResilienceKriPublisher::status()` for the KRI table,
  `BcmsHomePresenter`'s own plan-currency computation restated, never a
  second one). No `bcms_board_packs` table (confirmed absent, ADR 0021 §4;
  only `tp_board_packs` exists) — no draft/review/sign-off lifecycle, no
  persisted narrative; generated on demand, logged as `pack.exported` exactly
  like the regulatory pack.
- `resources/views/reports/pdf/bcms-board-pack.blade.php` for the PDF.
- `App\Services\Bcms\Reports\BoardPackPptxWriter` — a minimal, hand-written
  valid OOXML `.pptx` (one slide master/layout/theme, one slide per section),
  built with `ZipArchive` because `phpoffice/phppresentation` is not an
  existing dependency and adding one is outside this phase's remit. Verified
  structurally in `Phase11ComplianceTest` (openable zip, the required parts
  present). **Not verified against PowerPoint/Keynote itself** — flagged
  `verify at integration` for frontend-engineer or reliability-engineer to
  open the real file once generated end-to-end.
- `App\Http\Controllers\Bcms\BoardPackController` — `index` (`bcms.report.view`)
  and `export` (`bcms.report.export`, `format=pdf|pptx`).

### 8. Supplier resilience & My Resilience
- `App\Services\Bcms\Suppliers\SupplierResilienceService` — the
  BCMS-relevance filter (`bcms_dependencies` with `dependable_type =
  'tprm_third_party'` against a Tier-1/critical process), the
  continuity-currency view, the chase list, the evidence-ambiguous flag
  (more than one `supports_critical_function` engagement), the concentration
  read-through (counts and named processes only — **no utilisation
  percentage**, TPRM's own named go-live gap), and `attestationRate()` for
  the one KRI this phase measures.
- **New TPRM models** (tables already existed, migrated by TPRM, with no
  model class before this phase): `App\Models\Tprm\BcpTest`,
  `App\Models\Tprm\AwarenessAttestation`.
- `App\Services\Tprm\Continuity\BcpTestRecorder` — the one write path onto
  `tp_bcp_tests`, requiring `tprm.edit` in addition to whatever BCMS
  permission the caller holds. A BCMS grant is never a licence to write the
  vendor register.
- `App\Http\Controllers\Bcms\SupplierResilienceController` — continuity view,
  concentration, vendor exercise participation (matched by email between
  `bcms_contacts` and `tp_contacts` — see gap below), and `storeAttestation`.
- `App\Services\Bcms\MyResilienceService` + `App\Http\Controllers\Bcms\MyResilienceController`
  — the five cards from the screen spec, all read-only, no self-service edit
  of contact/consent (ADR 0018 §1 — Phase 2D's).
- **Permission decision for My Resilience, as the screen spec asked**: `my.view`
  — the platform's existing baseline "see your own responsibilities page"
  grant, already held by every employee via `RiskPermissionCatalog::baseline()`.
  `bcms.myprofile.manage` was considered and rejected: it is not in the
  baseline list, so gating on it would exclude exactly the "every employee"
  audience the screen is for. No catalogue edit was needed either way.

### 9. Gate 1 fixes, first re-gate (backend-engineer)

Two defects qa-engineer's gate 1 raised against the pass above, both closed
with **no schema change** (ADR 0021 continues to hold: the freeze delta for
this phase stays at zero tables, zero columns).

**Defect 1 — the reference seeder never retired the codes it superseded.**
`Database\Seeders\Bcms\BcmsReferenceSeeder::seedTrainingCurricula()` keys
`updateOrCreate` on `code`, so a tenant seeded under Phase 0's four-curriculum
placeholder pack (`BC-AWARE`, `BC-CHAMPION`, `BC-FACILITATOR`, `BC-CRISIS`)
kept all four rows live once this phase's six-curriculum pack landed: two
codes matched and updated in place (`BC-CHAMPION`, `BC-CRISIS`), and two did
not (`BC-AWARE` → `BC-AWARE-ALL`, `BC-FACILITATOR` → nothing), so those two
stayed active beside their replacements — eight active curricula instead of
six, live-verified on the dev tenant before the fix (`BC-AWARE` and
`BC-FACILITATOR` sitting alongside `BC-AWARE-ALL` and `BC-WARDEN`, exactly as
reported).

`bcms_training_curricula.is_active` already exists (frozen at Phase 0), so the
fix is the column-exists path the defect report named, not the
migrate-and-delete fallback: `Reference\TrainingCurricula::superseded()` is a
new `old code => new code|null` map (`BC-AWARE` → `BC-AWARE-ALL`,
`BC-FACILITATOR` → `null` — it has no successor in the six-curriculum pack and
is retired outright, not folded into `BC-WARDEN`, which trains a different
role for a different clause), and
`BcmsReferenceSeeder::retireSupersededTrainingCurricula()` sets
`is_active = false` on whichever of those codes exist for the system-owned
(`organization_id = null`) rows. **The superseded row is never deleted**, so
`bcms_training_records.curriculum_id` for anyone trained under the old code
stays a valid foreign key — no migration of historical records was needed or
done, because nothing about them becomes invalid by the curriculum they point
at going inactive rather than disappearing.
`Phase11TrainingTest::the_reference_seeder_retires_the_phase_0_placeholder_codes_it_superseded`
seeds the four placeholder rows exactly as the original Phase 0 seeder wrote
them (system-owned, `TenantContext::clear()`'d), attaches a historical
training record to `BC-FACILITATOR`, runs the reference seeder twice, and
asserts: exactly six active curricula; `BC-AWARE` and `BC-FACILITATOR` exist
but are inactive; the historical record still resolves to a real curriculum
row; and neither superseded code is duplicated by the second run. **Applied to
the dev `risk` database**: `BC-AWARE` and `BC-FACILITATOR` went from
`is_active = 1` to `is_active = 0`; the active count went from eight to six.

**Defect 2 — the Kano Heritage Bank demo tenant had no Phase 11 data.**
`BcmsDemoSeeder` gained three new private methods, called from the end of
`run()` in the seeder's own idempotent-by-existence-check style:

- `seedTrainingRecords()` — attendance-only `BC-AWARE-ALL` records for most of
  a ten-person roster, one assessed record per role-based curriculum through
  `TrainingComplianceService::recordOutcome()` (so the same self-assessment
  guard a real user hits is exercised here), one `BC-EMNS` row whose
  `next_due_date` is corrected by hand to demonstrate the six-month cadence
  (`recordOutcome()` always dates the next cycle from *today*, regardless of
  `completed_at`, so a demo row that wants to show a past-dated cycle has to
  restate the date itself), and one `BC-WARDEN` row pushed fourteen months
  back against its twelve-month cycle so the overdue tile has something real
  to show. Guarded on `TrainingRecord::query()->exists()`, matching every
  other block in this seeder.
- `seedSupplierResilienceEvidence()` — reuses `TprmDemoSeeder`'s own vendors
  (`Interswitch Limited`, `Cloudspan Digital Limited`) and engagement
  references (`ENG-DEMO-0001/0002/0004`) rather than inventing a third vendor
  portfolio; marks two of Interswitch's engagements
  `supports_critical_function = true` so it is the **evidence-ambiguous**
  vendor phase-11-spec §4(5) describes, and one of Cloudspan's so it is the
  unambiguous case beside it; attaches a `bcms_dependencies` row from each
  vendor to an approved, Tier-1 BIA assessment (`BCP-CARD`, `BCP-CHAN`) — the
  BCMS-relevance filter that makes a vendor BCMS-critical; and records three
  `tp_bcp_tests` rows **only through `App\Services\Tprm\Continuity\BcpTestRecorder`**,
  never a direct write, one of them deliberately past its `next_due_at` so the
  chase list is non-empty. Guarded per engagement (`BcpTest::query()->where('engagement_id', ...)->exists()`)
  and per dependency, and does nothing at all if the TPRM demo portfolio is
  not on the tenant — a dependency naming a vendor that does not exist would
  be exactly the invented data this seeder's own docblock refuses to write.
- `seedResilienceKris()` — adopts the seventeen definitions
  (`ResilienceKriPublisher::adopt()`, already idempotent), calls
  `TreeHealthService::mirrorKris()` so the four call-tree KRIs measure from
  the call trees this demo already builds (never a second number for what
  Phase 6 already computes), and records the one measurement this phase owns
  itself — `BCMS-VENDOR-ATTEST` — from `SupplierResilienceService::attestationRate()`
  computed over the evidence the previous method just seeded. **No number is
  invented**: the KRI adoption is idempotent by `kri_code`, the call-tree
  mirror is idempotent per measurement period, and the vendor-attestation rate
  is a real percentage over real rows or `null` over none, never zero.

`Phase11DemoSeederTest::the_demo_seeder_runs_phase_11_data_twice_without_duplicating_anything`
builds the minimal fixture the three methods need (the TPRM demo portfolio's
own vendor/engagement shape, two Tier-1 processes with approved BIAs, and a
ten-person roster including `admin@risk.test` as super-admin), invokes the
three methods twice by reflection (they are private and `run()` has no
smaller public seam — the same trade `DemoSeederCompletionTest` makes for the
call-tree and EMNS seeders), and asserts no duplication across every table
touched: one `BC-EMNS` record with the correct six-month due date, one
overdue `BC-WARDEN` record, exactly one `tp_bcp_tests` row per engagement,
`supports_critical_function` set exactly once per engagement, and exactly
seventeen `BCMS-%` KRI rows with `BCMS-VENDOR-ATTEST` measured. **Applied to
the dev `risk` database** (which already carried BCMS demo data from earlier
work, including two training records unrelated to this phase against
now-retired curriculum codes — the seeder's `TrainingRecord::query()->exists()`
guard correctly left those alone rather than adding a second batch beside
them): `tp_bcp_tests` went from 1 to 4 (the three new rows), the two
`Dependency` rows linking Interswitch and Cloudspan to `BCP-CARD`/`BCP-CHAN`
were created, `supports_critical_function` is set on all three engagements,
and `BCMS-VENDOR-ATTEST.current_value` is measured (a real percentage,
computed from the seeded evidence). A second `db:seed` run produced no
duplicates in any of those tables.

### 10. Gate 1 fixes, second re-gate (backend-engineer)

qa-engineer's second re-gate confirmed both fixes above and found two more
defects, each already filed with a reproducing test. Both closed with **no
schema change**.

**Defect 3 — `MyResilienceService::training()` had no assignment resolution
at all.** It read every `TrainingRecord` for the user and rendered
`overdue` straight off `next_due_date`, with no filter for the curriculum
still being active and no check that the person is still a current
role-holder for it. A record against a curriculum §9's fix retires
(`BC-FACILITATOR`, `is_active = false`) therefore rendered as a **permanent,
unclearable "Overdue" tile** on the employee's own My Resilience page
(`Index.jsx:38-58` renders `t.overdue` literally) — the record can never
become current again because nobody can re-assess a curriculum that no
longer accepts assessments.

Fixed by rebuilding `training()` around `TrainingComplianceService`'s own
assignment resolution, per `docs/bcms/screens/my-resilience.md` §2 card 1's
own instruction to resolve assignment "the same way training-compliance.md
resolves assignment": for each of the person's `TrainingRecord` rows, grouped
to the **latest per curriculum**, the row is kept only if its curriculum is
still active (`TrainingCurriculum::is_active`) **and** the person is still a
current role-holder for it (`TrainingComplianceService::assignedUsers()`,
matching the "current role-holders, not a stored enrolment list" rule
verbatim). A curriculum the person has never attended is not invented as a
placeholder row — the card reports what the person's own records say, so a
brand-new hire correctly sees an empty "My training" card rather than a row
for every curriculum a role match could theoretically enrol them in.
`MyResilienceService` now takes `TrainingComplianceService` as a constructor
dependency rather than querying `TrainingRecord` on its own terms.
Test: `Phase11ScreensTest::my_resilience_excludes_a_retired_curricula_record_from_the_training_list`
(was failing, now green) — a user with a single record against a
`is_active = false` curriculum sees `training: []`, not the record.

**Defect 4 — the demo seeder's training guard blocked on ANY row in the
tenant, not the rows the block itself creates.** `seedTrainingRecords()`
opened with `if (TrainingRecord::query()->exists()) return;` — exactly the
coarse guard §9's own fix note flagged as the reason the dev database's
training block never seeded (two unrelated rows, left by earlier manual
testing, already satisfied it). The guard had no self-healing path: every
future re-seed of that tenant would stay blocked for ever, because "any row
exists" never becomes false again.

Fixed by removing the top-of-method guard entirely and scoping the check to
what each write itself creates: `recordTrainingOutcomeOnce()` checks
`TrainingRecord::where('curriculum_id', ...)->where('user_id', ...)->exists()`
before calling `recordOutcome()`, the same per-row shape
`seedSupplierResilienceEvidence()` already uses per engagement (`BcpTest::where('engagement_id', ...)->exists()`).
Every call site (`BC-AWARE-ALL` attendance, the five assessed role-based
records, the overdue `BC-WARDEN` record) now goes through this helper, so
re-seeding a tenant that already carries some of these rows — or an
unrelated row against a retired curriculum — adds only what is missing.
Test: `Phase11DemoSeederTest::a_pre_existing_unrelated_training_record_no_longer_blocks_the_phase_11_training_seed`
— inverted from documenting the defect (asserted zero `BC-WARDEN` rows) to
asserting the fix (two `BC-WARDEN` rows — the assessed holder and the
separate overdue holder — beside the untouched unrelated row, stable across
two seeding passes).

**Re-applied to the dev `risk` database.** `bcms_training_records` went from
2 (the two unrelated/pre-existing rows) to 13 on the first re-run of
`db:seed --class="Database\Seeders\Bcms\BcmsDemoSeeder"`, and stayed at 13 on
a second run — the six `BC-AWARE-ALL` attendance rows and one assessed record
each for `BC-WARDEN`, `BC-CRISIS`, `BC-CHAMPION`, `BC-ITDR`, `BC-EMNS` were
added beside the two pre-existing rows, which were left untouched. The
overdue second `BC-WARDEN` record did not seed on this particular database
because it has exactly six active users, and the designated overdue holder
(index 5) is the same person as the assessor (`$users->last()`, also index
5) — the self-assessment guard correctly declines that pairing rather than
recording somebody assessing their own competence; the behaviour that
matters (two distinct `BC-WARDEN` records where a sixth, distinct user
exists) is proven on the ten-user fixture in
`Phase11DemoSeederTest`.

### 11. `ModuleSections`: `exercises` and `compliance` flipped live

Both `App\Support\Bcms\ModuleSections` entries had stayed `live => false` past
the phase that actually delivered them — `exercises` ("Exercises & AAR")
since Phase 9 shipped the execution workspace, scoring and the AAR;
`compliance` ("Training & Evidence") since this phase shipped the matrix,
the training screens and the evidence/board packs. The BCMS home screen was
therefore still showing both as "not landed" placeholders. Both flipped to
`true`; each shell's own route name is kept (ADR's own reuse rule for this
pattern — see `it-dr.index` → `dr-systems.index`), now redirecting to the
section's actual landing page: `exercises.index` → `exercise-programmes.index`,
`compliance.index` → `reports.compliance-matrix`. See
`docs/bcms/phase-9-notes.md` §10 for the paired `exercises` addendum.

**Consequence for two tests, not a regression.** With every real section now
live, `ModuleShellTest::a_section_screen_names_the_phase_that_delivers_it`
(bound to `bcms.incidents.index`, already broken independently of this pass
since Phase 10 went live) and `Phase1ScreensTest`'s shell-example assertion
(bound to `bcms.compliance.index`, broken by this pass's own flip) lost the
only kind of section either could demonstrate the shell against — a real key
whose phase has not landed. Both are kept, not deleted:
`ModuleSections::fake()`/`reset()` (a static override, set in the test's own
`setUp()` **before** `parent::setUp()` boots the application and
`routes/web.php` reads `ModuleSections::all()` to register the placeholder
route) lets each test manufacture a synthetic, permanently non-live
`shell-example` section and assert the shell mechanism against that instead
of a real key that will break again the next time a phase ships. `find()`
and `keys()` are unaffected in every other test, since the fake is scoped to
the one test method that sets it (checked by `$this->name()`) and reset in
`tearDown()`.

## 2. Gaps found and named, not papered over

1. **Vendor exercise participant → vendor link is a best-effort email match,
   not a stored relationship.** `bcms_contacts` carries no vendor-link column
   and none is proposed (ADR 0021 §4 confirms `bcms_exercise_participants`
   gets no `third_party_id`). `SupplierResilienceController::vendorParticipation()`
   joins a `bcms_contacts` row to a `tp_contacts` row **by email address** —
   a legitimate, zero-migration join key both tables already carry, but it is
   a heuristic (a vendor contact whose bank-side email differs from their
   TPRM contact email will not resolve) rather than an explicit stored edge.
   Flagged for compliance-analyst/architect if a stronger link is wanted.
2. **Regulatory-evidence and board-pack generation are synchronous only** (§6
   above) — the queued path from the screen specs is not built.
3. **The PPTX writer is hand-rolled OOXML**, structurally valid and tested as
   a zip, but not opened in PowerPoint/Keynote as part of this pass.
   `verify at integration`.
4. **The AI gap analyser is deterministic/templated, not an `BcmsLlmClient`
   call.** It satisfies criterion 9's citation requirement honestly from
   computed data; softer phrasing through the LLM is optional follow-up
   polish, not a correctness gap.
5. **`Phase1GovernanceTest::carried_to_occurrence_id_is_exposed_and_is_not_populated_by_this_phase`
   fails on this branch independently of this phase's changes** — it flags
   `app/Services/Bcms/Exercises/AarExportService.php` and
   `CarriedActionService.php`, both Phase 9's own files, under concurrent
   construction in this tree. Not touched, per this phase's explicit
   instruction not to edit Phase 9's files; noted for whoever closes Phase 9's
   gate.

## 3. Verification run

- `php artisan test --filter=Phase11ComplianceTest` — 15 passed (47 assertions).
- `php artisan test --filter=Phase11TrainingTest` — 7 passed (18 assertions).
- `php artisan test --filter=Phase11SupplierTest` — 7 passed (13 assertions).
- `php artisan test --filter=RouteAuthorizationTest` — 4 passed.
- `php artisan test --filter=PermissionCatalogCoversRoutesTest` — 6 passed.
- `php artisan test --filter=Phase0FoundationsTest` — 38 passed (528 assertions,
  both BCMS and TPRM suites) — the frozen manifest is untouched.
- `php artisan test --filter=NoFabricatedNumbersTest` — 8 passed.
- `php artisan test --filter=Phase1GovernanceTest` — 42 passed, 1 pre-existing
  failure unrelated to this phase (see gap 5).
- Pint: clean on every file this phase touched or added.
- PHPStan (`--memory-limit=1G`), scoped to every file this phase touched or
  added: **0 errors**.
- `grep -rn "bcms_kri\|bcms_metric\|bcms_report_runs\|bcms_board_packs" database/migrations/`
  — no matches (criterion 4, ADR 0021 §4).

### 3.1 Gate 1 fix re-run (§9)

- `tests/Feature/Bcms/Phase11TrainingTest.php` — **8 passed (31 assertions)**,
  including the new
  `the_reference_seeder_retires_the_phase_0_placeholder_codes_it_superseded`.
- `tests/Feature/Bcms/Phase11DemoSeederTest.php` (new file) —
  **1 passed (10 assertions)**.
- `tests/Feature/Bcms/Phase11SupplierTest.php` — 7 passed (13 assertions),
  unchanged, re-run to confirm the two fixes did not disturb it.
- `tests/Feature/Bcms/Phase11ComplianceTest.php` — 15 passed (47 assertions).
- `tests/Feature/Bcms/Phase11ScreensTest.php` — 11 passed.
- `tests/Feature/Bcms/Phase11ReportingGateTest.php` — 26 passed (271
  assertions).
- `tests/Feature/NoFabricatedNumbersTest.php` — 8 passed.
- `tests/Feature/Bcms/Phase0FoundationsTest.php` — 29 passed (433 assertions).
- Pint: clean on all five touched/added files
  (`BcmsReferenceSeeder.php`, `Reference/TrainingCurricula.php`,
  `BcmsDemoSeeder.php`, `Phase11TrainingTest.php`, `Phase11DemoSeederTest.php`).
- PHPStan (`--memory-limit=1G`), scoped to the same five files: **0 errors**.
- Dev `risk` database: the training-curricula fix applied directly
  (`db:seed --class="Database\Seeders\Bcms\BcmsReferenceSeeder"`) — active
  curriculum count went from eight to six, `BC-AWARE`/`BC-FACILITATOR` now
  inactive. The demo-seeder addition applied twice
  (`db:seed --class="Database\Seeders\Bcms\BcmsDemoSeeder"`) — `tp_bcp_tests`
  went from one to four on the first run and stayed at four on the second;
  the two vendor dependency rows and the `BCMS-VENDOR-ATTEST` measurement
  appeared on the first run and were not duplicated on the second. The demo
  seeder's own training block did not add rows on this particular database,
  because two pre-existing, unrelated training records (against now-retired
  curriculum codes, evidently left by earlier manual testing on this shared
  dev database) already satisfied its `TrainingRecord::query()->exists()`
  guard — the guard did exactly what it is for, and the behaviour is proven
  on a clean database by `Phase11DemoSeederTest`.

### 3.2 Gate 1 fix re-run, second re-gate (§10)

- `tests/Feature/Bcms/Phase11ScreensTest.php` — **12 passed (204 assertions
  combined with the file below)**, including
  `my_resilience_excludes_a_retired_curricula_record_from_the_training_list`
  (was failing, now green).
- `tests/Feature/Bcms/Phase11DemoSeederTest.php` — **2 passed**, including
  `a_pre_existing_unrelated_training_record_no_longer_blocks_the_phase_11_training_seed`
  (inverted from documenting the defect to asserting the fix, per gate
  instruction).
- `tests/Feature/Bcms/Phase11TrainingTest.php` — 8 passed (31 assertions).
- `tests/Feature/Bcms/Phase11ComplianceTest.php` — 15 passed (47 assertions).
- `tests/Feature/NoFabricatedNumbersTest.php` — 8 passed.
- Pint: clean on all six touched files (`MyResilienceService.php`,
  `BcmsDemoSeeder.php`, `BcmsReferenceSeeder.php`,
  `Reference/TrainingCurricula.php`, `Phase11TrainingTest.php`,
  `Phase11DemoSeederTest.php`).
- PHPStan (`--memory-limit=1G`), scoped to the same six files: **0 errors**.
- Dev `risk` database: `db:seed --class="Database\Seeders\Bcms\BcmsDemoSeeder"`
  re-run twice. `bcms_training_records` went from 2 (the two pre-existing,
  unrelated rows, left untouched) to 13 on the first run and stayed at 13 on
  the second — the fix's self-healing guard added exactly the rows it was
  missing beside data it does not own.
- **A note on how this re-run was actually done**: the first attempt at this
  re-run collided with a duplicate test process this agent had started
  against the same `risk_test_test_5` database (both running `migrate:fresh`
  concurrently), which corrupted that run's result (9 failed) and briefly
  left the database's `migrations` table missing entirely. Recovered by
  killing the duplicate process, confirming nothing else held the database,
  and re-running `migrate:fresh` and the test command as two separate,
  sequential steps — the clean re-run above is what that produced. Recorded
  here because it is the exact trap this repository's own working notes warn
  about: never two runs on one database.

### 12. Gate 1 fixes, third re-gate (backend-engineer)

Three defects, all closed with **no schema change**.

**Defect 5 — the universal-audience carve-out on `MyResilienceService::training()`
was itself the bug.** §10's fix correctly rebuilt `training()` around
assignment resolution, but kept a special case that suppressed the
tenant-wide `BC-AWARE-ALL` curriculum from the not-yet-attended placeholder
row until the person actually attended it once. `my-resilience.md` §2 card 1
draws no such distinction — "for each curriculum the person is currently
assigned to" — and `assignedUsers('*')` already resolves the universal
curriculum to every active user, so the carve-out made a never-trained
employee's own mandatory awareness obligation invisible on their own
resilience page, exactly the outcome the card exists to surface. The
carve-out is removed outright; `TrainingComplianceService::complianceRows()`,
the register this card is required to mirror, already renders this shape
(no record → "not yet attended", counted overdue by `summaryTiles()`) for
every active user with no special case, so `training()` now matches it
without exception. Three existing tests that had asserted `training.0`
positionally, written when the universal curriculum was still suppressed,
were updated (not weakened) to account for `BC-AWARE-ALL` now sorting first
by `code` — `my_resilience_excludes_a_retired_curricula_record_from_the_training_list`
now asserts the retired record is absent AND the universal placeholder is
the one row present (previously asserted an empty list, which stopped being
true once every active user carries that row); the other two moved their
assertions from `training.0` to `training.1`.
Test: `Phase11ScreensTest::my_resilience_shows_all_staff_awareness_training_even_when_never_attended`
(was failing, now green; three previously-green tests adjusted for the index
shift, not their intent).

**Defect 6 — `ProgrammeService` and `BoardPackService` still read the three
columns ADR 0020 §2 retired in place.** `bcms_incidents.reporting_due_at`,
`regulator_notified_at` and `cbn_reference` are NULL on every incident
created from Phase 10 onward; the management-review "incidents" block
(`ProgrammeService::captureReviewInputs()`) and the board pack's
`incidentSummary()` were still computing `notified_within_due`/
`notified_late_or_missing` from them, so both would have silently reported
zero overdue/late notifications forever regardless of what
`bcms_incident_notifications` actually held. Fixed in both readers by
computing the same two figures from `bcms_incident_notifications` instead:
"within due" is a submitted row whose `submitted_at <= due_at`; "late or
missing" is the union of `App\Services\Bcms\Incidents\NotificationService::overdueQuery()`
(never submitted, `due_at` already past — reused, not reimplemented) and a
submitted-late case (`submitted_at > due_at`) that query does not cover
because a submitted obligation is no longer "open". `ProgrammeService` now
takes `NotificationService` as a constructor dependency and the logic lives
in a new private `incidentReportingOutcomes()`, so both readers describe the
same rule once each rather than drifting. `NotificationService` itself was
not touched (Phase 10's file, out of this phase's boundary). Two readers
outside this phase's boundary still reference the retired columns and were
left alone, named rather than fixed: `App\Models\Tprm\Incident` and
`App\Services\Tprm\Incidents\NotificationDraftService` — both are TPRM's own
`tp_incidents` table, a different schema the grep for these three column
names cannot distinguish from BCMS's by name alone; confirmed by path, not
edited.
Test: `Phase11ReportingGateTest::an_overdue_notification_row_is_counted_by_the_board_pack_and_management_review`
(new) — an incident with `is_reportable = true`, NULL retired columns, and
one `bcms_incident_notifications` row overdue by six hours is asserted
counted as `notified_late_or_missing = 1` by both `BoardPackService::preview()`
and `ProgrammeService::captureReviewInputs()`.

**Defect 7 (named, verified as already correct) — PIR rows and the DependencyType
literal.** Checked whether `ClauseComplianceMatrixService`'s clause-8.5
evidence check (`aarSufficiency()`, behind `IsoClauseRef::Iso22301_8_5_report`)
counts a post-incident review (a `bcms_aars` row with `incident_id` set,
`occurrence_id` null, ADR 0020) as an exercise evaluation. It does not:
`aarSufficiency()` scopes its finalised-AAR count to
`whereIn('occurrence_id', $completedExerciseOccurrenceIds)`, which a PIR's
null `occurrence_id` never matches, and falls back to red
("No exercise occurrence has completed yet.") when there is no completed
exercise regardless of how many PIRs exist — already the correct behaviour,
not a fix. Added the reproducing test anyway, since the gate instruction
asked for one and none existed:
`Phase11ComplianceTest::a_finalised_post_incident_review_alone_does_not_evidence_clause_8_5`
— a finalised PIR with no completed exercise occurrence stays red, passed on
first run.
`ClauseComplianceMatrixService::supplyChainSufficiency()`'s literal
`'tprm_third_party'` (line ~643) was replaced with
`App\Enums\Bcms\DependencyType::Vendors->value`, matching the enum Phase 10
QA required for `'applications'` elsewhere in this file.

**Verification for this cycle**: all six `Phase11*Test.php` files green
(`Phase11ComplianceTest` 16 passed/49 assertions, `Phase11DemoSeederTest` 2
passed/17 assertions, `Phase11ReportingGateTest` 16 passed/103 assertions,
`Phase11ScreensTest` 15 passed/239 assertions, `Phase11SupplierTest` **7
passed/13 assertions** — corrected below, `Phase11TrainingTest` 8 passed/31
assertions); `Phase10IncidentTest` 23 passed/55 assertions (read-only
regression, not edited); `BcmsRecordVisibilityTest` 36 passed/253 assertions.
Pint clean and PHPStan (`--memory-limit=1G`) 0 errors on
`MyResilienceService.php`, `ProgrammeService.php`,
`Reports/BoardPackService.php`, `Compliance/ClauseComplianceMatrixService.php`.

**Correction (gate 1 re-gate #4 finding, §13):** this section originally
claimed `Phase11SupplierTest` "9 passed (30 assertions)". That number was
wrong — a copy/paste from a different file's total, never re-checked against
this file's own output. The file at the time this section was written had
**7 tests, 13 assertions**, confirmed by re-running it standalone
(`DB_DATABASE=risk_test_p11_b9babae php artisan test
tests/Feature/Bcms/Phase11SupplierTest.php` against the state before §13's
own additions below). Every other count in this section was independently
re-run and confirmed accurate at the time it was written; only this one line
was wrong.

### 13. Gate 1 fixes, fourth re-gate (backend-engineer)

Three coverage/paperwork defects from qa-engineer's re-gate #4, plus the
minor items named alongside them. No schema change.

**Defect 8 — phase-11-spec §6 criterion 2 (reproducibility) had no test.**
`EvidencePackService::generate()` and `BoardPackService::generatePdf()` both
bake `now()` into `generatedAt` on the rendered PDF, so raw PDF bytes were
never going to be identical across two calls — but that is not what the
criterion asks for. §6 says "section content", and `generatedAt`/
`generatedBy` are stamped onto the layout data AFTER the deterministic part
runs (`sectionsFor()`/`preview()`, exactly what the PDF/PPTX writers are
handed) and never appear inside it, so the honest test compares two calls to
those two methods directly with `===` — no normalisation needed, because
there is nothing non-deterministic to normalise away in the first place.
Both methods gained a docblock stating this explicitly.

Auditing every "latest row" / "order by a single column" read the two
packs compose over turned up real non-determinism, all in
`ClauseComplianceMatrixService` and `BoardPackService`: an `->latest($col)
->first()` (or `->orderBy($col)`) with no primary-key tiebreaker leaves the
database free to return either side of a tie, and a seeded test fixture
lands two rows in the same second easily. Fixed by adding `->orderByDesc('id')`
(or `->orderBy('id')` for a list, matching the primary sort's direction)
after every such call in both files — `existsCheck()`'s generic
`->latest()->first()` (used by ~8 different clause checks),
`competenceSufficiency()`, `awarenessSufficiency()`,
`communicationSufficiency()`, `bia/riskAssessmentSufficiency()` shared
`$latest`, `recoverySufficiency()`, `reviewSufficiency()`,
`latestReviewWithAudit()` (which also needed a defined base `->orderBy('id')`
on its `->get()` before the in-PHP `sortByDesc()`, since a PHP-stable sort
only preserves an ALREADY-defined order on a tie), `BoardPackService::
postureSummary()`'s two `MaturityAssessment` reads, `latestApprovedReview()`,
`maturityTrend()` and `openNonconformities()` (both list order, not just a
single row). `ClauseComplianceMatrixService`'s class docblock now states the
tiebreaker convention for any check added later.
Test: `Phase11ReportingGateTest::evidence_pack_and_board_pack_section_content_is_reproducible_across_two_calls`
(new) — seeds two finalised AARs and two open findings sharing the exact
same timestamp (a genuine tie, not an assumed absence of one), calls
`sectionsFor('iso22301')` and `preview($year)` twice each, asserts `===`.

**Defect 9 — `ClauseComplianceMatrixService::aarSufficiency()`'s
`last_evidenced` was unscoped (QA minor item, folded in here).**
`$finalised` (the count gating red/green) was already scoped to
`whereIn('occurrence_id', $completedExerciseOccurrenceIds)`, correctly
excluding a PIR (`incident_id` set, `occurrence_id` null, ADR 0020) from the
clause-8.5 evidence check — confirmed correct in §12's defect 7. But
`$latest` (the DATE shown beside a red/green cell) queried
`Aar::where('status', 'final')` with no such scope, so a finalised PIR could
still put its own date beside 8.5's red cell — a real, if cosmetic, defect.
Scoped `$latest` to the same `whereIn('occurrence_id', $completed)` as
`$finalised`, with the id tiebreaker from defect 8 folded in.

**Defect 10 — `supplyChainSufficiency()`'s literal `'tprm_third_party'`
(QA minor item, already fixed in §12 defect 7)** — no further action; listed
here only so this section's own defect count is not read as having missed
it.

**Test coverage added, QA minor items:**
- `Phase11ReportingGateTest::a_not_reportable_incident_is_in_neither_notification_bucket`
  (new) — an incident with `is_reportable = false` contributes to the raw
  incident `count` but to neither `notified_within_due` nor
  `notified_late_or_missing`, in both `BoardPackService::preview()` and
  `ProgrammeService::captureReviewInputs()`.
- `Phase11SupplierTest::the_concentration_view_carries_counts_and_processes_but_no_utilisation_percentage`
  (new) — a vendor dependent on by two Tier-1 processes (the concentration
  case itself) produces a row with exactly `vendor_id`, `vendor_label`,
  `process_count`, `processes` — no utilisation percentage key, named and
  confirmed absent by asserting the exact key set, not just a missing key by
  name (`SupplierResilienceService::concentration()`'s own docblock already
  states why: no shareholders'-funds figure behind TPRM's concentration
  bands).
- `Phase11ScreensTest::the_maturity_heatmap_ships_per_branch_available_false`
  (new) — pins `ComplianceController::maturityHeatmap()`'s existing, already
  correct `per_branch_available: false` / explanatory note (no business-unit
  column on `bcms_maturity_assessments`) so a future change cannot silently
  fabricate branch rows without a test noticing.

**Defect 11 — criterion 5 (dashboard widgets, branch-scoped) had no test,
and writing one found a genuine product gap.** No BCMS-specific widget
mechanism exists or should — the ask is that a BCMS-adopted resilience KRI
works through the SAME generic `key_risk_indicators` widget source ERM/TPRM
KRIs already use (`App\Services\Widgets\WidgetSourceRegistry`). It does, at
the organisation-wide scope: `WidgetScope::isUnrestricted()` skips node
filtering entirely when a widget renders with no node in context, so every
BCMS KRI — reachable or not by branch — is counted correctly there. What
does NOT work, and was not fixed here because it is outside this phase's
authority to decide, is branch scoping for a resilience KRI specifically:
`node_id` (the widget engine's branch column) is deliberately absent from
`KeyRiskIndicator::$fillable` — it is resolved automatically by the graph
sync (`App\Services\Graph\ObjectSyncService`), never written directly — and
`App\Support\Graph\ObjectSourceMap`'s `key_risk_indicators` spec resolves it
ONLY through `node_via => ['table' => 'risks', 'key' => 'risk_id']`: a KRI's
branch is wherever the ERM `Risk` it monitors sits.
`App\Services\Bcms\ResilienceKriPublisher::createKri()` never sets `risk_id`
(a resilience KRI monitors the BCMS programme as a whole, not one ERM risk),
so every BCMS-adopted KRI's `node_id` resolves to null on creation and stays
null for the row's entire life — it can never be assigned to one branch
through any existing write path. Giving these seventeen KRIs a branch (and
if so, which one — there is no single canonical "whole organisation" node in
this graph; `BusinessUnit`/`Entity` form a forest, not a single-rooted tree)
is a product/architecture decision, not a bug fix, and is named for
`architect` rather than guessed at: `App\Support\Graph\ObjectSourceMap`
(the `key_risk_indicators` spec) and `App\Services\Bcms\ResilienceKriPublisher`
(`createKri()`) are the two files that would change.
Tests (new file, `tests/Feature/Bcms/Phase11WidgetsTest.php`):
`a_bcms_adopted_kri_is_counted_through_the_generic_widget_source_at_the_organization_scope`
— `ResilienceKriPublisher::adopt()` then a `key_risk_indicators`-sourced
widget rendered at no node counts every BCMS-coded row correctly (proves the
generic path already works at the scope BCMS KRIs actually occupy) — and
`a_branch_scoped_kri_reading_is_not_shown_to_a_user_scoped_to_another_branch`
— two `BCMS-*`-coded rows with `node_id` set directly via `forceFill()`
(bypassing `$fillable` exactly as the graph sync itself does, since no
product write path sets it) prove the GENERIC node-isolation mechanism holds
for a BCMS-coded row, independent of whether today's product ever produces
that row shape through its own write path.

**Re-checked and corrected**: §12's `Phase11SupplierTest` count (see the
correction note directly above this section).

**Verification for this cycle** (`DB_DATABASE=risk_test_p11_b9babae`, each
file run standalone and then all seven together):
`Phase11ComplianceTest` 16 passed/49 assertions,
`Phase11DemoSeederTest` 2 passed/17 assertions,
`Phase11ReportingGateTest` **18** passed/**110** assertions (two new tests),
`Phase11ScreensTest` **16** passed/**241** assertions (one new test),
`Phase11SupplierTest` **8** passed/**18** assertions (one new test),
`Phase11TrainingTest` 8 passed/31 assertions,
`Phase11WidgetsTest` (new file) **2** passed/**6** assertions;
all seven together — **70 passed, 472 assertions**.
`Phase1GovernanceTest` — 43 passed (230 assertions; the one pre-existing
failure §2 gap 5 named, `carried_to_occurrence_id...`, is now green — Phase
9's own concurrent work closed it, not this phase).
`BcmsRecordVisibilityTest` — 36 passed (253 assertions).
`Phase10IncidentTest` — 23 passed (55 assertions), read-only regression, not
edited. All three of those plus all seven `Phase11*`/`Phase11Widgets` files
run together in one combined pass: **118 passed, 580 assertions** for the
governance/visibility/incident trio, no regressions from this cycle's
changes.
Pint (`--test`): clean on every file touched or added this cycle
(`ClauseComplianceMatrixService.php`, `BoardPackService.php`,
`EvidencePackService.php`, `Phase11ReportingGateTest.php`,
`Phase11ComplianceTest.php` [already clean from §12], `Phase11ScreensTest.php`,
`Phase11SupplierTest.php`, `Phase11WidgetsTest.php`).
PHPStan (`--memory-limit=1G`), scoped to the three touched service files: **0
errors**.

### 14. ADR 0021 Amendment 1 — branch-scoped BCMS widgets (backend-engineer)

The KRI branch gap named at the end of §13 is ruled, not fixed as a bug:
`docs/adr/0021-...md` Amendment 1 — the seventeen resilience KRIs and the
maturity score are organisation-level **by definition** and stay
unscoped; criterion 5 was about DRILLS AND PLANS all along
(`plans/bcms/prompts/PHASE-11-training-reporting-compliance.md:114`), and
`docs/bcms/phase-11-spec.md` §6 criterion 5 is corrected to say so, quoting
the amendment verbatim with a pointer to it. This section is the
implementation, in the order the amendment specifies. No schema, no
`ObjectSourceMap`/graph change — two additive `node_column_kind` arms on the
existing widget engine, following the TPRM precedent rather than inventing a
second one.

**1. `WidgetQueryEngine::unitsUnderNodes()` — extracted, not duplicated.**
The `$unitIds` subquery inside `engagementsUnderNodes()` (business units
whose graph object is one of the nodes in scope, or hangs off one) is now a
standalone private method; `engagementsUnderNodes()` calls it. Same query,
same bindings, called from the same place — confirmed byte-for-byte
behaviour-preserving by running `tests/Feature/Tprm/TprmWidgetTest.php` (13
passed/94 assertions) and `TprmWidgetHqRenderTest.php` (1 passed/18
assertions) **before** touching anything BCMS-specific, and again
afterwards with the two new `applyScope()` arms and the two new
`WidgetSourceRegistry` sources in place (14 passed/112 assertions combined,
unedited files, unchanged).

**2. `applyScope()` — two new arms.** `business_unit_ref`: the row carries
its own `business_unit_id` directly (a plan) — `whereIn(column,
unitsUnderNodes())`. `bcms_definition_units`: the row carries a
`definition_id` (an exercise occurrence) — in scope when that definition's
own `business_unit_id` resolves into `unitsUnderNodes()`, via a
`bcms_exercise_definitions` subquery scoped to `organization_id` and
`deleted_at is null`. A corporate drill or a group-wide plan with no
business unit at all is unattributable and out of scope on every node —
`engagementsUnderNodes()`'s own documented rule for TPRM, restated for BCMS
rather than reinvented.

**3. `WidgetSourceRegistry` — two new sources.** `bcms_plans` (`Plan`,
`business_unit_id`/`business_unit_ref`, date `next_review_date`, permission
`bcms.plan.view`) and `bcms_exercise_occurrences` (`ExerciseOccurrence`,
`definition_id`/`bcms_definition_units`, date `scheduled_date`, permission
`bcms.exercise.view`). Every whitelisted column checked against
`database/schema/bcms-manifest.php` before being listed. `key_risk_indicators`
(already existing, TPRM/ERM's own source) is reused as-is for the one KRI
tile — no BCMS-specific KRI source was added, because none is needed: at
organisation scope `WidgetScope::isUnrestricted()` skips node filtering
entirely (all seventeen count), and on a branch node the existing
`node_ref`/`node_id` default arm excludes every BCMS KRI correctly, because
`node_id` is null on all of them (see point 5).

**4. `Database\Seeders\Bcms\BcmsWidgetSeeder`** — shaped exactly like
`TprmWidgetSeeder` (system rows, `organization_id` null, `TenantContext::
bypass()`, idempotent `updateOrCreate` on `code`), called from
`DatabaseSeeder` immediately after `BcmsReferenceSeeder` (§15b, mirroring
TPRM's own §13b placement — a widget names a source, and a source is only
meaningful once the tables behind it exist). Three widgets, not nine: a
branch drill calendar (`wg-bcms-drill-calendar`, register, upcoming
occurrences, `bcms_exercise_occurrences`), plan status
(`wg-bcms-plan-status`, donut, grouped by `status`, `bcms_plans`), and
**exactly one** resilience-KRI tile (`wg-bcms-resilience-kris`, kpi_tile,
`key_risk_indicators` filtered to `kri_code like 'BCMS-'`) named "Resilience
KRIs (organisation-wide)" whose description states plainly that the figure
cannot be attributed to a branch and is never repeated as a per-branch
number. Each widget's own description also states its organisation-level
row's behaviour (a corporate drill or a group plan appears at organisation
scope only), so a dashboard author reads the limitation on the tile itself
rather than discovering it from a support ticket.

**5. `ResilienceKriPublisher::createKri()`** — no logic change.
Docblock gained one sentence: `entity_id` and `risk_id` are left unset on
purpose (Amendment 1) — every resilience KRI is organisation-level by
definition, so it takes no node in the org graph and its widget tile always
shows the whole tenant's figure.

**6. `Phase11WidgetsTest.php` — rewritten to Amendment 1's own proof list**,
replacing the previous cycle's `forceFill(['node_id' => ...])` test (named
in §13 as exercising a row shape no product write path produces):
- `a_branch_node_shows_its_own_drills_and_its_child_units_but_not_another_branchs`
  — Kano, a Kano child unit, Lagos and one corporate drill with no business
  unit: Kano's node shows 2 (its own + the child's), Lagos shows 1 (its
  own), organisation scope shows 4 (all, including the corporate one).
- `a_branch_node_shows_its_own_plans_and_its_child_units_but_not_another_branchs`
  — the identical three-way shape for `bcms_plans`.
- `resilience_kris_show_none_on_a_branch_node_and_all_seventeen_at_organisation_scope`
  — after `adopt()`, a branch node counts 0 of the seventeen; organisation
  scope counts all 17.
- `a_viewer_without_bcms_exercise_view_gets_forbidden` — the source
  permission gate holds regardless of node.
- Two more, mirroring `TprmWidgetTest`'s own seeder smoke tests since no
  `DatabaseSeeder`-wide smoke test exists in this repository:
  `the_bcms_widget_seeder_ships_three_system_widgets_idempotently` (seeded
  twice, still exactly 3, system-owned) and
  `every_column_the_bcms_widget_seeder_names_is_on_its_sources_whitelist`.

**Verification.** `tests/Feature/Tprm/TprmWidgetTest.php` — 13 passed/94
assertions (unedited). `tests/Feature/Tprm/TprmWidgetHqRenderTest.php` — 1
passed/18 assertions (unedited). `tests/Feature/Widgets/` (all seven files,
run together with both TPRM widget files) — **79 passed, 511 assertions**,
no regressions from the `WidgetQueryEngine`/`WidgetSourceRegistry` changes.
`tests/Feature/Bcms/Phase11WidgetsTest.php` — **6 passed, 19 assertions**.
The six other `Phase11*Test.php` files, run together — **68 passed, 466
assertions**, unchanged from §13's own count (nothing in this section
touched any of those files). Pint (`--test`) clean on
`WidgetQueryEngine.php`, `WidgetSourceRegistry.php`,
`BcmsWidgetSeeder.php`, `DatabaseSeeder.php`, `ResilienceKriPublisher.php`,
`Phase11WidgetsTest.php`. PHPStan (`--memory-limit=1G`), scoped to the same
five app files: **0 errors**. No `bcms:verify-schema` drift check was run
against `bcms_incident_notifications`/`bcms_plan_activations` per the
coordinator's instruction to ignore it (Phase 10's, in progress).

**Freeze delta: still 0 tables, 0 columns, 0 indexes** (ADR 0021 §"Consequences"
and Amendment 1 both hold). No structural migration in this section.

### 15. QA re-gate #5 — two small defects (backend-engineer)

Both earlier gaps (§13, §14) closed and confirmed sound (267 green). Two
leftovers, both fixed.

**Defect 12 — a phpstan `parameter.phpDocType` on a variadic parameter,
caught only because this re-gate ran phpstan on test files (the previous
cycle's run was scoped to app files only, per its own handoff — an omission,
now corrected).** `Phase11WidgetsTest::makeUser(string ...$permissions)`
carried `@param list<string> $permissions` — the wrong docblock shape for a
variadic parameter, which PHPStan already types fully from the native
signature. Removed the redundant docblock rather than "fixing" it to a
still-approximate shape (`@param string ...$permissions` says nothing PHP's
own signature does not already say). `phpstan analyse --memory-limit=1G`
now run against BOTH the touched app files and the touched test files in
this cycle (15 files, 0 errors) — the gap this defect exposed.

**Defect 13 — the tiebreaker sweep (§13 defect 8) missed three reads.**
`ClauseComplianceMatrixService::scopeSufficiency()` and `policySufficiency()`
each ran their own `Programme::query()->orderByDesc('year')->first()` — no
id tiebreaker, unlike `build()`'s own identically-shaped read three lines
above it. `bcms_programmes` is unique on `(organization_id, year, name)`,
not year alone, so two different programmes in the same year (one
superseding the other mid-year) is legal data, not a fixture bug. Both
fixed to `->orderByDesc('year')->orderByDesc('id')->first()`, matching
`build()`'s own line exactly.

`ClauseComplianceMatrixService::exerciseProgrammeSufficiency()` and
`BoardPackService::exerciseCompletion()` each ran
`ExerciseProgramme::where('year', ...)->where('status', 'approved')->first()`
with **no ORDER BY at all** — worse than a single-column sort, since
`bcms_exercise_programmes` carries no unique constraint on
`(organization_id, year)` either (only `year`+`name`), so two approved
programmes for one year is exactly as legal as two `bcms_programmes` rows
for one year. Both fixed to `->orderByDesc('approved_at')->orderByDesc('id')
->first()` — "the current programme" is read as the one most recently
approved, tiebroken on id for a same-instant approval, and the two files
now state the identical rule so the board pack and the compliance matrix
cannot pick two different programmes for the same year.

Re-grepped both files for every `->first()`/`->latest()`/`->oldest()`/
`->sole()`/single-column `->orderBy()` after the fix. Two more hits, both
checked and left alone with the reasoning stated:
- `ClauseComplianceMatrixService::build()`'s own `ClauseRef::query()
  ->orderBy('standard')->orderBy('sort_order')->get()` — `bcms_clause_refs`
  is system-seeded, static reference content
  (`Database\Seeders\Bcms\Reference\ClauseRefs`), never written by any
  application code path (checked: `grep -rn "ClauseRef::create\|
  ClauseRef::updateOrCreate" app/ database/` outside seeders returns
  nothing). A tie on `(standard, sort_order)` is a content-authoring
  question for whoever edits the reference seeder, not a runtime race a test
  fixture can create — unlike every other read fixed in this section, which
  reads rows a bank's own users create.
- `$this->maturity->latest()` (`MaturityService::latest()`,
  `orderByDesc('assessed_at')` with no id tiebreaker) — a DIFFERENT file
  (`app/Services/Bcms/MaturityService.php`), out of this phase's boundary,
  already named as such in §13's handoff. Not touched again here; the ask
  was these two files.

**Test extension**, per the gate instruction, in
`Phase11ReportingGateTest::evidence_pack_and_board_pack_section_content_is_reproducible_across_two_calls`
rather than a sibling (the existing test already asserts exactly the
`===` comparison a new one would repeat): added a second `Programme` for
the same year (different name, `status = 'draft'`, so it does not
accidentally become "the" approved programme by coincidence) and two
`ExerciseProgramme` rows for the same year, both `status = 'approved'` and
both `approved_at` the exact same instant as the AAR/Finding tie already in
the fixture — so the fixture now exercises a genuine tie on all three of
this cycle's fixed reads, not just the two from §13. Passed on this run (2
assertions, unchanged assertion count — the fixture grew, the shape of the
proof did not).

**Verification.** `tests/Feature/Bcms/Phase11ReportingGateTest.php` (this
test only) — 1 passed/2 assertions. All seven `Phase11*`/`Phase11Widgets`
files together — **74 passed, 485 assertions**. `tests/Feature/Tprm/
TprmWidgetTest.php` + `TprmWidgetHqRenderTest.php` — 14 passed/112
assertions (unedited, re-run as a cheap safety check even though nothing in
this section touched `WidgetQueryEngine`/`WidgetSourceRegistry`). Pint
(`--test`) clean on all ten app files and five test files touched across
this whole phase-11 effort, re-run together. PHPStan
(`--memory-limit=1G`), the same fifteen files, **both app and test scoped
this time**: **0 errors**.

### 16. Code review #1 fix cycle — B3–B13, A3, A6, A7, A9, A10, A11, A12, A15 (backend-engineer)

Code review #1 rejected the phase with 17 blocking defects across training &
competency, supplier resilience and the management-review capture path — the
first gate this phase has faced past qa-engineer. All items in this
engineer's boundary are closed below, each with a test that failed against
the pre-fix code (proven by temporarily reverting the fix and re-running,
except where noted). **No schema change** (ADR 0021 continues to hold — the
freeze delta for this phase stays at zero tables, zero columns).

**B3 — `competency_assessed` no longer means "an assessor was named"; it
means "passed".** `TrainingComplianceService::recordOutcome()` and `assess()`
now set `competency_assessed = true` only when a score was recorded AND it
meets the curriculum's `pass_mark`. A fail is still a first-class record —
`score` and `assessor_id` (now always the acting user, see B4) are set
regardless of outcome — distinguishing "assessed and failed" from "not yet
assessed" by those two columns being non-null, not by `competency_assessed`.
`iso_clause_ref` is stamped only on a pass, since a fail never evidenced
clause 7.2. **The matrix's own read (`ClauseComplianceMatrixService`) is the
other engineer's file and out of this boundary** — this fix is the write
side and the `complianceRows()` read side in the same service file (below,
under B10/B11), which is this engineer's.

**B4 — the assessor is the acting user; there is no assessor field.**
`assessor_id` is removed from both `StoreBcmsTrainingRecordRequest` and
`AssessBcmsTrainingRecordRequest` — anything submitted under that name is
silently dropped by `FormRequest::validated()`, never read. `recordOutcome()`
takes the acting user's id as its `$actorId` parameter and uses it as the
assessor whenever a score is present; `assess(TrainingRecord $record, float
$score, int $actorId)` dropped its old `$assessorId` parameter for the same
reason. Both refuse self-assessment (actor === subject) and `assess()`
additionally refuses to re-assess a record that already carries a score or
an assessor — a new record is the correct path for a second attempt, not
overwriting the first one's result. `TrainingController` and
`database/seeders/Bcms/BcmsDemoSeeder.php` (both callers) updated to match
the new signatures — the demo seeder's own `recordTrainingOutcomeOnce()`
helper now takes an explicit `?int $actorId` instead of an `assessor_id` key
buried in the attributes array.
**Named gap, not fixed here:** `resources/js/Pages/Bcms/Training/
Compliance.jsx` still renders an assessor picker for both forms; the
backend now ignores whatever it submits, so the UI needs the picker removed
as a frontend follow-up — out of this boundary (no `resources/js` file is
in it).

**B5 — `next_due_date` is computed from `completed_at`, never from `now()`.**
Fixed in `recordOutcome()` (`linkOccurrenceParticipants()` already used
`completed_at` correctly). Test: a record completed 11 months ago on a
12-month curriculum is due in 1 month, not 12.

**B6 — the EMNS acknowledgement window in `ProgrammeService::
callTreeAndEmnsPerformance()` was signed, not absolute.** Under Carbon 3,
`$earlier->diffInMinutes($later)` is signed by default; a two-hour-late
acknowledgement produced a -120-minute diff, which `<= 15` counted as
"within 15 minutes" — a 100% figure for a review nobody should have trusted.
Fixed to `$alert->dispatched_at->diffInMinutes($recipient->acknowledged_at,
absolute: true)`. The method also used to load every alert recipient the
tenant has ever had; it is now scoped to `dispatched_at` within the review
period and read with `cursor()` rather than `get()`. Test (`Phase11ScreensTest`):
two recipients, one acknowledging within 5 minutes and one after 2 hours,
assert the rate is 50%, not 100%.

**B7 — `BCMS-VENDOR-ATTEST` now moves on its own, daily, not only when
someone records a new attestation.** New command
`App\Console\Commands\RecomputeBcmsVendorAttestationKri`
(`bcms:vendor-attestation-recompute`), tenant-iterating like the other BCMS
commands, scheduled `dailyAt('06:15')->withoutOverlapping()->onOneServer()`
in `routes/console.php`, right after the training-link sweep. A null reading
(no BCMS-critical vendor dependency yet) is skipped and counted separately in
the command's own summary line, never published as zero. Test: a vendor's
evidence with `next_due_at` tomorrow reads 100% today; travel three days
forward with nobody touching the record, re-run the command, the KRI reads
0% — evidence ageing out on its own moves the register.

**B10 — the training register: eager-loaded, computed once, paginated;
`MyResilienceService::training()` checks the one person directly.**
`TrainingComplianceService::complianceRows()` now eager-loads
`assessor:id,name` alongside `occurrence` (was lazy-loaded per row).
`TrainingController::compliance()` computes the full (viewer-scoped) row set
exactly once per request and derives both the paginated table (25/page,
in-memory slice — server-side pagination of the OUTPUT; the underlying
per-curriculum assignment resolution is still computed in full first, named
below as the boundary of what this fix covers) and the summary tiles from
that same set, rather than `summaryTiles()` running its own second pass and
each curriculum's `assigned_count` running a separate `assignedUsers($c)
->count()` query. `summaryTiles(array $rows, ?User $viewer)` takes the
already-computed rows and derives `assessed_this_year`/`attended_only_this_year`
scoped to exactly the user ids in those rows, so the tiles can never disagree
with the table beneath them (this also closes NDPA register §11.4 rule 5).
`MyResilienceService::training()` no longer calls `assignedUsers($curriculum)`
(which for the universal `BC-AWARE-ALL` curriculum loaded every active user
in the tenant) to test membership of ONE person — new
`TrainingComplianceService::isAssignedTo(TrainingCurriculum, User)` checks
the one person's own roles directly, and all of that person's training
records are fetched in one query instead of one per curriculum. Test
(`Phase11ScreensTest`): the compliance screen's query count does not grow
materially as the number of assessed records on it grows from 3 to 18
(methodology note: measured after a warm-up request, since a cold first
request boots lazy providers and warms the permission cache regardless of
data volume — a raw first-call-vs-second-call comparison would hide a real
N+1 in that noise, which is exactly what happened on the first attempt at
this test).
**Named boundary of this fix:** the register's per-curriculum assignment
resolution (`assignedUsers()`) still loads every role-holder into memory
before scoping/paginating — true query-level pagination across
heterogeneous, role-predicate-driven curricula would be a larger redesign
than this fix cycle's remit; what is fixed is the N+1 and the repeated
recomputation, both of which scaled with page views, and the screen now
transmits one page of rows rather than the whole tenant's.

**B11 — the training register and its assessor/subject picker are scoped to
the viewer's org hierarchy, per `docs/compliance/ndpa-register.md` §11.4's
five numbered rules.** `TrainingComplianceService::complianceRows()` takes
an optional `?User $viewer`; a whole-estate viewer (`rcsa_scope.all_units`,
or no viewer — a console/queue context) sees everyone, exactly as before.
Every other viewer sees only: (1) people whose `business_unit_id` is in
their own unit subtree (`App\Support\Rcsa\RcsaScope::unitIdsFor()`, the same
resolver `ScopedToOrgHierarchy` uses — one resolver, per rule 1); (2)
themselves, regardless of unit (rule 3's named-subject arm); (3) a row they
themselves assessed, even where the subject sits outside their own units
(rule 3's named-assessor arm — they wrote that score). **Rule 2, the
INVERTED null arm**: a person with NO business unit is visible ONLY to a
whole-estate viewer, never to a unit-scoped one — the opposite of
`ScopedToOrgHierarchy`'s own null arm for BCMS *records* (a no-unit person is
not organisation-level content the way a group plan is; publishing
everyone's unplaced-person scores to every unit-scoped viewer is exactly the
leak this rule closes). `TrainingController::compliance()` builds a
`scopedUsersQuery()` for the `users` (subject/assessor picker) and
`departments` props using the identical rule (rule 4 — the picker leaks
unless scoped the same way the register is). Tests (`Phase11ScreensTest`):
two business units, an employee in each, an unassigned employee, and the
viewer's own champion role — the champion sees their own unit's employee and
themselves, not the other unit's employee, not the unassigned employee, and
the picker is scoped identically; a whole-estate examiner sees everyone; a
second test proves the assessor exception (a Kano-scoped assessor who
recorded a Lagos subject's outcome still sees that one row).

**B12 — an approved management review's clause 9.2 block is locked, and
only the five named `internal_audit` keys are ever stored.**
`ProgrammeController::captureReviewInputs()` refuses capture outright once
`$review->status === 'approved'` (flash message, not a 403 — this is the
lifecycle-rule shape, same as every other `InvalidArgumentException` catch in
this controller). New `App\Http\Requests\Bcms\CaptureBcmsReviewInputsRequest`
validates `internal_audit.{report_reference,date,auditor,
independence_statement,conclusion}` plus `interested_party_feedback` and
`context_changes` — `(array) $request->input('internal_audit')` used to
write an unvalidated, arbitrarily-keyed blob straight into
`bcms_management_reviews.inputs` json; `$request->validated('internal_audit')`
now means anything else submitted (a probe, a mistake, a malicious key) is
dropped before it ever reaches the snapshot. Tests: capturing against an
approved review is refused and the original block is untouched; a submission
carrying an extra key stores only the five named ones.

**B13 — `evidence_document_id` is tenant-bound.**
`StoreBcmsVendorAttestationRequest`'s `evidence_document_id` was a bare
`nullable integer` — another tenant's `tp_documents` row was accepted
outright, and a nonexistent id 500'd instead of failing validation. Now
`Rule::exists('tp_documents', 'id')->where('organization_id', $organizationId)`.
Test: a document belonging to a second tenant, and a nonexistent id, both
produce a clean validation error rather than a cross-tenant write or a 500.

**A3 — `ModuleSections::fake()` refuses to run outside a test.** Guarded on
`getenv('APP_ENV') !== 'testing'`, not `app()->runningUnitTests()` as named
in the review comment (`APP_RUNNING_UNIT_TESTS` is not a variable this
repository's `phpunit.xml` sets) and not `app()->environment()` either (a
real test calls `fake()` from its own `setUp()` **before**
`parent::setUp()` boots the application — verified empirically: swapping in
`app()->environment('testing')` throws `BindingResolutionException` because
the container is not yet resolvable at that point, which is exactly the
legitimate call site this guard must not break). `getenv('APP_ENV')` is a
raw process environment variable, set by PHPUnit before Laravel ever boots,
so it is safe to read this early. Test: temporarily setting `APP_ENV=production`
via `putenv()` and calling `fake()` throws `RuntimeException`; restored in a
`finally`.

**A6 — vendor participation matches email case-insensitively on both sides;
the continuity view is computed once per page; a BCP-test tie is
tiebroken.** `SupplierResilienceController::vendorParticipation()` built a
`tp_contacts.email → third_party_id` map keyed on the raw email, then looked
it up with `$vendorEmails->get($contact->email)` — a case-SENSITIVE PHP
array-key lookup layered on top of MariaDB's own case-INSENSITIVE
`utf8mb4_unicode_ci` collation (which already matched the `whereIn` correctly),
so a bank-side email differing only in case from its TPRM counterpart
(`Musa@Vendor.com` vs `musa@vendor.com`) missed the lookup and rendered
`vendor_third_party_id = 0` — a named, real person attributed to a vendor
that does not exist. Both the map's keys and the lookup key now go through a
`normaliseEmail()` (`strtolower(trim(...))`) applied identically on both
sides. `SupplierResilienceController::index()` now computes `continuityView()`
once and passes it into `chaseList()` (which gained an optional
`?array $continuityView` parameter, `null` preserving the original one-call
convenience for every other caller) — it was previously rebuilt a second
time inside `chaseList()` on every page render.
`SupplierResilienceService::engagementRow()`'s `BcpTest::latest('test_date')`
had no id tiebreaker; two tests dated the same day left the database free to
return either side of the tie — now `->orderByDesc('test_date')
->orderByDesc('id')`. Test: an email differing only in case from its TPRM
counterpart still resolves to the real vendor id, not 0 (proven failing
against the pre-fix lookup by temporarily reverting it).

**A7 — checked, already correct.** `BcpTestRecorder::record()` already
checks `$actor->can('tprm.edit')` directly, not `can('update', $engagement)`
as the review comment describes. `grep -rn "can('update', \$engagement)"
app/` returns nothing. No change made; the existing test
(`recording_an_attestation_requires_tprm_edit_in_addition_to_bcms_report_view`)
already pins the correct behaviour.

**A9 — `bcms:training-link-exercises` gained `onOneServer()`.** The create
it guards was already idempotent (`linkOccurrenceParticipants()` checks
curriculum/user/occurrence before every write); the schedule line in
`routes/console.php` now reads
`->dailyAt('06:00')->withoutOverlapping()->onOneServer()`.

**A10 — a retired curriculum and a future completion date are both
refused; `assess()` refuses a curriculum that does not require assessment.**
`StoreBcmsTrainingRecordRequest`'s `curriculum_id` rule gained
`->where('is_active', true)` (nested inside the existing org-scope closure,
not chained as a second top-level `->where()` — chaining a scalar `->where()`
after a closure `->where()` on `Rule::exists()` mixes the query-callback and
string-serialised-where code paths and is fragile; nesting avoids it);
`completed_at` gained `before_or_equal:today`.
`TrainingComplianceService::assess()` now refuses when
`! $curriculum->requires_assessment` — `BC-AWARE-ALL` has no pass mark to
score against, and assessing it anyway would produce a "competency" reading
for a curriculum that only ever claimed attendance. Tests: a retired
curriculum and a future date each produce their own validation error, a
valid submission does not; `assess()` on a `BC-AWARE-ALL` record throws.

**A11 — `showReview()` no longer serves a draft to a `bcms.report.view`-only
holder.** The method's own comment always said `bcms.report.view` reaches an
APPROVED review; nothing enforced it. Now `abort(403, …)` unless the review
is approved OR the viewer holds `bcms.programme.manage`/`bcms.programme.approve`.
Test: a report-view-only examiner gets 403 on a draft; a programme manager
does not.

**A12 — `recordVendorAttestation()` no longer silently adopts all
seventeen resilience KRIs.** It used to call the full `adopt()` when
`BCMS-VENDOR-ATTEST` was missing — creating every other definition as a side
effect of recording one vendor's attestation, which is exactly the "never
adopt silently" rule `bcms:kri:adopt` (deliberately unscheduled, explicit-only)
exists to protect. It now creates only its own definition
(`ResilienceKris::find('BCMS-VENDOR-ATTEST')` → `createKri()`), and logs a
warning and returns (never throws — a missing register must not block a
vendor's attestation from being recorded) if even that definition cannot be
found. **Runbook note, as the review asked**: a tenant that never runs
`bcms:kri:adopt` will have `BCMS-VENDOR-ATTEST` created the first time a
vendor attestation is recorded, and the other sixteen definitions only when
an administrator runs the adopt command — the KRI register is therefore not
guaranteed complete until that command has run at least once, and
`ResilienceKriPublisher::status()`'s "n of 17 not linked" line is what
surfaces that on the compliance screen. Test: recording an attestation
against an empty register creates exactly one `BCMS-%` row, not seventeen
(proven failing against the pre-fix `adopt()` call by temporarily reverting
it).
**Touched outside the literal file boundary list, named per the
instruction:** `app/Services/Bcms/ResilienceKriPublisher.php` is not in this
engineer's enumerated boundary, but A12 is explicitly this engineer's
defect and `recordVendorAttestation()` is defined only there — no other
location could carry the fix.

**A15 — `findings_raised` is validated.** `StoreBcmsVendorAttestationRequest`
gained `'findings_raised.*' => ['string', 'max:500']` alongside the existing
`'findings_raised' => ['nullable', 'array']`.

**Touched outside the literal file boundary list, named per the
instruction (second instance):** `database/seeders/Bcms/BcmsDemoSeeder.php`
is not enumerated in this engineer's boundary, but B4's signature change to
`TrainingComplianceService::recordOutcome()` (assessor moved from an
attributes-array key to an explicit actor parameter) breaks its one caller
in that file without a matching update — `recordTrainingOutcomeOnce()`'s
`assessor_id` key would have been silently ignored, leaving every demo
training record's `assessor_id` null. Updated to pass the assessor as the
new `$actorId` parameter; the seeder's own `Phase11DemoSeederTest` (already
in this engineer's boundary) re-run clean.

**Verification for this cycle.** All four owned files together —
**52 passed, 365 assertions**
(`Phase11TrainingTest` 14/47, `Phase11SupplierTest` 12/32,
`Phase11ScreensTest` 24/269, `Phase11DemoSeederTest` 2/17).
`Phase11ReportingGateTest.php` (read-only regression, the other engineer's
file, not edited) — **29 passed, 139 assertions**, confirming the B6/B12
`ProgrammeService`/`ProgrammeController` changes and the B3 write-side
`competency_assessed` semantics change do not disturb the compliance
matrix/board-pack/evidence-pack suite. `BcmsRecordVisibilityTest.php` (also
not edited) — **36 passed, 253 assertions**. `grep -rl "BcpTestRecorder"
tests/Feature/Tprm/` returns nothing — no TPRM-side test exercises it
directly; A7's coverage lives entirely in `Phase11SupplierTest`. Pint
(`--test`) clean on all nineteen touched/added app and test files. PHPStan
(`--memory-limit=1G`), the same nineteen files: **0 errors**.
Every fix above has a test that failed against the pre-fix code, proven by
temporarily reverting the specific line(s) and re-running — the one
exception named is A7 (already correct, no fix to prove) and A10's second
assertion set (a straightforward guard clause whose correctness follows
directly from the exception type asserted; not separately re-verified
against a reverted state given the volume of the cycle).

### 17. B10 follow-up — the "heavy amber" banner moved server-side (backend-engineer)

Found by the frontend engineer while wiring the B10/B11 screen changes:
`Compliance.jsx`'s "more than half of this mandatory curriculum's people are
attended but not yet assessed" banner (`heavyAmberCurricula`) computed its
ratio client-side over `rows` — which B10 made ONE PAGE of the register, not
the whole curriculum. A curriculum whose true, register-wide ratio crossed
50% could read under 50% on whichever page happened to be showing, and vice
versa.

**Fix.** New `TrainingComplianceService::attendedNotAssessedByCurriculum(array
$rows)` — takes the same already-computed, viewer-scoped (B11) full row set
`summaryTiles()` already takes, groups by `curriculum_id`, and for each of
the six curricula returns `attended_not_assessed_count`, `assessed_count`,
`total` and a `heavy_amber` boolean. **The threshold rule is `Compliance.jsx`'s
own, moved verbatim, not reinvented**: mandatory, at least one row, and more
than half of the curriculum's rows are "attended (`completed_at` set), not
yet assessed" (`competency` present — i.e. the curriculum requires
assessment at all — and `competency.assessed` false). `TrainingController::
compliance()` calls it once alongside `summaryTiles()`, over the SAME
`$allRows`, and folds the three fields onto each entry of the existing
`curricula` prop (not a new top-level prop) — the same place `assigned_count`
already lives, computed the same way.

**Prop shape, for the frontend engineer.** No new prop. Each object in the
existing `curricula` array now additionally carries:
```
attended_not_assessed_count: number   // rows attended but not yet assessed
assessed_count: number                // rows with an assessment recorded (pass or fail, per B3)
heavy_amber: boolean                  // is_mandatory && total > 0 && attended_not_assessed_count / total > 0.5
```
`Compliance.jsx`'s `heavyAmberCurricula` becomes `curricula.filter(c =>
c.heavy_amber)` — the ratio, mandatory check and page-independence are all
already applied server-side; the banner text itself (`{c.name}`) is
unchanged. **`resources/js/Pages/Bcms/Training/Compliance.jsx` was not
edited**, per instruction.

**Tests** (`Phase11TrainingTest`): `the_heavy_amber_flag_reflects_the_whole_scoped_register_not_one_page`
— 30 synthetic rows for one mandatory curriculum, the first 25 alone reading
48% (would not flag under a page-scoped computation, asserted as a sanity
check) and the full 30 reading 56.7% (flagged) — proves the method reads the
whole array handed to it, never a slice.
`the_heavy_amber_flag_is_identical_across_pages_of_the_same_register` — the
same 30-row fixture built for real through `recordOutcome()`, requested over
HTTP at `page=1` and `page=2`: the `curricula` prop's entry for the
curriculum is byte-identical across both responses, proving the figure the
screen actually receives does not depend on which page was requested.

**Verification.** `Phase11TrainingTest` — **16 passed, 57 assertions**
(was 14/47; two new tests, 10 new assertions).
`Phase11ScreensTest` — 24 passed, 269 assertions, unchanged, re-run to
confirm the `curricula` prop shape change did not disturb it (no test in
that file asserts the exact key set on a `curricula` entry). Pint clean and
PHPStan 0 errors on `TrainingComplianceService.php`, `TrainingController.php`,
`Phase11TrainingTest.php`.
**Database note:** this section's tests ran against the shared MariaDB
10.4 server at `127.0.0.1:3306` (`risk_test_p11b_b9babae`, recreated there)
— the private isolated instance used for the rest of this fix cycle
(`docs/bcms/phase-11-notes.md` §16's own environment note) was stopped
before this follow-up began.

### 18. Code review #2 fix cycle — R2, R5 + A1, A7, A8, A10, A11 (backend-engineer)

Code review #2 rejected the phase again, R1-R6. This engineer's share: R2,
R5, plus advisories A1, A7, A8, A10, A11. The other engineer took R1
(`WidgetQueryEngine`/`BindsToVisibleRecord`), R3 (packs), R4
(`ClauseComplianceMatrixService`, which calls this engineer's
`assignedUsers()` — its signature was not touched), R6, in parallel. No
schema change.

**R2 — the register's unit scope (B11) did not govern its WRITE endpoints.**
`StoreBcmsTrainingRecordRequest` checked `user_id` against the tenant only;
`training-records/{record}/assess` bound `TrainingRecord` by id under
tenancy alone. A unit-scoped `bcms.training.manage` holder (`risk-manager`
lacks `rcsa_scope.all_units`) could record or assess ANYONE in the bank, and
NDPA register §11.4 rule 3's assessor exception would then silently ADD that
person to their register on the next read — exactly what rule 3 rules out
("Nobody else is added"). Fixed with ONE shared resolver, so the read and
write sides cannot drift: `TrainingComplianceService::rowVisibleToViewer()`
was refactored onto a new private `personInScope()` core (rules 1-2 and the
self half of rule 3); a new public `subjectVisibleToActor(?User $actor, User
$subject)` calls the SAME core, deliberately WITHOUT the assessor exception
(that is a read-time allowance for a row that already exists, never a
licence to create one). Both `StoreBcmsTrainingRecordRequest::authorize()`
and `AssessBcmsTrainingRecordRequest::authorize()` now call it once they can
resolve a real subject (deferring to `rules()` for a missing/invalid
`user_id`, so the 422 for that stays a 422). Tests
(`Phase11TrainingTest`): a Kano-scoped manager recording and a Kano-scoped
manager assessing a Lagos person are both refused (403), and the Lagos
person does not appear in the Kano manager's own `complianceRows()`
afterwards; a whole-estate manager can still do both.

**R5 — `complianceRows()` picked the assessed record over an unordered
collection.** `$userRecords->first(fn ($r) => $r->assessor_id !== null ||
$r->score !== null)` ran over a collection built from a query with NO
`ORDER BY` — B4 made a re-sit (fail, then a later pass) the normal path, so
which record won the "assessed" slot was whatever order the database
happened to return, not the most recent attempt. Fixed to `completed_at
DESC, id DESC` for both `$assessed` and `$latest` (which had the same gap,
`sortByDesc` on `completed_at` alone). Test: a fail and a pass recorded at
the exact same instant (so only the `id` tiebreaker distinguishes them,
proving the fix cannot pass on `completed_at` alone) — the later (higher-id)
pass wins, not the fail.

**A1 — the approved-review lock moved into `ProgrammeService::
captureReviewInputs()` itself**, not only the controller that calls it —
every caller now shares one rule rather than the controller carrying its own
copy that a future caller (a console command, a job) could bypass. The
controller's own ad-hoc `if` check was removed in favour of a `try/catch` on
the service's `InvalidArgumentException`, the same shape every other
lifecycle rule in that controller already uses.

**A7 — `ProgrammeService`'s year bounds truncated `$yearEnd` to a bare
date.** `now()->endOfYear()->toDateString()` produces `'2026-12-31'`, which
`whereBetween` on a DATETIME column reads as midnight — an incident (or
alert, DR test, BIA approval) detected/dispatched/tested on 31 December
after 00:00:00 fell outside "this year" for every review captured that day.
Fixed to `toDateTimeString()` on both bounds (`'2026-12-31 23:59:59'` for
the end) — confirmed safe against the DATE-cast columns in the same set of
queries (`scheduled_date`, `test_date`) too, since MariaDB widens a DATE
column to midnight for the comparison. Test: an incident detected two hours
before midnight on 31 December is still counted in `captureReviewInputs()`'s
`incidents.count` for that year.

**A8 — `next_due_at` is optional on `tp_bcp_tests`, and a test with none
counted as current for ever.** `BcpTest::isOverdue()` returns `false` when
`next_due_at` is null (it has not passed a date that was never set), so
`chaseList()`/`attestationRate()` treated "no due date on file" identically
to "genuinely current". Ruled or, not found in the spec, per this
codebase's own repeated pattern (§5's "honest red over a fabricated green",
`NoFabricatedNumbersTest`, the null-vs-zero KRI rule): evidence with no
expiry cannot be confirmed current, so a missing `next_due_at` is now
treated the same as overdue for both chase-list membership and the
attestation rate's numerator — via a new private `testIsCurrent()` — with
its own reason text ("has no next-due date on file and cannot be confirmed
current") distinguishing it from a genuinely lapsed test on the chase list.
Test: a vendor with one BCP test and no `next_due_at` is on the chase list
with that reason, and the attestation rate over that one vendor is 0%, not
100%.

**A10 — My Resilience showed a failed assessment as "not assessed".**
`competency_assessed` means "passed" (B3); a real, failed record — score,
assessor, date, all present, just below the pass mark — rendered
`competency_assessed: false`, indistinguishable from never having been
assessed at all. `MyResilienceService::training()` now also ships `assessed`
(an assessment happened, pass or fail) and `failed` (it happened and did not
pass) alongside the existing key — additive, never a silent rename. Also
picked up the same R5 ordering gap locally (`orderByDesc('completed_at')`
alone, no id tiebreaker) and added `orderByDesc('id')` to match. **Prop
shape, for the frontend, same convention as the B10 follow-up — no `Index.jsx`
edit made:** each `training` row gains
```
assessed: boolean   // an assessment happened, pass or fail
failed: boolean      // assessed === true && competency_assessed === false
```
Test: a failed record (score below pass mark, assessor and date set) renders
`competency_assessed: false, assessed: true, failed: true`.

**A11 — a duplicated B13 comment block in `StoreBcmsVendorAttestationRequest`**
(the same three lines pasted twice above `evidence_document_id`) removed;
no behavioural change.

**Verification.** All four owned files together — **60 passed, 409
assertions** (`Phase11TrainingTest` 19/69, `Phase11SupplierTest` 13/35,
`Phase11ScreensTest` 26/287 including `Phase11DemoSeederTest`'s own 2/17
folded into the same run). `Phase11ReportingGateTest.php` (read-only,
other engineer's) — **29 passed, 139 assertions**, including its own
cross-tenant 404 tests for `assess`/`storeAttestation`/`showReview`, unaffected
by R2 (tenancy 404s before the new authorize() check ever runs).
`BcmsRecordVisibilityTest.php` (read-only) — **36 passed, 253 assertions**.
Every fix has a test that failed against the pre-fix code, proven by
temporarily reverting the specific change and re-running (R2, R5, A7 all
confirmed failing pre-fix; A1's mechanism is a straightforward guard-clause
move already covered by B12's existing tests plus A1 not independently
re-verified against a reverted state; A10 and A8's new prop/reason keys did
not exist pre-fix, so their tests are self-evidently failing beforehand).
Pint clean and PHPStan (`--memory-limit=1G`) 0 errors on all eleven
touched/added app and test files (one genuine PHPStan finding fixed along
the way: a `?->` nullsafe combined with `??` on `completed_at->timestamp`,
replaced with an explicit `=== null` ternary — both for the linter and
because `completed_at` is genuinely nullable at runtime, via
`linkOccurrenceParticipants()`'s own `actual_end ?? scheduled_date` path).
**Database:** this cycle ran against the shared MariaDB 10.4 server at
`127.0.0.1:3306` (`risk_test_p11b_b9babae`, recreated there per §17's own
database note).

## Compliance ruling — PIRs and clause 8.6

**Agent:** compliance-analyst · **Date:** 2026-09-23 · **Asked by:** code review of
`ClauseComplianceMatrixService::evaluationSufficiency()`, which today is
`existsCheck(Aar::query()->where('status', 'final'))` and so goes green on any
finalised AAR, including a post-incident review (PIR) on its own.

### The clause text, checked rather than remembered

The brief pointed at "§8.6 d)/e)" as quoted in the repo. **The repo quotes no
lettered 8.6 wording anywhere.** `Reference\ClauseRefs` paraphrases it ("…at
planned intervals and after a change or a disruption"), and
`docs/compliance/iso22301-clause-map.md:79` lists only "plan review dates; exercise
outcomes". I could not read the published ISO 22301:2019 text, so its item
lettering is **not verified**, and this ruling does not depend on it. I did read the
**ISO/DIS 22301:2019** text, where this clause is numbered 9.1.2 "Evaluation of
business continuity plans, procedures and capabilities". The published standard
moved it to 8.6. It reads:

> "These evaluations shall be undertaken through periodic reviews, analysis,
> exercises, tests, **post-incident reports** and performance evaluations. […] The
> organization shall conduct evaluations at planned intervals **after an incident or
> activation** and when significant changes occur […]"

Secondary sources on the published 8.6 agree on both points (evaluation through
review, exercises and post-incident analysis; carried out at planned intervals and
after an incident). So a post-incident report is a **named input to evaluation**, and
"after an incident or activation" is **one of the three triggers** that make an
evaluation owed.

### Ruling: (c). A PIR is required evidence for one arm of 8.6, and it can never stand in for the other

- **Not (a).** Excluding PIRs would misstate the clause. After a real disruption,
  8.6 requires an evaluation, and the PIR is that evaluation. If the matrix ignored
  PIRs, it could not show the one 8.6 obligation a real incident creates.
- **Not (b) as worded.** "A supplementary input" makes the PIR optional credit. The
  clause makes it an **obligation** once a real incident has closed. So a missing
  PIR must be able to hold 8.6 back.
- **Consistent with ADR 0020 §1.** That ruling protects the **exercise programme
  (8.5)**, which is the baseline an examiner measures testing delivery against. It
  still holds in full: a PIR never counts toward the planned-interval arm below.
  In an incident, reality supplied one scenario for whichever processes it hit. That
  is not a planned evaluation of every Tier-1 process, and counting it would inflate
  the testing baseline in exactly the way ADR 0020 refused. **The PIR's stamp stays
  `iso22320.incident_response`.** An artefact's `iso_clause_ref` records what the
  artefact *is*. A matrix row records what it is *evidence for*, and one artefact may
  feed several rows, just as an approved BIA feeds both 8.2.2 and 22317. **No
  restamping and no new clause ref.**

**The rule `evaluationSufficiency()` must implement, in one sentence:**

> **8.6 is red while there is no active Tier-1 process, or while `LadderAdvisor` would raise `tier1_never_drilled` or `tier1_stale` for any active Tier-1 process (read through `coverageMatrix()` / the same test `tierOneOnlyWalkedThrough()` applies, so the matrix cell and the ladder warning can never disagree); otherwise amber while any incident with `status = closed` and `is_exercise = false` has no `bcms_aars` row with that `incident_id` and `status = final`; otherwise green — a PIR can hold 8.6 at amber but can never lift it, and `last_evidenced` is never a PIR's date.**

Notes on the rule, so nobody has to ask twice:

1. **Red, amber, green.** The planned-interval arm is the capability actually being
   tested. If it fails, the clause is not evidenced at all: red. If that arm holds
   but a real incident has closed with no finalised PIR, the clause is part-evidenced
   and one owed evaluation is outstanding: amber. The artefact sentence **names the
   incident references**, so an examiner is not left to find them.
2. **No Tier-1 process means red, not green.** This matches `raciSufficiency()` and
   `strategySufficiency()`. A coverage rule over zero processes is vacuously true,
   and a vacuous green is a fabricated one.
3. **"Tier 1" is `criticality_tier = 1` and `status = active`**, which is
   `LadderAdvisor`'s own definition. `is_critical_service` is not added here: two
   definitions of "Tier 1" in one module is how the advisor and the matrix come to
   disagree. If the bank wants critical services in this rule too, change it in
   `LadderAdvisor`, in one place.
4. **The PIR arm counts `closed` incidents only.** `cancelled` is excluded, and so is
   `is_exercise = true` (ADR 0020 §4). No grace period after closure is invented.
   The PIR follows stand-down (`IncidentService::standDown()` does not require one),
   so a closed incident with no PIR is a real, reachable state. The honest amber is
   "evaluation owed, not yet done".
5. **`last_evidenced` comes from the exercise arm only**: the latest drill-or-higher
   date across Tier-1 rows. This is the same reasoning §13 defect 9 applied to 8.5. A
   PIR's date next to a red 8.6 cell would imply a recent evaluation where the
   planned-interval arm is failing.
6. **Plan review dates** appear in spec §3.2 row 14's source column but not in its
   rule. They are the "documentation" half of 8.6 and are **not** part of this
   ruling. That gap is recorded below for the architect, not added to the engineer's
   in-flight work.

### Which rows a PIR legitimately evidences, and which it never does

| Row | PIR counts? | How |
|---|---|---|
| `iso22301.8.6` | **Yes: the after-incident arm only** | As ruled above. It can hold the row at amber and never lift it |
| `iso22320.incident_response` | **Yes** | Its own stamp (ADR 0020 §1). `cbn.rcf.incident_response` inherits it through the §3.4 crosswalk |
| `iso22301.10.1.nonconformity` / `.corrective_action` | **Through its findings, not the PIR row** | `FindingSource::Incident` findings and their corrective actions are what `nonconformitySufficiency()` reads, and that is unchanged |
| `iso22301.9.3.inputs` | **Yes, in principle** | DIS 9.3.2 lists "lessons learned and actions arising from near-misses and disruptions". `captureReviewInputs()`'s `incidents` block carries only notification timeliness, so this input is **not carried today** (gap 4 below) |
| `iso22301.8.5.programme` / `.exercise` / `.report` | **Never** | ADR 0020 §1. `aarSufficiency()` is already correct (§12 defect 7, §13 defect 9) |
| `iso22398.exercise_design` / `.exercise_ladder` / `.exercise_evaluation` | **Never** | ISO 22398 is the exercise guideline |
| `cbn.rcf.cyber_drills`, `cbn.open_banking.failover`, `cbn.open_banking.dr_test` | **Never** | Crosswalked to 8.5.report and 8.4.5. A real invocation is never a DR test (ADR 0020 §4) |
| Maturity scoring, `iso22301.10.2` (carried actions) | **Never** | ADR 0020 §1 (`MaturityService`) and §4 (`carried_to_occurrence_id` stays the exercise engine's) |

### Three defects found while checking this, all the same family and all in `ClauseComplianceMatrixService::sufficiency()`

1. **`Iso22398_evaluation` has the defect code review found on 8.6, on an exercise-guideline row.**
   It is `existsCheck(Aar::query()->where('status', 'final'))`, so a finalised PIR
   alone turns ISO 22398 "Exercise evaluation and improvement" green. That row ships
   in the `iso22301` and `board` export packs. It must be scoped to
   `whereNotNull('occurrence_id')`. This breaches ADR 0020 §1 directly; **fix it in
   the same change as 8.6**.
2. **`Iso22320_incident` and `Iso22361_crisis` count crisis-simulation incidents.**
   Both `existsCheck`s read `Incident::query()` with no `is_exercise = false`. So a
   tabletop that declares a practice incident turns `iso22320.incident_response`
   green, and through the crosswalk it also turns **`cbn.rcf.incident_response`**
   green. That is a CBN regulatory row going green on a drill. ADR 0020 §4 requires
   `is_exercise = false` "in every aggregate". **Fix in the same cycle.**
3. **`competenceSufficiency()` (7.2, a mandatory record) counts a failed assessment toward green.**
   It counts `competency_assessed = true` rows regardless of `score` against
   `pass_mark`. `docs/bcms/screens/training-compliance.md` §3 (lines 127–131) says a below-pass-mark
   assessment is a valid record but "**not** counted as green on the compliance
   matrix's 7.2 sufficiency check, which requires `score >= pass_mark`". ISO 22301
   7.2 b) requires that persons *are* competent, and a failed assessment is evidence
   that one is not. The green artefact sentence is also misleading: "each with an
   assessor, a date and a result" is literally true of a failed result. Owner
   backend-engineer; the gate holds it.

### Gaps recorded, not ruled here

1. `docs/compliance/iso22301-clause-map.md:79` (the 8.6 row) should name PIRs and the
   coverage rule as its artefacts. It is owed by compliance-analyst on the next pass.
   This task was limited to two files.
2. The published ISO 22301:2019 8.6 item lettering has not been verified from a
   primary source. The ruling rests on content that both the DIS and the secondary
   sources agree on.
3. The documentation half of 8.6 (plan review currency) is in spec §3.2's source
   column but not in any rule. It is for the architect: an amber condition for
   approved plans past `next_review_date` would be the natural shape, and it is not
   invented here.
4. PIR outputs are not a `captureReviewInputs()` block. They are a legitimate 9.3
   input with no carrier today. This is a later-phase addition, not a Phase 11
   blocker.

**Sources:** ISO/DIS 22301:2019 clauses 8.5, 9.1.2 and 9.3.2 (read from the DRI Canada
copy, `dri.ca/docs/ISO_DIS_22301_(E).pdf`). Secondary descriptions of the published
8.6: iso-docs.com (clause 8.6 article) and GLOCERT's ISO 22301 requirements overview.
The repo files read are named inline.

## 16. Code review #1, matrix/packs/widgets share (backend-engineer)

**Branch:** `integration/bcms-remaining` (uncommitted). **DB:**
`risk_test_p11_b9babae` (re-migrated first, per instruction), reached over a
second, private MariaDB 10.4.28 instance on port 3309 (see the environment
note at the end of this section — the shared instance on 3306 was down and
not restartable without root). **Boundary:** `app/Services/Widgets/{WidgetQueryEngine,
WidgetSourceRegistry}.php`, `app/Services/Bcms/Compliance/*`,
`app/Services/Bcms/Reports/*`, `app/Http/Controllers/Bcms/{ComplianceController,
EvidencePackController,BoardPackController}.php` + a new form request,
`app/Services/Bcms/MaturityService.php` (A1 only), `database/seeders/Bcms/
BcmsWidgetSeeder.php`, `resources/views/reports/pdf/bcms-*.blade.php`,
`tests/Feature/Bcms/Phase11{Widgets,Compliance,ReportingGate}Test.php`, this
file — extended mid-task to `resources/js/Pages/Bcms/Reports/BoardPack.jsx`
for three live-browser text defects. A second backend engineer worked
`TrainingComplianceService`/`MyResilienceService`/`ProgrammeService`/
`SupplierResilience*`/`TrainingController`/`ProgrammeController` and
`Phase11TrainingTest`/`Phase11SupplierTest`/`Phase11ScreensTest` in parallel
on the same tree — none of those files were touched here, and their own B10/
B11/B3-write-side/B4/B5/B6/B7/B12/B13/A6/A10 work landed and is green
alongside this share's own changes in the final combined run below.

### B1 — `WidgetQueryEngine::baseQuery()` never applied ADR 0017 record
visibility

A Kano-assigned user with `bcms.plan.view` saw every branch's plans and
drills through a widget — node scope (`applyScope()`, which branch a widget
is PLACED ON) and record visibility (which rows THIS USER may see at all)
are different questions, and only the first was ever applied.

Fixed with a new `applyVisibility()` in `WidgetQueryEngine::baseQuery()`,
mirroring `BindsToVisibleRecord::constrainToVisibleRecord()`'s own two rules
— NOT calling that trait method directly, because it reads `Auth::user()`
implicitly and a widget's context user is not always the request's
authenticated user:

- **ANCHOR** — the model `implements ScopedToOrgHierarchyContract` (`Plan`):
  its own `scopeVisibleTo()` is the whole predicate.
- **DERIVED** — the model declares `orgAnchorPath()` (`ExerciseOccurrence`,
  anchored on its `definition`): walk the dot path to the anchor via a new
  `constrainAnchorPath()` and apply `scopeVisibleTo()` there.

Every other source (every TPRM model, every ERM model, `KeyRiskIndicator`)
implements neither, so both branches are unconditional no-ops for them —
confirmed by running `TprmWidgetTest`/`TprmWidgetHqRenderTest` **before and
after** this change: 14 passed/112 assertions, identical, unedited.

`Phase11WidgetsTest.php` rewritten with UNIT-ASSIGNED users
(`business_unit_user` rows, `includes_descendants`), replacing the previous
cycle's single unassigned user — an unassigned user is ADR 0017's "assigned
to nothing" case (sees organisation-level rows only), and the old test had
been shown 4 rows at a branch node where the rule gives 1, asserting the
defect rather than catching it. New fixture: `kanoUser` (assigned to Kano,
`includes_descendants`), `lagosUser` (Lagos only), `orgUser`
(`rcsa_scope.all_units`, unrestricted). New test
`an_assigned_users_own_visibility_holds_even_with_no_node_on_the_widget`
proves the second gate holds even when the first (node scope) is wide open.

### B16 — two defects in `ClauseComplianceMatrixService::sufficiency()`, both the same family

1. **`Iso22398_evaluation`** (~:208) was `existsCheck(Aar::where('status',
   'final'))` — a PIR alone turned ISO 22398 "Exercise evaluation and
   improvement" green. Scoped to `whereNotNull('occurrence_id')`, the same
   rule `aarSufficiency()` already applies for 8.5.report.
2. **`evaluationSufficiency()`** (ISO 22301 8.6, ~:535) was `existsCheck(Aar::
   where('status', 'final'))` too — identical defect on the mandatory-adjacent
   row. A compliance ruling landed mid-cycle ("Compliance ruling — PIRs and
   clause 8.6", §15 above) after research found the published ISO 22301 8.6
   text names post-incident reports as a required input, not something to
   exclude outright. Implemented verbatim: red while there is no active
   Tier-1 process, or while `LadderAdvisor` would raise
   `tier1_never_drilled`/`tier1_stale` for any (read through
   `coverageMatrix()`, restated rather than calling the private
   `tierOneOnlyWalkedThrough()` directly, which needs a hypothetical
   scheduled exercise's own level this read has none of —
   `LadderAdvisor::TIER1_STALE_MONTHS` is the one constant, reused); otherwise
   amber while any `status = closed`, `is_exercise = false` incident has no
   finalised PIR; otherwise green. A PIR holds 8.6 at amber but never lifts
   it; `last_evidenced` comes from the exercise arm only (drill-or-above
   dates), never a PIR's date. `LadderAdvisor` injected into the constructor.
   `docs/compliance/iso22301-clause-map.md`'s 8.6 row updated to match
   (boundary explicitly extended by the coordinator to that one doc).

Tests: `a_finalised_post_incident_review_alone_does_not_evidence_clause_8_5`
(unchanged, still green — proves defect 1 stays fixed); six new tests for
8.6 (`clause_8_6_is_red_with_no_active_tier_1_process`,
`..._never_been_drilled_above_a_walkthrough`, `..._is_stale`,
`..._is_amber_when_coverage_holds_but_...no_final_pir`,
`..._is_green_when_...every_closed_real_incident_has_a_final_pir`,
`..._a_lone_pir_never_makes_it_green_and_an_exercise_incident_never_triggers_the_amber`).

### B17 — `Iso22320_incident`/`Iso22361_crisis` counted exercise incidents

Neither `existsCheck` carried `is_exercise = false` — ADR 0020 §4's "in every
aggregate" rule. A tabletop's practice incident turned `iso22320.incident_
response` green and, through the §3.4 crosswalk, `cbn.rcf.incident_response`
— a CBN regulatory row going green on a drill. Both scoped. Test:
`an_exercise_incident_alone_leaves_incident_response_crisis_and_cbn_incident_non_green`.

### B3 — `competenceSufficiency()` counted a failed assessment toward clause 7.2

Counted `competency_assessed = true` regardless of `score` vs the
curriculum's `pass_mark`, and regardless of `next_due_date`. Compliance-analyst
confirmed against `docs/bcms/screens/training-compliance.md` §3: a
below-pass-mark assessment is a valid record but not clause-7.2 evidence.
Rewritten to filter records on `score >= pass_mark AND (next_due_date is
null or not past)`; the artefact sentence no longer claims "each with an
assessor, a date and a result" when a failed result is what is on file.

`Phase11TrainingTest::an_assessed_record_is_green_while_attendance_only_is_amber`
(the other engineer's file — not editable here) asserts by NAME only; it
never reads the matrix. Two new tests added here instead, reading the real
matrix state: `a_below_pass_mark_assessed_record_does_not_evidence_clause_7_2`
and `a_current_passing_record_evidences_clause_7_2_but_an_expired_one_does_not`.

### B2 — packs printed a period their content ignored

`EvidencePackService::sectionsFor()`/`generate()` printed `$from`–`$to` on
the cover but `build()` had no period concept at all — every sufficiency
rule always answered against "now". Two different fixes for two different
kinds of section, per the spec:

- **`ClauseComplianceMatrixService::build(?Carbon $asOf = null)`** — a new
  optional parameter, defaulting to `now()` (every existing zero-arg caller —
  the live matrix screen, `GapAnalyserService`, `CsatPrefillService` —
  unaffected). Five sufficiency rules that spec §3.2 states "in the
  period"/"in the cycle"/"inside its own frequency" now measure against
  `$this->asOf` instead of `now()`: `communicationSufficiency()` (row 7,
  calendar year), `aarSufficiency()` (row 13, calendar year, matching
  `exerciseProgrammeSufficiency()`'s own year), `reviewSufficiency()` (row
  17, calendar year), `recoverySufficiency()` (row 11, "the cycle" — no
  per-check DR cadence is read at this check's blanket-across-all-systems
  granularity, so defined as the twelve months ending `$asOf`, the same
  annual cadence `Programme`/`ExerciseProgramme` already assume, stated as
  such rather than left implicit), `kriSufficiency()` (row 15, each KRI's
  own `measurement_frequency` — a frequency-to-days mapping restated from
  `App\Services\MyResponsibilitiesService::kriDueDate()`'s own mapping,
  since that class is a different phase's file and cannot be called into,
  but the mapping itself must not be defined twice with two different
  answers). `EvidencePackService::sectionsFor(string $framework, ?Carbon
  $asOf = null)` threads the pack's own `$to` (parsed once in `generate()`)
  through as `$asOf`, so a pack for a past period now genuinely differs from
  a live one.
- **`BoardPackService`** does not use `ClauseComplianceMatrixService` at all
  — `plan_currency`/`top_rto_gaps`/`open_nonconformities`/`kris` are pure
  current-state reads with no point-in-time snapshot capability in this
  schema (there is no historical "which plans were current as of a past
  year"). Labelled honestly instead (spec's own second option): `preview()`
  gained an `as_at` ISO-8601 key, and the page header, the PDF subtitle and
  the PPTX's first slide all carry the same sentence naming which four
  sections are live figures and which are the year's own stored records
  (`honestSubtitle()`, one string, three call sites).

Tests: `evidence_pack_and_board_pack_section_content_is_reproducible_across_two_calls`
(unchanged, still green). New:
`a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does` — a
`NotificationDelivery` written between calls moves the LIVE pack
(`sectionsFor('iso22301', now())`) but not a pack for two years ago
(`sectionsFor('iso22301', $pastAsOf)`), proving the `asOf` threading actually
holds rather than being a parameter nobody reads.
`the_board_pack_preview_carries_an_as_at_timestamp_for_its_live_sections`.

### B10/A5 — redundant computation inside `build()`

Two multipliers, both fixed:

1. **A ref-level memo** (`$sufficiencyCache`, keyed by `IsoClauseRef->value`)
   stops `crosswalk()` re-answering an already-seen ISO ref (A5's own
   complaint) — e.g. `Iso22301_8_4_5` answered by both `cbn.rcf.bcdr` and
   `cbn.ob.dr_test` used to run `recoverySufficiency()` twice more on top of
   its own direct row.
2. **A method-level memo** (`memo(string $key, callable $compute)`, keyed by
   method name) stops a method SHARED BY SEVERAL ENUM CASES — three for
   `plansSufficiency()`, two each for `communicationSufficiency()`,
   `reviewSufficiency()`, `nonconformitySufficiency()`, `scopeSufficiency()`
   — from running once per case. The ref-level cache alone cannot catch this,
   because each case is a *different* ref/cache key. Also wraps
   `latestReviewWithAudit()`, shared by `auditProgrammeSufficiency()`/
   `auditResultsSufficiency()`.
3. **`nonconformitySufficiency()`'s own N+1**: `$f->correctiveActions()->count()`
   inside a `filter()` ran one query PER nonconformity — batched into the
   single query `withCount('correctiveActions')` already loads.

Both reset at the top of `build()`, keyed independently per call (never
shared across two different `$asOf` values). Test:
`build_does_not_n_plus_one_on_the_number_of_nonconformities` — same query
count at 3 nonconformities as at 20.

### A4 — `$registerSeeded` checked any obligation for any programme

`ProgrammeObligation::query()->exists()` (tenant-wide, any programme ever
loaded) became `$obligations->isNotEmpty()` — `$obligations` is already
scoped to the LATEST programme `build()` selected, loaded two lines above.
One existing test's fixture (`a_completed_occurrence_with_no_finalised_aar_is_an_honest_red`)
created its obligation with no `programme_id` at all (the factory does not
default one) — passed before only because the old check didn't care which
programme an obligation belonged to. Fixed the fixture to link the
obligation to the programme actually being scored, which is what the test's
own intent already required.

### A1 — `MaturityService::latest()` had no id tiebreaker

`orderByDesc('assessed_at')` alone, matching the same defect family §13
fixed across `ClauseComplianceMatrixService`/`BoardPackService`. Added
`orderByDesc('id')`.

### B8 — the gap-analyser raise endpoint had no form request; `ai_generated` mislabelled

`ComplianceController::raiseFromGapAnalysis` read `$request->input(...)` raw
— an unknown `clause_ref` reached `FindingService::raise()` uncaught
(`InvalidArgumentException` → 500), and any text was stored with
`ai_generated = true` unconditionally. New
`App\Http\Requests\Bcms\RaiseBcmsGapAnalysisFindingRequest`: `clause_ref`
validated against the CURRENT mandatory red/amber set, read live from
`GapAnalyserService::draftFindings()` — the same set the gap panel is
showing; `finding` bounded to 2000 characters. Its own `authorize()` checks
`bcms.finding.manage`, replacing the controller's `Gate::authorize()` call
(matching this codebase's own convention elsewhere).

**Orchestrator decision, relayed and implemented**: `GapAnalyserService` is a
deterministic, templated draft over computed matrix rows — never an LLM
call — so `ai_generated = false` is the truthful provenance label, not
`true`. Routing it through `BcmsLlmClient` for softer phrasing is recorded
here as a legitimate, **DEFERRED** follow-up pending an owner decision — not
built in this pass, and not mislabelled as already AI-authored in the
meantime. Both the controller's hardcoded `ai_generated => true` and
`GapAnalyserService::draftFindings()`'s own `ai_generated => true` changed to
`false`; both class docblocks updated to state the decision and its reason.

Tests: `raising_a_gap_analysis_finding_creates_a_real_finding_row_labelled_matrix_generated_not_ai`
(renamed from `..._tagged_ai_generated`, assertion flipped to `assertFalse`),
`raising_a_gap_analysis_finding_against_an_unknown_clause_ref_is_refused_as_validation_not_a_500`,
`raising_a_gap_analysis_finding_against_a_clause_that_is_not_currently_red_or_amber_is_refused`.
`the_gap_analyser_cites_the_org_node_and_elapsed_time_never_a_generic_statement`'s
own `assertTrue($finding['ai_generated'])` flipped to `assertFalse` to match.

### B9 — CSAT pre-fill: no audit, an O(n²)-shaped column bug, no size limit, a temp-file leak

- **`for ($col = 'A'; $col <= $highestColumn; $col++)`** compared column
  LETTERS as PHP strings — `'Z' <= 'AB'` behaves nothing like a spreadsheet
  reader expects, so a workbook with a question past column Z either never
  scanned past `A` or walked on far past where it should have stopped.
  Rewritten to loop on the INTEGER column index
  (`Coordinate::columnIndexFromString()`/`stringFromColumnIndex()`),
  converting only at the point of use. Test:
  `the_csat_prefill_finds_a_question_past_column_z_on_a_wide_workbook` — a
  question at column AD (30), answer must land at AE (31).
- **No `pack.exported` audit at all.** Added `CsatPrefillService::logExport()`,
  matching the same shape (and the A2 org-node/`AUDIT_FAILURE_CACHE_KEY`
  fixes below) as the other two packs. `fill()` now takes `User $actor`;
  `EvidencePackController::csatPrefill()` passes `$request->user()`. Test:
  `the_csat_prefill_writes_a_pack_exported_audit_entry`.
- **No upload size limit** (compliance-analyst's NDPA register §11 finding).
  `EvidencePackController::csatPrefill()`'s validation gained `max:20480`
  (20 MB).
- **A leaked temp file on a failed save.** `$writer->save($tmp)` throwing
  left the filled-in (regulator-content-bearing) workbook sitting in the
  temp directory for ever, because `unlink($tmp)` never ran. Wrapped in
  `try`/`finally`.
- **Confirmed nothing is persisted beyond the request**: the uploaded
  file's own PHP temp path is cleaned up by PHP at end of request; the
  intermediate `.xlsx` `fill()` writes lives only inside its own
  `try`/`finally`; no row anywhere stores the workbook or its answers —
  documented as a docblock guarantee, not just asserted in this note.

### A2 — pack-export audit: org node, and a failed write counted

Spec §3.1's "generation is recorded... (framework, period, **org node**) in
after" — none of the three `logExport()`s (evidence pack, board pack, now
CSAT) named the org node explicitly inside `after`; added
`'org_node' => ['organization_id' => ..., 'organization_name' => ...]` to
all three (these packs are organisation-wide, so the org node IS the
organisation). A failed audit write now increments
`AuditLog::AUDIT_FAILURE_CACHE_KEY` (`Cache::add`+`Cache::increment`, wrapped
in its own inner `try`/`catch` so a broken cache cannot fail the pack
generation), the same signal `BcmsWatchdog` and `BcmsAuditable`'s own catch
already use — a log line nothing reads is indistinguishable from silence.
Compliance ruling's own follow-up ("the board pack prints every open
nonconformity's full description... keep it, but make sure the export is
audited with the org node") is this exact fix applied to
`BoardPackService::logExport()`.

### A8 — PPTX: "not linked" conflated with "linked but unmeasured"; incident slide missing timeliness

`$k['current_value'] ?? 'not linked'` printed "not linked" for BOTH a
genuinely unlinked KRI AND a linked-but-never-measured one — the PDF blade
already had the correct three-state logic
(`$linked ? ($current_value ?? 'not yet measured') : 'not linked'`); the
PPTX slide builder now matches it. The incident-summary slide carried only
the incident count, not the notification-timeliness figures
(`notified_within_due`/`notified_late_or_missing`) the JSON payload and the
PDF already compute — added as a second line. Test:
`the_pptx_kri_slide_distinguishes_not_linked_from_linked_but_unmeasured`.

### Live-browser follow-up (mid-task, boundary extended to `BoardPack.jsx`)

Three text defects found rendering the board pack in the user's own Chrome
against the dev DB:

1. **The header/PDF subtitle/PPTX claimed nothing is recomputed**, false for
   the same four live-state sections B2 already named — the SAME
   `honestSubtitle()`/`as_at` fix above closes this; the JSX subtitle string
   was rewritten to match verbatim, and `tools/ui-audit.mjs` stays at 0
   findings on the file.
2. **"shortfall none recorded hours"** (a stray trailing unit on an
   undefined figure) and no pluralisation (`"shortfall 1 hours"`). Fixed in
   THREE places independently, since each renders its own copy of the text:
   `BoardPack.jsx` (`shortfallPhrase()`), the PDF blade (inline `@if`), and
   `BoardPackService::generatePptx()`'s own slide builder
   (`hoursPhrase()` — the SAME helper `postureSummary()`'s sibling
   `pluralSentence()` sits beside). Test:
   `the_top_rto_gaps_shortfall_is_worded_and_pluralised_honestly` — a real
   1-hour shortfall (via `Process`/`BiaAssessment`/`Strategy` fixtures
   matching `Phase3PlanBuilderTest`'s own established pattern) reads
   "shortfall 1 hour" not "1 hours" in the actual generated PPTX slide XML,
   and an unprotected process (no selected strategy) reads
   `shortfall_hours: null`, never a fabricated zero.
3. **"1 nonconformity(ies) remain open"** — `BoardPackService::postureSummary()`'s
   own sentence, fixed with a `pluralSentence()` helper; flows through to
   the JSX, the PDF (which just echoes `posture_summary.sentences`) and the
   PPTX's first slide unchanged, since all three read the same server-built
   string. Test: `the_posture_summary_pluralises_the_open_nonconformity_count_correctly`.

### Advisories not fixed, named

- **A13** — the coordinator's message named this advisory but its content
  was never relayed (the same pattern Phase 10's own notes record for
  A1/A7/.../A15 — "did not relay their text in this session"). Booked here,
  unaddressed, for whoever has the original text.
- **A14** — the evidence pack's index page names clause/title/state but no
  "page" column, despite spec §3.2's intro naming "clause → artefact →
  page". dompdf page numbers need bookmark/TOC machinery this pass did not
  add; named rather than guessed at.
- **A16** — `TprmWidgetSeeder`'s own `sort` key-name defect (`field`/
  `direction` vs `RegisterResolver`'s `by`/`dir`) is the SAME shape as
  `BcmsWidgetSeeder`'s own B14 defect, fixed here for BCMS only. TPRM's copy
  is TPRM's own file, out of this share's boundary — booked, not fixed.

### Verification, this cycle

All seven `Phase11*`/`Phase11Widgets` files, both TPRM widget files, the
whole of `tests/Feature/Widgets/`, and `Phase1GovernanceTest`, run together
in one combined pass: **237 passed, 1339 assertions**, no failures. Pint
(`--test`) and PHPStan (`--memory-limit=1G`), both scoped to every app AND
test file touched this cycle (14 files): **0 errors** each.
`tools/ui-audit.mjs` on `BoardPack.jsx`: **0 findings**.

### Environment note: the shared MariaDB instance was unreachable

`risk_test_p11_b9babae` (port 3306, the shared XAMPP MariaDB data directory)
was down at the start of this cycle and could not be restarted without root
— the data directory's files are owned by `_mysql:702`, and this session has
no sudo. Rather than stop, a SECOND, PRIVATE MariaDB 10.4.28 instance (the
same XAMPP-bundled `mysqld` binary, confirmed real 10.4.28, not a substitute
version) was started on port 3309 against a fresh datadir under this
session's own scratchpad, mirroring a pattern an earlier Phase 10 agent had
already used on port 3308 against the same scratchpad path — `risk_test_p11_b9babae`
was created fresh and migrated there. Every test run in this section used
`DB_PORT=3309` alongside `DB_DATABASE=risk_test_p11_b9babae`. No system
files were modified; the shared instance on 3306 was left exactly as found
(down), for whoever has the access to restart it properly.

## 17. Code review #2, R1/R3/R4/R6 + advisories (backend-engineer)

**Branch:** `integration/bcms-remaining` (uncommitted). **DB:** the SHARED
server was reachable this cycle — `mysql -u root -h 127.0.0.1 --skip-ssl`
confirmed real MariaDB `10.4.28`; `risk_test_p11_b9babae` was dropped and
recreated there, then `migrate:fresh`. The private port-3309 instance from
§16 was confirmed stopped (`lsof -nP -iTCP:3309 -sTCP:LISTEN` returned
nothing) and never restarted. Every test run in this section used
`DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=risk_test_p11_b9babae`.
**Boundary:** R1/R3/R4/R6/A2/A5/A6/A9, plus a mid-task addition from Phase
10's own code review (`BoardPackService::incidentSummary()`'s missing
`is_exercise` filter). `app/Models/Bcms/Concerns/BindsToVisibleRecord.php`
extended for R1 only. A second engineer worked R2/R5/A1/A7/A8/A10/A11 in
parallel (`TrainingComplianceService`, `TrainingController`, the training
requests, `ProgrammeService`, `SupplierResilience*`) — none of those files
were touched here; `TrainingComplianceService::assignedUsers()` was CALLED
from R4's rewrite of `competenceSufficiency()`, never edited.

### R1 — the widget engine's own copy of ADR 0017 visibility, deleted

`WidgetQueryEngine::applyVisibility()`/`constrainAnchorPath()` (§16's own B1
fix) had already drifted from `BindsToVisibleRecord::constrainToVisibleRecord()`:
it dropped the named-user arm (`ExerciseOccurrence::orgVisibilityNamedUsers()`
— `facilitator_id`/`participants.user_id` — so a Kano facilitator of a Lagos
drill saw it on the calendar but not the widget); it had no whole-estate
short-circuit when `unitIdsFor($user)` is null, adding a `whereHas('definition')`
that loses an occurrence whose definition is soft-deleted (the trait's own
docblock names this exact defect); it failed OPEN where the trait throws
`LogicException` for an unclassifiable model.

Fixed by widening `constrainToVisibleRecord()` to take an optional
`?User $user = null` (default `Auth::user()` — no behaviour change for every
existing caller, all of which pass nothing) and deleting the widget engine's
own copy entirely — `applyVisibility()` now delegates:

```php
private function applyVisibility(Builder $query, WidgetContext $context): void
{
    $model = $query->getModel();

    if (method_exists($model, 'constrainToVisibleRecord')) {
        $model->constrainToVisibleRecord($query, $context->user);
    }
}
```

`Plan`'s own `ScopedToOrgHierarchy::visibleTo` path was already calling the
model's own scope, not a copy — nothing to fix there.

**Tests:** `a_kano_assigned_facilitator_of_a_lagos_drill_sees_exactly_that_drill_on_the_widget`
and `an_all_units_reader_still_sees_an_occurrence_whose_definition_is_soft_deleted`
(`Phase11WidgetsTest.php`). `BcmsRecordVisibilityTest`, `Phase10IncidentTest`,
`Phase9ExecutionTest`, both TPRM widget files and `tests/Feature/Widgets/`
all stay green, unedited — proof the widened trait signature is a pure
addition, not a behaviour change for any existing caller.

### R3 — the evidence pack did not reproduce for its own period

`ClauseComplianceMatrixService::build(?Carbon $asOf = null)` became
`build(?Carbon $periodEnd = null, ?Carbon $periodStart = null)`. Every
upper-bound read now measures against `$periodEnd`; the three TRUE "in the
period" rules (spec §3.2 rows 7, 13, 17 — communication, AAR, review) also
measure against `periodStart()`, which is `$periodStart` when given
(`EvidencePackService::generate()`'s own `$from`) or the start of
`$periodEnd`'s own calendar year otherwise (the live screen's carried-forward
behaviour, unchanged for every caller that passes nothing).
`EvidencePackService::generate()` now computes `$periodEnd = Carbon::parse($to)->endOfDay()`
and `$periodStart = Carbon::parse($from)->startOfDay()` — `[00:00:00, 23:59:59.999]`
inclusive of the period's own last day, fixing the `whereBetween('detected_at', [$from, $to])`
defect named in the code review (a `$to` read at midnight silently dropped
everything dated on the last day of the period).

**Per-section decision table** (R3's own instruction: bound every read that
CAN be bounded; where a section can only be current state, LABEL it rather
than pretend it reconstructs):

| Section / method | Bound | How |
|---|---|---|
| `communicationSufficiency()` (7.4/8.4.3) | TRUE window | `whereBetween('delivered_at', [periodStart(), periodEnd])` |
| `aarSufficiency()` (8.5.report) | TRUE window, PLUS labelled `current_state: true` (AAR-dependent branches only) | occurrence `scheduled_date` bounded both sides; AAR's own `approved_at` ALSO bounded `<= periodEnd` (null-safe) — QA re-gate #9's finding. **QA re-gate #11 item 3 (design ruling):** `AarService::reopen()` nulls `approved_at` with no trace of the original finalisation, so the two AAR-reading branches (missing/all-present) now ALSO carry `current_state: true` + a reason string — see that section below. The FIRST red branch ("no occurrence completed") does not read Aar and stays unlabelled |
| `reviewSufficiency()` (9.3) | TRUE window | `whereBetween('held_on', [periodStart(), periodEnd])` |
| `exerciseProgrammeSufficiency()` (8.5.programme) | Upper only | programme year = `periodEnd->year`; `approved_at <= periodEnd` |
| `recoverySufficiency()` (8.4.5) | Upper + 12mo lower | `test_date` between `periodEnd - 12mo` and `periodEnd` |
| `kriSufficiency()` (9.1) | Upper + frequency lower | `last_measurement_at <= periodEnd` (R3's own new fix, below) AND not older than the KRI's own frequency before `periodEnd` |
| `competenceSufficiency()` (7.2) | Upper only, PLUS labelled `current_state: true` | currency (`next_due_date`) judged against `periodEnd` (R4); the "latest TrainingRecord per assigned user" selection ALSO bound `completed_at <= periodEnd` (null-safe) — QA re-gate #9's finding, see below, the currency bound alone was not enough; the denominator (`TrainingComplianceService::assignedUsers()`, live role membership with no history) is irreducibly current state, same shape as raci/bia/strategy/8.6 |
| every `existsCheck()`-driven row (documented info, operational planning, risk assessment, exercise design/ladder, strategy selection, crisis, incident response, supply chain, continual improvement, 22317/22331/22361/22398) | Upper only | `bestDateColumn() <= periodEnd`, tiebroken on id |
| `drTestReport()` (evidence pack, all frameworks) | Upper only (`$asOf` = `periodEnd`) | last DR test `test_date <= $asOf`; corrective-action `age_days` computed against `$asOf`, never `now()` |
| `cbnContent()`'s `dr_tests`/`incidents` | TRUE window | bounded `[$from, $to]`, `$to` end-of-day inclusive; `incidents` also `is_exercise = false` |
| `cbnContent()`'s `dr_systems` | **Not bounded — labelled** | a register snapshot (`last_test_date`/`next_test_due` pointer columns only, no history of what "next due" read on a past day); returned with `dr_systems_as_of`/`dr_systems_label` and rendered under an `<em>` note on the PDF, the same way the board pack labels its own live sections |
| `scopeSufficiency()` (4.1/4.2/4.3) | Upper only | **STALE — superseded by QA re-gate #10, see that section.** The programme is now picked via the shared `programmeAsOf()` two-tier rule (primary: `approved_at <= periodEnd`, status irrelevant; fallback: `created_at <= periodEnd`, "existed but never approved"), never a separate re-fetch of its own; approval is judged `approved_at !== null && approved_at <= periodEnd`, never `status` |
| `policySufficiency()` (5.2) | Upper only | Programme picked via the shared `programmeAsOf()` (see above); the linked policy `Plan` itself still bound `approved_at <= periodEnd`; "inside its review cycle" judged against `periodEnd`, not `isFuture()` against today. `Plan.status` carries a reported-not-fixed as-of flaw of its own — see QA re-gate #10 |
| `objectivesSufficiency()` (6.2) | Upper only | `Objective.created_at <= periodEnd` (no dedicated approval date on the table; `created_at` is the same fallback `bestDateColumn()` itself would reach for) |
| `plansSufficiency()` (8.4.1/8.4.2/8.4.4) | Upper only | **QA re-gate #11 item 1 — status dropped entirely.** `Plan.approved_at IS NOT NULL AND approved_at <= periodEnd` (never `status`: `PlanService::supersede()` archives the old version's status without touching its `approved_at`); deduped by lineage (`supersedes_plan_id` chain walked to its root — see that section below), latest approved-by-`periodEnd` version per family kept; `PlanAttestation.attested_at <= periodEnd` unchanged |
| `nonconformitySufficiency()` (10.1 ×2) | Upper only | `Finding.raised_at <= periodEnd` (null-safe); corrective-action count and the closed-but-unverified check bound to `created_at`/`completed_at <= periodEnd` |
| `auditProgrammeSufficiency()`/`auditResultsSufficiency()` (9.2 ×2) | Upper only | `ManagementReview.held_on <= periodEnd`; the linked-audit-finding count bound to `Finding.raised_at <= periodEnd` (null-safe) |
| `raciSufficiency()` (5.3), `biaSufficiency()` (8.2.2), `strategySufficiency()` (8.3), `evaluationSufficiency()` (8.6) | **Denominator not bounded — labelled `current_state: true`** | each measures against "processes active/Tier-1/critical RIGHT NOW" (`bcms_processes` carries no history of a past `criticality_tier`/`status`) — irreducibly current state, so every branch of all four returns `current_state: true`, printed on the PDF the same way `dr_systems_label` is. Their own SUB-reads (which the schema does date) are bound regardless: `RaciAssignment.created_at`, `BiaAssessment.approved_at`. **`strategySufficiency()`'s own sub-read, QA re-gate #11 item 2:** `approval_status = 'approved' AND approved_at <= periodEnd`, never `is_selected` (`StrategyService::select()` flips a SIBLING's `is_selected` to `false` with no trace, `approval_status`/`approved_at` untouched). **8.6's own PIR-arm sub-read, QA re-gate #11 item 3:** `Incident.closed_at` bound (null-safe) PLUS its amber/green branches now ALSO carry `current_state_reason` (AAR finalisation is reversible with no trace — see that section below) on top of the Process-denominator `current_state: true` they already had; the two red (tier1-gap) branches keep the generic label only, unaffected by AAR |

**A defect R3's own reproducibility test found while writing it:**
`kriSufficiency()` compared `last_measurement_at` only against a LOWER cutoff
(`periodEnd - frequencyDays`), never an upper bound — a reading dated AFTER
a past pack's own `periodEnd` (impossible in real production use, but
exactly what the "insert one row after `$to`" test writes) was further from
the lower cutoff than `periodEnd` itself and so read as "within frequency",
silently moving a pack for a period the reading postdates. Fixed with an
explicit `if ($kri->last_measurement_at->gt($this->periodEnd)) { return false; }`
guard before the existing lower-bound check.

**`drTestReport()`/A6:** the `Finding::where('dr_test_id', ...)->first()`
lookup had no explicit order — fixed with `orderByDesc('raised_at')->orderByDesc('id')`
(most recently raised finding against the test, tiebroken on id).

**`cbnContent()`/A6:** the notification-timing loop ran one
`IncidentNotification` query PER incident — replaced with one query across
every incident in the period, grouped by `incident_id` in PHP.

**Mid-task addition (Phase 10's own code review, same family as B17):**
`BoardPackService::incidentSummary()` did not exclude exercise incidents —
`Incident::query()->whereYear('detected_at', $year)->get()` carried no
`is_exercise` filter at all, so a drill's incident would inflate the board's
own incident count and severity breakdown. Latent today (nothing writes an
exercise incident yet) but ADR 0020 §4 requires the filter "in every
aggregate" regardless. Fixed with `where('is_exercise', false)`; every other
incident read in `BoardPackService` and `EvidencePackService` (incl.
`cbnContent()`) was checked — `cbnContent()` already carried the filter from
§16's own B17 fix, and there is no other incident read in either file.

**Required test replacement:** `a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does`
rewritten to insert ONE new row into EVERY class R3 named (exercise
programme, AAR, finding/corrective action via `continualImprovementSufficiency()`,
KRI measurement, notification delivery, DR test), each dated today —
strictly after a past pack's own two-years-ago `$to` — and asserts the past
pack is byte-identical while the live pack moves, per class rather than
trusting one row (the old test's `NotificationDelivery`-only write) to stand
for all six. New test `an_incident_detected_on_the_last_day_of_the_period_is_included`
proves the end-of-day-inclusive `$to` fix directly. New test
`an_exercise_incident_is_excluded_from_the_board_packs_incident_summary`
covers the mid-task addition.

### R4 — `competenceSufficiency()` was an existence check, not a coverage one

Two defects, both from spec §3.2 row 5 ("every ROLE in a mandatory
curriculum holds a current, assessed record"): it went green on ONE current,
passed record however many people the curriculum is actually assigned to;
and a FAILED assessment (`competency_assessed = false` with a non-null
`score` — the write side's now-settled meaning: "assessed AND passed", not
merely "was assessed") was counted the same as a record never touched at
all.

Rewritten to measure coverage against `TrainingComplianceService::assignedUsers()`
per mandatory, assessed curriculum (CALLED, never edited — a different
engineer's file this cycle), with `failed`/`unassessed`/`expired` counted
separately and named separately in the artefact sentence ("N failed
assessment(s)", "N not yet assessed", "N expired, needing re-assessment").
Currency (`next_due_date`) is judged against `$this->periodEnd` (R3), not
`isPast()` against today. **A5:** one `TrainingRecord` query PER CURRICULUM
(grouped by `user_id` in PHP to find each assigned person's latest record),
not per assigned person — `assignedUsers()` itself is a live role
resolution across `users`/`roles`, not a stored, joinable list, so this is
as far as the read can be batched into SQL.

**Tests:** `one_passed_of_three_assigned_is_not_green` (1 of 3 is amber, not
green); `a_failed_assessment_is_reported_as_failed_not_as_unassessed` (mixed
population: one failed, one never-assessed, named separately in the
artefact); the two pre-existing tests
(`a_below_pass_mark_assessed_record_does_not_evidence_clause_7_2`,
`a_current_passing_record_evidences_clause_7_2_but_an_expired_one_does_not`)
updated to assign their subjects the curriculum's own `floor-warden` role
(required now that coverage, not mere existence, gates the row) and to
assert the new failed/unassessed wording.

### R6 — the widget render test proved nothing about sort or the `$today` token

`every_seeded_bcms_widget_renders_through_the_engine` asserted only
`state === 'ok'` for all three seeded widgets — a bug in the drill-calendar
widget's own `$today` filter or its `by`/`dir` sort key (B14's exact defect
family, §16) would ALSO render `'ok'`, with the wrong rows or the wrong
order. Extended (not replaced with a second test, since the loop over all
three widgets is still the right shape) to seed a past, a near-future and a
far-future drill and assert, for `wg-bcms-drill-calendar` specifically, that
the past drill is excluded and the other two come back
`[nearFuture, farFuture]` — sort order and the live `$today` filter both
proven, not merely "did not throw".

### A2 — `BoardPackService::preview()`'s own reproducibility claim, undermined by `as_at`

`preview()`'s docblock claims identical content on two calls with nothing
written between them (phase-11-spec §6 criterion 2), but `as_at => now()`
sat INSIDE the compared array — two calls close enough together could
happen to agree by luck and mask a real regression. `as_at` moved to a new
`stampedPreview(int $year): array` (`preview($year) + ['as_at' => ...]`),
which `BoardPackController::index()` now calls instead of `preview()`
directly; `generatePdf()`/`generatePptx()` were already unaffected (neither
blade nor the PPTX writer reads `as_at` — only the React page's subtitle
does). New test `preview_itself_never_carries_an_as_at_key` pins this so a
future re-introduction cannot silently make the reproducibility comparison
flaky again; the pre-existing `the_board_pack_preview_carries_an_as_at_timestamp_for_its_live_sections`
now calls `stampedPreview()`.

### A9 — `CsatPrefillService`

Three items: `IOFactory::load($path)` (auto-detects the reader by sniffing
file content) replaced with `IOFactory::createReader('Xlsx')->load($path)`
— the upload is customer-supplied and unvalidated beyond its extension, so
the reader should be the one this class actually checked for, not a guess.
The adjacent-cell write in `writeAnswer()` is no longer unconditional — a
customer's own workbook may already carry an answer or note in that cell;
`writeAnswer()` now returns one of `'written'|'occupied'|'not_found'`, and
an OCCUPIED question is reported back distinct from an UNMATCHED one (both
in `fill()`'s return array and on the cover sheet, in its own labelled
block), so nothing is silently overwritten and nothing is silently
indistinguishable from "not found". The `writeAnswer()` docblock's own worked
example was corrected — it previously asserted `'Z' <= 'AB'` is true under
PHP's string comparison, which is false (PHP compares byte-by-byte; `'Z'`'s
first byte already exceeds `'AB'`'s, deciding the whole comparison
regardless of length); the real two defects are `'B' > 'AB'` — cutting the
loop off after column `A` for a target past `Z` — and `'AA' < 'Z'` — letting
the loop run ~676 iterations too many once it wraps past `Z` for a
single-letter target. The same wrong example was copied into
`Phase11ReportingGateTest.php`'s own docblock and corrected there too.

**Tests:** `the_csat_prefill_never_overwrites_an_already_occupied_adjacent_cell`
(a workbook with an existing answer next to a matched question — `matched`
stays 0, the question is reported in `occupied`, not `unmatched`, and the
original cell content is byte-for-byte untouched on reload).
`EvidencePackController::csatPrefill()` gained an `X-Csat-Occupied` header
alongside its existing `X-Csat-Matched`/`X-Csat-Unmatched`, for parity.

### Verification, this cycle

All fourteen required targets run together in one combined pass — the seven
`Phase11*` files, both TPRM widget files, the whole of `tests/Feature/Widgets/`,
`BcmsRecordVisibilityTest`, `Phase10IncidentTest`, `Phase9ExecutionTest`,
`Phase1GovernanceTest`: **377 passed, 2203 assertions, 0 failures**, after
fixing one genuine flaky fixture the first full run caught (below). Pint
(`--test`) and PHPStan (`--memory-limit=1G`), both scoped to every app AND
test file touched this cycle: **0 errors** each.

**A real defect the R6 test itself introduced, caught by the full-suite run:**
`every_seeded_bcms_widget_renders_through_the_engine`'s three new fixture
occurrences shared ONE `ExerciseDefinition` with no explicit `sequence_no`
— `bcms_exercise_occurrences` is unique on `(definition_id, sequence_no)`,
and `ExerciseOccurrenceFactory`'s own default is a RANDOM `1-5`, so three
draws collided roughly 44% of the time
(`UniqueConstraintViolationException` on the third insert). Fixed by giving
each occurrence an explicit, distinct `sequence_no` — confirmed
deterministic over three consecutive isolated runs before the full-suite
re-run above.

Files touched this cycle: `app/Models/Bcms/Concerns/BindsToVisibleRecord.php`,
`app/Services/Widgets/WidgetQueryEngine.php`,
`app/Services/Bcms/Compliance/ClauseComplianceMatrixService.php`,
`app/Services/Bcms/Reports/EvidencePackService.php`,
`app/Services/Bcms/Reports/BoardPackService.php`,
`app/Services/Bcms/Reports/CsatPrefillService.php`,
`app/Http/Controllers/Bcms/BoardPackController.php`,
`app/Http/Controllers/Bcms/EvidencePackController.php`,
`resources/views/reports/pdf/bcms-evidence-pack.blade.php`,
`tests/Feature/Bcms/Phase11ComplianceTest.php`,
`tests/Feature/Bcms/Phase11WidgetsTest.php`,
`tests/Feature/Bcms/Phase11ReportingGateTest.php`, and this file.

### §17 follow-up — the ten remaining current-state sufficiency methods closed

The coordinator's own gap: §17's first pass left `scopeSufficiency()`,
`policySufficiency()`, `raciSufficiency()`, `objectivesSufficiency()`,
`biaSufficiency()`, `strategySufficiency()`, `plansSufficiency()`,
`nonconformitySufficiency()`, `auditProgrammeSufficiency()`/
`auditResultsSufficiency()` and `evaluationSufficiency()` (8.6) unbounded
AND unlabelled — a past-period pack silently presented today's state as
that period's evidence for all ten. Closed per clause in the decision table
above: six (`scopeSufficiency`, `policySufficiency`, `objectivesSufficiency`,
`plansSufficiency`, `nonconformitySufficiency`,
`auditProgrammeSufficiency`/`auditResultsSufficiency`) are now bound on
whichever date column the schema actually carries (`approved_at`, `held_on`,
`raised_at`, `attested_at`, `created_at` — the last a `bestDateColumn()`-style
fallback where no dedicated evidence date exists), all null-safe where the
column is nullable so an existing fixture with no `approved_at` recorded is
not silently excluded. Four (`raciSufficiency`, `biaSufficiency`,
`strategySufficiency`, `evaluationSufficiency`) share ONE irreducible
current-state piece — "which processes are Tier 1/critical RIGHT NOW"
(`bcms_processes` carries `criticality_tier`/`is_critical_service`/`status`
with no history of what any of the three read on a past date) — and now
carry `current_state: true` on every return branch, rendered on the PDF
under each clause's own `last_evidenced` line, worded identically to
`cbnContent()`'s own `dr_systems_label`. Their own dated sub-reads
(`RaciAssignment.created_at`, `BiaAssessment.approved_at`,
`Strategy.approved_at`, 8.6's `Incident.closed_at`/`Aar.approved_at`) are
bound regardless — the label communicates "the denominator is current", not
"nothing here was checked against the period".

**Test:** `a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does`
extended with two more post-`$to` rows (an approved `Plan`, a nonconformity
`Finding` with its `CorrectiveAction`) into two of the newly-bounded
classes, and a new assertion that `iso22301.5.3` carries `current_state`
identically in both the past and the live pack (neither can reconstruct it,
so neither pack differs on this one row for that reason).

### QA re-gate #8 — `awarenessSufficiency()` (7.3), the last defect

QA re-gate #8 found one remaining gap: `awarenessSufficiency()`
(`iso22301.7.3`) had no `<= periodEnd` bound and no `current_state` flag on
either of its two reads — `TrainingRecord::whereNotNull('completed_at')->count()`
and the awareness `Alert` lookup ordered by `latest('dispatched_at')`. A
past-period pack's 7.3 row moved when a training record was added, or an
awareness alert was dispatched, after the period.

**Bound choice: two-sided `[periodStart(), periodEnd]`, not `<= periodEnd`
alone.** Spec §3.2 row 6's own sufficiency rule reads "A campaign IN THE
PERIOD with reach reported" — the same true-window framing row 7
(`communicationSufficiency()`, immediately above in the file) already uses.
Both pulls (attendance and the awareness alert) are bound this way — an
attendance record or a campaign dispatched BEFORE the period started is no
more "in the period" than one dispatched after it ended, and the "Pulls"
column groups both reads under the one row.

**Test:** a new sibling test,
`a_training_record_or_awareness_alert_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one`
— a `TrainingRecord` completed today and an awareness `Alert` (via an
`AlertTemplate` with `category = 'awareness'`) dispatched today, both
strictly after a past pack's own two-years-ago `$to`. Asserts the past
pack's `iso22301.7.3` row is byte-identical before/after, and the live
pack's row is not. **Confirmed to fail against the pre-fix code**: the
bound was temporarily reverted, the test run (failed exactly as expected —
the past pack's 7.3 row moved from `red`/"No awareness attendance record
and no awareness campaign has been sent." to `amber`/"An awareness campaign
reached 10 recipient(s); no engagement (acknowledgement) data is on file."
purely from writes dated inside the LIVE period), then the fix was
restored and the test re-run green, byte-for-byte against a saved copy of
the fixed file.

**Full sweep — every `*Sufficiency` method in `ClauseComplianceMatrixService`,
confirmed bound, labelled, or n/a (delegates/dispatches only, no direct
date-sensitive read of its own):**

| Method | Classification |
|---|---|
| `computeSufficiency()` | n/a — dispatcher only |
| `scopeSufficiency()` | bound |
| `policySufficiency()` | bound |
| `raciSufficiency()` | labelled (+ sub-read bound) |
| `objectivesSufficiency()` | bound |
| `competenceSufficiency()` | ~~bound~~ — **WRONG, corrected by QA re-gate #9 below: bound + labelled** |
| `awarenessSufficiency()` | bound — **fixed this pass** |
| `communicationSufficiency()` | bound |
| `documentedInformationSufficiency()` | ~~bound (via `existsCheck()`)~~ — **WRONG, corrected by QA re-gate #11 item 1: was still `where('status','approved')`, `whereNotNull('approved_at')` now** |
| `operationalPlanningSufficiency()` | bound (via `existsCheck()`) — Programme, fixed under QA re-gate #10 |
| `biaSufficiency()` | labelled (+ sub-read bound) |
| `riskAssessmentSufficiency()` | bound (via `existsCheck()`) |
| `strategySufficiency()` | ~~labelled (+ sub-read bound)~~ — **sub-read WRONG, corrected by QA re-gate #11 item 2: was `is_selected`, `approval_status = 'approved' AND approved_at <= periodEnd` now; denominator labelling unchanged** |
| `plansSufficiency()` | ~~bound~~ — **WRONG, corrected by QA re-gate #11 item 1: was `where('status','approved')` with no lineage dedup — now `approved_at`-only, deduped by `supersedes_plan_id` family** |
| `recoverySufficiency()` | bound |
| `exerciseProgrammeSufficiency()` | bound |
| `exerciseSufficiency()` | bound (via `existsCheck()`) |
| `aarSufficiency()` | ~~bound (occurrence membership gates the AAR read on both sides)~~ — **INCOMPLETE, corrected by QA re-gate #9 (the AAR's own `approved_at` also needed a bound), THEN by QA re-gate #11 item 3 (design ruling): also labelled `current_state: true` on its two AAR-reading branches — see that section below** |
| `evaluationSufficiency()` (8.6) | labelled (+ sub-reads bound; `tier1LadderGap()` already `periodEnd`-based) — **its PIR-arm branches gained a `current_state_reason` under QA re-gate #11 item 3** |
| `kriSufficiency()` | bound (upper AND lower) |
| `auditProgrammeSufficiency()` | bound (via `latestReviewWithAudit()`) |
| `auditResultsSufficiency()` | bound |
| `reviewSufficiency()` | bound |
| `nonconformitySufficiency()` | bound |
| `continualImprovementSufficiency()` | bound (via `existsCheck()`) |
| `supplyChainSufficiency()` | bound (via `existsCheck()`) |
| `crosswalk()` | n/a — takes the worst of already-classified ISO rows' own `sufficiency()`, no direct read (the one exception, `cbn.rcf.csat`, is a static amber by design, ADR 0021 §4) |
| `existsCheck()` | bound — the shared bounding primitive itself |
| `build()`'s own `$programme` fetch / `programmeAsOf()` | ~~not fixed, flagged~~ — **bound, see the QA ruling section below (this is not a clause row, but is included here for completeness since it gates every row's grey/amber register-seeded state)** |

No method is both unbounded and unlabelled — **this line was wrong; see QA
re-gate #9 immediately below, which found `competenceSufficiency()` was
in fact still both.**

### QA re-gate #9 — `competenceSufficiency()` (7.2), and one more found by the same sweep

QA re-gate #9's own audit against the spec (row 5 says "current", row 6
says "in the period") found `competenceSufficiency()` (`ClauseComplianceMatrixService.php`,
the "latest TrainingRecord per assigned user" selection) still both
unbounded and unlabelled — the §17-follow-up sweep table above had marked
it "bound" in full, which was wrong: only the CURRENCY half (`next_due_date`
vs `periodEnd`, R4) was bound; the SELECTION itself (which record counts as
"the latest" for a user) had no `completed_at <= periodEnd` bound at all. A
record entered TODAY for a user who had none in a past period was still
picked for the past pack, flipping this MANDATORY clause from red
("not yet assessed") to green.

**Fix 1 — the selection, upper bound only:**
`TrainingRecord::where('curriculum_id', ...)->whereIn('user_id', ...)` now
also carries `where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '<=', $this->periodEnd))`
before the `orderByDesc('completed_at')`/`groupBy('user_id')` pick — null-safe,
mirroring `awarenessSufficiency()`'s own neighbours
(`scopeSufficiency()`/`nonconformitySufficiency()`). Row 5 is VALIDITY AS OF
`periodEnd`, not activity IN the period (unlike row 6) — an upper bound
only, so a record completed before `periodStart` that is still valid at
`periodEnd` still counts.

**Fix 2 — the denominator, labelled, not bound:**
`TrainingComplianceService::assignedUsers()`
(`app/Services/Bcms/Training/TrainingComplianceService.php:44-60`) resolves
LIVE role membership — `User::where('is_active', true)->whereHas('roles', ...)`
against the curriculum's CURRENT `target_roles` — with no queryable history
of `is_active`, role assignment, or `target_roles` on any past date. Who
counts as "assigned" is irreducibly current state, the same shape as
`raciSufficiency()`/`biaSufficiency()`/`strategySufficiency()`/
`evaluationSufficiency()`'s own Process-catalogue denominator, so
`current_state: true` was added to all four of `competenceSufficiency()`'s
own return branches, on top of the bound above (a row can be both bound
AND labelled — the label says "who counts as assigned cannot be
reconstructed", the bound says "what they hold as evidence still can").

**One more found by the same "latest-per-X with only a later check bound"
sweep — `aarSufficiency()`:** the `$finalised` count and `$latest` lookup
gated Aar rows by OCCURRENCE membership (occurrence `scheduled_date` bound
both sides) but never bounded the AAR's OWN `approved_at` — an occurrence
completed inside a past pack's period could still have its AAR finalised
TODAY, and that AAR would count toward the past pack's `$finalised`, the
exact same "a later, unrelated bound creates a false sense of full
protection" shape `competenceSufficiency()`'s currency-only bound had.
Fixed with the same null-safe `approved_at <= periodEnd` guard on both
reads.

**Re-swept everything else for the identical pattern** (a "latest per X"/
`orderByDesc`/`first()` selection with no upper bound, where a DIFFERENT,
LATER check is bound, creating a false sense that the row is fully
protected): `scopeSufficiency()`/`policySufficiency()`'s own programme
re-fetch, `biaSufficiency()`'s `$latest`, `recoverySufficiency()`'s
`$failback`, `awarenessSufficiency()`'s alert lookup,
`communicationSufficiency()`'s delivery lookup,
`computeLatestReviewWithAudit()`, `reviewSufficiency()`, and
`existsCheck()`'s own generic `$found` — all confirmed to bound the
SELECTION itself (not merely a later derived check), no further defect of
this shape found.

**One related candidate found, NOT fixed in this pass — later RULED on and
closed. See "QA ruling — the build() programme picker" below**: `build()`'s
own top-level `$programme` fetch (no bound at all) fed
`registerSeeded`/`$obligations` for EVERY clause row before `rowFor()` even
ran — a programme for a LATER year created after a past pack's own
`periodEnd` could change which clauses render grey/amber for a past pack.
Flagged here, unfixed, pending a ruling; the coordinator's next message
ordered it fixed in this same cycle.

**Test:** new sibling test
`a_training_record_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one`
— an assigned BC-WARDEN holder with no `TrainingRecord` at all, so both
packs start red; a passing record entered today (far-future
`next_due_date`, so currency alone would have let it through) moves only
the live pack's `iso22301.7.2` row. **Confirmed to fail against the pre-fix
code**: the bound was temporarily reverted (scratchpad backup, byte-diffed
on restore), the test run — failed exactly as expected, the past pack's 7.2
row moved from `red`/"0 of 1 assigned person(s) hold a current, passed
competency record (1 not yet assessed)." to `green`/"All 1 assigned
person(s) hold a current, passed competency record." — then the fix was
restored and the test re-run green.

### QA ruling — the `build()` programme picker (closed, then REVISED by QA re-gate #10 — read that section too, below, before trusting anything in this one about `programmeAsOf()`'s own implementation)

The coordinator ruled on the "out-of-scope candidate" flagged at the end of
the QA re-gate #9 section above: fix it in this cycle. Two defects, both in
`ClauseComplianceMatrixService`:

**Defect 1 — `build()`'s own top-level `$programme` fetch was completely
unbound.** `Programme::query()->orderByDesc('year')->orderByDesc('id')
->first()` fed `registerSeeded`/`$obligations` — and so the grey/amber
"applicability not yet determined" gate — for EVERY clause row, before
`rowFor()` even ran, with no relationship to `periodEnd` at all.

**Defect 2 — a sibling hole in `scopeSufficiency()`/`policySufficiency()`'s
own separate re-fetch.** `whereNull('approved_at')->orWhere('approved_at',
'<=', periodEnd)` bounded APPROVAL, not EXISTENCE — a DRAFT programme's
`approved_at` is null, so it sailed through that filter regardless of when
it was created, and could win `orderByDesc('year')` over an approved
programme from an earlier, correct year: a 2027 draft created today making
a 2025 pack's 5.2/5.3 read red "no approved programme"/pick the wrong
programme.

**Fix (this version — SUPERSEDED, see QA re-gate #10 below) — one private
helper, `programmeAsOf()`, memoised, used by all three call sites**
(`build()`, `scopeSufficiency()`, `policySufficiency()`, which now all
agree on which programme a pack is about):

```php
private function programmeAsOf(): ?Programme
{
    return $this->memo('programme_as_of', fn () => Programme::query()
        ->where('created_at', '<=', $this->periodEnd)
        ->orderByDesc('year')->orderByDesc('id')->first());
}
```

Bound on EXISTENCE, upper-only: `created_at <= periodEnd`. APPROVAL is
deliberately not filtered inside the helper — `status` is CURRENT state (a
programme approved after `periodEnd` reads as `approved` today regardless),
so each caller judges approval itself, downstream, against `approved_at <=
periodEnd`: `scopeSufficiency()` gained this guard explicitly (it did not
have one before — an omission this fix also closed);
`policySufficiency()` already carried it, for the POLICY PLAN it reads
through this programme.

**This `created_at`-only, single-tier existence bound was ITSELF still
defective** — QA re-gate #10 found it: a LATER-YEAR DRAFT created BEFORE
`periodEnd` still won `orderByDesc('year')` over an EARLIER-YEAR APPROVED
programme, because `created_at <= periodEnd` alone does not distinguish
"existed" from "was ever approved" — both were merely permitted to exist by
this bound. The reasoning immediately below (no `year` bound, because
`year` is a label not a validity window) still holds and carries over into
the re-gate #10 fix unchanged; only the EXISTENCE-only tier-1 test itself
was replaced with an approval-based one. Left in place, struck nowhere,
because the underlying "`year` is a label" reasoning is still load-bearing
for the CURRENT fix's own fallback tier.

**No `year <= periodEnd->year` bound — a deliberate, EMPIRICALLY-tested
departure from the first-suggested fix**, stated with the reasoning: `year`
is a LABEL in this table's own established use, not a validity window —
`bcms_programmes` is unique on `(organization_id, year, name)`, explicitly
allowing two DIFFERENT programmes to share a year (`scopeSufficiency()`'s
own long-standing comment), and one approved programme is expected to keep
governing for YEARS until superseded, unlike `bcms_exercise_programmes` (a
genuinely one-per-year artefact — `exerciseProgrammeSufficiency()`'s own
`$year = periodEnd->year` EXACT match). Tried alongside `created_at` during
this fix and reverted: it broke every existing past-period reproducibility
test in this file, all of which model "the BCMS programme" as ONE ongoing
row created during the test run (`year: now()->year`) governing whatever
period is asked about, including one two years in the past. Worse than an
ordinary failure: a test comparing two now-EMPTY past-pack results against
each other (`assertSame($firstPast, $secondPast)`) passed VACUOUSLY (`null
=== null`), silently certifying nothing — only one test's explicit
`assertNotNull()` caught it in verification; the others would not have.
This reasoning is why re-gate #10's own fix (below) STILL does not add a
`year` bound anywhere.

`created_at <= periodEnd` alone required the SAME three tests to backdate
their fixture Programme's own `created_at` into the period they actually
test (`Phase11ReportingGateTest.php`:
`a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does`,
`a_training_record_or_awareness_alert_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one`,
`a_training_record_after_the_period_does_not_move_a_past_pack_but_does_move_a_live_one`)
— done, and arguably the more REALISTIC fixture in each case: a programme
governing a 2024 period ought to have existed by 2024, not have been
created today. This backdating survives unchanged into the re-gate #10
fix — it was never the wrong idea, only the SELECTION rule built on top of
it was.

**Obligations — labelled, not bound.** `bcms_programme_obligations` carries
only generic `created_at`/`updated_at`, no dated applicability history —
checked directly against the migration. WHICH programme governs a pack is
now bound (`programmeAsOf()`); WHAT each obligation currently says
(`applies`, its rationale) is not reconstructable for a past period. One
PACK-LEVEL flag, not per-row (every grey/amber-not-seeded gate already
reads the SAME `$obligations` collection): `build()`'s return gained
`'obligations_current_state' => true`. `EvidencePackService` gained a
thin `obligationsCurrentState(?Carbon $asOf, ?Carbon $periodStart): bool`
wrapper (a SECOND `build()` call, deliberately — `sectionsFor()`'s own
contract is a flat list, compared byte-for-byte by the reproducibility
tests, and must not carry a second, differently-shaped key; pack
generation is not a hot path) threaded into `generate()`'s payload as
`obligationsCurrentState`, and the evidence-pack blade prints one line near
the cover, in the `dr_systems_label` wording family: "Applicability and
exclusion rationales are shown as currently recorded, not reconstructed for
the period."

**Tests, both confirmed to fail before their fix:**

- `a_draft_programme_for_a_later_year_created_today_does_not_move_a_past_packs_own_programme`
  — an approved 2-years-ago programme (backdated `created_at`/`approved_at`,
  with a linked APPROVED policy plan — needed so 5.2 actually differs by
  which programme is picked, rather than reading "no approved policy"
  either way); a draft for a later year created today. Asserts `build()`'s
  own `programme.id`, `iso22301.5.2` and `iso22301.5.3` are byte-identical
  for the past pack, while the LIVE pack DOES pick the new draft (the
  screen's own long-standing behaviour, unchanged — a newly drafted
  programme becomes "the" programme shown immediately, because its
  `created_at` trivially satisfies `<= now()`). Verified failing twice,
  independently: with only `build()`'s own picker reverted (`programme.id`
  moved 1→2); with only the `scopeSufficiency()`/`policySufficiency()`
  sibling hole reverted, `build()`'s own picker fixed (`iso22301.5.2`
  flipped green→red) — each reverted in isolation via a scratchpad backup,
  byte-diffed on restore.
- `build_and_the_evidence_pack_carry_the_obligations_current_state_flag` —
  asserts `build()['obligations_current_state']` and
  `EvidencePackService::obligationsCurrentState()` are both `true`.
  Verified failing (temporarily removed the key, ran, restored).

**Verification:** `Phase11ComplianceTest.php` — 28 passed. `Phase11ReportingGateTest.php`
(full file, including the three tests whose fixtures needed backdating) —
37 passed, 162 assertions. `Phase11ScreensTest.php` (the screen that
consumes `build()`'s own `programme` payload directly via
`Inertia::render('Bcms/Compliance/Matrix', $this->matrix->build() + [...])`)
— 26 passed, 288 assertions. Pint `--test` and PHPStan
`--memory-limit=1G` on every touched file — 0 errors each.

### QA re-gate #10 — `programmeAsOf()`'s design ruling, implemented verbatim

QA re-gate #10 FAILED the section above on one defect, and named a second,
pre-existing one alongside it, both in `programmeAsOf()`/`scopeSufficiency()`.

**Defect 1 — `orderByDesc('year')` still let a later-year DRAFT beat an
earlier-year APPROVED programme, even under the `created_at`-only fix.**
QA's own example, `periodEnd` 2025-12-31: programme A, year 2025, `approved_at`
2025-01-15, `created_at` 2025-01-10 (genuinely governed the period); programme
B, year 2027, draft, `created_at` 2025-11-01 (created BEFORE `periodEnd` too
— `created_at <= periodEnd` alone does not exclude it). B won on `year`.
`build()` then read B's own (empty) obligations register, so EVERY row went
amber "applicability not yet determined", and 5.2/5.3 went red — for a
period A properly governed.

**Defect 2 — a pre-existing leak in `scopeSufficiency()`'s own
`status !== 'approved'` check, same lines, not previously named.**
`status` is CURRENT state, and (per the ruling) only moves FORWARD
(`draft` → `approved` → `active`). A programme approved WITHIN the period
that has since moved to `active` read red "no approved programme" for its
OWN past pack — nothing in the suite's fixtures had ever exercised
`active` before this.

**The ruling, implemented exactly as given:** `programmeAsOf()` means "the
programme IN FORCE as of `periodEnd`" —

1. **Primary** — the most recently APPROVED programme as of `periodEnd`:
   `approved_at <= periodEnd`, ordered `approved_at` desc, `id` desc.
   Status is irrelevant here — `approved_at` is stamped once, on approval,
   and never cleared, so `approved` and `active` both count.
2. **Fallback**, only when nothing qualifies under (1): the latest
   programme that EXISTED as of `periodEnd` — `created_at <= periodEnd`,
   ordered `year` desc, `id` desc. The pack then honestly shows that no
   approved programme governed the period.

```php
private function programmeAsOf(): ?Programme
{
    return $this->memo('programme_as_of', function () {
        $approved = Programme::query()
            ->where('approved_at', '<=', $this->periodEnd)
            ->orderByDesc('approved_at')->orderByDesc('id')->first();

        return $approved ?? Programme::query()
            ->where('created_at', '<=', $this->periodEnd)
            ->orderByDesc('year')->orderByDesc('id')->first();
    });
}
```

Both defects close at once: B (draft, `approved_at` null) can never qualify
for tier 1, at ALL, regardless of `year` or `created_at` — tier 1 finds A
and returns immediately, tier 2 (where `year` ordering lives) is never even
reached while a real approved candidate exists. An `active` programme's
`approved_at` still satisfies tier 1's own `<= periodEnd` check, `status`
never enters into it.

**Every "is it approved" check on `Programme` rewritten to
`approved_at !== null && approved_at <= periodEnd`, grepped for and
replaced file-wide, per the ruling's own instruction:**

- `scopeSufficiency()` — `$programme->status !== 'approved'` →
  `$programme->approved_at === null || $programme->approved_at->gt($this->periodEnd)`.
- `operationalPlanningSufficiency()` (8.1) — a SEPARATE `existsCheck()`-driven
  query over the same model, caught by the same grep:
  `Programme::query()->where('status', 'approved')` →
  `Programme::query()->whereNotNull('approved_at')` (status irrelevant;
  `existsCheck()`'s own `approved_at <= periodEnd` bound does the rest —
  the same two-part answer `programmeAsOf()`'s own primary tier gives).
- `policySufficiency()` does not check the PROGRAMME's own approval at
  all — only the linked POLICY PLAN's (`Plan.status`), untouched by this
  ruling.

**Reported, not fixed, per the ruling's own instruction (§3): `Plan.status`
carries the SAME as-of flaw in principle.** `documentedInformationSufficiency()`
(7.5, `Plan::query()->where('status', 'approved')`) and `policySufficiency()`'s
own `$policy->status !== 'approved'` read Plan's CURRENT status
(`draft|review|approved|archived`). A plan approved within a past period
that has since been SUPERSEDED (moved to `archived`) would misread the same
way A did before this fix — named here for whoever rules on Plan/Strategy
next, not fixed, exactly as instructed.

**Consistency, past and live — the live screen follows the identical
rule, and this CHANGES its previous behaviour, on purpose.** Before this
ruling, the live matrix (`periodEnd = now()`) picked "whatever is latest by
year/id", so a newly drafted, not-yet-approved programme for a later year
became "the" programme shown the moment someone started drafting it — even
overriding a fully approved, still-current programme. QA re-gate #10
overturned this: a draft must be APPROVED first, the same governance gate a
past pack is judged against. `a_draft_programme_for_a_later_year_created_today_does_not_move_a_past_packs_own_programme`'s
own live-pack assertion was inverted to match — it previously asserted
`assertNotSame($firstLiveBuild['programme']['id'], $secondLiveBuild['programme']['id'])`
(the draft wins live); it now asserts `assertSame(...)` (the approved
programme keeps winning live) plus `assertNotSame($draft->getKey(),
$secondLiveBuild['programme']['id'])`. No other screen or test asserted the
old draft-picking behaviour — `Phase11ScreensTest.php`'s own compliance-matrix
tests do not construct a competing draft programme at all, so nothing else
needed changing; checked directly, not assumed.
`ProgrammeController`'s own governance screen (left alone, per the ruling
— out of scope) does not call `ClauseComplianceMatrixService` at all.

**Tests, three failing before their fix, verified independently:**

- `a_draft_programme_for_a_later_year_created_today_does_not_move_a_past_packs_own_programme`
  (rewritten): the draft's `created_at` moved to BEFORE `periodEnd` — QA's
  own more adversarial example shape (a later-year draft that ALSO
  satisfies an existence bound, not merely one created after the period).
  A RACI fixture (critical process + accountable assignment, backdated
  `created_at`) was added so `iso22301.5.3`'s own byte-identical assertion
  is meaningful rather than trivially "red before and after" for an
  unrelated reason. Now also asserts `register_seeded` stays `true` and
  neither 5.2 nor 5.3 is `red`, and the live pack keeps the approved
  programme. Verified failing against the immediately-prior (single-tier,
  `created_at`-only) `programmeAsOf()` — the past pack's `programme.id`
  moved 1→2, confirming the two-tier design is genuinely load-bearing, not
  cosmetic.
- `an_active_programme_approved_within_the_period_is_not_read_as_unapproved`
  (new) — a `status = 'active'` programme, `approved_at` within the past
  period. Asserts `iso22301.4.1` is not red and does not read "No approved
  programme". Verified failing against the pre-fix `status !== 'approved'`
  check.
- `a_programme_approved_after_period_end_is_not_approved_for_the_past_pack_but_is_for_the_live_one`
  (new) — a programme created before `periodEnd` but approved AFTER it
  (today). Past pack: falls to the fallback tier (still the only programme
  that exists), reads red "no approved programme". Live pack: the SAME
  programme, now approved as of `now()`, reads approved. Verified failing
  against the pre-fix `status !== 'approved'` check (which read `approved`
  unconditionally, live AND past, since `status` had already moved to
  `approved` by test time regardless of period).

All three reverts were via a scratchpad backup of the fixed file, byte-diffed
on restore before re-verifying green.

**Decision table corrected (lines ~2029-2030 in the R3-era table, flagged
stale by QA):** `scopeSufficiency()`'s and `policySufficiency()`'s own rows
now read "bound on `approved_at <= periodEnd` via the shared `programmeAsOf()`
two-tier pick (primary: approved as of `periodEnd`, status irrelevant;
fallback: existed as of `periodEnd`) — see the QA re-gate #10 section" in
place of the superseded "programme picked bound `approved_at <= periodEnd`
(null-safe...)" wording, which described the OLD, single, separately-
re-fetched query this cycle deleted.

**Verification:** all seven `Phase11*` files (`Compliance`, `DemoSeeder`,
`ReportingGate`, `Screens`, `Supplier`, `Training`, `Widgets`) — 137 passed.
`BcmsRecordVisibilityTest` — 36 passed, 253 assertions. Pint `--test` and
PHPStan `--memory-limit=1G` on every touched file — 0 errors each.

### QA re-gate #11 — PART 2, the "status is current state" class, closed

QA re-gate #11 PART 1 passed the two-tier `programmeAsOf()` design outright
(173/173). PART 2 mapped the SAME defect class — a write-side service
mutating `status` (or an `is_selected`/`is_active` pointer) while leaving
the ORIGINAL approval date untouched — across every model this matrix
reads, and named three more instances. `BiaAssessment`, `ExerciseProgramme`,
`ExerciseOccurrence`, `Incident`, `ManagementReview` and `Process` were
checked and came back clean (their own write paths do not have a
superseding/reopening act that clears a status without clearing the date
that gates it) — not touched.

**Item 1 — `Plan` (`PlanService.php:184`, `supersede()`).** Archiving a
superseded approved version leaves `approved_at` set (stamped once, at
`:112`, `approve()`). Every `Plan` `status = 'approved'` string comparison
replaced with `approved_at IS NOT NULL AND approved_at <= periodEnd`:
`documentedInformationSufficiency()` (7.5, via `existsCheck()`) and
`plansSufficiency()` (8.4.1/8.4.2/8.4.4). `policySufficiency()` already
read the linked policy plan's OWN approval separately (not via `status`
since the earlier QA re-gate #9 pass) — its `status !== 'approved'` check
is now ALSO dropped in favour of `approved_at`, for full consistency (it
had not been named a defect before, but is the same class, on the same
model, and the instruction was to replace EVERY such comparison).

`plansSufficiency()` now DEDUPES BY LINEAGE. `bcms_plans` has no separate
"family id" column — `supersedes_plan_id` is a CHAIN, each version pointing
at the one it replaces. The family key used is the ROOT of that chain,
computed by walking `supersedes_plan_id` (every plan's own `id =>
supersedes_plan_id` loaded ONCE via `planFamilyRoot()`/`latestPerPlanFamily()`,
so a multi-version family costs no extra query per hop). Per family, the
LATEST version with `approved_at <= periodEnd` is kept (`approved_at` desc,
`id` desc — the file's own standard tiebreak), so a family with TWO
versions both approved by `periodEnd` counts ONCE, with the CORRECT (most
recently approved, as of the period) version's own date.

**Item 2 — `Strategy` (`StrategyService::select()`, :106-107).** Selecting
a sibling strategy flips `is_selected` to `false` on the PREVIOUSLY
selected one, with no trace of when it stopped being selected;
`approval_status`/`approved_at` are untouched by `select()`. Replaced
`is_selected = true` with `approval_status = 'approved' AND approved_at <=
periodEnd` in BOTH places: the `Iso22331_strategy` `existsCheck()` row and
`strategySufficiency()`'s own `$withSelected` count (already grouped by
`process_id`). `whereNotNull('selection_rationale')` kept — `approve()`
itself already refuses to approve an unselected strategy, so
`approval_status = 'approved'` implies it WAS selected at approval time,
but the rationale requirement is restated for clarity and because
`select()` does not universally require one (only when the strategy type
does).

**Item 3 — `Aar` — a design RULING, not a bug fix.** `AarService::reopen()`
(:728-758) sets `status` back to `draft` AND NULLS `approved_at` — no
column anywhere holds the fact that an AAR (or a PIR) was EVER finalised.
The schema is frozen and an audit-log reconstruction of the original
finalisation date is out of scope for this phase. The reads themselves are
UNCHANGED (the `approved_at <= periodEnd` bounds from QA re-gate #9 stay);
`current_state: true` was added instead, to every branch whose state
depends on `bcms_aars.status = 'final'`/`approved_at`:

- The `Iso22398_evaluation` `existsCheck()` row (both branches — a bare
  existsCheck() call had no way to carry the flag before this pass;
  `existsCheck()` gained an OPTIONAL third parameter, `?string
  $currentStateReason = null`, default null so every OTHER caller —
  documented info, operational planning, risk assessment, exercise
  design/ladder, crisis, incident response, supply chain, continual
  improvement, 22317/22331 strategy — is unaffected).
- `aarSufficiency()`'s two AAR-reading branches (missing/all-present) —
  its FIRST red branch ("no occurrence completed in the period") does NOT
  read Aar at all and deliberately stays unlabelled.
- `evaluationSufficiency()`'s (8.6) amber/green branches (the PIR arm) —
  ALREADY `current_state: true` from the Process/Tier-1 denominator (R3),
  which "simply stays true"; these two ALSO now carry a
  `current_state_reason` on top, since a reopened PIR is what would
  actually move them. The two RED (tier1-gap) branches are unaffected —
  they never read Aar.

A single reason constant, `AAR_CURRENT_STATE_REASON`: *"AAR finalisation is
read as currently recorded: an AAR reopened after the period is not
counted until re-finalised."* A new `current_state_reason` key (optional,
alongside `current_state`) carries it; the evidence-pack blade now prints
`$section['current_state_reason'] ?? '<the generic dr_systems_label-style
sentence>'` — a row WITH a specific reason gets it instead of the generic
line, not beside it, per the instruction's own "keep it minimal".

**Booked, not built — the real fix, per the ruling's own instruction:** a
stamped-once `first_finalised_at` (or equivalent) column on `bcms_aars`,
set once on the FIRST transition to `status = 'final'` and never cleared by
`reopen()`, would let `aarSufficiency()`/`evaluationSufficiency()`/the
22398 row all read a TRUE finalisation date instead of current state. This
needs its own ADR and a schema-freeze exception — named here as a follow-up
for the architect, no migration added.

**Tests, each verified failing before its own fix** (scratchpad-backed,
byte-diffed on restore before re-verifying green):

- `a_plan_approved_in_the_period_then_superseded_after_period_end_is_unchanged_for_the_past_pack`
  — v1 approved and attested within the period; v2 supersedes it TODAY
  (archiving v1's `status`, `approved_at` untouched). Asserts
  `iso22301.7.5` and `iso22301.8.4.1` are byte-identical, neither red.
  Failed against the pre-fix `status`-based reads (7.5 flipped green→red).
- `two_approved_by_period_end_versions_of_one_plan_family_count_once` — TWO
  versions of one family, BOTH `status = 'approved'`, both `approved_at <=
  periodEnd` (a deliberately unusual data state, chosen specifically to
  exercise the dedup path in isolation from `status`). Asserts the count is
  1, not 2, and `last_evidenced` is the LATER version's own date. Failed
  against the pre-fix, non-deduping read ("2 approved plan(s)").
- `a_strategy_approved_in_the_period_then_deselected_after_period_end_is_unchanged_for_the_past_pack`
  — strategy A approved and selected within the period; sibling B selected
  TODAY (deselecting A; B is deliberately unapproved with NO rationale, so
  it cannot itself mask the defect by accidentally satisfying the OLD
  null-safe `is_selected`-based read). Asserts `iso22301.8.3` is
  byte-identical. Failed against the pre-fix `is_selected`-based read
  (green→red).
- `every_aar_dependent_row_carries_current_state_in_the_past_and_the_live_pack`
  — a completed occurrence in EACH of the past and live windows (so
  `aarSufficiency()` reaches its AAR-reading branch, not its unrelated
  "no occurrence completed" one). Asserts `current_state` on
  `iso22398.exercise_evaluation`, `iso22301.8.5.report` and `iso22301.8.6`,
  in BOTH packs, plus the exact reason string on the 22398 row. Failed
  against the pre-fix code with the flag/reason genuinely absent (not
  merely a different value) on the 22398 existsCheck row and both
  `aarSufficiency()` branches.

**Classification table corrected** (§17's original table and the §17-follow-up
sweep table both): `documentedInformationSufficiency()`, `strategySufficiency()`'s
sub-read, `plansSufficiency()`, `aarSufficiency()` and `evaluationSufficiency()`'s
PIR arm all updated in place, struck through where the prior text was
simply wrong rather than merely incomplete.

**Verification:** all seven `Phase11*` files — 141 tests total this pass
(`Compliance` 28, `DemoSeeder` 2, `ReportingGate` 43, `Screens` 26,
`Supplier` 13, `Training` 19, `Widgets` 10). `BcmsRecordVisibilityTest` —
36 passed, 253 assertions. Grand total 177. Pint `--test` and PHPStan
`--memory-limit=1G` on every touched file — 0 errors each.

### Left undone, named rather than guessed at

- ~~`EvidencePackService::drTestReport()`'s corrective-action chain still
  shows every action tied to the finding regardless of period — only its
  `age_days` was bounded to `$asOf` (R3's named defect). A chain-membership
  bound (only actions raised on/before `$asOf`) was not requested and was
  not added.~~ **WRONG — it WAS requested, in code review #2's own R3, and
  code review #3 (D1) correctly called this entry out as a self-contradiction.
  Fixed; see "Code review #3 — D1/D2 and the advisories" below.**

### Code review #3 — D1/D2 and the advisories

Code review #3 REJECTED on two blockers (D1, D2) with seven advisories
("apply now") and one to book. R1/R2/R4/R5/R6 from the same review were
already confirmed fixed; 191/191 green before this pass.

#### D1 — `EvidencePackService::drTestReport()` still moved on a later write

Criterion 2's own examiner content ("show me your last DR test report and
the corrective actions arising") had only `age_days` bounded to `$asOf` —
the finding lookup and the corrective-action set were both still unbounded.

- Finding lookup: `where('dr_test_id', ...)` now ALSO carries
  `where(fn ($q) => $q->whereNull('raised_at')->orWhere('raised_at', '<=', $asOf))`
  (null-safe, matching the file's own established convention).
- Corrective actions: `->where('created_at', '<=', $asOf)` on the query
  itself — an action opened after `$asOf` cannot evidence a past pack.
- `completed_at`/`age_days`: rendered ONLY when `completed_at <= $asOf`
  (`$completedByAsOf`); otherwise the action is treated as still OPEN at
  period end, and its age is measured to `$asOf`, not `now()`.
- `status`: derived `'open'`/`'completed'` from that same
  `$completedByAsOf` boolean, never the raw six-value
  `CorrectiveActionStatus` (`open|in_progress|completed|verified|overdue|
  accepted_risk`) — only "completed" is stamped by a column this pack can
  bound (`completed_at`); "in progress" has no date marker at all, and
  "verified"/"accepted_risk" have their own dates but the pack's own
  examiner question only asks open-vs-done. Each action carries
  `current_state: true` + `current_state_reason` (`CORRECTIVE_ACTION_STATUS_REASON`),
  and the blade prints ONE line under the table (not per-row — "keep it
  minimal").
- The "Left undone" entry from earlier in this file, claiming this bound
  "was not requested", was ITSELF wrong — code review #2's own R3 asked
  for exactly this. Struck through above, not deleted.

**Test**, verified failing before the fix (a scratchpad-backed revert,
restored and byte-diffed afterwards):
`dr_test_report_does_not_move_for_a_past_period_when_a_corrective_action_is_added_or_closed_after_it`
— an action added, and an existing one closed, both TODAY (after the past
pack's own `$to`). Past report byte-identical; live report moves.

#### D2 — three matrix reads neither bounded nor labelled, each with a cheap bound

- `aarSufficiency()`'s completed-occurrence set read live `status =
  'completed'`. `OccurrenceExecutionService::complete()` (:80-84) stamps
  `actual_end` in the SAME write — now
  `whereNotNull('actual_end')->where('actual_end', '<=', periodEnd)`,
  `scheduled_date` window unchanged. The red "no occurrence completed"
  branch needed no label — it is a genuine, reconstructable fact once
  bound this way.
- `Iso22398_ladder`'s `existsCheck()` read the same live `status`. Same
  `actual_end` bound, applied EXPLICITLY on the query passed in — NOT via
  `existsCheck()`'s own generic per-model column, because the SIBLING row
  `Iso22398_exercise_design` (same model, immediately above) needs a
  merely SCHEDULED occurrence to count, not a completed one — one static
  per-model column cannot serve both call sites (see A1 below).
- `scopeSufficiency()`'s exclusion-with-no-rationale count had no
  `created_at <= periodEnd` bound at all — an exclusion row added TODAY
  could make a past pack amber. Added.

**Test**, verified failing before the fix (same scratchpad-revert
discipline): `a_past_period_pack_does_not_move_on_a_new_write_but_a_live_one_does`
extended with an occurrence SCHEDULED inside the past window but COMPLETED
today (`actual_end = now()`), and a scope exclusion with no rationale added
today. Two fixture traps found and worked around while writing it, both
noted inline in the test: a baseline occurrence had to be seeded BEFORE the
first snapshot so the new one's `scheduled_date` (which legitimately falls
inside the past window) did not ALSO flip `iso22398.exercise_design`'s own
existsCheck (untouched by D2, bound on `scheduled_date` alone); and the new
`ProgrammeScopeItem`'s default `scopable_id` (a fresh `Process`, which
defaults to `status = 'active'`) had to be pinned to an explicitly
`'retired'` Process, or it would have moved every OTHER Process-denominator
row (raci/bia/strategy/8.6) for a reason unrelated to this test.

#### A1 — `bestDateColumn()`'s per-call `Schema::hasColumn()` lookups replaced with a static map

Roughly 40 `information_schema` round trips per `build()`. Replaced with
`DATE_COLUMN_BY_MODEL`, a reviewed, exhaustive map — one entry per model
`existsCheck()` has ever been called with in this file, each confirmed to
reproduce the SAME column the old priority-ordered `Schema::hasColumn()`
scan picked. Before/after mapping (unchanged in every case — a lookup-
mechanism change only):

| Model | Column (old dynamic scan and new static map agree) |
|---|---|
| `BiaAssessment` | `approved_at` |
| `Incident` | `declared_at` (has no `approved_at`/`carried_at`/`completed_at`; `declared_at` outranks `detected_at` in the old priority order) |
| `Strategy` | `approved_at` |
| `ExerciseOccurrence` | `scheduled_date` (no `approved_at`/`carried_at`/`completed_at`/`declared_at`/`detected_at`) |
| `Aar` | `approved_at` |
| `Plan` | `approved_at` |
| `Programme` | `approved_at` |
| `CorrectiveAction` | `carried_at` (no `approved_at`) |
| `Dependency` | `created_at` (no other priority column at all) |

`ExerciseOccurrence` stays `scheduled_date` in the map even though
`Iso22398_ladder` (D2) needs `actual_end` — that row's own query is bound
explicitly, bypassing the map, exactly because the SAME model answers two
different questions at its two call sites (see D2 above).

#### A2 — `obligationsCurrentState()` ran a whole second `build()`

`generate()` used to call `sectionsFor()` (one `build()`) AND
`obligationsCurrentState()` (a second, independent `build()`) for one
boolean. A new private `sectionsAndObligationsFor()` runs `build()` ONCE
and returns both; `sectionsFor()` is now a thin wrapper over it (public
contract, and the byte-for-byte reproducibility comparison, unchanged).
`obligationsCurrentState()` itself is KEPT as a standalone public method
(a test calls it directly, and it is a legitimate single-flag entry point)
— only `generate()`'s own double-call was fixed.

#### A3 — `build()` gained `newer_draft_programme` (backend half only)

```php
'newer_draft_programme' => ['id' => int, 'year' => int] | null,
```

The latest programme CREATED AFTER the governing one (`programmeAsOf()`'s
own pick) that is NOT approved as of `periodEnd` — `null` when none exists.
Ordered `created_at` desc, `id` desc (the newest DRAFT, not the highest
`year` label — `year` is not a validity window in this table, per
`programmeAsOf()`'s own docblock). Computed by a new private
`newerDraftProgramme(Programme $governing): ?array`. **Not wired into any
JSX** — `Matrix.jsx:113`'s own copy change (saying which approved
programme governs, and that a newer draft is not used until approved) is
left for a frontend engineer, per the instruction. The prop shape above is
the exact contract.

#### A4 — `Store`/`AssessBcmsTrainingRecordRequest::authorize()`, a bad `user_id`

Two distinct defects, reconciled to two distinct correct HTTP outcomes:

1. An ARRAY `user_id` made `User::query()->find()` return a `Collection`,
   hitting `subjectVisibleToActor()`'s typed `?User` parameter and 500ing,
   where a MALFORMED submission should 422. Fix: a non-scalar `user_id`
   (not an `int`, not a numeric string) is never handed to `find()` at all
   — `authorize()` returns `true` for it, DEFERRING to `rules()`'s own
   `'integer'` rule, which already refuses an array with a proper 422,
   field-level message. (`AssessBcmsTrainingRecordRequest` reads
   `$record->user_id` — a trusted, already-scalar column, never raw
   request input — so this half does not apply there.)
2. A well-FORMED but UNRESOLVABLE `user_id` (a soft-deleted user, or one
   genuinely missing) made `authorize()` `return true`, deferring the
   scope question to `Rule::exists('users', 'id')`, which carries no
   `whereNull('deleted_at')` and so PASSES for a soft-deleted row even
   though `User::query()->find()` (which DOES apply the model's own
   `SoftDeletes` global scope) returns `null` for it. Both requests now
   FAIL CLOSED (`return false`, a 403) when the subject cannot be
   resolved.

**Tests** (`Phase11TrainingTest.php`): `a4_an_array_user_id_on_store_is_refused_with_a_422_not_a_500`,
`a4_a_soft_deleted_subject_on_store_fails_closed_not_open`,
`a4_a_soft_deleted_subject_on_assess_fails_closed_not_open`.

#### A5 — objectives' targets and scope items' rationale, labelled

Neither `bcms_objectives.target_value`/`target_date` nor
`bcms_programme_scope.rationale` carries any dated history of its own — the
ROW's existence is bound (`created_at <= periodEnd`), but the CONTENT
(whether a target/rationale is currently populated) could have been edited
at any point after the period with no trace. The amber/green branches of
`objectivesSufficiency()` (6.2) and `scopeSufficiency()` (4.1-4.3) — which
read that content — now carry `current_state: true` +
`OBJECTIVE_CURRENT_STATE_REASON`/`SCOPE_RATIONALE_CURRENT_STATE_REASON`
respectively. Neither method's own RED branch (mere existence/no-programme)
is content-dependent and stays unlabelled.

#### A6 — `cbnContent()`: `submitted_at` and incident `status` are current state

`incidents` is bounded `[$from, $to]` by `detected_at`, but two fields on
it are not: an incident's own `status` (open/contained/recovering/closed/
cancelled) moves AFTER `detected_at`, and a notification's `submitted_at`
may not have happened yet as of `$to` and is read as of TODAY instead. One
label, `incidents_current_state_label`, in the `dr_systems_label` wording
family, printed once above the incident table (not per-row/per-field).

#### A7 — `plansSufficiency()` loaded full `Plan` rows, including `content`

`get()` with no column list also loaded the frozen plan body JSON, never
read by this sufficiency check. `->get(['id', 'approved_at'])` — the only
two columns the family-reduce (`latestPerPlanFamily()`) and
`$approved->pluck('id')`/`->max('approved_at')` ever touch.

#### A8 — booked, not built: align the home and board-pack programme pickers with `programmeAsOf()`

Two more `Programme` pickers exist outside `ClauseComplianceMatrixService`,
neither touched this pass (explicitly out of scope — "book, don't do"):

- `App\Presenters\Bcms\BcmsHomePresenter::programme()` (:76) —
  `Programme::query()->where('year', now()->year)->orderByDesc('id')->first()`.
- `App\Services\Bcms\MaturityService` (:78) — the SAME shape,
  `Programme::query()->where('year', now()->year)->orderByDesc('id')->first()`,
  used when computing a maturity assessment; its own output (the maturity
  score/trend) surfaces in the board pack's posture summary, which is the
  "board-pack picker" this item's own name refers to.

Both pick "the programme for the CURRENT calendar year", a different rule
from `programmeAsOf()`'s own two-tier "approved as of `periodEnd`, else
existed as of `periodEnd`" — neither is period-aware at all (both always
read against `now()->year`), so neither has the SPECIFIC defect this
module's own re-gates #10/#11 closed (a later-year draft beating an
earlier approved programme), but both would benefit from the same design
for consistency, in a later pass. `App\Http\Controllers\Bcms\ProgrammeController`
(:42-46) is the ORIGINAL unbound `orderByDesc('year')->orderByDesc('id')`
shape too, but was separately ruled out of scope earlier in this cycle
("ProgrammeController's own governance screen is out of scope; leave it
alone") — named here again for completeness, not re-opened.

**Verification, this pass:** all seven `Phase11*` files (`Compliance` 28,
`DemoSeeder` 2, `ReportingGate` 44, `Screens` 26, `Supplier` 13, `Training`
22, `Widgets` 10), plus `BcmsRecordVisibilityTest` (36) and
`Phase10IncidentTest` (64) — **245 tests total, 0 failures.** Pint
`--test` and PHPStan `--memory-limit=1G` on every touched file — 0 errors
each.

### Code review #4 — two small blockers, one advisory folded in

Everything from review #3 confirmed closed. Two new, narrower defects in
the SAME family, plus advisory A1 done together with E1 per the
instruction.

#### E1 — `drTestReport()`'s finding printed LIVE `status`

`EvidencePackService.php` printed `$finding->status` as-is — a finding
open at the end of Q1 and closed in Q2 printed "(closed)" when the Q1 pack
was regenerated, even though every OTHER read in this method was already
bounded to `$asOf`. Confirmed directly against the write path:
`FindingService::close()` (:165-169) AND `::acceptRisk()` (:179-184) BOTH
stamp `closed_at` on every closure — so `closed_at !== null && closed_at
<= $asOf` correctly derives open/closed either way a finding was shut, the
identical shape `CORRECTIVE_ACTION_STATUS_REASON` already uses for
corrective actions. `bcms_findings.status` is
`open|in_progress|closed|accepted_risk` — the finer distinction
(in-progress vs open; accepted-risk vs a plain close) is not derivable
from `closed_at` alone, so the finding array now carries
`current_state`/`current_state_reason` (`FINDING_CURRENT_STATE_REASON`).

**Advisory A1, folded into the SAME reason line, not a second one**
("keep it minimal"): `description` is live content with no dated history
either — an edit after `$asOf` leaves no trace — named in the one printed
sentence alongside the status derivation limit, not a separate blade line.

**Test**, verified failing before the fix (scratchpad revert/restore):
`dr_test_report_does_not_move_for_a_past_period_when_the_finding_is_closed_after_it`
— the finding closed TODAY, strictly after the past pack's own `$to`. Past
report byte-identical; live report moves.

#### E2 — `bestDateColumn()` returned `null` for an unmapped model, silently

`existsCheck()` then applied no bound and no label — a NEW `existsCheck()`
call added later, for a model nobody had reviewed a date column for, would
fail open with no signal. `bestDateColumn()` now throws a `LogicException`
naming the model instead of returning `?string`; `existsCheck()` simplified
accordingly (the `$column === null` branches it used to carry are gone —
`$column` is now always a real, reviewed column or the method never
returns at all). If a model is ever genuinely meant to stay unbounded, the
map needs an EXPLICIT entry that forces a current-state reason via
`existsCheck()`'s own `$currentStateReason` parameter — none of the 9
current callers needs one today.

**Test**, verified failing before the fix (same discipline):
`best_date_column_throws_for_a_model_with_no_reviewed_entry`
(`Phase11ComplianceTest.php`) — reflection-invokes the private method
directly with `ManagementReview` (a real model, deliberately not one of
the 9 mapped ones), matching this file's own established reflection-test
style (`Phase11DemoSeederTest::runPhase11Seeding()`).

**Verification, this pass:** `Phase11ReportingGateTest.php` (45, includes
the evidence-pack PDF render test), `Phase11ComplianceTest.php` (29),
`Phase11ScreensTest.php` (27, including a pre-existing test exercising
A3's `newer_draft_programme` field, unrelated to this pass and already
green). Pint `--test` and PHPStan `--memory-limit=1G` on every touched
file — 0 errors each. DB `risk_test_p11_b9babae` only; every test run kept
to one process at a time, none over ~40 seconds.
