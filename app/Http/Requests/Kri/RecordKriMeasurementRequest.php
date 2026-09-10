<?php

namespace App\Http\Requests\Kri;

use App\Models\KeyRiskIndicator;
use Illuminate\Foundation\Http\FormRequest;

/** Enter a reading against a KRI (migration Phase 4.1). */
class RecordKriMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $kri = $this->route('kri');

        return $kri instanceof KeyRiskIndicator && $this->user()->can('recordMeasurement', $kri);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'measurement_date' => ['required', 'date'],
            'value' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
