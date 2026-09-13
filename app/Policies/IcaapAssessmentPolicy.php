<?php

namespace App\Policies;

use App\Models\IcaapAssessment;
use App\Models\User;

/**
 * Who may read and sign off an ICAAP assessment (migration Phase 5.2).
 *
 * `quantification.approve_icaap` is seeded and, until this policy, was read by
 * nothing: the assessment carries `approved_by_board`, `board_approval_date`,
 * `cbn_submission_date` and `cbn_submission_ref`, and no screen had ever
 * guarded writing them. Board approval of an ICAAP is the act that turns a
 * working paper into a document filed with the regulator, so it gets its own
 * ability rather than riding on `quantification.create`.
 *
 * THE PREPARER IS NOT THE APPROVER. A preparer holding `approve_icaap` may
 * still sign off their own assessment — segregation of duties is a matter for
 * the tenant's role assignments, not something to hardcode here — but the two
 * abilities are separate so a tenant CAN separate them.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class IcaapAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quantification.view');
    }

    public function view(User $user, IcaapAssessment $assessment): bool
    {
        return $user->can('quantification.view') && $this->sameTenant($user, $assessment);
    }

    public function create(User $user): bool
    {
        return $user->can('quantification.create');
    }

    public function update(User $user, IcaapAssessment $assessment): bool
    {
        return $user->can('quantification.create') && $this->sameTenant($user, $assessment);
    }

    /**
     * Record board approval, and with it the CBN submission details.
     */
    public function approve(User $user, IcaapAssessment $assessment): bool
    {
        return $user->can('quantification.approve_icaap') && $this->sameTenant($user, $assessment);
    }

    private function sameTenant(User $user, IcaapAssessment $assessment): bool
    {
        return (int) $assessment->organization_id === (int) $user->organization_id;
    }
}
