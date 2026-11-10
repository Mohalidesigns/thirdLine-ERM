<?php

namespace App\Policies\Sm;

use App\Models\Sm\Scenario;
use App\Models\User;

/**
 * Named for its model, in the namespace Laravel's discovery looks in:
 * `App\Models\Sm\Scenario` → `App\Policies\Sm\ScenarioPolicy`.
 *
 * Development standard §3 records two shipped outages from getting this wrong —
 * an ability written on the wrong policy class returns false for everybody,
 * silently, and nothing fails until a user reports a 403 they should not have had.
 *
 * ORDER INSIDE EVERY ABILITY: permission → tenancy → domain rule.
 *
 * THE LIFECYCLE IS DELIBERATELY NOT A DOMAIN RULE HERE. "Only an editable scenario
 * can be updated" is a LIFECYCLE rule, and standard §3 is explicit that those stay
 * in the controller and answer with a FLASH MESSAGE rather than a 403: the user is
 * allowed to try, the machine says no and says why. `ScenarioService::refuseIfReadOnly()`
 * and `ScenarioLifecycle::transition()` are where those live, and both throw
 * `ScenarioRefused` carrying the exact sentence to flash. Putting the status check
 * here instead would turn "this scenario is in validation" into a 403 page.
 *
 * `Gate::before` in AppServiceProvider grants super-admin every ability before any
 * of this runs.
 */
class ScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('scenario.view');
    }

    public function view(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.view') && $this->sameTenant($user, $scenario);
    }

    public function create(User $user): bool
    {
        return $user->can('scenario.create');
    }

    public function update(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.update') && $this->sameTenant($user, $scenario);
    }

    /**
     * Soft delete, under `scenario.update`.
     *
     * §A1.10 REFUSED a `scenario.delete` permission outright: soft delete goes
     * through this ability, because `loss_event.delete` exists only where a record
     * is regulatory and carries its own destruction rules. A scenario is neither —
     * it is a governance record whose deletion is an edit of the register, and
     * inventing a seventh permission for it would put a grant on the
     * role-assignment screen that no route uses.
     */
    public function delete(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.update') && $this->sameTenant($user, $scenario);
    }

    /**
     * Every lifecycle transition, including `draft → in_design`.
     *
     * §A1.11 gates `POST {scenario}/status` with `scenario.validate` and spec 01 §3
     * states it twice, so with §A1.10's grants only `risk-manager`,
     * `chief-risk-officer` and `super-admin` hold it. A designer holding
     * `scenario.update` alone can fill in every field and cannot advance the
     * scenario out of `draft` — which is why the workspace must never render a
     * transition button for them, and why the readiness strip says so in words
     * rather than leaving a silence that reads as a broken button.
     */
    public function transition(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.validate') && $this->sameTenant($user, $scenario);
    }

    /**
     * Record a sign-off row as one of the three sources.
     *
     * NOT the same authority as `scenario.validate`: signing is attesting to
     * somebody else's design, and §A1.10 refused this grant to `risk-analyst`
     * precisely because "independent challenge is a flag on a sign-off row, not a
     * reason to let the analyst sign the record they are challenging".
     */
    public function signOff(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.sign_off') && $this->sameTenant($user, $scenario);
    }

    /**
     * Append to the notes log.
     *
     * SEPARATE FROM `update` BECAUSE THEY ARE DIFFERENT AUTHORITIES (§A1.10):
     * editing a scenario is first-line work, recording a dissent against somebody
     * else's is second-line work, and one permission for both lets the designer
     * write the dissent log the trail exists to keep. This is the module's
     * separation of duties made visible — per §A1.10's grants, `risk-analyst`,
     * `risk-owner` and `compliance-officer` hold `scenario.view` + `scenario.note`
     * ONLY, so a risk owner can open a scenario they cannot edit and dissent
     * against it.
     */
    public function note(User $user, Scenario $scenario): bool
    {
        return $user->can('scenario.note') && $this->sameTenant($user, $scenario);
    }

    /**
     * Tenancy, checked explicitly even though `BelongsToOrganization` already scopes
     * the query and `ResolveTenant` runs before `SubstituteBindings`.
     *
     * Belt and braces on purpose: a policy that trusts the route binding is a policy
     * that passes for any caller who manages to hand it a model from elsewhere —
     * a queued job, a console command, a future controller that resolves by uuid.
     */
    private function sameTenant(User $user, Scenario $scenario): bool
    {
        return (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
