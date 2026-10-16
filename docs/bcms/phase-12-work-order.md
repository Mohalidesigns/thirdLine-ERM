# BCMS Phase 12 — AI layer · hardening · work order (offline/USSD deferred)

**Track:** E · **Lead (accountable for G3):** reliability-engineer · **Decided by:** ADR 0023 **as amended (Amendment 1, 2026-09-24)**, and ADR 0024 as a prerequisite
**Branch:** written on `integration/bcms-remaining` (Phases 9+10+11 committed at `066ca26`; ADR 0022 at `55d8e2f`). All of that is on `main` now; see §1's 2026-10-16 update
**Schema:** Phase 12 adds **one column** (`bcms_exercise_definitions.ai_generated`), as 12.0. ADR 0024's `bcms_alerts.template_variables` lands before Phase 12 and is Phase 7 remediation
**Prompt:** `plans/bcms/prompts/PHASE-12-ai-offline-hardening.md`. Read it through ADR 0007, because it still says Prism, AntD and `docs/design/`

**G3 posture (owner decisions):**
- **BUILD + PREPARE.** Everything buildable and provable in-repo on this machine is built and proven. Everything that needs the outside world is a prerequisite with an owner.
- **USSD and the offline PWA are deferred**, and **live SMS is on hold**, both until a client deployment is being prepared. G3 criteria 3 and 4 are therefore **deferred, not pending**.
- AI runs on **local Ollama** for every capability that needs a model.
- G3 is declared **"engineering complete, external sign-offs pending; offline and USSD deferred by owner decision"**, and never as more than that.

Read before writing a line:
- `docs/DEVELOPMENT_STANDARD.md`.
- ADRs **0023 (start at Amendment 1)**, **0024**, 0007, 0010, 0015 §9–10, 0016, 0017, 0019, 0020 and 0022.
- `plans/bcms/BCMS-ORCHESTRATION.md` §5, §7 and §8, and `docs/bcms/WORKING-AGREEMENT.md`.
- `docs/compliance/ndpa-register.md` §13, where requirements R1–R4 bind 12A-1.
- `docs/bcms/content/*.md`, the stage-0 content packs.
- `docs/performance/baseline.md`.
- `docs/bcms/phase-7-handoff.md`. Phase 7 criteria 1, 2, 6 and 12 were carried to G3, and 10 on translation.

---

## 1. Preconditions and the pre-Phase-12 queue

**Update 2026-10-16.** Everything in this section through `066ca26` is now on `main`; production
runs `main` (`463e0b3`). **P-1 has landed** (`eaea1c3`) and **P-2 has landed** (`d4871f2`, ADR 0024).
**P-3 has not**: `PlanTemplates.php` still stamps `bcp_payments` with `cbn.open_banking.threshold`.
No Phase 12 code exists yet: `bcms_exercise_definitions` has no `ai_generated` column, and
`PlanAiDrafter` still sends contact numbers and emails to the model (NDPA §13.2, R1). Phase 12 work
branches from `main`, not from `integration/bcms-remaining`. Two numbers moved when these documents
were committed after the post-go-live hardening (PR #14), which took the register's §12 and DPO
questions 17–20: the Phase 12 register section is **§13**, and its DPO questions are **21–23**.
BCMS Phase 7 still owes its QA and review gates (`docs/bcms/phase-7-handoff.md`) before
`FEATURE_BCMS` can be switched on in production. That certification is separate from this work order.

Status on 2026-09-24:

1. **The combined Phase 9+10+11 commit has landed** (`066ca26`), and so has ADR 0022 (`55d8e2f`).
   Both of the original blockers are cleared.
2. **These land ahead of Phase 12, in this order, each gated by QA and then code review as its own
   commit:**
   - **P-1, the standalone EMNS rendering fix** (in the working tree now). It makes channel renderings
     substitute from one variable map and fail closed on any unfilled `{{…}}`. It also stops
     `SmsSegmenter::truncate()` from appending `…`, which forced UCS-2. The Pidgin templates go
     inactive until review (owner decision 4). All of this was stage-0 finding (e) and is **no longer
     in 12A-2's scope**.
   - **P-2, ADR 0024: template variables on alerts.** It is urgent, because the client demo cannot
     send EVACUATE until it lands.
   - **P-3, the `bcp_payments` clause stamp** changes to `iso22301.8.4.4` (ADR 0023 A1.3 f). This is
     one line in `app/Support/Bcms/PlanTemplates.php`. compliance-analyst confirms it. It can land any
     time and is independent of everything else.
3. **Nothing is pushed and no PR is opened** without the owner's instruction. A merge to `main`
   triggers the production deploy.
4. **Ollama is installed but not running** (`granite4:micro`, 2.1 GB). Only the live run in 12A-2
   needs `ollama serve`. **No test may need it** (§10, trap 3).

---

## 2. Sub-phases

Each sub-phase runs **implementers → `qa-engineer` (gate 1) → `code-reviewer` (gate 2) → its own
commit**. After any defect fix, the cycle restarts at `qa-engineer`. QA runs the **full** suite for a
verdict. The main session runs anything longer than 10 minutes in the background.

| Id | Name | Lead | Also R | Depends on | Parallel with |
|---|---|---|---|---|---|
| **12.0** | Schema: `bcms_exercise_definitions.ai_generated` | backend-engineer | — | P-1, P-2 committed (both touch the manifest; see §7) | nothing |
| **12A-1** | NDPA R1 fix to `PlanAiDrafter` **first**, then the provenance spine (with redaction), capabilities 7 and 8, and the guardrail suite | backend-engineer | compliance-analyst (R1–R3 verification) | 12.0 | 12D-1a |
| **12A-2** | Capability 2 (scenario generator), capability 6 (alert composer), content conversion, screens, live run | backend-engineer | compliance-analyst, ui-designer (specs drafted in stage 0), frontend-engineer | 12A-1, **P-1 and P-2** | nothing that touches EMNS |
| **12D-1a** | Hardening code: scheduler dead-man, guard tests (MFA entry points, no AD write-back, secrets, DSAR), on-prem scripts | reliability-engineer | integrations-engineer, backend-engineer | 12.0 | 12A-1 |
| **12D-1b** | Redis-stall degraded mode on the dispatch path | reliability-engineer | backend-engineer | **12A-2 committed** (`AlertService`) | nothing |
| **12D-2** | Measurement (load, chaos, local DR rehearsal) plus runbook and pen-test scope | reliability-engineer | qa-engineer, integrations-engineer | 12A-2, 12D-1a and 12D-1b all committed | **nothing: it needs a quiet machine** |
| **12E** | WCAG whole-surface audit, 100 kbps runs, documentation set, demo tenant, G3 dossier | ui-designer (audit) | frontend-engineer, compliance-analyst, qa-engineer, reliability-engineer (dossier) | 12D-2 | — |
| ~~12B~~ | ~~USSD~~ | **Deferred**: not in Phase 12 (Appendix A) | | | |
| ~~12C~~ | ~~Offline PWA~~ | **Deferred**: not in Phase 12 (Appendix A) | | | |

**If G3 slips, 12A-2 is cut first** (Orchestration §4). 12A-1 does not depend on it. If capabilities
2 and 6 are cut, the guardrail suite covers the remaining six and G3 criterion 2 says so.

**Concurrency ceiling:** at most **two implementing agents at once** in this reduced phase, plus the
gates. Each agent gets its own test database (`risk_test_p12a_…`), and **never two runs on one
database**.

---

## 3. Schedule

```
stage 0  DONE     content packs (docs/bcms/content/), NDPA register §13, screen specs
pre-12   P-1 EMNS rendering fix ─ QA ─ review ─ commit
         P-2 ADR 0024 template variables ─ QA ─ review ─ commit       ← before the client demo
         P-3 bcp_payments one-liner (independent, any time)
stage 1  12.0 schema ─ QA ─ review ─ commit
stage 2  12A-1  ‖  12D-1a
stage 3  12A-2
stage 4  12D-1b
stage 5  12D-2 measurement            (alone on the machine)
stage 6  12E
stage 7  G3: full suite (main session) · dossier · architect + reliability + code-reviewer sign the engineering half
```

---

## 4. Sub-phase detail

### 12.0 — Schema

`database/migrations/2026_09_25_120001_bcms_phase12_definition_ai_flag.php` adds
`boolean('ai_generated')->default(false)`. Schema builder only, no timestamp, SQLite-safe `down()`.
It sorts after ADR 0024's `2026_09_24_120001`. Also: `bcms:verify-schema --write` and the
`ExerciseDefinition` `$fillable` and casts.
**Proof:** `tests/Feature/Bcms/Phase12SchemaTest.php` covers the default, the flag surviving an
update, and the flag present in the manifest. It also requires `ReferenceCodeConcurrencyTest` and
`NoSelfUpdatingTimestampColumnsTest` to be green.

### 12A-1 — Provenance spine (backend-engineer)

In this order:

1. **NDPA R1 and R3 first.** `PlanAiDrafter` and `SourceResolver`'s prompt-facing rows drop
   `mobile`, `email` and `phone`, and send roles rather than names where the role carries the
   meaning (the finding is at `SourceResolver.php:499-511`, `:688-693` and
   `PlanAiDrafter.php:253-261`). **The rendered plan document and the offline bundle are not
   changed.** Only what reaches the model changes. Audit the other drafters' prompt builders
   (`BiaAiDrafter`, `AarAiDrafter`, `ProgrammeAdvisor`) against the same rule. NDPA register §13.2
   already found them clean of structured channel data.
2. `BcmsLlmClient`: additive `budget` and `promptVersion` parameters, plus `usage_event_id` and
   `prompt_version` in the return (ADR 0023 §2.2).
3. `app/Services/Bcms/Ai/AiProvenance.php` **(new)** writes the §2.3 audit row, **redacted at the
   write**: E.164 and email shapes are replaced in `prompt` and `output`, and
   `redactions: {phone, email}` is stored (R2). Full text is kept, **pending DPO question 21**.
4. Retrofit a `PROMPT_VERSION` and `AiProvenance::record()` into `BiaAiDrafter`, `PlanAiDrafter`,
   `AarAiDrafter` and `ProgrammeAdvisor`.
5. **Capability 7:** the definition store path accepts `from_advisor_proposal` and sets
   `ai_generated`. Correct the advisor's docblock.
6. **Capability 8:** `app/Services/Bcms/Ai/PirLearningDrafter.php` **(new)**, with its route and
   action on the PIR. `IncidentPresenter` ships the URL only when the capability is available.
   `Incidents/Review.jsx` gets its draft button (small frontend change). It inherits
   `phase-10-incident-clause-map.md` item 10's refusals.
7. `tests/Feature/Bcms/Phase12AiGuardrailsTest.php`: one data provider over the eight capabilities,
   asserting every ADR 0023 §2.1 row:
   - the three kill switches;
   - the flag surviving an edit;
   - the draft state;
   - a human action;
   - one provenance row plus one usage row;
   - reach fields ignored;
   - **no E.164 or email pattern in any captured prompt, nor in any stored provenance context**;
   - every endpoint profile self-hosted;
   - capability 5 making zero gateway calls.
8. `tests/Feature/Bcms/Phase12AiArchitectureTest.php`: no `LlmService` import under
   `app/Services/Bcms`.

Fake the model at the HTTP boundary, with `Http::preventStrayRequests()`. Golden outputs go in
`tests/Fixtures/Bcms/Llm/*.json`, including malformed and hostile ones, and one output that
**invents a phone number**, which R2 must redact.

### 12A-2 — Capabilities 2 and 6 (backend-engineer lead)

**Out of scope here, landed earlier:** the renderer placeholder and truncation defects (P-1), and
template variables (P-2). The composer's two-segment check and its template suggestions depend on
both.

**Specs** (ui-designer; drafted in stage 0 as `docs/bcms/screens/scenario-library.md`,
`scenario-variant-review.md` and `alert-composer.md`). They must decide **who may read prompt or
output text** on any screen that shows it (NDPA §13.8). They must also place the screen or
evidence-pack section that lists `ai.draft_generated` rows (NDPA §13.10). That listing is owed
before any tenant switches AI on.

**Content conversion** (compliance-analyst reviews, backend-engineer converts):
- `docs/bcms/content/scenario-pack.md` becomes `Reference/Scenarios.php`: 44 templates and 272
  injects. `FIBRE-CUT` becomes `connectivity`.
- `threat-catalogue.md` becomes `app/Support/Bcms/ThreatCatalogue.php`, keyed by category, with
  `grounding` as the only field that reaches a prompt.
- `emns-templates.md` becomes `Reference/AlertTemplates.php` for any rows not already landed by P-1.
  `pcm` rows are seeded **inactive**.
- `BcmsReferenceSeeder` seeds `suggested_injects`.
- **`app/Enums/Bcms/InjectDeliveryChannel.php` (new)** holds the six ratified values. It is
  enforced on write and **not cast on the model** (ADR 0023 A1.3 c).

**Backend:**
- `ScenarioGenerator`, `ScenarioDraftValidator` (the enum, offsets within `duration_minutes`, and the
  field-by-field verdicts), and `GenerateScenarioVariantJob` (queue from
  `config('bcms.queues.sync')`, with the actor passed in).
- `ScenarioLibraryService` (list, activate, apply-to-occurrence), `ScenarioController`, and
  `ScenarioPolicy`, named for the model.
- Form Requests, with the category limited to `ThreatCatalogue` keys and tenant-bound `exists`.
- `ScenarioLibraryPresenter`.
- `AlertComposer` (pure; ADR 0023 §4.1), `app/Support/Bcms/Readability.php` (deterministic), and
  `AlertController::composeWithAi` with its Form Request.
- The `AlertService::release()` guard, and `confirm_ai_content` on **every** dispatch Form Request,
  the crisis room's included.
- The composer suggests **active** templates only. A suggested template's operator variables use
  ADR 0024's form. The AI never fills a template variable.

**Frontend:** `Pages/Bcms/Scenarios/Index.jsx` and `Show.jsx`, and `Components/Bcms/AiAlertComposer.jsx`,
which shows live segment and encoding counts, readability warnings and locale coverage. Verify with
`npx vite build`, `node tools/ui-audit.mjs` at 0 findings, and a browser.

**Proofs:**
- `Phase12ScenarioGeneratorTest`.
- `Phase12ScenarioPackSeedingTest`, which asserts `scenario-pack.md` §5's eleven rules plus a
  `ThreatCatalogue` coverage check and the E.164 and email scan over `grounding`.
- `Phase12AlertComposerTest`.
- `Phase12AiArchitectureTest`, extended to prove the composer cannot send.

**Live run (not a test):** `php artisan bcms:ai-live-run` on real `granite4:micro` against the Kano
Heritage demo data, covering BIA, scenario, AAR and composer. It is committed to
`docs/bcms/phase-12-ai-live-run.md` with outputs (as provenance would store them, redacted), timings,
validator verdicts, truncation events, and compliance-analyst's edit-or-rewrite rubric.

### 12D-1a — Hardening code (reliability-engineer lead)

- **Scheduler dead-man** (ADR 0023 §7): the heartbeat in `routes/console.php`, a self-re-dispatching
  checker job in `app/Jobs/Bcms/`, and an allowlisted health route.
- `Phase12NoAdWriteBackTest` (integrations-engineer): the Graph client issues only `GET`.
- `Phase12MfaEntryPointsTest` (backend-engineer): every route that can release an alert carries
  `mfa`, enumerated from the router.
- A sweep of webhook authentication on every unauthenticated BCMS route that **exists**, stating
  whether each fails closed.
- `Phase12SecretsGuardTest`.
- `Phase12DsarExportTest` (backend-engineer and compliance-analyst): one contact's data exported
  across every BCMS table the NDPA register lists. This includes `ai.draft_generated` rows found by
  **name-text search**. NDPA §13.6 records the limits of that search: the export states it searched
  by name, and it uses portable `LIKE`, never `JSON_SEARCH`.
- The on-prem package: `scripts/onprem/install.sh`, `upgrade.sh` and `smoke.sh`.

### 12D-1b — Degraded dispatch (after 12A-2)

The Redis-stall degraded mode for `AlertService`, `DispatchAlertJob` and `DispatchAlertChunkJob`.
Write-ahead is preserved, there is no schema change, and the design goes in the runbook.
**Proof:** `Phase12ChaosTest`, for the automatable items in §5 row 6.

### 12D-2 — Measurement and its documents (reliability-engineer)

- `tools/load/` holds the check-in burst (existing QR and code routes), written in Node with no
  dependencies, or in PHP with `curl_multi`.
- `app/Console/Commands/BcmsLoadSeed.php` builds a dedicated load tenant with 50,000 contacts and 520
  occurrences. **Never use the Kano demo tenant.**
- `docs/performance/phase-12-load.md`, `phase-12-chaos.md` and `availability-model.md`, plus the
  measured column in `baseline.md`.
- `docs/runbooks/bcms-self-dr.md`, including a **timed local procedural rehearsal**: restore into a
  fresh database, start the minimal stack, and dispatch a simulation.
- `docs/security/bcms-pentest-scope.md`, covering tenancy, the dispatch authorisation that can
  message 50,000 people, directory credentials, inbound webhook authentication, evidence
  immutability, and **AI provenance access** (who can read prompts). USSD and offline items are
  listed as **out of scope, deferred**, so the tester does not assume they were missed.
- `docs/bcms/guides/on-prem-install.md`, followed verbatim in a fresh directory with a fresh
  database, with `smoke.sh` run.

### 12E — Accessibility, documentation, demo, dossier

- **WCAG 2.1 AA across the whole surface:** every page under `resources/js/Pages/Bcms/` plus the
  Phase 12 additions (the scenario library and variant pages, the composer panel, the PIR learning
  section) and the compose-form variable inputs from P-2. The method is `@axe-core/playwright` per
  screen, a manual keyboard and focus-order pass, and `ui-audit` at 0. Results go in
  `docs/bcms/accessibility/g3-audit.md`.
- **100 kbps:** `tests/e2e/bcms/low-bandwidth.spec.ts` on the critical screens (§8).
- **Documentation**, in `docs/bcms/guides/`:
  - `admin-guide.md`, `bc-officer-guide.md`, and `employee-quick-start.md` (one A4 page);
  - `api-dr-ingestion.md`;
  - `api-scim.md` (the ERM core endpoint, which does not feed BCMS; ADR 0018);
  - `consultant-training.md`;
  - `demo-script.md` (the year calendar with its reminder ladder, and the broken-branch screen).
  - The guides **state plainly** that USSD, offline and live SMS are not in this release.
- **Demo tenant:** extend `BcmsDemoSeeder`. **It must not seed `ai_generated = true`** on anything a
  model did not produce. `tests/e2e/bcms/demo-walk.spec.ts` must pass.
- **G3 dossier** (reliability-engineer): `docs/bcms/phase-12-g3-status.md` holds §5 with evidence
  links, the §6 register, and the deferred-items list.

---

## 5. G3 acceptance criteria: where each one is proven

| # | Criterion | In-repo proof | External remainder | G3 state |
|---|---|---|---|---|
| 1 | AI drafts a BIA, a scenario and an AAR to reviewable quality | 12A-2: `bcms:ai-live-run` on real `granite4:micro`, with compliance-analyst's rubric in `phase-12-ai-live-run.md` | **E5**: a BC officer assesses three real cases | prepared |
| 2 | Eight capabilities pass the guardrail suite | 12A-1: `Phase12AiGuardrailsTest` and `Phase12AiArchitectureTest`; 12A-2 extends both to capabilities 2 and 6. Capability 5 is proven to make **no** model call. The R1 prompt fix and R2 redaction are asserted | — | built |
| 3 | USSD check-in on a feature phone or emulator; same record as SMS | — | — | **DEFERRED by owner decision** (client deployment). The contract is kept in ADR 0023 §5 and the plan in Appendix A |
| 4 | Offline: readable, and a check-in syncs | — | — | **DEFERRED by owner decision** (client deployment). The contract is kept in ADR 0023 §6 and the plan in Appendix A |
| 5 | Every §14 NFR met under load, numbers committed | 12D-2: `phase-12-load.md` (§8). The 10,000-recipient fan-out is measured against mock channels, and "first SMS" as **pipeline latency to the first `sent` row through a stub adapter** | Availability over time in production (X4). *Handset delivery (X1) is deferred with live SMS* | measured locally |
| 6 | Every chaos scenario passes | 12D-1b: `Phase12ChaosTest`; 12D-2: `phase-12-chaos.md`. Covers worker `kill -9` mid-dispatch, the primary **stub** gateway black-holed with failover recorded, Redis `CLIENT PAUSE`, the scheduler stopped (dead-man fires), and the dispatcher run three times | — | built |
| 7 | Self-DR rehearsed, RTO ≤ 15 min; EMNS from standby | 12D-2: the runbook plus a timed local procedural rehearsal (a simulation dispatch on the minimal stack) | **E2**: a rehearsal on real infrastructure | prepared |
| 8 | Pen test clean of highs | 12D: the scope document, plus internal pre-checks (MFA entry points, the webhook authentication sweep, tenancy on every new route, AI provenance access) | **E1**: a third-party test | prepared |
| 9 | On-prem clean-host install and smoke | 12D: scripts, the guide, and a fresh-directory dry run with `smoke.sh` | **E3**: a clean host | prepared |
| 10 | WCAG 2.1 AA whole surface; 100 kbps on critical screens | 12E: `g3-audit.md`, axe specs, the keyboard pass, and `low-bandwidth.spec.ts` | Screen-reader pass on a real device (X5) | built |
| 11 | Full regression green | Every sub-phase gate, and the final main-session run on MariaDB 10.4, logged. Also the Playwright suite, `ui-audit` at 0, and `vite build` | — | built |
| 12 | Kano Heritage populated; demo rehearsed | 12E: seeder extensions and `demo-walk.spec.ts`. **P-2 (ADR 0024) is what makes EVACUATE sendable in the demo** | Presenter rehearsal (X6) | built |

---

## 6. External prerequisites, and what is deferred to the client deployment

### 6.1 Pending at G3 (owner and definition of done)

| Id | Prerequisite | Owner | Done means |
|---|---|---|---|
| **E1** | Penetration test | Mohammed | A report against `bcms-pentest-scope.md`. **Zero open highs** after retest. Every medium has an owner and a date. The scope's named items are each exercised |
| **E2** | Self-DR rehearsal on real infrastructure | Mohammed + the hosting operator | The runbook is followed verbatim on the AZ-redundant stack. **RTO ≤ 15 min** is measured to a simulation dispatched from standby, and the result is written into the runbook |
| **E3** | On-prem install on a clean host | Mohammed | Someone other than the author follows the guide verbatim on a never-used host. `smoke.sh` passes, and the time and every deviation are recorded |
| **E5** | BC-officer assessment of AI drafts | Mohammed nominates a practising BC officer | Three real cases (a BIA, a scenario, an AAR) are scored with the rubric, with a verdict of **edit, not rewrite** per artefact and the model and prompt versions named. The same reader can do the scenario pack's realism review (`scenario-pack.md` §10) |
| X2 | Hausa, Yoruba and Igbo copy for the 11 EMNS templates; **native-speaker review of the `pcm` rows** (owner decision 4) | Mohammed (a translator and a native-speaker reviewer per language) | The rows are loaded or reviewed, activated by a human, and the coverage grid shows each language |
| X4 | Availability evidence | Hosting operator | Measured in operation. G3 does not claim it |
| X5 | Screen-reader pass on a real device | ui-designer with a tester | NVDA or JAWS and VoiceOver on the critical screens, with findings triaged |
| X6 | Demo rehearsal by the presenter | Mohammed | The script run live, end to end, by the presenter |
| DPO-21 | Full prompt text (redacted) or a hash, for AI capabilities 4, 6 and 8 | Client DPO | A ruling recorded in NDPA register §13.5. A "hash" answer amends ADR 0023 §2.3 |
| DPO-ret | BCMS retention periods | Client DPO | The periods are set. Retention jobs are then built as a follow-on |

### 6.2 Deferred to the client deployment (not G3 items)

When a client deployment is being prepared, reopen these **in this order**:

1. **Number normalisation and the contact indexes** (ADR 0023 §1.2 and §5.3). This is **a hard
   precondition on switching on any live SMS gateway or USSD code**, because the inbound reply
   fallback is an unindexed cross-tenant `LIKE`.
2. **Live SMS gateway**, including X1 (first SMS delivered to a handset in < 60 s) and measuring the
   ADR 0016 §4 webhook ceilings on the accepted path.
3. **USSD** (Appendix A, 12B) and E4 (a live short code on real handsets). Reopening needs the
   Africa's Talking 160-character limit folded in (ADR 0023 §5 note), plus the NCC number-recycling
   treatment (NDPA §13.9).
4. **The offline PWA** (Appendix A, 12C), with X3 (a real-device airplane-mode check) and the NDPA
   device entries from NDPA §13.9.
5. **The later-"I need help" ADR** (the first-answer-wins follow-up), which is deferred with USSD.
6. **The EMNS per-recipient-variables ADR** (EXBLOCKED, CONTACTVERIFY, and Phase 9's T-0 codes; ADR
   0024 §2).

---

## 7. Collision matrix

The rules:
- Shared files get **labelled blocks**.
- The later sub-phase rebases onto the earlier one's commit.
- **The manifest is regenerated only by P-2 (ADR 0024) and then 12.0, strictly in that order.** An
  agent who finds any other reason to regenerate it has made a structural change, and must stop and
  ask.

| File | P-1 / P-2 (pre-12) | 12A-1 | 12A-2 | 12D-1a | 12D-1b | Resolution |
|---|---|---|---|---|---|---|
| `app/Services/Bcms/Emns/TemplateRenderer.php` | ✓ both | | reads | | | 12A-2 starts after P-2 commits |
| `app/Services/Bcms/Emns/AlertService.php` | ✓ both (preflight, estimate `preview`) | | ✓ release guard | | ✓ degraded mode | Strictly serial: P-1 → P-2 → 12A-2 → 12D-1b |
| `app/Http/Controllers/Bcms/AlertController.php`, `StoreBcmsAlertRequest`, dispatch Form Requests | ✓ P-2 | | ✓ | reads (MFA test) | | Serial, as above |
| `app/Presenters/Bcms/EmnsPresenter.php`, `Pages/Bcms/Emns/*` | ✓ P-2 | | ✓ composer panel | | | Serial |
| `database/seeders/Bcms/Reference/AlertTemplates.php`, `BcmsReferenceSeeder` | ✓ P-1 (pcm inactive) | | ✓ content | | | 12A-2 appends only |
| `database/schema/bcms-manifest.php` | ✓ P-2 | — | — | — | — | Then 12.0 alone |
| `app/Support/Bcms/PlanTemplates.php` | P-3 | | | | | P-3 alone |
| `app/Services/Bcms/Plans/SourceResolver.php`, `PlanAiDrafter.php` | | ✓ R1 | | | | 12A-1 only. The rendered plan and bundle output must be **unchanged**. A plan-document characterisation test guards it |
| `routes/web.php` | ✓ P-2 if needed | ✓ PIR draft | ✓ scenarios, composer | ✓ health | | Labelled blocks; 12A-1 and 12D-1a touch different blocks |
| `config/bcms.php` | | | ✓ `ai.*` | ✓ `watchdog.*` | | One top-level key each |
| `app/Console/Commands/BcmsWatchdog.php`, `routes/console.php` | | | | ✓ | | 12D only |
| `app/Services/Bcms/Incidents/*`, `Incidents/Review.jsx` | | ✓ capability 8 | | reads | | — |
| `package.json` / lockfile | | | | | | 12E adds Playwright and axe (devDependencies) |
| `BcmsDemoSeeder.php` | | | | | | **12E only** |
| `config/services.php`, `config/llm.php`, `LlmGateway`, `packages/platform/**` | ✗ | ✗ | ✗ | ✗ | ✗ | **Not edited in Phase 12** |
| `Contact.php`, `ChangeDetector.php`, `InboundResponseHandler.php`, `UssdChannel.php`, `CheckInService.php`, `routes/bcms-webhooks.php` | | | | | | **Deferred work; not touched in Phase 12** |

The machine is a collision surface too. 12D-2 measures **only when nothing else is running** on the
shared MariaDB.

---

## 8. The §14 NFRs, measured locally, and what a laptop proves

The machine of record is this MacBook: 10 cores, 16 GB, MariaDB 10.4, Redis, and Herd PHP 8.4 under
php-fpm. Every number is recorded with its machine, versions, dataset, command,
`pm.max_children` and Horizon settings.

| NFR | Target | Local method | Proves | Does not prove |
|---|---|---|---|---|
| Calendar year view, 520 occurrences | < 1.5 s p95, cold | 30 GETs through php-fpm after `cache:clear`. Record p50, p95 and the query count | No N+1; PHP and SQL cost | Production network and database sizing |
| 10,000 recipients queued | < 30 s | `release()` through chunk jobs on real Redis with production-shaped Horizon settings. Clock from the dispatch request to the last chunk enqueued. Assert 10,000 write-ahead rows. **Mock channels** (ADR 0023 A1.2) | The fan-out has no per-recipient round trip or lock | Multi-node contention |
| "First SMS" | < 60 s | **Pipeline latency** to the first delivery row reaching `sent` through a local stub adapter with realistic latency and a warm `bcms-lifesafety` worker. **Labelled as pipeline, not delivery** | Pickup, render and adapter path | Handset delivery (deferred, X1) |
| 50,000 contacts per tenant | functional | `BcmsLoadSeed`, then audience resolve, `estimate()`, the roll-call summary at 10,000, and a 2C delta against a fake Graph of 50,000 users | Memory and query scaling | Directory API limits |
| 200 concurrent check-ins | no errors, all rows, p95 | A burst of 200 distinct tokens against the existing check-in routes on php-fpm | Row independence; the limiter ceiling under shared NAT | Carrier NAT and handset latency |
| 100 kbps usable | critical screens | Playwright over CDP: 100 kbps down and up, 400 ms RTT, cold and warm | Transfer budgets | Real radio, low-end CPUs |
| 99.9% / 99.95% | availability | **Not measurable here.** `availability-model.md` plus the chaos proofs of each degraded mode | The design has no unnamed single point of failure | Uptime (X4) |

**Printed at the top of `phase-12-load.md`:** a laptop number is a **lower bound on inefficiency,
not an upper bound on capacity**. A target met here means the code has no per-item round trip, lock
or N+1 that would make it impossible, and nothing more. A target missed here is a real defect. Load
generator, PHP, MariaDB and Redis share ten cores.

**Known risks, recorded before measuring:**
- The entry chunk is **131 KB gzipped**, plus 24 KB of CSS. That is about 12 s at 100 kbps before
  any page code loads.
- Herd php-fpm is `pm.max_children = 5` (8.2 config; confirm 8.4). Changing it needs the owner's
  approval. Otherwise report at 5 and say so.
- `php artisan serve` is never used for a concurrency figure.

**Critical screens for 100 kbps:** crisis room, EMNS compose (with P-2's variable inputs), approve
and dispatch, roll-call live, check-in (QR and code), cascade acknowledgement, My Resilience, plan
show, and call tree live.

---

## 9. Content packs

| Pack | Owner | Where | State |
|---|---|---|---|
| **44 exercise scenario templates, 272 injects** | compliance-analyst | `docs/bcms/content/scenario-pack.md` → `Reference/Scenarios.php` (12A-2) | Drafted in stage 0. Awaiting a BC-officer realism read (§10 of the pack; the E5 reader) |
| Nigerian-context threat catalogue, 12 entries | compliance-analyst | `threat-catalogue.md` → `app/Support/Bcms/ThreatCatalogue.php` | Drafted. Shape ratified (ADR 0023 A1.3 d) |
| Inject `delivery_channel` vocabulary | architect (ratified) | `InjectDeliveryChannel` enum | Ratified (A1.3 c) |
| 11 EMNS scenario templates | compliance-analyst | `emns-templates.md` → `AlertTemplates.php` | `en` authored. **`pcm` seeded inactive pending a native speaker** (X2). ha, yo and ig are external (X2). Variables per ADR 0024 |
| 12 plan templates | — | `PlanTemplates.php` | Exist. **`bcp_payments` stamp corrected by P-3** |
| CBN and ISO clause libraries | compliance-analyst | `IsoClauseRef`, `ClauseRefs.php` | Exist. Verify coverage only |
| USSD menu copy | — | — | **Deferred** |

Nobody machine-translates anything.

---

## 10. Traps this repository has already paid for

1. **Portable SQL:** MariaDB 10.4 in production and CI. No raw `JSON_*`, CTEs or window functions.
   ADR 0024's column and the DSAR name search are handled PHP-side or with `LIKE`.
2. **The SQLite reference migration.** `ReferenceCodeConcurrencyTest` migrates SQLite, so migrations
   use the schema builder only. If raw SQL is unavoidable, branch on the driver. Remember that
   `driver === 'mysql'` silently skips `mariadb`.
3. **No test may depend on a running Ollama.** Fake at the HTTP boundary with
   `preventStrayRequests()`. Prove against the real model with `bcms:ai-live-run`, committed as a
   document.
4. **Horizon queue names come from `config('bcms.queues.*')`** (`b04ac15`).
5. **ADR 0022:** no bare `$table->timestamp()`. Phase 12 adds none.
6. **Carbon keeps the offset.** Any parsed time goes through `->utc()` (Phase 10 review #2).
7. **ADR 0016's oracle rules** apply to any token lookup Phase 12 adds. It adds none, but the
   composer's routes must not create one.
8. **No tenant on an unauthenticated endpoint.** Phase 12 adds none. The dead-man health route
   returns no tenant data.
9. **The exercise prefix lives only in `RenderedMessage`.** Composer previews go through
   `TemplateRenderer`, never `$alert->message`.
10. **First answer wins.** Screens must not show success for a no-op.
11. **The demo seed must not fabricate provenance.**
12. **`BcmsAuditable` never fails the write.** No control may depend on an audit row existing
    (ADR 0023 §1.1 and §4.4).
13. **The model context is 4,096 tokens and the budgets are fixed.** Keep prompts at about 2,500
    tokens and threat-catalogue `grounding` at 700 characters or fewer.
14. **Enum casts on free-string columns throw `ValueError`** on legacy values. `InjectDeliveryChannel`
    is enforced on write only (ADR 0023 A1.3 c).
15. **A fix to prompts must not change documents.** R1 changes only what reaches the model. Pin the
    plan PDF and bundle output with a characterisation test before touching `SourceResolver`.
16. **Redaction is defence in depth, not the control.** A clean prompt builder is the control, and a
    non-zero `redactions` count on a capability with a clean builder is a signal to investigate
    (NDPA §13.3).
17. **This machine:**
    - Herd PHP is not on `PATH`, and `head` is shadowed.
    - Keep at least 10 GB free, because the MariaDB error log has filled the disk twice.
    - **Subagents stall on a Bash call longer than 10 minutes.** Run long jobs in the background.
    - The main session runs the full suite, with one run per database.
18. **Worktrees with a symlinked `vendor`** load the main checkout's code. Copy `vendor` instead.
19. **No JavaScript runs in phpunit.** The composer panel, the variable inputs and the scenario
    screens are proven only by `vite build`, Playwright and a browser.
20. **CSP:** do not edit the shared platform middleware inside a BCMS phase. The Phase 6.8
    blank-page defect passed the whole suite.

---

## 11. Definition of done

Every sub-phase meets Orchestration §7:
- tenancy fails closed on new routes;
- ADR 0017 visibility applies;
- every state change is audited;
- `iso_clause_ref` is set on evidence artefacts;
- validation uses Form Requests with tenant-bound `exists`;
- policies are named for their models;
- its screens pass WCAG and 100 kbps;
- an NDPA note is written (AI provenance, and redaction counts);
- new domain code has at least 80% coverage;
- demo data is added (12E);
- `docs/bcms/phase-12-notes.md` gains a section per sub-phase;
- a HANDOFF block is written.

**G3 is declared by `reliability-engineer`, signed by architect and code-reviewer, in these words:**
*"BCMS Phase 12 — engineering complete. External sign-offs pending: E1, E2, E3, E5, X2, X4–X6, DPO-21.
Offline PWA and USSD deferred by owner decision to client deployment (criteria 3 and 4); live SMS on
hold. See `docs/bcms/phase-12-g3-status.md`."*

---

## 12. Owner questions: resolved 2026-09-24

| # | Question | Resolution |
|---|---|---|
| 1 | Capabilities 8 and 5 | **Build 8** (12A-1). **5 stays deterministic** |
| 2 | A later "I need help" after "I'm safe" | First answer wins. **The follow-up ADR is deferred with USSD** (§6.2 item 5) |
| 3 | Hausa, Yoruba and Igbo copy | **Human translators** (X2). `pcm` is inactive pending a native speaker. AI never translates |
| 4 | Offline pack contact scope | **Deferred** with the PWA |
| 5 | Retention and DSAR | **Retention waits on the DPO.** The DSAR test is built in 12D-1a |
| 6 | Browser tooling | **Playwright and axe as local-only devDependencies**. No CI browser job |
| 7 | USSD short code | **Deferred** with USSD |
| new | DPO question 21: full text or hash for capability 4, 6 and 8 prompts | **Open.** The default is full text with redactions (ADR 0023 A1.3 b) |

---

## Appendix A — Deferred sub-phases, kept for reopening at client deployment

### ~~12B~~ — USSD (integrations-engineer), deferred

The contract is ADR 0023 §5, plus the note that Africa's Talking's limit is **160** characters.

**Scope when reopened:**
- `UssdGateway` with `UssdRequest` and `UssdReply`, and `AfricasTalkingGateway`.
- `UssdMenu`, `UssdSessionStore` and `UssdCopy`.
- `Msisdn`, `MsisdnResolver`, `Contact` normalise-on-write, `ChangeDetector` comparing normalised
  values, and the backfill data migration.
- `InboundResponseHandler` delegating to the resolver.
- `UssdChannel` pull mode.
- `UssdController`, the route in `routes/bcms-webhooks.php`, and the `bcms-ussd` limiter.
- Gateway configuration and the `bcms:ussd-emulate` command.
- **Plus the contact-index migration** from ADR 0023 §1.2, which must land before any live gateway.

**Named proofs:** `Phase12UssdTest::a_ussd_safe_lands_on_the_same_recipient_row_as_an_sms_reply` and
`…check_in_lands_on_the_same_participant_row_as_a_code_check_in`, `MsisdnTest`,
`Phase12ContactNormalisationTest` (zero churn in the 2C delta), and the middleware-stack guard
extended to the new route.

### ~~12C~~ — Offline PWA (frontend-engineer lead, backend-engineer for the API), deferred

The contract is ADR 0023 §6, plus the device-data checklist in NDPA register §13.9.

**Scope when reopened:**
- `OfflinePackBuilder` (reuses `OfflineBundleBuilder::build()`) and `OfflineSyncService`.
- `OfflineController` and `OfflineSyncController`, with its sessionless route in the thin group.
- `CheckInService::checkIn(…, ?Carbon $at)`.
- The `bcms.offline.use` permission.
- A data-free Blade shell with a separate Vite entry, the service worker, the manifest, and an
  IndexedDB queue (Background Sync where supported).

**Named proofs:** `Phase12OfflinePackTest`, `Phase12OfflineSyncTest`, and
`tests/e2e/bcms/offline.spec.ts` (`context.setOffline(true)`).

**Traps kept for that day:**
- Background Sync is Chromium-only.
- Logout must never clear the unsent queue.
- The shell holds no personal data.
- The service worker scope must be `bcms/`, not `/build/`.
