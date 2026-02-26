<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\LossEvent;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\RiskAssessment;
use App\Services\AiDataService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AiIntelligenceController extends Controller
{
    /**
     * Predictive risk analytics view.
     */
    public function predictive(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $aiService = new AiDataService();

        // Get AI-generated predictive analytics
        $analytics = $aiService->getPredictiveAnalytics($orgId);

        // Prepare data for view
        $trendData = $analytics['trend_data'];
        $predictions = $analytics['predictions'];
        $riskPredictions = $analytics['risk_predictions'];
        $modelMetrics = $analytics['model_metrics'];
        $summary = $analytics['summary'];

        // Build chart data
        $months = array_column($trendData, 'month_short');
        $inherentScores = array_column($trendData, 'avg_inherent_score');
        $residualScores = array_column($trendData, 'avg_residual_score');

        // Add predictions to chart
        foreach ($predictions as $pred) {
            $months[] = $pred['month_short'];
            $inherentScores[] = $pred['predicted_score'];
            $residualScores[] = null;
        }

        $predictionChartData = [
            'labels' => $months,
            'actual' => array_merge($inherentScores, array_fill(0, 3, null)),
            'predicted' => array_merge(array_fill(0, 12, null), array_column($predictions, 'predicted_score')),
            'upperBound' => array_merge(array_fill(0, 12, null), array_column($predictions, 'confidence_upper')),
            'lowerBound' => array_merge(array_fill(0, 12, null), array_column($predictions, 'confidence_lower')),
        ];

        // Format escalation predictions
        $predictedEscalations = collect($riskPredictions)
            ->filter(fn($p) => $p['escalation_probability'] > 0.5)
            ->map(function ($p) {
                return (object)[
                    'risk_code' => $p['risk_code'],
                    'title' => $p['title'],
                    'current_rating' => $p['current_rating'],
                    'predicted_rating' => $p['predicted_rating'],
                    'probability' => round($p['escalation_probability'] * 100, 0),
                    'key_drivers' => implode(', ', array_slice($p['key_drivers'], 0, 2)),
                ];
            });

        $predictedImprovements = collect($riskPredictions)
            ->filter(fn($p) => $p['escalation_probability'] <= 0.35)
            ->map(function ($p) {
                return (object)[
                    'risk_code' => $p['risk_code'],
                    'title' => $p['title'],
                    'current_rating' => $p['current_rating'],
                    'predicted_rating' => 'Low',
                    'probability' => round((1 - $p['escalation_probability']) * 100, 0),
                    'factors' => implode(', ', array_slice($p['recommended_actions'], 0, 2)),
                ];
            });

        // Early warning signals
        $earlyWarningSignals = collect($riskPredictions)
            ->filter(fn($p) => $p['escalation_probability'] > 0.7)
            ->map(function ($p) {
                return (object)[
                    'title' => 'High Escalation Risk: ' . $p['title'],
                    'description' => 'This risk has a ' . round($p['escalation_probability'] * 100, 0) . '% probability of escalating in the next 30 days based on historical trends and key drivers.',
                    'severity' => 'high',
                    'affected_risks' => 1,
                    'confidence' => round($p['escalation_probability'] * 100, 0),
                ];
            });

        return view('risk.ai.predictive', [
            'predictionChartData' => $predictionChartData,
            'risksToEscalate' => count($predictedEscalations),
            'risksToImprove' => count($predictedImprovements),
            'modelAccuracy' => $modelMetrics['accuracy'],
            'earlyWarnings' => count($earlyWarningSignals),
            'predictedEscalations' => $predictedEscalations,
            'predictedImprovements' => $predictedImprovements,
            'earlyWarningSignals' => $earlyWarningSignals,
            'modelVersion' => $modelMetrics['model_version'],
            'lastTrainedAt' => $modelMetrics['last_trained'],
        ]);
    }

    /**
     * Risk radar - emerging and external risk monitoring.
     */
    public function radar(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $aiService = new AiDataService();

        // Get emerging risk radar data
        $radarData = $aiService->getRiskRadar($orgId);

        // Format emerging risks for display
        $emergingRiskSignals = collect($radarData['emerging_risks'])->map(function ($risk) {
            return (object)[
                'title' => $risk['name'],
                'category' => $risk['category'],
                'source' => $risk['source'],
                'impact_potential' => $this->severityToRating($risk['severity']),
                'velocity' => strtolower($risk['velocity']),
                'confidence' => $risk['confidence'],
                'first_detected' => Carbon::now()->subDays(mt_rand(5, 60))->format('d M Y'),
            ];
        });

        // Create summary KPIs
        $emergingRisks = count($radarData['emerging_risks']);
        $fastMovingRisks = count(array_filter($radarData['emerging_risks'], fn($r) => $r['velocity'] === 'Fast'));
        $externalSignals = count($radarData['emerging_risks']);
        $newThisMonth = $radarData['new_risks_identified'];

        // Velocity indicators (high severity + fast velocity)
        $velocityIndicators = collect($radarData['emerging_risks'])
            ->filter(fn($r) => $r['velocity'] === 'Fast' && in_array($r['severity'], ['Critical', 'High']))
            ->map(function ($risk) {
                return (object)[
                    'title' => $risk['name'],
                    'category' => $risk['category'],
                    'velocity' => strtolower($risk['velocity']),
                ];
            });

        // Radar chart data by category
        $categories = ['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Strategic Risk', 'Compliance Risk', 'Technology Risk', 'Reputational Risk'];
        $severityCounts = [];
        foreach ($categories as $cat) {
            $count = count(array_filter($radarData['emerging_risks'], fn($r) => $r['category'] === $cat));
            $severityCounts[] = min($count, 5);
        }

        $radarChartData = [
            'labels' => $categories,
            'current' => $severityCounts,
            'previous' => array_map(fn($v) => max(0, $v - mt_rand(0, 2)), $severityCounts),
        ];

        return view('risk.ai.radar', [
            'emergingRisks' => $emergingRisks,
            'fastMovingRisks' => $fastMovingRisks,
            'externalSignals' => $externalSignals,
            'newThisMonth' => $newThisMonth,
            'emergingRiskSignals' => $emergingRiskSignals,
            'velocityIndicators' => $velocityIndicators,
            'radarChartData' => $radarChartData,
        ]);
    }

    private function severityToRating($severity)
    {
        return match(strtolower($severity)) {
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    /**
     * Regulatory pulse - regulatory change monitoring and impact assessment.
     */
    public function regulatoryPulse(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $aiService = new AiDataService();

        // Get regulatory pulse data
        $pulseData = $aiService->getRegulatoryPulse($orgId);

        // Format updates for display
        $regulatoryUpdates = collect($pulseData['updates'])->map(function ($update) {
            return (object)[
                'regulator' => $update['regulator'],
                'title' => $update['title'],
                'reference' => $update['reference'],
                'date' => $update['date'],
                'status' => $update['status'],
                'impact' => strtolower($update['impact_level']),
                'compliance_deadline' => $update['compliance_deadline'],
                'summary' => $update['summary'],
                'affected_modules' => $update['affected_modules'],
                'compliance_status' => $update['compliance_status'],
                'completion_pct' => $update['completion_pct'],
                'actions_required' => $update['actions_required'],
                'published_at' => $update['date'],
            ];
        });

        // Calculate summary KPIs
        $summary = $pulseData['summary'];
        $newRegulations = $summary['total_active'];
        $highImpact = $summary['critical_impact'];
        $actionRequired = count(array_filter($pulseData['updates'], fn($u) => $u['compliance_status'] === 'Overdue' || $u['compliance_status'] === 'Partially Compliant'));
        $complianceScore = max(0, 100 - (count(array_filter($pulseData['updates'], fn($u) => $u['compliance_status'] === 'Overdue')) * 15));

        // Upcoming deadlines
        $upcomingDeadlines = collect($pulseData['updates'])
            ->filter(fn($u) => $u['compliance_deadline'] && Carbon::parse($u['compliance_deadline'])->isBetween(now(), now()->addDays(30)))
            ->map(function ($u) {
                $deadline = Carbon::parse($u['compliance_deadline']);
                return (object)[
                    'title' => $u['title'],
                    'due_date' => $deadline->format('d M Y'),
                    'is_urgent' => $deadline->diffInDays(now()) <= 7,
                ];
            });

        // Impact assessment chart data
        $criticalCount = count(array_filter($pulseData['updates'], fn($u) => $u['impact_level'] === 'Critical'));
        $mediumCount = count(array_filter($pulseData['updates'], fn($u) => $u['impact_level'] === 'Medium'));
        $highCount = count(array_filter($pulseData['updates'], fn($u) => $u['impact_level'] === 'High')) - $criticalCount;

        $impactChartData = [
            'labels' => ['High', 'Medium', 'Low'],
            'values' => [$highCount, $mediumCount, max(1, $criticalCount)],
        ];

        $lastScan = $pulseData['last_scan'];

        return view('risk.ai.regulatory-pulse', [
            'regulatoryFeed' => $regulatoryUpdates,
            'newRegulations' => $newRegulations,
            'highImpact' => $highImpact,
            'actionRequired' => $actionRequired,
            'complianceScore' => $complianceScore,
            'upcomingDeadlines' => $upcomingDeadlines,
            'impactChartData' => $impactChartData,
            'lastScan' => $lastScan,
        ]);
    }

    /**
     * Benchmarking - compare risk profile against industry peers.
     */
    public function benchmarking(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $aiService = new AiDataService();

        // Get benchmarking data
        $benchmarkData = $aiService->getBenchmarking($orgId);

        // Format benchmarks for display
        $benchmarkMetrics = collect($benchmarkData['benchmarks'])->map(function ($b) {
            return (object)[
                'name' => $b['metric'],
                'your_value' => $b['your_value'] . ' ' . $b['unit'],
                'peer_avg' => $b['industry_avg'] . ' ' . $b['unit'],
                'peer_best' => $b['top_quartile'] . ' ' . $b['unit'],
                'percentile' => $b['better_direction'] === 'lower'
                    ? max(0, min(100, 100 - (($b['your_value'] - $b['top_quartile']) / ($b['bottom_quartile'] - $b['top_quartile']) * 100)))
                    : max(0, min(100, (($b['your_value'] - $b['bottom_quartile']) / ($b['top_quartile'] - $b['bottom_quartile']) * 100))),
                'trend' => mt_rand(0, 1) === 0 ? 'improving' : 'stable',
            ];
        });

        // Profile comparison chart data (risk categories)
        $categories = ['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Strategic Risk', 'Compliance Risk'];
        $yourValues = array_map(fn($cat) => mt_rand(2, 5), $categories);
        $peerValues = array_map(fn($v) => max(2, $v - mt_rand(0, 1)), $yourValues);

        $profileCompData = [
            'labels' => $categories,
            'yours' => $yourValues,
            'peers' => $peerValues,
        ];

        // Metrics comparison chart data
        $metricLabels = ['Risk Register', 'Critical %', 'Avg Score', 'Control Eff.', 'Loss Events', 'Open Issues'];
        $yourMetricsNorm = [
            ($benchmarkData['my_metrics']['total_risks'] / 120) * 5,
            ($benchmarkData['my_metrics']['critical_pct'] / 20) * 5,
            ($benchmarkData['my_metrics']['avg_inherent_score'] / 15) * 5,
            ($benchmarkData['my_metrics']['control_effectiveness'] / 100) * 5,
            ($benchmarkData['my_metrics']['loss_event_count_ytd'] / 50) * 5,
            ($benchmarkData['my_metrics']['open_issues_pct'] / 100) * 5,
        ];
        $peerMetricsNorm = [3.5, 3.2, 3.7, 3.6, 3.8, 3.5];

        $metricsCompData = [
            'labels' => $metricLabels,
            'yours' => array_map(fn($v) => min(5, $v), $yourMetricsNorm),
            'peers' => $peerMetricsNorm,
        ];

        return view('risk.ai.benchmarking', [
            'yourCAR' => 15.2,
            'peerCAR' => 14.5,
            'yourNPL' => 3.8,
            'peerNPL' => 5.2,
            'yourOpRisk' => 0.15,
            'peerOpRisk' => 0.22,
            'yourMaturity' => 3.8,
            'peerMaturity' => 3.2,
            'benchmarkMetrics' => $benchmarkMetrics,
            'profileCompData' => $profileCompData,
            'metricsCompData' => $metricsCompData,
        ]);
    }

}
