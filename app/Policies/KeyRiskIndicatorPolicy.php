<?php

namespace App\Policies;

use App\Models\KeyRiskIndicator;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to a KRI and to the breaches it raises (migration Phase 4.1).
 *
 * Shape follows the Phase 3 policies: the permission string first, then reach,
 * then any domain rule. Reach is the caller's organisation and, for a
 * subtree-limited caller, the KRI's own node — a KRI IS pinned to a node
 * (ScopedToGraph on the model), unlike a treatment plan, which inherits its
 * risk's.
 *
 * Breach acknowledgement is NOT here. Laravel resolves a policy from the
 * subject's class, so an ability about a MeasureBreach has to live on
 * App\Policies\MeasureBreachPolicy or it is never reached — it would fall
 * through to a Gate ability that does not exist and deny everyone silently.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class KeyRiskIndicatorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('kri.view');
    }

    public function view(User $user, KeyRiskIndicator $kri): bool
    {
        return $user->can('kri.view') && $this->withinReach($user, $kri);
    }

    public function create(User $user): bool
    {
        return $user->can('kri.create');
    }

    public function update(User $user, KeyRiskIndicator $kri): bool
    {
        return $user->can('kri.edit') && $this->withinReach($user, $kri);
    }

    public function delete(User $user, KeyRiskIndicator $kri): bool
    {
        return $user->can('kri.delete') && $this->withinReach($user, $kri);
    }

    /** Enter a reading against the indicator. */
    public function recordMeasurement(User $user, KeyRiskIndicator $kri): bool
    {
        return $user->can('kri.record_measurement') && $this->withinReach($user, $kri);
    }

    /**
     * Edit the band set. Same permission as editing the indicator — the bands
     * are part of its definition — but a separate ability so the bulk
     * thresholds screen can ask the question without a KRI in hand.
     */
    public function manageThresholds(User $user): bool
    {
        return $user->can('kri.edit');
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one.
     * A KRI carries its own node, so this is the model's own visibleTo() scope
     * rather than a hop through a parent.
     */
    private function withinReach(User $user, KeyRiskIndicator $kri): bool
    {
        if ((int) $kri->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return KeyRiskIndicator::query()->whereKey($kri->getKey())->visibleTo($user)->exists();
    }
}
