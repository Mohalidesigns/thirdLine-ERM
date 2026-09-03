<?php

namespace App\Http\Requests\Concerns;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\View\Components\DynamicForm;
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
     * @return array<string, list<string>> configured_attributes.<code> => rules
     */
    protected function configuredAttributeRules(ObjectType|string $type): array
    {
        $rules = [];

        foreach ($this->writableConfiguredAttributes($type) as $attribute) {
            $rules["configured_attributes.{$attribute->code}"] = $attribute->validationRules();

            if (($elementRules = $attribute->elementValidationRules()) !== null) {
                $rules["configured_attributes.{$attribute->code}.*"] = $elementRules;
            }
        }

        return $rules;
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
            ->filter(fn (ObjectAttribute $attribute) => DynamicForm::visibleToUser($attribute))
            ->values();
    }
}
