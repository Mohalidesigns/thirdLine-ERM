<?php

namespace App\Services\Tprm\Continuity;

use App\Models\Tprm\BcpTest;
use App\Models\Tprm\Engagement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one write path onto `tp_bcp_tests` — TPRM's own service and TPRM's own
 * authority check, called from BCMS Phase 11's supplier resilience screen and
 * from anywhere inside TPRM itself.
 *
 * A BCMS PERMISSION IS NOT A LICENCE TO WRITE THE VENDOR REGISTER
 * (phase-11-spec §5). The caller may hold `bcms.report.view` and nothing
 * else; recording an attestation additionally requires `tprm.edit` — "change
 * a third party or an engagement that already exists" — because a BCP test
 * result is exactly that: a fact recorded against an engagement TPRM owns.
 */
class BcpTestRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     */
    public function record(Engagement $engagement, array $attributes, User $actor): BcpTest
    {
        if (! $actor->can('tprm.edit')) {
            throw new AuthorizationException(
                'Recording a BCP test result changes an engagement TPRM owns, and requires the tprm.edit '
                .'permission in addition to any BCMS grant.'
            );
        }

        return BcpTest::query()->create(array_merge($attributes, [
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'created_by' => $actor->getKey(),
        ]));
    }
}
