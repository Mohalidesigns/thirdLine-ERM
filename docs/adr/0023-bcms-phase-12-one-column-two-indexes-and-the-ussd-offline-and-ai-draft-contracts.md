# ADR 0023 — Phase 12 asks for one column; the AI-draft contracts (USSD and offline contracts written and deferred)

**Status:** Accepted, **amended 2026-09-24 (Amendment 1, below)** · **Date:** 2026-09-24 · **Phase:** BCMS Phase 12 (AI layer · hardening; offline/USSD deferred) · **Author:** architect
**Filename note:** the filename still says "two indexes". Amendment 1 defers the indexes. The file is not renamed, because the work order, the NDPA register §13 and the content packs cite this path.
**Requested by:** the coordinator, opening Phase 12 under the owner's three binding decisions (G3 = build + prepare; AI on local Ollama through the shared `LlmGateway`; USSD through a generic adapter, one Africa's Talking-style callback format and a local emulator)
**Consumers:** reliability-engineer **(lead, accountable for G3)** · backend-engineer · integrations-engineer · frontend-engineer · ui-designer · compliance-analyst · qa-engineer · code-reviewer · **Phase 2C** (§5.3 changes what the directory sync compares) · **Phase 4** (§1.1, the advisor flag) · **Phase 6** (§5.6, cascade acknowledgement by USSD and offline) · **Phase 7** (§4.4 release guard, §5 inbound number resolution and `UssdChannel` semantics) · **Phase 9** (§6.4, `CheckInService` takes a time) · **Phase 10** (§2.4, capability 8) · **TPRM track** (no action — `LlmGateway`, `config/llm.php` and `config/services.php` are not edited; recorded because they are shared)

Read with `docs/bcms/phase-12-work-order.md`, which turns this into sub-phases, owners and files.
"Owner question N" below refers to that document's §12. Each question carries the default this ADR
has **already adopted**, so no work waits on an answer. An answer that differs from the default is
an amendment to this ADR.

## Amendment 1 (2026-09-24): the owner's decisions and the stage-0 findings

**This section governs wherever it disagrees with the text below it.** The original text is kept, so
the deferred work can be reopened without being re-derived.

### A1.1 The owner's decisions, and what each one changes here

| Decision | Consequence in this ADR |
|---|---|
| **USSD and the offline PWA are deferred** until a client deployment is being prepared | §5 and §6 are marked **"Deferred — not in Phase 12"**. Their contracts stand as written, for reopening. Sub-phases 12B and 12C leave Phase 12 |
| **Live SMS sending is on hold** until a client deployment | See A1.2 |
| **AI runs on local Ollama** for every capability that needs a model | Confirms §2.2 and ADR 0010. Nothing hosted |
| **Pidgin (`pcm`) alert templates are seeded inactive** until a native speaker has reviewed them (a separate fix, in flight). AI never translates | §4.2: the composer may suggest **only active** templates. Locale coverage will show `pcm` as uncovered until review, and that is the honest state |
| **Defaults accepted** | Capability 8 is built and capability 5 stays deterministic (§2.4). First answer wins; the later-help follow-up ADR is deferred **together with USSD**. Hausa, Yoruba and Igbo copy comes from human translators. Retention waits on the DPO. Playwright and axe are local-only devDependencies |

### A1.2 With live SMS on hold, the contact indexes and normalise-on-write are deferred too

Before Amendment 1, §1.2's justification had two consumers:

- the USSD caller lookup, which is now deferred;
- the inbound-SMS reply fallback (`recipientForNumber()`).

The fallback only runs on a **real inbound SMS from a real gateway**. With live SMS on hold, no such
request reaches it: mock channels send nothing that anyone can reply to. Nothing left in Phase 12
(capabilities 1–8, the guardrail suite, load, chaos, WCAG and docs) looks a contact up by number.

**Ruling:** the two `bcms_contacts` indexes, `Msisdn::normalise()`, normalise-on-write, the Phase 2C
`ChangeDetector` change and the backfill data migration are **all deferred** with 12B. **12.0 is now
only `bcms_exercise_definitions.ai_generated`.** The Phase 12 structural count becomes **one column,
zero indexes, zero tables.**

**The deferral is tied to live SMS, not to USSD.** Whichever of the two comes back first (a USSD
short code or a live SMS gateway), §1.2 and §5.3 must land **before the first live gateway is
switched on**. The unindexed cross-tenant `LIKE` in `recipientForNumber()` becomes a real cost the
moment real replies arrive, whether or not USSD exists. That precondition is on the client-deployment
checklist in the work order (§6).

**X1 (live gateway for "first SMS delivered < 60 s") is deferred** to the client deployment. It is no
longer a G3 pending item.

**The "10,000 recipients queued < 30 s" load test stays in Phase 12**, against mock channels. It
measures the fan-out: the audience resolved, 10,000 recipient rows and 10,000 write-ahead delivery
rows written, and chunk jobs enqueued on real Redis and picked up by Horizon. None of that depends on
a gateway, and mock channels write real delivery rows (ADR 0004). "First SMS" is measured as
**pipeline latency to the first delivery row reaching `sent` through a stub adapter**, and is
labelled as exactly that. Handset delivery goes with X1. The gateway black-hole chaos test also
stays: `FailoverSmsChannel`'s logic is exercised against stubbed gateways.

### A1.3 Stage-0 findings: rulings

| Finding | Ruling |
|---|---|
| **(a)** `PlanAiDrafter` puts staff and vendor-contact phone numbers and emails into prompts (`SourceResolver.php:499-511`, `:688-693`; `PlanAiDrafter.php:253-261`) | **Accepted as the first item of 12A-1**, before `AiProvenance` can record anything (NDPA register §13.3, R1). The prompt receives the fields the draft needs: role, tier, must-reach, deputy present, expected response, and vendor name and criticality. **Roles, not names**, where the role carries the meaning (R3). The prompt scan in §2.1 becomes a gate on this fix: `PlanAiDrafter` fails it today, so the fix must turn it green |
| **(b)** Prompts stored verbatim | **§2.3 is amended: "verbatim except for recorded redactions"** (R2). `AiProvenance::record()` replaces E.164-shaped and email-shaped strings in **both** `prompt` and `output` with `[redacted:phone]` or `[redacted:email]`, and stores `redactions: {phone: n, email: n}`. The guardrail suite asserts that no `ai.draft_generated` context matches either pattern. **Full text with redactions is the default, pending the DPO's answer to NDPA register open question 21** (full text or hash for capabilities 4, 6 and 8). If the DPO chooses the hash, that is an amendment to §2.3 and a change in `AiProvenance`, and nothing else moves |
| **(c)** Inject `delivery_channel` has no vocabulary | **Ratified as proposed:** `in_room`, `sim_phone`, `sim_email`, `sim_sms`, `sim_media`, `sim_system`, as a backed enum `App\Enums\Bcms\InjectDeliveryChannel`. The `sim_` prefix is kept deliberately: it records in the data that the channel is simulated, so a future inject-delivery path cannot read `sms` as an instruction to send. **The enum is enforced on write** (`ScenarioDraftValidator`, the inject Form Requests, the seeder test). It is **not cast on the model**. The column is a free `string(20)` that no screen has ever constrained, and casting a column that may already hold other values is the enum-cast `ValueError` defect family this module has already shipped once. §3.2's "vocabulary the execution workspace already accepts" is replaced by this enum |
| **(d)** `FIBRE-CUT` recategorised to `connectivity`; `ThreatCatalogue` keyed by category | **Both accepted.** `ThreatCatalogue::for($category)` is how §3.3's "matching entry" works. `grounding` (≤ 700 characters) is **the only field that reaches a prompt**, and it passes the same E.164 and email scan. The catalogue's twelve keys **become the scenario category vocabulary**. A tenant-authored scenario must pick one (a Form Request `in:` rule), so every scenario the generator can ground has a grounding. The `bcms_scenarios.category` comment in the Phase 0 migration is stale, and no migration is needed to correct it. Re-seeding updates the existing `FIBRE-CUT` row. Nothing reads a scenario's category today (verified by compliance-analyst) |
| **(e)** The `TemplateRenderer` placeholder defect and `SmsSegmenter`'s `…` defect | **Removed from 12A-2's scope.** They are being fixed now, in a standalone commit, and **ADR 0024** gives template variables a home. 12A-2 **depends on both landing first**: the composer's two-segment check assumes a GSM-7-safe truncation marker, and its template suggestions assume templates that can actually render |
| **(f)** `bcp_payments` is stamped `cbn.open_banking.threshold` where 8.4.4 was expected (`PlanTemplates.php:224`) | **Ruled: change it to `IsoClauseRef::Iso22301_8_4_4`.** A plan template's `clause` is the stamp of the *artefact*, and every BCP is ISO 22301 8.4.4 evidence. The CBN open-banking threshold is an obligation the plan's *content* addresses, and the compliance matrix already answers it through 8.4.5 (`ClauseComplianceMatrixService::crosswalk()`). Stamping the plan with it claims that a plan's existence meets a 30-minute failover threshold. The `standard` label keeps naming both. **No data repair is needed:** `PlanService` stamps new plans from `PlanType::clauseRef()`, so the template value reaches only the library display and `PlanAssembler`'s fallback. It **lands as its own one-line commit** (backend-engineer applies it, compliance-analyst confirms, then both gates). It is not Phase 12 scope |

### A1.4 Sequencing after Amendment 1

Standalone EMNS rendering fix → **ADR 0024** (template variables, before the demo) → `bcp_payments`
one-liner (any time) → **12.0** (the definitions column; migration `2026_09_25_120001`) →
12A-1 ‖ 12D-1a → 12A-2 → 12D-1b → 12D-2 → 12E. The work order carries the detail.

## Context

Phase 12 closes the module. Its build prompt (`plans/bcms/prompts/PHASE-12-ai-offline-hardening.md`)
was written against a generic Laravel application and, in five places, assumes things that are not
true here. Each was checked in the code on 2026-09-24, not taken on report:

1. **"Capabilities 1, 3, 4, 5, 7 and 8 were built inside their phases."** Four were. **Capability 5**
   (the ISO/CBN gap analyser) is deliberately deterministic. `GapAnalyserService` makes no model call
   and says so (`ai_generated = false`, Phase 11 gate 2 B8). The LLM phrasing was deferred pending an
   owner decision. **Capability 8** (post-incident learning) has a constant
   (`BcmsLlmClient::POST_INCIDENT_LEARNING`) and a config switch and nothing else. `phase-10-notes.md`
   records that no `PirAiDrafter` was written. **Capability 7** is built, but its docblock promises
   something the schema cannot hold: `ProgrammeAdvisor` says each proposed definition is "marked
   `ai_generated` at the point a human accepts it", and `bcms_exercise_definitions` has no such
   column. The proposals are flashed to the session. A definition created from one carries no
   provenance at all.
2. **"Prompts and outputs logged."** `llm_usage_events` (ADR 0015 §5 and §10) records who, when,
   which capability and which outcome. It **deliberately holds no prompt text and no model
   response**, and ADR 0015 §10 says so as the first answer to "what is in the AI log". BCMS prompts
   are stamped `promptVersion: 'unversioned'`. So today a BCMS prompt is logged nowhere, and an
   output is logged only where a drafter happens to write it onto the record (BIA `ai_reasoning`).
3. **"Draft a clear, calm alert … in the required languages."** Phase 7's `TemplateRenderer` says
   *"NOTHING HERE MACHINE-TRANSLATES. The Phase 7 prompt forbids it and it is right to: an
   evacuation instruction that says the wrong thing in Hausa is a safety incident."* A 2.1 GB local
   model writing Hausa, Yoruba or Igbo life-safety copy would reverse that rule by the back door.
4. **"Wire the Nigerian aggregator to the flow built in Phase 7."** `UssdChannel` models USSD as
   *registration with an aggregator*, a POST per recipient before anyone dials. An Africa's
   Talking-style aggregator works the other way round: the person dials, and the aggregator POSTs
   `sessionId`, `serviceCode`, `phoneNumber` and the accumulated `text` to **our** callback. It
   expects `CON …` or `END …` back. Under that model the only identity is the caller's number.
   Number lookup is `InboundResponseHandler::recipientForNumber()`, a leading-wildcard
   `LIKE '%{last nine digits}'` over `bcms_contacts`, run without a tenant because a webhook has
   none. ADR 0016 flagged this as unindexable. It deferred the fix, which is *"a stored reversed-tail
   or normalised-E.164 column … argued on its own evidence"*, to a "Phase 8" that the track plan
   renumbered to 2C and nobody picked up. The `bcms_contacts` migration comment says *"E.164
   throughout … Normalisation happens on write, in one place."* **No such place exists.** Nothing in
   `app/` normalises a number.
5. **"A check-in taken offline syncs correctly."** `CheckInService::checkIn()` stamps
   `checked_in_at = now()`. An assembly-point check-in taken with no signal at 10:02 and synced at
   10:40 would record 10:40, and the evacuation headcount time a drill is scored on would be wrong
   by the length of the outage.

Separately, the build pack still names Laravel Prism (ADR 0010: never coming), AntD (ADR 0007
deviation 5), and `docs/design/` (it means `plans/`). ADR 0007 governs. Nothing here revisits it.

The schema freeze has held at 15 → 8 → 5 → 1 → 0 structural changes per phase through Phase 7.
Phases 9 and 10 each needed a home for an artefact that had none (ADRs 0019, 0020), and Phase 11
asked for nothing (ADR 0021). Phase 12 is asked to add no tables unless it must. It adds none.

## Decision

### 1. Schema — one column, two indexes, no tables

Every candidate the phase raised, each with a verdict:

| Candidate | Verdict | Where it lives instead |
|---|---|---|
| USSD session state | **No table** | Cache (§5.4). It is re-derivable, lives for at most three minutes, and the aggregator already carries the input path |
| Offline sync idempotency keys | **No table** | Every offline action is a *set-once* fact the domain already refuses to set twice (§6.3). The client id goes in the audit row |
| Scenario template content | **No table** | `bcms_scenarios` system rows (`organization_id` null, `is_system_default`, `suggested_injects` json). All of these already exist, with `tenantIncludesGlobal` |
| AI provenance for capability 2 | **No column** | `bcms_scenarios.ai_generated` and `bcms_exercise_injects.ai_generated` exist |
| AI provenance for capability 6 | **No column** | `bcms_alerts.ai_generated` exists |
| AI provenance for capability 8 | **No column** | A PIR is a `bcms_aars` row (ADR 0020 §1). `ai_generated` and `ai_draft_generated_at` exist, and the section lands in `quantitative_results` as `phase-10-notes.md` specified |
| AI prompt and output log | **No table** | A `bcms_audit_logs` row per draft (§2.3), linked to the `llm_usage_events` row by id |
| Alert-composer drafts | **No table** | `bcms_alerts` with `status = 'draft'` |
| Scenario lineage (which template a variant came from) | **No column** | The variant's `code` is `{TEMPLATE_CODE}-V{n}`, and the provenance audit row names the template |
| USSD as a check-in method | **No column** | `check_in_method` is `string(20)`. `'ussd'` is a new value, not a new type |
| Scheduler heartbeat | **No table** | Reliability contract, §7 |
| Per-tenant, per-capability AI switches | **No column** | Rejected. The tenant master switch (`bcms_settings.ai_enabled`) and the per-capability deployment switch meet Blueprint §12's "per-tenant toggleable" |
| **`bcms_exercise_definitions.ai_generated`** | **Accepted** | §1.1 |
| **Indexes on `bcms_contacts.mobile_primary` and `.mobile_secondary`** | ~~Accepted~~ **Deferred with USSD and live SMS (Amendment 1, A1.2)** | §1.2 |

#### 1.1 `bcms_exercise_definitions.ai_generated` — `boolean`, `default(false)`

| | |
|---|---|
| **Column** | `bcms_exercise_definitions.ai_generated`, `boolean NOT NULL DEFAULT 0` |
| **Phase that needs it** | Phase 12. Gate G3 criterion 2 requires the provenance flag on every capability's artefact. The defect it closes is Phase 4's |
| **What breaks without it** | Capability 7's artefact carries no provenance. The advisor's own docblock is false. G3 criterion 2 cannot pass for one of the eight capabilities, and there is no honest way to make it pass |
| **Semantics** | Set **once, at creation**, when the definition is created from an advisor proposal. **Never cleared by a human edit.** Provenance is history, not state. This rule applies to every `ai_generated` column in the module (§2.1) |

**Why the audit log is not enough on its own.** The obvious no-column answer is to record provenance
as an audit row on the new definition. It fails on a property of this module's own auditing:
`BcmsAuditable` **never fails the write** (ADR 0007 deviation 6). An audit row is best-effort by
design. A guardrail that disappears whenever an audit insert fails is not a guardrail. The flag is a
fact about the artefact, so it lives on the artefact. The audit row (§2.3) is the *log*, and it may
be best-effort because `llm_usage_events` beside it is best-effort too (`UsageRecorder::recordSafely`).

#### 1.2 Two secondary indexes on `bcms_contacts`: `(mobile_primary)` and `(mobile_secondary)` — Deferred — not in Phase 12

> **Deferred by Amendment 1 (A1.2).** Nothing left in Phase 12 looks a contact up by number. This section must land, with §5.3, before the first live SMS gateway or USSD short code is switched on. The text below stands for that day.

| | |
|---|---|
| **Change** | `index('mobile_primary', 'bcms_contacts_mobile_primary_idx')` and `index('mobile_secondary', 'bcms_contacts_mobile_secondary_idx')`. Single-column and **not tenant-prefixed**, because the lookup runs where no tenant exists (a gateway callback) |
| **Phase that needs it** | Phase 12. USSD makes the caller's number the **primary** identity for a life-safety channel, not the fallback it is for SMS |
| **What breaks without it** | Every USSD session start, and every bare SMS reply without a token, scans the contacts of **every tenant** on an unauthenticated endpoint. At the §14 scale of 50,000 contacts per tenant that scan grows with the whole customer base, on the path whose availability target is 99.95%. ADR 0016 §4's rule (*a ceiling is priced on what an accepted request costs*) cannot be applied to a request whose cost is the size of the database |
| **Paired with** | Normalisation on write (§5.3) and a one-off backfill **data** migration (not structural). Without both, an index on unnormalised values makes the lookup fast and wrong |

**Why this, and not ADR 0016's reversed-tail column.** ADR 0016 named two shapes: a stored
reversed-tail column, or a normalised E.164 column. Both add a column that duplicates a number
already stored. Normalising the **existing** columns on write, which their own migration comment
already requires, makes an exact match possible on the column that is already there. The index is
the only structural change. It adds no data and no second copy of a personal identifier, and
nothing that reads `mobile_primary` sees a different type.

#### 1.3 The migration

> **Amended (A1.2, ADR 0024):** the file is now `database/migrations/2026_09_25_120001_bcms_phase12_definition_ai_flag.php`. It carries the definitions column only. ADR 0024 takes `2026_09_24_120001`, and the indexes are deferred.

One file, originally `database/migrations/2026_09_24_120001_bcms_phase12_definition_ai_flag_and_contact_msisdn_indexes.php`.
It sorts after ADR 0022's `2026_09_23_120001` (open on PR #10 to `main`), so the two never reorder
when the branches meet.

- **Schema builder only.** No raw `ALTER`. `ReferenceCodeConcurrencyTest` migrates a SQLite
  database, and a raw `MODIFY` is exactly what broke there in Phase 10.
- **No `timestamp()` of any kind** (ADR 0022). This phase adds no time columns. Any it later wants
  are `dateTime()` and need an amendment to this ADR.
- `down()` drops both indexes and the column. It is SQLite-safe: drop the indexes by name before
  the column.
- `php artisan bcms:verify-schema --write` regenerates `database/schema/bcms-manifest.php` **in the
  same commit**.
- It lands **alone, as sub-phase 12.0**, before any Phase 12 code. Every workstream builds against
  the frozen shape, as in Week 1.

**Structural count for Phase 12: one column, zero indexes, zero tables** (amended from "one column, two indexes"; A1.2).

### 2. The AI draft contract, all eight capabilities

#### 2.1 What every AI artefact must satisfy (the guardrail suite asserts each row)

| Guardrail | Rule | Asserted by |
|---|---|---|
| **One door** | Every model call goes through `BcmsLlmClient` → `LlmGateway`. No BCMS class imports `LlmService` or makes an HTTP call to a model | Architecture test on `app/Services/Bcms/**` |
| **Kill switches** | `services.llm.enabled` ∧ `bcms_settings.ai_enabled` ∧ `bcms.ai.capabilities.<cap>`. When any is off: a refusal with a printable reason, **no gateway call and no usage row**, and the manual path completes | Per capability, per switch |
| **Provenance flag** | The artefact's `ai_generated` is `true` on landing and **survives a human edit** | Per capability: land, edit, re-read from a fresh query |
| **Editable draft state** | The artefact lands in its table's own draft state (below). The model never sets a terminal or approved state | Per capability |
| **Human action required** | The state change that makes the artefact count (approve, activate, finalise, dispatch) requires an authenticated human. For BIA, AAR and PIR the approver cannot be the person who requested the draft, which the existing rules already enforce | Per capability |
| **Prompt and output logged** | Exactly one `ai.draft_generated` audit row on the subject (§2.3), and one `llm_usage_events` row whose `service` and `prompt_version` match it | Per capability |
| **AI writes words, humans decide reach** | No model output may set severity, audience, channels, `is_simulation`, response options, criticality, RTO/MTPD approval, `is_reportable`, or any status. It proposes *text* and *suggestions*. Fields that decide who is affected and how loudly are human-set | Per capability: a fixture whose model output *tries* to set these is ignored field by field |
| **No channel data in prompts** | No prompt carries a phone number, email address, WhatsApp handle or next-of-kin record. The model needs none of them to draft | Every captured prompt in the suite is scanned for E.164 and email patterns. Any hit fails. **Amendment 1 (A1.3 a, b):** `PlanAiDrafter` fails this today and is fixed first in 12A-1, and `AiProvenance` also redacts at the write |
| **Tenant data not used for training** | Architectural: every configured endpoint profile is self-hosted. The suite pins that. Adding a profile that is not self-hosted must fail the suite until the profile declares a no-training attestation (a config key, not a column) | Config assertion + `Http::preventStrayRequests()` |

Per-capability landing states. Rows 5 and 8 depend on owner question 1. The default is taken here.

| # | Capability | Subject | Draft state on landing | Human action that makes it count |
|---|---|---|---|---|
| 1 | BIA co-pilot | `bcms_bia_assessments` | draft; `ai_generated`, `ai_drafted_at`, `ai_reasoning` | approve, by someone other than the drafter (existing) |
| 2 | Scenario & inject generator | `bcms_scenarios` (tenant row) | **`is_active = false`** | activate (human, audited). Applying it to an occurrence copies injects with `ai_generated = true`. `InjectService::release()` already requires a `User` |
| 3 | Plan drafting | `bcms_plans`, `bcms_plan_sections` | plan draft | plan approval (existing) |
| 4 | AAR synthesis | `bcms_aars` (exercise) | draft | finalise (existing) |
| 5 | Gap analyser | — | **not a model capability** (default: stays deterministic) | the human raises a finding. The suite asserts **zero** gateway calls and `ai_generated = false` with every AI switch on |
| 6 | Alert composer | `bcms_alerts` | **`status = 'draft'`** | dispatch by a human, with MFA and an explicit content confirmation (§4.4) |
| 7 | Programme advisor | `bcms_exercise_definitions` (via proposal) | definition `status = 'draft'`, `ai_generated = true` (§1.1) | activation (existing) |
| 8 | Post-incident learning | `bcms_aars` (PIR) → `quantitative_results` section | PIR draft | PIR finalise (existing, ADR 0020) |

#### 2.2 `BcmsLlmClient` — additive, trailing parameters only (ADR 0010's contract extended, not changed)

- `json(…, ?string $budget = null, ?string $promptVersion = null)`. The budget defaults to the
  current `'medium'`, and the prompt version to `'unversioned'`, so existing callers are unchanged.
  Every Phase 12 caller **and every retrofitted drafter** passes a real `PROMPT_VERSION` constant.
- The return shape gains `usage_event_id` and `prompt_version`. `{ok, data, reason}` is unchanged.
- **Budgets come from the existing keys** in `config('services.llm.budgets')`: `'short'` (600
  tokens / 60 s) for the composer, `'long'` (1,200 / 90 s) for the scenario generator. **Output
  contracts are sized to fit the budget, not the other way round** (§3.2), so this phase does not
  edit a config file ERM and TPRM share. If the live run (work order 12A) shows truncation, raising
  a budget is a separate change and needs TPRM's sign-off.
- `num_ctx` is 4,096 (`config/llm.php`). A prompt that, with its maximum output, would exceed it is
  refused before the call rather than truncated by the model.

#### 2.3 Where the prompt and the output are logged

`App\Services\Bcms\Ai\AiProvenance::record(Model $subject, string $capability, array $call)` writes
**one** `bcms_audit_logs` row through the subject's `recordAudit('ai.draft_generated', …)`.
The context carries:

`{capability, prompt_version, usage_event_id, model, prompt, output, validator: {accepted, rejected}}`

- `prompt` is **verbatim except for recorded redactions** (Amendment 1, A1.3 b: E.164 and email shapes are replaced in `prompt` and `output`, with the counts stored as `redactions`). `output` is the model's parsed JSON **before** any human edit. Full text is kept, rather than a hash, **pending the DPO's answer to NDPA open question 21**.
- It is written **after** the subject row exists. Every Phase 12 capability persists its draft
  before recording (§3.4, §4.3). Capability 7 records against the `ExerciseProgramme` whose advice
  was asked for, and each definition later created from a proposal carries the flag (§1.1).
- **Why the audit table and not a new store.** A prompt is assembled from rows the same audit table
  already holds as before/after images. Logging it there adds no new category of personal data and
  no new sink with its own retention to be discovered later. The "no channel data in prompts" rule
  (§2.1) keeps the most sensitive fields out entirely.
- **What it does not change:** `llm_usage_events` still holds no prompt or response text. ADR 0015
  §10's register statement stays true.
- `compliance-analyst` adds the NDPA register note: AI prompts and raw outputs are held in
  `bcms_audit_logs` under that table's existing lawful basis and retention, which is none, as the
  register already records.

#### 2.4 Capability 8 (default taken pending owner question 1)

`App\Services\Bcms\Ai\PirLearningDrafter`, under `POST_INCIDENT_LEARNING` (off by default). It drafts
the PIR's "what the exercises predicted, and what reality exposed" section into
`quantitative_results`, draft only. It inherits `phase-10-incident-clause-map.md` item 10 in full. It
may not set `severity`, `is_reportable` or `status`, may not raise findings, and submits nothing.
`AarAiDrafter` keeps refusing PIRs. Capability 5 stays deterministic. G3's statement will read
*"seven capabilities use a model; the gap analyser is deterministic by design and the suite proves
it makes no model call"*.

### 3. Capability 2 — the scenario and inject generator

#### 3.1 The brief (human-set, validated by a Form Request)

`template_code` (an active system or tenant scenario) · `duration_minutes` (60–480) · up to three
`site_ids` and five `process_ids` (tenant-bound `Rule::exists`, used **by name only** for grounding) ·
optional `notes` (≤ 500 characters). The ladder level and category are inherited from the template
and are editable by the human afterwards. They are never model-set.

#### 3.2 The output contract (validated deterministically, sized to the `'long'` budget)

```json
{ "name": "≤200", "summary": "≤600", "narrative": "≤1500",
  "injects": [ { "sequence": 1, "release_offset_minutes": 0, "title": "≤250",
                 "content": "≤400", "delivery_channel": "<existing inject vocabulary>",
                 "expected_response": "≤300" } ],
  "objectives": [ "≤300" ] }
```

Constraints: 4–10 injects. Offsets are non-decreasing, and every offset falls within
`duration_minutes`. Objectives: 2–6. `delivery_channel` must be a value of `InjectDeliveryChannel` (`in_room`,
`sim_phone`, `sim_email`, `sim_sms`, `sim_media`, `sim_system`; Amendment 1, A1.3 c), and anything
else is rejected. `ScenarioDraftValidator` accepts or rejects
**field by field**. A rejected field is left empty for the human rather than repaired by guesswork,
and the reasons are recorded in the provenance row.
`expected_response` has no column on `bcms_exercise_injects`. It stays in `suggested_injects` json
and is folded into the inject's `content` under a heading when applied to an occurrence.

#### 3.3 Grounding

The prompt carries: the template (narrative, objectives, injects), the matching
**Nigerian-context threat catalogue** entry (a content pack, `app/Support/Bcms/ThreatCatalogue.php`,
no table), site names with city and state, and process names with tier. It carries no people, no
contacts and no numbers.

#### 3.4 Execution and persistence

Generation takes 40–90 seconds on `granite4:micro` locally, so it is a **queued job**
(`App\Jobs\Bcms\GenerateScenarioVariantJob`) on `config('bcms.queues.sync')`. No fifth queue: the
volume is a handful per officer per year, and a warm supervisor for a feature that is off by
default is a cost with no benefit. The job is dispatched with the real actor (`BcmsLlmClient` falls
back to `auth()->id()`, which is null in a job). The screen follows it with `useJobProgress`. On
success the job writes one tenant `bcms_scenarios` row: `code = {TEMPLATE_CODE}-V{n}`,
`ai_generated = true`, `is_active = false`, `is_system_default = false`, `created_by` = requester. It
then records provenance (§2.3). On refusal or failure it writes nothing and reports the reason.

Applying a scenario to an occurrence (active scenarios only, planned or in-progress occurrences, and
refused if the occurrence already has injects) copies `suggested_injects` into
`bcms_exercise_injects` with `ai_generated` equal to the scenario's.

#### 3.5 Content

The shipped library grows from 10 to **at least 42** system templates. Every one carries timed
injects, which none of the current ten do, plus objectives, drivers and `ladder_min`. It covers the
eight Blueprint §12 families with several variants each. `compliance-analyst` owns the content.
Seeding stays `updateOrCreate(['organization_id' => null, 'code' => …])`. MariaDB's unique index
treats NULLs as distinct, so the seeder, not the index, is what keeps system rows unique.

### 4. Capability 6 — the alert composer, and how it hands a draft to EMNS without being able to send it

#### 4.1 The composer is pure

`App\Services\Bcms\Ai\AlertComposer::draft(AlertBrief $brief): AlertDraftResult`. Its constructor
takes `BcmsLlmClient`, `TemplateRenderer` (read-only: template matching and locale coverage) and
nothing that sends. It **writes no model and dispatches nothing**. An architecture test asserts that
its constructor graph contains none of `AlertService`, `AlertDispatcher`, `ChannelRegistry`, any
`NotificationChannel`, `Bus` or a job class. It also asserts that its source contains no `::create(`,
`->save(`, `dispatch(` or `->release(`. The composer *cannot* send because it holds nothing that can.

#### 4.2 Brief and output

- **Brief (human):** a one-line `description` (≤ 280), the **human-chosen** `severity`, optional
  `occurrence_id` / `incident_id` context, and an optional template hint.
- **Model output:** `{title ≤ 120, message, voice_script ≤ 600 | null, suggested_template_code | null}`,
  in **English only**.
- **Deterministic checks on `message`:** segments and encoding via `SmsSegmenter`. The budget is
  **two segments after** the worst-case `RenderedMessage::EXERCISE_PREFIX` and a reply token are
  added, which is the budget `TemplateRenderer::MAX_SMS_SEGMENTS` already enforces. It also gets a
  readability measure: sentence length and a grade estimate, computed and never taken from the
  model. Over budget gets one "shorten" retry. After that the draft lands with the renderer's
  existing word-boundary cut shown as a warning. A readability miss is a warning, not a block.
- **Languages:** the model never writes Hausa, Yoruba, Igbo or Pidgin (Context 3). Multilingual reach
  comes from `suggested_template_code`. The screen offers the approved template and shows its locale
  coverage from `TemplateRenderer::coverage()`. Locales with no authored template fall back to
  English, and the fallback is **recorded**, as Phase 7 already does.
- **Amendment 1 (A1.1, A1.3 e; ADR 0024):** only **active** templates may be suggested, and `pcm`
  rows are inactive until a native speaker has reviewed them. A suggested template that declares
  operator variables (ADR 0024 §2) hands the human the same input form as a manual compose. The AI
  never fills a template variable. 12A-2 starts only after the standalone rendering fix and ADR 0024
  have landed.

#### 4.3 Hand-off: the human's request persists the draft, not the composer

`AlertController::composeWithAi` (permission `bcms.alert.compose`, a Form Request, no MFA, matching
compose today) calls the composer. If the result is ok, it calls the existing
`AlertService::compose([... 'title', 'message', 'ai_generated' => true])` with the **brief's**
severity and context. The existing defaults then apply, including `is_simulation` for an
exercise-linked alert. Then it calls `AiProvenance::record($alert, …)` and redirects to the draft.
Audience, channels and response options are set by the human on the existing screen, as for any
draft.

#### 4.4 The release guard: a recorded human confirmation, in the service, on every entry point

- `AlertService::release()` **refuses** an `ai_generated` alert when `$userId` is null. No job,
  command or listener can release an AI-drafted alert.
- Every dispatch Form Request (the EMNS dispatch route and every crisis-room dispatch path) requires
  `confirm_ai_content` to be `accepted` when the alert is `ai_generated`. It records
  `alert.ai_content_confirmed` with the actor. The confirmation travels **in the dispatch request
  itself**, so it cannot go stale against a later edit.
- Dual approval, MFA on dispatch, simulation defaults and the exercise prefix are **unchanged**. The
  guard adds a condition and removes none.
- **Why not reuse `approve()`.** `approve()` refuses simulations, by a rule Phase 7 argued carefully.
  Routing AI confirmation through it would make an AI-drafted drill undispatchable, or would weaken
  that rule.
- **Why not make the control an audit row.** §1.1: audit is best-effort, and a control is not.

The composer is **never on the life-safety critical path**. Pre-scripted templates are, and a
dispatch never waits on a model.

### 5. USSD — the adapter interface and the "same record as SMS" rule — Deferred — not in Phase 12

> **Deferred by Amendment 1 (A1.1).** Reopen when a client deployment is being prepared. The research for NDPA register §13.9 found that Africa's Talking documents a **160-character** menu limit for Nigeria, not 182, plus a 120-second session and a 30-second idle timeout. Fold those into §5.1, §5.4 and §5.5 on reopening. The contract below is otherwise unchanged.

#### 5.1 Interface

```php
namespace App\Contracts\Bcms;

interface UssdGateway
{
    public function key(): string;                          // 'africastalking', …
    public function authenticate(Request $request): bool;  // FAILS CLOSED when unconfigured
    public function parse(Request $request): UssdRequest;   // session id, E.164 msisdn, service code, input path, network
    public function respond(UssdReply $reply): Response;    // 'CON …' / 'END …', text/plain for the AT shape
}

final class UssdRequest { string $sessionId; string $msisdn; string $serviceCode; array $path /* list<string> */; ?string $network; }
final class UssdReply   { bool $continue; string $text; }  // text ≤ 182 GSM-7 characters, asserted
```

One implementation ships: `AfricasTalkingGateway`, the aggregator-callback shape. The **emulator**
(`php artisan bcms:ussd-emulate {msisdn} [--script=1,1]`) POSTs that exact shape, with real
credentials, to the real route through the real middleware stack, and renders each screen. It is an
emulator of the aggregator, not a mock of our code.

#### 5.2 Authentication and route

- **Fail closed**, like `alert-reply` and unlike `provider-status`. This endpoint writes life-safety
  answers. The AT callback shape is unsigned, so authentication is **HTTP Basic credentials** that
  the operator configures in the aggregator's callback URL, compared with `hash_equals`, plus an
  optional source-CIDR allowlist. A secret in a path segment is **rejected**, because it lands in
  every access log. Aggregators that sign get an HMAC `authenticate()` in their own class.
- The caller's number is **network-asserted**, and trustworthy only because the callback is
  authenticated. That is the whole security argument, and the pen-test scope names it.
- Route `POST bcms/ussd/{provider}` in `routes/bcms-webhooks.php`, in the thin `bcms-webhook` group,
  with `throttle:bcms-ussd` first. It is added to the ADR 0016 §5 resolved-stack guard test and to
  the `RouteAuthorizationTest` allowlist with its reason.
- Throttle keys: per `{provider}+msisdn` and per IP. The ceiling is priced under ADR 0016 §4's rule
  on the accepted path (§5.3's two indexed lookups, three small reads and one cache write). The
  number is `reliability-engineer`'s, from measurement in 12D.
- **A recorded deviation from Blueprint §10.** Blueprint §10 routes USSD inbound through
  `POST /alerts/{id}/responses`. A dialled USSD session names no alert: the caller does not know
  the id, and several may be open. The route is therefore per provider, following Phase 7's
  `bcms/alert-reply/{provider}` precedent. The offline pack and sync endpoints have no §10
  counterpart, and their paths are the ones in §6.

#### 5.3 Identity: `MsisdnResolver`, and normalisation on write

- `App\Support\Bcms\Msisdn::normalise(string): ?string` produces E.164. The default calling code is
  `config('bcms.default_calling_code', '234')`: `0XXXXXXXXXX` becomes `+234XXXXXXXXXX`, `234…`
  becomes `+234…`, `00…` becomes `+…`, and an unparseable value returns null. **An unparseable
  number is stored as entered and never destroyed.** It simply cannot match, and contact hygiene
  reports it.
- `Contact` normalises `mobile_primary`, `mobile_secondary` and `whatsapp` on write, in one place,
  which is what the migration comment always claimed.
- **Phase 2C must compare normalised values.** `ChangeDetector` normalises the directory side before
  comparing. Otherwise every sync sees `08031234567` against `+2348031234567` as a change, forever.
  This is a Phase 2C behaviour change and is broadcast as such.
- A **data** migration (chunked, PHP-side, portable, idempotent) backfills existing rows. It reports
  a count of unparseable values and does not fail on them.
- `App\Services\Bcms\Emns\MsisdnResolver::contactsFor(string $msisdn)` normalises the input, then
  runs **two indexed exact-match queries** (primary, secondary), unioned in PHP. The union is not
  left to `index_merge`. It covers active, non-deleted contacts, under `Contact::bypassTenancy('USSD
  and SMS inbound resolve by network-asserted number; no tenant exists on a gateway callback')`.
  After resolution, `TenantContext::set()` runs per resolved row, as `handleReply()` does today.
- `InboundResponseHandler::recipientForNumber()` **delegates to it**. The SMS fallback keeps its
  existing rule: exactly one open alert, or null. The leading-wildcard `LIKE` is deleted. ADR 0016's
  deferred item is closed here.

#### 5.4 Session state: cache, with a defined degraded mode

- Key `bcms:ussd:` + `sha256(provider|sessionId|msisdn)`, with a TTL of 180 s on the default cache
  store. It holds the resolved contact ids and **the menu the person was actually shown at each
  level**, as ordered `(kind, id)` pairs.
- **Why state at all, when the aggregator carries the input path.** Between screen 1 and screen 2 a
  new alert can be dispatched, and "2" would then mean a different alert. The stored menu pins the
  selection to what was on the handset. Every selected id is **re-fetched with its eligibility
  predicate inside the query** (ADR 0016 §3) before anything is written.
- **Degraded mode** (cache miss mid-session, or a stalled store): rebuild the menu from the database
  in its stable order (`dispatched_at, id`). If the rebuilt list would change the meaning of the
  path, reply `END` with "Please dial again". **Never guess.** A wrong alert settled tells a crisis
  manager someone is out of a building they are still in. 12D chaos-tests this.
- The key includes the msisdn, so a replayed or guessed `sessionId` from another number reads
  nothing.

#### 5.5 Menu: depth ≤ 3, only what applies

Level 1 lists only the options this caller has open: *I'm safe · I need help · Read current alert ·
Check in to exercise · Confirm call-tree call*. Level 2 picks the item when more than one applies,
using titles truncated on a word boundary. Level 3 confirms. With one candidate, the confirm is
level 2. An unknown number and a known number with nothing open receive **the same** `END` text.
Screens are ≤ 182 GSM-7 characters. Menu copy in Hausa, Yoruba or Igbo will often force UCS-2, so
the budget is computed per encoding by `SmsSegmenter`, not assumed.

#### 5.6 The sameness rule (G3 criterion 3)

USSD writes **nothing itself**. It calls the domain services another channel calls, with
`via = 'ussd'`:

| Action | Service (unchanged unless noted) |
|---|---|
| I'm safe / I need help | `RollCallService::record($recipient, SAFE / NEEDS_HELP, $text, 'ussd')`. First answer wins, as for SMS |
| Read current alert | `TemplateRenderer::render($alert, ChannelKey::Ussd, $contact->preferred_language)`. **Never `$alert->message`**, or the "THIS IS AN EXERCISE" prefix is bypassed (standing rule 5) |
| Check in | `CheckInService::checkIn($participant, 'ussd')`, where the participant is matched on `contact_id`, or on `user_id` when the contact has a user, in an in-progress occurrence |
| Confirm cascade call | `CascadeEngine::acknowledge($node, 'ussd')` on a pending node of an open cascade |

The criterion's test runs two identical fixtures, one answered by SMS reply and one by the USSD
emulator path. It asserts the recipient rows are equal in every column except the channel trace.

#### 5.7 `UssdChannel` under the pull model

For the AT shape, `send()` makes **no HTTP call**. The alert is retrievable because its
`bcms_alert_recipients` row exists. It returns `sent` with `raw.semantics = 'registered_for_pull'`
exactly as today, so the audit is not misread as delivery. The registration POST stays available,
behind `mode = push`, for aggregators that sell network-initiated USSD. `requiredConfig()` depends on
the mode. ADR 0004's interface is untouched.

### 6. Offline — the pack, the shell, and the sync endpoint — Deferred — not in Phase 12

> **Deferred by Amendment 1 (A1.1).** Reopen with the client deployment. NDPA register §13.9 lists what the device-data entries must cover then (withdrawn-consent filtering, no next of kin, clearing on account revocation, auditing pack fetches). The contract below is otherwise unchanged.

#### 6.1 The pack: `GET bcms/offline/pack`

- New permission `bcms.offline.use`, "Keep my plan, call tree and emergency contacts on this device".
  It is granted to Employee and above, through the catalogue and a grant data migration.
- **Computed per request and never stored.** It holds: `schema_version`, server `generated_at`, the
  user's own emergency profile, approved plans the user may see (ADR 0017), each built by
  **`OfflineBundleBuilder::build()`** (one assembler, one truth: sections, assembly points, crisis
  contacts), the call trees on which the user is a node with those trees' node contacts (default
  pending owner question 4), and the user's **own** open capability tokens: `chk-…` for their
  participants, `r-…` for their open recipients, `c-…` for their pending cascade nodes.
- `ETag` / `If-None-Match`, because at 100 kbps the pack is the largest thing the device fetches. A
  budget is set in 12C from measurement.

#### 6.2 The shell: a data-free page

The offline viewer is **not an Inertia page**. An Inertia page's HTML carries its props, which here
would be personal data inside a cached document. It is a Blade shell with a separate small Vite
entry. The service worker caches the shell, static assets and the pack response, and **nothing
else**: no authenticated HTML of other pages. The worker is served from a route whose scope covers
`bcms/`, not from `/build/`, with `Cache-Control: no-cache`. Push registration is **out of scope**
(§ What this ADR does not do).

#### 6.3 Sync: `POST bcms/offline/sync`, where each action carries its own authority

- It sits in the thin `bcms-webhook` group: no session, no CSRF, throttle first. It is added to the
  resolved-stack guard test and the route allowlist. **Why sessionless:** a device offline for hours
  comes back with an expired session and a stale CSRF token. An endpoint that needed both would
  refuse exactly the check-ins this feature exists to save. Each action is authorised by the
  capability token the pack gave the user. That is the same authority the existing check-in and
  cascade links already accept without a session.
- Body: at most 25 actions, each
  `{client_action_id: uuid, kind: checkin|alert_response|cascade_ack, token, value?, captured_at: ISO-8601 with offset}`.
- Tokens resolve through the **existing** resolvers with their ADR 0016 §3 constant-time and
  eligibility rules (`CheckInService::participantForToken`, `CascadeEngine::nodeForToken`, and
  `InboundResponseHandler`'s token lookup made public). A token whose prefix does not match `kind` is
  refused **by shape**.
- Each action is processed in its own transaction, and one refusal does not roll back another.
- **Idempotency without a table.** All three kinds are set-once in the domain already:
  `checkIn()` returns early when already checked in, `RollCallService::record()` keeps the first
  answer, and `CascadeEngine::acknowledge()` returns a settled node unchanged. A replay after a lost
  response therefore returns `already_recorded`, which is true. The `client_action_id` is written
  into the audit context for tracing, not used as a lock.
- **Outcomes:** `applied` · `already_recorded` (with the recorded value, channel and time, so the
  device can say "you were already marked SAFE by SMS at 10:05") · `not_accepted`. A malformed
  token, an unknown token and a closed window give the **same** outcome and message (ADR 0016 §3).
- **Per-IP ceiling ≥ the check-in route's.** Two hundred phones at one assembly point often share
  one carrier NAT address. A 429 is retried with backoff by the client, which **never drops** an
  action.

#### 6.4 Whose clock: the physical event, or when the crisis room knew

`captured_at` is parsed **with `->utc()`** (Phase 10 review #2, defect 1). It is trusted only within
bounds: not after server time plus 5 minutes, and not before the window it belongs to opened
(occurrence actual start, cascade `initiated_at`, alert `dispatched_at`). Outside those bounds the
server time is used, and the audit row carries `captured_at_untrusted: true`.

| Kind | Time recorded | Why |
|---|---|---|
| Check-in | bounded `captured_at` → `checked_in_at` | It is a physical fact: the person reached the assembly point then. The evacuation headcount a drill is scored on is built from it. **`CheckInService::checkIn()` gains a trailing `?Carbon $at = null`**, additive, defaulting to `now()` |
| Cascade acknowledgement | bounded `captured_at` → `acknowledge(..., at:)` (the parameter already exists) | It is a physical fact: the person received the call then. The scorecard measures people, not radio coverage |
| Roll-call answer | **server receipt time** → `acknowledged_at` (unchanged); `captured_at` in audit | Its operational meaning is "the crisis room knows". Until 10:40 nobody could stop looking, and backdating it would make the live picture look better than it was |

#### 6.5 "Last synced", honestly

The client shows two facts and never computes a third. They are the server `generated_at` of the
last pack **actually fetched from the network** (a response served from the worker's cache does not
count), and the time the queue last emptied. It shows how many actions are waiting. It never says
"up to date" while offline.

On logout the pack and caches are cleared, but **never the unsent queue**. Logout warns while actions
wait, and queued actions sync without a session (§6.3). There is no silent age-based wipe while the
user stays logged in: in an emergency an old plan with a loud banner beats no plan. Whether that
satisfies NDPA device-retention expectations is `compliance-analyst`'s entry to write.

### 7. Reliability contracts this ADR fixes (the numbers and designs are 12D's)

- **The scheduler dead-man cannot run on the scheduler.** `bcms:watchdog` is scheduled, so "stop the
  scheduler → the watchdog alerts" cannot pass as built. 12D adds a heartbeat that the scheduler
  writes every minute (cache, **no table**) and **two checkers that do not depend on the
  scheduler**. The first is a self-re-dispatching job on `bcms.queues.sync`. The second is an
  allowlisted health route returning 503 when the heartbeat is stale, for an external uptime monitor
  (an external prerequisite).
- **Degraded mode must not change the schema or weaken write-ahead.** Whatever 12D designs for a
  stalled Redis, a delivery row is still written before any provider call (standing rule 8).
- **Queue names come from `config('bcms.queues.*')` only** (commit `b04ac15`). New jobs never name
  a queue literally.

## Contracts touched — broadcast

| Contract | Change | Consumers |
|---|---|---|
| `BcmsLlmClient` (ADR 0010) | Two trailing optional parameters; two added return keys | every BCMS AI caller; code-reviewer |
| `bcms_contacts` + `ContactResolver` (Orchestration §5) | Numbers normalised to E.164 on write; two indexes — **deferred (A1.2)** | Phase 2C (`ChangeDetector`), Phase 6, Phase 7, every channel adapter |
| `InboundResponseHandler` (ADR 0016) | `recipientForNumber` → `MsisdnResolver`; the token lookup made public — **deferred (A1.1, A1.2)** | Phase 7, 12B, 12C |
| `NotificationChannel` (ADR 0004) | **Unchanged.** `UssdChannel` behaviour gains a pull mode — **pull mode deferred (A1.1)** | Phase 5, 7, 10 |
| `AlertService::release()` | Refuses `ai_generated` without a human actor; dispatch requests require `confirm_ai_content` | Phase 7 EMNS, Phase 10 crisis room |
| `CheckInService::checkIn()` | Trailing `?Carbon $at = null` — **deferred with offline (A1.1)** | Phase 9 |
| `bcms-webhook` middleware group (ADR 0016 §5) | Two new routes, USSD and offline sync, under the same "throttle before any I/O" guard — **deferred (A1.1)** | integrations-engineer, qa-engineer |
| `bcms_exercise_definitions` | `ai_generated` column | Phase 4 advisor, Phase 11 reporting (counts may now show it) |
| `InjectDeliveryChannel` (new enum, A1.3 c) | Closed vocabulary for `bcms_exercise_injects.delivery_channel`. Enforced on write; not cast on the model | Phase 9 execution workspace, 12A-2, seeder test |
| Scenario category vocabulary (A1.3 d) | The twelve `ThreatCatalogue` keys. `FIBRE-CUT` becomes `connectivity` | 12A-2, scenario library, compliance-analyst |
| `AiProvenance` record shape (A1.3 b) | Gains `redactions: {phone, email}`. Prompt and output are redacted at write | 12A-1, qa-engineer, compliance-analyst (DPO q17) |
| Plan template `bcp_payments` clause (A1.3 f) | `cbn.open_banking.threshold` becomes `iso22301.8.4.4`. A standalone commit | compliance-analyst, Phase 3 plan library |

## What this ADR deliberately does not do

- **It adds no table.** §1 lists the eleven reasons.
- **It does not make capability 5 an LLM call.** Phase 11's decision stands until the owner says
  otherwise (question 1).
- **It does not machine-translate anything, anywhere.** Phase 7's rule holds. Hausa, Yoruba and Igbo
  copy for alert templates and the USSD menu comes from human translators, as an external
  prerequisite. English and Pidgin are authored in-repo.
- **It does not change "first answer wins"** (`RollCallService`). A later "I need help" after "I'm
  safe" is still not recorded as the answer. USSD and the PWA make that path one keypress away, so it
  is raised as owner question 2 with a recommended change. Changing it is a Phase 7 contract change
  and needs its own ADR, not a line in this one.
- **It does not build push registration.** A raw Web Push subscription does not fit
  `bcms_contacts.push_token` (`string(255)`), and the choice between FCM (hosted) and a self-hosted
  relay is a provider decision with residency consequences. It is argued when that choice is made.
- **It does not build BCMS retention jobs.** Periods are the DPO's to set. A job running on invented
  periods would be a fabricated control.
- **It does not add per-tenant USSD short codes.** One deployment-level code; the tenant follows
  from the caller's number (question 7).
- **It does not edit `config/services.php`, `config/llm.php`, `LlmGateway` or
  `packages/platform/…/SetSecurityHeaders.php`.** They are shared with ERM and TPRM. If the service
  worker needs a CSP directive beyond `default-src 'self'`, that is a platform change with its own
  review.
- **It does not approve any Phase 12 gate.** QA, then code review, on every sub-phase. G3 is signed
  by architect, reliability-engineer and code-reviewer on the engineering half only.

## Alternatives rejected, in one line each

| Alternative | Why not |
|---|---|
| A `bcms_ussd_sessions` table | Three-minute, re-derivable state. A table adds writes on the life-safety path and a cleanup job |
| A `bcms_offline_sync_receipts` table keyed by `client_action_id` | The three actions are already set-once. A receipts table would be a second source of truth that could disagree with the first |
| An idempotency key in the cache | Lost on a Redis stall, the exact chaos scenario G3 tests. Domain idempotency survives it |
| A `bcms_scenario_templates` table | `bcms_scenarios` system rows already exist and are shared across tenants |
| Prompt and output text columns on `llm_usage_events` | Reverses ADR 0015 §10's register statement on a platform table TPRM also writes |
| Logging prompts to a file channel | An undeclared NDPA sink. The Phase 2C review already objected to the app log in that role |
| Audit-row-only provenance for capability 7 | Audit is best-effort by design. A guardrail cannot be |
| ADR 0016's reversed-tail or normalised-E.164 column | Duplicates a personal identifier. Normalising the existing columns plus an index reaches the same lookup |
| A tenant-prefixed `(organization_id, mobile_primary)` index | A gateway callback has no tenant. The prefix would never be usable |
| Keeping the leading-wildcard `LIKE` | Cost grows with every tenant's roster, on an unauthenticated 99.95% path |
| The composer creating and releasing the alert itself | Puts the send path inside the AI service. The whole point is that it holds nothing that sends |
| Routing AI-content confirmation through `approve()` | Collides with the simulation guard Phase 7 argued. It would block drills or weaken the rule |
| A model-drafted Hausa, Yoruba, Igbo or Pidgin alert "for human review" | The human reviewing it usually cannot read it. That is the failure Phase 7's rule exists to prevent |
| A synchronous scenario generation request | 40–90 s on the local model. An HTTP request that long is a timeout, not a feature |
| A fifth Horizon queue for AI | A warm supervisor for a feature that is off by default and runs a few times a year |
| A sessionful offline sync route | Refuses the check-ins taken during the outage it exists for, because of an expired session and a stale CSRF token |
| `checked_in_at = now()` on sync | Records the length of the outage as the time it took to evacuate |
| Backdating roll-call answers to `captured_at` | Makes the crisis room's picture of *when it knew* better than it was |
| The offline viewer as an Inertia page | Its HTML would carry personal data inside a cached document |
| Raising the `'long'` budget for the generator | A shared ERM/TPRM config. Size the output to the budget first, and measure before asking |
