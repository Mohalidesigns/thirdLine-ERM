<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\Process;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Proposing or editing a continuity strategy option.
 *
 * `process_id` IS RESOLVED THROUGH THE MODEL, NOT `Rule::exists`. A bare
 * `exists:bcms_processes,id` sees every tenant's rows — the global scope is on
 * the Eloquent model, not on the validator's query builder — and would let a
 * crafted request attach a strategy to another bank's process (development
 * standard §4). `Rule::exists` scoped by organisation is the other half of the
 * fix, and both are here because the first one is easy to remove by accident.
 *
 * COST IS TAKEN IN MINOR UNITS AND IN A NAMED CURRENCY. ₦42,000,000 typed as
 * `42000000` is either forty-two million naira or four hundred and twenty
 * thousand, and a strategy register where the two are mixed is one nobody can
 * total.
 */
class StoreBcmsStrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.strategy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'process_id' => [
                'required', 'integer',
                Rule::exists('bcms_processes', 'id')
                    ->where('organization_id', $this->user()?->organization_id),
            ],
            'strategy_type' => ['required', Rule::in(array_column(StrategyType::cases(), 'value'))],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cost_estimate_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'required_with:cost_estimate_minor'],
            'rto_achievable_hours' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'selection_rationale' => ['nullable', 'string', 'max:5000'],
            'resource_requirements' => ['nullable', 'array'],
            'resource_requirements.people' => ['nullable', 'array'],
            'resource_requirements.technology' => ['nullable', 'array'],
            'resource_requirements.facilities' => ['nullable', 'array'],
            'resource_requirements.information' => ['nullable', 'array'],
            'resource_requirements.suppliers' => ['nullable', 'array'],
            'resource_requirements.funding' => ['nullable', 'array'],
        ];
    }

    /** The process, resolved through the model so its tenancy scope applies. */
    public function process(): ?Process
    {
        return Process::query()->find($this->integer('process_id'));
    }
}
