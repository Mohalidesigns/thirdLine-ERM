<?php

namespace App\Http\Requests\Kri;

use App\Http\Requests\Concerns\ValidatesRedBand;
use App\Models\KeyRiskIndicator;
use App\Services\KriMeasureBridge;
use App\Services\PeriodService;
use App\Support\Kri\KriBandRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Enter a reading against a KRI (migration Phase 4.1).
 *
 * ADR 0026 section 6.4: a RED reading must carry `red_band`. The record form on
 * Kri/Show embeds the same red-band component, so the validator never demands
 * fields its own form cannot post (development standard section 10).
 */
class RecordKriMeasurementRequest extends FormRequest
{
    use ValidatesRedBand;

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
            ...$this->redBandShapeRules(),
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kri = $this->route('kri');

                if (! $kri instanceof KeyRiskIndicator || $validator->errors()->isNotEmpty()) {
                    return;
                }

                // The same period resolution the write path uses, so the band
                // judged here is the band the engine will record.
                $period = app(PeriodService::class)->resolve(
                    (string) $this->input('measurement_date'),
                    app(KriMeasureBridge::class)->periodTypeFor($kri),
                    TenantContext::organizationId(),
                );

                $band = app(KriBandRule::class)->bandForReading($kri, $period, (float) $this->input('value'));

                if ($band === 'red') {
                    $this->requireRedBand($validator, $kri, $this->input('red_band'));
                }
            },
        ];
    }
}
