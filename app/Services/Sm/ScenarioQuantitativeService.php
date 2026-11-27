<?php

namespace App\Services\Sm;

use App\Models\Sm\FrequencyDriver;
use App\Models\Sm\Scenario;
use App\Models\Sm\Scorecard;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * Quantitative model persistence and lifecycle service (spec 06–09, ADR 0027 Amendment 2).
 *
 * Encapsulates transactional writes, computation synchronization, and audit trail
 * recording for the four quantitative tabs: scorecard, frequency, impact, and insurance.
 */
class ScenarioQuantitativeService
{
    public function __construct(
        private readonly ScenarioService $scenarios,
        private readonly ScenarioComputationService $computation,
    ) {}

    /**
     * Update COSO Control Scorecard and recompute downstream impact.
     *
     * @param  array{control_nature: string, components: list<array<string, mixed>>}  $validated
     */
    public function updateScorecard(Scenario $scenario, array $validated, User $actor): Scorecard
    {
        $this->scenarios->refuseIfReadOnly($scenario);

        $beforeScore = $scenario->scorecard?->global_score !== null
            ? (string) $scenario->scorecard->global_score
            : null;

        $scResult = $this->computation->computeScorecard(
            $validated['control_nature'],
            $validated['components']
        );

        return DB::transaction(function () use ($scenario, $actor, $scResult, $beforeScore) {
            $scorecard = $scenario->scorecard()->updateOrCreate(
                ['sm_scenario_id' => $scenario->id],
                [
                    'organization_id' => $scenario->organization_id,
                    'control_nature' => $scResult['control_nature'],
                    'total_weight' => $scResult['total_weight'],
                    'total_weighted_score' => $scResult['total_weighted_score'],
                    'global_score' => $scResult['global_score'],
                    'updated_by' => $actor->id,
                ]
            );

            foreach ($scResult['components'] as $c) {
                $scorecard->components()->updateOrCreate(
                    [
                        'sm_scorecard_id' => $scorecard->id,
                        'component_code' => $c['component_code'],
                    ],
                    [
                        'organization_id' => $scenario->organization_id,
                        'efficiency_level' => $c['efficiency_level'],
                        'weighting_factor' => $c['weighting_factor'],
                        'score' => $c['score'],
                        'level_score' => $c['level_score'],
                        'adjusted_score' => $c['adjusted_score'],
                        'weighting_score' => $c['weighting_score'],
                        'weighted_score' => $c['weighted_score'],
                        'notes' => $c['notes'],
                        'sort_order' => $c['sort_order'],
                    ]
                );
            }

            $this->computation->recomputeAll($scenario);

            AuditTrailService::record(
                $scenario,
                'scorecard_updated',
                'global_score',
                $beforeScore,
                (string) $scResult['global_score']
            );

            return $scorecard;
        });
    }

    /**
     * Update Frequency Drivers and recompute downstream impact.
     *
     * @param  list<array<string, mixed>>  $drivers
     */
    public function updateFrequency(Scenario $scenario, array $drivers, User $actor): void
    {
        $this->scenarios->refuseIfReadOnly($scenario);

        $beforeCount = $scenario->frequencyDrivers()->count();

        DB::transaction(function () use ($scenario, $drivers, $beforeCount) {
            $scenario->frequencyDrivers()->delete();
            $scorecardId = $scenario->scorecard()->value('id');

            $sortOrder = 1;
            foreach ($drivers as $d) {
                // The link toggle (Bug 5). Absent → keep whatever the row's own fields say.
                if ($d['computation_role'] === 'control_score_complement' && array_key_exists('use_scorecard', $d)) {
                    $linked = (bool) $d['use_scorecard'];
                    $d['sm_scorecard_id'] = $linked ? $scorecardId : null;
                    if ($linked) {
                        $d['data_source'] = FrequencyDriver::DEFAULT_DATA_SOURCES['scorecard'];
                    } elseif (($d['data_source'] ?? null) === FrequencyDriver::DEFAULT_DATA_SOURCES['scorecard']) {
                        $d['data_source'] = 'Manual assessment';
                    }
                }

                $scenario->frequencyDrivers()->create([
                    'organization_id' => $scenario->organization_id,
                    'driver_type' => $d['driver_type'],
                    'criterion' => $d['criterion'],
                    'computation_role' => $d['computation_role'],
                    'value' => $d['value'],
                    'data_source' => $d['data_source'] ?? 'expert_judgement',
                    'sm_scorecard_id' => $d['sm_scorecard_id'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }

            $this->computation->recomputeAll($scenario);

            AuditTrailService::record(
                $scenario,
                'frequency_updated',
                'drivers_count',
                (string) $beforeCount,
                (string) count($drivers)
            );
        });
    }

    /**
     * Update Impact Categories & Criteria and recompute insurance netting.
     *
     * @param  list<array<string, mixed>>  $types
     */
    public function updateImpact(Scenario $scenario, array $types, User $actor): void
    {
        $this->scenarios->refuseIfReadOnly($scenario);

        $beforeCount = $scenario->impactTypes()->count();

        DB::transaction(function () use ($scenario, $types, $beforeCount) {
            $scenario->impactTypes()->delete();

            $typeOrder = 1;
            foreach ($types as $t) {
                $impactType = $scenario->impactTypes()->create([
                    'organization_id' => $scenario->organization_id,
                    'name' => $t['name'],
                    'aggregation_template' => $t['aggregation_template'],
                    'eligible_to_transfer' => $t['eligible_to_transfer'] ?? false,
                    'sort_order' => $typeOrder++,
                ]);

                $critOrder = 1;
                foreach ($t['criteria'] ?? [] as $c) {
                    $impactType->criteria()->create([
                        'organization_id' => $scenario->organization_id,
                        'label' => $c['label'],
                        'unit' => $c['unit'] ?? null,
                        'computation_role' => $c['computation_role'],
                        'data_source' => $c['data_source'] ?? null,
                        'typical_value' => $c['typical_value'],
                        'serious_value' => $c['serious_value'],
                        'extreme_value' => $c['extreme_value'],
                        'sort_order' => $critOrder++,
                    ]);
                }
            }

            $this->computation->recomputeAll($scenario);

            AuditTrailService::record(
                $scenario,
                'impact_updated',
                'types_count',
                (string) $beforeCount,
                (string) count($types)
            );
        });
    }

    /**
     * Update Insurance Netting Terms and recompute final net figures.
     *
     * @param  list<array<string, mixed>>  $coverages
     */
    public function updateInsurance(Scenario $scenario, array $coverages, User $actor): void
    {
        $this->scenarios->refuseIfReadOnly($scenario);

        $beforeCount = $scenario->insuranceCoverages()->count();

        DB::transaction(function () use ($scenario, $coverages, $beforeCount) {
            foreach ($coverages as $cov) {
                $scenario->insuranceCoverages()->updateOrCreate(
                    [
                        'sm_scenario_id' => $scenario->id,
                        'severity' => $cov['severity'],
                    ],
                    [
                        'organization_id' => $scenario->organization_id,
                        'deductible_local' => $cov['deductible_local'],
                        'limit_local' => $cov['limit_local'],
                        'deductible_group' => $cov['deductible_group'],
                        'limit_group' => $cov['limit_group'],
                        'recovery_rate' => $cov['recovery_rate'],
                        'probability_of_cover' => $cov['probability_of_cover'],
                        'insurer_default_haircut' => $cov['insurer_default_haircut'],
                    ]
                );
            }

            $this->computation->recomputeAll($scenario);

            AuditTrailService::record(
                $scenario,
                'insurance_updated',
                'coverages_count',
                (string) $beforeCount,
                (string) count($coverages)
            );
        });
    }
}
