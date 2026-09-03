<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\RegulatoryFiling;
use App\Presenters\GridPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RegulatoryComplianceController extends Controller
{
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;

        $upcomingDeadlines = RegulatoryDeadline::where('organization_id', $orgId)
            ->where('deadline_date', '>=', now())
            ->where('status', '!=', 'submitted')
            ->orderBy('deadline_date')
            ->take(10)
            ->get();

        $overdueCount = RegulatoryDeadline::where('organization_id', $orgId)
            ->where('deadline_date', '<', now())
            ->whereNotIn('status', ['submitted', 'not_applicable'])
            ->count();

        $totalCirculars = RegulatoryCircular::where('organization_id', $orgId)->count();
        $pendingCompliance = RegulatoryCircular::where('organization_id', $orgId)->whereIn('compliance_status', ['not_assessed', 'partially_compliant', 'non_compliant'])->count();
        $compliantCirculars = RegulatoryCircular::where('organization_id', $orgId)->where('compliance_status', 'compliant')->count();
        $complianceRate = $totalCirculars > 0 ? round($compliantCirculars / $totalCirculars * 100, 1) : 0;

        $recentCirculars = RegulatoryCircular::where('organization_id', $orgId)
            ->with('assignee')
            ->latest('date_issued')
            ->take(10)
            ->get();

        $regulatorStats = RegulatoryCircular::where('organization_id', $orgId)
            ->selectRaw('regulator, COUNT(*) as total, SUM(CASE WHEN compliance_status = \'compliant\' THEN 1 ELSE 0 END) as compliant_count')
            ->groupBy('regulator')
            ->get();

        return view('risk.regulatory.dashboard', compact(
            'upcomingDeadlines', 'overdueCount', 'totalCirculars', 'pendingCompliance',
            'complianceRate', 'recentCirculars', 'regulatorStats'
        ));
    }

    public function calendar(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);

        $deadlines = RegulatoryDeadline::where('organization_id', $orgId)
            ->whereMonth('deadline_date', $month)
            ->whereYear('deadline_date', $year)
            ->with('responsible')
            ->orderBy('deadline_date')
            ->get();

        return view('risk.regulatory.calendar', compact('deadlines', 'month', 'year'));
    }

    public function deadlines(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $query = RegulatoryDeadline::where('organization_id', $orgId)->with('responsible');

        if ($request->filled('regulator')) {
            $query->where('regulator', $request->regulator);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $deadlines = $query->orderBy('deadline_date')->paginate(20);

        return view('risk.regulatory.deadlines', compact('deadlines'));
    }

    public function createDeadline()
    {
        $users = \App\Models\User::where('organization_id', auth()->user()->organization_id)->where('is_active', true)->orderBy('name')->get();

        return view('risk.regulatory.create-deadline', compact('users'));
    }

    public function storeDeadline(Request $request)
    {
        $request->validate([
            'regulator' => 'required|string|max:50',
            'report_type' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'deadline_date' => 'required|date',
            'frequency' => 'required',
        ]);

        RegulatoryDeadline::create([
            'organization_id' => auth()->user()->organization_id,
            'regulator' => $request->regulator,
            'report_type' => $request->report_type,
            'title' => $request->title,
            'description' => $request->description,
            'deadline_date' => $request->deadline_date,
            'frequency' => $request->frequency,
            'responsible_id' => $request->responsible_id,
            'status' => 'upcoming',
        ]);

        return redirect()->route('risk.regulatory.deadlines')->with('success', 'Regulatory deadline created.');
    }

    /**
     * WP-09: the circular register is the shared data grid
     * (App\Grids\Definitions\RegulatoryCircularsGrid), which owns the query,
     * the regulator/compliance filters and the pagination. Nothing on the page
     * outside the grid needs data.
     */
    public function circulars(Request $request, GridPresenter $presenter)
    {
        return Inertia::render('Regulatory/Circulars', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('circulars'), $request, $request->user()),
        ]);
    }

    public function createCircular()
    {
        $orgId = auth()->user()->organization_id;
        $users = \App\Models\User::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();
        $risks = \App\Models\Risk::where('organization_id', $orgId)->orderBy('title')->get();

        return view('risk.regulatory.create-circular', compact('users', 'risks'));
    }

    public function storeCircular(Request $request)
    {
        $request->validate([
            'regulator' => 'required|string|max:50',
            'circular_ref' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'date_issued' => 'required|date',
        ]);

        RegulatoryCircular::create([
            'organization_id' => auth()->user()->organization_id,
            'regulator' => $request->regulator,
            'circular_ref' => $request->circular_ref,
            'title' => $request->title,
            'date_issued' => $request->date_issued,
            'effective_date' => $request->effective_date,
            'summary' => $request->summary,
            'impact_level' => $request->impact_level ?? 'medium',
            'compliance_status' => 'not_assessed',
            'action_required' => $request->action_required,
            'assigned_to' => $request->assigned_to,
            'affected_risk_ids' => $request->affected_risk_ids,
        ]);

        return redirect()->route('risk.regulatory.circulars')->with('success', 'Regulatory circular recorded.');
    }

    public function showCircular(RegulatoryCircular $circular)
    {
        $circular->load('assignee');

        return view('risk.regulatory.show-circular', compact('circular'));
    }

    public function updateCompliance(Request $request, RegulatoryCircular $circular)
    {
        $request->validate([
            'compliance_status' => 'required|in:not_assessed,compliant,partially_compliant,non_compliant,not_applicable',
            'compliance_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $circular->update($request->only(['compliance_status', 'compliance_pct', 'action_required']));

        return back()->with('success', 'Compliance status updated.');
    }

    public function submitFiling(Request $request, RegulatoryDeadline $deadline)
    {
        $request->validate([
            'filing_date' => 'required|date',
            'document_ref' => 'nullable|string',
        ]);

        RegulatoryFiling::create([
            'deadline_id' => $deadline->id,
            'filing_date' => $request->filing_date,
            'filed_by' => auth()->id(),
            'status' => 'submitted',
            'document_ref' => $request->document_ref,
            'notes' => $request->notes,
        ]);

        $deadline->update(['status' => 'submitted']);

        return back()->with('success', 'Filing submitted.');
    }

    public function taxonomyIndex()
    {
        $orgId = auth()->user()->organization_id;
        $taxonomies = \App\Models\RiskTaxonomy::where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->with('children.children')
            ->orderBy('sort_order')
            ->get();

        return view('risk.regulatory.taxonomy', compact('taxonomies'));
    }

    public function storeTaxonomy(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'framework' => 'nullable|string|max:50',
        ]);

        $parentDepth = 0;
        if ($request->parent_id) {
            $parent = \App\Models\RiskTaxonomy::find($request->parent_id);
            $parentDepth = $parent ? $parent->depth + 1 : 0;
        }

        \App\Models\RiskTaxonomy::create([
            'organization_id' => auth()->user()->organization_id,
            'name' => $request->name,
            'description' => $request->description,
            'framework' => $request->framework,
            'parent_id' => $request->parent_id,
            'depth' => $parentDepth,
        ]);

        return back()->with('success', 'Taxonomy node added.');
    }
}
