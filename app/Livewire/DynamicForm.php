<?php

namespace App\Livewire;

use App\Models\GraphObject;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\ObjectVersion;
use App\Services\Graph\ObjectSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * WP-03 TASK 7 — renders, validates and persists the fields a tenant has
 * configured on an object type.
 *
 * The fields come from object_attributes and the values go into
 * objects.attributes. No migration, no model change, no deploy: a compliance
 * officer adds "NDPR lawful basis" to the Risk type and it appears on every
 * risk form, validated, the next time somebody opens one.
 *
 * Three things make this safe rather than merely flexible:
 *
 *   VALIDATION IS SERVER-SIDE AND DERIVED. Rules come from the attribute's
 *   data_type and its validation JSON — see ObjectAttribute::validationRules().
 *   The Alpine conditions in the view only decide what a user SEES.
 *
 *   ROLE-CONDITIONAL FIELDS ARE FILTERED SERVER-SIDE. A field a user may not
 *   see is never rendered and is never accepted on submit. Hiding it in the
 *   browser alone would be a permission check anyone can turn off in devtools.
 *
 *   FORMULA ATTRIBUTES ARE READ-ONLY. They are computed, so posting one is
 *   rejected outright rather than quietly overwritten.
 */
class DynamicForm extends Component
{
    /** The object type whose attributes are being edited. */
    public int $objectTypeId;

    /** The graph object being edited, if it already exists. */
    public ?int $objectId = null;

    /** @var array<string, mixed> code => value */
    public array $values = [];

    public bool $saved = false;

    /** Rendered inline (inside a parent form) rather than as its own form. */
    public bool $embedded = false;

    public function mount(
        ObjectType|int|string $objectType,
        GraphObject|Model|int|null $model = null,
        bool $embedded = false,
    ): void {
        $this->objectTypeId = $this->resolveTypeId($objectType);
        $this->embedded = $embedded;

        $object = $this->resolveObject($model);

        if ($object !== null) {
            $this->objectId = $object->id;
        }

        $stored = $object?->customAttributes() ?? [];

        foreach ($this->fields() as $field) {
            $this->values[$field->code] = $stored[$field->code]
                ?? $this->castDefault($field);
        }
    }

    /**
     * The attributes this user may see, in section then sort order.
     *
     * Inherited attributes are included: an Opportunity form shows everything
     * configured on Risk, plus its own.
     *
     * @return Collection<int, ObjectAttribute>
     */
    public function fields(): Collection
    {
        $type = ObjectType::find($this->objectTypeId);

        if ($type === null) {
            return collect();
        }

        return $type->resolvedAttributes()
            ->filter(fn (ObjectAttribute $attribute) => $this->visibleToUser($attribute))
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']])
            ->values();
    }

    /**
     * Role gating, read from the attribute's validation JSON:
     *
     *   {"roles": ["risk-manager"]}        only these roles see the field
     *   {"permission": "risk.edit"}        only holders of this permission do
     *
     * Absent means everyone who can see the form can see the field.
     */
    private function visibleToUser(ObjectAttribute $attribute): bool
    {
        $rules = $attribute->validation ?? [];
        $user = auth()->user();

        if (isset($rules['roles']) && $rules['roles'] !== []) {
            if ($user === null || ! $user->hasAnyRole((array) $rules['roles'])) {
                return false;
            }
        }

        if (isset($rules['permission'])) {
            if ($user === null || ! $user->can($rules['permission'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save the configured values onto the object's attributes bag.
     */
    public function save(): void
    {
        $this->saved = false;

        $fields = $this->fields();
        $rules = [];
        $labels = [];

        foreach ($fields as $field) {
            // Computed, never posted.
            if ($field->data_type === 'formula') {
                unset($this->values[$field->code]);

                continue;
            }

            $rules['values.'.$field->code] = $field->validationRules();
            $labels['values.'.$field->code] = $field->label;

            if (($elementRules = $field->elementValidationRules()) !== null) {
                $rules['values.'.$field->code.'.*'] = $elementRules;
            }
        }

        // Livewire throws when handed an empty rule set, and a type whose only
        // attributes are computed or role-hidden legitimately has one.
        if ($rules !== []) {
            $this->validate($rules, [], $labels);
        }

        $object = $this->objectId === null ? null : GraphObject::find($this->objectId);

        if ($object === null) {
            throw ValidationException::withMessages([
                'values' => 'This record has no graph object to attach attributes to yet. Save the record first.',
            ]);
        }

        $this->enforceUniqueness($fields, $object);

        $existing = $object->customAttributes();

        foreach ($fields as $field) {
            if ($field->data_type === 'formula') {
                continue;
            }

            $existing[$field->code] = $this->normalise($field, $this->values[$field->code] ?? null);
        }

        $object->setCustomAttributes($existing);
        $object->version = (int) $object->version + 1;
        $object->updated_by = auth()->id();
        $object->saveQuietly();

        ObjectVersion::create([
            'object_id' => $object->id,
            'version' => $object->version,
            'snapshot' => ['attributes' => $existing],
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'change_reason' => 'configured attributes updated',
            'source' => 'ui',
        ]);

        $this->saved = true;
        $this->dispatch('object-attributes-saved', objectId: $object->id);
    }

    /**
     * is_unique is enforced here rather than by a database constraint: the
     * values live in one JSON column shared by every attribute, and no index
     * can express "unique within this key, for this type, in this tenant".
     *
     * @param  Collection<int, ObjectAttribute>  $fields
     */
    private function enforceUniqueness(Collection $fields, GraphObject $object): void
    {
        foreach ($fields as $field) {
            if (! $field->is_unique) {
                continue;
            }

            $value = $this->values[$field->code] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clash = GraphObject::query()
                ->where('object_type_id', $object->object_type_id)
                ->where('id', '!=', $object->id)
                ->get(['id', 'attributes'])
                ->first(fn (GraphObject $other) => ($other->customAttributes()[$field->code] ?? null) === $value);

            if ($clash !== null) {
                throw ValidationException::withMessages([
                    'values.'.$field->code => "{$field->label} must be unique; object #{$clash->id} already uses that value.",
                ]);
            }
        }
    }

    /**
     * Store the value in the shape the attribute declares, so a later read does
     * not have to guess whether "3" is a string or a number.
     */
    private function normalise(ObjectAttribute $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($field->data_type) {
            'int', 'user', 'object_ref' => (int) $value,
            // Money arrives in major units from a human and is stored in minor
            // units, per the platform-wide rule. The currency lives alongside
            // it in the same bag so a bare number is never left to guess at.
            'money' => (int) round(((float) $value) * 100),
            'decimal' => (float) $value,
            'bool' => (bool) $value,
            'multi_enum', 'json' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }

    private function castDefault(ObjectAttribute $field): mixed
    {
        if ($field->default_value === null) {
            return in_array($field->data_type, ['multi_enum', 'json'], true) ? [] : null;
        }

        return match ($field->data_type) {
            'bool' => filter_var($field->default_value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $field->default_value,
            'decimal', 'money' => (float) $field->default_value,
            'multi_enum', 'json' => json_decode($field->default_value, true) ?? [],
            default => $field->default_value,
        };
    }

    private function resolveTypeId(ObjectType|int|string $objectType): int
    {
        if ($objectType instanceof ObjectType) {
            return $objectType->id;
        }

        if (is_int($objectType)) {
            return $objectType;
        }

        $type = ObjectType::resolve($objectType);

        if ($type === null) {
            throw new \InvalidArgumentException("Unknown object type [{$objectType}].");
        }

        return $type->id;
    }

    private function resolveObject(GraphObject|Model|int|null $model): ?GraphObject
    {
        if ($model === null) {
            return null;
        }

        if ($model instanceof GraphObject) {
            return $model;
        }

        if (is_int($model)) {
            return GraphObject::find($model);
        }

        // A typed record: use its graph node, creating it if the mirror is
        // somehow missing.
        if (method_exists($model, 'graphObject')) {
            return $model->graphObject();
        }

        return app(ObjectSyncService::class)->objectFor($model);
    }

    public function render()
    {
        return view('livewire.dynamic-form', [
            'fields' => $this->fields(),
        ]);
    }
}
