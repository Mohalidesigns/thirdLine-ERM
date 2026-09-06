<?php

namespace App\Http\Requests\Admin;

use App\Models\ScoringProfile;

/**
 * Amend a scoring profile (migration Phase 6.4).
 *
 * Saving over the SEEDED profile forks it into this organisation's own rather
 * than editing it in place — the 5×5 is what every tenant without a profile of
 * their own resolves against, so an edit would move scores for everybody. That
 * is the controller's behaviour, kept from ScoringProfileBuilder; the
 * uniqueness rule here ignores the row being edited.
 */
class UpdateScoringProfileRequest extends StoreScoringProfileRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->subject());
    }

    protected function subject(): ?ScoringProfile
    {
        return $this->route('scoringProfile');
    }
}
