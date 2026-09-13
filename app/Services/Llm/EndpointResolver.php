<?php

namespace App\Services\Llm;

/**
 * Turns a stored profile KEY into the profile itself — never a URL, ADR 0015
 * §3: "endpoint selection, never endpoint entry."
 *
 * AN UNKNOWN OR REMOVED KEY RESOLVES TO THE DEFAULT PROFILE, silently for the
 * call itself (a tenant's extraction must not fail because an operator
 * renamed a profile) but NOT silently for the screen: `wasFallback()` is the
 * fact the settings page uses to render "the stored choice is no longer
 * offered" rather than pretending the tenant chose the default all along.
 */
class EndpointResolver
{
    public function resolve(?string $profileKey): EndpointProfile
    {
        $profiles = (array) config('llm.profiles', []);

        $key = $profileKey !== null && isset($profiles[$profileKey])
            ? $profileKey
            : (string) config('llm.default_profile', 'local-ollama');

        $profile = $profiles[$key] ?? [];

        return new EndpointProfile(
            key: $key,
            endpoint: (string) ($profile['endpoint'] ?? 'http://localhost:11434'),
            model: (string) ($profile['model'] ?? 'granite4:micro'),
            keepAlive: (string) ($profile['keep_alive'] ?? '30m'),
            unitCostPer1kTokensMinor: $profile['unit_cost_per_1k_tokens_minor'] ?? null,
            currency: $profile['currency'] ?? null,
        );
    }

    /**
     * Whether a NON-NULL stored key would fall back to the default because it
     * names no configured profile. Null itself is not a fallback — it is the
     * ordinary "tenant has not chosen" case and resolves to the default
     * without anything being stale.
     */
    public function wasFallback(?string $profileKey): bool
    {
        if ($profileKey === null) {
            return false;
        }

        return ! array_key_exists($profileKey, (array) config('llm.profiles', []));
    }

    /**
     * @return list<array{key: string, label: string, is_default: bool, priced: bool}>
     */
    public function availableProfiles(): array
    {
        $default = (string) config('llm.default_profile', 'local-ollama');

        $profiles = [];

        foreach ((array) config('llm.profiles', []) as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => (string) ($profile['label'] ?? $key),
                'is_default' => $key === $default,
                'priced' => ($profile['unit_cost_per_1k_tokens_minor'] ?? null) !== null,
            ];
        }

        return $profiles;
    }
}
