<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\LossEvent;
use App\Models\Issue;
use App\Models\TreatmentPlan;
use App\Models\KeyRiskIndicator;
use App\Models\Control;
use App\Models\IcaapAssessment;
use App\Services\RiskAppetiteService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // ──────────────────────────────────────────────────────────
        // Section 1 — Executive KPI Strip
        // ──────────────────────────────────────────────────────────

        $totalActiveRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')->count();

        $criticalRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('residual_rating', 'Critical')->count();

        $highRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('residual_rating', 'High')->count();

        $kriBreaches = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')->count();

        $openIssues = Issue::where('organization_id', $orgId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])->count();

        // YTD Net Loss — try decimal column first, fallback to kobo
        $ytdNetLoss = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', now()->year)
            ->sum('net_loss_amount');

        if ($ytdNetLoss == 0) {
            $ytdNetLossKobo = LossEvent::where('organization_id', $orgId)
                ->whereYear('date_of_loss', now()->year)
                ->selectRaw('SUM(COALESCE(gross_loss_amount_kobo, 0) - COALESCE(insurance_recovery_kobo, 0) - COALESCE(other_recovery_kobo, 0)) as total')
                ->value('total') ?? 0;
            $ytdNetLoss = $ytdNetLossKobo / 100;
        }

        $overdueTreatments = TreatmentPlan::where('organization_id', $orgId)
            ->where('status', 'overdue')->count();

        // Capital Adequacy Ratio from latest ICAAP
        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();
        $carPercentage = $latestIcaap
            ? number_format(($latestIcaap->car_percentage ?? ($latestIcaap->car_actual ?? 0) * 100), 1)
            : null;

        // ──────────────────────────────────────────────────────────
        // Section 2 — Residual Risk Heatmap + Rating Distribution
        // ──────────────────────────────────────────────────────────

        $heatmapRaw = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('residual_likelihood')
            ->whereNotNull('residual_impact')
            ->selectRaw('residual_likelihood, residual_impact, COUNT(*) as count')
            ->groupBy('residual_likelihood', 'residual_impact')
            ->get();

        // Build 5×5 matrix: rows = likelihood 5→1 (top→bottom), cols = impact 1→5 (left→right)
        $heatmapData = array_fill(0, 5, array_fill(0, 5, 0));
        foreach ($heatmapRaw as $cell) {
            $lRow = 5 - (int) $cell->residual_likelihood;
            $iCol = (int) $cell->residual_impact - 1;
            if ($lRow >= 0 && $lRow < 5 && $iCol >= 0 && $iCol < 5) {
                $heatmapData[$lRow][$iCol] = (int) $cell->count;
            }
        }

        // Rating distribution for doughnut
        $ratingDistribution = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('residual_rating')
            ->selectRaw('residual_rating, COUNT(*) as count')
            ->groupBy('residual_rating')
            ->pluck('count', 'residual_rating')
            ->toArray();

        // Ensure all 4 rating levels exist for the chart
        $ratingDistribution = array_merge(
            ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0],
            $ratingDistribution
        );

        // ──────────────────────────────────────────────────────────
        // Section 3 — Risk Trend (12 months) + Loss Trend (12 months)
        // ──────────────────────────────────────────────────────────

        // Risk trend: Use risk_assessments grouped by month and overall_rating
        $twelveMonthsAgo = now()->subMonths(12)->startOfMonth();
        $riskTrendRaw = RiskAssessment::where('organization_id', $orgId)
            ->where('assessment_date', '>=', $twelveMonthsAgo)
            ->selectRaw("DATE_FORMAT(assessment_date, '%Y-%m') as month, overall_rating, COUNT(*) as count")
            ->groupBy('month', 'overall_rating')
            ->orderBy('month')
            ->get()
            ->groupBy('month');

        $riskTrendData = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->format('Y-m');
            $monthData = $riskTrendRaw->get($key, collect());
            $riskTrendData[] = [
                'label'    => $month->format('M'),
                'critical' => (int) $monthData->where('overall_rating', 'Critical')->sum('count'),
                'high'     => (int) $monthData->where('overall_rating', 'High')->sum('count'),
                'medium'   => (int) $monthData->where('overall_rating', 'Medium')->sum('count'),
                'low'      => (int) $monthData->where('overall_rating', 'Low')->sum('count'),
            ];
        }

        // Loss event trend: monthly gross vs net
        $lossTrendRaw = LossEvent::where('organization_id', $orgId)
            ->where('date_of_loss', '>=', $twelveMonthsAgo)
            ->selectRaw("DATE_FORMAT(date_of_loss, '%Y-%m') as month,
                SUM(COALESCE(gross_loss_amount, COALESCE(gross_loss_amount_kobo, 0) / 100)) as gross,
                SUM(COALESCE(net_loss_amount, (COALESCE(gross_loss_amount_kobo, 0) - COALESCE(insurance_recovery_kobo, 0) - COALESCE(other_recovery_kobo, 0)) / 100)) as net,
                COUNT(*) as event_count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $lossTrendData = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->format('Y-m');
            $data = $lossTrendRaw->get($key);
            $lossTrendData[] = [
                'label' => $month->format('M'),
                'gross' => $data ? round((float) $data->gross, 2) : 0,
                'net'   => $data ? round((float) $data->net, 2) : 0,
                'count' => $data ? (int) $data->event_count : 0,
            ];
        }

        // ──────────────────────────────────────────────────────────
        // Section 4 — KRI Status + Control Effectiveness
        // ──────────────────────────────────────────────────────────

        $kriStatusCounts = [
            'green' => KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'green')->count(),
            'amber' => KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'amber')->count(),
            'red'   => KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'red')->count(),
        ];

        $breachedKris = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')
            ->orderByDesc('current_value')
            ->limit(5)
            ->get();

        // Control effectiveness: group by percentage ranges
        $controlEffRaw = Control::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw("
                SUM(CASE WHEN effectiveness_pct >= 80 THEN 1 ELSE 0 END) as effective,
                SUM(CASE WHEN effectiveness_pct >= 50 AND effectiveness_pct < 80 THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN effectiveness_pct < 50 OR effectiveness_pct IS NULL THEN 1 ELSE 0 END) as ineffective
            ")
            ->first();

        $controlEffectiveness = [
            'Effective'           => (int) ($controlEffRaw->effective ?? 0),
            'Partially Effective' => (int) ($controlEffRaw->partial ?? 0),
            'Ineffective'         => (int) ($controlEffRaw->ineffective ?? 0),
        ];

        // ──────────────────────────────────────────────────────────
        // Section 5 — Risk Appetite + Treatment Progress
        // ──────────────────────────────────────────────────────────

        $appetiteService = new RiskAppetiteService();
        $appetiteData = $appetiteService->getDashboardData($orgId);

        $treatmentStatusDist = TreatmentPlan::where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $avgTreatmentProgress = TreatmentPlan::where('organization_id', $orgId)
            ->whereIn('status', ['in_progress', 'completed'])
            ->avg('progress_pct') ?? 0;

        // ──────────────────────────────────────────────────────────
        // Section 6 — Top 10 Risks by Residual Score
        // ──────────────────────────────────────────────────────────

        $topRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderByDesc('residual_score')
            ->limit(10)
            ->with(['category', 'riskOwner', 'treatmentPlans' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->get();

        // ──────────────────────────────────────────────────────────
        // Section 7 — Activity Feed
        // ──────────────────────────────────────────────────────────

        $recentLossEvents = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->limit(5)
            ->get()
            ->map(fn ($e) => (object) [
                'type'       => 'loss_event',
                'icon'       => 'report_problem',
                'icon_bg'    => 'bg-red-100',
                'icon_color' => 'text-red-600',
                'code'       => $e->event_reference ?? $e->id,
                'title'      => $e->title ?? $e->event_title ?? 'Loss Event',
                'amount'     => $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) / 100),
                'date'       => $e->date_of_loss ? Carbon::parse($e->date_of_loss) : $e->created_at,
                'url'        => url('/risk/loss-events/' . $e->id),
            ]);

        $recentIssues = Issue::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($i) => (object) [
                'type'       => 'issue',
                'icon'       => 'bug_report',
                'icon_bg'    => 'bg-orange-100',
                'icon_color' => 'text-orange-600',
                'code'       => $i->issue_code ?? $i->id,
                'title'      => $i->title ?? 'Issue',
                'amount'     => null,
                'date'       => $i->created_at,
                'url'        => url('/risk/issues/' . $i->id),
            ]);

        $recentAssessments = RiskAssessment::where('organization_id', $orgId)
            ->orderByDesc('assessment_date')
            ->limit(5)
            ->with('risk')
            ->get()
            ->map(fn ($a) => (object) [
                'type'       => 'assessment',
                'icon'       => 'fact_check',
                'icon_bg'    => 'bg-blue-100',
                'icon_color' => 'text-blue-600',
                'code'       => $a->risk->risk_code ?? $a->id,
                'title'      => 'Assessment: ' . ($a->risk->title ?? 'Risk Assessment'),
                'amount'     => null,
                'date'       => $a->assessment_date ? Carbon::parse($a->assessment_date) : $a->created_at,
                'url'        => url('/risk/assessments/' . $a->id),
            ]);

        $activityFeed = $recentLossEvents
            ->merge($recentIssues)
            ->merge($recentAssessments)
            ->sortByDesc('date')
            ->take(10)
            ->values();

        // ──────────────────────────────────────────────────────────
        // Section 8 — Regulatory & Compliance Summary
        // ──────────────────────────────────────────────────────────

        $cbnReportableCount = LossEvent::where('organization_id', $orgId)
            ->where('cbn_reportable', true)
            ->where(function ($q) {
                $q->where('cbn_notification_sent', false)
                  ->orWhereNull('cbn_notification_sent');
            })
            ->count();

        $regulatoryIssues = Issue::where('organization_id', $orgId)
            ->where('regulatory_reportable', true)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->count();

        $upcomingReviews = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereBetween('next_review_date', [now(), now()->addDays(30)])
            ->count();

        $overdueReviews = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('next_review_date')
            ->where('next_review_date', '<', now())
            ->count();

        // ──────────────────────────────────────────────────────────
        // Render
        // ──────────────────────────────────────────────────────────

        return view('risk.dashboard', compact(
            // Section 1 — KPIs
            'totalActiveRisks', 'criticalRisks', 'highRisks', 'kriBreaches',
            'openIssues', 'ytdNetLoss', 'overdueTreatments', 'carPercentage',
            // Section 2 — Heatmap + Rating
            'heatmapData', 'ratingDistribution',
            // Section 3 — Trends
            'riskTrendData', 'lossTrendData',
            // Section 4 — KRI + Controls
            'kriStatusCounts', 'breachedKris', 'controlEffectiveness',
            // Section 5 — Appetite + Treatments
            'appetiteData', 'treatmentStatusDist', 'avgTreatmentProgress',
            // Section 6 — Top Risks
            'topRisks',
            // Section 7 — Activity
            'activityFeed',
            // Section 8 — Regulatory
            'cbnReportableCount', 'upcomingReviews', 'overdueReviews', 'regulatoryIssues'
        ));
    }
}
