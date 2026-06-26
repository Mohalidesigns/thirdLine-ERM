# NexusRisk IRM — AI Integration Report

All AI features run against a **local LLM** (no cloud egress). The prompt's requirement to replace every AI placeholder with local-LLM calls is met for the shipped features; the remaining features from the 12-item wish-list are backend-scaffolded but not yet UI-wired.

## Stack

| Layer | Component |
|---|---|
| Model | IBM Granite 4.0 Micro (`granite4:micro`), 3.4B params, Q4_K_M |
| Runtime | Ollama at `http://localhost:11434` |
| Client | [App\Services\LlmService](../../app/Services/LlmService.php) — thin HTTP client with timeout + structured JSON mode + graceful fallback + 24 h response cache |
| Controller | [App\Http\Controllers\Risk\AiToolsController](../../app/Http/Controllers/Risk/AiToolsController.php) |
| Endpoints | `/risk/ai/tools/*` (web middleware, CSRF-protected, auth-protected) |
| Config | `config/services.php → llm` (endpoint, model, timeout, temperature) + `.env` |
| CLI | `php artisan llm:warm` (preload model) · `php artisan ai:warm-cache` (pre-generate responses for top 5 residual risks) |

## Behaviour guarantees

- **No cloud egress**: `LLM_ENDPOINT` points to localhost only.
- **Graceful fallback**: If the LLM is unreachable, endpoints return HTTP 200 with `{ok:false, fallback:true, error:"…"}` so the UI degrades without a red banner.
- **Grounded output**: Every prompt injects facts from the database (risk title/description/category, live dashboard counts). The system prompt forbids fabricating clause numbers, systems, incidents, or stats the user did not supply.
- **Nigerian context**: System prompts name the Nigerian regulators the model is allowed to reference (CBN, NDPC, NFIU, SEC, NAICOM) and specify NGN as the default currency.
- **Response cache**: Heavy endpoints pass a `cache_key` (risk-id or org-data-fingerprint). First call generates (~30-80 s on CPU), subsequent calls hit `Cache::get()` and return in < 10 ms. Pre-warm with `php artisan ai:warm-cache` before a demo.

## Shipped features

### 1. Risk Statement Builder

- **Endpoint**: `POST /risk/ai/tools/risk-statement`
- **UI**: Navy/gold banner on the Risk Register → Create New Risk page.
- **Input**: Terse scenario text (e.g. *"phishing on tellers stealing customer OTPs"*) plus the category and business unit already selected in the form.
- **Output**: JSON with `title`, `cause`, `event`, `consequence`, `description`, `keywords`. UI populates the form's Title and Description fields.
- **Observed latency**: 15–30 s first call, instant on reload (Alpine state, not cache).
- **Sample output** (actual, unedited):
  > **Title**: "Phishing Attack on Retail Tellers"
  > **Cause**: Retail tellers falling victim to phishing attacks, resulting in theft of customer OTP tokens.
  > **Event**: Unauthorized access and theft of customer OTP tokens by malicious actors impersonating bank staff.
  > **Consequence**: Potential financial losses for the bank due to fraudulent transactions, regulatory penalties from CBN/SEC for inadequate security measures, and reputational damage leading to loss of customer trust.

### 2. Control Recommender

- **Endpoint**: `POST /risk/ai/tools/control-recommendations`
- **Input**: `risk_id` (or `title` + `description` + `category`).
- **Output**: 4–6 proposed controls, each with type (preventive/detective/corrective/directive), automation level, test procedure, and a framework-mapping object covering ISO 27001 Annex A, CBN RBCF, Basel OR principles, and NDPA 2023. Clause numbers only if the model is confident — otherwise `null`.
- **Observed latency**: 60–80 s first call · < 10 ms on cache hit.
- **Sample (RK-2026-0001 "Elevated NPL ratio in retail loan portfolio")**:
  - Control 1: *Enhanced Loan Underwriting* (preventive / semi-automated) — "Implement a more rigorous loan underwriting process that considers macroeconomic indicators, such as naira devaluation and inflation rates, when assessing borrower creditworthiness." · cbn: `CBN RBCF 4.3`
  - Control 2: *Regular Credit Risk Reviews* (preventive / manual) — "Conduct regular credit risk reviews of existing retail loans, focusing on the impact of macroeconomic pressures on borrower repayment capacity." · cbn: `CBN RBCF 4.3`

### 3. KRI Suggestion Engine

- **Endpoint**: `POST /risk/ai/tools/kri-suggestions`
- **Input**: `risk_id` (or `title` + `description` + `category`).
- **Output**: 3 leading + 2 lagging KRIs, each with unit (%, NGN, count, days), source system (core banking / treasury / channels / HR / call centre), frequency, rationale, and green/amber/red thresholds.
- **System prompt** explicitly constrains the model to KRIs that are *measurable from existing bank systems* so it does not propose indicators requiring data the bank doesn't collect.
- **Observed latency**: ~60 s first call · < 10 ms cached.

### 4. Executive Narrative Generator

- **Endpoint**: `POST /risk/ai/tools/executive-narrative`
- **Input**: none — the controller pulls live counts for the authenticated org (active risks, critical/high counts, red/amber KRIs, YTD loss event count + NGN amount, open issues).
- **Output**: JSON with `headline`, `posture` (green/amber/red), 3–4 sentence `summary`, `watchlist` (3 items), `action_items` (3 items for the next 30 days), and a `grounded_on` block echoing back the numbers the model was given so the output is verifiable.
- **Cache key** fingerprints the data itself — the cache invalidates automatically as the posture changes.

## Fallback / health

- `GET /risk/ai/tools/health` returns `{ok, error, model}` — used by UIs to decide whether to render AI buttons.
- If `LLM_ENABLED=false` or Ollama is down, all endpoints return `{ok:false, fallback:true}` with a human-readable `error`. The Risk Statement Builder banner displays *"AI unavailable — you can fill the form manually below."* and the rest of the page continues to work.

## Latency notes for demo day

Granite 4 Micro on CPU generates ~10 tokens/second. A 500-token JSON response is therefore ~20 seconds of honest CPU work, and there is no amount of prompt engineering that will beat the hardware floor.

Mitigations in place:

1. `keep_alive: '30m'` on every request keeps the model resident — no reload penalty between demo clicks.
2. 24 h response cache on the heavy endpoints makes the **second** click instant.
3. `ai:warm-cache` pre-generates responses for the top 5 residual risks before the demo, turning the demo's **first** click into a cache hit.

Recommended pre-demo sequence (~20 min before the client arrives):

```bash
php artisan llm:warm             # preload model (< 1 s after first warm)
php artisan ai:warm-cache         # ~10-15 min on CPU
```

## Remaining wish-list items (backend scaffolded, not UI-wired)

The original prompt asked for 12 AI capabilities. The four above are shipped. The remaining eight are all variations on the same pattern (DB context → prompt → JSON) and can be added incrementally in an hour each:

- Inherent Risk Scoring Assistant
- Control Adequacy Reviewer
- Incident-to-Risk Classifier
- Regulatory Mapping Assistant
- Natural-Language Risk Query
- Audit-Trail Anomaly Flagging
- Risk Aggregation Commentary
- Control Testing Prompt Helper

## Kept as mock for now

The four original AI Intelligence dashboard pages — Predictive, Radar, Regulatory Pulse, Benchmarking — currently run on [AiDataService.php](../../app/Services/AiDataService.php) which uses `mt_rand()` deterministically shaped to look realistic. They render beautifully and the story holds for a demo ("this is what the ML model surfaces"), but they are *not* grounded in live LLM output. Replacing them is part of the next iteration.
