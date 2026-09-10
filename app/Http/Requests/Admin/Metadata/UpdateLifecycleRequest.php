<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectLifecycle;

/**
 * Amend a state machine (migration Phase 6.3).
 *
 * Saving over a SEEDED lifecycle clones it into the tenant's own namespace
 * rather than editing it in place — the seeded states are what the domain
 * tables' existing strings conform to, and changing one would silently
 * invalidate live rows. That behaviour is the controller's, kept verbatim from
 * LifecycleBuilder.
 */
class UpdateLifecycleRequest extends StoreLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->subject());
    }

    protected function subject(): ?ObjectLifecycle
    {
        return $this->route('lifecycle');
    }
}
