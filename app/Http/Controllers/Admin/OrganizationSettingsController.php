<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrganizationSettingsController extends Controller
{
    /**
     * Display organization settings page
     */
    public function index()
    {
        return view('admin.settings.index');
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

        // TODO: Update organization settings in database
        // For now just a placeholder
        session()->put('org_settings', $validated);

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

        // TODO: Update thresholds in database
        session()->put('risk_thresholds', $validated);

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

        // TODO: Update risk settings in database
        session()->put('risk_settings', $validated);

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

        // TODO: Update notification preferences in database
        session()->put('notification_prefs', $validated);

        return back()->with('success', 'Notification preferences updated successfully.');
    }
}
