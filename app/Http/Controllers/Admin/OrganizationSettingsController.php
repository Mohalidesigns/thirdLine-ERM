<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Services\Scoring\ScoringProfileProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class OrganizationSettingsController extends Controller
{
    /**
     * Display organization settings page
     */
    public function index()
    {
        $organization = $this->organization();
        $settings = $organization->settings ?? [];

        return view('admin.settings.index', [
            'organization' => $organization,
            'orgSettings' => $settings['org_profile'] ?? [],
            'riskThresholds' => $settings['risk_thresholds'] ?? [],
            'riskSettings' => $settings['risk_settings'] ?? [],
            'notificationPrefs' => $settings['notification_prefs'] ?? [],
        ]);
    }

    /**
     * Update organization profile
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'org_name' => 'required|string|max:255',
            'org_code' => 'required|string|max:50',
            'industry' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'regulatory_framework' => 'required|string|max:255',
        ]);

        $organization = $this->organization();
        $organization->update([
            'name' => $validated['org_name'],
            'settings' => array_merge($organization->settings ?? [], ['org_profile' => $validated]),
        ]);

        return back()->with('success', 'Organization profile updated successfully.');
    }

    /**
     * Update regulatory thresholds
     */
    public function updateThresholds(Request $request)
    {
        $validated = $request->validate([
            'critical_threshold' => 'required|numeric|min:0|max:100',
            'high_threshold' => 'required|numeric|min:0|max:100',
            'medium_threshold' => 'required|numeric|min:0|max:100',
            'low_threshold' => 'required|numeric|min:0|max:100',
            'capital_requirement_percentage' => 'required|numeric|min:0|max:100',
        ]);

        $this->mergeSettings('risk_thresholds', $validated);

        return back()->with('success', 'Regulatory thresholds updated successfully.');
    }

    /**
     * Update risk scoring settings
     */
    public function updateRiskSettings(Request $request)
    {
        $validated = $request->validate([
            'scoring_methodology' => 'required|string',
            // A matrix below 3×3 cannot distinguish anything and one above
            // 10×10 is unreadable. The old bound of 1 allowed a 1×1 matrix.
            'probability_scale' => 'required|integer|min:3|max:10',
            'impact_scale' => 'required|integer|min:3|max:10',
            'calculation_method' => 'required|string|in:max,weighted,average,worst_two',
            'review_frequency' => 'required|string',
        ]);

        $this->mergeSettings('risk_settings', $validated);

        // WP-05 TASK 3 — write through to the scoring profile. Until this
        // release these five values were stored and then read by nothing: a
        // user could set a 4×4 matrix, see a success message, and watch every
        // screen carry on rendering five columns. The profile is what the
        // calculations and the heat map actually read, so the save has to
        // reach it or the screen is still lying.
        $organization = $this->organization();
        $provisioner = app(ScoringProfileProvisioner::class);

        $profile = $provisioner->resize(
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
     * Update notification preferences
     */
    public function updateNotificationPreferences(Request $request)
    {
        $validated = $request->validate([
            'critical_risk_notification' => 'boolean',
            'approval_required_notification' => 'boolean',
            'deadline_approaching_notification' => 'boolean',
            'report_ready_notification' => 'boolean',
            'notification_email' => 'required|email',
        ]);

        $this->mergeSettings('notification_prefs', $validated);

        return back()->with('success', 'Notification preferences updated successfully.');
    }

    private function organization(): Organization
    {
        $orgId = TenantContext::organizationId();

        return Organization::findOrFail($orgId);
    }

    private function mergeSettings(string $key, array $values): void
    {
        $organization = $this->organization();
        $organization->update([
            'settings' => array_merge($organization->settings ?? [], [$key => $values]),
        ]);
    }
}
