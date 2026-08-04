<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
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
            'probability_scale' => 'required|integer|min:1|max:10',
            'impact_scale' => 'required|integer|min:1|max:10',
            'calculation_method' => 'required|string',
            'review_frequency' => 'required|string',
        ]);

        $this->mergeSettings('risk_settings', $validated);

        return back()->with('success', 'Risk scoring settings updated successfully.');
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
        $orgId = auth()->user()->organization_id ?? 1;

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
