<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectAttribute;

/**
 * Amend a field (migration Phase 6.3).
 *
 * The rules are the same; the code uniqueness check ignores this row. The
 * dangerous part of an update — changing the data type of an attribute that
 * records already carry — is MetadataGuard's, enforced in the controller, so
 * a configuration bundle import is held to the same rule.
 */
class UpdateObjectAttributeRequest extends StoreObjectAttributeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->subject());
    }

    protected function subject(): ?ObjectAttribute
    {
        return $this->route('attribute');
    }
}
