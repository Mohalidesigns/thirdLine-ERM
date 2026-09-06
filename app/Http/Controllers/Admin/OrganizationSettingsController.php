<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrganizationProfileRequest;
use App\Http\Requests\Admin\OrganizationSettingsRequest;
use App\Http\Requests\Admin\RiskSettingsRequest;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Services\CurrencyService;
use App\Services\Scoring\ScoringProfileProvisioner;
use App\Support\RiskCalculationSettings;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

class OrganizationSettingsController extends Controller
{
    public function __construct(private readonly ScoringProfileProvisioner $provisioner) {}

    /**
     * The organisation settings screen (migration Phase 6.2).
     *
     * Three panels, and each one's fields are read by something. The two the
     * Blade screen carried that were not — regulatory thresholds and
     * notification preferences — are gone; see
     * OrganizationSettingsRequest's docblock for what replaced them and
     * `docs/migration/phase-6-notes/organisation-and-sso.md` for the evidence.
     */
    public function index()
    {
        $organization = $this->organization();
        Gate::authorize('viewSettings', $organization);

        $settings = (array) ($organization->settings ?? []);
        $risk = is_array($settings['risk'] ?? null) ? $settings['risk'] : [];
        $profile = $this->provisioner->ensureFor($organization);

        return Inertia::render('Admin/Settings/General', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'profile' => array_merge([
                'org_name' => $organization->name,
                'org_code' => $organization->short_name,
                'industry' => '',
                'country' => '',
                'regulatory_framework' => '',
            ], (array) ($settings['org_profile'] ?? [])),
            'riskSettings' => array_merge([
                'scoring_methodology' => 'qualitative',
                'probability_scale' => $profile->matrix_rows,
                'impact_scale' => $profile->matrix_cols,
                'calculation_method' => $profile->impact_aggregation,
                'review_frequency' => 'quarterly',
            ], (array) ($settings['risk_settings'] ?? [])),
            // The defaults shown are config/risk.php's, so an untouched field
            // shows the value the calculations are actually using rather than
            // an empty box.
            'calculation' => [
                'control_effectiveness' => RiskCalculationSettings::effectivenessMap($organization->id),
                'regulatory_reportable_threshold_ngn' => RiskCalculationSettings::regulatoryReportableThresholdNgn($organization->id),
            ],
            'platform' => [
                'reporting_currency' => app(CurrencyService::class)->reportingCurrency($organization->id),
                'default_fx_rate_type' => app(CurrencyService::class)->defaultRateType($organization->id),
                'mfa_required_roles' => array_values((array) ($settings['mfa_required_roles'] ?? [])),
            ],
            'options' => [
                'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
                'effectivenessBands' => OrganizationSettingsRequest::EFFECTIVENESS_BANDS,
                'rateTypes' => ['cbn_official', 'nafem', 'parallel', 'internal', 'custom'],
            ],
            'matrix' => [
                'rows' => $profile->matrix_rows,
                'cols' => $profile->matrix_cols,
                'bands' => $profile->rating_bands ?? [],
            ],
        ]);
    }

    /**
     * The institution's identifying details, printed on generated documents by
     * DocumentRenderer.
     */
    public function updateProfile(OrganizationProfileRequest $request)
    {
        $validated = $request->validated();
        $organization = $request->organization();

        $organization->update([
            'name' => $validated['org_name'],
            'settings' => array_merge($organization->settings ?? [], ['org_profile' => $validated]),
        ]);

        return back()->with('success', 'Organization profile updated successfully.');
    }

    /**
     * The scoring matrix.
     *
     * WP-05 TASK 3 — write through to the scoring profile. Until that release
     * these five values were stored and then read by nothing: a user could set
     * a 4×4 matrix, see a success message, and watch every screen carry on
     * rendering five columns. The profile is what the calculations and the heat
     * map actually read, so the save has to reach it or the screen is still
     * lying.
     */
    public function updateRiskSettings(RiskSettingsRequest $request)
    {
        $validated = $request->validated();
        $organization = $request->organization();

        $this->mergeSettings($organization, ['risk_settings' => $validated]);

        $profile = $this->provisioner->resize(
            $organization,
            (int) $validated['probability_scale'],
            (int) $validated['impact_scale'],
        );

        $profile->impact_aggregation = $validated['calculation_method'];
        $profile->save();

        ScoringProfile::flushResolutionCache();

        $bands = collect($profile->rating_bands)
            ->map(fn ($band) => "{$band['label']} {$band['min']}–{$band['max']}")
            ->implode(', ');

        // A resize re-rates the whole register. Saying so, with the new
        // boundaries, is the difference between a configuration change and a
        // surprise on tomorrow's dashboard.
        return back()->with(
            'success',
            "Risk scoring settings updated. The matrix is now {$profile->matrix_rows}×{$profile->matrix_cols} "
            ."and ratings are banded {$bands}. Existing risks are re-rated against the new bands."
        );
    }

    /**
     * The settings JSON the platform reads: control effectiveness bands, the
     * regulatory reporting threshold, reporting currency and rate type, and
     * the roles that must carry multi-factor authentication.
     *
     * The request builds the blob, because the nesting is what the readers
     * disagree about: `risk.*` for the calculation settings, top level for the
     * rest.
     */
    public function updateSettings(OrganizationSettingsRequest $request)
    {
        $organization = $request->organization();

        $this->mergeSettings($organization, $request->settings());

        // Memoised per organization for the life of a request. This one has
        // already read them, so a save without a flush would leave the
        // redirect's flash message quoting the old numbers.
        RiskCalculationSettings::flush($organization->id);

        return back()->with('success', 'Settings updated. The new values apply to the next calculation.');
    }

    private function organization(): Organization
    {
        return Organization::findOrFail(TenantContext::organizationId());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function mergeSettings(Organization $organization, array $values): void
    {
        $organization->update([
            'settings' => array_merge($organization->settings ?? [], $values),
        ]);
    }
}
