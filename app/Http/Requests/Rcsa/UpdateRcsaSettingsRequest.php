<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaMethodology;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The §14 settings screen's one write.
 *
 * TWO REFUSALS HERE ARE THE POINT, and both would be silent data problems if
 * they were left to the service:
 *
 *   1. A retention period of 0 is REFUSED rather than accepted and reinterpreted.
 *      `RcsaRetentionService` reads 0 as "keep for ever", because guessing what
 *      somebody meant is not how to decide whether to delete their records —
 *      but a screen that swallowed the 0 and displayed "keep for ever" would be
 *      telling them their input was accepted when it was discarded. Say no.
 *
 *   2. Per-category appetite cannot be turned on with no ceilings configured.
 *      It would be legal — every category would fall back to the house ceiling
 *      — and it would be a mode that changes nothing, which reads as a broken
 *      switch rather than as an empty configuration.
 */
class UpdateRcsaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rcsa_settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bands = RcsaMethodology::active()?->bands->pluck('level')->all() ?? [];

        return [
            /* --- Q4 ------------------------------------------------------ */
            'appetite_mode' => ['required', Rule::in(['single', 'per_category'])],
            'appetite_ceiling_level' => ['required', 'string', Rule::in($bands)],

            'category_appetites' => ['array'],
            'category_appetites.*.risk_category' => ['required', 'string', Rule::in(Template::RISK_CATEGORIES)],
            'category_appetites.*.ceiling_level' => ['required', 'string', Rule::in($bands)],
            'category_appetites.*.note' => ['nullable', 'string', 'max:2000'],

            /* --- Q5 ------------------------------------------------------ */
            'treatment_override_approval_required' => ['required', 'boolean'],

            /* --- Q10 ----------------------------------------------------- */
            // Nullable means "keep for ever", which is the default and has to
            // stay expressible. `min:1` is what refuses the 0.
            'retention.export_files_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'retention.import_files_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'retention.closed_cycle_years' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retention.export_files_days.min' => 'A retention period of 0 would mean deleting a file the moment '
                .'it is written. Leave it blank to keep exports for ever.',
            'retention.import_files_days.min' => 'A retention period of 0 would mean deleting an upload the moment '
                .'it is written. Leave it blank to keep them for ever.',
            'retention.closed_cycle_years.min' => 'Leave it blank to keep closed cycles for ever.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /* --- A mode that would change nothing --------------------- */

            if ($this->input('appetite_mode') === 'per_category'
                && $this->input('category_appetites', []) === []) {
                $validator->errors()->add(
                    'appetite_mode',
                    'Set at least one category ceiling before switching to per-category appetite. '
                        .'With none set, every category falls back to the house ceiling and the mode does nothing.',
                );
            }

            /* --- The same category twice ------------------------------ */

            $categories = array_column($this->input('category_appetites', []), 'risk_category');

            if (count($categories) !== count(array_unique($categories))) {
                $validator->errors()->add(
                    'category_appetites',
                    'Each risk category can have only one ceiling.',
                );
            }
        });
    }
}
