<?php

namespace App\Presenters;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Services\Metadata\FormOptionResolver;
use App\View\Components\DynamicDetail;
use App\View\Components\DynamicForm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns an object type's configured fields into the props the React
 * DynamicForm and DynamicDetail components render (migration Phase 2, 2.4).
 *
 * This is a projection of the two Blade view components, not a second
 * implementation of them. Which fields exist, which of them the signed-in
 * user may see, what they post as, what their options are and how a stored
 * value is displayed are all decided by DynamicForm / DynamicDetail; this
 * class instantiates them and reads the answers out as arrays. A rule
 * enforced in the Blade renderer and re-derived here would be two rules.
 *
 * ROLE GATING STAYS SERVER-SIDE. A field the user may not see is not in the
 * schema at all, so the browser never learns it exists. Conditional
 * visibility (`visibleWhen`) is a display rule the client evaluates, and
 * nothing more.
 */
class FormSchemaPresenter
{
    /**
     * The schema a create/edit form renders from.
     *
     * @param  list<string>  $omit
     * @param  list<string>|null  $sections
     * @param  array<string, mixed>  $defaults
     * @return array{
     *     objectType: array{id: int, code: string, name: string}|null,
     *     sections: list<array{code: string, label: string, fields: list<array<string, mixed>>}>
     * }
     */
    public function form(
        ObjectType|string|int $type,
        ?Model $record = null,
        array $omit = [],
        ?array $sections = null,
        array $defaults = [],
        bool $mobile = false,
    ): array {
        $component = new DynamicForm(
            type: $type,
            record: $record,
            sections: $sections,
            omit: $omit,
            defaults: $defaults,
            mobile: $mobile,
        );

        // The Blade form drops formula fields because it has nowhere to put
        // an input that must not post. The React form shows them as a
        // read-only box, so they are appended to the component's own field
        // list — filtered by the same omit, mobile and role rules — and then
        // sectioned by the component's own grouping, so order and section
        // selection stay its decision.
        $formulas = $this->formulaFields($component, $omit, $mobile);
        $formulaValues = $this->formulaValues($record, $formulas);

        $component->fields = $component->fields
            ->concat($formulas)
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']])
            ->values();

        $resolver = app(FormOptionResolver::class);

        $out = [];

        foreach ($component->sectioned() as $section => $fields) {
            $out[] = [
                'code' => (string) $section,
                'label' => (string) $section,
                'fields' => $fields->map(function (ObjectAttribute $field) use ($component, $resolver, $formulaValues) {
                    $readonly = $field->data_type === 'formula';

                    return [
                        'code' => $field->code,
                        'name' => $component->nameFor($field),
                        'errorKey' => $component->errorKeyFor($field),
                        'label' => $field->label,
                        'type' => $field->data_type,
                        'required' => (bool) $field->is_required,
                        'options' => $this->optionsFor($field, $component, $resolver),
                        'value' => $readonly
                            ? ($formulaValues[$field->code] ?? null)
                            : ($component->values[$field->code] ?? null),
                        'help' => $field->help_text,
                        'width' => $field->width ?: 'half',
                        'pii' => (bool) $field->is_pii,
                        'visibleWhen' => self::visibleWhen($field),
                        'mapped' => $field->isMapped(),
                        'readonly' => $readonly,
                        'formula' => $readonly ? $field->formula : null,
                    ];
                })->values()->all(),
            ];
        }

        $objectType = $component->objectType;

        return [
            'objectType' => $objectType === null ? null : [
                'id' => $objectType->id,
                'code' => $objectType->code,
                'name' => $objectType->name,
            ],
            'sections' => $out,
        ];
    }

    /**
     * The read view of one record, values already formatted for a human.
     *
     * @param  list<string>  $omit
     * @param  list<string>|null  $sections
     * @return array{sections: list<array{code: string, label: string, fields: list<array{code: string, label: string, value: string|null, block: bool, pii: bool}>}>}
     */
    public function detail(
        Model $record,
        ObjectType|string|int|null $type = null,
        array $omit = [],
        ?array $sections = null,
        bool $hideEmpty = false,
        bool $mobile = false,
    ): array {
        $component = new DynamicDetail(
            record: $record,
            type: $type,
            sections: $sections,
            omit: $omit,
            hideEmpty: $hideEmpty,
            mobile: $mobile,
        );

        $out = [];

        foreach ($component->sectioned() as $section => $fields) {
            $out[] = [
                'code' => (string) $section,
                'label' => (string) $section,
                'fields' => $fields->map(fn (ObjectAttribute $field) => [
                    'code' => $field->code,
                    'label' => $field->label,
                    'value' => $component->display($field),
                    'block' => $component->isBlock($field),
                    'pii' => (bool) $field->is_pii,
                ])->values()->all(),
            ];
        }

        return ['sections' => $out];
    }

    /**
     * One shape for a display condition, whichever of the two places it was
     * configured in:
     *
     *   object_attributes.visible_when  {"field": "issue_source", "equals": "regulatory"}
     *   validation JSON                 {"visible_when": {"attribute": "has_third_party", "equals": true}}
     *
     * The column wins when both are set. Beyond `equals`, the column's other
     * operators (`not_equals`, `in`, `filled`) are passed through under their
     * own keys so the client can evaluate them; a rule with no usable field
     * is null, which means always visible.
     *
     * @return array<string, mixed>|null
     */
    public static function visibleWhen(ObjectAttribute $field): ?array
    {
        $rule = $field->visible_when ?? [];

        if (! is_array($rule) || empty($rule['field'])) {
            $legacy = data_get($field->validation, 'visible_when');

            $rule = is_array($legacy) ? $legacy : [];
        }

        $target = $rule['field'] ?? $rule['attribute'] ?? null;

        if (! is_string($target) || $target === '') {
            return null;
        }

        $out = ['field' => $target];

        foreach (['equals', 'not_equals', 'in', 'filled'] as $operator) {
            if (array_key_exists($operator, $rule)) {
                $out[$operator] = $operator === 'in' ? array_values((array) $rule[$operator]) : $rule[$operator];
            }
        }

        // The legacy shape treats a bare {"attribute": x} as "equals true".
        if (count($out) === 1) {
            $out['equals'] = true;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return list<array{value: int|string, label: string}>|null
     */
    private function optionsFor(ObjectAttribute $field, DynamicForm $component, FormOptionResolver $resolver): ?array
    {
        // A `user` field with no explicit source is still a choice of a
        // person; the users lookup is the only sensible list for it.
        if ($field->data_type === 'user' && data_get($field->validation, 'options_source') === null) {
            $proxy = $field->replicate();
            $proxy->validation = array_merge($field->validation ?? [], ['options_source' => 'users']);

            $options = $resolver->optionsFor($proxy);
        } elseif ($component->isChoice($field)) {
            $options = $component->optionsFor($field);
        } else {
            return null;
        }

        $out = [];

        foreach ($options as $value => $label) {
            $out[] = ['value' => $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * The formula fields the Blade component filtered out, subject to the
     * same omit / mobile / role rules it applied to everything else.
     *
     * @param  list<string>  $omit
     * @return Collection<int, ObjectAttribute>
     */
    private function formulaFields(DynamicForm $component, array $omit, bool $mobile): Collection
    {
        if ($component->objectType === null) {
            return collect();
        }

        return $component->objectType->resolvedAttributes()
            ->filter(fn (ObjectAttribute $field) => $field->data_type === 'formula')
            ->reject(fn (ObjectAttribute $field) => in_array($field->code, $omit, true))
            ->reject(fn (ObjectAttribute $field) => $mobile && ! $field->show_on_mobile)
            ->filter(fn (ObjectAttribute $field) => DynamicForm::visibleToUser($field))
            ->values();
    }

    /**
     * Whatever a computation last stored for each formula field, if anything.
     *
     * @param  Collection<int, ObjectAttribute>  $formulas
     * @return array<string, mixed>
     */
    private function formulaValues(?Model $record, Collection $formulas): array
    {
        if ($formulas->isEmpty() || $record === null || ! method_exists($record, 'graphObject')) {
            return [];
        }

        $bag = $record->graphObject()?->customAttributes() ?? [];

        return $formulas
            ->mapWithKeys(fn (ObjectAttribute $field) => [$field->code => $bag[$field->code] ?? null])
            ->all();
    }
}
