# BCMS Phase 1 — programme governance and the CAPA core

**Track:** A · **Weeks 2–3** · **Lead:** compliance-analyst · **Depends on:** G0

The phase prompt opens with an instruction that shaped everything below: *this
phase ships two things every other track depends on — the `Finding` →
`CorrectiveAction` core, and the maturity scoring engine. Build them first, not
last.* They were built first.

---

## 1. What landed

| Layer | Files |
|---|---|
| ADR | `docs/adr/0008-phase-1-schema-additions.md` — **the first structural migration after the freeze** |
| Schema | `2026_09_10_120001_create_bcms_governance_tables.php` — 7 tables, 6 columns, 1 dropped; manifest regenerated in the same commit (53 → **60 tables**) |
| Enums | `FindingSource`, `RaciRole`, `MaturityClauseGroup`; `PlanType::Policy` added |
| Models | `ManagementReview`, `ProgrammeScopeItem`, `ProgrammeObligation`, `RaciAssignment`, `MaturityAssessment`, `MaturityScore`, `PlanAttestation` (+ 7 factories) |
| Services | `Findings\FindingService`, `Findings\CorrectiveActionService`, `Integration\ErmBridge`, `MaturityService`, `PolicyService`, `ProgrammeService`, `RaciService`, `ProcessCatalogueService` |
| HTTP | `ProgrammeController`, `PolicyController`, `ProcessController`, `FindingController`, `ProgrammePresenter`, 3 form requests, 27 routes |
| Front end | `Bcms/Programme/Index`, `Bcms/Policy/Index`, `Bcms/Processes/Index`, `Bcms/Findings/Index` |
| Console | `bcms:sweep-actions`, daily at 06:45 |
| Seeds | 50 processes (10 critical services, each justified), the policy approved and board-attested, scope with one justified exclusion, 13 obligations, RACI over 44 of 50, 4 objectives with baselines, one management review, one worked finding |
| Tests | `Phase1GovernanceTest` (30), `Phase1ScreensTest` (17) |

## 2. The nine acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | Programme + 50 processes + approval, with a complete audit trail | **Pass** — the trail asserts actor, timestamp and before/after on each state change |
| 2 | Policy drafted → approved → attested → superseded; v1 immutable and retrievable | **Pass** |
| 3 | 200-row import: dry run reports 6 errors, corrected file imports, export round-trips | **Pass** — and an import with *any* invalid row writes nothing at all |
| 4 | RACI gap report finds processes with no accountable owner | **Pass** — plus the gap that hides: an accountable owner who has left |
| 5 | Branch manager sees only their branch; group risk officer sees all — **both directions** | **Pass** — and a third case: a user assigned to nothing sees organisation-level rows only, never everything |
| 6 | Maturity computes from artefact presence and moves when one is added | **Pass** |
| 7 | Every object carries `iso_clause_ref`; clauses 4, 5, 6.2, 8.1, 8.6, 9.3 evidenced | **Pass** — 9.3 needed the ADR |
| 8 | A finding of each classification, from each source, through to verification | **Pass** — all seven sources, all three classifications, before any producer phase exists |
| 9 | One maturity scorer; a grep confirms no second | **Pass** — asserted as a grep, not as a promise |

## 3. The decisions worth not re-litigating

**The first post-freeze ADR was the one Phase 0 predicted.** ISO 22301 clause
9.3 had no home table; `docs/bcms/phase-0-notes.md` §5.1 said Phase 11 would need
it and that it would be the first ADR against standing rule 2. Phase 1 needed it
sooner. The freeze worked exactly as designed: `bcms:verify-schema` named all
fifteen changes, the ADR argued them, and the manifest was regenerated in the
same commit.

**A management review has no action register of its own.** Actions arising are
corrective actions against a finding whose source is `management_review`.
Building a second register would give a customer two places to look for "what did
we agree to do".

**A nonconformity cannot exist without a clause, and cannot be closed without a
verified action.** Clause 10.1 defines a nonconformity as a failure to meet *a
requirement*; one recorded without naming it is an opinion. `FindingService`
refuses it with an exception rather than silently downgrading it to an
observation — quietly reclassifying somebody's finding is worse than making them
say which clause.

**Verification refuses the owner by name, not just by permission.**
`bcms.finding.verify` answers "may this person verify anything";
`CorrectiveActionService::verify()` answers "may they verify *this*". Both are
needed, because the service is also called from a job where no permission check
runs.

**A ratio with no denominator scores null; an absent artefact scores 1.** The
two look alike and are not. An empty process catalogue has no BIA coverage rate —
a rate over nothing is undefined — but an organisation with no BC policy is
squarely at level 1, because the artefact either exists or it does not.

**The exercise clause group cannot reach 4 on documentation alone.** Blueprint
§2.4 is the product thesis: certification proves you wrote plans, only testing
proves readiness. A complete plan library with no delivered exercise programme
stops at 3, and a screen that let it score 5 would be selling the failure this
module exists to prevent. Asserted by test.

**BCMS creates no ERM risks.** TPRM does, because a critical vendor is a
concentration a risk committee must see in the register. A missed fire drill is
not a new risk — the disruption it exercises is already there — and "Fire drill
overdue at Kano branch" as a risk row would fill the register with
programme-management noise. Continuity exposure reaches the register through the
KRIs the objectives are measured by, which is the join Blueprint §4.2 actually
asks for.

## 4. Two defects found on the way, one of them not ours

**`date_identified` is not a column on `issues`.** The BCMS ERM bridge was
modelled on TPRM's, which passes that key when creating a mirrored issue. It is
silently dropped by mass-assignment guarding in a request and **throws inside a
seeder**, because Laravel unguards during seeding. Fixed here; **TPRM still has
it**, which means every TPRM finding mirrored into the issue register believes it
recorded an identification date and did not. Raised as separate work.

**`BelongsToOrganization` typed its boot closure against `Model` rather than
`self`.** In a trait `self` resolves to the using class, so larastan could see
nothing on it — one baseline entry per tenant-scoped model, 116 of them, and BCMS
Phase 0 added 57 more in one commit. The baseline's own comment already said "the
fix belongs in the traits"; a one-word change removed all 173. `HasObjectIdentity`
has the identical defect and was deliberately **not** touched: no BCMS model uses
it, so BCMS added nothing to it.

## 5. Known gaps handed forward

1. **MeiliSearch.** The prompt asks for process search via MeiliSearch. This
   product has neither Scout nor MeiliSearch installed; search is a database
   `LIKE` across name, code and description. Adequate at 50 processes and it
   will not be at 5,000. Installing a search stack is an infrastructure decision,
   not a Phase 1 one.
2. **The plan `content` editor is a JSON field, not a rich-text editor.** The
   policy screen renders and versions the document; authoring it is a Phase 3
   concern (the plan builder), and building a second editor here would be thrown
   away.
3. **`bcms_programme_obligations.how_satisfied` is not yet joined to the
   exercise cadence it drives.** The column and the `cadence_per_year` are
   there; the comparison — "this obligation requires four a year and the
   calendar has two" — needs Phase 4's occurrences to compare against.
4. **The management review record has no PDF export.** Phase 11 owns the
   evidence packs.
5. **RACI is assignable per process and per programme, and the UI exposes only
   the process side.** The programme-level assignment works through the service
   and the maturity engine reads it; the screen for it lands with Phase 11's
   governance views.

## 6. HANDOFF

**Phase:** P1 — Programme governance & process catalogue
**Agent:** compliance-analyst (lead), architect (ADR 0008), backend-engineer, frontend-engineer
**Status:** complete
**Delivered:** as §1
**Contracts touched:** `Finding` + `CorrectiveAction` — **now implemented, not just schema.** Producers call `FindingService::raise()` and nothing else; `carried_to_occurrence_id` remains unwritten and a test greps the write layers to keep it that way. The maturity engine is live and single-owner. `bcms_processes` is populated, so **Track B can bind `process_ids` on exercise definitions**.
**Assumptions made:** the BC policy is a `bcms_plans` row; a management review's actions are corrective actions; observations are not mirrored to the issue register
**Known gaps:** the five in §5
**Next agent:** Track A Phase 2 (the BIA engine runs against this catalogue). **Broadcast to Track B: the process list and criticality tiers are live.** Broadcast to Tracks B, C and D: the CAPA core is ready to consume — call `FindingService::raise()`, do not write `bcms_findings` directly
**Verification run:** the **full suite — 3,180 passed, 4 skipped** (one guard needed its list updated, below); `bcms:verify-schema` exit 0 against the regenerated manifest; `pint --test` clean; PHPStan **7 findings, all pre-existing and none in BCMS** (down from ~20, because the trait fix removed a whole class); a fresh `migrate --seed` green on MySQL 8; `npm run build` green

### The one guard that turned red, and which of the three kinds it was

`NullOrganizationRowsAreDeclaredGlobalTest` pins the set of models that treat a
NULL `organization_id` as "shared with every tenant". BCMS Phase 0 added seven —
the exercise-type catalogue, the readiness templates and their tasks, the
blackout calendar, the alert templates, the scenario library and the training
curricula.

Development standard §11 asks which of three kinds a red guard is before
touching it. This is the first: **bookkeeping that is now stale.** The
declaration is the mechanism ADR 0006 requires, the shipped rows are proved
visible to a tenant by `Phase0FoundationsTest`, and the assertion's own message
says to update the list and say why. `bcms_clause_refs` is deliberately not on
it — that table has no `organization_id` column at all.
