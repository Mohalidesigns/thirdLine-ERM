<?php

namespace App\Services\Llm\Contracts;

use App\Services\Llm\ModulePolicySnapshot;

/**
 * Implemented ONE PER MODULE, outside `App\Services\Llm\` — the interface
 * itself is the only thing this namespace knows about a module's settings.
 *
 * `App\Services\Tprm\Ai\TprmAiPolicy` and `App\Services\Bcms\Ai\BcmsAiPolicy`
 * are the two implementations in Phase 11a. Neither is referenced by class
 * name anywhere in `App\Services\Llm\`; both are registered into
 * `ModuleAiPolicyRegistry` by their own module's wiring
 * (`TprmServiceProvider` for TPRM, `AppServiceProvider` for BCMS, which has
 * none of its own). This is what lets the guard test assert that
 * `App\Services\Llm\` never imports `App\Models\Tprm` or `App\Models\Bcms`
 * while `LlmGateway::availability()` still answers a tenant-specific
 * question.
 */
interface ModuleAiPolicy
{
    public function snapshot(int $organizationId, string $service): ModulePolicySnapshot;
}
