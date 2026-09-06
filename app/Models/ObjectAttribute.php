<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One configurable field on an object type.
 *
 * No organization_id of its own: an attribute belongs to a type, and the type
 * already answers whose it is. A tenant extends a system type by defining its
 * own type that inherits from it, not by writing attributes onto the shared row.
 */
class ObjectAttribute extends Model
{
    use HasFactory;

    protected $table = 'object_attributes';

    protected $fillable = [
        'object_type_id',
        'code',
        'maps_to_column',
        'label',
        'data_type',
        'is_required',
        'is_unique',
        'default_value',
        'validation',
        'visible_when',
        'enum_options',
        'ref_object_type_id',
        'formula',
        'section',
        'sort_order',
        'width',
        'show_on_mobile',
        'show_in_detail',
        'help_text',
        'is_pii',
        'is_system',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_unique' => 'boolean',
        'validation' => 'array',
        'visible_when' => 'array',
        'enum_options' => 'array',
        'is_pii' => 'boolean',
        'is_system' => 'boolean',
        'show_on_mobile' => 'boolean',
        'show_in_detail' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Whether this attribute is stored in a real column on the backing model
     * rather than in the objects.attributes JSON bag.
     */
    public function isMapped(): bool
    {
        return filled($this->maps_to_column);
    }

    /**
     * The Alpine expression that decides whether this field is on screen, or
     * null when it is always visible.
     *
     * visible_when is {"field":"code","equals":value} or
     * {"field":"code","in":[...]} or {"field":"code","filled":true}.
     *
     * This governs DISPLAY ONLY. A field that a user must not be allowed to
     * set is gated by validation.roles / validation.permission, which is
     * enforced server-side in DynamicForm — hiding a field in the browser is
     * not a permission check, because the browser belongs to the user.
     */
    public function visibilityExpression(string $bag = 'values'): ?string
    {
        $rule = $this->visible_when ?? [];
        $field = $rule['field'] ?? null;

        if (! is_string($field) || $field === '') {
            return null;
        }

        $target = "{$bag}['".addslashes($field)."']";

        if (array_key_exists('equals', $rule)) {
            return $target.' == '.json_encode($rule['equals']);
        }

        if (array_key_exists('not_equals', $rule)) {
            return $target.' != '.json_encode($rule['not_equals']);
        }

        if (array_key_exists('in', $rule)) {
            return json_encode(array_values((array) $rule['in'])).'.includes('.$target.')';
        }

        if (array_key_exists('filled', $rule)) {
            return ($rule['filled'] ? '' : '!').'('.$target." !== null && {$target} !== '' )";
        }

        return null;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ObjectType, $this> */
    public function objectType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function referencedType()
    {
        return $this->belongsTo(ObjectType::class, 'ref_object_type_id');
    }

    /**
     * The Laravel validation rules this attribute implies.
     *
     * Built from data_type first, then anything explicit in validation JSON,
     * so a configured rule can tighten the type but a missing configuration
     * still validates. `validation` accepts either a rule string or a list.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        $rules = array_merge($rules, match ($this->data_type) {
            'string' => ['string', 'max:255'],
            'text' => ['string', 'max:65535'],
            'int' => ['integer'],
            // Money is entered in major units by a human and stored in minor
            // units by the caster; the rule validates what is typed.
            'decimal', 'money' => ['numeric'],
            'bool' => ['boolean'],
            'date' => ['date'],
            'datetime' => ['date'],
            'enum' => ['string', 'in:'.implode(',', $this->enum_options ?? [])],
            'multi_enum' => ['array'],
            'user' => ['integer', 'exists:users,id'],
            'object_ref' => ['integer', 'exists:objects,id'],
            'json' => ['array'],
            // A formula is computed, never posted. Rejecting input outright is
            // the difference between a derived field and a spoofable one.
            'formula' => ['prohibited'],
            default => ['string'],
        });

        $configured = $this->validation ?? [];

        if (isset($configured['rules'])) {
            $rules = array_merge($rules, (array) $configured['rules']);
        }

        return array_values(array_unique($rules));
    }

    /**
     * Rules for each element of a multi_enum, which Laravel validates through
     * a second `code.*` key rather than on the array itself.
     *
     * @return list<string>|null
     */
    public function elementValidationRules(): ?array
    {
        if ($this->data_type !== 'multi_enum') {
            return null;
        }

        return ['string', 'in:'.implode(',', $this->enum_options ?? [])];
    }
}
