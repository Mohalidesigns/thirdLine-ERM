<?php

namespace App\Services\Llm;

/**
 * One module's answer to "what does this tenant's configuration say about
 * this service", resolved and handed to the gateway as data.
 *
 * THIS IS THE SHAPE THAT KEEPS `App\Services\Llm\` MODULE-BLIND. ADR 0015 §2:
 * "the gateway takes resolved settings as an argument and reads no settings
 * table itself, so the two modules can disagree about where they keep their
 * policy without the gateway caring." TPRM's tri-state `tp_settings` columns
 * and BCMS's boolean `bcms_settings.ai_enabled` both compress down to this one
 * shape — a `ModuleAiPolicy` implementation living in the MODULE's own
 * namespace does the compressing, never this one.
 */
final class ModulePolicySnapshot
{
    public function __construct(
        /** `services.llm.enabled` is checked once, generically, before this is even asked for. */
        public readonly bool $moduleEnabled,
        /** Tri-state: true = on, false = off, null = not set, follow the deployment. */
        public readonly ?bool $tenantMasterEnabled,
        /**
         * Deployment allows this service AND the module has code behind it.
         * An unimplemented service collapses to `false` here — TPRM's screen
         * renders that as "Not built in this release" rather than as a
         * disabled switch that implies code exists to flip on later.
         */
        public readonly bool $serviceDeploymentEnabled,
        /** Tri-state, same meaning as `$tenantMasterEnabled`, scoped to one service. */
        public readonly ?bool $tenantServiceEnabled,
        public readonly UsageCaps $caps,
        /** Null = tenant has not chosen; `EndpointResolver` applies the deployment default. */
        public readonly ?string $endpointProfileKey,
    ) {}
}
