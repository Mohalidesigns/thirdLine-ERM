<?php

namespace App\Http\Requests\Tprm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The rules for the figures the module cannot ship a default for.
 *
 * EVERY FIELD IS NULLABLE AND CLEARING ONE IS A LEGITIMATE ACT. A tenant that
 * entered a shareholders' funds figure from the wrong year should be able to
 * take it out again; forcing them to leave a wrong number in place, because
 * the form insists on a value, is how a bad threshold becomes permanent.
 *
 * THE AS-AT DATE IS REQUIRED WITH THE FIGURE, NOT ALONGSIDE IT. Shareholders'
 * funds move with every audited account and the incident threshold derived
 * from them is only interpretable if the reader knows how old the basis is.
 * A figure with no date would show on the settings screen as current.
 */
class UpdateProgrammeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.admin') ?? false;
    }

    /**
     * Codes are upper-cased BEFORE validation, not after.
     *
     * An LEI, an ISO country and an ISO currency are all defined as upper
     * case, and the rules below are written that way. Normalising afterwards
     * would mean a user who typed a perfectly good identifier in lower case
     * got told it was invalid — and the screen's own input handler already
     * upper-cases, which would have hidden the defect from everything except
     * an API caller.
     */
    protected function prepareForValidation(): void
    {
        foreach (['lei', 'country', 'reporting_currency', 'shareholders_funds_currency'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ISO 17442: 20 alphanumeric characters, upper case.
            'lei' => ['nullable', 'string', 'size:20', 'regex:/^[A-Z0-9]{20}$/'],
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'competent_authority' => ['nullable', 'string', 'max:200'],
            'reporting_currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],

            'shareholders_funds' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'shareholders_funds_currency' => [
                Rule::requiredIf(fn () => $this->filled('shareholders_funds')),
                'nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/',
            ],
            'shareholders_funds_as_at' => [
                Rule::requiredIf(fn () => $this->filled('shareholders_funds')),
                'nullable', 'date', 'before_or_equal:today',
            ],

            'regulatory_contact_name' => ['nullable', 'string', 'max:200'],
            'regulatory_contact_title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lei.size' => 'A Legal Entity Identifier is exactly 20 characters. Leave it blank rather than '
                .'entering a partial one — the register prints "Not set", which is true.',
            'shareholders_funds_as_at.required' => 'Give the date this figure is as at. The CBN materiality '
                .'threshold derived from it is only interpretable if a reader knows how old the basis is.',
            'shareholders_funds_as_at.before_or_equal' => 'Shareholders\' funds are reported as at a date that '
                .'has passed.',
        ];
    }

    /**
     * The model payload, with money converted to the minor units every other
     * money column in this module stores.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $validated = $this->validated();

        $funds = $validated['shareholders_funds'] ?? null;

        return [
            'lei' => $this->upper($validated['lei'] ?? null),
            'country' => $this->upper($validated['country'] ?? null),
            'competent_authority' => $validated['competent_authority'] ?? null,
            'reporting_currency' => $this->upper($validated['reporting_currency'] ?? null),
            'shareholders_funds_minor' => $funds === null ? null : (int) round(((float) $funds) * 100),
            'shareholders_funds_currency' => $this->upper($validated['shareholders_funds_currency'] ?? null),
            'shareholders_funds_as_at' => $validated['shareholders_funds_as_at'] ?? null,
            'regulatory_contact_name' => $validated['regulatory_contact_name'] ?? null,
            'regulatory_contact_title' => $validated['regulatory_contact_title'] ?? null,
        ];
    }

    private function upper(?string $value): ?string
    {
        return $value === null || $value === '' ? null : strtoupper($value);
    }
}
