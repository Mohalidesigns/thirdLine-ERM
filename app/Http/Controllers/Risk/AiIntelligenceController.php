<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\EmergingRisk;
use App\Services\RegulatoryPulseService;
use App\Services\RiskForecastService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Risk Intelligence screens.
 *
 * Everything these three actions render is computed from the tenant's own
 * tables. The earlier version of this controller generated user-facing figures
 * from a random number generator at five call sites, and read a further seven
 * from a service that did the same; those are gone, along with the fabricated
 * model-accuracy panel and the Benchmarking screen, whose "peer values" were
 * random draws against a hardcoded 14-bank peer group that did not exist.
 *
 * The full before-and-after mapping is in docs/ai-number-provenance.md.
 *
 * Benchmarking is not disabled — it is removed. Genuine peer comparison needs
 * cross-tenant anonymised aggregates with a documented k-anonymity floor and
 * per-organization opt-in, which is a separate piece of work with its own data
 * governance. Until that exists there is no honest version of the screen.
 *
 * All three routes additionally sit behind `feature:ai_intelligence`, so they
 * 404 unless the environment has opted in.
 */
class AiIntelligenceController extends Controller
{
    /**
     * Forward view of the risk position, projected from the organisation's own
     * assessment history.
     */
    public function predictive(Request $request, RiskForecastService $forecasts)
    {
        $orgId = TenantContext::organizationId();

        $forecast = $forecasts->forecast($orgId);

        // Chart series. History and projection are separate datasets sharing a
        // label axis; the projection carries a band drawn from the fit's
        // standard error of prediction, and both start where the history ends
        // so the join is visible rather than smoothed over.
        $labels = array_merge(
            array_column($forecast['history'], 'label_short'),
            array_column($forecast['projection'], 'label_short')
        );

        $historyLength = count($forecast['history']);
        $projectionLength = count($forecast['projection']);

        $observed = array_merge(
            array_column($forecast['history'], 'mean_residual_score'),
            array_fill(0, $projectionLength, null)
        );

        // Anchor the projected line on the last observed month so the two
        // segments meet on the chart instead of floating apart.
        $lastObserved = null;
        foreach ($forecast['history'] as $row) {
            if ($row['mean_residual_score'] !== null) {
                $lastObserved = $row['mean_residual_score'];
            }
        }

        $projected = array_fill(0, $historyLength, null);
        $rangeHigh = $projected;
        $rangeLow = $projected;

        if ($projectionLength > 0) {
            $projected[$historyLength - 1] = $lastObserved;
            $rangeHigh[$historyLength - 1] = $lastObserved;
            $rangeLow[$historyLength - 1] = $lastObserved;
        }

        foreach ($forecast['projection'] as $p) {
            $projected[] = $p['projected_mean_residual_score'];
            $rangeHigh[] = $p['range_high'];
            $rangeLow[] = $p['range_low'];
        }

        return view('risk.ai.predictive', [
            'chart' => [
                'labels' => $labels,
                'observed' => $observed,
                'projected' => $projected,
                'rangeHigh' => $rangeHigh,
                'rangeLow' => $rangeLow,
            ],
            'fit' => $forecast['fit'],
            'projection' => $forecast['projection'],
            'signals' => $forecast['signals'],
            'watchlist' => $forecast['watchlist'],
            'deteriorating' => array_values(array_filter($forecast['watchlist'], fn ($w) => $w['delta'] > 0)),
            'improving' => array_values(array_filter($forecast['watchlist'], fn ($w) => $w['delta'] < 0)),
            'inputs' => $forecast['inputs'],
            'asAt' => $forecast['as_at'],
        ]);
    }

    /**
     * Emerging risk radar, plotted from the organisation's emerging risk
     * register.
     */
    public function radar(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $register = EmergingRisk::query()
            ->where('organization_id', $orgId)
            ->onRadar()
            ->with(['category', 'owner'])
            ->get()
            ->sortByDesc(fn (EmergingRisk $e) => $e->radar_score)
            ->values();

        // Scatter points: proximity on x, velocity on y, impact carried in the
        // point so the view can size and colour it.
        $points = $register->map(fn (EmergingRisk $e) => [
            'x' => (int) $e->proximity_score,
            'y' => (int) $e->velocity_score,
            'label' => $e->reference.' — '.$e->title,
            'impact' => $e->potential_impact,
        ])->values();

        // Count by category, over the categories the register actually uses.
        $byCategory = $register
            ->groupBy(fn (EmergingRisk $e) => $e->category?->name ?? 'Uncategorised')
            ->map->count();

        $byHorizon = [];
        foreach (EmergingRisk::HORIZONS as $horizon) {
            $byHorizon[$horizon] = $register->where('horizon', $horizon)->count();
        }

        return view('risk.ai.radar', [
            'register' => $register,
            'points' => $points,
            'categoryChart' => [
                'labels' => $byCategory->keys()->all(),
                'values' => $byCategory->values()->all(),
            ],
            'byHorizon' => $byHorizon,
            'totalOnRadar' => $register->count(),
            'fastMoving' => $register->where('velocity_score', '>=', 4)->count(),
            'imminent' => $register->where('proximity_score', '>=', 4)->count(),
            'highImpact' => $register->whereIn('potential_impact', ['High', 'Critical'])->count(),
            'neverReviewed' => $register->whereNull('last_reviewed_at')->count(),
            'staleReviews' => $register
                ->filter(fn (EmergingRisk $e) => $e->last_reviewed_at !== null
                    && $e->last_reviewed_at->lessThan(now()->subDays(90)))
                ->count(),
        ]);
    }

    /**
     * Regulatory pulse, driven by regulatory_circulars and
     * regulatory_deadlines.
     */
    public function regulatoryPulse(Request $request, RegulatoryPulseService $pulseService)
    {
        $orgId = TenantContext::organizationId();

        $pulse = $pulseService->pulse($orgId);

        return view('risk.ai.regulatory-pulse', [
            'feed' => $pulse['feed'],
            'upcomingDeadlines' => $pulse['upcoming_deadlines'],
            'impactMix' => $pulse['impact_mix'],
            'summary' => $pulse['summary'],
            'window' => $pulse['window'],
            'asAt' => $pulse['as_at'],
        ]);
    }
}
