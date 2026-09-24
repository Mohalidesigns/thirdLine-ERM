<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\ActivationLevel;
use App\Enums\Bcms\IncidentSeverity;
use App\Models\Bcms\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `incident-declaration.md` §2/§3 — declaration is never blocked by an
 * incomplete record. Only what the clause map treats as non-negotiable is
 * validated here: a title, a severity, and `detected_at <= now`.
 */
class DeclareBcmsIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.declare') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'title' => ['required', 'string', 'max:250'],
            'incident_type' => ['nullable', 'string', 'max:40'],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'activation_level' => ['nullable', Rule::enum(ActivationLevel::class)],
            'detected_at' => ['required', 'date', 'before_or_equal:now'],
            'business_unit_id' => [
                'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],
            'site_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_sites', 'id')->where('organization_id', $organizationId),
            ],
            'impacted_processes' => ['nullable', 'array'],
            'impacted_processes.*' => [
                'integer',
                Rule::exists('bcms_processes', 'id')->where('organization_id', $organizationId),
            ],
            'estimated_impact_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'declaration_reason' => ['nullable', 'string', 'max:2000'],
            'options_considered' => ['nullable', 'string', 'max:2000'],
            // Required only when the officer overrode the suggested band
            // (declaration screen §3) — the frontend decides when to show
            // the field; the server enforces it is filled whenever present.
            'severity_override_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
            // Gate 1 re-gate defect 9: a bare tenant-scoped `exists` let a
            // declaring officer activate ANY approved plan in the tenant,
            // including one their business unit cannot see, and with no
            // check that they hold `bcms.plan.activate` at all —
            // `IncidentService::declare()` calls `PlanActivationService::
            // activate()` unconditionally once an id is present. A closure
            // rule does both checks `Rule::exists()` cannot reach: a plain
            // `Illuminate\Database\Query\Builder` (which is what `exists()`
            // validates against) has no `visibleTo()` scope, and `exists()`
            // cannot see the acting user's permissions at all.
            'activate_plan_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->user() === null || ! $this->user()->can('bcms.plan.activate')) {
                        $fail('You do not have permission to activate a plan from this incident.');

                        return;
                    }

                    $visible = Plan::query()->where('organization_id', $this->user()->organization_id)
                        ->visibleTo($this->user())
                        ->whereKey($value)
                        ->exists();

                    if (! $visible) {
                        $fail('That plan does not exist or is not visible to you.');
                    }
                },
            ],
            'activation_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
