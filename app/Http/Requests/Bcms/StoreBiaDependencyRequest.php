<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\DependencyCriticality;
use App\Enums\Bcms\DependencyRelation;
use App\Enums\Bcms\DependencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording one dependency.
 *
 * `dependable_type` IS VALIDATED AGAINST THE ENUM, not against a free list. An
 * unregistered morph key would be refused by `Relation::enforceMorphMap()` at
 * write time anyway (ADR 0002), but a 500 is a worse answer to a bad select box
 * than a validation message.
 *
 * The id is NOT tenant-bound with `Rule::exists` here, and that is deliberate:
 * two of the seven types are not BCMS's — `vendors` is TPRM's and `users` is the
 * platform's — so a single `exists` rule would need a per-type table. The
 * controller resolves the model through the enum's own class, which applies that
 * model's own tenancy scope, and a target from another tenant comes back null
 * and is refused with a message.
 */
class StoreBiaDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.bia.complete') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dependable_type' => ['required', Rule::in(array_column(DependencyType::cases(), 'value'))],
            'dependable_id' => ['required', 'integer', 'min:1'],
            'dependency_type' => ['required', Rule::in(array_column(DependencyRelation::cases(), 'value'))],
            'criticality' => ['required', Rule::in(array_column(DependencyCriticality::cases(), 'value'))],
            'single_point_of_failure' => ['nullable', 'boolean'],
            'alternative_available' => ['nullable', 'boolean'],
            'recovery_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // A single point of failure WITH an alternative is a contradiction,
            // and it is the commonest way a SPOF register ends up under-counting:
            // somebody ticks both because each sounds true on its own.
            if ($this->boolean('single_point_of_failure') && $this->boolean('alternative_available')) {
                $validator->errors()->add(
                    'single_point_of_failure',
                    'A dependency cannot be both a single point of failure and have an alternative available. '
                    .'If there is a workable alternative it is not a single point of failure; if the alternative '
                    .'is not workable, say so in the recovery notes and leave it unticked.'
                );
            }
        });
    }
}
