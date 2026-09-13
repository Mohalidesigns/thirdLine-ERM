<?php

namespace App\Services\Rcsa;

use App\Models\AssessmentCampaign;
use App\Models\CampaignResponse;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskControlMapping;
use Illuminate\Support\Facades\DB;

/**
 * §13 step 1: "Inventory first. Before writing migrations, dump the legacy RCSA
 * schema and row counts, and record every screen and report that reads from it.
 * Anything reading legacy tables is a cutover dependency."
 *
 * THE LEGACY RCSA MODULE HAS NO TABLES OF ITS OWN, and that is the single most
 * important thing this inventory says. It is four screens computed over
 * `risks`, `controls` and `risk_control_mapping`, plus a write path that files
 * worksheet submissions into `campaign_responses` under an `rcsa` campaign.
 *
 * That changes what "migrating the legacy module" means, and it changes it in
 * both directions:
 *
 *   - There is no legacy schema to move. The MASTER DATA the v2 Universe needs
 *     already exists as the risk register; migrating it means DERIVING a
 *     governed inventory from the register, not copying rows between two
 *     rcsa-prefixed schemas.
 *
 *   - The shared tables must NOT be treated as legacy. `risks` and `controls`
 *     are the enterprise register that half the product reads. §13's
 *     instruction to make legacy tables read-only cannot be applied to them,
 *     and applying it would take the Risk Register, the KRI module, the control
 *     library and the board pack down with it. What actually has to close at
 *     cutover is the legacy WRITE PATH — one controller action.
 *
 * The counts are per business unit as well as in total, because §13's
 * reconciliation compares per-BU counts and a total that matches while the
 * units do not is the failure this is built to catch.
 */
class RcsaLegacyInventory
{
    /**
     * Campaigns the legacy worksheet writes into.
     *
     * `RcsaWorksheetService` creates them with this type and files every
     * submission underneath one, so this is the whole of the legacy module's
     * own data.
     */
    public const LEGACY_CAMPAIGN_TYPE = 'rcsa';

    /**
     * The complete inventory.
     *
     * @return array<string, mixed>
     */
    public function report(int $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'taken_at' => now()->toDateTimeString(),
            'finding' => 'The legacy RCSA module has no tables of its own. It reads the enterprise risk '
                .'register and writes worksheet submissions into assessment campaigns.',
            'sources' => $this->sources($organizationId),
            'per_business_unit' => $this->perBusinessUnit($organizationId),
            'campaigns' => $this->campaigns($organizationId),
            'readiness' => $this->readiness($organizationId),
            'dependencies' => $this->dependencies(),
        ];
    }

    /**
     * Row counts on every table the legacy module reads or writes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sources(int $organizationId): array
    {
        $campaignIds = $this->legacyCampaignIds($organizationId);

        return [
            'risks' => [
                'role' => 'Master data source for rcsa_register_risks. SHARED — the enterprise register.',
                'rows' => Risk::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->count(),
                'migratable' => $this->migratableRisks($organizationId)->count(),
                'shared' => true,
            ],
            'controls' => [
                'role' => 'Master data source for rcsa_register_controls, through risk_control_mapping. SHARED.',
                'rows' => Control::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->count(),
                'shared' => true,
            ],
            'risk_control_mapping' => [
                'role' => 'Which controls belong to which risk. SHARED.',
                // COUNTED THROUGH THE RISK, NOT THROUGH THE PIVOT'S OWN
                // `organization_id`. That column is not reliably populated:
                // `RiskControlMapping::using()` stamps it, but a pivot written
                // by a plain `attach()` or a seeder insert does not, and every
                // row in the demo estate has it null. Counting on it made the
                // inventory report zero mappings while the migration found
                // twelve — an inventory that disagrees with the migration it
                // exists to precede is worse than no inventory.
                'rows' => RiskControlMapping::query()->withoutGlobalScopes()
                    ->whereIn('risk_id', $this->riskIds($organizationId))
                    ->count(),
                'shared' => true,
            ],
            'assessment_campaigns' => [
                'role' => "Holds legacy worksheet submissions where campaign_type = 'rcsa'. SHARED with the campaign module.",
                'rows' => AssessmentCampaign::query()->withoutGlobalScopes()
                    ->where('organization_id', $organizationId)->count(),
                'legacy_rcsa_rows' => count($campaignIds),
                'shared' => true,
            ],
            'campaign_responses' => [
                'role' => 'The legacy module\'s own assessment data — one row per risk per submission.',
                'rows' => $this->responses($campaignIds)->count(),
                'shared' => false,
            ],
        ];
    }

    /**
     * Per-unit counts, which is what the reconciliation compares.
     *
     * @return list<array<string, mixed>>
     */
    public function perBusinessUnit(int $organizationId): array
    {
        $risks = $this->migratableRisks($organizationId)
            ->selectRaw('business_unit_id, count(*) as aggregate')
            ->groupBy('business_unit_id')
            ->pluck('aggregate', 'business_unit_id');

        $names = DB::table('business_units')
            ->where('organization_id', $organizationId)
            ->pluck('name', 'id');

        $rows = [];

        foreach ($risks as $unitId => $count) {
            $rows[] = [
                'business_unit_id' => (int) $unitId,
                'business_unit' => $names[$unitId] ?? '(unknown unit)',
                'risks' => (int) $count,
            ];
        }

        usort($rows, fn ($a, $b) => $b['risks'] <=> $a['risks']);

        return $rows;
    }

    /**
     * The legacy campaigns, each of which becomes one closed `Legacy` cycle.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(int $organizationId): array
    {
        return AssessmentCampaign::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('campaign_type', self::LEGACY_CAMPAIGN_TYPE)
            ->orderBy('start_date')
            ->get()
            ->map(fn (AssessmentCampaign $campaign) => [
                'id' => $campaign->id,
                'code' => $campaign->campaign_code,
                'title' => $campaign->title,
                'status' => $campaign->status,
                'period_start' => $campaign->start_date?->toDateString(),
                'period_end' => $campaign->end_date?->toDateString(),
                'responses' => $this->responses([$campaign->id])->count(),
                'scored_responses' => $this->responses([$campaign->id])
                    ->whereNotNull('likelihood_score')
                    ->whereNotNull('impact_score')
                    ->count(),
            ])
            ->all();
    }

    /**
     * What would stop a migration, counted before one is attempted.
     *
     * EVERY ONE OF THESE IS A ROW THAT LANDS IN THE EXCEPTIONS REPORT rather
     * than being dropped. Counting them here means the operator sees the size
     * of the manual triage before they start, not after.
     *
     * @return array<string, mixed>
     */
    public function readiness(int $organizationId): array
    {
        $all = Risk::query()->withoutGlobalScopes()->where('organization_id', $organizationId);

        return [
            'risks_without_business_unit' => (clone $all)->whereNull('business_unit_id')->count(),
            'risks_without_title' => (clone $all)->where(fn ($q) => $q->whereNull('title')->orWhere('title', ''))->count(),
            'risks_already_migrated' => DB::table('rcsa_register_risks')
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_risk_id')
                ->count(),
            'universe_rows_born_in_v2' => DB::table('rcsa_register_risks')
                ->where('organization_id', $organizationId)
                ->whereNull('legacy_risk_id')
                ->count(),
            'responses_without_risk' => $this->responses($this->legacyCampaignIds($organizationId))
                ->whereNull('risk_id')
                ->count(),
            // Not a migration blocker — the migration reads the pivot through
            // the risk — but a real defect in the shared table, and the
            // inventory is where somebody should see it.
            'control_mappings_missing_organization_id' => RiskControlMapping::query()->withoutGlobalScopes()
                ->whereIn('risk_id', $this->riskIds($organizationId))
                ->whereNull('organization_id')
                ->count(),
        ];
    }

    /**
     * Everything that reads the legacy module — §13's cutover dependencies.
     *
     * A HAND-MAINTAINED LIST, and honestly so. It could be derived by grepping
     * for the service classes, but the interesting dependencies are the ones
     * that read the SHARED tables through the legacy lens, and no static
     * analysis distinguishes those from the hundred other readers of `risks`.
     * What matters is that the list names what must be decided at cutover.
     *
     * @return list<array<string, string>>
     */
    public function dependencies(): array
    {
        return [
            [
                'what' => 'risk.rcsa.dashboard, .worksheet, .matrix, .controls',
                'reads' => 'risks, controls, risk_control_mapping',
                'at_cutover' => 'Redirect to the v2 equivalents. The screens themselves stay routable until the '
                    .'retention period ends, because the regulator may ask what the old module showed.',
            ],
            [
                'what' => 'risk.rcsa.worksheet.store (RcsaWorksheetService)',
                'reads' => 'writes assessment_campaigns, campaign_assignments, campaign_responses',
                'at_cutover' => 'THE ONE WRITE PATH. It must refuse after cutover — this is what "legacy becomes '
                    .'read-only" actually means here, since there are no legacy tables to lock.',
            ],
            [
                'what' => 'ExportController@rcsaMatrix (risk.export.rcsa-matrix)',
                'reads' => 'risks via RcsaService',
                'at_cutover' => 'Superseded by rcsa.exports.store. Keep until the retention period ends.',
            ],
            [
                'what' => 'The campaign module',
                'reads' => 'assessment_campaigns of every type, including rcsa',
                'at_cutover' => 'UNAFFECTED and must stay so. Migrating rcsa campaigns copies them; it does not '
                    .'move or delete them.',
            ],
            [
                'what' => 'Risk Register, KRI, control library, board pack, and every other reader of risks/controls',
                'reads' => 'risks, controls',
                'at_cutover' => 'UNAFFECTED. These tables are NOT legacy and must not be made read-only — doing so '
                    .'would take half the product down.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Shared queries */
    /* ------------------------------------------------------------------ */

    /**
     * Risks eligible to become universe rows.
     *
     * A business unit is required because `rcsa_register_risks.business_unit_id`
     * is NOT NULL and the whole module is organised by unit. One without a unit
     * is an exception, not a failure — it is reported and skipped.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Risk>
     */
    public function migratableRisks(int $organizationId)
    {
        return Risk::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('business_unit_id');
    }

    /**
     * Every risk id in the tenant — the reliable way into the pivot.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function riskIds(int $organizationId)
    {
        return DB::table('risks')->select('id')->where('organization_id', $organizationId);
    }

    /**
     * @return list<int>
     */
    public function legacyCampaignIds(int $organizationId): array
    {
        return AssessmentCampaign::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('campaign_type', self::LEGACY_CAMPAIGN_TYPE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Responses under the given campaigns.
     *
     * `campaign_responses` has no `organization_id` of its own — it reaches the
     * tenant through its assignment's campaign — so every query here is bounded
     * by campaign ids that were themselves resolved inside the tenant.
     *
     * @param  list<int>  $campaignIds
     * @return \Illuminate\Database\Eloquent\Builder<CampaignResponse>
     */
    public function responses(array $campaignIds)
    {
        return CampaignResponse::query()
            ->whereIn(
                'assignment_id',
                DB::table('campaign_assignments')->select('id')->whereIn('campaign_id', $campaignIds ?: [0]),
            );
    }
}
