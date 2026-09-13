<?php

namespace App\Policies;

use App\Models\QuantificationScenario;
use App\Models\User;

/**
 * Who may maintain the scenario register (migration Phase 5.2).
 *
 * A scenario's frequency and severity parameters are drawn on by
 * MonteCarloService, the run produces an aggregate VaR, and that VaR becomes a
 * capital add-on in an ICAAP submission. Editing one is editing an input to a
 * regulatory filing, which is why the write abilities ask for
 * `quantification.create` rather than a general register permission.
 *
 * Reach is the tenant. A scenario belongs to the organisation's own
 * calibration; `quantification_scenarios` carries neither entity_id nor
 * business_unit_id, so there is no subtree to narrow to.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class QuantificationScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quantification.view');
    }

    public function view(User $user, QuantificationScenario $scenario): bool
    {
        return $user->can('quantification.view') && $this->sameTenant($user, $scenario);
    }

    public function create(User $user): bool
    {
        return $user->can('quantification.create');
    }

    public function update(User $user, QuantificationScenario $scenario): bool
    {
        return $user->can('quantification.create') && $this->sameTenant($user, $scenario);
    }

    /**
     * Take a template out of the shipped library into this register.
     *
     * The same ability as creating one by hand, because that is what it is:
     * `ScenarioLibrary::attributesFor()` writes a scenario row, and the
     * template's provenance travels into its description so the parameters can
     * still say where they came from.
     */
    public function import(User $user): bool
    {
        return $this->create($user);
    }

    private function sameTenant(User $user, QuantificationScenario $scenario): bool
    {
        return (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
