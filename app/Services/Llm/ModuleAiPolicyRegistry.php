<?php

namespace App\Services\Llm;

use App\Services\Llm\Contracts\ModuleAiPolicy;

/**
 * Module name to policy resolver, resolved lazily through the container —
 * the same shape as `App\Grids\GridRegistry`.
 *
 * A module registers itself by NAME rather than the gateway discovering it,
 * for the same reason `TprmServiceProvider::POLICIES` states its map rather
 * than letting Laravel infer one: an unregistered module should get "no
 * per-tenant policy, so nothing can be enabled" rather than a silently wrong
 * guess.
 */
class ModuleAiPolicyRegistry
{
    /** @var array<string, class-string<ModuleAiPolicy>> */
    protected static array $policies = [];

    /** @param  class-string<ModuleAiPolicy>  $class */
    public static function register(string $module, string $class): void
    {
        static::$policies[$module] = $class;
    }

    public static function resolve(string $module): ?ModuleAiPolicy
    {
        $class = static::$policies[$module] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * Test-only: restore a clean slate between tests that register their own
     * fake policy.
     */
    public static function flush(): void
    {
        static::$policies = [];
    }
}
