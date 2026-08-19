<?php

namespace App\Http\Controllers\Risk;

use App\Events\RcsaWorksheetSubmitted;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\RiskControlMapping;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RcsaController extends Controller
{
    /**
     * RCSA dashboard overview.
     */
    public function dashboard()
    {
        $orgId = TenantContext::organizationId();

        $totalAssessments = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $completedAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('last_assessment_date')
            ->where('last_assessment_date', '>=', now()->subMonths(12))
            ->count();
        $inProgressAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('last_assessment_date')
            ->where('last_assessment_date', '<', now()->subMonths(12))
            ->where('last_assessment_date', '>=', now()->subMonths(18))
            ->count();
        $notStartedAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNull('last_assessment_date')
            ->count();
        $overdueAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('last_assessment_date')
                    ->orWhere('last_assessment_date', '<', now()->subMonths(12));
            })
            ->count();
        $completionRate = $totalAssessments > 0
            ? (int) round(($completedAssessments / $totalAssessments) * 100)
            : 0;

        $businessUnits = BusinessUnit::where('organization_id', $orgId)
            ->withCount([
                'risks as total_risks' => function ($q) {
                    $q->where('status', 'active');
                },
                'risks as assessed' => function ($q) {
                    $q->where('status', 'active')
                        ->whereNotNull('last_assessment_date')
                        ->where('last_assessment_date', '>=', now()->subMonths(12));
                },
                'risks as high_risks' => function ($q) {
                    $q->where('status', 'active')->whereIn('residual_rating', ['High', 'Critical']);
                },
            ])
            ->orderByDesc('total_risks')
            ->get();

        $unitProgress = $businessUnits->map(function ($bu) {
            $progress = $bu->total_risks > 0 ? (int) round(($bu->assessed / $bu->total_risks) * 100) : 0;

            return (object) [
                'name' => $bu->name,
                'total_risks' => $bu->total_risks,
                'assessed' => $bu->assessed,
                'progress' => $progress,
                'high_risks' => $bu->high_risks,
                'control_gaps' => 0,
                'status' => $progress >= 80 ? 'Completed' : ($progress >= 50 ? 'In Progress' : 'Behind'),
                'due_date' => now()->addDays(30)->format('d M Y'),
            ];
        });

        $topRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderByRaw("FIELD(residual_rating,'Critical','High','Medium','Low')")
            ->orderByDesc('residual_score')
            ->limit(8)
            ->get()
            ->map(fn ($r) => (object) [
                'title' => $r->title,
                'business_unit' => optional($r->businessUnit)->name ?? '-',
                'inherent_rating' => $r->inherent_rating,
                'residual_rating' => $r->residual_rating,
                'control_effectiveness' => 'partially',
                'action_required' => 'Review control design and operating effectiveness',
            ]);

        $completionByUnitData = [
            'labels' => $unitProgress->pluck('name')->toArray(),
            'values' => $unitProgress->pluck('progress')->toArray(),
        ];
        $riskDistCounts = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('residual_rating, COUNT(*) c')
            ->groupBy('residual_rating')->pluck('c', 'residual_rating');
        $riskDistData = [
            'labels' => ['Critical', 'High', 'Medium', 'Low'],
            'values' => [
                (int) ($riskDistCounts['Critical'] ?? 0),
                (int) ($riskDistCounts['High'] ?? 0),
                (int) ($riskDistCounts['Medium'] ?? 0),
                (int) ($riskDistCounts['Low'] ?? 0),
            ],
        ];
        $effCounts = Control::where('organization_id', $orgId)
            ->selectRaw('effectiveness_rating r, COUNT(*) c')
            ->groupBy('r')->pluck('c', 'r');
        $controlEffData = [
            'labels' => ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'],
            'values' => [
                (int) ($effCounts['effective'] ?? 0),
                (int) ($effCounts['partially_effective'] ?? 0),
                (int) ($effCounts['ineffective'] ?? 0),
                (int) ($effCounts['not_tested'] ?? 0),
            ],
        ];

        return view('risk.rcsa.dashboard', compact(
            'totalAssessments', 'completedAssessments', 'inProgressAssessments',
            'notStartedAssessments', 'overdueAssessments', 'completionRate',
            'unitProgress', 'topRisks',
            'completionByUnitData', 'riskDistData', 'controlEffData'
        ));
    }

    /**
     * RCSA worksheet view - risk and control self-assessment matrix.
     */
    public function worksheet(Request $request)
    {
        $orgId = TenantContext::organizationId();

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

        // Campaigns the respondent can file this worksheet against. Leaving the
        // selector empty is allowed — storeWorksheet() resolves an open RCSA
        // campaign, or opens an ad-hoc one, rather than losing the submission.
        $campaigns = AssessmentCampaign::where('organization_id', $orgId)
            ->whereIn('status', ['active', 'in_progress'])
            ->orderByDesc('start_date')
            ->get();

        // A worksheet becomes a campaign assignment rather than a register
        // entry, so without this the respondent has no trace of the work they
        // filed from this very screen. Their own submissions only.
        $mySubmissions = CampaignAssignment::whereHas('campaign', fn ($q) => $q->where('organization_id', $orgId))
            ->where('respondent_id', auth()->id())
            ->has('responses')
            ->with(['campaign:id,campaign_code,title', 'businessUnit:id,name'])
            ->withCount('responses')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('risk.rcsa.worksheet', compact(
            'risks', 'businessUnits', 'categories', 'processes', 'campaigns', 'mySubmissions'
        ));
    }

    /**
     * RCSA controls view - control effectiveness assessment.
     */
    public function controls(Request $request)
    {
        $orgId = TenantContext::organizationId();

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

        // KPI tile counts (unfiltered, organization-wide)
        $effectivenessCounts = Control::where('organization_id', $orgId)
            ->selectRaw('effectiveness_rating, COUNT(*) as c')
            ->groupBy('effectiveness_rating')
            ->pluck('c', 'effectiveness_rating');

        $totalControls = (int) $effectivenessCounts->sum();
        $effectiveControls = (int) ($effectivenessCounts['effective'] ?? 0);
        $partialControls = (int) ($effectivenessCounts['partially_effective'] ?? 0);
        $ineffectiveControls = (int) ($effectivenessCounts['ineffective'] ?? 0);

        return view('risk.rcsa.controls', compact(
            'controls', 'businessUnits',
            'totalControls', 'effectiveControls', 'partialControls', 'ineffectiveControls'
        ));
    }

    /**
     * RCSA risk-control matrix view.
     */
    public function matrix(Request $request)
    {
        $orgId = TenantContext::organizationId();

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

        $matrixRisks = $mappings->pluck('risk')->filter()->unique('id')->sortBy('risk_code')->values();
        $matrixControls = $mappings->pluck('control')->filter()->unique('id')->sortBy('control_code')->values();

        // Pre-compute per-risk effectiveness for each control + overall
        // coverage so the Blade can read them straight off $risk.
        foreach ($matrixRisks as $risk) {
            $riskMappings = $mappings->where('risk_id', $risk->id);
            $perControl = [];
            foreach ($matrixControls as $control) {
                $m = $riskMappings->firstWhere('control_id', $control->id);
                if (! $m) {
                    $perControl[] = (object) ['control_id' => $control->id, 'effectiveness' => 'na'];

                    continue;
                }
                $rating = strtolower((string) ($m->control->effectiveness_rating ?? ''));
                $bucket = match ($rating) {
                    'effective' => 'effective',
                    'partially_effective' => 'partially',
                    'ineffective' => 'ineffective',
                    default => 'na',
                };
                $perControl[] = (object) ['control_id' => $control->id, 'effectiveness' => $bucket];
            }
            $risk->setAttribute('control_mappings', $perControl);
            $risk->setAttribute('control_coverage', $matrixControls->count() > 0
                ? (int) round($riskMappings->count() / $matrixControls->count() * 100)
                : 0);
        }

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.matrix', compact('matrixRisks', 'matrixControls', 'mappings', 'businessUnits'));
    }

    /**
     * Store RCSA worksheet entries.
     *
     * This method previously consisted of a comment reading "// Process
     * worksheet submission" followed by a redirect carrying a success message.
     * Every worksheet a respondent filled in was discarded, and the interface
     * told them it had been saved. That is the worst possible failure mode for
     * an assurance product: it manufactures evidence of an assessment that
     * never happened.
     *
     * A submission now lands in campaign_responses under a campaign
     * assignment, moves the assignment's status, refreshes the campaign's
     * completion counters and — on a real submission rather than a draft —
     * raises a domain event. All of it in one transaction, so a worksheet is
     * either wholly recorded or wholly rejected.
     */
    public function storeWorksheet(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            // Nullable so that an organisation which has not yet set up a
            // campaign can still record an assessment — see resolveCampaign().
            // A worksheet must never be silently dropped for want of
            // configuration.
            'campaign_id' => [
                'nullable', 'integer',
                Rule::exists('assessment_campaigns', 'id')->where('organization_id', $orgId),
            ],
            'business_unit_id' => [
                'required', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
            'process_id' => [
                'nullable', 'integer',
                Rule::exists('business_processes', 'id')->where('organization_id', $orgId),
            ],
            'assessment_date' => 'required|date',
            'action' => 'nullable|in:draft,submit',

            'risks' => 'required|array|min:1',
            'risks.*.description' => 'required|string|max:5000',
            'risks.*.category' => 'nullable|string|max:60',
            'risks.*.risk_id' => [
                'nullable', 'integer',
                Rule::exists('risks', 'id')->where('organization_id', $orgId),
            ],
            'risks.*.inherent_likelihood' => 'required|integer|min:1|max:5',
            'risks.*.inherent_impact' => 'required|integer|min:1|max:5',
            'risks.*.residual_likelihood' => 'required|integer|min:1|max:5',
            'risks.*.residual_impact' => 'required|integer|min:1|max:5',
            'risks.*.control_effectiveness' => 'nullable|in:effective,partially_effective,ineffective,not_tested',
            'risks.*.existing_controls' => 'nullable|string|max:5000',
            'risks.*.action_plan' => 'nullable|string|max:5000',
        ]);

        $isSubmission = ($validated['action'] ?? 'submit') === 'submit';

        [$assignment, $campaign, $responseCount] = DB::transaction(function () use ($validated, $orgId, $isSubmission) {
            $campaign = $this->resolveCampaign($validated['campaign_id'] ?? null, $orgId);

            // The unique index on (campaign_id, business_unit_id,
            // respondent_id) makes this the natural key: the same person
            // revising the same unit's worksheet updates their assignment
            // rather than creating a second one.
            $assignment = CampaignAssignment::firstOrNew([
                'campaign_id' => $campaign->id,
                'business_unit_id' => (int) $validated['business_unit_id'],
                'respondent_id' => auth()->id(),
            ]);

            if (! $assignment->exists) {
                $assignment->due_date = $campaign->end_date ?? now()->addDays(30);
                $assignment->started_at = now();
                $assignment->status = 'in_progress';
                $assignment->save();
            } elseif ($assignment->started_at === null) {
                $assignment->update(['started_at' => now()]);
            }

            // A resubmission replaces the previous lines rather than appending
            // to them, matching CampaignController::submitResponse. Without
            // this a corrected worksheet would leave the superseded scores in
            // place and double-count the unit.
            $assignment->responses()->delete();

            foreach ($validated['risks'] as $row) {
                $residualLikelihood = (int) $row['residual_likelihood'];
                $residualImpact = (int) $row['residual_impact'];
                $residualScore = $residualLikelihood * $residualImpact;
                $inherentScore = (int) $row['inherent_likelihood'] * (int) $row['inherent_impact'];

                CampaignResponse::create([
                    'assignment_id' => $assignment->id,
                    'risk_id' => $row['risk_id'] ?? null,
                    'control_id' => null,
                    // The scored position is the residual one: it is what the
                    // respondent is asserting about the environment as it
                    // stands. The inherent pair is preserved in
                    // questionnaire_data so the reduction stays auditable.
                    'likelihood_score' => $residualLikelihood,
                    'impact_score' => $residualImpact,
                    'overall_score' => $residualScore,
                    'rating' => $this->scoreToRating($residualScore),
                    'control_effectiveness' => $row['control_effectiveness'] ?? null,
                    'comments' => $row['action_plan'] ?? null,
                    'questionnaire_data' => [
                        'description' => $row['description'],
                        'category' => $row['category'] ?? null,
                        'process_id' => $validated['process_id'] ?? null,
                        'assessment_date' => $validated['assessment_date'],
                        'inherent_likelihood' => (int) $row['inherent_likelihood'],
                        'inherent_impact' => (int) $row['inherent_impact'],
                        'inherent_score' => $inherentScore,
                        'inherent_rating' => $this->scoreToRating($inherentScore),
                        'residual_likelihood' => $residualLikelihood,
                        'residual_impact' => $residualImpact,
                        'residual_score' => $residualScore,
                        'existing_controls' => $row['existing_controls'] ?? null,
                        'action_plan' => $row['action_plan'] ?? null,
                    ],
                ]);
            }

            if ($isSubmission) {
                $assignment->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
            }

            // Refreshes total_assignments, completed_assignments and
            // completion_pct on the campaign from the assignments themselves.
            $campaign->recalculateProgress();

            // A campaign with work in it is no longer a draft.
            if ($campaign->status === 'draft') {
                $campaign->update(['status' => 'in_progress', 'launched_at' => $campaign->launched_at ?? now()]);
            }

            return [$assignment, $campaign->fresh(), count($validated['risks'])];
        });

        if ($isSubmission) {
            RcsaWorksheetSubmitted::dispatch($assignment, $campaign, $responseCount);
        }

        $message = $isSubmission
            ? "Worksheet submitted for review: {$responseCount} risk line(s) recorded against campaign {$campaign->campaign_code}."
            : "Draft saved: {$responseCount} risk line(s) recorded against campaign {$campaign->campaign_code}.";

        // Land on the submission itself rather than back on an empty worksheet.
        // A worksheet becomes a campaign assignment, not a register risk, so a
        // respondent returned to the blank form had no way of telling where
        // their work had gone — or whether it had been kept at all.
        // Every role holding rcsa.submit also holds campaign.view today; the
        // fallback keeps the flash message meaningful if that ever stops
        // being true rather than bouncing the respondent into a 403.
        if (auth()->user()?->can('campaign.view')) {
            return redirect()
                ->route('risk.campaigns.submission', $assignment)
                ->with('success', $message);
        }

        return redirect()->route('risk.rcsa.worksheet')->with('success', $message);
    }

    /**
     * Find the campaign this worksheet belongs to, creating a standing ad-hoc
     * one if the organisation has not set any up.
     *
     * The alternative — rejecting the submission with "no campaign exists" —
     * would reintroduce the exact defect being fixed: a respondent's work
     * failing to persist because of configuration they cannot control. An
     * ad-hoc campaign is visible in the campaigns module like any other and can
     * be reviewed and closed normally.
     */
    private function resolveCampaign(?int $campaignId, int $orgId): AssessmentCampaign
    {
        if ($campaignId !== null) {
            return AssessmentCampaign::where('organization_id', $orgId)->findOrFail($campaignId);
        }

        $open = AssessmentCampaign::where('organization_id', $orgId)
            ->where('campaign_type', 'rcsa')
            ->whereIn('status', ['active', 'in_progress'])
            ->orderByDesc('start_date')
            ->first();

        if ($open) {
            return $open;
        }

        return AssessmentCampaign::create([
            'organization_id' => $orgId,
            'campaign_code' => ReferenceCodeService::generate(
                'assessment_campaigns', 'campaign_code', 'RCSA', 4, $orgId
            ),
            'title' => 'Ad-hoc RCSA '.now()->format('Y'),
            'description' => 'Created automatically to hold worksheet submissions made outside a scheduled campaign.',
            'campaign_type' => 'rcsa',
            'status' => 'in_progress',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'created_by' => auth()->id(),
            'launched_at' => now(),
        ]);
    }

    /**
     * Map a 1-25 score onto the register's four-band rating scale. Kept
     * identical to CampaignController::scoreToRating so the same worksheet
     * scores the same whichever screen recorded it.
     */
    /**
     * Delegates to RiskScoringService — there is one set of rating bands.
     *
     * This used to be a private copy using >= 6 for Medium while the service
     * used >= 5, so a risk scoring exactly 5 was Medium or Low depending on
     * which screen you were looking at. The service's bands win.
     */
    private function scoreToRating(int $score): string
    {
        return app(RiskScoringService::class)->calculateRating($score);
    }
}
