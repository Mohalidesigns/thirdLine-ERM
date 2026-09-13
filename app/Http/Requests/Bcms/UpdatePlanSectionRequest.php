<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\PlanSectionSource;
use App\Support\Bcms\PlanBinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Editing one plan section.
 *
 * THE BINDING IS PARSED THROUGH ITS VALUE OBJECT, not merely shape-checked. A
 * raw array reaching the assembler is a section that renders nothing in six
 * months with no explanation, and the grammar's own validator is the only place
 * that knows what a valid binding is.
 */
class UpdatePlanSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.plan.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Lower-case, dashed or underscored: the key is used as a PDF
            // anchor and an offline-bundle lookup, and a space in it breaks
            // both quietly.
            'section_key' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'title' => ['nullable', 'string', 'max:250'],
            'body' => ['nullable', 'string', 'max:60000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'source_binding' => ['nullable', 'array'],
            'source_binding.source' => [
                'required_with:source_binding',
                Rule::in(array_column(PlanSectionSource::cases(), 'value')),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $binding = $this->input('source_binding');

            if (! is_array($binding) || $binding === []) {
                return;
            }

            try {
                PlanBinding::fromArray($binding);
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('source_binding', $e->getMessage());
            }
        });
    }
}
