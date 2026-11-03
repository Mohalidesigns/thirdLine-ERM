<?php

namespace App\Http\Requests\Kri;

use App\Http\Requests\Concerns\ValidatesRedBand;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\Period;
use App\Support\Kri\KriBandRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The body shared by the champion's save-draft and submit (ADR 0026 sections
 * 6.4 and 16):
 *
 *   {lines: [{line_id, value: number|null, comment: string|null, red_band?: {...}}]}
 *
 * in the order of the page props; errors come back as `lines.{i}.value` and
 * `lines.{i}.red_band.*`. The subclasses differ in two things only: the ability
 * that authorises them and whether a missing value or explanation is a 422.
 */
abstract class ReturnLinesRequest extends FormRequest
{
    use ValidatesRedBand;

    /** The KriReturnPolicy ability this request asserts. */
    abstract protected function ability(): string;

    /** Whether a value is required on every line, and a red line must be explained. */
    abstract protected function enforcesSubmission(): bool;

    public function authorize(): bool
    {
        $return = $this->route('return');

        return $return instanceof KriReturn && $this->user()->can($this->ability(), $return);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $return = $this->route('return');
        $orgId = TenantContext::organizationId();

        $rules = [
            'lock_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('kri_return_lines', 'id')
                    ->where('organization_id', $orgId)
                    ->where('kri_return_id', $return instanceof KriReturn ? $return->id : 0),
            ],
            'lines.*.value' => [
                $this->enforcesSubmission() ? 'required' : 'nullable',
                'numeric',
                'between:-100000000000000000000,100000000000000000000',
            ],
            'lines.*.comment' => ['nullable', 'string', 'max:1000'],
        ];

        $posted = (array) $this->input('lines', []);

        foreach (array_keys($posted) as $i) {
            if (is_int($i)) {
                $rules = array_merge($rules, $this->redBandShapeRules("lines.{$i}.red_band"));
            }
        }

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $return = $this->route('return');

                if (! $return instanceof KriReturn || $validator->errors()->isNotEmpty() || $return->isSubmitted()) {
                    // A submitted return is a lifecycle fact the controller answers with a flash.
                    return;
                }

                $posted = array_values((array) $this->input('lines', []));
                $lines = KriReturnLine::query()->where('kri_return_id', $return->id)->get()->keyBy('id');
                $kris = KeyRiskIndicator::query()->whereIn('id', $lines->pluck('key_risk_indicator_id')->all())->get()->keyBy('id');
                $periods = Period::query()->whereIn('id', $lines->pluck('period_id')->unique()->all())->get()->keyBy('id');
                $bands = app(KriBandRule::class);

                foreach ($posted as $i => $row) {
                    $line = $lines->get((int) ($row['line_id'] ?? 0));
                    $value = $row['value'] ?? null;

                    if ($line === null || $value === null || $value === '') {
                        continue;
                    }

                    $kri = $kris->get($line->key_risk_indicator_id);
                    $period = $periods->get($line->period_id);

                    if ($kri === null || $period === null) {
                        continue;
                    }

                    // The server decides red. A draft stores whatever it is
                    // given; only a submission owes the explanation.
                    if ($this->enforcesSubmission() && $bands->bandForReading($kri, $period, (float) $value) === 'red') {
                        $this->requireRedBand(
                            $validator,
                            $kri,
                            $row['red_band'] ?? null,
                            "lines.{$i}.red_band",
                            (int) $return->business_unit_id,
                        );
                    }
                }
            },
        ];
    }
}
