<?php

namespace App\Http\Controllers\Admin\Metadata;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Metadata\StoreObjectAttributeRequest;
use App\Http\Requests\Admin\Metadata\UpdateObjectAttributeRequest;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The fields on a type (migration Phase 6.3, from Livewire AttributeBuilder).
 *
 * THE DATA TYPE OF AN ATTRIBUTE IN USE IS THE DANGEROUS EDIT. Changing 'text'
 * to 'int' is one click and turns "approximately ₦4m" into 0 in every record
 * that ever held it, with no undo. MetadataGuard refuses it unless the caller
 * picks an explicit migration path; widening conversions that cannot lose
 * anything are allowed silently.
 *
 * `impact` reproduces the warning the Livewire component raised the moment the
 * data type changed rather than at save. A user who discovers at save that
 * their choice is refused has already lost the rest of the form's state to a
 * validation bounce, and that is as true of an Inertia form as it was of a
 * Livewire one.
 */
class ObjectAttributeController extends Controller
{
    public function __construct(private readonly MetadataGuard $guard) {}

    public function index(ObjectType $objectType)
    {
        Gate::authorize('viewAny', ObjectAttribute::class);
        Gate::authorize('view', $objectType);

        $own = $objectType->attributeDefinitions()->orderBy('section')->orderBy('sort_order')->get();
        $ownCodes = $own->pluck('code')->all();

        return Inertia::render('Admin/Builder/Attributes/Edit', [
            'objectType' => $objectType->only(['id', 'code', 'name', 'plural_name', 'is_system']),
            'attributes' => $own->map(fn (ObjectAttribute $attribute) => $this->present($attribute))->values(),
            // Attributes this type gets from its ancestors, read-only: they are
            // edited on the type that defines them, and an SME who does not see
            // them here will define a duplicate.
            'inherited' => $objectType->resolvedAttributes()
                ->reject(fn (ObjectAttribute $attribute) => in_array($attribute->code, $ownCodes, true))
                ->map(fn (ObjectAttribute $attribute) => [
                    'code' => $attribute->code,
                    'label' => $attribute->label,
                    'data_type' => $attribute->data_type,
                    'section' => $attribute->section,
                ])->values(),
            'options' => [
                'dataTypes' => self::DATA_TYPE_LABELS,
                'types' => ObjectType::query()->orderBy('name')->get(['id', 'name'])->values(),
                'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
                'permissions' => Permission::query()->orderBy('name')->pluck('name')->values(),
                'migrationStrategies' => ['preserve_as_text', 'clear'],
            ],
        ]);
    }

    /**
     * What a data type change would cost, asked before the save.
     *
     * The Livewire component answered this on every `updatedDataType`. It is a
     * GET rather than a side-effecting call so a page can ask it freely.
     */
    public function impact(Request $request, ObjectType $objectType, ObjectAttribute $attribute)
    {
        $this->assertBelongsTo($attribute, $objectType);
        Gate::authorize('update', $attribute);

        $target = (string) $request->query('data_type', $attribute->data_type);

        if ($target === $attribute->data_type) {
            return response()->json(['needs_migration_path' => false, 'affected_records' => 0]);
        }

        try {
            $this->guard->assertDataTypeChangeIsSafe($attribute, $target);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'needs_migration_path' => true,
                'affected_records' => $this->guard->attributeUsageCount($attribute),
                'reason' => $e->validator->errors()->first(),
            ]);
        }

        return response()->json(['needs_migration_path' => false, 'affected_records' => 0]);
    }

    public function store(StoreObjectAttributeRequest $request, ObjectType $objectType)
    {
        $attribute = new ObjectAttribute;
        $attribute->fill($request->payload())->save();

        return back()->with('success', "Saved field “{$attribute->label}”.");
    }

    public function update(UpdateObjectAttributeRequest $request, ObjectType $objectType, ObjectAttribute $attribute)
    {
        $this->assertBelongsTo($attribute, $objectType);

        $validated = $request->validated();
        $confirmed = (bool) ($validated['confirm_lossy_change'] ?? false);

        // Throws unless the change is safe or the caller picked a path.
        $this->guard->assertDataTypeChangeIsSafe($attribute, $validated['data_type'], $confirmed);

        if ($confirmed && $attribute->data_type !== $validated['data_type']) {
            $this->guard->migrateAttributeValues($attribute, $validated['migration_strategy'] ?? 'preserve_as_text');
        }

        $attribute->fill($request->payload())->save();

        return back()->with('success', "Saved field “{$attribute->label}”.");
    }

    public function destroy(Request $request, ObjectType $objectType, ObjectAttribute $attribute)
    {
        $this->assertBelongsTo($attribute, $objectType);
        Gate::authorize('delete', $attribute);

        $confirmed = $request->boolean('confirmed');

        // Throws unless the attribute is unused or the caller confirmed.
        $this->guard->assertAttributeDeletable($attribute, $confirmed);

        $label = $attribute->label;
        $attribute->delete();

        return back()->with('success', "Deleted field “{$label}”.");
    }

    /**
     * The attribute in the URL must be a field of the type in the URL.
     *
     * ObjectAttribute carries no organization_id and no tenancy trait, so
     * route-model binding resolves it unscoped. The policy already refuses an
     * attribute whose type belongs to another institution; this refuses the
     * quieter mismatch of editing type A's field through type B's URL, where
     * both types are the caller's own and the redirect would land on a screen
     * that never shows the change.
     */
    private function assertBelongsTo(ObjectAttribute $attribute, ObjectType $objectType): void
    {
        abort_unless((int) $attribute->object_type_id === (int) $objectType->id, 404);
    }

    public const DATA_TYPE_LABELS = [
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

    /**
     * @return array<string, mixed>
     */
    private function present(ObjectAttribute $attribute): array
    {
        $validation = $attribute->validation ?? [];
        $visible = $attribute->visible_when ?? [];

        $operator = 'equals';
        foreach (['not_equals', 'in', 'filled'] as $candidate) {
            if (array_key_exists($candidate, $visible)) {
                $operator = $candidate;
                break;
            }
        }

        $value = match (true) {
            array_key_exists('in', $visible) => implode(', ', (array) $visible['in']),
            array_key_exists('equals', $visible) => $this->scalarToText($visible['equals']),
            array_key_exists('not_equals', $visible) => $this->scalarToText($visible['not_equals']),
            default => '',
        };

        return array_merge($attribute->only([
            'id', 'code', 'label', 'data_type', 'maps_to_column', 'is_required', 'is_unique',
            'is_pii', 'default_value', 'help_text', 'section', 'sort_order', 'width',
            'show_on_mobile', 'show_in_detail', 'formula', 'ref_object_type_id',
        ]), [
            'enum_options' => array_values($attribute->enum_options ?? []),
            'extra_rules' => array_values((array) ($validation['rules'] ?? [])),
            'visible_to_roles' => array_values((array) ($validation['roles'] ?? [])),
            'required_permission' => $validation['permission'] ?? null,
            'visible_when_field' => $visible['field'] ?? '',
            'visible_when_operator' => $operator,
            'visible_when_value' => $value,
            'usage_count' => $this->guard->attributeUsageCount($attribute),
        ]);
    }

    private function scalarToText(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
