<?php

namespace Tests\Feature\Characterisation;

use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Services\Campaigns\CampaignDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The campaign dashboard's four figures (migration Phase 4.5).
 *
 * Written against the RUNNING BLADE SCREEN before CampaignDashboardService
 * existed: a throwaway test hit risk.campaigns.dashboard and dumped
 * $response->original->getData(), and the numbers asserted here are the numbers
 * that came back. That is the only way to be sure an extraction is faithful —
 * 3.5 spent a day on a figure that "should" have been 50 and had always been 38.
 *
 * The fixture is deliberately awkward:
 *   - four campaigns, two of them running (active + in_progress);
 *   - Alpha: 3 assignments — one approved, one submitted, one pending;
 *   - Bravo: 2 assignments — one submitted, one under_review;
 *   - Charlie (draft) and Delta (closed): no assignments at all.
 *
 * which makes every one of the four tiles say something different, and puts an
 * `under_review` assignment in front of the two figures that disagree about it.
 */
class CampaignDashboardFiguresTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('campaign.view');
        $this->actor->givePermissionTo('campaign.view');

        $alpha = $this->campaign('Alpha', 'active', '2026-01-01 09:00:00');
        $bravo = $this->campaign('Bravo', 'in_progress', '2026-01-02 09:00:00');
        $this->campaign('Charlie', 'draft', '2026-01-03 09:00:00');
        $this->campaign('Delta', 'closed', '2026-01-04 09:00:00');

        $this->assign($alpha, 'approved');
        $this->assign($alpha, 'submitted');
        $this->assign($alpha, 'pending');

        $this->assign($bravo, 'submitted');
        $this->assign($bravo, 'under_review');

        $alpha->recalculateProgress();
        $bravo->recalculateProgress();
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_four_tiles_are_what_the_blade_screen_showed(): void
    {
        $stats = app(CampaignDashboardService::class)->stats();

        // Running campaigns: Alpha (active) and Bravo (in_progress). A draft
        // campaign is not open for response and a closed one is finished.
        $this->assertSame(2, $stats['activeCampaigns']);

        // Every campaign of the tenant, whatever its status.
        $this->assertSame(4, $stats['totalCampaigns']);

        // Alpha's one submitted plus Bravo's one submitted. Bravo's
        // under_review assignment is NOT counted here — see the test below.
        $this->assertSame(2, $stats['pendingReview']);

        // Averaged over the RUNNING campaigns only, and completion_pct counts
        // approved assignments alone: Alpha is 1 of 3 = 33.33, Bravo is 0 of 2.
        // (33.33 + 0) / 2 = 16.665, which the screen renders as "16.7%".
        $this->assertSame(16.665, round($stats['avgCompletion'], 4));
    }

    /**
     * The disagreement, pinned so that resolving it is a deliberate act.
     *
     * The "Pending Review" tile counts 'submitted'. The progress bar directly
     * beneath it is drawn from AssessmentCampaign::AWAITING_REVIEW_STATUSES,
     * which is ['submitted', 'under_review']. Bravo holds one of each, so the
     * bar says two are awaiting review and the tile counts one of them.
     *
     * Nothing in the product writes 'under_review' to campaign_assignments
     * today, so this is dormant rather than live. Carried across unchanged.
     */
    #[Test]
    public function the_pending_review_tile_and_the_progress_bar_disagree_about_under_review(): void
    {
        $service = app(CampaignDashboardService::class);
        $bravo = AssessmentCampaign::where('title', 'Bravo')->firstOrFail();

        $this->assertSame(['submitted'], CampaignDashboardService::PENDING_REVIEW_STATUSES);
        $this->assertSame(['submitted', 'under_review'], AssessmentCampaign::AWAITING_REVIEW_STATUSES);

        $this->assertSame(2, $service->pendingReview());
        $this->assertSame(2, $bravo->progressBreakdown()['awaiting_review']);

        // Bravo contributes one of the tile's two and two of its own bar's two.
        $this->assertSame(
            1,
            $bravo->assignments()->whereIn('status', CampaignDashboardService::PENDING_REVIEW_STATUSES)->count()
        );
    }

    #[Test]
    public function the_recent_table_is_newest_first_with_its_progress_counts(): void
    {
        $recent = app(CampaignDashboardService::class)->recent();

        $this->assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], $recent->pluck('title')->all());

        $alpha = $recent->firstWhere('title', 'Alpha');
        $bravo = $recent->firstWhere('title', 'Bravo');

        // withProgressCounts() has to have supplied these, or progressBreakdown()
        // falls through to a query per row and the screen is N+1 again.
        $this->assertSame(3, (int) $alpha->assignments_count);
        $this->assertFalse($alpha->relationLoaded('assignments'));

        $this->assertSame(
            ['total' => 3, 'completed' => 1, 'awaiting_review' => 1, 'completed_pct' => 33.33, 'awaiting_review_pct' => 33.33],
            $alpha->progressBreakdown(),
        );

        $this->assertSame(
            ['total' => 2, 'completed' => 0, 'awaiting_review' => 2, 'completed_pct' => 0.0, 'awaiting_review_pct' => 100.0],
            $bravo->progressBreakdown(),
        );
    }

    /** A fresh tenant has no running campaign, and AVG() returns null there. */
    #[Test]
    public function an_organisation_with_nothing_running_reads_zero_rather_than_null(): void
    {
        AssessmentCampaign::query()->update(['status' => 'draft']);

        $stats = app(CampaignDashboardService::class)->stats();

        $this->assertSame(0, $stats['activeCampaigns']);
        $this->assertSame(0.0, $stats['avgCompletion']);
        $this->assertSame(4, $stats['totalCampaigns']);
    }

    /* ------------------------------------------------------------------ */

    private function campaign(string $title, string $status, string $createdAt): AssessmentCampaign
    {
        $campaign = AssessmentCampaign::create([
            'organization_id' => $this->organization->id,
            'campaign_code' => 'CAM-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            'title' => $title,
            'campaign_type' => 'rcsa',
            'status' => $status,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'created_by' => $this->actor->id,
        ]);

        // latest() orders on created_at, and four rows written in the same
        // second tie — spread them so "newest first" is an assertion rather
        // than a coincidence of insertion order.
        $campaign->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $campaign;
    }

    private function assign(AssessmentCampaign $campaign, string $status): CampaignAssignment
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU'.++$this->sequence,
            'name' => 'Unit '.$this->sequence,
        ]);

        return CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $unit->id,
            'respondent_id' => $this->actor->id,
            'due_date' => '2026-03-25',
            'status' => $status,
        ]);
    }
}
