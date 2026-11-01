<?php

namespace App\Http\Requests\Kri;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Http\Requests\Concerns\ValidatesRedBand;
use App\Models\KeyRiskIndicator;
use App\Support\Kri\KriBandRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Update a KRI (migration Phase 4.1).
 *
 * `risk_id` is absent, exactly as it was: update() never accepted it, so
 * offering it would be an editable field that never saves.
 */
class UpdateKriRequest extends FormRequest
{
    use ValidatesConfiguredAttributes, ValidatesRedBand;

    public function authorize(): bool
    {
        $kri = $this->route('kri');

        return $kri instanceof KeyRiskIndicator && $this->user()->can('update', $kri);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'kri_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'measurement_unit' => ['required', 'string', 'max:100'],
            'measurement_frequency' => ['required', Rule::in(KeyRiskIndicator::FREQUENCIES)],
            'data_source' => ['nullable', 'string', 'max:255'],
            'kri_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'green_threshold' => ['nullable', 'numeric'],
            'red_threshold' => ['nullable', 'numeric'],
            'direction' => ['required', Rule::in(KeyRiskIndicator::DIRECTIONS)],
            'target_value' => ['nullable', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
            'formula' => ['nullable', 'string', 'max:1000'],
            'business_unit_id' => ['nullable', 'integer', Rule::exists('business_units', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            ...$this->redBandShapeRules(),
            ...$this->configuredAttributeRules('KeyRiskIndicator', $this->route('kri')),
        ];
    }

    /**
     * A RED target needs its explanation (ADR 0026 section 6.4). Red is decided
     * here, on the server, from the boundaries the user is posting - the form's
     * live badge is a mirror and is not trusted.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kri = $this->route('kri');
                $target = $this->input('target_value');

                if (! $kri instanceof KeyRiskIndicator || $validator->errors()->isNotEmpty()
                    || $target === null || $target === '' || ! is_numeric($target)) {
                    return;
                }

                $bands = app(KriBandRule::class);
                $rule = $bands->forBoundaries(
                    (string) $this->input('direction'),
                    $this->input('green_threshold'),
                    $this->input('red_threshold'),
                );

                if (! $bands->isRed($rule, (float) $target)) {
                    return;
                }

                // Amendment 1, A1.11: the explanation is required only when the
                // target MOVED INTO red, moved while red, or there is no open KRI
                // issue to carry it. Otherwise saving an unrelated field on a KRI
                // already explained in red does not re-ask.
                $old = $bands->forBoundaries(
                    $kri->threshold_direction === 'lower_worse' ? 'lower_is_worse' : 'higher_is_worse',
                    $kri->green_threshold,
                    $kri->red_threshold,
                );
                $oldTarget = $kri->target_value;
                $oldBand = $oldTarget === null ? null : $bands->bandOf($old, (float) $oldTarget);
                $targetChanged = $oldTarget === null
                    || round((float) $oldTarget, 6) !== round((float) $target, 6);

                $posted = (array) $this->input('red_band', []);
                $hasAny = collect($posted)->contains(fn ($v) => is_string($v) ? trim($v) !== '' : $v !== null);

                $required = $oldBand !== 'red'
                    || $targetChanged
                    || app(\App\Services\Kri\KriRedBandService::class)->openIssueFor($kri) === null;

                if ($required || $hasAny) {
                    $this->requireRedBand($validator, $kri, $posted === [] ? null : $posted);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('KeyRiskIndicator');
    }
}
