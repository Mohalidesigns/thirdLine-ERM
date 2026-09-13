<?php

namespace App\Rules;

use App\Models\GraphObject;
use App\Models\ObjectAttribute;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `is_unique` on a tenant-configured attribute.
 *
 * WHY THIS IS NOT A DATABASE CONSTRAINT, and not `Rule::unique()` either: the
 * values live in one JSON column shared by every attribute of every type, so
 * no index can express "unique within this key, for this type, in this
 * tenant". The scan is the only way to ask the question.
 *
 * WHY IT IS A RULE RATHER THAN A CHECK IN THE CONTROLLER: it belongs in the
 * same pass as the attribute's other rules, so a duplicate reports itself
 * against the field the user typed into rather than as a failed save.
 *
 * MIGRATION PHASE 6.8. This enforcement existed ONLY inside the Livewire
 * DynamicForm component (its private enforceUniqueness()). Every Inertia
 * screen has gone through ObjectAttribute::validationRules(), which has never
 * produced a uniqueness rule of any kind — so since Phase 3.2 a tenant could
 * tick "unique" in the builder and the screens people actually use would write
 * duplicates without complaint. Deleting the Livewire component would have
 * removed the last place the box meant anything; this is where it means
 * something now.
 *
 * Tenancy is not asserted here because it cannot be forgotten: GraphObject
 * carries BelongsToOrganization's global scope, so the query is already
 * confined to the current organization. A value used by another tenant is not
 * a clash.
 */
class UniqueConfiguredAttribute implements ValidationRule
{
    public function __construct(
        private readonly ObjectAttribute $attribute,
        private readonly int $objectTypeId,
        /**
         * The graph object being edited, excluded from the scan so that
         * re-saving a record without touching the field does not report the
         * record's own value as a duplicate of itself.
         */
        private readonly ?int $ignoreObjectId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || is_array($value)) {
            return;
        }

        $needle = $this->comparable($value);

        $clash = GraphObject::query()
            ->where('object_type_id', $this->objectTypeId)
            ->when(
                $this->ignoreObjectId !== null,
                fn ($query) => $query->where('id', '!=', $this->ignoreObjectId)
            )
            ->get(['id', 'attributes'])
            ->first(function (GraphObject $other) use ($needle): bool {
                $stored = $other->customAttributes()[$this->attribute->code] ?? null;

                return $stored !== null
                    && ! is_array($stored)
                    && $this->comparable($stored) === $needle;
            });

        if ($clash !== null) {
            $fail("The {$this->attribute->label} must be unique; another record already uses that value.");
        }
    }

    /**
     * Compare as text.
     *
     * What arrives from a form is a string; what is stored has been through
     * PersistsConfiguredAttributes::castConfiguredValue() and may be an int or
     * a float. A strict comparison between the two would report "3" and 3 as
     * different values and let the duplicate through — which is the failure
     * mode this rule exists to prevent.
     */
    private function comparable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
