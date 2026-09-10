<?php

namespace App\Services\Regulatory;

use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The regulatory compliance dashboard's figures (migration Phase 5.3).
 *
 * Lifted out of RegulatoryComplianceController::dashboard(). This screen is
 * read as a statement of how the institution stands with its regulators, so
 * every figure on it has to mean what it says.
 *
 * THE DEFECT. `complianceRate` was
 * `$total > 0 ? round($compliant / $total * 100, 1) : 0`, and the tile printed
 * it as "Compliance Rate · 0%" in green. An institution that had recorded no
 * circulars at all therefore read **0% compliance** — a specific claim of total
 * non-compliance, made from no data whatsoever.
 *
 * It is the same defect WP-08 removed from CAR on the ICAAP screen ("0% CAR is
 * a specific, catastrophic claim about a bank's solvency — the one number the
 * screen must never invent") and Phase 5.2 removed from the ICAAP capital
 * add-on. A rate over an empty register is UNDEFINED, not zero, and the page
 * says so.
 */
class RegulatoryDashboardService
{
    /** Compliance states that still need work, as the register defines them. */
    private const OUTSTANDING = ['not_assessed', 'partially_compliant', 'non_compliant'];

    /**
     * @return array<string, mixed>
     */
    public function figures(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $totalCirculars = RegulatoryCircular::where('organization_id', $orgId)->count();
        $compliantCirculars = RegulatoryCircular::where('organization_id', $orgId)
            ->where('compliance_status', 'compliant')->count();

        return [
            'totalCirculars' => $totalCirculars,
            'compliantCirculars' => $compliantCirculars,
            'pendingCompliance' => RegulatoryCircular::where('organization_id', $orgId)
                ->whereIn('compliance_status', self::OUTSTANDING)->count(),

            // Null, never 0, over an empty register. See the class note.
            'complianceRate' => $totalCirculars > 0
                ? round($compliantCirculars / $totalCirculars * 100, 1)
                : null,

            'overdueCount' => RegulatoryDeadline::where('organization_id', $orgId)
                ->where('deadline_date', '<', now())
                ->whereNotIn('status', RegulatoryDeadline::NOT_CHASED)
                ->count(),

            'upcomingDeadlines' => $this->upcomingDeadlines($orgId),
            'recentCirculars' => $this->recentCirculars($orgId),
            'regulatorStats' => $this->regulatorStats($orgId),
        ];
    }

    /**
     * The next ten filings due, soonest first.
     *
     * @return Collection<int, mixed>
     */
    private function upcomingDeadlines(int $orgId): Collection
    {
        return RegulatoryDeadline::where('organization_id', $orgId)
            ->where('deadline_date', '>=', now())
            ->where('status', '!=', 'submitted')
            ->with('responsible')
            ->orderBy('deadline_date')
            ->take(10)
            ->get();
    }

    /**
     * @return Collection<int, RegulatoryCircular>
     */
    private function recentCirculars(int $orgId): Collection
    {
        return RegulatoryCircular::where('organization_id', $orgId)
            ->with('assignee')
            ->latest('date_issued')
            ->take(10)
            ->get();
    }

    /**
     * Circulars per regulator, and how many of each are compliant.
     *
     * `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` is portable SQL and stays; what
     * changes is that the counts are cast, because the drivers disagree about
     * whether an aggregate comes back as an int or a string.
     *
     * @return Collection<int, mixed>
     */
    private function regulatorStats(int $orgId): Collection
    {
        return RegulatoryCircular::where('organization_id', $orgId)
            ->selectRaw("regulator, COUNT(*) as total, SUM(CASE WHEN compliance_status = 'compliant' THEN 1 ELSE 0 END) as compliant_count")
            ->groupBy('regulator')
            ->orderBy('regulator')
            ->get()
            ->map(fn (RegulatoryCircular $row) => [
                'regulator' => $row->getAttribute('regulator'),
                'total' => (int) $row->getAttribute('total'),
                'compliant_count' => (int) $row->getAttribute('compliant_count'),
            ]);
    }
}
