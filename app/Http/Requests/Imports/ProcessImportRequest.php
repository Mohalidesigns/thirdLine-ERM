<?php

namespace App\Http\Requests\Imports;

use App\Models\DataImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start an import with a column mapping (migration Phase 5.5).
 *
 * THE MAPPING'S KEYS ARE THE THING BEING VALIDATED, and nothing validated them
 * before: the rule was `column_mapping => required|array` and that was all.
 * Those keys become the attribute names in `Model::create()` —
 *
 *     foreach ($mapping as $field => $columnIndex) { $data[$field] = $value; }
 *     Risk::create(array_merge($data, [...]));
 *
 * — so a caller could name ANY fillable column on the target model, not just
 * the nine the mapping screen offers: `parent_risk_id`, `entity_id`,
 * `hierarchy_path` and `created_by` are all fillable on Risk, and none of them
 * is something a spreadsheet import was meant to set.
 *
 * WHAT IT COULD NOT DO, stated because the obvious worry is the wrong one:
 * `organization_id` is fillable too, but DataImportProcessor::process()
 * overwrites it with the import's own organisation after the mapping is
 * applied, so a mapping naming it could not write into another institution.
 * That one line is the whole reason this was a hole in what a row may CONTAIN
 * rather than in which bank it lands in.
 *
 * The accepted keys are `DataImport::fieldsFor()` — the same list the mapping
 * screen offers. 4.6's rule: what a form OFFERS must be what the validator
 * ACCEPTS, and both must read one list.
 */
class ProcessImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process', $this->route('import'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $import = $this->route('import');

        $allowed = $import instanceof DataImport
            ? DataImport::fieldsFor($import->import_type)
            : [];

        return [
            'column_mapping' => ['required', 'array', 'min:1'],
            // The KEYS, which is what reaches Model::create().
            'column_mapping.*' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Refuse a mapping that names anything outside the import type's fields.
     *
     * Laravel validates array VALUES with `column_mapping.*`; the keys need
     * their own pass, and the error is attributed to the offending key so the
     * screen can point at it.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $import = $this->route('import');

            if (! $import instanceof DataImport) {
                return;
            }

            $allowed = DataImport::fieldsFor($import->import_type);

            foreach (array_keys((array) $this->input('column_mapping', [])) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add(
                        "column_mapping.{$field}",
                        "\"{$field}\" is not a field a {$import->import_type} import can set.",
                    );
                }
            }
        });
    }
}
