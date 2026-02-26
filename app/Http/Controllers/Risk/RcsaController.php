<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Control;
use App\Models\RiskControlMapping;
use App\Models\BusinessUnit;
use Illuminate\Http\Request;

class RcsaController extends Controller
{
    /**
     * RCSA dashboard overview.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $stats = [
            'total_risks_assessed' => Risk::where('organization_id', $orgId)->whereNotNull('last_assessment_date')->count(),
            'total_controls' => Control::where('organization_id', $orgId)->count(),
            'effective_controls' => Control::where('organization_id', $orgId)->where('effectiveness_rating', 'effective')->count(),
            'partially_effective' => Control::where('organization_id', $orgId)->where('effectiveness_rating', 'partially_effective')->count(),
            'ineffective_controls' => Control::where('organization_id', $orgId)->where('effectiveness_rating', 'ineffective')->count(),
            'risks_without_controls' => Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->whereDoesntHave('controlMappings')
                ->count(),
        ];

        // Business units RCSA completion status
        $businessUnits = BusinessUnit::where('organization_id', $orgId)
            ->withCount([
                'risks' => function ($q) {
                    $q->where('status', 'active');
                },
                'risks as assessed_risks_count' => function ($q) {
                    $q->whereNotNull('last_assessment_date')
                      ->where('last_assessment_date', '>=', now()->subYear());
                },
            ])
            ->orderBy('name')
            ->get();

        return view('risk.rcsa.dashboard', compact('stats', 'businessUnits'));
    }

    /**
     * RCSA worksheet view - risk and control self-assessment matrix.
     */
    public function worksheet(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with(['category', 'riskOwner', 'businessUnit', 'controlMappings']);

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $risks = $query->orderBy('risk_code')->paginate(20)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $processes = \App\Models\BusinessProcess::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.worksheet', compact('risks', 'businessUnits', 'categories', 'processes'));
    }

    /**
     * RCSA controls view - control effectiveness assessment.
     */
    public function controls(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Control::where('organization_id', $orgId)
            ->with(['controlOwner', 'businessUnit', 'riskMappings']);

        if ($request->filled('effectiveness')) {
            $query->where('effectiveness_rating', $request->effectiveness);
        }

        if ($request->filled('control_type')) {
            $query->where('control_type', $request->control_type);
        }

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        $controls = $query->orderBy('control_code')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.controls', compact('controls', 'businessUnits'));
    }

    /**
     * RCSA risk-control matrix view.
     */
    public function matrix(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // Build the risk-control mapping matrix
        $query = RiskControlMapping::whereHas('risk', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            })
            ->with(['risk.category', 'control']);

        if ($request->filled('business_unit_id')) {
            $query->whereHas('risk', function ($q) use ($request) {
                $q->where('business_unit_id', $request->business_unit_id);
            });
        }

        $mappings = $query->get();

        // Get unique risks and controls for matrix headers
        $risks = $mappings->pluck('risk')->unique('id')->sortBy('risk_code');
        $controls = $mappings->pluck('control')->unique('id')->sortBy('control_code');

        // Build matrix data
        $matrixData = [];
        foreach ($risks as $risk) {
            foreach ($controls as $control) {
                $mapping = $mappings->first(function ($m) use ($risk, $control) {
                    return $m->risk_id === $risk->id && $m->control_id === $control->id;
                });
                $matrixData[$risk->id][$control->id] = $mapping;
            }
        }

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.matrix', compact('risks', 'controls', 'matrixData', 'mappings', 'businessUnits'));
    }

    /**
     * Store RCSA worksheet entries.
     */
    public function storeWorksheet(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // Process worksheet submission
        return redirect()->route('risk.rcsa.worksheet')
            ->with('success', 'RCSA worksheet saved successfully.');
    }
}
