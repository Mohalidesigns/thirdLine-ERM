<?php

namespace App\Services\Llm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Per-ENDPOINT-PROFILE breaker, cache-backed — ADR 0015 §6.
 *
 * KEYED BY PROFILE, NOT TENANT AND NOT SERVICE. The thing that fails is the
 * box; keying by tenant would make ten tenants discover the same dead box ten
 * times, and keying by service would make the same box fail seven times over.
 *
 * CACHE-BACKED PRECISELY SO A TRANSIENT `LlmGateway` WORKS. State lives in the
 * shared cache store, not on the object, which is what lets a fresh gateway
 * instance per call still share one breaker across every worker — see
 * `config('llm.cache_store')` and why an `array` store defeats this entirely
 * (ADR 0015 §6, "deployment note, load-bearing").
 *
 * `state()` and `opensAt()` are PURE READS for the screen: they never
 * transition the breaker. Only `allows()` — called immediately before an
 * actual attempt — performs the open-to-half-open transition and admits
 * exactly one probe, per ADR 0015 §6's "half-open admits one; success closes,
 * failure re-opens."
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('llm.cache_store');

        return $store === null ? Cache::store() : Cache::store($store);
    }

    private function key(string $profileKey): string
    {
        return 'llm:breaker:'.$profileKey;
    }

    /**
     * @return array{state: string, consecutive_failures: int, opened_at: int|null, half_open_admitted: bool}
     */
    private function read(string $profileKey): array
    {
        $default = [
            'state' => self::STATE_CLOSED,
            'consecutive_failures' => 0,
            'opened_at' => null,
            'half_open_admitted' => false,
        ];

        $stored = $this->store()->get($this->key($profileKey));

        return is_array($stored) ? array_merge($default, $stored) : $default;
    }

    private function write(string $profileKey, array $data): void
    {
        // No TTL: a breaker that silently forgets it was open after the
        // cache's own eviction window would readmit traffic to a box nobody
        // has confirmed recovered. It is cleared explicitly by
        // recordSuccess(), never by time alone.
        $this->store()->forever($this->key($profileKey), $data);
    }

    /**
     * Whether a call attempt may be made right now. Transitions OPEN to
     * HALF_OPEN and admits exactly one probe once `open_seconds` has
     * elapsed; refuses every other call while a probe is outstanding.
     */
    public function allows(string $profileKey): bool
    {
        $data = $this->read($profileKey);

        if ($data['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($data['state'] === self::STATE_OPEN) {
            $openSeconds = (int) config('llm.breaker.open_seconds', 60);
            $reopensAt = ((int) $data['opened_at']) + $openSeconds;

            if (now()->timestamp < $reopensAt) {
                return false;
            }

            // The probe window has arrived. Move to half-open and admit
            // exactly this one call.
            $data['state'] = self::STATE_HALF_OPEN;
            $data['half_open_admitted'] = true;
            $this->write($profileKey, $data);

            return true;
        }

        // HALF_OPEN: exactly one probe is ever admitted between a
        // recordSuccess()/recordFailure() resolving it.
        return false;
    }

    public function recordSuccess(string $profileKey): void
    {
        $this->write($profileKey, [
            'state' => self::STATE_CLOSED,
            'consecutive_failures' => 0,
            'opened_at' => null,
            'half_open_admitted' => false,
        ]);
    }

    public function recordFailure(string $profileKey): void
    {
        $data = $this->read($profileKey);

        if ($data['state'] === self::STATE_HALF_OPEN) {
            // The probe failed: back to fully open for another window.
            $this->write($profileKey, [
                'state' => self::STATE_OPEN,
                'consecutive_failures' => ((int) $data['consecutive_failures']) + 1,
                'opened_at' => now()->timestamp,
                'half_open_admitted' => false,
            ]);

            return;
        }

        $failures = ((int) $data['consecutive_failures']) + 1;
        $threshold = (int) config('llm.breaker.failure_threshold', 3);

        if ($failures >= $threshold) {
            $this->write($profileKey, [
                'state' => self::STATE_OPEN,
                'consecutive_failures' => $failures,
                'opened_at' => now()->timestamp,
                'half_open_admitted' => false,
            ]);

            return;
        }

        $this->write($profileKey, [
            'state' => self::STATE_CLOSED,
            'consecutive_failures' => $failures,
            'opened_at' => null,
            'half_open_admitted' => false,
        ]);
    }

    public function state(string $profileKey): string
    {
        return $this->read($profileKey)['state'];
    }

    public function opensAt(string $profileKey): ?Carbon
    {
        $data = $this->read($profileKey);

        if ($data['state'] !== self::STATE_OPEN || $data['opened_at'] === null) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $data['opened_at'])
            ->addSeconds((int) config('llm.breaker.open_seconds', 60));
    }

    public function consecutiveFailures(string $profileKey): int
    {
        return (int) $this->read($profileKey)['consecutive_failures'];
    }

    /**
     * Whether the resolved cache store is process-shared. `array` (and any
     * store not listed here as shared) is per-process, which makes the
     * breaker a decoration rather than a control — the settings screen must
     * say so rather than imply protection that is not happening.
     */
    public function usesSharedStore(): bool
    {
        $store = config('llm.cache_store') ?? config('cache.default');
        $driver = config("cache.stores.{$store}.driver", $store);

        return ! in_array($driver, ['array', 'null'], true);
    }
}
