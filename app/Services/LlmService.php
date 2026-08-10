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
                    'options' => [
                        'temperature' => $opts['temperature'] ?? $this->temperature,
                        'num_predict' => $opts['max_tokens'] ?? 512,
                    ],
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
                    'options' => [
                        'temperature' => $opts['temperature'] ?? $this->temperature,
                        'num_predict' => $opts['max_tokens'] ?? 768,
                    ],
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
