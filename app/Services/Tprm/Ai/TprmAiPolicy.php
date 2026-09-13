<?php

namespace App\Services\Tprm\Ai;

use App\Models\Tprm\TprmSetting;
use App\Services\Llm\Contracts\ModuleAiPolicy;
use App\Services\Llm\ModulePolicySnapshot;
use App\Services\Llm\UsageCaps;

/**
 * Compresses `config('tprm.ai.*')` and `tp_settings` into the module-blind
 * shape `App\Services\Llm\LlmGateway` reads — phase-11a-ai-contract.md §5.
 *
 * REGISTERED FOR 'tprm' in `TprmServiceProvider::boot()`. This is the ONE
 * place TPRM's tri-state resolution (config → config → tri-state tenant →
 * config → tri-state tenant) is written; `Tprm\Extraction\LlmClient::enabled()`
 * calls it too, so the cheap no-network check and the gateway's own
 * resolution can never drift apart into two different answers to the same
 * question.
 *
 * BOUND `scoped()` IN `TprmServiceProvider` (Gate 2 fix, phase 11a). Every
 * consumer — `AiSettingsController`'s own constructor injection,
 * `ModuleAiPolicyRegistry::resolve('tprm')` inside `LlmGateway`,
 * `ExtractionDispatcher`, `LlmClient` — therefore shares ONE instance per
 * request/job, and `$settingsCache` below memoises the one-row-per-tenant
 * `tp_settings` read across all of them: the settings screen was issuing nine
 * identical `SELECT`s for that single row (seven `snapshot()` calls, one
 * `masterEnabled()`, one direct read in the controller) before this. `scoped`
 * rather than `singleton` deliberately: Laravel flushes scoped instances
 * between queued jobs (`QueueServiceProvider`), so a persistent queue worker
 * gets a fresh policy — and a fresh cache — per job rather than serving one
 * tenant's stale switch state to the next job on the same worker.
 */
class TprmAiPolicy implements ModuleAiPolicy
{
    /** @var array<int, TprmSetting> */
    private array $settingsCache = [];

    /**
     * The one place this class reads `tp_settings`, so every caller within
     * one request/job sees the same row without a repeat query.
     */
    public function settingsFor(int $organizationId): TprmSetting
    {
        return $this->settingsCache[$organizationId] ??= TprmSetting::forOrganization($organizationId);
    }

    /**
     * Drop the memoised row — called after a save so a policy resolved
     * earlier in the SAME request/job (e.g. the controller's own instance,
     * which is this same scoped instance) never hands back what the tenant
     * just changed away from.
     */
    public function forget(?int $organizationId = null): void
    {
        if ($organizationId === null) {
            $this->settingsCache = [];

            return;
        }

        unset($this->settingsCache[$organizationId]);
    }

    public function snapshot(int $organizationId, string $service): ModulePolicySnapshot
    {
        $settings = $this->settingsFor($organizationId);

        $implemented = in_array($service, (array) config('tprm.ai.implemented_services', []), true);
        $deploymentEnabled = (bool) config("tprm.ai.services.{$service}", false);

        $tenantServices = (array) ($settings->ai_services ?? []);
        $tenantServiceValue = array_key_exists($service, $tenantServices) ? $tenantServices[$service] : null;

        return new ModulePolicySnapshot(
            moduleEnabled: (bool) config('tprm.ai.enabled'),
            tenantMasterEnabled: $settings->ai_enabled,
            // An unimplemented service collapses to "not deployment-enabled"
            // regardless of its config boolean — contract §4.2: the screen
            // must never render a switch for code that does not exist.
            serviceDeploymentEnabled: $implemented && $deploymentEnabled,
            tenantServiceEnabled: $tenantServiceValue === null ? null : (bool) $tenantServiceValue,
            caps: new UsageCaps($settings->ai_monthly_token_cap, $settings->ai_monthly_call_cap),
            endpointProfileKey: $settings->ai_endpoint_profile,
        );
    }

    /**
     * Whether AI is on for this tenant AT ALL, independent of any one
     * service — steps 1-3 of contract §5 only (`services.llm.enabled`,
     * `tprm.ai.enabled`, `tp_settings.ai_enabled`'s tri-state). Shared by
     * `AiSettingsController` and `AiUsageController` so the two screens can
     * never disagree about what "AI is currently on for this tenant" means.
     */
    public function masterEnabled(int $organizationId): bool
    {
        if (! config('services.llm.enabled') || ! config('tprm.ai.enabled')) {
            return false;
        }

        $settings = $this->settingsFor($organizationId);

        return $settings->ai_enabled !== false;
    }
}
