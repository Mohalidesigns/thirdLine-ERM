<?php

namespace App\Http\Requests\Assessments;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\RiskCause;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * POST risk/assessments — the whole thirteen-step chain, validated in one
 * place (migration Phase 3.3).
 *
 * These are the rules RiskAssessmentController::validateChain() and
 * validateChainIntegrity() held inline, moved wholesale. Two things changed on
 * the way:
 *
 *  - Every foreign key is now checked INSIDE THE TENANT. `exists:users,id`
 *    accepted any user in the database, so a forged `actions.*.owner_id` could
 *    make somebody at another bank the owner of an action plan — and their
 *    name would then render on the treatment register.
 *  - `causes.*.cause_category_id` accepts a system category too: rows with a
 *    NULL organization_id are the shared Basel taxonomy every tenant inherits,
 *    and a rule that demanded the tenant's own id would reject the defaults
 *    the form offers.
 *
 * Axis bounds still come from the organization's scoring profile rather than a
 * hardcoded 1-5, so a tenant on a 4x4 or 10x10 matrix is validated against the
 * grid they actually use.
 */
class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('create', RiskAssessment::class) ?? false)
            && $this->assessedRisk() !== null;
    }

    /** The risk being assessed — chosen on the form for a new assessment. */
    public function assessedRisk(): ?Risk
    {
        return once(fn () => Risk::where('organization_id', TenantContext::organizationId())
            ->find($this->integer('risk_id')));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $risk = $this->assessedRisk();
        $profile = app(\App\Services\RiskScoringService::class)->profileForRisk($risk);
        $rows = $profile->matrix_rows;
        $cols = $profile->matrix_cols;
        $orgId = TenantContext::organizationId();

        $rules = [
            'risk_id' => ['required', 'integer'],
            'assessment_type' => ['required', Rule::in(['initial', 'periodic', 'event_driven', 'triggered', 'annual', 'full', 'targeted'])],
            'assessment_date' => ['required', 'date'],

            // Step 2 — Root Cause
            'causes' => ['array', 'max:50'],
            'causes.*.id' => ['nullable', 'integer'],
            'causes.*.description' => ['required_with:causes.*.cause_category_id', 'nullable', 'string', 'max:2000'],
            'causes.*.cause_category_id' => ['nullable', $this->causeCategoryRule($orgId)],
            'causes.*.source' => ['nullable', 'string', Rule::in(array_keys(RiskCause::SOURCES))],
            'causes.*.is_primary' => ['nullable', 'boolean'],

            // Step 3 — Likelihood
            'likelihood' => ['required', 'integer', 'min:1', "max:{$rows}"],
            'likelihood_rationale' => ['nullable', 'string', 'max:2000'],

            // Steps 6-7 — Existing Controls and their effectiveness
            'controls' => ['array', 'max:200'],
            'controls.*.design_effectiveness' => ['nullable', 'string', Rule::in(array_keys(RiskAssessmentControl::RATINGS))],
            'controls.*.operating_effectiveness' => ['nullable', 'string', Rule::in(array_keys(RiskAssessmentControl::RATINGS))],
            'controls.*.notes' => ['nullable', 'string', 'max:2000'],
            'controls.*.evidence_ref' => ['nullable', 'string', 'max:255'],

            // Step 8 — Residual Risk. Supplied by the form as the derived
            // values; only a change to them counts as an override.
            'residual_likelihood' => ['nullable', 'integer', 'min:1', "max:{$rows}"],
            'residual_impact' => ['nullable', 'integer', 'min:1', "max:{$cols}"],
            'residual_justification' => ['nullable', 'string', 'max:2000'],

            // Step 9 — Risk Treatment
            'treatment_strategy' => ['nullable', Rule::in(array_keys(RiskAssessment::TREATMENT_STRATEGIES))],

            // Steps 10-12 — Action Plan, Owner, Due Date
            'actions' => ['array', 'max:25'],
            'actions.*.action_title' => ['nullable', 'string', 'max:200'],
            'actions.*.action_description' => ['nullable', 'string', 'max:5000'],
            'actions.*.owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'actions.*.target_date' => ['nullable', 'date'],
            'actions.*.priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],

            // Step 13 — KRI
            'kri_ids' => ['array', 'max:25'],
            'kri_ids.*' => ['integer', Rule::exists('key_risk_indicators', 'id')->where('organization_id', $orgId)],

            'rationale' => ['required', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'action' => ['nullable', Rule::in(['draft', 'submit'])],
        ];

        // Step 4 — Impact, one rule per dimension the profile actually scores.
        // This is also how `impact_people` stops being accepted by a tenant
        // whose profile has nowhere to store it.
        foreach ($profile->dimensions() as $dimension) {
            $rules["impact_{$dimension}"] = ['nullable', 'integer', 'min:1', "max:{$cols}"];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'likelihood.required' => 'Likelihood is required — it is step 3 of the assessment.',
            'rationale.required' => 'An assessment rationale is required.',
        ];
    }

    /**
     * The rules that are about the chain holding together rather than about
     * any one field being well-formed. Was validateChainIntegrity().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $risk = $this->assessedRisk();

            if ($risk === null) {
                return;
            }

            $dimensions = app(\App\Services\RiskScoringService::class)->profileForRisk($risk)->dimensions();

            // Step 4: at least one impact dimension has to be scored, or there
            // is no inherent risk to speak of.
            $scored = collect($dimensions)->filter(fn (string $d) => filled($this->input("impact_{$d}")));

            if ($scored->isEmpty()) {
                $validator->errors()->add('impact_financial', 'Score at least one impact dimension.');
            }

            // Steps 10-12: an action plan is only an action plan once it has an
            // owner and a due date. A titled action with neither is a wish, and
            // letting it save is how a treatment register fills up with them.
            foreach ((array) $this->input('actions', []) as $index => $action) {
                if (blank($action['action_title'] ?? null)) {
                    continue;
                }

                if (blank($action['owner_id'] ?? null)) {
                    $validator->errors()->add("actions.{$index}.owner_id", 'An action plan needs an owner (step 11).');
                }

                if (blank($action['target_date'] ?? null)) {
                    $validator->errors()->add("actions.{$index}.target_date", 'An action plan needs a due date (step 12).');
                }
            }
        });
    }

    /**
     * The tenant's own cause categories plus the system ones every tenant
     * inherits (NULL organization_id).
     */
    protected function causeCategoryRule(int $orgId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('risk_cause_categories', 'id')->where(
            fn (Builder|\Illuminate\Database\Query\Builder $query) => $query
                ->where(fn ($inner) => $inner->where('organization_id', $orgId)->orWhereNull('organization_id'))
        );
    }
}
