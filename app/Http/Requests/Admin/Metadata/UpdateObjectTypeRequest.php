<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectType;

/**
 * Amend an object type (migration Phase 6.3).
 *
 * Identical rules, except that a system type's identity fields are absent —
 * see StoreObjectTypeRequest::identityRules() and ObjectTypePolicy.
 */
class UpdateObjectTypeRequest extends StoreObjectTypeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->subject());
    }

    protected function subject(): ?ObjectType
    {
        return $this->route('objectType');
    }
}
