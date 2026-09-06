<?php

namespace App\Http\Requests\Admin;

use App\Models\Organization;
use App\Services\CurrencyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The settings the platform actually reads (migration Phase 6.2).
 *
 * The screen this replaces had two panels — "Regulatory thresholds" and
 * "Notification preferences", ten fields between them — that were written to
 * `organizations.settings` and READ BY NOTHING. Not by a service, a job, a
 * command, a Blade template or a React page; the greps are in the module note.
 * A user set a critical threshold of 80, saw "updated successfully", and
 * nothing anywhere changed.
 *
 * Meanwhile five settings keys with real readers had no interface at all:
 *
 * | Key | Read by |
 * |---|---|
 * | `risk.control_effectiveness` | Control, RiskAssessmentControl, ControlEffectivenessService |
 * | `risk.regulatory_reportable_threshold_ngn` | LossEventService |
 * | `mfa_required_roles` | EnsureMfaVerified, SsoController, AuthenticatedSessionController |
 * | `reporting_currency` | CurrencyService, ScoringProfileProvisioner, DynamicDetail |
 * | `default_fx_rate_type` | CurrencyService |
 *
 * So the panels are swapped: every field here has a consumer, and every field
 * that had no consumer is gone. That is a deliberate departure from parity and
 * it is argued in `docs/migration/phase-6-notes/organisation-and-sso.md`.
 *
 * NOT here, deliberately: `risk.impact_aggregation` and `risk.impact_weights`.
 * They are read only by ScoringProfileProvisioner::ensureFor(), which runs
 * once when an organisation has no profile yet, so a form that changed them
 * later would be the same lie in a new place. The live control is
 * `calculation_method` on the risk panel, which writes to the profile.
 */
class OrganizationSettingsRequest extends FormRequest
{
    /** The effectiveness bands, in the order the form shows them. */
    public const EFFECTIVENESS_BANDS = [
        'effective',
        'mostly_effective',
        'partially_effective',
        'ineffective',
        'not_operating',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('updateSettings', $this->organization());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            // Percentages, and a band may legitimately be 0 (a control that is
            // not operating contributes nothing) or 100 (an organisation that
            // disagrees with the platform's view that no control is perfect).
            'control_effectiveness' => ['required', 'array'],

            'regulatory_reportable_threshold_ngn' => ['required', 'numeric', 'min:0', 'max:1000000000000'],

            'reporting_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'default_fx_rate_type' => ['required', 'string', 'in:cbn_official,nafem,parallel,internal,custom'],

            'mfa_required_roles' => ['present', 'array'],
            'mfa_required_roles.*' => ['string', Rule::in(Role::query()->pluck('name')->all())],
        ];

        foreach (self::EFFECTIVENESS_BANDS as $band) {
            $rules["control_effectiveness.{$band}"] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * The settings blob to merge, shaped the way the readers expect it.
     *
     * `risk` is a nested key because RiskCalculationSettings reads
     * `settings.risk.*`; the other three are top level because their readers
     * do. Getting this wrong is the failure mode the whole class exists to
     * prevent, so the shape is built once, here, rather than in the
     * controller.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();

        return [
            'risk' => [
                'control_effectiveness' => array_map(
                    'floatval',
                    array_intersect_key($validated['control_effectiveness'], array_flip(self::EFFECTIVENESS_BANDS)),
                ),
                'regulatory_reportable_threshold_ngn' => (float) $validated['regulatory_reportable_threshold_ngn'],
            ],
            'reporting_currency' => strtoupper($validated['reporting_currency']),
            'default_fx_rate_type' => $validated['default_fx_rate_type'],
            'mfa_required_roles' => array_values(array_unique($validated['mfa_required_roles'])),
        ];
    }

    public function organization(): Organization
    {
        return Organization::findOrFail(TenantContext::organizationId());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reporting_currency.regex' => 'The reporting currency must be a three-letter code, e.g. '
                .CurrencyService::DEFAULT_REPORTING_CURRENCY.'.',
        ];
    }
}
