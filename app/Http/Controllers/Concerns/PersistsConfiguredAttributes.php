<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\ObjectVersion;
use App\Rules\UniqueConfiguredAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * WP-05 TASK 2 — the second half of "a tenant adds a field and it works".
 *
 * <x-dynamic-form> renders a tenant-added field under
 * configured_attributes[code]. Without something to receive that, adding a
 * field in the builder would show it on the form, accept what the user typed,
 * and throw it away on submit — a failure with no error message, which is the
 * worst kind.
 *
 * THIS IS NOT A MASS ASSIGNMENT HOLE, and the reasons matter:
 *
 *   Only attributes CONFIGURED ON THIS TYPE are considered. A key in the
 *   request that no attribute declares is ignored, not stored.
 *
 *   Column-backed attributes are refused here. They post under their own
 *   column name and go through the controller's own validation; accepting one
 *   through this path would be a second, unvalidated way to set `status`.
 *
 *   Role-gated attributes are re-checked, not trusted. The renderer omits a
 *   field the user may not see, but the renderer runs in their browser's past
 *   and the request arrives from their browser's present.
 *
 *   Formula attributes are rejected outright, being computed.
 *
 * Validation rules come from the attribute definition, so a tenant's `min:3`
 * is enforced by the server rather than by the input's `minlength`.
 */
trait PersistsConfiguredAttributes
{
    /**
     * Validate and store the configured attributes posted with a record.
     *
     * Call after the model is saved — the values hang off its graph object,
     * which does not exist until it does.
     *
     * @return int the number of attributes written
     */
    protected function saveConfiguredAttributes(Request $request, Model $record, ObjectType|string|null $type = null): int
    {
        $posted = $request->input('configured_attributes');

        if (! is_array($posted) || $posted === []) {
            return 0;
        }

        $objectType = $this->resolveConfiguredType($type, $record);

        if ($objectType === null) {
            return 0;
        }

        $writable = $objectType->resolvedAttributes()
            ->reject(fn (ObjectAttribute $attribute) => $attribute->isMapped())
            ->reject(fn (ObjectAttribute $attribute) => $attribute->data_type === 'formula')
            ->filter(fn (ObjectAttribute $attribute) => $attribute->visibleToCurrentUser())
            ->filter(fn (ObjectAttribute $attribute) => array_key_exists($attribute->code, $posted));

        if ($writable->isEmpty()) {
            return 0;
        }

        $rules = [];
        $labels = [];
        $ignoreObjectId = method_exists($record, 'graphObject') ? $record->graphObject()?->id : null;

        foreach ($writable as $attribute) {
            $attributeRules = $attribute->validationRules();

            // The same uniqueness rule the Form Request applies. Both passes
            // must agree: a rule enforced in one of them and not the other is
            // not enforced, since a controller may reach here without one.
            if ($attribute->is_unique) {
                $attributeRules[] = new UniqueConfiguredAttribute(
                    $attribute,
                    $objectType->id,
                    $ignoreObjectId,
                );
            }

            $rules["configured_attributes.{$attribute->code}"] = $attributeRules;
            $labels["configured_attributes.{$attribute->code}"] = $attribute->label;

            if (($elementRules = $attribute->elementValidationRules()) !== null) {
                $rules["configured_attributes.{$attribute->code}.*"] = $elementRules;
            }
        }

        // Throws a ValidationException the controller's own error handling
        // already knows how to render.
        $validated = $request->validate($rules, [], $labels);
        $values = $validated['configured_attributes'] ?? [];

        $object = method_exists($record, 'graphObject') ? $record->graphObject() : null;

        if ($object === null) {
            // A record whose graph mirror is missing is a data problem worth
            // knowing about, but not one to fail a user's save over.
            logger()->warning('Configured attributes could not be stored: the record has no graph object.', [
                'model' => $record::class,
                'id' => $record->getKey(),
            ]);

            return 0;
        }

        $bag = $object->customAttributes();

        foreach ($writable as $attribute) {
            $bag[$attribute->code] = $this->castConfiguredValue($attribute, $values[$attribute->code] ?? null);
        }

        $object->setCustomAttributes($bag);
        $object->version = (int) $object->version + 1;
        $object->updated_by = auth()->id();
        $object->saveQuietly();

        ObjectVersion::create([
            'object_id' => $object->id,
            'version' => $object->version,
            'snapshot' => ['attributes' => $bag],
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'change_reason' => 'configured attributes saved with the record',
            'source' => 'ui',
        ]);

        return $writable->count();
    }

    /**
     * Store the value in the shape its type declares, so a later read does not
     * have to guess whether "3" is a string or a number.
     */
    private function castConfiguredValue(ObjectAttribute $attribute, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($attribute->data_type) {
            'int', 'user', 'object_ref' => (int) $value,
            // Entered in major units by a human, stored in minor units, per
            // the platform-wide money rule.
            'money' => (int) round(((float) $value) * 100),
            'decimal' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multi_enum' => is_array($value) ? array_values($value) : [$value],
            'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? $value),
            default => $value,
        };
    }

    private function resolveConfiguredType(ObjectType|string|null $type, Model $record): ?ObjectType
    {
        if ($type instanceof ObjectType) {
            return $type;
        }

        if (is_string($type)) {
            return ObjectType::resolve($type);
        }

        $code = \App\Support\Graph\ObjectTypeRegistry::modelTypeMap()[$record::class] ?? null;

        return $code === null ? null : ObjectType::resolve($code);
    }
}
