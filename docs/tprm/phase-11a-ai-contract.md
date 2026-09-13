# TPRM Phase 11a — AI consolidation contract

**Status:** Frozen at Phase 11a midpoint · **Date:** 2026-09-11 · **Author:** architect
**Governed by:** ADR 0015 · **Consumers:** TPRM track, BCMS track, platform track
**Amended:** 2026-09-12 — §2.2, §2.3, §4.3, §4.4, §7.5 (new) and §8 for ADR 0015 **§6d**
(architect deviation 7: the context window is declared, `max_document_chars` is derived from it,
and completeness is verified against `prompt_eval_count`). §8 also gains the allowlist count
assertion (ADR 0015 §1 amendment, deviation 8) and the NDPA merge condition (ADR 0015 §10).
**Amended again:** 2026-09-12 — §2.6 (new), §3.3 and §8 for ADR 0015 **§6e** (architect deviation
10: every path applying `max_document_chars` carries the truncation fact to a **named durable
destination**; `tp_contracts` gets no `_meta` column and `clause_analysis`'s numbers go to
`tp_audit_logs`). Escalated by `backend-engineer` from the fifth Gate 2 pass. **Schema freeze
unchanged: 0 structural changes.**
**Implemented by:** `backend-engineer` (§2–§6), `frontend-engineer` (§7), `qa-engineer` then
`code-reviewer` at the gate

This document is the frozen shape. Successors build against it plus Phase 0's mocks. A change to
anything in §3, §4 or §5 after this point is a new ADR and a broadcast, not a quiet edit.

Read ADR 0015 first — it carries the *arguments*. This document carries only the *shapes*.

---

## 1. What is being built, in one paragraph

A platform-level `App\Services\Llm\LlmGateway` becomes the only thing that talks to
`App\Services\LlmService`. It adds transport retry, a per-endpoint circuit breaker, per-tenant
endpoint selection, monthly usage budgets and a persisted usage row per call. `LlmClient` (TPRM)
and `BcmsLlmClient` (BCMS) keep their public APIs and call the gateway instead of the driver.
TPRM gains a `tprm.admin` AI settings screen and a usage report on `tp_settings`. Extraction moves
onto the queue. Three defects found by the 2026-09-09 probe are fixed.

**Nothing moves to `app/Services/Ai/`.** ADR 0015 §1. That directory is not created.

---

## 2. New and changed classes

### 2.1 New — `App\Services\Llm\` (platform, no module imports)

This namespace may not reference `App\Enums\Tprm`, `App\Models\Tprm`, `App\Enums\Bcms` or
`App\Models\Bcms`. A guard test asserts it.

| Class | Responsibility |
|---|---|
| `LlmGateway` | The one door. Resolves profile → checks budget → checks breaker → calls driver with retry → records usage → returns `LlmOutcome`. **Never throws.** |
| `LlmCall` | Immutable request value object (below). |
| `LlmOutcome` | Immutable result value object (below). |
| `Outcome` (enum, `App\Enums\Llm\Outcome`) | `Succeeded`, `Refused`, `CircuitOpen`, `CapExceeded`, `Unreachable`, `Timeout`, `HttpError`, `Unparsable`. Backed by string. |
| `EndpointProfile` | Resolved profile: `key`, `endpoint`, `model`, `keepAlive`, `unitCostPer1kTokensMinor` (nullable), `currency` (nullable). |
| `EndpointResolver` | `resolve(?string $profileKey): EndpointProfile`. Unknown/removed key → default profile, and the *fact* of the fallback is exposed for the screen. |
| `CircuitBreaker` | Cache-backed, keyed `llm:breaker:{profileKey}`. `allows()`, `recordSuccess()`, `recordFailure()`, `state()`, `opensAt()`. |
| `UsageRecorder` | Writes one `llm_usage_events` row per gateway call, including refusals. |
| `UsageBudget` | `check(int $organizationId, UsageCaps $caps): BudgetVerdict`. Reads the monthly aggregate. |
| `UsageReporter` | The read side: monthly totals by service, by outcome, and the recent-call list. |
| `Contracts\ModuleAiPolicy` (interface) | `snapshot(int $organizationId, string $service): ModulePolicySnapshot`. **Implemented outside this namespace, once per module** — `Tprm\Ai\TprmAiPolicy`, `Bcms\Ai\BcmsAiPolicy`. This is how the gateway gets resolved tenant settings while reading no settings table. ADR 0015 §2. |
| `ModuleAiPolicyRegistry` | Module name → policy class, resolved through the container; same shape as `App\Grids\GridRegistry`. Written to **only** by module wiring at boot (`TprmServiceProvider`; `AppServiceProvider` for BCMS, which has no provider). An **unregistered** module resolves to "no policy, therefore nothing enabled" — never a permissive default. `flush()` is **test-only**. |
| `ModulePolicySnapshot` | Immutable: `moduleEnabled`, `tenantMasterEnabled` (tri-state), `serviceDeploymentEnabled`, `tenantServiceEnabled` (tri-state), `UsageCaps`, `endpointProfileKey`. TPRM's tri-state columns and BCMS's boolean both compress to this one shape, in the **module's** namespace. |
| `UsageCaps`, `BudgetVerdict`, `Availability` | Value objects for §2.2 and §5. |
| `RetryBackoff` | The jittered sleep of §6/6c, and **nothing else**. It exists as its own file so the RNG allowlist entry covers two lines of sleep arithmetic instead of the file that computes `total_tokens`, `duration_ms` and `unit_cost_minor`. ADR 0015 §6c. |

**Binding:** `LlmGateway` is bound **transient** in `AppServiceProvider`. Not a singleton — it
carries per-call state and a queue worker would leak one tenant's endpoint and last error into
the next tenant's job. `CircuitBreaker` state lives in the shared cache precisely so a transient
object works. This is the same reasoning as `RuleEvaluator` in `TprmServiceProvider`.

### 2.2 Signatures

```php
namespace App\Services\Llm;

final class LlmCall
{
    public function __construct(
        public readonly int     $organizationId,   // REQUIRED. No tenant, no call. ADR 0015 §5.
        public readonly string  $module,           // 'tprm' | 'bcms' | 'erm'
        public readonly string  $service,          // the per-service switch key
        public readonly string  $promptKey,        // config key, e.g. 'soc2'
        public readonly string  $promptVersion,    // e.g. 'soc2.v2'
        public readonly string  $prompt,           // already rendered by the module's registry
        public readonly string  $system = '',
        public readonly string  $budget = 'short', // key into services.llm.budgets
        public readonly ?string $endpointProfile = null, // null = tenant setting, then default
        public readonly ?string $subjectType = null,     // morph, ADR 0002
        public readonly ?int    $subjectId = null,
        public readonly ?int    $userId = null,
    ) {}
}

final class LlmOutcome
{
    public readonly Outcome $outcome;
    public readonly array   $data;            // [] on anything but Succeeded
    public readonly ?string $reason;          // printable; null only on Succeeded
    public readonly string  $model;
    public readonly string  $endpointProfile;
    public readonly ?int    $promptTokens;    // backend-reported; NULL, never 0, when not reported
    public readonly ?int    $completionTokens;
    public readonly ?int    $totalTokens;
    public readonly int     $durationMs;      // ours; always present
    public readonly int     $attempts;        // transport attempts actually made
    public readonly ?int    $contextWindow;   // the num_ctx ACTUALLY SENT; null when none was sent
    public readonly ?int    $usageEventId;

    public function succeeded(): bool;
}

final class LlmGateway
{
    public function call(LlmCall $call): LlmOutcome;   // never throws
    public function availability(int $organizationId, string $module, string $service, ?string $profile = null): Availability;
}
```

**`contextWindow` (added 2026-09-12, ADR 0015 §6d).** The gateway is the only thing that knows
what it sent, so it is the only thing that may report it. Callers must not re-derive `num_ctx` from
config: the gateway clamps and defaults, and a caller reading the config would report a number the
box never received. Null means no `num_ctx` was sent on that call — and a null `contextWindow`
**forbids the affirmative completeness statement** in §7.5, because nothing was declared to compare
against.

`Availability` carries `allowed: bool`, `reason: ?string`, `layer: ?string` — the layer being
which switch said no (`deployment_llm`, `deployment_module`, `tenant_master`,
`deployment_service`, `tenant_service`, `cap`, `breaker`, `endpoint`). The layer is what lets a
screen tell an administrator *which* switch to go and change, and is why `available(): bool` was
not enough.

### 2.3 Changed — existing classes

| Class | Change | Public API |
|---|---|---|
| `App\Services\LlmService` | Add `forEndpoint(string $endpoint, ?string $model = null): static` returning a **clone**, not a mutation — a transient gateway holding a per-tenant endpoint must not leak it. Pass `keep_alive` through in `json()` **and** `jsonWithUsage()` (defect (c)). **Pass `num_ctx` into Ollama's `options` in all three of `complete()`, `json()` and `jsonWithUsage()`, from `$opts['num_ctx']`, and send the key only when it is present** — never a default invented by the driver. ADR 0015 §6d. | additive only |
| `Tprm\Extraction\LlmClient` | `enabled()`/`available()` gain `?int $organizationId`; `run()` routes through `LlmGateway` and gains trailing `?string $subjectType = null, ?int $subjectId = null`. Keeps `extract()`, `run()`, the three service constants and the refusal-not-throw contract. Its own `log()` is **deleted** — the gateway writes the row. | optional trailing args only; no call site breaks |
| `Bcms\Ai\BcmsLlmClient` | `json()` internals route through `LlmGateway`. **Public API unchanged.** `BiaAiDrafter`, `PlanAiDrafter`, `ProgrammeAdvisor` are not touched. | unchanged |
| `Tprm\Extraction\SchemaValidator` | New `coerce(array $payload, array $schema): array` (defect (a), §6.1). `validate()` unchanged. | additive |
| `Tprm\Extraction\ExtractionDispatcher` | Calls `coerce()` before `validate()`. Skips its second schema attempt when the breaker is open. Copies `low_trust_fields` and `document_truncated` into `_meta`, **and writes `_meta.context_window` (§2.5)**. `MAX_ATTEMPTS` stays 2. **The comment at line 249 asserting that a null `document_truncated` means the document was sent whole is deleted** — it is false, and it is the defect ADR 0015 §6d exists to fix. | unchanged |
| `Tprm\Extraction\PromptRegistry` | `renderKey()` applies `max_document_chars` and returns the truncation fact alongside the text (new `renderWithMeta()`; `renderKey()` kept for callers that do not need it). | additive |
| `Http\Controllers\Tprm\DocumentController` | Dispatches `App\Jobs\RunTprmDocumentExtraction` instead of calling the dispatcher inline. | route shape unchanged |

**On `run()`'s two extra arguments (ruled 2026-09-11, deviation 2).** Amended from "otherwise
unchanged". `llm_usage_events.subject_type`/`subject_id` are justified in §3.1 by their index —
*"tracing a usage spike back to the document that caused it"* — and TPRM's document extraction is
the one caller that causes those spikes. Without a way to name the subject at the one door, that
column pair and its index would be permanently null for the only caller they were designed for,
and the index would be a cost with no reader. The change is additive, trailing and optional; the
alternative (a mutable `forSubject()` on a client resolved from the container) would put per-call
state on a shared object, which is the defect the transient gateway binding exists to avoid.

`$subjectType` **must be a morph alias from `App\Support\MorphTypes`, never a class name.**
`enforceMorphMap()` is on; an FQCN in that column is a row nobody can resolve later.

### 2.4 New job

`App\Jobs\RunTprmDocumentExtraction` — **flat in `app/Jobs/`**. TPRM has no `app/Jobs/Tprm`; BCMS
does. Follow the module you are in.

`$timeout` must exceed `services.llm.budgets.extraction.timeout` × `attempts` plus backoff, or
the worker kills the HTTP call mid-flight and the usage row records a timeout that was ours.
`$tries = 1` — the gateway owns transport retry and the dispatcher owns schema retry; a third
retry layer at the queue would multiply both.

### 2.5 The context window, end to end (added 2026-09-12 — ADR 0015 §6d)

The window is declared by config, sent by the driver, reported by the gateway, carried through the
module client and written once into `_meta`. Five hops, each one-directional, no re-derivation.

| Hop | What changes |
|---|---|
| `config/llm.php` → `context.num_ctx` | New key, **in the gateway's own config and not in any budget** (amended 2026-09-12 — ADR 0015 §6d deviation 9). §4.4. |
| `App\Services\Llm\LlmGateway::attemptGenerate()` | Reads `config('llm.context.num_ctx')` — beside the `llm.retry.*` and `llm.breaker.*` reads already in that method — and passes it in the `$opts` array it already builds alongside the budget's `max_tokens`/`timeout`. Puts the value it sent on `LlmOutcome::$contextWindow`. **If the key is absent or null, nothing is sent and `contextWindow` is null.** |
| `App\Services\LlmService` | Sends `options.num_ctx` when `$opts['num_ctx']` is present. §2.3. |
| `Tprm\Extraction\LlmResult` | Gains `public readonly ?int $contextWindow = null`, a **trailing optional** constructor argument. `LlmClient::run()` populates it from `LlmOutcome`. No call site breaks. |
| `Tprm\Extraction\ExtractionDispatcher::persist()` | Writes `_meta.context_window`. |

**`_meta.context_window` — exact shape.**

```php
'context_window' => [
    'num_ctx'       => 4096,   // int|null — what was sent; null = none was sent
    'prompt_tokens' => 2272,   // int|null — the backend's own count; NULL, never 0
    'fitted'        => true,   // bool|null — see the table below. NULL is a real value here.
],
```

`fitted` is **computed once, server-side, in the dispatcher**, and is the only thing §7.5 branches
on. Not in JavaScript: it is a comparison the screen must never be able to get subtly different
from an export.

| `num_ctx` | `prompt_tokens` | `fitted` |
|---|---|---|
| int | int, **strictly less than** `num_ctx` | `true` — the server did not truncate; a truncated prompt fills the window |
| int | int, `>=` `num_ctx` | `false` — indistinguishable from a prompt ten times the size that was cut to fit |
| int | null | `null` — the backend reported nothing. Not `false`; "we were not told" is a third answer |
| null | anything | `null` — nothing was declared, so nothing can be concluded |

Use `>=`, never `==`, and do not introduce a tolerance margin. A margin would be a number nobody
measured, and there is no need for one: strict inequality already proves the only thing being
claimed.

**`tp_document_extractions` is still not altered.** `extracted` is an existing JSON column and
`_meta` is an existing key inside it. `llm_usage_events` gains **no column** — ADR 0015 §6d, and
the phase's schema-change count stays at **0**.

---

### 2.6 The truncation fact, end to end (added 2026-09-12 — ADR 0015 §6e)

`max_document_chars` is applied in **one** class and declared in **another**, so the declaration is
something a caller must remember rather than something the code does. That is why it reached only
one of three callers. §6e freezes the rule; this section is the wiring.

**Destination map. A prompt key does not ship without a row here.**

| Prompt key(s) | Caller | Durable destination |
|---|---|---|
| `soc2`, `iso_cert`, `pci_aoc`, `pentest`, `insurance`, `financials`, `bcp_test`, `dpa` | `ExtractionDispatcher::persist()` | `tp_document_extractions.extracted._meta.document_truncated` — **already built, unchanged** |
| `clause_analysis` | `ClauseAnalyzer::analyse()` | `tp_contracts.clause_analysis_status = 'analysed_partial'` (the state) **+ a `clause_analysis_partial` row in `tp_audit_logs`** (the two numbers) — the ledger row is the only new work |
| `board_narrative` | `BoardNarrativeWriter::draft()` | `tp_board_packs.figures.narrative_meta.truncated` — **already built, unchanged** |

The first and third rows persist the numbers inside the payload the run produced, which satisfies
§6e destination (a). **They do not also get a ledger event** — one fact, one home; a tidy-up pass
that adds `document_extraction_partial` and `board_narrative_partial` events beside them is
duplicating a record that can then disagree with itself.

`DocumentExtractor::Generic` needs no row: `ExtractionDispatcher` short-circuits it before any
render, and `PromptRegistry::forKey()` throws for an unconfigured key.

#### 2.6.1 Change 1 — `ClauseAnalyzer::analyse()` writes the ledger event

In the `$truncated !== null` branch only, **after** the `clause_analysis_status` save and **before**
`refreshBlockingGapCount()`. That ordering makes the chain read causally: the status change, then
why, then the recomputed count.

```php
try {
    AuditLog::create([
        'organization_id' => $contract->organization_id,
        'auditable_type'  => Contract::class,
        'auditable_id'    => $contract->getKey(),
        'event'           => 'clause_analysis_partial',
        'actor_type'      => $userId !== null ? 'user' : 'system',
        'actor_id'        => $userId,
        'before'          => null,
        'after'           => [
            'prompt_key'      => self::PROMPT_KEY,
            'prompt_version'  => $this->prompts->forKey(self::PROMPT_KEY)['version'],
            'cap'             => $truncated['cap'],
            'original_length' => $truncated['original_length'],
            'detected'        => $detected,
            'applicable'      => $applicable->count(),
        ],
        'ip'         => request()?->ip(),
        'user_agent' => substr((string) request()?->userAgent(), 0, 500) ?: null,
    ]);
} catch (\Throwable $exception) {
    Log::error('TPRM clause-analysis truncation event failed to write', [
        'contract_id' => $contract->getKey(),
        'message'     => $exception->getMessage(),
    ]);
}
```

Four things about that block are load-bearing.

1. **`auditable_type` is the FQCN, not a morph alias.** This column stores `static::class`
   (`TprmAuditable::writeAuditRow()`) and `Contract::auditLogs()` is a `morphMany` joining on it.
   ADR 0002's alias rule governs `llm_usage_events.subject_type`, which is a new column with no
   rows; it does not govern a column with production rows written as FQCNs. Writing an alias here
   would make the event invisible to the relation that would read it.
2. **`prompt_version` is recorded** for the same reason `tp_document_extractions` records it: it
   makes a prompt change distinguishable from a model change when somebody asks why the cap moved.
3. **The try/catch is deliberate and it degrades to the ruled-sufficient state.** The trait's rule
   is that auditing never fails the write (`TprmAuditable`), and by this line the clause rows and
   the status are already persisted — throwing would 500 a completed analysis. A swallowed failure
   loses the *quantity* and logs loudly; the *qualitative* fact is already durable in
   `clause_analysis_status`, which ADR 0015 §6e ruled sufficient for every screen. It cannot
   degrade into silence.
4. **`detected` and `applicable` ride along** because they are already computed and they are what
   makes the row answer "partial against what" without a join.

Imports: `App\Models\Tprm\AuditLog`, `Illuminate\Support\Facades\Log`. No constructor change —
`AuditLog::create()` matches `EvidenceService::logAccess()`'s precedent for a non-CRUD event.

#### 2.6.2 Change 2 — a prompt with no cap is refused, not sent uncapped

`renderWithMeta()` does `(int) ($prompt['max_document_chars'] ?? 0)` and `truncate()` reads
`$cap <= 0` as *send the whole document*, reporting `document_truncated` as null. A configured
prompt that omits its cap therefore sends uncapped and declares nothing — the §6d false negative,
reachable by omission rather than by argument. Replace the `?? 0` with a refusal:

```php
$cap = (int) ($prompt['max_document_chars'] ?? 0);

if ($cap <= 0) {
    throw new RuntimeException(
        "Prompt '{$key}' has no usable max_document_chars. A prompt without a cap is sent uncapped "
        .'and reports no truncation, which is the false negative ADR 0015 §6b and §6d exist to '
        .'prevent. Derive one with the formula in config/tprm_prompts.php.'
    );
}
```

`truncate()`'s own `$cap <= 0` branch stays as a defensive floor; it simply becomes unreachable from
this path. AC 20 already fails a missing cap in the test suite — this makes the same failure a
property of the code, which is the distinction `docs/DEVELOPMENT_STANDARD.md` draws.

#### 2.6.3 Change 3 — the two artefacts stop saying "read in full"

§7.5 rule 1 bans **"read in full"** and **"the model read"** in any state, because we can prove what
was *delivered* and never what was *attended to*. Two artefacts outside AC 21's grep currently use
the banned phrase in its negated form, which defeats the grep and leaves the positive form one edit
away:

| File | Now | Must read |
|---|---|---|
| `resources/js/Pages/Tprm/Contracts/Show.jsx` | "This contract's document was too long to be **read in full**." | "Only the first part of this contract's document was **sent** to the automated reader." |
| `resources/views/reports/pdf/tprm-clause-gap.blade.php` | "The contract document was too long for the automated reader to **read in full**…" | "The contract document was longer than the automated reader's limit, so only its first part was **sent**…" |

The rest of both passages is correct and stays: absent is unproven rather than confirmed, and check
any absent blocking clause by hand. `ContractController::analyse()`'s warning flash already says
"were sent" and needs no change. Neither artefact gains a number in this phase — the PDF's caveat
stays qualitative pending the `compliance-analyst` referral in ADR 0015 §6e, and the screen reads
the state from the row rather than the flash, as it already does.

---

## 3. Schema — FROZEN

### 3.1 New table: `llm_usage_events`

Platform-owned, unprefixed (precedent: `risk_audit_trail`). Append-only. Written by the gateway
for every call from every module, **including refusals**.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `bigIncrements` | no | |
| `organization_id` | `foreignId` → `organizations`, `cascadeOnDelete` | **no** | Never null. A call with no tenant is refused, not recorded unattributed. A nullable value would be hidden by the `BelongsToOrganization` global scope — the `QuestionnaireTemplate` trap. |
| `module` | `string(20)` | no | `tprm` \| `bcms` \| `erm` |
| `service` | `string(40)` | no | the per-service switch key |
| `prompt_key` | `string(60)` | no | |
| `prompt_version` | `string(40)` | no | so a prompt change is distinguishable from a model change — the same rule as `tp_document_extractions` |
| `endpoint_profile` | `string(40)` | no | which box answered |
| `model` | `string(120)` | no | |
| `outcome` | `string(20)` | no | `Outcome` enum value. String column + PHP enum cast, per module convention |
| `attempts` | `unsignedTinyInteger` default 1 | no | transport attempts actually made |
| `prompt_tokens` | `unsignedInteger` | **yes** | backend-reported. Null when not reported — never 0 |
| `completion_tokens` | `unsignedInteger` | **yes** | as above |
| `total_tokens` | `unsignedInteger` | **yes** | **stored, not derived at read time**, so the monthly `SUM` is one column with no SQL arithmetic. Null when either part is null |
| `duration_ms` | `unsignedInteger` | no | ours; always present |
| `unit_cost_minor` | `unsignedInteger` | **yes** | populated **only** when the endpoint profile declares a price. Null = not priced |
| `currency` | `char(3)` | **yes** | as above |
| `usage_month` | `char(7)` | no | `'2026-09'`, computed in PHP at insert in **`config('app.timezone')`** — the one clock both the writer and the cap check share (amended 2026-09-11; ADR 0015 §4 carries the argument and the bounded month-boundary error). This column is the portable-SQL device that makes the monthly aggregate index-usable on MariaDB 10.4 |
| `subject_type` | `string(255)` | yes | morph **alias**, per ADR 0002 — never an FQCN. TPRM document extractions write `tprm_document` (`App\Support\MorphTypes`, added in 11a alongside the existing `tprm_third_party`). The alias is permanent once rows exist. |
| `subject_id` | `unsignedBigInteger` | yes | |
| `user_id` | `foreignId` → `users`, `nullOnDelete` | yes | null for scheduled work |
| `created_at` | `timestamp` | no | **no `updated_at`** — the row is append-only |

**Indexes — three, and no more.**

| Index | Serves |
|---|---|
| `(organization_id, usage_month, service)` | the cap check (leftmost two columns) **and** the by-service report. A separate `(organization_id, usage_month)` index would be a redundant prefix of this one — do not add it. |
| `(organization_id, created_at)` | the recent-call list and the retention prune |
| `(subject_type, subject_id)` | tracing a usage spike back to the document that caused it |

No index on `outcome` or `module`: both are low-cardinality and always filtered alongside
`organization_id`.

**Forbidden in every query against this table:** CTEs, window functions, raw JSON functions,
`DATE_FORMAT` in a `WHERE`. MariaDB 10.4 is the target and `phpunit.xml` now enforces it on this
branch.

### 3.2 Changed table: `tp_settings` (5 columns added)

The table already exists with `unique(organization_id)` and is already served by
`ProgrammeSettingsController` behind `tprm.admin`. No new settings table.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `ai_enabled` | `boolean` | **yes** | `null` | **Tri-state.** `true` = tenant opted in, `false` = tenant opted out, `null` = **nobody has asked this tenant**, follow `config('tprm.ai.enabled')`. ADR 0015 §2 for why this differs from `bcms_settings.ai_enabled`. |
| `ai_services` | `json` | yes | `null` | `{"evidence_extraction": true, "clause_analysis": false}`. **An absent key means "not decided", not false.** Read per-row by a service; never queried in SQL, so `json` is safe on MariaDB 10.4. |
| `ai_endpoint_profile` | `string(40)` | yes | `null` | A **key** into `config/llm.php` → `profiles`. Never a URL. |
| `ai_monthly_token_cap` | `unsignedBigInteger` | yes | `null` | Null = uncapped. Not a currency. |
| `ai_monthly_call_cap` | `unsignedInteger` | yes | `null` | Null = uncapped. |

`ai_cap_action` was considered and **rejected**: a cap that only warns is not a cap, and the
second mode would be switched on the first time it fired and never switched back. Exceeding the
cap refuses the call and records a `CapExceeded` row.

`TprmSetting::$fillable` and `$casts` extend accordingly: `ai_enabled` → `boolean`,
`ai_services` → `array`, the two caps → `integer`.

**The write end reads the same way (ruled 2026-09-11, deviation 4).** "An absent key means *not
decided*, not false" is a statement about the whole column, not only about reads, so a `PUT` that
omits `ai_services` entirely means *"this request decided nothing about services"* — it does not
mean *"forget every service preference this tenant recorded"*. `update()` therefore **merges keys
present** rather than replacing the column. Keys the request does send win outright, **including a
key sent as `null`**, which records "not decided": the resolver treats a present `null` and an
absent key identically, so merge cannot strand a value a tenant meant to clear. On the specified
form path, which always submits all seven rows, merge and replace are indistinguishable; the merge
is for the paths that are not the form.

**phpstan-baseline.neon:** no new entry needed — `TprmSetting` already uses
`BelongsToOrganization` and already has its two trait-limitation entries. `LlmUsageEvent` is a new
tenant-scoped model and **does** need the same pair; copy the shape from the neighbouring entries
(the baseline block beginning at the `BelongsToOrganization` comment).

### 3.3 Nothing else changes

`tp_document_extractions` is untouched. `bcms_settings` is untouched. No column is added to
`organizations`.

**`tp_contracts` is untouched, and that is now a ruling rather than an omission** (added
2026-09-12 — ADR 0015 §6e). It gains **no** `_meta`, `analysis_meta` or truncation column, in this
phase or a later one as currently framed. `clause_analysis_status` takes a third *value* —
`analysed_partial` — in an existing free-form `string(20)` with no DB enum; a value is not a
structural change and needs no migration. The cap and the original length are per-**run** facts and
go to `tp_audit_logs`, which already exists, is append-only and hash-chained, is indexed by
`(organization_id, auditable_type, auditable_id)`, and already accepts hand-written non-CRUD events
(`EvidenceService::logAccess()`). ADR 0015 §6e carries the argument and names the two triggers that
would reopen it. **The phase's structural-change count stays at 0.**

`tp_board_packs` is untouched for the same reason in the other direction: `figures` is an existing
JSON column documented as holding "every figure the pack prints", and `narrative_meta` is a key
inside it.

---

## 4. Config — FROZEN

### 4.1 New: `config/llm.php` — **not publishable**

Same reasoning as `config/tprm.php`: `endpoint_profile` is stamped onto every usage row, and a
published copy could carry a profile the code has never seen.

```php
return [
    // The breaker is useless on a per-process store. The settings screen
    // reports whether the resolved store is shared.
    'cache_store' => env('LLM_CACHE_STORE', null),   // null = default store

    'retry' => [
        'max_attempts'   => 2,
        'backoff_ms'     => [1000, 3000],   // jittered
        'retry_on'       => ['connection', 'timeout', 'http_5xx'],
    ],

    'breaker' => [
        'failure_threshold' => 3,    // consecutive
        'open_seconds'      => 60,
        'half_open_probes'  => 1,
    ],

    'default_profile' => env('LLM_PROFILE', 'local-ollama'),

    'profiles' => [
        'local-ollama' => [
            'label'      => 'Local model (this deployment)',
            'endpoint'   => env('LLM_ENDPOINT', 'http://localhost:11434'),
            'model'      => env('LLM_MODEL', 'granite4:micro'),
            'keep_alive' => '30m',
            // Self-hosted. There is no price, so there is no figure.
            // NULL, not 0 — see ADR 0015 §4.
            'unit_cost_per_1k_tokens_minor' => null,
            'currency'                      => null,
        ],
    ],

    'retention_months' => 24,
];
```

A profile carries **no `timeout` and no `max_tokens`.** Those live in
`config/services.php` → `llm.budgets`, keyed by the shape of the answer, and that is their only
home.

### 4.2 Changed: `config/tprm.php`

| Change | Why |
|---|---|
| **Delete** `ai.monthly_spend_cap_minor` | Read by nothing, and named after a currency this deployment does not spend. ADR 0015 §4. |
| **Add** `ai.services.<key>.implemented` (or a sibling `ai.implemented_services` list) | Four of the seven services — `subprocessor_discovery`, `response_quality`, `adverse_media_triage`, `scoping_assistant` — have **no code behind them anywhere in `app/`**. The screen must render them as *"Not built in this release"*, not as switches that silently do nothing. |
| Unchanged | `ai.enabled`, the seven `ai.services.*` booleans, `ai.confidence_threshold` |

### 4.3 Changed: `config/tprm_prompts.php`

Per prompt, two additive keys. Existing keys (`version`, `system`, `instructions`) unchanged.

| Key | Type | Notes |
|---|---|---|
| `max_document_chars` | int | ADR 0015 §6b and **§6d**. Truncation at a paragraph boundary, and it is **declared**, never silent. **The value is derived, not chosen** — see below. |
| `low_trust_fields` | list of string | Fields the probe shows the model gets wrong. `soc2` → `['subservice_method']` (wrong in 4 of 5 runs). |

**`max_document_chars` is derived from the context window (amended 2026-09-12, ADR 0015 §6d).**
The shipped values were chosen against nothing and `soc2`'s 60,000 was roughly fifteen times the
window the server actually applies, so the server truncated below our cap and `document_truncated`
stayed null. Every value is replaced by the arithmetic:

```
max_document_chars = floor( (num_ctx - budget.max_tokens) * 3.5 ) - rendered_overhead
                     rounded DOWN to the nearest 100
rendered_overhead  = strlen(system) + strlen(instructions) + strlen(the vendor-data footer) + 120
```

`num_ctx` is `config('llm.context.num_ctx')` — one window for every governed call, **not** a
per-budget value (§4.4). `budget.max_tokens` is still the budget's, via `LlmClient::budgetFor()`.

`3.5` chars/token is deliberately below the ~4.4 the 2026-09-09 probe implies; ADR 0015 §6d carries
the argument for the margin and for why an *approximate* cap is safe once `_meta.context_window`
verifies it. `120` covers the two delimiter lines and the blank lines `renderWithMeta()` joins with.
`max_tokens` is **reserved, not shared**: llama.cpp evicts the earliest prompt tokens to make room
for generation, which is silent truncation through a second door.

The budget per prompt is the one `LlmClient::budgetFor()` already returns — `narrative` for
`board_narrative`, `extraction` for the other nine. At `num_ctx = 4096` (§4.4) the values are:

| Prompt | Budget | `max_tokens` | Overhead (chars) | **`max_document_chars`** | Was |
|---|---|---|---|---|---|
| `soc2` | `extraction` | 2048 | 3,458 | **3,700** | 60,000 |
| `iso_cert` | `extraction` | 2048 | 1,504 | **5,600** | 12,000 |
| `pci_aoc` | `extraction` | 2048 | 1,503 | **5,600** | 16,000 |
| `pentest` | `extraction` | 2048 | 1,719 | **5,400** | 30,000 |
| `insurance` | `extraction` | 2048 | 1,507 | **5,600** | 10,000 |
| `financials` | `extraction` | 2048 | 1,590 | **5,500** | 20,000 |
| `bcp_test` | `extraction` | 2048 | 1,302 | **5,800** | 16,000 |
| `clause_analysis` | `extraction` | 2048 | 2,318 | **4,800** | 40,000 |
| `dpa` | `extraction` | 2048 | 2,834 | **4,300** | 30,000 |
| `board_narrative` | `narrative` | 900 | 1,347 | **9,800** | 16,000 |

Values are the derivation **rounded down** to the nearest hundred, **with no exceptions and no
hand-tuning**. Write them as literals with the formula in a comment above the file's first prompt
block — a config that computes itself hides the number that matters — and let §8.20's guard test
re-derive them so the literal and the formula cannot drift. Recompute every row if any `system` or
`instructions` string is edited; the guard test is what will tell you.

**`soc2` was 3,500 in the first draft of this table and is corrected to 3,700 (2026-09-12).** The
formula gives 3,710, and floor-to-hundred is 3,700; every other row followed that rule and this one
did not. It was an **arithmetic slip, not a deliberate margin**, and it is recorded rather than
quietly corrected so that nobody restores 3,500 from an older copy on the assumption it meant
something. It was caught by a hand-check at implementation, which is also why §8.20 is tightened
from "at or below" to equality below: an inequality passes over exactly this mistake.

A deliberate margin on a prompt remains possible and must be **stated as one** — a named per-prompt
override of the chars-per-token constant, which the guard test then re-derives from. It may not be
a literal that is quietly smaller than its formula, because that is indistinguishable from this
slip.

`soc2` at 3,700 characters reads about a page and a half of a 90-page report. **That is not a
regression introduced here; it is the extraction that has always been happening, now visible.**
ADR 0015 §6d.

`soc2.version` bumps `soc2.v1` → `soc2.v2` with sharpened carve-out wording. Existing extraction
rows keep `soc2.v1` and stay interpretable — that is what the column is for.

### 4.4 Changed: `config/services.php`

`llm.budgets.extraction` already landed at `['max_tokens' => 2048, 'timeout' => 120]`. The two
existing keys are unchanged. `llm.timeout`'s default of 20 s stays as the driver's own fallback;
nothing in TPRM reaches it any more.

**`num_ctx` is NOT a budget key. `config/services.php` is unchanged by this contract**
(corrected 2026-09-12 — ADR 0015 §6d deviation 9).

A first implementation added `'num_ctx' => 4096` to `budgets.extraction` and `budgets.narrative`.
**Both must be removed.** `Risk\AiToolsController::narrative()` calls
`$this->llm->json($prompt, $system, [...self::budget('narrative'), ...])` — it spreads the whole
budget array into a direct `LlmService` call — so the key reached ERM's executive-narrative tool,
one of ADR 0015 §1's three grandfathered callers, and pinned it to 4,096 on any deployment that had
raised `OLLAMA_CONTEXT_LENGTH`. ERM derives no cap, writes no usage row and has no `fitted` signal,
so the reduction would never have surfaced.

**The window lives in `config/llm.php` instead** — the gateway's own config, not publishable, read
by `LlmGateway` already and by no module caller:

```php
    // ADR 0015 §6d. Declared, not assumed: the value the 2026-09-09 probe ran
    // under, stated here so `max_document_chars` can be derived from a number
    // this repository sets rather than from Ollama's default.
    //
    // NOT a budget key. A budget array is spread wholesale into LlmService by
    // Risk\AiToolsController, so anything put there reaches the grandfathered
    // ERM callers, which derive nothing from a window and only lose the
    // deployment's own tuning. Governance belongs in the gateway's config.
    'context' => [
        'num_ctx' => 4096,
    ],
```

Absent or null ⇒ no `num_ctx` in the request ⇒ `LlmOutcome::$contextWindow` is null ⇒
`_meta.context_window.fitted` is null ⇒ §7.5's affirmative statement is unavailable. The chain
degrades to "we cannot say", never to a false claim.

A budget carries `max_tokens` and `timeout`, and **nothing else**. An endpoint profile carries
neither, and no context ceiling either — ADR 0015 §6 and §6d, same reason: one profile exists, and
two consumers is not a pattern.

---

## 5. Resolution order — the one sequence every caller inherits

```
1. config('services.llm.enabled')            false → Refused, layer=deployment_llm
2. config('tprm.ai.enabled')                 false → Refused, layer=deployment_module
3. tp_settings.ai_enabled                    false → Refused, layer=tenant_master
                                             null  → fall through to (2)'s value
4. config('tprm.ai.services.<s>')            false → Refused, layer=deployment_service
5. tp_settings.ai_services[<s>]              false → Refused, layer=tenant_service
                                             absent→ fall through to (4)'s value
6. UsageBudget::check()                      over  → CapExceeded, layer=cap
7. CircuitBreaker::allows(profile)           open  → CircuitOpen, layer=breaker
8. LlmService->forEndpoint(profile)->available()  no → Unreachable, layer=endpoint
9. call, with transport retry
```

**A tenant may only ever narrow.** No tenant value can enable something the deployment has
disabled. Steps 1–5 make no network call, so a deployment with AI off never contacts a box to
discover that AI is off — the ordering both existing clients already hold, preserved.

Steps 1–5 and 7 write a usage row; step 6 writes one; step 8 writes one. **Refusals are recorded.**
A usage report that only counts successes cannot tell a quiet month from a broken endpoint.

---

## 6. The three probe defects — exact fixes

### 6.1 `"Security"` ≠ `"security"` (discards a correct extraction after two attempts)

`SchemaValidator::coerce(array $payload, array $schema): array`, called by `ExtractionDispatcher`
**before** `validate()`, never after.

For each field whose spec has an `in` list where **every** allowed value is a lower-snake string:
take the incoming scalar (or each entry of an incoming list), `trim`, `strtolower`, fold internal
runs of spaces and hyphens to a single `_`. If the result is exactly one of the allowed values,
substitute it. Otherwise leave the original untouched so `validate()` reports the real value in
its error message.

Must pass: `"Security"` → `security`; `"Type II"` → `type_ii`; `"Carve-Out"` → `carve_out`;
`" PROCESSING INTEGRITY "` → `processing_integrity`; `"Kwality"` → unchanged, and still fails.

`normalise()` is **not** moved and **not** changed. It reshapes into the persisted payload and
validating its output would validate a different document from the one the model returned.

### 6.2 The 20-second timeout

Already fixed in `config/services.php` → `llm.budgets.extraction`. This contract adds the second
half: `keep_alive` must be sent by `json()` and `jsonWithUsage()`, not only by `complete()`. Cold
model load is a large share of the observed 26–69 s and is pure waste on the second call of a
batch.

### 6.3 Carve-out read as inclusive (4 of 5 runs)

Not fixable in code. `soc2.v2` sharpens the wording; `low_trust_fields = ['subservice_method']`
puts the fact in `_meta`; the confirmation screen badges that field. The reviewer is told which
field the model is known to get wrong instead of being asked to check all twelve equally.

---

## 7. The settings screen — what it reads and writes

Two screens, one controller each, both behind `permission:tprm.admin` in the TPRM group of
`routes/web.php`. Not folded into `ProgrammeSettingsController`: that screen is about regulatory
identity, and a two-purpose settings screen is how one of the two ends up unmaintained.

| Route | Name | Controller |
|---|---|---|
| `GET  tprm/settings/ai` | `tprm.settings.ai` | `Tprm\AiSettingsController@edit` |
| `PUT  tprm/settings/ai` | `tprm.settings.ai.update` | `Tprm\AiSettingsController@update` |
| `GET  tprm/settings/ai/usage` | `tprm.settings.ai.usage` | `Tprm\AiUsageController@index` |

No policy and no entry in `TprmServiceProvider::POLICIES` — ADR 0015 §8. The guard test asserting
that map stays exact.

Inertia pages: `Tprm/Settings/Ai` and `Tprm/Settings/AiUsage`. Presenters per
DEVELOPMENT_STANDARD §1; the recent-call list uses `GridPresenter` + `DataGrid` per §8.

### 7.1 `Tprm/Settings/Ai` — reads

```
deployment: {
  llm_enabled, module_enabled,
  services: [{ key, label, deployment_enabled, implemented, effective, tenant_value }],
  profiles: [{ key, label, is_default, priced }],
  cache_store_is_shared,        // false ⇒ the breaker does nothing; say so
  model, default_profile_key,
}
tenant: { ai_enabled, ai_services, ai_endpoint_profile, ai_monthly_token_cap, ai_monthly_call_cap }
effective: { enabled, master_layer, services: { <key>: bool }, endpoint_profile }
             // master_layer: the layer that decided the MASTER switch, null when enabled.
             // Added 2026-09-11 (frontend deviation 1): the per-service rows already carry a
             // backend `layer` and the master row must not be the one place a screen
             // re-derives §5 in JavaScript.
breaker:   { state, opens_at, consecutive_failures }   // per resolved profile
month:     { usage_month, total_tokens, call_count, token_cap, call_cap, remaining }
stale_profile: ?string   // the stored key is no longer offered; the default is in use
```

**Three layers are shown, never just the effective value.** A tenant admin who switches on a
service the deployment has switched off is told the switch will not take effect. Anything the
screen cannot determine reads "Not reported", never `0`.

### 7.2 `Tprm/Settings/Ai` — writes

`UpdateAiSettingsRequest` (a Form Request — no inline validation, per DEVELOPMENT_STANDARD §4).

| Field | Rule |
|---|---|
| `ai_enabled` | `nullable|boolean` — null is a legitimate value meaning "follow the deployment" |
| `ai_services` | `nullable|array`; keys must be `Rule::in(array_keys(config('tprm.ai.services')))`; values `boolean|null`. A key for an **unimplemented** service is rejected. |
| `ai_endpoint_profile` | `nullable|string`, `Rule::in(array_keys(config('llm.profiles')))`. **A URL is never accepted.** |
| `ai_monthly_token_cap` | `nullable|integer|min:0` |
| `ai_monthly_call_cap` | `nullable|integer|min:0` |

Writes `tp_settings` + `updated_by`. **`ai_services` is merged, not replaced** — §3.2. Do not
"simplify" it to an assignment: that turns any partial `PUT` into a silent erase.

**`TprmSetting` must gain the `TprmAuditable` trait — it does not have it today.** It carries
`BelongsToOrganization` only, so every change ever made to the shareholders'-funds figure and the
DORA identity fields has gone unaudited as well. Changing who may call a model is a governance
event and a model-risk function will ask who turned it on and when. Adding the trait is in 11a's
scope and it retro-covers the Phase 9 and Phase 10 columns at no extra cost; it changes no
schema, since `tp_audit_logs` already exists.

### 7.3 `Tprm/Settings/AiUsage` — the report

Default window: the current `usage_month`, with a month selector over the retained range.

| Panel | Query | Rule |
|---|---|---|
| By service | `SELECT service, COUNT(*), SUM(total_tokens), SUM(duration_ms), SUM(outcome='succeeded') … GROUP BY service` filtered by `organization_id` + `usage_month` | plain `GROUP BY` on the composite index. No CTE, no window function |
| By outcome | same, `GROUP BY outcome` | includes refusals, breaker trips and cap hits |
| Against the cap | `SUM(total_tokens)`, `COUNT(*)` vs the two caps | `remaining` **and** each tile's `tone` are computed server-side and delivered; see below |
| Recent calls | `(organization_id, created_at)` index, `GridPresenter` | |
| Cost | **Rendered only when the resolved profile declares a price.** Otherwise the panel reads *"Not priced — self-hosted endpoint"* | ADR 0015 §4. No `0.00`, ever. `NoFabricatedNumbersTest` would be right to fail one |

Where token counts are null because the backend did not report them, the report shows the **call
count** and says *"tokens not reported by this backend"* for that slice. It does not sum nulls as
zero and present the result as a total. This is the module's standing rule applied to its own
report: *"we checked and it is fine"* is a different statement from *"nobody looked"*.

The screen labels the limits **"Monthly usage limit"**. The words "spend" and "cost" do not appear
against an unpriced profile.

**Every figure this screen prints is computed server-side (ruled 2026-09-11, frontend deviation
2).** `month.remaining` was already in §7.1's field list and must actually be sent. Two more are
added to it: each cap tile's `tone` (`critical` when `remaining <= 0`, `warn` at ≥ 90 % consumed,
otherwise neutral — a **threshold**, i.e. a judgement, and the settings screen computes its own
server-side, so a browser-side copy is a second home for the 90 %), and each by-outcome row's
`share` of the month's calls, which belongs in `UsageReporter::byOutcome()` so an export and the
screen can never disagree. `share` is **null when `call_count` is 0** — never `0`, and never a
division the browser has to guard.

**A month outside the retention window is declared, not silently swapped (ruled 2026-09-11,
frontend deviation 3).** `AiUsageController` is right to normalise `?month=` to a retained value —
`TprmAiUsageEventsGrid` reads the parameter independently, and an un-normalised URL would have the
grid and the panels above it showing different months. What it must stop doing is normalising
*silently*: the response carries `requested_month` (the out-of-range string the URL asked for, or
`null`), and the screen says which month it was asked for, why that month is gone, and which month
it is showing instead. Same discipline as ADR 0015 §6b's declared truncation — the substitution is
fine, the silence is not.

### 7.4 Retention

`App\Console\Commands\PruneLlmUsageEvents` (`llm:prune-usage`), flat in `app/Console/Commands/`,
registered in `routes/console.php`, daily. Deletes rows older than `config('llm.retention_months')`
in chunks. This telemetry is not audit evidence and has no regulatory retention; `tp_audit_logs`
holds the governance events.

### 7.5 The extraction confirmation screen — the completeness banner (added 2026-09-12)

`resources/js/Pages/Tprm/Documents/Show.jsx`. Reads `_meta.document_truncated` (unchanged) and
`_meta.context_window` (§2.5). **Three states, and there is no fourth.** The screen branches on
`document_truncated === null` and on `context_window.fitted`, and computes nothing else.

| State | Condition | What the screen says |
|---|---|---|
| **We truncated it** | `document_truncated` is not null | The cap, the original length, and that the cut was made at a paragraph boundary. Unchanged from ADR 0015 §6b. |
| **Complete, and confirmed** | `document_truncated` is null **and** `fitted === true` | The affirmative, **with both numbers**: the whole document was sent, and the model reported *N* prompt tokens against a declared window of *M*. |
| **Complete as far as we cut, unconfirmed** | `document_truncated` is null **and** `fitted` is `false` or `null` | We did not truncate this document. Then, distinctly: either the backend reported no token count, or it reported a count at the window, in which case the model may have received less than we sent. |

**Binding wording rules.**

1. The words **"read in full"**, **"the model read"** and any equivalent never appear, in any state.
   We can prove what was *delivered*. Nothing here is evidence of what was *attended to*, and the
   difference is the whole of ADR 0015 §6d.
2. **"Sent whole"** is permitted **only** in the confirmed state, and only next to the two numbers
   that make it checkable. Alone it is the old false comment with a nicer font.
3. No percentage, no ratio, no "approximately N % of the document". `original_length` and the cap
   are both exact and both printable; a derived share is a figure nobody measured, and
   `NoFabricatedNumbersTest` (DEVELOPMENT_STANDARD §5) would be right about it.
4. Where a number is absent it reads **"not reported"**, never `0` and never blank. The module's
   standing rule, on the one screen it matters most.
5. The unconfirmed state is **visually distinct from** the confirmed state. If the two render alike,
   §6d's fix has reproduced §6b's defect in CSS.

The truncated state is now the **normal** case for a real SOC 2, not an exception. That is
intended: the banner prints figures that differ per document, so it does not become wallpaper, and
it is the mechanism by which "our extractor reads a page and a half of a 90-page report" reaches a
human instead of staying in a config file.

**The instruction already given to `frontend-engineer` stands and needs no rework.** A banner that
states only measured facts and never claims completeness is correct in all three states and is the
correct thing to ship today. The confirmed state's affirmative line is **additive** and lands when
backend ships `_meta.context_window` — nobody waits on the other.

---

## 8. Acceptance criteria for the 11a gate

1. `AC-16` still passes: with every AI switch off at deployment level, every TPRM workflow
   completes manually and no screen 500s.
2. With AI off at **tenant** level and on at deployment level, the same holds, and the screen
   names the tenant switch as the reason.
3. A tenant cannot enable a service the deployment has disabled — asserted at the resolver, not
   only at the form.
4. `ai_endpoint_profile` rejects a URL and accepts only a configured key.
5. Exceeding `ai_monthly_token_cap` refuses the next call, writes a `CapExceeded` row, and the
   screen states the cap and the month.
6. Three consecutive transport failures open the breaker; a fourth call is refused **without a
   network request**; after `open_seconds` exactly one probe is admitted.
7. An unparseable 200 does **not** open the breaker.
8. `coerce()` accepts `"Security"`, `"Type II"` and `"Carve-Out"`, and still rejects `"Kwality"`.
9. Every gateway call writes exactly one `llm_usage_events` row, refusals included, with
   `organization_id` never null.
10. A call with no tenant context is refused and writes nothing.
11. The usage report renders no cost figure for an unpriced profile, and renders "not reported"
    rather than `0` where the backend gave no token counts.
12. Every query in `UsageReporter` and `UsageBudget` runs on **MariaDB 10.4** — no CTE, no window
    function, no raw JSON function, no `DATE_FORMAT` in a `WHERE`.
13. Cross-tenant probe: tenant A's usage report contains no row of tenant B's.
14. BCMS's three AI drafters behave unchanged through the gateway. **[verify at integration]** at
    the next BCMS integration window — BCMS Phase 7 is in flight and the overlap rule covers it.
15. Extraction runs on the queue; the upload screen reports progress and does not hold the request
    open for the duration of a model call.
16. A `PUT` to `tprm.settings.ai.update` that omits `ai_services` leaves the stored per-service
    preferences intact; one that sends a single key changes only that key; one that sends a key as
    `null` records "not decided" and resolves identically to an absent key (§3.2).
17. The RNG allowlist in `tests/Feature/NoFabricatedNumbersTest.php` and `scripts/check-no-rng.sh`
    counts **3**, and the third entry is `app/Services/Llm/RetryBackoff.php`. **`LlmGateway.php`
    must not appear in either allowlist** — it computes `total_tokens`, `duration_ms` and
    `unit_cost_minor`, and a file-level exemption there would cover every figure the usage report
    prints (ADR 0015 §6c).

18. **`num_ctx` is sent, and only through the gateway.** An extraction call reaches `LlmService`
    with `options.num_ctx` set to `config('llm.context.num_ctx')`, and `LlmOutcome::$contextWindow`
    reports the value that was sent; with the key unset, no such option is sent and
    `contextWindow` is null. **And the leak is asserted closed:** no array under
    `config('services.llm.budgets')` contains a `num_ctx` key, and a direct
    `Risk\AiToolsController` call sends no `num_ctx` — the regression this criterion exists to
    catch is a future engineer moving the value back onto a budget for tidiness.
19. **`_meta.context_window` is written on every persisted extraction**, with `fitted` computed
    server-side by the truth table in §2.5 — including the two `null` cases, which are distinct
    from `false`. `fitted` is never computed in JavaScript.
20. **The caps match the formula exactly.** A guard test re-derives every `max_document_chars` in
    `config/tprm_prompts.php` from `config('llm.context.num_ctx')`, the prompt's own budget (via
    `LlmClient::budgetFor()`), `3.5` chars/token and the prompt's measured overhead, and asserts
    each literal **equals** the derived value floored to the nearest hundred. **Equality, not
    "at or below"** (tightened 2026-09-12): an inequality passes silently over a literal that is
    smaller than its own formula for no recorded reason, which is exactly the slip that put `soc2`
    at 3,500 (§4.3). It fails if a prompt's `instructions` grow without its cap being recomputed,
    and it fails if a new prompt key ships with no cap at all. A deliberate margin is expressed as
    a named per-prompt chars-per-token override that the test re-derives from — never as a quietly
    smaller literal.
21. **A 90-page SOC 2 is declared.** Extracting a document well over `soc2`'s cap produces a
    non-null `_meta.document_truncated` and the confirmation screen prints the cap, the original
    length and the paragraph-boundary fact. The words "read in full" and "the model read" appear
    nowhere in `resources/js/Pages/Tprm/Documents/Show.jsx`, in any state (§7.5).
22. **`LlmGatewayGuardTest` asserts `assertCount(5, self::ALLOWED_LLM_SERVICE_CALLERS)`**, with a
    failure message naming ADR 0015 §1's amendment. Growth and shrinkage both fail; shrinking is a
    deliberate edit that updates the ADR in the same commit.
23. **Merge condition, not a gate defect:** `docs/compliance/ndpa-register-platform.md` exists and
    carries the `llm_usage_events` entry required by ADR 0015 §10, including the statement that the
    table holds no prompt, no response and no document text. **`docs/compliance/ndpa-register.md`
    carries no `llm_usage_events` entry** — verified by grep, not by intent.
    **Wording narrowed 2026-09-12:** this criterion previously read *"is unmodified"*, which fails
    for another track's work — BCMS Phase 7 and `compliance-analyst` are legitimately editing that
    file in this same tree (its current diff is `bcms_*` rows only). What 11a must not do is put a
    **platform** table into a **module's** register; "nobody else may touch the file" was never the
    rule and is not 11a's to impose.

24. **The truncation fact reaches a durable destination on every path** (ADR 0015 §6e). A clause
    analysis run against a document over `clause_analysis`'s 4,800-character cap leaves
    `tp_contracts.clause_analysis_status = 'analysed_partial'` **and** exactly one
    `clause_analysis_partial` row in `tp_audit_logs` for that contract, whose `after` carries
    `cap`, `original_length`, `prompt_key`, `prompt_version`, `detected` and `applicable`, with
    `auditable_type` as the FQCN `App\Models\Tprm\Contract` — asserted through
    `$contract->auditLogs()`, which is the relation that would break on an alias. A run **under**
    the cap writes no such row and leaves the status `analysed`. The numbers survive a page reload;
    asserted by re-reading the contract fresh, not by reading the flash.

25. **A prompt with no cap is refused, not sent uncapped.** `PromptRegistry::renderWithMeta()`
    throws for a configured prompt whose `max_document_chars` is absent, null, zero or negative,
    and the message names the key. This is AC 20's failure made a property of the code: today the
    `?? 0` path sends the whole document and reports `document_truncated` as null, which is §6d's
    false negative reachable by omission.

26. **The discard cannot spread.** A guard test in `LlmGatewayGuardTest`'s shape asserts (a) the
    allowlist of files calling `PromptRegistry::renderKey()` is exactly
    `app/Services/Tprm/Extraction/LlmClient.php`, with the count asserted so growth **and**
    shrinkage fail and the message naming ADR 0015 §6e; and (b) every file calling
    `renderWithMeta()` also contains the string `document_truncated`. Blunt on purpose — (b) is the
    test that would have failed on `ClauseAnalyzer`'s first render call, and `NoFabricatedNumbersTest`
    is the standing evidence that a mechanical guard catches what careful review did not. Plus:
    the words **"read in full"** and **"the model read"** appear in neither
    `resources/js/Pages/Tprm/Contracts/Show.jsx` nor
    `resources/views/reports/pdf/tprm-clause-gap.blade.php`, in any state — AC 21's grep, extended
    to the two artefacts §7.5's wording rules always governed (§2.6.3).

Gate order is unchanged and not negotiable: `qa-engineer`, then `code-reviewer`. The architect
does not approve the architect's phase.

---

## 9. What 11a does NOT include

Stated so scope does not drift into items 2–7 of Phase 11.

**Out — other Phase 11 items, in their entirety:**

| | |
|---|---|
| Item 2 | `AssessmentScopingAssistant` and its `scoping_trace`; `NarrativeGenerator` for assessment summaries, engagement narratives, quarterly reports, exit-plan skeletons and regulatory drafts. 11a touches `BoardNarrativeWriter` only to re-point it at the gateway. |
| Item 3 | Core integrations — workflow engine swap, risk/issue register sync, document store driver, notification service, WhatsApp/SMS, **licensing feature flags and per-tier AI entitlements**. The entitlement layer is where a currency legitimately appears; it is not here. |
| Item 4 | ThirdLine audit-universe integration. |
| Item 5 | Analyst-assisted assessment, the analyst role, the queue, **the per-unit billing record**. |
| Item 6 | NFR hardening — p95 targets, query-count assertions, the full cross-tenant probe suite, document encryption and signed URLs, retention jobs, the WCAG audit, correlation IDs. 11a adds one cross-tenant assertion (§8.13) and one prune command, and neither is a down-payment on item 6. |
| Item 7 | Supervisory-period audit trail export. |

**Out — within 11a's own subject, deliberately:**

- **`app/Services/Ai/` is not created and no file moves into it.** ADR 0015 §1.
- **No new AI capability.** The four unimplemented services stay unimplemented; the screen says
  so rather than offering a switch that does nothing.
- **No best-of-N sampling, self-consistency voting or ensembling** to address 3-of-5 exception
  recall. Each triples a 26–69-second call. `attempts` and `outcome` on every row make the
  variance measurable first; re-argue in Phase 12 with the golden-file fixtures in hand.
- **No shared `ai_settings` table** and no BCMS migration off `bcms_settings.ai_enabled`.
- **No cost, currency or billing figure anywhere**, including PDFs and exports.
- **No `llm_usage_months` rollup table.** The composite index is the answer until something is
  measured that it is not.
- **No change to `tp_document_extractions`.**
- **No column on `tp_contracts`** — no `_meta`, no `analysis_meta`, no `truncated_chars` /
  `source_chars` pair. ADR 0015 §6e. `analysed_partial` is a new *value* in an existing free-form
  `string(20)`, which is not a structural change.
- **No ledger event for the extraction or board-pack paths.** They persist the numbers in the
  payload the run produced, which is §6e destination (a). A second record of the same fact is a
  record that can disagree with itself.
- **`renderKey()` is not deleted and `LlmClient::run()`'s signature does not change.** Making the
  discard unrepresentable — `run()` taking a `RenderedPrompt` value object rather than a
  `string $prompt` — is the better fix and it is **Phase 12**, argued alongside chunking. It
  changes the one door every AI call in the module passes through, at a fifth Gate 2, for a defect
  AC 26's guard already holds closed. ADR 0015 §6e.
- **No number is added to the gap-report PDF or to `Contracts/Show.jsx`.** The caveat stays
  qualitative pending `compliance-analyst`'s ruling on whether a contractual-provision gap report
  must state the *extent* of a partial read (ADR 0015 §6e). The answer costs one indexed read of
  `tp_audit_logs` either way, and no migration — which is why the referral does not block this
  gate.
- **No percentage, ratio or "N % of the document" anywhere.** §7.5 rule 3, and a second reason for
  contracts specifically: a contract's opening characters are parties, recitals and definitions, so
  a character ratio reads as a clause coverage it is not.
- **No streaming, token-level UI, embeddings, vector store or RAG.** Prism is not installed and
  is never coming (ADR 0010); MeiliSearch is not installed either.
- **No second model, no model selection by a tenant.** A tenant selects an endpoint profile; the
  profile names the model.
- **No prompt editing in the UI.** Prompts stay versioned in config. A tenant-editable prompt has
  no version to record and `tp_document_extractions.prompt_version` silently becomes a lie.
- **No auto-apply of any extraction.** Unchanged: extractions are `pending` and a human confirms.
- **No raise of `num_ctx` above 4,096.** 11a declares the window the probe already ran under. Any
  larger value is taken on a measurement — extraction quality against a real SOC 2, plus resident
  memory on the Ollama box — owned by `reliability-engineer` with `qa-engineer` on the quality
  half. ADR 0015 §6d. The config edit that follows is one line; the number in front of it is the
  work.
- **No chunking, sliding window or map-reduce across several calls** to get a whole SOC 2 in. Phase
  12, argued with the golden-file fixtures and the window probe in hand.
- **No change to `budgets.extraction.max_tokens`.** Trading answer budget for input budget is a
  probe variable, not a config edit, and turning two knobs against one measurement is how the
  26-69-second timeout defect happened the first time.
- **No `num_ctx` or context column on `llm_usage_events`, and no per-profile context ceiling.**
  ADR 0015 §6d. The phase's structural-change count stays at **0**.
- **ERM's three grandfathered `LlmService` callers are not moved.** ADR 0015 §1 amendment; Phase 11
  item 2, and a precondition of that item's gate.
