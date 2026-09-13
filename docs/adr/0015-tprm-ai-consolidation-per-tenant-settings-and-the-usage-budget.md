# ADR 0015 — TPRM AI consolidation: the seam is a gateway, not a directory; the cap is tokens, not money

**Status:** Accepted · **Date:** 2026-09-11 · **Phase:** TPRM Phase 11a · **Author:** architect
**Requested by:** architect (lead, Phase 11) · **Broadcast to:** TPRM track, BCMS track (Phase 7
in flight), platform track
**Amended:** 2026-09-12 — **§6d** (deviation 7: the context window is declared, the cap is derived
from it, and the claim is verified against `prompt_eval_count`), **§1 amendment** (deviation 8: the
three grandfathered ERM callers, named, costed and bound to Phase 11 item 2's gate), **§10**
(`llm_usage_events` is personal data and gets a register entry). All three escalated by gate 1 and
gate 2 on the 11a pass. **Re-broadcast to:** TPRM track, platform track, compliance.
**Amended again:** 2026-09-12 — **§6d deviation 9** (`num_ctx`'s home moves from the budget to
`config/llm.php`, because the budget array is spread into a direct `LlmService` call by a
grandfathered ERM caller), and `soc2`'s cap corrected **3,500 → 3,700**, an arithmetic slip in the
first draft. **Re-broadcast to:** TPRM track, platform track.
**Amended again:** 2026-09-12 — **§6e deviation 10** (the truncation fact gets a *named durable
destination per prompt*; `tp_contracts` gets **no** `_meta` column, and `clause_analysis`'s numbers
go to `tp_audit_logs` instead). Escalated by `backend-engineer` from the fifth Gate 2 pass on 11a,
which is where it belonged. The freeze still holds at **0** structural changes for this phase.
**Re-broadcast to:** TPRM track, platform track, compliance (one referral, §6e, non-blocking).

## Context

Phase 11 item 1 says, in full:

> Consolidate AI under `Services/Ai/` with a single `PromptRegistry` (versioned prompts in config,
> never inline), one `LlmClient` with retry, timeout, circuit-breaker and per-tenant endpoint
> configuration, `ExtractionGuard` (untrusted-input handling and instruction stripping), and
> `CitationVerifier`. Add a **per-tenant AI settings screen**: enable/disable each service
> individually, endpoint selection, monthly spend cap, and a usage-and-cost report by service.

Every class it names already exists and is tested. What does not exist is any of the governance
the sentence is actually asking for.

**What is on the ground (verified 2026-09-11).**

| Piece | Where | State |
|---|---|---|
| Platform driver | `App\Services\LlmService` | Ollama, `granite4:micro`, timeout **20s**, reads `config/services.php` → `llm.*` |
| TPRM's client | `App\Services\Tprm\Extraction\LlmClient` | two config switches, availability probe, per-call `Log::info`, `run()` is "the one door" |
| BCMS's client | `App\Services\Bcms\Ai\BcmsLlmClient` | **three** switches, the third being `bcms_settings.ai_enabled` — a real per-tenant column |
| Prompts | `App\Services\Tprm\Extraction\PromptRegistry` + `config/tprm_prompts.php` | 10 versioned prompts, typed against `App\Enums\Tprm\DocumentExtractor` |
| Guard, validator, citations | `Tprm\Evidence\ExtractionGuard`, `Tprm\Extraction\SchemaValidator`, `Tprm\Evidence\CitationVerifier` | working |
| Per-tenant AI config | — | **none in TPRM.** BCMS has one boolean. |
| Retry (transport) | — | none. `ExtractionDispatcher::MAX_ATTEMPTS = 2` is *schema* retry, a different thing |
| Circuit breaker | — | none |
| Usage persistence | — | **none.** Tokens land in `tp_document_extractions.extracted._meta` (JSON) and in the log |
| Spend cap | `config('tprm.ai.monthly_spend_cap_minor')` | declared, **read by nothing**, named after a currency this deployment does not spend |

**And there is a probe.** Five runs of `granite4:micro` against a SOC 2 on 2026-09-09: period
dates, auditor, opinion and CUECs correct every time; a carve-out subservice arrangement called
`inclusive` in **4 of 5**; all three Section 4 exceptions found in **3 of 5**; successful runs
took **26–69 seconds** against a shipped **20-second** timeout. The shipped configuration cannot
complete a single extraction. That is not a tuning problem, it is the central design input: this
module's model is sometimes wrong and always slow, and everything below follows from designing
for that rather than around it.

Four of the seven service switches in `config/tprm.php` → `ai.services.*` —
`subprocessor_discovery`, `response_quality`, `adverse_media_triage`, `scoping_assistant` — have
**no implementation anywhere in `app/`**. They are switches for features that do not exist.

## Decision

### 1. The classes do not move. The seam goes *below* them, not above them.

| | |
|---|---|
| **Decision** | No file moves to `app/Services/Ai/`. That directory is not created. A new **platform-level** namespace `App\Services\Llm\` is created to hold the governance the prompt asks for — retry, timeout, circuit breaker, per-tenant endpoint, usage recording — and **both** module clients are re-pointed at it. |
| **Deviation from the prompt** | Stated here, deliberately, per ADR 0007's rule that the repository wins but the disagreement gets written down. |

The argument. `PromptRegistry::for()` takes an `App\Enums\Tprm\DocumentExtractor` and reads
`config/tprm_prompts.php`. `ExtractionGuard` returns a `Tprm\Evidence\SanitisedDocument`.
`CitationVerifier` returns a `Tprm\Evidence\CitationVerification` and exists to check quoted
strings against *vendor document* text. Hoisting those three into a shared `Services/Ai/` would
produce a "shared" namespace that imports one module's enums and value objects — which is not a
shared namespace, it is TPRM with a misleading path, and the next module to arrive would either
depend on TPRM's enum or add a second registry beside it. The directory name would be the only
thing that had consolidated.

Meanwhile the duplication that is real sits one level down: `LlmClient` and `BcmsLlmClient`
each re-implement enablement, availability, a refusal string and a `Log::info`, against the same
driver, and **neither has retry, a timeout override, a breaker, an endpoint choice or a usage
row**. Consolidating there fixes both modules at once and touches no module's public surface.

Cost of the literal reading, priced: nine files moved, roughly thirty import sites rewritten, a
full suite rerun, and a diff in which the one behavioural change of the phase is invisible among
the renames. Benefit: a directory name that matches a sentence in a document written before BCMS
existed. Rejected.

**What the prompt actually wanted, and gets.** One governed door to a model. It is enforced by a
guard test, not by a directory: **no class outside `App\Services\Llm\` may reference
`App\Services\LlmService`, except the two module clients.** That is a stronger guarantee than
co-location, and it is checkable.

**Amendment — there are three doors, not one, and the third is named here (ruled 2026-09-12,
deviation 8).** The sentence above is true of TPRM and BCMS and was never true of the deployment.
`LlmGatewayGuardTest::ALLOWED_LLM_SERVICE_CALLERS` carries **five** entries: the two module clients,
plus `app/Http/Controllers/Risk/AiToolsController.php`, `app/Console/Commands/WarmAiCache.php` and
`app/Console/Commands/WarmLlm.php`. These are pre-existing **ERM** surface. Both gates judged the
deferral correct — contract §9 items 2 and 3 put ERM's AI surface outside 11a — and the deferral is
**upheld**. What is not upheld is grandfathering recorded only in a code comment: an exception
documented where only the person already editing the file will read it is an exception that becomes
permanent by default.

| | |
|---|---|
| **The guarantee, restated exactly** | No class outside `App\Services\Llm\` may reference `App\Services\LlmService` **except the five files named in `ALLOWED_LLM_SERVICE_CALLERS`**: the two module clients, and the three ERM callers listed above. TPRM and BCMS reach a model through the gateway and through nothing else; **ERM does not, and 11a does not claim it does.** |
| **What the exception costs** | Those three calls write no `llm_usage_events` row. So a tenant's usage report and its monthly cap **count TPRM and BCMS only**, and an ERM-heavy month will under-report. AC-16's kill switch also does not cover them: `config('services.llm.enabled')` still stops them, but no tenant switch and no cap does. Stated here so the usage screen's numbers are read for what they are. |
| **When it ends** | **Phase 11 item 2** (ERM AI consolidation — `AssessmentScopingAssistant`, `NarrativeGenerator`). Moving those three onto the gateway is a **precondition of that item's gate**, not an aspiration: item 2 does not pass gate 2 with the allowlist still at five. |
| **Count assertion** | **Required.** `assertCount(5, self::ALLOWED_LLM_SERVICE_CALLERS)`, with a failure message naming this section. The comment *"this list must shrink, not grow"* enforced nothing; a sixth entry is currently a one-line edit nobody has to justify. The assertion fails on shrinkage too, and that is deliberate — removing an entry is the edit that should also update this ADR, and the failing count is what makes that happen in the same commit. |

Rejected: leaving the count unasserted because "the reviewer will notice". Eleven fabricated figures
got past reviewers before `NoFabricatedNumbersTest` existed; the argument for a blunt mechanical
guard is the same one, and it is the argument this ADR already accepted in §6c.

### 2. Per-tenant configuration lives on the tables that already exist

| | |
|---|---|
| **Decision** | TPRM's per-tenant AI settings are **columns added to `tp_settings`** (one row per organisation, unique `organization_id`, already gated by `tprm.admin`, already has a controller). BCMS keeps `bcms_settings.ai_enabled`. **No shared `ai_settings` table.** |
| **Schema change** | 5 columns on one existing table + 1 new table (§5). Full column list in the contract document. |

Rejected: a cross-module `ai_settings` table. It would require BCMS to migrate off a column it
already ships and reads, mid-Phase-7, for a screen only TPRM is building this phase. The gateway
takes resolved settings **as an argument** and reads no settings table itself, so the two modules
can disagree about where they keep their policy without the gateway caring. If a third consumer
appears, revisit; two is not a pattern.

**The mechanism by which resolved settings arrive (ruled 2026-09-11, deviation 1).** "Takes
resolved settings as an argument" is satisfied by an interface the *module* implements, not by an
array the caller assembles: `App\Services\Llm\Contracts\ModuleAiPolicy`, one implementation per
module in that module's own namespace (`Tprm\Ai\TprmAiPolicy`, `Bcms\Ai\BcmsAiPolicy`),
registered **by name** into `ModuleAiPolicyRegistry` by that module's own wiring —
`TprmServiceProvider` for TPRM, `AppServiceProvider` for BCMS, which has no provider of its own
(ADR 0007 deviation 2). Each returns a `ModulePolicySnapshot`: module switch, the two tri-states,
`UsageCaps`, endpoint-profile key. `App\Services\Llm\` names no module class anywhere, so the
guard test asserting the namespace imports no `App\Models\Tprm`, `App\Enums\Tprm`,
`App\Models\Bcms` or `App\Enums\Bcms` still holds, and `LlmGateway::availability()` can still
answer a tenant-specific question for a settings screen that has no call to make.

Rejected, and this is why the snapshot is *fetched* rather than *passed*: putting the snapshot on
`LlmCall` would move the enablement decision to the caller, which is the least trustworthy place
for it — a caller could then construct a call that its own tenant's settings forbid, and the kill
switch that AC-16 depends on would be enforced once per caller instead of once. It would also
leave `availability()` unanswerable, because the screen asking "may this tenant use this service"
is not making a call and has no snapshot to hand.

Three constraints follow and are binding: the registry is written to **only** by module wiring at
boot; an **unregistered** module resolves to "no policy, therefore nothing is enabled", never to a
permissive default; and `ModuleAiPolicyRegistry::flush()` is **test-only** — a production caller
would silently disable AI for every module at once.

**Precedence is AND at every layer, and a tenant may only ever narrow.**

```
services.llm.enabled          (deployment: is there a model at all)
  AND tprm.ai.enabled         (deployment: is the module's AI layer on)
  AND tp_settings.ai_enabled  (tenant: has this bank opted in)
  AND tprm.ai.services.<s>    (deployment: is this service on)
  AND tp_settings.ai_services[<s>]  (tenant: has this bank opted into this service)
```

**`tp_settings.ai_enabled` is a NULLABLE boolean and that is on purpose.** Three states:
`true` = the tenant said yes, `false` = the tenant said no, `null` = **nobody has asked this
tenant**, follow the deployment. An absent key in `ai_services` means the same. This differs
from `bcms_settings.ai_enabled`, which is `boolean default false`, and the difference is
justified: BCMS has no module-level deployment master switch to inherit from, TPRM has
`tprm.ai.enabled`. Two-state would force every existing tenant to `false` on migration, so
turning on a deployment switch would then do nothing, and the next engineer would "fix" that by
backfilling `true` across every tenant — which is the opposite of an opt-in.

The screen shows all three layers and the effective value, never just the effective value. A
tenant admin who switches on a service the deployment has switched off is told the switch will
not take effect. That is the module's standing rule — *"we checked and it is fine"* is a
different statement from *"nobody looked"* — applied to configuration.

### 3. Endpoint **selection**, never endpoint **entry**

| | |
|---|---|
| **Decision** | `config/llm.php` (new, **not publishable**, same reasoning as `config/tprm.php`) declares a named set of endpoint profiles. `tp_settings.ai_endpoint_profile` stores a **key into that set**. A tenant administrator picks from a list; nobody types a URL into the database. |

A URL supplied by a tenant and fetched server-side is a server-side request forgery with a
settings screen in front of it, and the fetch is authenticated by nothing but network position —
which on a bank's internal network is the whole attack. The prompt's own word is "endpoint
selection", and selection from a deployment-controlled list is both what it says and the only
safe reading. An unknown or removed profile key resolves to the default profile and the screen
says the stored choice is no longer offered; it does not fail closed silently and it does not
fetch the stored string.

### 4. What a "spend cap" means here: **there is no money, and we will not print one**

| | |
|---|---|
| **Decision** | The cap is denominated in **tokens and calls per calendar month**, not currency. `config('tprm.ai.monthly_spend_cap_minor')` is **deleted**. No screen, export or PDF in 11a renders a cost figure for a self-hosted profile. |

Ollama runs on a box the institution already owns. There is no per-call price, so any naira
figure on a usage report would be a number a developer typed — and `NoFabricatedNumbersTest`
(DEVELOPMENT_STANDARD §5) would be right to fail the build over it. A `0.00` cost column is worse
than no column: zero is a claim, and the true statement is "this endpoint is not priced".

What is real, and is therefore what we store and show:

| Quantity | Source | Null when |
|---|---|---|
| Prompt / completion tokens | Ollama's own `prompt_eval_count` / `eval_count` | the backend did not report them — **null, never 0** |
| Wall-clock milliseconds | measured by the gateway | never |
| Call count, attempt count, outcome | the gateway | never |

The scarce resource behind a single-GPU box is **the model's time**, not money — a 69-second
extraction is a minute nobody else's extraction gets. So the budget is a budget of the thing that
is actually scarce.

**The hook for a priced endpoint stays open without lying now.** An endpoint profile may declare
`unit_cost_per_1k_tokens_minor` and `currency`; every self-hosted profile declares neither.
`llm_usage_events.unit_cost_minor` and `.currency` are nullable and are populated **only** when
the profile carries a price. Where they are null the report prints
*"Not priced — self-hosted endpoint"*, not `0`. The day a tenant points at a metered API, the
column fills in and the report gains a cost line with no schema change and no retro-fitted
guesswork.

Naming follows the substance: the screen says **"Monthly usage limit"**, never "spend cap".

**Enforcement is portable SQL.** Each event row carries `usage_month` (`char(7)`, `'2026-09'`,
computed **in PHP** at insert, in `config('app.timezone')` — see the note below). The cap check is
`SELECT SUM(total_tokens), COUNT(*) … WHERE organization_id = ? AND usage_month = ?` — an
index-usable equality on a stored column. `DATE_FORMAT(created_at, '%Y-%m')` in a `WHERE` clause
would be unindexable, and a window function or CTE would pass every test and fail on MariaDB
10.4, which is the only database a customer runs (`CalendarService.php:410` records the same
reasoning for `JSON_CONTAINS`). `total_tokens` is **stored**, not summed from its two parts at
read time, so the aggregate is one column and needs no arithmetic in SQL.

**Which clock computes that month (ruled 2026-09-11, deviation 5).** `config('app.timezone')`, not
a per-tenant timezone. The platform has no tenant-timezone concept: `bcms_settings.timezone` is one
module's column, and `App\Services\Llm\` may not read a module's settings table (§2 above). The
decisive constraint is not locality but **agreement**: the writer stamps `usage_month` and the cap
check filters on it with an equality, so if the two ever used different clocks the cap would stop
binding for part of every month and nothing would say so. One clock, shared by writer and reader,
is worth more here than a boundary that is right for one tenant and wrong for the aggregate.

The residual error is bounded and visible: a call in the last minutes of a month from a tenant
whose civil clock has already turned over is counted in the outgoing month. It is off by minutes,
never by a row that goes missing, and `created_at` still records the true instant. Giving a tenant
a real timezone means a column on `organizations` — a structural migration, therefore its own
numbered ADR, and it is not Phase 11a's. If that ADR is ever written, the value reaches this
namespace the same way every other tenant fact does: through `ModulePolicySnapshot`, never by this
namespace reading a table.

### 5. One new table, platform-level, and why the existing ones will not do

| | |
|---|---|
| **Decision** | `llm_usage_events` — unprefixed, platform-owned (the precedent is `risk_audit_trail`), written by the gateway for **every** call from every module. Append-only, no `updated_at`, pruned at 24 months. |

Three cheaper options were considered and all three are wrong:

**"Query `tp_document_extractions._meta.tokens`."** It is a JSON column on MariaDB 10.4, so
reading it needs the raw JSON functions the product bans. And it exists only for extractions that
*succeeded* — the timed-out, refused, unreachable and unparseable calls, which are precisely what
a usage report is for, leave no row at all. A usage report built on it would say a broken
deployment used no resources.

**"Use the audit trail."** `tp_audit_logs` records changes to records. A model call that returned
nothing changes no record, so it is invisible to an audit trail by design.

**"Use the log."** `LlmClient::log()` already writes every field we need. A log is not queryable
per tenant per month from a screen, rotates, and is not the place a cap is enforced from.

**One morph alias is added product-wide (ruled 2026-09-11, deviation 3).**
`App\Support\MorphTypes` gains `'tprm_document' => App\Models\Tprm\Document::class`. The moment
a usage row names a document as its subject under `enforceMorphMap()`, the model needs an alias or
the write throws; `App\Models\Tprm\Document` had none. Zero schema impact, but `MorphTypes` is a
shared naming table and the string is **permanent once rows exist**, so it is recorded here rather
than in a diff: `tprm_document`, module-prefixed to match the neighbouring `tprm_third_party`, and
prefixed at all because "document" is a word three modules could each claim. No other module has a
`Document` model today; the prefix is what keeps that true if one arrives.

`organization_id` is **NOT NULL**, and the gateway **refuses a call with no tenant context**
rather than writing an unattributed row. A nullable `organization_id` would be excluded by the
`BelongsToOrganization` global scope anyway — the `QuestionnaireTemplate` trap — so the row would
exist and be unreadable, which is the worst of both.

### 6. Retry, timeout and breaker semantics — sized to the probe, not to a default

**Timeout is already settled and this ADR does not re-open it.** Between the drafting of this ADR
and its acceptance, `config/services.php` → `llm.budgets` landed: per-call `max_tokens`/`timeout`
pairs named for the *shape* of the answer, with `budgets.extraction = ['max_tokens' => 2048,
'timeout' => 120]` and the 26–69-second probe recorded in the comment. That is the right home and
the right number.

**So the two axes are split, and neither may set the other's field.** A **budget** owns
`max_tokens` and `timeout` and is chosen by what is being asked for. An **endpoint profile** owns
`endpoint`, `model`, `keep_alive` and pricing, and is chosen by which box answers. A profile
carrying its own timeout would be a second place to set one number, and the two would drift.

| Concern | Decision | Why |
|---|---|---|
| **Timeout** | From `llm.budgets.<shape>.timeout`. Profiles do not carry a timeout. | Already landed and justified. The queue job's `$timeout` must exceed it (§6a) or the worker kills the HTTP call mid-flight. |
| **`keep_alive`** | Sent on every call, `'30m'`, from the profile | `LlmService::complete()` sends it; `json()` and `jsonWithUsage()` **still do not** — so every extraction may pay cold model load, which is a large share of those 26–69 seconds. Third defect found, in scope, and the cheapest latency win available. |
| **Transport retry** | Max **2 attempts**, jittered backoff 1 s then 3 s (jitter computed by `App\Services\Llm\RetryBackoff`, **not inline in the gateway** — §6c). Retries connection failure, timeout and HTTP 5xx **only**. | A 4xx will not succeed on a second try. A 200 carrying unparseable JSON is not a transport failure. |
| **Schema retry** | Stays where it is: `ExtractionDispatcher::MAX_ATTEMPTS = 2`, unchanged | Different failure, different layer. **They must not multiply**: 2 × 2 × 69 s is four and a half minutes on a dead box. The dispatcher's second attempt is **skipped when the breaker is open**, which is what keeps the product of the two bounded. |
| **Circuit breaker** | Per **endpoint profile** — not per tenant, not per service. Cache-backed. Closed → **open after 3 consecutive transport failures** → open for **60 s** → half-open admits **one** probe; success closes, failure re-opens for another 60 s. | The thing that fails is the box. Keying by tenant would make ten tenants discover the same dead box ten times; keying by service would make the same box fail seven times. |
| **Breaker counts** | Timeouts and connection failures count as failures. **An unparseable 200 does not.** | The box is up. The model being a model is not an outage, and breaking on it would disable AI for every tenant because one vendor's PDF confused `granite4:micro`. |
| **Breaker open** | Returns an `unavailable` outcome with a printable reason. **Never throws.** Writes a `circuit_open` usage row. | Same rule both existing clients already hold: a disabled or unreachable optional service is not an error condition. The row is written so the report can distinguish "we refused to call" from "nobody asked". |
| **Order of checks** | Config and tenant switches → cap → breaker → endpoint probe → call | A deployment with AI off never makes a network call to discover it is off, and a service that is switched off never opens a circuit. |

**Deployment note, load-bearing:** the breaker's state lives in the shared cache store. On
`CACHE_STORE=array` the breaker is per-process and does nothing. `config/llm.php` names the store
explicitly and the settings screen reports whether the resolved store is shared.

### 6a. Extraction moves onto the queue, because otherwise §6 is undeliverable

| | |
|---|---|
| **Decision** | `ExtractionDispatcher` stops running inline in the HTTP request. `DocumentController` dispatches `App\Jobs\RunTprmDocumentExtraction` (flat in `app/Jobs/` — TPRM has no `app/Jobs/Tprm`) and the upload screen polls with `hooks/useJobProgress`. `ExtractionDispatcher`'s own behaviour is unchanged. |

This is not scope creep; it is the precondition for the phase's own named requirements. Today the
dispatcher is called synchronously from `DocumentController` and the user waits. Add retry with
backoff on top of that and a single upload becomes 1 s + 120 s + 3 s + 120 s before the schema
retry has even begun — a guaranteed proxy timeout presented to the user as a broken page. A
circuit breaker whose purpose is to stop people waiting on a dead box cannot do its job when the
person is already waiting sixty-nine seconds. **The retry and breaker semantics in §6 are simply
not implementable on a synchronous path**, so either they are dropped or the path changes, and
the path is the cheaper of the two.

`useJobProgress` is the standard's named primitive for a long job (DEVELOPMENT_STANDARD §8), so
this reuses a pattern rather than inventing polling.

### 6b. Document text is capped, and truncation is declared

| | |
|---|---|
| **Decision** | `config/tprm_prompts.php` gains `max_document_chars` per prompt. Text over the cap is truncated at a paragraph boundary and `_meta.document_truncated` records the fact, the cap and the original length. The confirmation screen prints it. |

Today the document text is sent **uncapped**. A 90-page SOC 2 exceeds the model's context window
long before it exceeds any timeout, and the model's response to an overflowing context is not an
error — it is a confident extraction of whichever part survived. That is the single worst failure
mode available to this pipeline: a clean-looking extraction from a report the model only read a
third of, indistinguishable on screen from one it read in full.

Silent truncation would be the same defect with a cap on it. The flag is what makes it
"we checked and it is fine" rather than "nobody looked", and it is why this is one line of config
plus one `_meta` key rather than a guess at the right number.

### 6c. The jitter is legitimate; its home is not (ruled 2026-09-11, deviation 6)

| | |
|---|---|
| **Decision** | Jittered backoff stays — it is why the retry exists rather than a second synchronised stampede at a box that has just come back. But the RNG call **moves out of `LlmGateway`** into a one-purpose `App\Services\Llm\RetryBackoff`, and it is *that* file, never the gateway, that `NoFabricatedNumbersTest::RNG_ALLOWLIST` and `scripts/check-no-rng.sh` name. The allowlist count stays at **3**. |

Network jitter is a genuine third admissible shape and the prose written for it is to the standard
of the other two: the number is slept on, never shown, stored or reasoned about. That part is
accepted as argued.

What is not accepted is the **scope of the entry**, because the allowlist is per *file*.
`LlmGateway` is the file that computes `total_tokens` from two nullable halves, carries
`duration_ms`, and runs `costFor()` — every figure the usage report prints is produced in that
file. Allowlisting it for RNG puts the product's only fabricated-number guard to sleep over
precisely the file most able to fabricate one: the day someone decides to estimate token counts
when the backend does not report them, or to spread an unpriced profile's cost, the guard that
found eleven fabricated user-facing figures says nothing. The guard's value is that it is blunt;
an exemption must be as narrow as the thing exempted, and the thing exempted is two lines of sleep
arithmetic. `PortalAuthService` is the precedent for the granularity, not a counter-example — it
does one job and holds no figure.

Rejected alternatives. **A seeded generator** makes it worse, not better: every queue worker seeded
the same way jitters identically, which is the lockstep the jitter exists to break, and nobody
will ever re-derive a past sleep from a stored seed. **Dropping the jitter** re-synchronises a
herd of retries onto a box that has just recovered — the failure the backoff is for. **Moving the
sleep to the queue** is a third retry layer over §6's two, which §6 forbids for the multiplication.

### 6d. The cap was not the binding constraint (ruled 2026-09-12, deviation 7)

| | |
|---|---|
| **Decision** | The context window is **declared, not assumed** — `config/llm.php` gains `context.num_ctx`, the gateway reads it and the driver sends it (the home was corrected from the budget array on 2026-09-12; deviation 9 below). `max_document_chars` is **derived from that window** rather than chosen, which puts our cap back underneath the server's. And the claim is **verified after the call** against Ollama's own `prompt_eval_count`, because a character cap can never guarantee a token count. All three. Not one of the three. |
| **Schema impact** | **None.** `_meta` is an existing JSON key on an untouched table and `llm_usage_events` gains no column. The freeze holds at 0 for this phase. |

§6b was written on the assumption that our cap is the first thing a document meets. It is not.
Ollama's default context window is **4,096 tokens**; `App\Services\LlmService` has never sent
`num_ctx`, only `num_predict`; and `soc2`'s `max_document_chars` is **60,000 characters**, which is
something like fifteen times that window. The server therefore truncates, silently, long before we
do. A null `document_truncated` has meant *"our cap did not cut it"* and has been read as *"the
model read the document"*. The comment at `ExtractionDispatcher.php:249` says the second thing in
as many words — **"Null `document_truncated` means the document was sent whole"** — and it is false
for exactly the document class §6b was written for. A 90-page SOC 2 is the case the flag exists to
make visible. It is also the case where the flag is null.

This is §6b's own defect one layer down, and it fails the module's standing rule stated in the
module's own words: *"we checked and it is fine"* had become *"nobody looked"*, printed as the
first.

**Why none of the three candidate fixes is sufficient alone.**

| Alone | Why not |
|---|---|
| **Send `num_ctx` sized to the existing cap** | It replaces a measured configuration with an unmeasured one. The only quality evidence this module has is the 2026-09-09 probe, and that probe ran at **2,272 prompt tokens inside the 4,096 default** — which is why it worked. Sizing the window to hold 60,000 characters is roughly a 15k-token context on a self-hosted 2.1 GB model nobody here has run at that size, for KV-cache memory nobody has measured. It would buy a true guarantee about a configuration we cannot vouch for. |
| **Lower the cap to "the real window"** | Right as far as it goes, but *the real window* is a default on a server we do not configure. Ollama's default has moved before and `OLLAMA_CONTEXT_LENGTH` lets whoever installed the box move it again, in either direction. A guarantee that rests on an unstated default is not a guarantee; it is the same assumption with a smaller number in it. |
| **Reword the guarantee** | Refused on §6b's own stated purpose. §6b exists to make *"the model only read a third of it"* **visible**. Wording that claims only what is measured stops us saying something false — which is a floor, not an achievement — and leaves the confirmation screen permanently unable to say anything affirmative about completeness at all. That is the thing being asked for. |

**What each of the three buys, and why the set is closed.**

**1. `num_ctx` is sent by the gateway, from `config/llm.php`, and 11a sets it to 4,096.**
**4,096 is the value the probe already ran under**, so on a box running Ollama's default this is
behaviour-neutral by construction: it writes down what is already happening. That is what makes it
shippable in 11a without a new probe, and it is what turns the window from a default into a number
this repository states — the only form in which a cap can be derived from it. The endpoint profile
does **not** gain a context field: one profile exists, and two consumers is not a pattern (§2).

**Its home is `config/llm.php`, not the budget (amended 2026-09-12, deviation 9).** The first
implementation put `num_ctx` on `services.llm.budgets.extraction` **and** on
`services.llm.budgets.narrative`, reasoning that the budget owns `max_tokens` and `timeout` so it
should own the window too. That is a good argument about *shape* and it is defeated by a fact about
*callers*: `Risk\AiToolsController::narrative()` does `...self::budget('narrative')`, spreading the
whole budget array into a direct `LlmService` call. So the key leaked to ERM — one of §1's three
grandfathered callers — and pinned its executive-narrative tool to 4,096 on any deployment that had
raised `OLLAMA_CONTEXT_LENGTH`.

That is a **silent capability reduction in a module this phase explicitly scoped out**, and it is
invisible by construction: ERM writes no usage row and has no `fitted` signal, so nothing would ever
report it. The config comment defended it with *"because 4,096 is the value Ollama already applies,
nothing changes"* — which is the reasoning this very section **rejected** when it refused lowering
the cap alone. A statement about someone else's default is not a statement about our behaviour, and
it is no more true one layer up than it was one layer down.

The principle that settles it, and it generalises past this key:

> **Declaring the window is worth its cost only where something is derived from it.** TPRM derives a
> character cap and an affirmative completeness claim, so pinning the window is the point. ERM
> derives nothing — no cap, no `fitted`, no usage row — so for ERM a pin is pure loss.

So `num_ctx` moves **out of `config/services.php` entirely** — off `extraction` as well as off
`narrative` — and into `config/llm.php`, which is the **gateway's** config: not publishable, read by
`LlmGateway` already for retry and breaker settings, and read by **no** module caller. The leak is
then closed *structurally* rather than by remembering: a caller that spreads a budget array cannot
pick up a key that is not in it, and ERM's three grandfathered callers do not go through the gateway
and do not read `llm.php`. `board_narrative` — TPRM's use of the `narrative` budget — keeps its
declared window, because it *does* go through the gateway.

This also sharpens what "grandfathered" means in §1, and the uniformity is worth stating: ERM's three
callers get **no** usage row, **no** tenant cap, **no** breaker and now, explicitly, **no** declared
window. They are ungoverned in every respect rather than in most of them, which is a coherent thing
to describe and to end at Phase 11 item 2's gate.

Rejected: keeping the key on `narrative` and recording in this ADR that a deployment raising
`OLLAMA_CONTEXT_LENGTH` must revisit the literals. It buries a cross-module coupling in a document
ERM's maintainers have no reason to open, and turns a deployment tuning knob into a trap. Also
rejected: a TPRM-specific budget key to hold the window. Budgets are named for the **shape** of the
answer, never for the caller (`config/services.php`'s own rule), and `board_narrative` and ERM's
narrative are the same shape — the thing that differs is governance, which is exactly what the
gateway's own config is for.

**2. `max_document_chars` is derived, not chosen.**

```
max_document_chars = floor( (num_ctx - budget.max_tokens) x CHARS_PER_TOKEN ) - rendered_overhead
```

`rendered_overhead` is the prompt's own `system` + `instructions` + the two delimiter lines + the
vendor-data footer. `max_tokens` is **reserved, not shared**: llama.cpp will evict the earliest
prompt tokens to make room for generation, which is silent truncation arriving through a second
door.

`CHARS_PER_TOKEN = 3.5`. The one measured point implies about **4.4** — the probe's 2,272 prompt
tokens against a rendered prompt of roughly ten thousand characters — and 3.5 is a deliberate ~20 %
margin, because a real SOC 2 is control identifiers, dates, tables and mixed case, all of which
tokenise worse than the synthetic prose the probe used. **The estimate does not have to be right.
It has to be conservative, and step 3 is what tells us when it was not.** That is the whole reason
this ADR is willing to put a chars-per-token constant in config at all.

**3. The claim is verified against the backend's own count.** Ollama returns `prompt_eval_count`,
which `jsonWithUsage()` already captures and `llm_usage_events.prompt_tokens` already stores. The
inference is exact and needs no invented margin:

| Observation | What it proves |
|---|---|
| `prompt_tokens` strictly **<** `num_ctx` | The server did not truncate. A truncated prompt fills the window. |
| `prompt_tokens` **>=** `num_ctx` | Nothing. It is indistinguishable from a prompt ten times the size that was cut down to fit. |
| `prompt_tokens` is **null** | Nothing. The backend did not report. Null, never 0 — §4. |

**What the confirmation screen may now say, and the list is closed at three states.**

| State | Condition | The screen |
|---|---|---|
| **We cut it** | `document_truncated` is not null | Says so: the cap, the original length, and that a paragraph boundary was used. Unchanged from §6b. |
| **We did not cut it, and the model's own count confirms it fitted** | `document_truncated` is null **and** `prompt_tokens` is not null **and** `prompt_tokens < num_ctx` | May make an **affirmative, quantified** statement — the two numbers, e.g. *"sent whole; the model reported 2,272 prompt tokens against a declared window of 4,096"*. It still does **not** say "read in full": we can prove what was delivered, never what was attended to. |
| **We did not cut it and cannot prove the rest** | `prompt_tokens` null, or `>= num_ctx` | Says exactly that — *"we did not truncate this document; the backend did not report a token count, so we cannot confirm the whole of it reached the model"*. Never silence, and never the affirmative. |

The middle row is the entire point of ruling this way rather than rewording. It is the only route by
which a truthful affirmative statement about completeness exists at all, and it is affirmative
because it is arithmetic on two measured numbers rather than an assumption about a default.

**The consequence, stated plainly because the bank has to see it.** At a 4,096-token window,
`soc2`'s derived cap is **3,700 characters** — about a page and a half of a 90-page report. That
number is not a regression. **Lowering the cap does not make the extraction worse; the extraction
is already exactly that bad. Lowering the cap is what makes it possible to see.** Truncation will
now fire on essentially every real SOC 2, which is the correct outcome and not banner fatigue: the
banner prints two measured figures that differ per document, and a number that changes is not
wallpaper. If a page and a half is not enough to extract a SOC 2 from — and it is not — then the
window has to be raised, and **that** is the decision this makes visible and fundable, on a probe
with a number attached, instead of leaving a 60,000 in config that never reached the model.

**Known limitation, recorded rather than papered over.** Ollama reuses a cached prompt prefix, and
in that case `prompt_eval_count` can report only the newly evaluated tokens. Step 3 is therefore
evidence and not proof on its own, and it errs towards under-reporting — which is why the
affirmative state requires step 2's conservative cap **as well as** step 3's check, and why neither
is allowed to carry the claim by itself.

### 6e. The truncation fact has a named durable destination, and the destination is per prompt (ruled 2026-09-12, deviation 10)

| | |
|---|---|
| **Decision** | `tp_contracts` gets **no `_meta` column — not in 11a and not in a later phase as currently framed.** The cap and the original length are facts about a *run*, not about a contract's current state, and this module already owns a durable, append-only, hash-chained, per-subject ledger for run facts: **`tp_audit_logs`**. `ClauseAnalyzer` writes one `clause_analysis_partial` event carrying both numbers. `tp_contracts.clause_analysis_status` keeps carrying the qualitative state, which is the right thing for a state column to carry. |
| **Schema impact** | **None.** `tp_audit_logs` already exists, already has `before`/`after` as `json`, already has `(organization_id, auditable_type, auditable_id)` indexed, and already takes non-CRUD events written by hand — `EvidenceService::logAccess()` is the precedent, and it exists for the same reason: *"an access is not a change to the evidence"*. A partial read is not a change to the contract either. The freeze holds at **0** structural changes for this phase. |

**Why the escalation was right to come here, and why the answer is still no column.** The question
was asked in its strongest form: the extraction path persists the cap and the original length
(`_meta.document_truncated`), the board-pack path persists them (`figures.narrative_meta.truncated`),
and the contract path — the one feeding an activation gate and a CBN/DORA gap report — persists
neither. Two of three paths keep the numbers; the third loses them on reload. Stated that way it
looks like an obvious asymmetry with an obvious fix.

It is an asymmetry of **table shape**, not of care. `tp_document_extractions.extracted` and
`tp_board_packs.figures` are *result* payloads: a row per run, holding what that run produced, and
`_meta` sits inside the thing it describes. `tp_contracts` is a *register* row — one row per
contract, edited by forms, read by a grid, guarded by `GUARDED_STATE`, and outliving any number of
analysis runs. Hanging a `_meta` blob off it would put a per-run fact on a per-entity row, where
the second run silently overwrites the first and nothing records that there was a first. That is
not the extraction pattern reproduced; it is a different pattern wearing its name.

The question "how partial was the read, and when" is a question about an event. The module has a
table for events, it is append-only so the answer cannot be quietly rewritten, it is hash-chained so
an answer changed around the application is detectable, and it is the table the supervisory export
(Phase 11 item 7) already reads. A JSON column on `tp_contracts` would be a weaker record of the
same fact in a place that cannot be queried on MariaDB 10.4 anyway — raw JSON functions in a
`WHERE` are forbidden module-wide, so a `_meta` column could never answer "show me every contract
analysed on a partial read" without a second, real column beside it.

**What the engineer built stands, with one addition.** `analysed_partial` as a third value of an
existing free-form `string(20)` is correct and needs no ADR of its own — it is a value, not a
column, the column has no DB enum, `analysed_partial` is 16 characters inside 20, and the two
consumers that branch on the column (`TprmContractsGrid`'s `!= 'not_started'` filter and the
`unanalysed` tile) were both checked. Refreshing `refreshBlockingGapCount()` on a partial basis is
also correct, and the argument written into the code is the one this ADR would have written: a
truncated read can only over-count gaps, never under-count them, because `persist()` verifies every
quote against the **full** extracted text rather than the truncated prompt, so a truncation-caused
false *present* is structurally impossible. An over-count sends a human to look. An absent count
sends nobody.

The addition is that the two numbers get written to the ledger rather than lost with the flash.

**The general rule, frozen here because this defect will otherwise recur at the next caller.**

> **Every path that applies `max_document_chars` must deliver `document_truncated` to a destination
> that survives the request, and the destination is named per prompt in the table below. There are
> exactly three admissible kinds of destination: (a) an existing JSON payload on the row the run
> produces; (b) an append-only `tp_audit_logs` event on the subject the run describes; (c) a refusal
> to run at all. A flash message is not a destination. A discard is never one.**

The defect being generalised is not "a caller forgot a flag". It is that **the cap is applied in one
class and declared in another**, so the declaration is a thing a caller must remember rather than a
thing the code does. `PromptRegistry::renderWithMeta()` truncates and reports; `ExtractionDispatcher`
was the only caller wired to the report; `ClauseAnalyzer` and `BoardNarrativeWriter` each rendered
through a convenience method that returned the text and dropped the fact. Three callers, one wired.
The apparatus §6b specified was built in full and connected to a third of the system it was built
for, which is the same shape as §6d one layer down and the same shape as §6b's own original defect:
*"we checked and it is fine"* printed where *"nobody looked"* was true.

**Destination map — authoritative, and a new prompt key does not ship without a row here.**

| Prompt key | Caller | Durable destination |
|---|---|---|
| `soc2`, `iso_cert`, `pci_aoc`, `pentest`, `insurance`, `financials`, `bcp_test`, `dpa` | `ExtractionDispatcher::persist()` | `tp_document_extractions.extracted._meta.document_truncated` (§6b) |
| `clause_analysis` | `ClauseAnalyzer::analyse()` | `tp_contracts.clause_analysis_status = 'analysed_partial'` (state) **and** a `clause_analysis_partial` row in `tp_audit_logs` (the two numbers) |
| `board_narrative` | `BoardNarrativeWriter::draft()` | `tp_board_packs.figures.narrative_meta.truncated` |

`DocumentExtractor::Generic` has no configured prompt and is short-circuited by
`ExtractionDispatcher` before any render, so it needs no row; `forKey()` throws for an unconfigured
key, which is the loud failure the rest of this section is asking for everywhere else.

**What enforces it.** Two tiers, and the first one ships in 11a.

1. **A guard test in `LlmGatewayGuardTest`'s shape** (AC 24). `renderKey()` discards the fact by
   construction, so the set of files allowed to call it is an **asserted-count allowlist**, exactly
   as `ALLOWED_LLM_SERVICE_CALLERS` is: today it is `app/Services/Tprm/Extraction/LlmClient.php`
   and the count is **1**. Growth fails, shrinkage fails. Beside it, the blunt half: any file that
   calls `renderWithMeta()` must also contain the string `document_truncated`. That is a grep and
   it is meant to be — it is precisely the file-level test that would have failed on the first
   commit of `ClauseAnalyzer`'s render call, and `NoFabricatedNumbersTest` is the standing proof
   that a blunt mechanical guard catches what several careful reviews did not.
2. **A runtime refusal for the silent-uncapped case** (AC 25). `renderWithMeta()` currently does
   `(int) ($prompt['max_document_chars'] ?? 0)` and `truncate()` treats `$cap <= 0` as *send the
   whole thing*. So a configured prompt that omits its cap sends uncapped and reports
   `document_truncated` as null — the exact false negative §6d spent a section on, reachable by
   omission. It must throw instead. AC 20 already fails a missing cap at config level; this makes
   the failure a property of the code rather than of the test suite, which is the standard's own
   distinction.

**What is deliberately *not* enforced yet, and where it is argued.** The structural fix is to delete
`renderKey()` and make `LlmClient::run()` take a `RenderedPrompt` value object instead of a
`string $prompt`, so that a caller physically cannot send a capped prompt without holding the
truncation fact — making the wrong thing unrepresentable rather than detectable. That is the better
answer and it is **not 11a's**: it changes the signature of the one door every AI call in the module
passes through, at a fifth Gate 2, to fix a defect the guard test already holds closed. It is
recorded as a **Phase 12** input, alongside chunking, which is the change that will force the
question anyway — once a long document is read across several calls, "how partial" becomes "which
chunks", and *that* is a per-run record with a real table behind it, not a JSON blob on a register
row. The column question is not deferred so much as re-framed: revisit it when there is a runs
table to put it in, and revisit it then with the chunking design in hand, not before.

**Where a column would become the right answer, so the next person can tell whether this still
holds.** Two triggers, either alone sufficient: (a) a screen, grid or export needs to **filter or
sort** contracts by the extent of the partial read — JSON is unqueryable here, so that demand
produces real columns and a numbered ADR, not a `_meta` blob; or (b) a third TPRM register table
acquires the same need, at which point it is a pattern rather than two consumers and the shape to
argue for is a shared per-run table. Neither is true today.

**The one thing this ADR does not decide, and does not guess at.** Whether the *qualitative* state
is sufficient on the **gap-report PDF** — the artefact that reaches a vendor and can feed a CBN or
DORA contractual-provision submission — is an evidence-sufficiency question, not a schema one. An
examiner reading "this analysis is partial" learns that the basis was incomplete but not whether
that meant 1 % or 94 % of the document. Referred to `compliance-analyst`, framed exactly:
*does a contractual-provision gap report have to state the extent of a partial automated read, or
does an unquantified caveat plus a manual-verification instruction discharge the obligation?*

Ruling this way is what makes that referral **non-blocking for the freeze**: the two numbers are in
`tp_audit_logs` from this phase onward, indexed by subject, so if the answer comes back
*"quantify it"* the PDF gains one indexed read and no migration. Had the answer been "no durable
record", the compliance answer would have been able to force a schema change into a frozen phase.

**No percentage, ever, on any of these artefacts.** §7.5 rule 3 already forbids a derived ratio as a
fabricated figure, and there is a second reason specific to contracts that is worth writing down:
characters read is not clauses covered. The first few thousand characters of a contract are parties,
recitals and definitions — the least clause-dense part of the document. A "4 % read" figure invites
a reader to infer a clause coverage from a character ratio, and the inference is not merely
imprecise, it is biased in the dangerous direction. Print the cap and the original length, both
exact, both measured. Let the reader do no arithmetic at all.

### 7. The two probe defects, fixed at the seam rather than patched

**(a) `"Security"` ≠ `"security"`.** `SchemaValidator` compares with `in_array(…, true)` and
`Soc2Extractor::normalise()` — which is where casing would be fixed — runs only *after*
validation has already passed. So a correct extraction is discarded, twice, over capitalisation.

The fix is **not** to lowercase inside `normalise()` and move it earlier: `normalise()` reshapes
into the persisted payload (drops unknown keys, computes `exception_count`, nulls
`severity_assessment`), and validating *its* output would validate a different document from the
one the model returned, producing error messages naming fields the model never sent.

The fix is a new `SchemaValidator::coerce(array $payload, array $schema): array`, called by
`ExtractionDispatcher` **before** `validate()`. For any field whose spec carries an `in` list of
lower-snake strings, an incoming scalar or list entry is trimmed, lower-cased, and internal
spaces and hyphens folded to underscores; if that unambiguously matches an allowed value it is
replaced, otherwise it is left alone and fails validation as before. One change, one place, all
eight extractors, and the ninth cannot forget it. `"Type II"`, `"Carve-Out"` and `"Security"` all
land.

**(b) The 20-second timeout.** §6.

**(c) The carve-out error is not a code defect and must not be fixed as one.** 4 of 5 runs called
a carve-out `inclusive`. No configuration change makes a 3B model read that paragraph correctly.
11a's response is to make the error *visible* rather than to pretend it is solved: the prompt is
sharpened and its version bumped `soc2.v1` → `soc2.v2` (old rows stay interpretable because
`prompt_version` is recorded per extraction), and `config/tprm_prompts.php` gains a
`low_trust_fields` list per prompt — `subservice_method` for SOC 2 — which the dispatcher copies
into `_meta.low_trust_fields` so the confirmation screen badges those fields specifically. The
extraction was already `pending` and human-confirmed; what changes is that the reviewer is told
*which field the model is known to get wrong*, instead of being asked to check all twelve equally.

### 8. Wiring

| | |
|---|---|
| `LlmGateway` binding | **Transient**, in `AppServiceProvider` (it is platform-level, not TPRM's). It carries per-call state; a singleton in a queue worker would leak one tenant's last error and endpoint into the next tenant's job. The breaker's state is in the shared cache **precisely so that a transient object works** — the same reasoning as `RuleEvaluator` in `TprmServiceProvider`. |
| `TprmSetting` policy | **None.** The screen stays on `Gate::authorize('tprm.admin')` plus route middleware, as `ProgrammeSettingsController` already does. |

The policy decision is a stated deviation from DEVELOPMENT_STANDARD §3. §3 exists for models with
per-record authorisation decisions; `tp_settings` has exactly one row per tenant, reachable only
through a `tprm.admin`-gated route, and no per-record question can be asked of it. A policy would
be a second place to state one permission, free to drift from the first. It is **not** added to
`TprmServiceProvider::POLICIES`, so the guard test that asserts that map stays exact.

### 9. BCMS moves onto the gateway in the same phase

`BcmsLlmClient::json()`'s internals are re-pointed at the gateway. Its **public API does not
change**, so `BiaAiDrafter`, `PlanAiDrafter` and `ProgrammeAdvisor` are untouched, and
`bcms_settings.ai_enabled` keeps its current meaning.

The alternative — ship the gateway with TPRM as its only consumer and migrate BCMS later — was
rejected because `llm_usage_events` would then be silently incomplete, and a usage report that is
*nearly* true is worse than one that does not exist: a cap computed from three of five callers
will not bind, and nobody will know why. BCMS Phase 7 is in flight, so the BCMS AI regression
check is marked **[verify at integration]** for the next BCMS integration window; the overlap
rule covers it and no BCMS criterion is blocked in the meantime.

### 10. `llm_usage_events` holds personal data, so it gets a register entry (ruled 2026-09-12)

Gate 2 disagreed with deferring the NDPA register entry for this table, and gate 2 is right. The
deferral was defensible while `user_id` had no writer: a table of token counts and outcomes is
telemetry. **This cycle's own fix gave it a writer.** Every model call now records a **named member
of staff** against a timestamp, a subject record, a module, a service and an endpoint, kept for 24
months. That is a behavioural log of identifiable individuals, and the fact that its purpose is
capacity management does not change what it is.

| | |
|---|---|
| **Decision** | A register entry is a **merge condition on the 11a re-gate**, not a fourth blocking defect on this pass. Verified by `code-reviewer` at gate 2; authored by `compliance-analyst`. |
| **Where it lands** | A **new file, `docs/compliance/ndpa-register-platform.md`**, with `llm_usage_events` as its §1. **`docs/compliance/ndpa-register.md` is not touched** — it is titled and scoped to BCMS, its §5 and §6 are mid-remediation under a separate gate, and a platform table does not belong in a module's register anyway. |
| **Why a merge condition and not a blocker** | The processing already happens and the **control already exists**: `PruneLlmUsageEvents` runs daily against `config('llm.retention_months')`. The defect is missing documentation, not a missing safeguard. Blocking now would force an edit to a file another gate is holding open, which is the larger risk. |

The file boundary follows the ownership boundary the schema already draws and that §5 used to name
this table: unprefixed is platform, `bcms_*` is BCMS. One product-wide register is the better end
state; the way to reach it is to let the platform file exist now and fold the module registers into
it at a moment when **no** module register is mid-remediation — not by restructuring a file two
gates are currently reading.

**What the entry must establish, and the second item is the one that matters most:**

1. Purpose, subjects (staff who trigger an AI call), categories (`user_id`, `created_at`,
   `subject_type`/`subject_id`, module, service, endpoint profile, outcome, token counts),
   lawful basis, access control (`tprm.admin` for the report), and DSAR retrieval by `user_id`.
2. **What the table does not hold: no prompt text, no model response, no document text, not one
   character of the vendor document.** That is the first question anyone asks of an "AI usage log"
   and the register should answer it before it is asked.
3. Retention: 24 months, `config('llm.retention_months')`, enforced by `PruneLlmUsageEvents` daily.
   Named, because BCMS's register records that *no* BCMS retention job exists — the contrast is the
   evidence.
4. Residency, and the forward-looking half: today every endpoint profile is self-hosted, so nothing
   crosses a border and §41 does not engage. **The day a profile with `unit_cost_per_1k_tokens_minor`
   set is added (§4's open hook), vendor document text leaves the bank** — that is an NDPA §41 and
   §29 question about a *new processor*, and the register must record it as a precondition on adding
   such a profile rather than discovering it after one is configured.

## What this ADR deliberately does not do

**It does not create `app/Services/Ai/`.** §1. The prompt's wording is overridden and the
override is recorded here, which is the whole point of ADR 0007's discipline.

**It does not add a shared `ai_settings` table**, and it does not move BCMS off
`bcms_settings.ai_enabled`. Two consumers is not a pattern.

**It does not add a cost column to any screen, export or PDF.** §4. There is no price to put in
one, and a zero would be a fabricated number in the exact sense DEVELOPMENT_STANDARD §5 forbids.

**It does not add a second rollup table** (`llm_usage_months` or similar). The composite index
`(organization_id, usage_month, service)` makes the monthly aggregate a leftmost-prefix scan over
a few thousand rows. A rollup is a cache that can disagree with its source, and nothing has been
measured that needs one. Revisit with a number, not with an intuition.

**It does not touch `tp_document_extractions`.** `_meta` keeps carrying tokens and duration to the
confirmation screen, which is a different job from reporting and has a different lifetime.

**It does not add best-of-N sampling, self-consistency voting or an ensemble** to answer the
3-of-5 exception recall. Each is a straight multiplier on a 26–69-second call, and the right
first move is to *measure* the variance — which `attempts` and `outcome` on every event row now
make possible — rather than to spend three times the model's time on a hunch. That argument
should be re-made in Phase 12 with the golden-file fixtures in hand.

**It does not build the four unimplemented services.** `subprocessor_discovery`,
`response_quality`, `adverse_media_triage` and `scoping_assistant` have no code behind them. 11a
does not add any; it makes the settings screen render them as **"Not built in this release"**
rather than as switches that silently do nothing. A toggle that changes nothing is the purest
form of "nobody looked".

**It does not add streaming, embeddings, a vector store or retrieval augmentation.** Prism is not
installed and is never coming (ADR 0010); MeiliSearch is not installed either.

**It does not raise the context window.** §6d declares 4,096 — the value the 2026-09-09 probe
already ran under — and derives the caps from it. Raising it is a real decision with a real
KV-cache cost on a 2.1 GB self-hosted model, and it is taken with a **measurement**: a probe at the
candidate window, on a real SOC 2, reporting extraction quality and resident memory, owned by
`reliability-engineer` with `qa-engineer` on the quality half. The config change that follows is one
line. The number in front of it is the work.

**It does not add a context or window column to `llm_usage_events`.** The window in force is
recorded per extraction in `tp_document_extractions.extracted._meta.context_window` — an existing
JSON key on a table this ADR does not touch. The usage report has no question that needs it, and
"a column might be useful later" is how a frozen schema stops being one. The freeze holds at **0**
structural changes for this phase.

**It does not chunk, window or map-reduce a long document across several calls.** That is the only
thing that would make a 90-page SOC 2 genuinely extractable, and it is a straight multiplier on a
26-69-second call — the same objection that rejected best-of-N above, for the same reason. It
belongs in Phase 12, argued with the golden-file fixtures and the window probe both in hand.

**It does not add a `_meta`, `analysis_meta` or any other JSON column to `tp_contracts`.** §6e.
A per-run fact does not belong on a per-entity register row, where the next run overwrites it and
nothing records that there was a previous one. The cap and the original length go to
`tp_audit_logs`, which is append-only, hash-chained, indexed by subject and already read by the
supervisory export. Revisit only on §6e's two named triggers.

**It does not delete `renderKey()` or change `LlmClient::run()`'s signature to take a rendered
prompt object.** §6e. That is the structural fix — it makes the discard unrepresentable rather than
detectable — and it changes the one door every AI call in the module passes through, at a fifth
Gate 2, for a defect the new guard test already holds closed. Phase 12, with chunking.

**It does not decide whether the gap-report PDF must quantify a partial read.** §6e. That is
evidence sufficiency, which `compliance-analyst` owns, and the ruling was made in the shape that
keeps the answer from costing a migration either way.

**It does not move ERM's three direct `LlmService` callers onto the gateway.** §1's amendment.
That is Phase 11 item 2's work and a precondition of item 2's gate.

**It does not edit `docs/compliance/ndpa-register.md`.** §10. That file is BCMS-scoped and
mid-remediation under another gate.

**It does not touch licensing, entitlements or per-seat AI units.** That is Phase 11 item 3, and
it is the one place a currency legitimately appears.

## Alternatives rejected, in one line each

| Alternative | Why not |
|---|---|
| Move the nine classes to `app/Services/Ai/` as written | Produces a shared namespace importing `App\Enums\Tprm`; thirty import rewrites; zero behaviour change; hides the real diff |
| A shared `ai_settings` table across TPRM and BCMS | Forces a BCMS migration mid-Phase-7 for a TPRM screen; two consumers is not a pattern |
| Free-text endpoint URL per tenant | SSRF with a settings screen in front of it |
| A naira monthly spend cap | No price exists; the figure would be invented, and §5 of the standard would fail the build |
| Cost column showing `0.00` for self-hosted | Zero is a claim; "not priced" is the truth |
| `DATE_FORMAT(created_at,'%Y-%m')` in the cap query | Unindexable; a CTE or window function would pass tests and fail on MariaDB 10.4 |
| Breaker keyed per tenant or per service | The failing thing is the box, so ten tenants would each discover it separately |
| Lowercase inside `normalise()` and validate its output | Validates a different payload from the one the model returned; error messages name fields nobody sent |
| Ship the gateway for TPRM only, migrate BCMS later | A cap computed from some of the callers does not bind, and the gap is invisible |
| Put a `timeout` on the endpoint profile | `llm.budgets` already owns it; two homes for one number is drift with extra steps |
| Keep extraction inline and add retry anyway | 1 s + 120 s + 3 s + 120 s inside one HTTP request is a proxy timeout, not a retry |
| Raise `budgets.extraction.timeout` above 120 s for large documents | Does not help: a 90-page report exhausts PHP-FPM and the context window first. §6a and §6b are the fixes |
| Truncate long documents silently | Produces a confident extraction of a third of a report, indistinguishable from a complete one |
| Pass a resolved settings snapshot in on `LlmCall` | Moves the kill switch to the caller, so it is enforced once per caller instead of once; and leaves `availability()` unanswerable for a screen that is making no call |
| Read a tenant timezone for `usage_month` | No such concept exists outside one module's table; and a writer and a cap check on different clocks stop agreeing, silently |
| Allowlist `LlmGateway.php` for the retry jitter | The allowlist is per file, and that file computes `total_tokens`, `duration_ms` and `unit_cost_minor` — the exemption would cover every figure the report prints |
| Seed the jitter so it is reproducible | Identically seeded workers jitter in lockstep, which is the herd the jitter exists to break; no consumer ever re-derives a past sleep |
| Send `num_ctx` sized to the 60,000-character cap | Swaps a measured configuration for an unmeasured one: ~15k tokens of context on a 2.1 GB self-hosted model nobody has run at that size, for KV memory nobody has measured |
| Lower `max_document_chars` alone and leave `num_ctx` unsent | "The real window" is then a default on a server we do not configure, and `OLLAMA_CONTEXT_LENGTH` moves it silently in either direction |
| Reword §6b's guarantee alone | Stops us stating a falsehood, which is a floor, not a fix; §6b exists to make incompleteness **visible**, and rewording leaves the screen unable to say anything affirmative for ever |
| Trust a character cap to guarantee a token count | Tables, identifiers and mixed case tokenise nothing like prose; the cap can only be conservative, and `prompt_eval_count` is what says when it was not |
| Shave `budgets.extraction.max_tokens` to buy input tokens | Two knobs, one measurement. Turning both blind trades a visible failure (unparsable JSON) for an invisible one. It is a probe variable, not a config edit |
| A `max_context_tokens` ceiling on the endpoint profile | One profile exists. Two consumers is not a pattern (§2); revisit when a second box with different memory appears |
| `num_ctx` on the budget array | `Risk\AiToolsController` spreads whole budget arrays into a direct `LlmService` call, so the key leaks to an ungoverned ERM caller and pins a window it derives nothing from |
| Keep the key on `narrative` and document the `OLLAMA_CONTEXT_LENGTH` coupling | Buries a cross-module trap in a document ERM's maintainers will not open |
| A TPRM-only budget key to hold the window | Budgets are named for the shape of the answer, never the caller; the difference here is governance, which is what the gateway's own config is for |
| Retro-justify `soc2`'s 3,500 as extra margin for a table-heavy document class | The rationale would apply to every vendor-document prompt, not one; and a number with a story invented after the fact is the failure mode the formula exists to remove |
| Put `llm_usage_events` into `docs/compliance/ndpa-register.md` | That register is BCMS-scoped and mid-remediation under another gate; a platform table does not belong in a module's register |
| Leave the allowlist count unasserted because a reviewer would notice | The same argument that `NoFabricatedNumbersTest` already answered: a blunt mechanical guard catches what eleven reviews did not |
| A `_meta` JSON column on `tp_contracts`, mirroring `tp_document_extractions` | Mirrors the name, not the pattern: that column is a per-run result payload, `tp_contracts` is a per-entity register row that outlives every run, so the second analysis silently overwrites the first |
| A `clause_analysis_truncated_chars` / `clause_analysis_source_chars` column pair | Two columns to record what an existing append-only ledger records with none, on a table under a freeze that has held 15 → 8 → 5 → 1 → 0 |
| Keep the numbers only in the flash and accept the loss on reload | The flash reaches the one person who pressed the button; the activation gate, the grid, the second reviewer and the vendor-facing PDF all read the row, and a regulatory artefact whose basis is unrecorded is the defect, not the mitigation |
| Print a percentage or "N % of the document was read" | Forbidden as a derived figure (§7.5 rule 3), and biased besides: the opening characters of a contract are parties, recitals and definitions, so a character ratio reads as a clause coverage it is not |
| Skip `refreshBlockingGapCount()` on a partial read rather than declare it | A truncated basis can only over-count (`persist()` verifies quotes against the full text, so a false *present* is structurally impossible). An over-count sends a human to look; no count sends nobody |
| Defer the whole question to Phase 12 and record nothing now | Every run between now and then loses its numbers permanently, and the compliance referral would come back to a phase with no record to quantify from |
| Fix `ClauseAnalyzer` and trust review to catch the next caller | Three callers, one wired, across two review passes. That is the evidence about review that this section exists to act on |
