<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\DistributionMode;
use App\Support\Bcms\AudienceRule;
use App\Support\Bcms\PreferredWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The exercise definition wizard's payload.
 *
 * `frequency_per_year` IS CAPPED AT 52 BECAUSE THE ENGINE HAS TO PLACE THEM.
 * A definition asking for 300 occurrences in a year with 169 working days
 * generates a wall of `needs_scheduling` rows and teaches the user that the
 * status means nothing. Weekly is the sensible ceiling for a scheduled
 * exercise, and anything above it is a different kind of activity.
 *
 * EVERY FOREIGN KEY IS SCOPED TO THE TENANT. A bare `exists:` sees every
 * organisation's rows — the global scope is on the Eloquent model, not on the
 * validator's query builder — and an exercise bound to another bank's business
 * unit would resolve its audience from their contact roster (development
 * standard §4).
 */
class StoreExerciseDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'exercise_type_id' => [
                'required', 'integer',
                // Exercise types are a shared catalogue: system defaults carry
                // a null organisation and a tenant may add its own.
                Rule::exists('bcms_exercise_types', 'id')
                    ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)),
            ],
            'name' => ['required', 'string', 'max:200'],
            'business_unit_id' => [
                'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],
            'site_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_sites', 'id')->where('organization_id', $organizationId),
            ],
            'owner_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'facilitator_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'process_ids' => ['nullable', 'array', 'max:200'],
            'process_ids.*' => [
                'integer',
                Rule::exists('bcms_processes', 'id')->where('organization_id', $organizationId),
            ],
            'frequency_per_year' => ['required', 'integer', 'min:1', 'max:52'],
            'distribution_mode' => ['required', Rule::in(array_column(DistributionMode::cases(), 'value'))],
            'preferred_window' => ['nullable', 'array'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:90'],
            'min_notice_days' => ['required', 'integer', 'min:0', 'max:90'],
            'daily_reminder_enabled' => ['nullable', 'boolean'],
            'reminder_mode' => ['nullable', Rule::in(['discrete', 'digest'])],
            'readiness_gating' => ['nullable', 'boolean'],
            'unannounced' => ['nullable', 'boolean'],
            'mandatory' => ['nullable', 'boolean'],
            'regulatory_drivers' => ['nullable', 'array', 'max:20'],
            'regulatory_drivers.*' => ['string', 'max:60'],
            'objectives' => ['nullable', 'array', 'max:50'],
            'blackout_overrides' => ['nullable', 'array'],
            'default_audience_rule' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'suspended', 'retired'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $window = $this->input('preferred_window');

            if (is_array($window) && $window !== []) {
                try {
                    PreferredWindow::fromArray($window);
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('preferred_window', $e->getMessage());
                }
            }

            $audience = $this->input('default_audience_rule');

            if (is_array($audience) && $audience !== []) {
                try {
                    AudienceRule::fromArray($audience);
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('default_audience_rule', $e->getMessage());
                }
            }

            // `month_specific` with no months named would generate every
            // occurrence unscheduled. That is the honest fallback the engine
            // takes, but it is a configuration mistake rather than an
            // intention, and saying so here is cheaper than a wall of
            // `needs_scheduling` rows.
            if ($this->input('distribution_mode') === DistributionMode::MonthSpecific->value
                && ! is_array($window['months'] ?? null)) {
                $validator->errors()->add(
                    'preferred_window',
                    'A definition placed "in named months" has to name the months. Add them to the preferred window, '
                    .'or choose a different distribution.'
                );
            }

            // An unannounced exercise with participant reminders on is a
            // contradiction, and it is the one that ruins the exercise rather
            // than merely annoying somebody.
            if ($this->boolean('unannounced') && $this->boolean('daily_reminder_enabled')) {
                $validator->errors()->add(
                    'unannounced',
                    'An unannounced exercise cannot also send participants a daily countdown. Turn the reminders off, '
                    .'or make it announced — the facilitator still gets their own readiness ladder either way.'
                );
            }
        });
    }
}
