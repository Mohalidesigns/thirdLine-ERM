<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectRelationshipType;

/**
 * Amend a typed edge (migration Phase 6.3).
 *
 * A system type's `code` is locked, for the reason ObjectTypePolicy gives: the
 * platform resolves the seeded registry by code.
 */
class UpdateRelationshipTypeRequest extends StoreRelationshipTypeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->subject());
    }

    protected function subject(): ?ObjectRelationshipType
    {
        return $this->route('relationshipType');
    }
}
