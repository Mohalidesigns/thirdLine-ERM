<?php

namespace App\Http\Requests\Concerns;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Rules\UniqueConfiguredAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The validation rules for a tenant's configured fields, for a Form Request
 * (migration Phase 2, 2.4).
 *
 * The React DynamicForm posts an unmapped field under
 * configured_attributes[code], and PersistsConfiguredAttributes validates
 * it again on save. A Form Request that wants those errors alongside its
 * own — so a bad configured value fails the request in one pass rather than
 * after the controller's rules have already been satisfied — merges these
 * into rules() and attributes():
 *
 *     public function rules(): array
 *     {
 *         return [
 *             'name' => ['required', 'string', 'max:200'],
 *             ...$this->configuredAttributeRules('Control'),
 *         ];
 *     }
 *
 *     public function attributes(): array
 *     {
 *         return $this->configuredAttributeLabels('Control');
 *     }
 *
 * The rule set is the one PersistsConfiguredAttributes builds: only fields
 * that are unmapped (a mapped one posts under its column and has the
 * controller's own rule), not computed, and visible to the signed-in user.
 * A role-gated field the user may not see gets no rule, so nothing they
 * post under its name is ever validated into existence.
 */
trait ValidatesConfiguredAttributes
{
    /**
     * @param  Model|null  $editing  the record being edited, so its own value
     *                               is not reported as a duplicate of itself.
     *                               Null on a create, which has no record yet.
     * @return array<string, array<int, mixed>> configured_attributes.<code> => rules
     */
    protected function configuredAttributeRules(ObjectType|string $type, ?Model $editing = null): array
    {
        $rules = [];
        $ignoreObjectId = $this->graphObjectIdOf($editing);
        $objectType = $type instanceof ObjectType ? $type : ObjectType::resolve($type);

        foreach ($this->writableConfiguredAttributes($type) as $attribute) {
            $attributeRules = $attribute->validationRules();

            // is_unique cannot be expressed as a rule string: the values share
            // one JSON column, so it takes a scan. See UniqueConfiguredAttribute.
            //
            // Scoped to the type being EDITED, not to $attribute->object_type_id
            // — an inherited attribute belongs to the parent type, and a value
            // on an Opportunity does not collide with one on a Risk.
            if ($attribute->is_unique && $objectType !== null) {
                $attributeRules[] = new UniqueConfiguredAttribute(
                    $attribute,
                    $objectType->id,
                    $ignoreObjectId,
                );
            }

            $rules["configured_attributes.{$attribute->code}"] = $attributeRules;

            if (($elementRules = $attribute->elementValidationRules()) !== null) {
                $rules["configured_attributes.{$attribute->code}.*"] = $elementRules;
            }
        }

        return $rules;
    }

    private function graphObjectIdOf(?Model $editing): ?int
    {
        if ($editing === null || ! method_exists($editing, 'graphObject')) {
            return null;
        }

        return $editing->graphObject()?->id;
    }

    /**
     * Human names for the error messages, so a failure reads "The NDPR
     * Lawful Basis field is required" rather than naming the array key.
     *
     * @return array<string, string> configured_attributes.<code> => label
     */
    protected function configuredAttributeLabels(ObjectType|string $type): array
    {
        $labels = [];

        foreach ($this->writableConfiguredAttributes($type) as $attribute) {
            $labels["configured_attributes.{$attribute->code}"] = $attribute->label;
        }

        return $labels;
    }

    /**
     * @return Collection<int, ObjectAttribute>
     */
    private function writableConfiguredAttributes(ObjectType|string $type): Collection
    {
        $objectType = $type instanceof ObjectType ? $type : ObjectType::resolve($type);

        if ($objectType === null) {
            return collect();
        }

        return $objectType->resolvedAttributes()
            ->reject(fn (ObjectAttribute $attribute) => $attribute->isMapped())
            ->reject(fn (ObjectAttribute $attribute) => $attribute->data_type === 'formula')
            ->filter(fn (ObjectAttribute $attribute) => $attribute->visibleToCurrentUser())
            ->values();
    }
}
