<?php

namespace App\Http\Requests\Admin;

use App\Models\ScoringProfile;
use App\Services\FormulaEvaluator;
use App\Support\Metadata\MetadataRules;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Define what a score means (migration Phase 6.4).
 *
 * ScoringProfileBuilder validated fourteen fields. Four more are validated
 * here, and each of the four was a way to save a profile the platform could
 * not use.
 *
 * 1. `residual_formula` was `nullable|string|max:500` — any string at all.
 *    RiskScoringService evaluates it through FormulaEvaluator and, when it
 *    throws, logs a warning and falls back to the platform default. So a typo
 *    did not break anything visibly: it silently reverted **every residual
 *    score in the register** to the default formula, and the only trace was a
 *    line in the log. It is checked with FormulaEvaluator::canEvaluate() now,
 *    against the same variables the scoring service puts in scope.
 *
 * 2. `applies_to_object_type_ids` had NO RULE AT ALL — it was read straight off
 *    the component into the `applies_to` blob. Another institution's object
 *    type could scope a profile, which is both a foreign id in the tenant's
 *    configuration and a profile that resolves for nothing.
 *
 * 3. `code` had no uniqueness check. ScoringProfile::resolveFor() picks from
 *    the profiles that apply; two with the same code is configuration nobody
 *    can reason about.
 *
 * 4. `impact_dimensions` was `required|array|min:1` with no element rule.
 *    A dimension the platform does not score contributes nothing and is
 *    invisible — the aggregation simply skips it.
 *
 * BAND CONTIGUITY stays where the Livewire component had it, in a dedicated
 * check with its own messages: bands must run from 1 to the maximum attainable
 * score with no gap and no overlap. A score that falls into no band renders as
 * an empty rating, which on a dashboard is indistinguishable from "not
 * assessed" — a silent failure with a governance consequence.
 */
class StoreScoringProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ScoringProfile::class);
    }

    protected function subject(): ?ScoringProfile
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->subject();

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_\-]*$/',
                Rule::unique('scoring_profiles', 'code')
                    ->where('organization_id', TenantContext::organizationId())
                    ->ignore($profile?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'matrix_rows' => ['required', 'integer', 'min:3', 'max:10'],
            'matrix_cols' => ['required', 'integer', 'min:3', 'max:10'],
            'impact_aggregation' => ['required', 'in:max,weighted,average,worst_two'],
            'residual_formula' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'is_default' => ['boolean'],

            'likelihood_scale' => ['required', 'array', 'min:1'],
            'likelihood_scale.*.value' => ['required', 'integer', 'min:1'],
            'likelihood_scale.*.label' => ['required', 'string', 'max:120'],
            'likelihood_scale.*.definition' => ['nullable', 'string', 'max:500'],

            'impact_scale' => ['required', 'array', 'min:1'],
            'impact_scale.*.value' => ['required', 'integer', 'min:1'],
            'impact_scale.*.label' => ['required', 'string', 'max:120'],
            'impact_scale.*.definition' => ['nullable', 'string', 'max:500'],

            'rating_bands' => ['required', 'array', 'min:1'],
            'rating_bands.*.code' => ['required', 'string', 'max:32'],
            'rating_bands.*.label' => ['required', 'string', 'max:60'],
            'rating_bands.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'rating_bands.*.min' => ['required', 'integer', 'min:0'],
            'rating_bands.*.max' => ['required', 'integer', 'min:0'],

            'impact_dimensions' => ['required', 'array', 'min:1'],
            'impact_dimensions.*' => ['string', Rule::in(ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS)],
            'dimension_weights' => ['array'],
            'dimension_weights.*' => ['numeric', 'min:0', 'max:100'],

            'applies_to_object_type_ids' => ['array'],
            'applies_to_object_type_ids.*' => ['integer', MetadataRules::objectType()],
            'applies_to_risk_types' => ['array'],
            'applies_to_risk_types.*' => ['string', 'max:120'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                // The band checks read min/max as integers and the formula
                // check needs a matrix size; neither is meaningful while the
                // basic rules are still failing.
                return;
            }

            $this->assertFormulaEvaluates($validator);
            $this->assertBandsCoverTheRange($validator);
        });
    }

    /**
     * The formula must evaluate with the variables the scoring service puts in
     * scope — `inherent`, `effectiveness` and `max_score`.
     */
    private function assertFormulaEvaluates(\Illuminate\Validation\Validator $validator): void
    {
        $formula = trim((string) $this->input('residual_formula', ''));

        if ($formula === '' || $formula === ScoringProfileTemplates::DEFAULT_RESIDUAL_FORMULA) {
            return;
        }

        $ok = app(FormulaEvaluator::class)->canEvaluate(
            $formula,
            ['organization_id' => TenantContext::organizationId()],
            ['inherent' => 10, 'effectiveness' => 50.0, 'max_score' => $this->maxScore()],
        );

        if (! $ok) {
            $validator->errors()->add('residual_formula', 'This formula cannot be evaluated. '
                .'Residual scores would silently fall back to the platform default. '
                .'`inherent`, `effectiveness` and `max_score` are the values in scope.');
        }
    }

    /**
     * Bands must be contiguous from 1 to the maximum attainable score.
     *
     * A gap renders as a blank rating, which reads as "not assessed"; an
     * overlap means the first match wins and the second band is dead
     * configuration.
     */
    private function assertBandsCoverTheRange(\Illuminate\Validation\Validator $validator): void
    {
        $bands = $this->normalisedBands();
        $maxScore = $this->maxScore();

        if ($bands === []) {
            return;
        }

        if ($bands[0]['min'] !== 1) {
            $validator->errors()->add('rating_bands', 'The lowest band must start at 1.');
        }

        if ($bands[count($bands) - 1]['max'] < $maxScore) {
            $validator->errors()->add('rating_bands', "The highest band must reach {$maxScore}, the top score a "
                .$this->input('matrix_rows').'×'.$this->input('matrix_cols').' matrix can produce.');
        }

        foreach ($bands as $index => $band) {
            if ($band['min'] > $band['max']) {
                $validator->errors()->add('rating_bands', "“{$band['label']}” starts above where it ends.");
            }

            if ($index > 0 && $band['min'] !== $bands[$index - 1]['max'] + 1) {
                $validator->errors()->add('rating_bands', 'There is a gap or an overlap between '
                    ."“{$bands[$index - 1]['label']}” and “{$band['label']}”. Every score from 1 to {$maxScore} "
                    .'must fall into exactly one band.');
            }
        }
    }

    public function maxScore(): int
    {
        return (int) $this->input('matrix_rows', 5) * (int) $this->input('matrix_cols', 5);
    }

    /**
     * The bands in ascending order, which is the order the contiguity check and
     * the stored profile both assume.
     *
     * @return list<array<string, mixed>>
     */
    public function normalisedBands(): array
    {
        $bands = array_map(fn (array $band) => [
            'code' => (string) $band['code'],
            'label' => (string) $band['label'],
            'color' => ($band['color'] ?? '') ?: '#64748b',
            'min' => (int) $band['min'],
            'max' => (int) $band['max'],
        ], array_values((array) $this->input('rating_bands', [])));

        usort($bands, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $bands;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        $appliesTo = array_filter([
            'object_type_ids' => ($validated['applies_to_object_type_ids'] ?? []) ?: null,
            'risk_types' => ($validated['applies_to_risk_types'] ?? []) ?: null,
        ]);

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => ($validated['description'] ?? '') ?: null,
            'applies_to' => $appliesTo ?: null,
            'likelihood_scale' => array_values($validated['likelihood_scale']),
            'impact_scale' => array_values($validated['impact_scale']),
            'impact_dimensions' => array_values($validated['impact_dimensions']),
            'impact_aggregation' => $validated['impact_aggregation'],
            'dimension_weights' => ($validated['dimension_weights'] ?? []) ?: null,
            'rating_bands' => $this->normalisedBands(),
            'residual_formula' => ($validated['residual_formula'] ?? '') ?: null,
            'matrix_rows' => $validated['matrix_rows'],
            'matrix_cols' => $validated['matrix_cols'],
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'is_system' => false,
            'effective_from' => ($validated['effective_from'] ?? '') ?: null,
            // Redefining what Critical means is a governance act, so the
            // profile records who signed it off rather than only who typed it.
            'approved_by' => $this->user()->id,
            'approved_at' => now(),
        ];
    }
}
