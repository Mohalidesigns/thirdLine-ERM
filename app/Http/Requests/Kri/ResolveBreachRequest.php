<?php

namespace App\Http\Requests\Kri;

use App\Models\MeasureBreach;
use Illuminate\Foundation\Http\FormRequest;

/** Close a breach, resolved or never real (migration Phase 4.1). */
class ResolveBreachRequest extends FormRequest
{
    public function authorize(): bool
    {
        $breach = $this->route('breach');

        return $breach instanceof MeasureBreach && $this->user()->can('resolve', $breach);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'in:resolved,false_positive'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
