<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for a locally hosted Ollama-compatible LLM (e.g. Granite, Llama).
 *
 * Contract:
 *  - complete(): returns raw string output, or "" on failure.
 *  - json():     returns an array parsed from the model's JSON output, or [] on failure.
 *  - available(): cheap health check. Used by UIs to show a graceful fallback path.
 *
 * The service never throws to callers. On failure it returns an empty result AND
 * sets $lastError so the UI layer can explain why the AI feature is temporarily
 * unavailable instead of blanking the page.
 */
class LlmService
{
    protected string $endpoint;

    protected string $model;

    protected int $timeout;

    protected float $temperature;

    protected bool $enabled;

    protected ?string $lastError = null;

    public function __construct()
    {
        $cfg = config('services.llm');
        $this->endpoint = $cfg['endpoint'] ?? 'http://localhost:11434';
        $this->model = $cfg['model'] ?? 'granite4:micro';
        $this->timeout = (int) ($cfg['timeout'] ?? 20);
        $this->temperature = (float) ($cfg['temperature'] ?? 0.2);
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
    }

    /**
     * A clone of this service pointed at a different endpoint and, optionally,
     * a different model — phase-11a-ai-contract.md §2.3.
     *
     * A CLONE, NEVER A MUTATION. `App\Services\Llm\LlmGateway` is bound
     * transient specifically so a per-call endpoint cannot leak between
     * tenants or between jobs on the same worker; mutating this singleton-ish
     * service in place would defeat that the moment two calls interleaved
     * on the same PHP process (a queue worker running two jobs back to back).
     */
    public function forEndpoint(string $endpoint, ?string $model = null): static
    {
        $clone = clone $this;
        $clone->endpoint = rtrim($endpoint, '/');

        if ($model !== null) {
            $clone->model = $model;
        }

        return $clone;
    }

    public function available(): bool
    {
        if (! $this->enabled) {
            $this->lastError = 'LLM is disabled in configuration.';

            return false;
        }
        try {
            $res = Http::timeout(3)->get($this->endpoint.'/api/tags');
            if (! $res->successful()) {
                $this->lastError = 'LLM endpoint returned HTTP '.$res->status();
            }

            return $res->successful();
        } catch (Throwable $e) {
            $this->lastError = 'LLM endpoint unreachable: '.$e->getMessage();

            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Ollama's `options` object, shared by `complete()`, `json()` and
     * `jsonWithUsage()` — ADR 0015 §6d, phase-11a-ai-contract.md §2.3/§4.4.
     *
     * `num_ctx` is sent ONLY when `$opts['num_ctx']` is present — never a
     * default invented here. The driver does not decide the context window;
     * `config/llm.php` → `context.num_ctx` does (ADR 0015 §6d, deviation 9 —
     * moved out of the budget array specifically because a grandfathered ERM
     * caller spreads its budget straight into a direct `LlmService` call, and
     * a `num_ctx` living in that array would have reached Ollama through a
     * caller this phase never touched). A caller with nothing declared for it
     * (a budget the gateway did not populate, or the grandfathered ERM path)
     * must leave Ollama on whatever it would otherwise apply, exactly as
     * before.
     *
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function modelOptions(array $opts, int $defaultMaxTokens = 768): array
    {
        $options = [
            'temperature' => $opts['temperature'] ?? $this->temperature,
            'num_predict' => $opts['max_tokens'] ?? $defaultMaxTokens,
        ];

        if (isset($opts['num_ctx'])) {
            $options['num_ctx'] = (int) $opts['num_ctx'];
        }

        return $options;
    }

    /**
     * Free-form text completion.
     */
    public function complete(string $prompt, string $system = '', array $opts = []): string
    {
        if (! $this->enabled) {
            $this->lastError = 'LLM disabled.';

            return '';
        }

        try {
            $res = Http::timeout($opts['timeout'] ?? $this->timeout)
                ->post($this->endpoint.'/api/generate', [
                    'model' => $opts['model'] ?? $this->model,
                    'prompt' => $prompt,
                    'system' => $system,
                    'stream' => false,
                    'keep_alive' => $opts['keep_alive'] ?? '30m',
                    'options' => $this->modelOptions($opts, 512),
                ]);

            if (! $res->successful()) {
                $this->lastError = 'LLM HTTP '.$res->status();
                Log::warning('LLM request failed', ['status' => $res->status(), 'body' => $res->body()]);

                return '';
            }

            return (string) ($res->json('response') ?? '');
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('LLM request exception', ['err' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Ask the model for JSON and parse it. Uses Ollama's `format: json` mode when
     * available and falls back to extracting the first JSON object from free text.
     *
     * Pass `cache_key` in $opts (and optional `cache_ttl` in seconds, default 24h)
     * to memoize the response. Used for heavy prompts that are stable per-entity
     * (e.g. "give me controls for risk #7") so a warmed cache makes live demos instant.
     */
    public function json(string $prompt, string $system = '', array $opts = []): array
    {
        if (! $this->enabled) {
            $this->lastError = 'LLM disabled.';

            return [];
        }

        $cacheKey = $opts['cache_key'] ?? null;
        if ($cacheKey && ($cached = Cache::get('llm:'.$cacheKey)) !== null) {
            return $cached;
        }

        try {
            $res = Http::timeout($opts['timeout'] ?? $this->timeout)
                ->post($this->endpoint.'/api/generate', [
                    'model' => $opts['model'] ?? $this->model,
                    'prompt' => $prompt,
                    'system' => $system,
                    'stream' => false,
                    'format' => 'json',
                    // Defect (c), phase-11a-ai-contract.md §6.2: complete()
                    // already sent this; json() did not, so every cached-miss
                    // call here paid a cold model load on top of its own
                    // latency budget.
                    'keep_alive' => $opts['keep_alive'] ?? '30m',
                    'options' => $this->modelOptions($opts),
                ]);

            if (! $res->successful()) {
                $this->lastError = 'LLM HTTP '.$res->status();
                Log::warning('LlmService completeJson failed: '.$this->lastError);

                return [];
            }

            $raw = (string) ($res->json('response') ?? '');
            $parsed = $this->parseJson($raw);

            if ($cacheKey && ! empty($parsed)) {
                Cache::put('llm:'.$cacheKey, $parsed, $opts['cache_ttl'] ?? 86400);
            }

            return $parsed;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('LlmService completeJson exception: '.$this->lastError);

            return [];
        }
    }

    /**
     * A JSON completion WITH the backend's own token counts.
     *
     * Added for TPRM's extraction pipeline, which must "log model, prompt
     * version, tokens and cost per call" (TRD §12.1). {@see self::json()}
     * discards the usage numbers the backend returns, and a cost log built on
     * an estimate of its own is a cost log nobody can reconcile against a
     * bill — so the counts come from the response or they are null, never
     * guessed.
     *
     * Deliberately a separate method rather than a change to `json()`: every
     * existing caller expects that method to return the decoded object itself,
     * and widening its return type would break each of them.
     *
     * `kind` and `status` are ADDITIVE fields for `App\Services\Llm\LlmGateway`
     * (phase-11a-ai-contract.md §6.2), which must tell a connection failure
     * from a timeout from an HTTP 5xx from an unparsable 200 in order to
     * apply ADR 0015 §6's retry-on and breaker rules correctly — a plain
     * string `error` cannot be matched on reliably. Every existing caller
     * that only reads the keys it already knew about is unaffected.
     *
     * @param  array<string, mixed>  $opts
     * @return array{data: array<mixed>, model: string, prompt_tokens: int|null, completion_tokens: int|null, duration_ms: int, error: string|null, kind: string, status: int|null}
     */
    public function jsonWithUsage(string $prompt, string $system = '', array $opts = []): array
    {
        $model = (string) ($opts['model'] ?? $this->model);
        $startedAt = microtime(true);

        $empty = fn (?string $error, string $kind, ?int $status = null) => [
            'data' => [],
            'model' => $model,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error,
            'kind' => $kind,
            'status' => $status,
        ];

        if (! $this->enabled) {
            $this->lastError = 'LLM disabled.';

            return $empty($this->lastError, 'disabled');
        }

        try {
            $res = Http::timeout($opts['timeout'] ?? $this->timeout)
                ->post($this->endpoint.'/api/generate', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'system' => $system,
                    'stream' => false,
                    'format' => 'json',
                    // Same defect (c) fix as json() above — this is TPRM
                    // extraction's own path and the one the 26-69s probe was
                    // measured against.
                    'keep_alive' => $opts['keep_alive'] ?? '30m',
                    'options' => $this->modelOptions($opts),
                ]);

            if (! $res->successful()) {
                $this->lastError = 'LLM HTTP '.$res->status();
                Log::warning('LlmService jsonWithUsage failed: '.$this->lastError);

                return $empty($this->lastError, 'http_error', $res->status());
            }

            $parsed = $this->parseJson((string) ($res->json('response') ?? ''));

            return [
                'data' => $parsed,
                'model' => $model,
                // Ollama's own counters. Absent on a backend that does not
                // report them, and null rather than zero in that case: zero
                // tokens is a claim, and "we were not told" is the truth.
                'prompt_tokens' => $res->json('prompt_eval_count'),
                'completion_tokens' => $res->json('eval_count'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $parsed === [] ? $this->lastError : null,
                // The box answered with a 200; the model's own output just was
                // not usable JSON. ADR 0015 §6: this does NOT count as a
                // breaker failure — the endpoint is up.
                'kind' => $parsed === [] ? 'unparsable' : 'ok',
                'status' => $res->status(),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->lastError = $e->getMessage();
            Log::warning('LlmService jsonWithUsage connection exception: '.$this->lastError);

            // Laravel's HTTP client raises the same exception class for a
            // refused connection and for a client-side timeout; the message
            // is the only place the two differ, so it is inspected here once
            // rather than by every caller that needs to tell them apart.
            $kind = str_contains(mb_strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'connection';

            return $empty($this->lastError, $kind);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('LlmService jsonWithUsage exception: '.$this->lastError);

            return $empty($this->lastError, 'connection');
        }
    }

    protected function parseJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Attempt to extract first {...} block if model added prose.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $this->lastError = 'LLM returned non-JSON payload.';

        return [];
    }
}
