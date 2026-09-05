<?php

namespace App\Services\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use Illuminate\Support\Collection;

/**
 * The campaign dashboard's figures (migration Phase 4.5).
 *
 * Lifted out of CampaignController::dashboard(), which ran four separate
 * aggregate queries inline. Three of the four are over the same table filtered
 * the same way, so they are one grouped query here and the screen costs three
 * round trips rather than five.
 *
 * Pinned by tests/Feature/Characterisation/CampaignDashboardFiguresTest before
 * the extraction. The figures are carried across unchanged, including the one
 * that disagrees with itself:
 *
 *   `pendingReview` counts assignments whose status is 'submitted' ONLY, while
 *   AssessmentCampaign::AWAITING_REVIEW_STATUSES — which the progress bar
 *   directly underneath the tile is drawn from — is ['submitted',
 *   'under_review']. On a campaign holding both, the bar says two are awaiting
 *   review and the tile counts one. Nothing in the product ever writes
 *   'under_review' to campaign_assignments (the enum permits it; no code path
 *   sets it), so the disagreement is dormant rather than live, and which of the
 *   two is right is a product call. Documented in the module notes, not
 *   silently aligned.
 */
class CampaignDashboardService
{
    /**
     * Campaigns that are open for response. Not the same as
     * AWAITING_REVIEW_STATUSES: this is about the campaign, not an assignment.
     *
     * @var list<string>
     */
    public const RUNNING_STATUSES = ['active', 'in_progress'];

    /** Assignment statuses the "Pending Review" tile counts. */
    public const PENDING_REVIEW_STATUSES = ['submitted'];

    /** How many campaigns the "Recent Campaigns" table shows. */
    public const RECENT_LIMIT = 10;

    /**
     * The four KPI tiles.
     *
     * @return array{activeCampaigns: int, totalCampaigns: int, pendingReview: int, avgCompletion: float}
     */
    public function stats(): array
    {
        // One pass over the tenant's campaigns: the total, and — restricted to
        // the running ones — the count and the average completion the two other
        // tiles need.
        $row = AssessmentCampaign::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as running', self::RUNNING_STATUSES)
            ->selectRaw('AVG(CASE WHEN status IN (?, ?) THEN completion_pct END) as avg_completion', self::RUNNING_STATUSES)
            ->first();

        return [
            'activeCampaigns' => (int) ($row->running ?? 0),
            'totalCampaigns' => (int) ($row->total ?? 0),
            'pendingReview' => $this->pendingReview(),
            // AVG returns null when no campaign is running, which is a real
            // state on a fresh tenant — the tile has always shown 0% there.
            'avgCompletion' => (float) ($row->avg_completion ?? 0),
        ];
    }

    /**
     * Assignments handed in and waiting on a reviewer, across the tenant's
     * campaigns.
     *
     * campaign_assignments carries no organization_id, so the tenancy comes
     * from the campaign it hangs off. A bare whereHas('campaign') is enough:
     * AssessmentCampaign carries the OrganizationScope, so the subquery is
     * already restricted to this tenant — the explicit
     * `where('organization_id', $orgId)` the controller passed was the same
     * filter written twice.
     *
     * Every campaign counts, whatever its status. A submission sitting in a
     * campaign somebody closed is still a submission nobody has looked at.
     */
    public function pendingReview(): int
    {
        return CampaignAssignment::query()
            ->whereHas('campaign')
            ->whereIn('status', self::PENDING_REVIEW_STATUSES)
            ->count();
    }

    /**
     * The most recently opened campaigns, with the counts the two-segment
     * progress bar needs.
     *
     * withProgressCounts() is what keeps this off a query per row — see
     * AssessmentCampaign::progressBreakdown().
     *
     * @return Collection<int, AssessmentCampaign>
     */
    public function recent(int $limit = self::RECENT_LIMIT): Collection
    {
        return AssessmentCampaign::query()
            ->with(['creator', 'questionnaire'])
            ->withProgressCounts()
            ->latest()
            ->take($limit)
            ->get();
    }
}
