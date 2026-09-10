<?php

namespace App\Http\Requests\Emerging;

/**
 * Amend a horizon entry (migration Phase 4.6).
 *
 * Same fields as the create form — an emerging risk has no field that may only
 * be set once — so the rules are inherited whole. The authorize() differs: it
 * asks the policy about THIS record, which is what makes the tenancy check a
 * policy decision rather than the controller's hand-rolled abort_unless.
 *
 * The update path had the same orphan-shaped bug as the create path, one step
 * worse: `$emerging->update(...)` ran before saveConfiguredAttributes()
 * validated, so a submission that failed on a tenant-added field still wrote
 * every column — the record was renamed, restatused and rescored by a request
 * the user was told had failed. See the note on StoreEmergingRiskRequest.
 */
class UpdateEmergingRiskRequest extends StoreEmergingRiskRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('emerging'));
    }
}
