<?php

namespace App\Services\Bcms\Ai;

use App\Services\Bcms\BcmsSettings;
use App\Services\Llm\Contracts\ModuleAiPolicy;
use App\Services\Llm\ModulePolicySnapshot;
use App\Services\Llm\UsageCaps;

/**
 * BCMS's answer to the same question TPRM's `TprmAiPolicy` answers —
 * phase-11a-ai-contract.md §2.3, ADR 0015 §9.
 *
 * BCMS'S SHAPE IS SIMPLER AND STAYS THAT WAY IN 11A. `bcms_settings.ai_enabled`
 * is a plain boolean with no module-level deployment master switch to fall
 * through to (ADR 0015 §2 explains why TPRM's tri-state column exists and
 * BCMS's does not), there is no per-service tenant override, and there are no
 * caps or endpoint choice for BCMS in this phase. None of that is a gap this
 * class fills in — it reports the true, simpler shape, which is why
 * `tenantServiceEnabled` is always null (no such setting exists) and
 * `caps` is always uncapped.
 */
class BcmsAiPolicy implements ModuleAiPolicy
{
    public function __construct(private readonly BcmsSettings $settings) {}

    public function snapshot(int $organizationId, string $service): ModulePolicySnapshot
    {
        $setting = $this->settings->for($organizationId);

        return new ModulePolicySnapshot(
            // BCMS has no module-level deployment switch of its own — the
            // deployment LLM switch (services.llm.enabled) is the only
            // deployment-level gate it has.
            moduleEnabled: true,
            tenantMasterEnabled: (bool) $setting->ai_enabled,
            serviceDeploymentEnabled: (bool) config("bcms.ai.capabilities.{$service}", false),
            tenantServiceEnabled: null,
            caps: UsageCaps::uncapped(),
            endpointProfileKey: null,
        );
    }
}
