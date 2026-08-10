<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Services\LlmService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live AI tools backed by the locally hosted LLM.
 *
 * Each method is a narrow, grounded capability — prompts are built from
 * on-screen input or database context, never free-form hallucination.
 * All methods return JSON suitable for AJAX calls from Blade views.
 *
 * If the LLM is unreachable, endpoints return HTTP 200 with
 * { ok: false, fallback: true, error: "..." } so the UI can degrade
 * gracefully instead of popping a browser error.
 */
class AiToolsController extends Controller
{
    public function __construct(protected LlmService $llm) {}

    /**
     * Risk Statement Builder — transforms a terse user scenario into a
     * structured Cause → Event → Consequence risk statement plus a concise
     * title and a board-ready description.
     *
     * POST /risk/ai/tools/risk-statement
     * Body: { scenario: "phishing on tellers" }
     */
    public function riskStatement(Request $request): JsonResponse
    {
        // Local LLM inference on a 3.4B model can exceed PHP's default 30s
        // execution cap. Grant extra budget for this endpoint only.
        @set_time_limit(120);

        $validated = $request->validate([
            'scenario' => 'required|string|min:3|max:2000',
            'category' => 'nullable|string|max:100',
            'business_unit' => 'nullable|string|max:100',
        ]);

        if (! $this->llm->available()) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?? 'LLM unavailable',
            ]);
        }

        $system = <<<'SYS'
You are a senior risk analyst at a Nigerian commercial bank. You write precise,
board-ready risk statements using the Cause → Event → Consequence model.
Use professional English. Reference Nigerian regulators (CBN, NDPC, NFIU, SEC,
NAICOM) where relevant. Use NGN for currency. Never invent facts about specific
systems, people, or incidents the user did not provide. Respond with JSON only.
SYS;

        $category = $validated['category'] ?? 'Not specified';
        $bu = $validated['business_unit'] ?? 'Not specified';

        $prompt = <<<PROMPT
Scenario from risk owner: "{$validated['scenario']}"
Risk category: {$category}
Business unit: {$bu}

Produce a JSON object with EXACTLY these keys:
  "title":        (string, <= 90 chars) short risk name, no trailing period
  "cause":        (string) the root or trigger condition
  "event":        (string) what could go wrong
  "consequence":  (string) the business, regulatory and customer impact
  "description":  (string) a 2-3 sentence narrative combining cause, event and
                  consequence in prose suitable for a board paper
  "keywords":     (array of 3-6 short strings) taxonomy tags

Respond with the JSON object only.
PROMPT;

        $data = $this->llm->json($prompt, $system, ['max_tokens' => 600, 'timeout' => 60]);

        $required = ['title', 'cause', 'event', 'consequence', 'description'];
        foreach ($required as $k) {
            if (! isset($data[$k]) || ! is_string($data[$k]) || trim($data[$k]) === '') {
                return response()->json([
                    'ok' => false,
                    'fallback' => true,
                    'error' => $this->llm->lastError() ?: 'Model response missing required fields.',
                    'raw' => $data,
                ]);
            }
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => [
                'title' => mb_substr(trim($data['title']), 0, 255),
                'cause' => trim($data['cause']),
                'event' => trim($data['event']),
                'consequence' => trim($data['consequence']),
                'description' => trim($data['description']),
                'keywords' => array_values(array_filter((array) ($data['keywords'] ?? []))),
            ],
        ]);
    }

    /**
     * Control Recommender — given a risk, suggest controls mapped to
     * CBN Risk-Based Cybersecurity Framework, NDPA, ISO 27001, and Basel III.
     *
     * POST /risk/ai/tools/control-recommendations
     */
    public function controlRecommendations(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $validated = $request->validate([
            'risk_id' => 'nullable|exists:risks,id',
            'title' => 'required_without:risk_id|string|max:500',
            'description' => 'required_without:risk_id|string|max:3000',
            'category' => 'nullable|string|max:100',
        ]);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $title = $validated['title'] ?? null;
        $description = $validated['description'] ?? null;
        $category = $validated['category'] ?? null;

        if (! empty($validated['risk_id'])) {
            $risk = \App\Models\Risk::with('category')
                ->where('organization_id', TenantContext::organizationId())
                ->find($validated['risk_id']);
            if ($risk) {
                $title = $title ?? $risk->title;
                $description = $description ?? $risk->description;
                $category = $category ?? optional($risk->category)->name;
            }
        }

        $system = <<<'SYS'
You are a senior operational risk and controls advisor at a Nigerian commercial
bank. When proposing controls, map each one to exactly one clause from:
  - CBN Risk-Based Cybersecurity Framework (RBCF)
  - Central Bank of Nigeria Circular on Operational Risk
  - Basel III operational risk principles
  - ISO/IEC 27001:2022 Annex A
  - NDPA 2023 (Nigeria Data Protection Act)
Use the actual clause or control reference (e.g. "ISO 27001 A.5.12", "CBN RBCF 4.3"
or "Basel OR Principle 6"). Never invent clause numbers. If you are unsure of the
exact reference, omit the mapping rather than fabricate it. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Risk title: {$title}
Risk category: {$category}
Risk description: {$description}

Propose 4 to 6 controls that would materially reduce this risk. For each control,
produce a JSON object with these fields:
  "name":        (string) short control name, <= 80 chars
  "type":        (one of: "preventive", "detective", "corrective", "directive")
  "automation":  (one of: "automated", "semi-automated", "manual")
  "description": (string) 1-2 sentences explaining how the control works
  "framework_mapping": (object with keys "iso27001","cbn","basel","ndpa" — each
                  value is either a clause string or null if not applicable)
  "test_procedure": (string) how an internal auditor would test the control

Return as JSON: { "controls": [ ... ] }.
PROMPT;

        $cacheKey = 'controls:'.md5(($validated['risk_id'] ?? '').'|'.$title.'|'.$description);
        $data = $this->llm->json($prompt, $system, [
            'max_tokens' => 1200, 'timeout' => 90, 'cache_key' => $cacheKey,
        ]);

        if (empty($data['controls']) || ! is_array($data['controls'])) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model returned no controls.',
                'raw' => $data,
            ]);
        }

        $controls = [];
        foreach ($data['controls'] as $c) {
            if (! is_array($c) || empty($c['name'])) {
                continue;
            }
            $controls[] = [
                'name' => (string) $c['name'],
                'type' => strtolower((string) ($c['type'] ?? 'preventive')),
                'automation' => strtolower((string) ($c['automation'] ?? 'manual')),
                'description' => (string) ($c['description'] ?? ''),
                'framework_mapping' => [
                    'iso27001' => $c['framework_mapping']['iso27001'] ?? null,
                    'cbn' => $c['framework_mapping']['cbn'] ?? null,
                    'basel' => $c['framework_mapping']['basel'] ?? null,
                    'ndpa' => $c['framework_mapping']['ndpa'] ?? null,
                ],
                'test_procedure' => (string) ($c['test_procedure'] ?? ''),
            ];
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => ['controls' => $controls],
        ]);
    }

    /**
     * KRI Suggestion Engine — propose leading + lagging indicators for a risk
     * with measurement frequency, unit and amber/red thresholds.
     *
     * POST /risk/ai/tools/kri-suggestions
     */
    public function kriSuggestions(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $validated = $request->validate([
            'risk_id' => 'nullable|exists:risks,id',
            'title' => 'required_without:risk_id|string|max:500',
            'description' => 'required_without:risk_id|string|max:3000',
            'category' => 'nullable|string|max:100',
        ]);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $title = $validated['title'] ?? null;
        $description = $validated['description'] ?? null;
        $category = $validated['category'] ?? null;

        if (! empty($validated['risk_id'])) {
            $risk = \App\Models\Risk::with('category')
                ->where('organization_id', TenantContext::organizationId())
                ->find($validated['risk_id']);
            if ($risk) {
                $title = $title ?? $risk->title;
                $description = $description ?? $risk->description;
                $category = $category ?? optional($risk->category)->name;
            }
        }

        $system = <<<'SYS'
You are a risk measurement specialist at a Nigerian commercial bank. You design
Key Risk Indicators that are measurable from existing bank systems (core banking,
treasury, loan origination, channels, HR, call centre). Always include realistic
units (%, ₦, count, days, ratio) and practical measurement frequencies. Never
propose indicators that require data the bank does not collect. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Risk title: {$title}
Risk category: {$category}
Risk description: {$description}

Propose 3 leading and 2 lagging KRIs for this risk. For each KRI produce:
  "name":       (string) short KRI name, <= 80 chars
  "type":       (one of: "leading", "lagging")
  "unit":       (string) e.g. "%", "NGN", "count", "days"
  "frequency":  (one of: "daily", "weekly", "monthly", "quarterly")
  "source":     (string) the source system / team that produces the data
  "rationale":  (string) 1 sentence on why this indicator is predictive
  "green_threshold": (string) acceptable range
  "amber_threshold": (string) warning range
  "red_threshold":   (string) breach trigger

Return as JSON: { "kris": [ ... ] } with exactly 5 entries.
PROMPT;

        $cacheKey = 'kris:'.md5(($validated['risk_id'] ?? '').'|'.$title.'|'.$description);
        $data = $this->llm->json($prompt, $system, [
            'max_tokens' => 1200, 'timeout' => 90, 'cache_key' => $cacheKey,
        ]);

        if (empty($data['kris']) || ! is_array($data['kris'])) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model returned no KRIs.',
                'raw' => $data,
            ]);
        }

        $kris = [];
        foreach ($data['kris'] as $k) {
            if (! is_array($k) || empty($k['name'])) {
                continue;
            }
            $kris[] = [
                'name' => (string) $k['name'],
                'type' => strtolower((string) ($k['type'] ?? 'leading')),
                'unit' => (string) ($k['unit'] ?? ''),
                'frequency' => strtolower((string) ($k['frequency'] ?? 'monthly')),
                'source' => (string) ($k['source'] ?? ''),
                'rationale' => (string) ($k['rationale'] ?? ''),
                'green_threshold' => (string) ($k['green_threshold'] ?? ''),
                'amber_threshold' => (string) ($k['amber_threshold'] ?? ''),
                'red_threshold' => (string) ($k['red_threshold'] ?? ''),
            ];
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => ['kris' => $kris],
        ]);
    }

    /**
     * Executive Narrative Generator — produces a board-ready commentary
     * from live register/KRI/loss-event data for the authenticated org.
     *
     * POST /risk/ai/tools/executive-narrative
     */
    public function executiveNarrative(Request $request): JsonResponse
    {
        @set_time_limit(120);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $orgId = TenantContext::organizationId();
        $year = now()->year;

        $totalActive = \App\Models\Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $critical = \App\Models\Risk::where('organization_id', $orgId)->where('residual_rating', 'Critical')->count();
        $high = \App\Models\Risk::where('organization_id', $orgId)->where('residual_rating', 'High')->count();
        $redKris = \App\Models\KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'red')->count();
        $amberKris = \App\Models\KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'amber')->count();
        $netLoss = (float) \App\Models\LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $year)->sum(\App\Models\LossEvent::netLossNairaSql());
        $lossCount = \App\Models\LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $year)->count();
        $openIssues = \App\Models\Issue::where('organization_id', $orgId)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count();

        $topRisks = \App\Models\Risk::where('organization_id', $orgId)
            ->whereIn('residual_rating', ['Critical', 'High'])
            ->orderByDesc('residual_score')
            ->limit(5)
            ->get(['risk_code', 'title', 'residual_rating'])
            ->map(fn ($r) => "- {$r->risk_code} [{$r->residual_rating}]: {$r->title}")
            ->implode("\n");

        $system = <<<'SYS'
You are the Chief Risk Officer's briefing writer at a Nigerian commercial bank.
You are writing a one-page narrative for the Board Risk Committee. Be factual —
every number you cite must come from the data block below. Use measured
executive language. Nigerian regulator references (CBN, NDPA, NFIU) are welcome
where they fit the facts. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Data block for this quarter:
- Active risks:               {$totalActive}
- Critical residual risks:    {$critical}
- High residual risks:        {$high}
- KRIs in breach (red):       {$redKris}
- KRIs on watch (amber):      {$amberKris}
- Loss events YTD:            {$lossCount}
- Net loss YTD (NGN):         {$netLoss}
- Open issues & findings:     {$openIssues}

Top residual-risk items:
{$topRisks}

Produce a JSON object:
  "headline":    (string, <= 140 chars) one-line headline for the Board paper
  "posture":     (one of: "green", "amber", "red")
  "summary":     (string) 3-4 sentence narrative for the Board covering overall
                  posture, notable shifts, and regulatory exposure
  "watchlist":   (array of 3 strings) the three items the CRO would put on the
                  Board watchlist for next quarter, each <= 120 chars
  "action_items":(array of 3 strings) concrete actions for the next 30 days

Return JSON only.
PROMPT;

        // Cache key keyed on *current data fingerprint* so stale caches invalidate
        // automatically when the underlying risk posture changes.
        $fingerprint = md5("$totalActive|$critical|$high|$redKris|$lossCount|$netLoss|$openIssues");
        $cacheKey = 'narrative:'.$orgId.':'.$fingerprint;
        $data = $this->llm->json($prompt, $system, [
            'max_tokens' => 900, 'timeout' => 90,
            'cache_key' => $cacheKey, 'cache_ttl' => 3600,
        ]);

        if (empty($data['summary'])) {
            return response()->json([
                'ok' => false, 'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model returned incomplete narrative.',
                'raw' => $data,
            ]);
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => [
                'headline' => (string) ($data['headline'] ?? ''),
                'posture' => strtolower((string) ($data['posture'] ?? 'amber')),
                'summary' => (string) $data['summary'],
                'watchlist' => array_slice(array_map('strval', (array) ($data['watchlist'] ?? [])), 0, 5),
                'action_items' => array_slice(array_map('strval', (array) ($data['action_items'] ?? [])), 0, 5),
                'grounded_on' => [
                    'active_risks' => $totalActive,
                    'critical' => $critical,
                    'high' => $high,
                    'red_kris' => $redKris,
                    'loss_count' => $lossCount,
                    'net_loss_ngn' => $netLoss,
                    'open_issues' => $openIssues,
                    'generated_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Control Description Builder — drafts a board-ready description of how a
     * specific control operates, given the control name and (optionally) type,
     * frequency and linked risks.
     *
     * POST /risk/ai/tools/control-description
     */
    public function controlDescription(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $validated = $request->validate([
            'name' => 'nullable|string|max:200',
            'scenario' => 'required|string|min:3|max:2000',
            'control_type' => 'nullable|string|max:50',
            'control_nature' => 'nullable|string|max:50',
            'frequency' => 'nullable|string|max:50',
        ]);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $name = $validated['name'] ?? 'Not specified';
        $type = $validated['control_type'] ?? 'Not specified';
        $nature = $validated['control_nature'] ?? 'Not specified';
        $freq = $validated['frequency'] ?? 'Not specified';

        $system = <<<'SYS'
You are a senior internal-controls advisor at a Nigerian commercial bank. You
write precise, audit-ready control descriptions. Describe how the control
operates, who performs it, when it runs, what evidence it produces, and what
risk it mitigates. Reference Nigerian regulators (CBN, NDPC, NFIU) where they
naturally fit. Never invent system names, people or vendors the user did not
provide. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Scenario / brief from control owner: "{$validated['scenario']}"
Proposed control name: {$name}
Control type:    {$type}
Control nature:  {$nature}
Control frequency: {$freq}

Produce a JSON object with EXACTLY these keys:
  "name":        (string, <= 80 chars) a concise control name (refine the proposed name if needed)
  "description": (string, 3-5 sentences) board-ready description of how the
                 control operates — actor, trigger, action, evidence and the
                 risk it addresses

Respond with the JSON object only.
PROMPT;

        $data = $this->llm->json($prompt, $system, ['max_tokens' => 600, 'timeout' => 60]);

        if (empty($data['description']) || ! is_string($data['description'])) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model response missing required fields.',
                'raw' => $data,
            ]);
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => [
                'name' => mb_substr(trim((string) ($data['name'] ?? $name)), 0, 200),
                'description' => trim($data['description']),
            ],
        ]);
    }

    /**
     * Treatment Plan Description Builder — drafts a description of plan
     * objectives, scope and expected outcomes from a short scenario plus the
     * selected treatment strategy and (optionally) the linked risk.
     *
     * POST /risk/ai/tools/treatment-description
     */
    public function treatmentDescription(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $validated = $request->validate([
            'scenario' => 'required|string|min:3|max:2000',
            'title' => 'nullable|string|max:200',
            'treatment_type' => 'nullable|string|max:50',
            'risk_id' => 'nullable|exists:risks,id',
        ]);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $title = $validated['title'] ?? 'Not specified';
        $strategy = $validated['treatment_type'] ?? 'Not specified';
        $riskTitle = 'Not specified';
        $riskDescription = 'Not specified';

        if (! empty($validated['risk_id'])) {
            $risk = \App\Models\Risk::where('organization_id', TenantContext::organizationId())
                ->find($validated['risk_id']);
            if ($risk) {
                $riskTitle = $risk->title;
                $riskDescription = (string) $risk->description;
            }
        }

        $system = <<<'SYS'
You are a risk treatment programme manager at a Nigerian commercial bank. You
draft pragmatic treatment plans for the Board Risk Committee. Each plan must
state objectives, scope, key activities and the expected outcome. Respect the
chosen treatment strategy (mitigate / transfer / accept / avoid). Reference
Nigerian regulators (CBN, NDPC, NFIU) only where they fit the facts. Never
invent vendors, systems or budgets the user did not provide. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Scenario / brief from plan owner: "{$validated['scenario']}"
Proposed plan title:   {$title}
Treatment strategy:    {$strategy}
Linked risk title:     {$riskTitle}
Linked risk statement: {$riskDescription}

Produce a JSON object with EXACTLY these keys:
  "title":       (string, <= 100 chars) a clear plan title (refine the proposed title if needed)
  "description": (string, 4-6 sentences) plan description covering objectives,
                 scope, key activities and expected residual outcome — written
                 for senior management

Respond with the JSON object only.
PROMPT;

        $data = $this->llm->json($prompt, $system, ['max_tokens' => 700, 'timeout' => 60]);

        if (empty($data['description']) || ! is_string($data['description'])) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model response missing required fields.',
                'raw' => $data,
            ]);
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => [
                'title' => mb_substr(trim((string) ($data['title'] ?? $title)), 0, 200),
                'description' => trim($data['description']),
            ],
        ]);
    }

    /**
     * KRI Description Builder — drafts a board-ready description of what a
     * Key Risk Indicator measures and why it is predictive, given a name,
     * category and unit.
     *
     * POST /risk/ai/tools/kri-description
     */
    public function kriDescription(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $validated = $request->validate([
            'scenario' => 'required|string|min:3|max:2000',
            'name' => 'nullable|string|max:200',
            'category' => 'nullable|string|max:100',
            'measurement_unit' => 'nullable|string|max:50',
        ]);

        if (! $this->llm->available()) {
            return response()->json(['ok' => false, 'fallback' => true, 'error' => $this->llm->lastError()]);
        }

        $name = $validated['name'] ?? 'Not specified';
        $category = $validated['category'] ?? 'Not specified';
        $unit = $validated['measurement_unit'] ?? 'Not specified';

        $system = <<<'SYS'
You are a risk measurement specialist at a Nigerian commercial bank. You
describe Key Risk Indicators in plain English — what is measured, where the
data comes from inside the bank, why the indicator is predictive of underlying
risk, and how breaches should be interpreted. Use Nigerian regulator references
(CBN, NDPC, NFIU) only where they naturally fit. Never invent systems or
metrics the user did not provide. Respond with JSON only.
SYS;

        $prompt = <<<PROMPT
Scenario / brief from KRI owner: "{$validated['scenario']}"
Proposed KRI name: {$name}
Risk category:     {$category}
Unit of measure:   {$unit}

Produce a JSON object with EXACTLY these keys:
  "name":        (string, <= 80 chars) a clear KRI name (refine the proposed name if needed)
  "description": (string, 3-5 sentences) what the indicator measures, where the
                 underlying data sits, why it is predictive of the risk and how
                 a breach should be interpreted

Respond with the JSON object only.
PROMPT;

        $data = $this->llm->json($prompt, $system, ['max_tokens' => 600, 'timeout' => 60]);

        if (empty($data['description']) || ! is_string($data['description'])) {
            return response()->json([
                'ok' => false,
                'fallback' => true,
                'error' => $this->llm->lastError() ?: 'Model response missing required fields.',
                'raw' => $data,
            ]);
        }

        return response()->json([
            'ok' => true,
            'fallback' => false,
            'data' => [
                'name' => mb_substr(trim((string) ($data['name'] ?? $name)), 0, 200),
                'description' => trim($data['description']),
            ],
        ]);
    }

    /**
     * Simple health endpoint for the UI to decide whether to show AI buttons.
     * GET /risk/ai/tools/health
     */
    public function health(): JsonResponse
    {
        $ok = $this->llm->available();

        return response()->json([
            'ok' => $ok,
            'error' => $ok ? null : $this->llm->lastError(),
            'model' => config('services.llm.model'),
        ]);
    }
}
