<?php

namespace App\Services\Rcsa;

use App\Events\RcsaWorksheetSubmitted;
use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\User;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filing an RCSA worksheet (migration Phase 3.8).
 *
 * Lifted out of RcsaController::storeWorksheet() unchanged in behaviour. The
 * docblock that method carried is worth keeping in front of anyone editing
 * this: it once consisted of a comment reading "// Process worksheet
 * submission" followed by a redirect carrying a success message. Every
 * worksheet a respondent filled in was discarded while the interface told them
 * it had been saved — the worst failure mode an assurance product has, because
 * it manufactures evidence of an assessment that never happened.
 *
 * A submission lands in campaign_responses under a campaign assignment, moves
 * the assignment's status, refreshes the campaign's completion counters and —
 * on a real submission rather than a draft — raises a domain event. ALL OF IT
 * IN ONE TRANSACTION, so a worksheet is either wholly recorded or wholly
 * rejected. That atomicity is why this stayed a batch form rather than becoming
 * a cell-at-a-time editable grid; see the module notes.
 */
class RcsaWorksheetService
{
    public function __construct(private readonly RiskScoringService $scoring) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{assignment: CampaignAssignment, campaign: AssessmentCampaign, lines: int, submitted: bool}
     */
    public function file(array $validated, User $actor): array
    {
        $submitted = ($validated['action'] ?? 'submit') === 'submit';
        $orgId = TenantContext::organizationId();

        [$assignment, $campaign, $lines] = DB::transaction(function () use ($validated, $orgId, $actor, $submitted) {
            $campaign = $this->resolveCampaign($validated['campaign_id'] ?? null, $orgId, $actor);
            $assignment = $this->assignmentFor($campaign, (int) $validated['business_unit_id'], $actor);

            // A resubmission replaces the previous lines rather than appending
            // to them, matching CampaignController::submitResponse. Without
            // this a corrected worksheet would leave the superseded scores in
            // place and double-count the unit.
            $assignment->responses()->delete();

            foreach ($validated['risks'] as $row) {
                $this->recordLine($assignment, $row, $validated);
            }

            if ($submitted) {
                $assignment->update(['status' => 'submitted', 'submitted_at' => now()]);
            }

            // Refreshes total_assignments, completed_assignments and
            // completion_pct on the campaign from the assignments themselves.
            $campaign->recalculateProgress();

            // A campaign with work in it is no longer a draft.
            if ($campaign->status === 'draft') {
                $campaign->update([
                    'status' => 'in_progress',
                    'launched_at' => $campaign->launched_at ?? now(),
                ]);
            }

            return [$assignment, $campaign->fresh(), count($validated['risks'])];
        });

        if ($submitted) {
            RcsaWorksheetSubmitted::dispatch($assignment, $campaign, $lines);
        }

        return compact('assignment', 'campaign', 'lines', 'submitted');
    }

    /**
     * The unique index on (campaign_id, business_unit_id, respondent_id) makes
     * this the natural key: the same person revising the same unit's worksheet
     * updates their assignment rather than creating a second one.
     */
    private function assignmentFor(AssessmentCampaign $campaign, int $businessUnitId, User $actor): CampaignAssignment
    {
        $assignment = CampaignAssignment::firstOrNew([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $businessUnitId,
            'respondent_id' => $actor->id,
        ]);

        if (! $assignment->exists) {
            $assignment->due_date = $campaign->end_date ?? now()->addDays(30);
            $assignment->started_at = now();
            $assignment->status = 'in_progress';
            $assignment->save();
        } elseif ($assignment->started_at === null) {
            $assignment->update(['started_at' => now()]);
        }

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $validated
     */
    private function recordLine(CampaignAssignment $assignment, array $row, array $validated): void
    {
        $residualLikelihood = (int) $row['residual_likelihood'];
        $residualImpact = (int) $row['residual_impact'];
        $residualScore = $residualLikelihood * $residualImpact;
        $inherentScore = (int) $row['inherent_likelihood'] * (int) $row['inherent_impact'];

        CampaignResponse::create([
            'assignment_id' => $assignment->id,
            'risk_id' => $row['risk_id'] ?? null,
            'control_id' => null,
            // The scored position is the residual one: it is what the
            // respondent is asserting about the environment as it stands. The
            // inherent pair is preserved in questionnaire_data so the reduction
            // stays auditable.
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

    /**
     * Find the campaign this worksheet belongs to, creating a standing ad-hoc
     * one if the organisation has not set any up.
     *
     * The alternative — rejecting the submission with "no campaign exists" —
     * would reintroduce the exact defect described on this class: a
     * respondent's work failing to persist because of configuration they cannot
     * control. An ad-hoc campaign is visible in the campaigns module like any
     * other and can be reviewed and closed normally.
     */
    private function resolveCampaign(?int $campaignId, int $orgId, User $actor): AssessmentCampaign
    {
        if ($campaignId !== null) {
            return AssessmentCampaign::where('organization_id', $orgId)->findOrFail($campaignId);
        }

        $open = $this->openCampaigns($orgId)->first();

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
            'created_by' => $actor->id,
            'launched_at' => now(),
        ]);
    }

    /** @return Builder<AssessmentCampaign> */
    private function openCampaigns(int $orgId): Builder
    {
        return AssessmentCampaign::query()
            ->where('organization_id', $orgId)
            ->where('campaign_type', 'rcsa')
            ->whereIn('status', ['active', 'in_progress'])
            ->orderByDesc('start_date');
    }

    /**
     * Campaigns the respondent can file this worksheet against. Leaving the
     * selector empty is allowed — file() resolves an open RCSA campaign, or
     * opens an ad-hoc one, rather than losing the submission.
     *
     * @return list<array<string, mixed>>
     */
    public function selectableCampaigns(): array
    {
        return AssessmentCampaign::where('organization_id', TenantContext::organizationId())
            ->whereIn('status', ['active', 'in_progress'])
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (AssessmentCampaign $campaign) => [
                'id' => $campaign->id,
                'code' => $campaign->campaign_code,
                'title' => $campaign->title,
            ])
            ->all();
    }

    /**
     * The respondent's own recent worksheets.
     *
     * A worksheet becomes a campaign assignment rather than a register entry,
     * so without this the respondent has no trace of the work they filed from
     * this very screen.
     *
     * @return Collection<int, CampaignAssignment>
     */
    public function recentSubmissions(User $actor, int $limit = 5): Collection
    {
        return CampaignAssignment::whereHas(
            'campaign',
            fn (Builder $q) => $q->where('organization_id', TenantContext::organizationId()),
        )
            ->where('respondent_id', $actor->id)
            ->has('responses')
            ->with(['campaign:id,campaign_code,title', 'businessUnit:id,name'])
            ->withCount('responses')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Map a 1-25 score onto the register's four-band rating scale.
     *
     * Delegates to RiskScoringService — there is one set of rating bands. This
     * used to be a private copy using >= 6 for Medium while the service used
     * >= 5, so a risk scoring exactly 5 was Medium or Low depending on which
     * screen you were looking at.
     */
    private function scoreToRating(int $score): string
    {
        return $this->scoring->calculateRating($score);
    }
}
