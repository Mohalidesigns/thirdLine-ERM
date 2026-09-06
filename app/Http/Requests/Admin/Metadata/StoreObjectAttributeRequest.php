<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Support\Metadata\MetadataRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Define a field on a type (migration Phase 6.3).
 *
 * AttributeBuilder validated eleven of this form's twenty-odd inputs. Three of
 * the rest are worth naming:
 *
 * - `ref_object_type_id` was `exists:object_types,id`, so a link field could
 *   point at another institution's type. It is tenant-bound now.
 * - `visible_to_roles` had no rule at all. The form offered `Role::all()` and
 *   the component wrote whatever arrived. An invented role fails closed — the
 *   field becomes invisible to everyone — so this was a usability trap rather
 *   than a hole, but a form that offers a list and a validator that accepts
 *   anything is the pattern Phase 4.6 named.
 * - `required_permission` likewise, against the permissions that exist.
 *
 * `validation.rules` — the comma-separated extra Laravel rules an SME may type
 * — is deliberately left as free text. It is merged into the validator by
 * ObjectAttribute::validationRules(), so it is genuinely expressive, and
 * narrowing it to a whitelist would silently drop rules tenants have already
 * configured. Its blast radius is a form in the tenant's own organisation
 * refusing input. That is a hardening job with a migration attached, not a
 * port.
 */
class StoreObjectAttributeRequest extends FormRequest
{
    public const DATA_TYPES = [
        'string', 'text', 'int', 'decimal', 'money', 'bool', 'date', 'datetime',
        'enum', 'multi_enum', 'user', 'object_ref', 'json', 'formula',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('create', ObjectAttribute::class)
            && $this->user()->can('update', $this->objectType());
    }

    protected function subject(): ?ObjectAttribute
    {
        return null;
    }

    public function objectType(): ObjectType
    {
        return $this->route('objectType');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $attribute = $this->subject();

        return [
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                function (string $field, mixed $value, callable $fail) use ($attribute) {
                    $clash = ObjectAttribute::where('object_type_id', $this->objectType()->id)
                        ->where('code', $value)
                        ->when($attribute?->exists, fn ($query) => $query->whereKeyNot($attribute->id))
                        ->exists();

                    if ($clash) {
                        $fail('This type already has a field with that code.');
                    }
                },
            ],
            'label' => ['required', 'string', 'max:160'],
            'data_type' => ['required', Rule::in(self::DATA_TYPES)],
            'maps_to_column' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'section' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['integer', 'min:0'],
            'width' => ['in:half,full'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'default_value' => ['nullable', 'string', 'max:1000'],
            'ref_object_type_id' => ['nullable', 'integer', MetadataRules::objectType()],
            'formula' => ['nullable', 'string', 'max:2000'],

            'is_required' => ['boolean'],
            'is_unique' => ['boolean'],
            'is_pii' => ['boolean'],
            'show_on_mobile' => ['boolean'],
            'show_in_detail' => ['boolean'],

            'enum_options' => ['array'],
            'enum_options.*' => ['string', 'max:190'],

            'extra_rules' => ['array'],
            'extra_rules.*' => ['string', 'max:190'],

            // The form offers Role::all() and Permission::all(); the validator
            // accepts exactly those.
            'visible_to_roles' => ['array'],
            'visible_to_roles.*' => ['string', Rule::exists('roles', 'name')],
            'required_permission' => ['nullable', 'string', 'max:120', Rule::exists('permissions', 'name')],

            'visible_when_field' => ['nullable', 'string', 'max:64'],
            'visible_when_operator' => ['nullable', 'in:equals,not_equals,in,filled'],
            'visible_when_value' => ['nullable', 'string', 'max:500'],

            'migration_strategy' => ['nullable', 'in:preserve_as_text,clear'],
            'confirm_lossy_change' => ['boolean'],
        ];
    }

    /**
     * The three shape rules AttributeBuilder enforced with addError() after
     * validation rather than during it. A choice field with no options, a
     * calculated field with no formula and a link field with no target are all
     * saveable-looking and useless.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $type = $this->input('data_type');

            if (in_array($type, ['enum', 'multi_enum'], true) && $this->optionList() === []) {
                $validator->errors()->add('enum_options', 'A choice field needs at least one option.');
            }

            if ($type === 'formula' && trim((string) $this->input('formula')) === '') {
                $validator->errors()->add('formula', 'A calculated field needs a formula.');
            }

            if ($type === 'object_ref' && $this->input('ref_object_type_id') === null) {
                $validator->errors()->add('ref_object_type_id', 'A link field needs to know what it links to.');
            }
        });
    }

    /** @return list<string> */
    public function optionList(): array
    {
        return array_values(array_filter(array_map(
            fn ($option) => trim((string) $option),
            (array) $this->input('enum_options', []),
        ), fn (string $option) => $option !== ''));
    }

    /**
     * The attributes to write.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $dataType = $validated['data_type'];

        $validation = array_filter([
            'rules' => array_values(array_filter(array_map('trim', $validated['extra_rules'] ?? []))),
            'roles' => array_values(array_filter($validated['visible_to_roles'] ?? [])),
            'permission' => ($validated['required_permission'] ?? '') ?: null,
        ], fn ($value) => $value !== null && $value !== []);

        return [
            'object_type_id' => $this->objectType()->id,
            'code' => $validated['code'],
            'maps_to_column' => ($validated['maps_to_column'] ?? '') ?: null,
            'label' => $validated['label'],
            'data_type' => $dataType,
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'is_unique' => (bool) ($validated['is_unique'] ?? false),
            'is_pii' => (bool) ($validated['is_pii'] ?? false),
            'default_value' => ($validated['default_value'] ?? '') ?: null,
            'help_text' => ($validated['help_text'] ?? '') ?: null,
            'validation' => $validation ?: null,
            'visible_when' => $this->composedVisibility(),
            'enum_options' => $this->optionList() ?: null,
            'ref_object_type_id' => $dataType === 'object_ref' ? ($validated['ref_object_type_id'] ?? null) : null,
            'formula' => $dataType === 'formula' ? ($validated['formula'] ?? null) : null,
            'section' => ($validated['section'] ?? '') ?: 'Details',
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'width' => $validated['width'] ?? 'half',
            'show_on_mobile' => (bool) ($validated['show_on_mobile'] ?? true),
            'show_in_detail' => (bool) ($validated['show_in_detail'] ?? true),
        ];
    }

    /**
     * visible_when, in the shape ObjectAttribute reads it.
     *
     * "true"/"false"/"3" typed into a text box mean the boolean and the
     * number, because that is what the field they point at will hold.
     *
     * @return array<string, mixed>|null
     */
    private function composedVisibility(): ?array
    {
        $field = (string) $this->input('visible_when_field', '');

        if ($field === '') {
            return null;
        }

        $value = (string) $this->input('visible_when_value', '');

        $typed = match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => is_numeric($value) ? $value + 0 : $value,
        };

        return match ($this->input('visible_when_operator')) {
            'not_equals' => ['field' => $field, 'not_equals' => $typed],
            'in' => ['field' => $field, 'in' => array_map('trim', explode(',', $value))],
            'filled' => ['field' => $field, 'filled' => true],
            default => ['field' => $field, 'equals' => $typed],
        };
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return Role::query()->orderBy('name')->pluck('name')->all();
    }
}
