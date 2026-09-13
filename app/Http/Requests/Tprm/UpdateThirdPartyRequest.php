<?php

namespace App\Http\Requests\Tprm;

use App\Models\Tprm\ThirdParty;

/**
 * Editing a third party.
 *
 * The rules are the store rules, minus the parts that only apply on create.
 * `ultimate_parent_id` gains one rule the store form cannot need: a record may
 * not be its own parent, which is the one-step case of the group cycle the
 * concentration analyser would otherwise walk forever.
 */
class UpdateThirdPartyRequest extends StoreThirdPartyRequest
{
    public function authorize(): bool
    {
        $thirdParty = $this->route('third_party');

        return $thirdParty instanceof ThirdParty
            && ($this->user()?->can('update', $thirdParty) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $thirdParty = $this->route('third_party');

        if ($thirdParty instanceof ThirdParty) {
            $rules['ultimate_parent_id'][] = function (string $attribute, mixed $value, callable $fail) use ($thirdParty) {
                if ((int) $value === (int) $thirdParty->getKey()) {
                    $fail('A third party cannot be its own ultimate parent.');
                }
            };
        }

        unset($rules['accept_duplicate']);

        return $rules;
    }
}
