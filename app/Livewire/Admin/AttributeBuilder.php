<?php

namespace App\Livewire\Admin;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use Livewire\Component;

/**
 * WP-05 TASK 1 — CRUD for the fields on an object type.
 *
 * Every data type the schema allows, plus validation rules, enum options,
 * conditional visibility, calculated fields, sections and ordering — which is
 * the full list the work package asks for, and also the full list of ways an
 * SME can quietly break a form. The guardrails live in MetadataGuard so that
 * a bundle import is held to the same rules as this screen.
 *
 * THE DATA TYPE OF AN ATTRIBUTE IN USE IS THE DANGEROUS EDIT. Changing 'text'
 * to 'int' is one click and turns "approximately ₦4m" into 0 in every record
 * that ever held it, with no undo. The form refuses it unless the user picks
 * an explicit migration path, and widening conversions that cannot lose
 * anything are allowed silently.
 */
class AttributeBuilder extends Component
{
    public int $objectTypeId;

    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $deletingId = null;

    /** Set when a data_type change needs an explicit migration decision. */
    public bool $needsMigrationPath = false;

    public string $migrationStrategy = 'preserve_as_text';

    public int $affectedRecordCount = 0;

    /* Form state ------------------------------------------------------- */

    public string $code = '';

    public string $label = '';

    public string $data_type = 'string';

    public string $maps_to_column = '';

    public bool $is_required = false;

    public bool $is_unique = false;

    public bool $is_pii = false;

    public string $default_value = '';

    public string $help_text = '';

    public string $section = 'Details';

    public int $sort_order = 0;

    public string $width = 'half';

    public bool $show_on_mobile = true;

    public bool $show_in_detail = true;

    /** Free-text list of enum options, one per line — the shape SMEs type. */
    public string $enum_options = '';

    public string $formula = '';

    public ?int $ref_object_type_id = null;

    /** Extra Laravel rules, comma separated, e.g. "min:3,max:20". */
    public string $extra_rules = '';

    /** @var list<string> role names that may see this field */
    public array $visible_to_roles = [];

    public string $required_permission = '';

    /* Conditional visibility */
    public string $visible_when_field = '';

    public string $visible_when_operator = 'equals';

    public string $visible_when_value = '';

    public const DATA_TYPES = [
        'string' => 'Short text',
        'text' => 'Long text',
        'int' => 'Whole number',
        'decimal' => 'Decimal',
        'money' => 'Money',
        'bool' => 'Yes / No',
        'date' => 'Date',
        'datetime' => 'Date and time',
        'enum' => 'Single choice',
        'multi_enum' => 'Multiple choice',
        'user' => 'User',
        'object_ref' => 'Link to a record',
        'json' => 'Structured data',
        'formula' => 'Calculated',
    ];

    public function mount(int $objectTypeId): void
    {
        $this->objectTypeId = $objectTypeId;
    }

    public function render()
    {
        $type = ObjectType::findOrFail($this->objectTypeId);

        return view('livewire.admin.attribute-builder', [
            'objectType' => $type,
            'attributes' => $type->attributeDefinitions()->orderBy('section')->orderBy('sort_order')->get(),
            'inherited' => $this->inheritedAttributes($type),
            'dataTypes' => self::DATA_TYPES,
            'typeOptions' => ObjectType::orderBy('name')->get(['id', 'name']),
            'roleOptions' => \Spatie\Permission\Models\Role::orderBy('name')->pluck('name'),
            'siblingCodes' => $type->attributeDefinitions()
                ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
                ->pluck('label', 'code'),
        ]);
    }

    /**
     * Attributes this type gets from its ancestors. Shown read-only: they are
     * edited on the type that defines them, and an SME who does not see them
     * here will define a duplicate.
     */
    private function inheritedAttributes(ObjectType $type)
    {
        $own = $type->attributeDefinitions->pluck('code')->all();

        return $type->resolvedAttributes()
            ->reject(fn (ObjectAttribute $attribute) => in_array($attribute->code, $own, true))
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Form */
    /* ------------------------------------------------------------------ */

    public function create(): void
    {
        $this->resetForm();
        $this->sort_order = (int) ObjectAttribute::where('object_type_id', $this->objectTypeId)->max('sort_order') + 10;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $attribute = ObjectAttribute::findOrFail($id);

        $this->editingId = $attribute->id;
        $this->code = $attribute->code;
        $this->label = $attribute->label;
        $this->data_type = $attribute->data_type;
        $this->maps_to_column = $attribute->maps_to_column ?? '';
        $this->is_required = (bool) $attribute->is_required;
        $this->is_unique = (bool) $attribute->is_unique;
        $this->is_pii = (bool) $attribute->is_pii;
        $this->default_value = (string) ($attribute->default_value ?? '');
        $this->help_text = (string) ($attribute->help_text ?? '');
        $this->section = $attribute->section ?: 'Details';
        $this->sort_order = (int) $attribute->sort_order;
        $this->width = $attribute->width ?: 'half';
        $this->show_on_mobile = (bool) $attribute->show_on_mobile;
        $this->show_in_detail = (bool) $attribute->show_in_detail;
        $this->enum_options = implode("\n", $attribute->enum_options ?? []);
        $this->formula = (string) ($attribute->formula ?? '');
        $this->ref_object_type_id = $attribute->ref_object_type_id;

        $validation = $attribute->validation ?? [];
        $this->extra_rules = implode(',', (array) ($validation['rules'] ?? []));
        $this->visible_to_roles = (array) ($validation['roles'] ?? []);
        $this->required_permission = (string) ($validation['permission'] ?? '');

        $visible = $attribute->visible_when ?? [];
        $this->visible_when_field = (string) ($visible['field'] ?? '');
        $this->visible_when_operator = $this->operatorOf($visible);
        $this->visible_when_value = $this->visibilityValueOf($visible);

        $this->needsMigrationPath = false;
        $this->showForm = true;
    }

    /**
     * Warn as soon as the data type is changed, not at save time. A user who
     * discovers at save that their choice is refused has already lost the rest
     * of the form's state to a validation bounce.
     */
    public function updatedDataType(): void
    {
        $this->needsMigrationPath = false;
        $this->affectedRecordCount = 0;

        if ($this->editingId === null) {
            return;
        }

        $attribute = ObjectAttribute::find($this->editingId);

        if ($attribute === null || $attribute->data_type === $this->data_type) {
            return;
        }

        $guard = app(MetadataGuard::class);

        try {
            $guard->assertDataTypeChangeIsSafe($attribute, $this->data_type);
        } catch (\Illuminate\Validation\ValidationException) {
            $this->needsMigrationPath = true;
            $this->affectedRecordCount = $guard->attributeUsageCount($attribute);
        }
    }

    public function save(): void
    {
        $attribute = $this->editingId === null
            ? new ObjectAttribute(['object_type_id' => $this->objectTypeId])
            : ObjectAttribute::findOrFail($this->editingId);

        $validated = $this->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                function ($field, $value, $fail) use ($attribute) {
                    $clash = ObjectAttribute::where('object_type_id', $this->objectTypeId)
                        ->where('code', $value)
                        ->when($attribute->exists, fn ($query) => $query->whereKeyNot($attribute->id))
                        ->exists();

                    if ($clash) {
                        $fail('This type already has a field with that code.');
                    }
                },
            ],
            'label' => 'required|string|max:160',
            'data_type' => 'required|in:'.implode(',', array_keys(self::DATA_TYPES)),
            'maps_to_column' => 'nullable|string|max:64|regex:/^[a-z][a-z0-9_]*$/',
            'section' => 'nullable|string|max:80',
            'sort_order' => 'integer|min:0',
            'width' => 'in:half,full',
            'help_text' => 'nullable|string|max:1000',
            'default_value' => 'nullable|string|max:1000',
            'ref_object_type_id' => 'nullable|integer|exists:object_types,id',
            'required_permission' => 'nullable|string|max:120',
            'visible_when_field' => 'nullable|string|max:64',
        ]);

        $options = $this->parsedEnumOptions();

        if (in_array($this->data_type, ['enum', 'multi_enum'], true) && $options === []) {
            $this->addError('enum_options', 'A choice field needs at least one option.');

            return;
        }

        if ($this->data_type === 'formula' && trim($this->formula) === '') {
            $this->addError('formula', 'A calculated field needs a formula.');

            return;
        }

        if ($this->data_type === 'object_ref' && $this->ref_object_type_id === null) {
            $this->addError('ref_object_type_id', 'A link field needs to know what it links to.');

            return;
        }

        $guard = app(MetadataGuard::class);

        if ($attribute->exists) {
            // Throws unless the change is safe or the user picked a path.
            $guard->assertDataTypeChangeIsSafe($attribute, $this->data_type, $this->needsMigrationPath);

            if ($this->needsMigrationPath && $attribute->data_type !== $this->data_type) {
                $guard->migrateAttributeValues($attribute, $this->migrationStrategy);
            }
        }

        $validation = array_filter([
            'rules' => array_values(array_filter(array_map('trim', explode(',', $this->extra_rules)))),
            'roles' => array_values(array_filter($this->visible_to_roles)),
            'permission' => $this->required_permission ?: null,
        ], fn ($value) => $value !== null && $value !== []);

        $attribute->fill([
            'object_type_id' => $this->objectTypeId,
            'code' => $validated['code'],
            'maps_to_column' => $this->maps_to_column ?: null,
            'label' => $validated['label'],
            'data_type' => $this->data_type,
            'is_required' => $this->is_required,
            'is_unique' => $this->is_unique,
            'is_pii' => $this->is_pii,
            'default_value' => $this->default_value ?: null,
            'validation' => $validation ?: null,
            'visible_when' => $this->composedVisibility(),
            'enum_options' => $options ?: null,
            'ref_object_type_id' => $this->data_type === 'object_ref' ? $this->ref_object_type_id : null,
            'formula' => $this->data_type === 'formula' ? $this->formula : null,
            'section' => $this->section ?: 'Details',
            'sort_order' => $this->sort_order,
            'width' => $this->width,
            'show_on_mobile' => $this->show_on_mobile,
            'show_in_detail' => $this->show_in_detail,
        ])->save();

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Saved field “{$validated['label']}”.");
    }

    public function delete(int $id): void
    {
        $attribute = ObjectAttribute::findOrFail($id);
        $guard = app(MetadataGuard::class);

        // First click asks; second click, with the id already staged, proceeds.
        $confirmed = $this->deletingId === $id;

        $guard->assertAttributeDeletable($attribute, $confirmed);

        if (! $confirmed && $guard->attributeUsageCount($attribute) > 0) {
            $this->deletingId = $id;

            return;
        }

        $label = $attribute->label;
        $attribute->delete();

        $this->deletingId = null;
        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Deleted field “{$label}”.");
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->deletingId = null;
        $this->resetForm();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function parsedEnumOptions(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $this->enum_options) ?: [])));
    }

    /** @return array<string, mixed>|null */
    private function composedVisibility(): ?array
    {
        if ($this->visible_when_field === '') {
            return null;
        }

        $value = $this->visible_when_value;

        // "true"/"false"/"3" typed into a text box mean the boolean and the
        // number, because that is what the field they point at will hold.
        $typed = match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => is_numeric($value) ? $value + 0 : $value,
        };

        return match ($this->visible_when_operator) {
            'not_equals' => ['field' => $this->visible_when_field, 'not_equals' => $typed],
            'in' => ['field' => $this->visible_when_field, 'in' => array_map('trim', explode(',', $value))],
            'filled' => ['field' => $this->visible_when_field, 'filled' => true],
            default => ['field' => $this->visible_when_field, 'equals' => $typed],
        };
    }

    /** @param array<string, mixed> $rule */
    private function operatorOf(array $rule): string
    {
        foreach (['not_equals', 'in', 'filled'] as $operator) {
            if (array_key_exists($operator, $rule)) {
                return $operator;
            }
        }

        return 'equals';
    }

    /** @param array<string, mixed> $rule */
    private function visibilityValueOf(array $rule): string
    {
        foreach (['equals', 'not_equals'] as $operator) {
            if (array_key_exists($operator, $rule)) {
                return is_bool($rule[$operator])
                    ? ($rule[$operator] ? 'true' : 'false')
                    : (string) $rule[$operator];
            }
        }

        if (array_key_exists('in', $rule)) {
            return implode(', ', (array) $rule['in']);
        }

        return '';
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'label', 'data_type', 'maps_to_column', 'is_required', 'is_unique',
            'is_pii', 'default_value', 'help_text', 'enum_options', 'formula', 'ref_object_type_id',
            'extra_rules', 'visible_to_roles', 'required_permission', 'visible_when_field',
            'visible_when_operator', 'visible_when_value', 'needsMigrationPath', 'affectedRecordCount',
        ]);

        $this->data_type = 'string';
        $this->section = 'Details';
        $this->width = 'half';
        $this->show_on_mobile = true;
        $this->show_in_detail = true;
        $this->visible_when_operator = 'equals';
        $this->migrationStrategy = 'preserve_as_text';
        $this->resetErrorBag();
    }
}
