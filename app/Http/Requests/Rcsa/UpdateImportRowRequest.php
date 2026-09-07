<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaTemplateWriter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct a cell on the import preview (rcsa.imports.rows.update).
 *
 * The payload is a partial map of RAW cell values, keyed by the template's own
 * field names — the same shape the parser produced. It is merged over the
 * row's existing raw values and the row is normalised and validated again from
 * scratch, so a correction made here is held to exactly the rules the file was
 * held to. There is no path by which editing on the preview produces a row the
 * upload would have rejected.
 */
class UpdateImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import', RcsaRegisterRisk::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1'],
            // Only the template's own columns. A key outside that set is a
            // client sending something the parser never produced.
            'values.*' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $allowed = array_keys(RcsaTemplateWriter::COLUMNS);

            foreach (array_keys((array) $this->input('values', [])) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add("values.{$field}", 'That is not a column of the RCSA template.');
                }
            }
        });
    }
}
