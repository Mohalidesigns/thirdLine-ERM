<?php

namespace Database\Seeders;

use App\Models\IcaapAssessment;
use App\Models\QuantificationScenario;
use App\Models\QuantificationSetting;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use Illuminate\Database\Seeder;

class QuantificationSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = 1;
        $userId = 1;

        $this->command->info('Seeding quantification data...');

        // ── Settings ────────────────────────────────────────────────────
        QuantificationSetting::updateOrCreate(
            ['organization_id' => $orgId],
            [
                'default_iterations' => 10000,
                'default_confidence_levels' => [90, 95, 99, 99.5, 99.9],
                'cbn_minimum_car' => 10.0,
                'cbn_conservation_buffer' => 2.5,
                'cbn_mpr' => 27.5,
                'cbn_loss_threshold_kobo' => 500000000,
                'nfiu_str_threshold_kobo' => 500000000,
                'nfiu_ctr_threshold_kobo' => 1000000000,
                'ndic_threshold_kobo' => 50000000,
                'distribution_defaults' => [
                    'frequency' => 'poisson',
                    'severity' => 'lognormal',
                ],
            ]
        );

        // ── Scenarios ───────────────────────────────────────────────────
        $scenariosData = [
            [
                'scenario_reference' => 'SCN-2026-001',
                'name' => 'Credit Default - Oil & Gas Portfolio',
                'description' => 'Potential losses from defaults in the oil & gas loan portfolio due to crude oil price volatility and sector-wide stress. Calibrated from 5-year internal loss data.',
                'cbn_risk_category' => 'Credit Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 3.5,
                'expected_annual_frequency' => 3.5,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 23.025851,  // ln(10B kobo)
                'severity_sigma' => 1.2,
                'severity_min_kobo' => 50000000,
                'severity_max_kobo' => 50000000000,
                'expected_loss_per_event_kobo' => 5000000000,
                'expected_annual_loss_kobo' => 17500000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-002',
                'name' => 'External Fraud - Cyber Attack',
                'description' => 'Losses from sophisticated cyber attacks including phishing, BEC, card fraud, and system intrusions targeting digital banking channels.',
                'cbn_risk_category' => 'Operational Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 5.0,
                'expected_annual_frequency' => 5.0,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 21.416413,  // ln(2B kobo)
                'severity_sigma' => 1.8,
                'severity_min_kobo' => 10000000,
                'severity_max_kobo' => 20000000000,
                'expected_loss_per_event_kobo' => 1200000000,
                'expected_annual_loss_kobo' => 6000000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-003',
                'name' => 'CBN Regulatory Fine',
                'description' => 'Monetary penalties from CBN for regulatory breaches including AML/KYC failures, capital adequacy violations, and governance issues.',
                'cbn_risk_category' => 'Operational Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 1.2,
                'expected_annual_frequency' => 1.2,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 22.332583,  // ln(5B kobo)
                'severity_sigma' => 1.5,
                'severity_min_kobo' => 100000000,
                'severity_max_kobo' => 30000000000,
                'expected_loss_per_event_kobo' => 2000000000,
                'expected_annual_loss_kobo' => 2400000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-004',
                'name' => 'FX Volatility - Naira Devaluation',
                'description' => 'Market risk losses from sudden Naira devaluation impacting open FX positions, trade finance, and foreign currency denominated assets.',
                'cbn_risk_category' => 'Market Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 2.0,
                'expected_annual_frequency' => 2.0,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 22.517248,  // ~6B kobo
                'severity_sigma' => 1.3,
                'severity_min_kobo' => 200000000,
                'severity_max_kobo' => 40000000000,
                'expected_loss_per_event_kobo' => 3500000000,
                'expected_annual_loss_kobo' => 7000000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-005',
                'name' => 'IT System Failure - Core Banking',
                'description' => 'Losses from critical core banking system outages including downtime costs, transaction failures, customer compensation, and reputational damage.',
                'cbn_risk_category' => 'Operational Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 3.0,
                'expected_annual_frequency' => 3.0,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 20.723266,  // ~1B kobo
                'severity_sigma' => 1.0,
                'severity_min_kobo' => 5000000,
                'severity_max_kobo' => 10000000000,
                'expected_loss_per_event_kobo' => 500000000,
                'expected_annual_loss_kobo' => 1500000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-006',
                'name' => 'Internal Fraud - Unauthorized Trading',
                'description' => 'Losses from employee misconduct including unauthorized trading, embezzlement, and misappropriation of funds.',
                'cbn_risk_category' => 'Operational Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 1.5,
                'expected_annual_frequency' => 1.5,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 21.416413,
                'severity_sigma' => 2.0,
                'severity_min_kobo' => 20000000,
                'severity_max_kobo' => 25000000000,
                'expected_loss_per_event_kobo' => 850000000,
                'expected_annual_loss_kobo' => 1275000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-007',
                'name' => 'Credit Default - Retail Portfolio',
                'description' => 'Mass defaults in retail consumer lending including personal loans, salary advances, and credit cards triggered by economic downturn.',
                'cbn_risk_category' => 'Credit Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 4.0,
                'expected_annual_frequency' => 4.0,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 21.640418,
                'severity_sigma' => 1.1,
                'severity_min_kobo' => 100000000,
                'severity_max_kobo' => 15000000000,
                'expected_loss_per_event_kobo' => 2500000000,
                'expected_annual_loss_kobo' => 10000000000,
                'status' => 'active',
            ],
            [
                'scenario_reference' => 'SCN-2026-008',
                'name' => 'Liquidity Stress - Deposit Run',
                'description' => 'Severe liquidity stress from sudden deposit withdrawals triggered by market rumours, resulting in emergency borrowing costs and asset fire sales.',
                'cbn_risk_category' => 'Liquidity Risk',
                'frequency_distribution' => 'poisson',
                'frequency_lambda' => 0.3,
                'expected_annual_frequency' => 0.3,
                'severity_distribution' => 'lognormal',
                'severity_mu' => 24.635247,
                'severity_sigma' => 1.5,
                'severity_min_kobo' => 1000000000,
                'severity_max_kobo' => 100000000000,
                'expected_loss_per_event_kobo' => 10000000000,
                'expected_annual_loss_kobo' => 3000000000,
                'status' => 'active',
            ],
        ];

        $scenarioIds = [];
        foreach ($scenariosData as $data) {
            $scenario = QuantificationScenario::updateOrCreate(
                ['organization_id' => $orgId, 'scenario_reference' => $data['scenario_reference']],
                array_merge($data, [
                    'organization_id' => $orgId,
                    'scenario_type' => 'single_event',
                    'created_by' => $userId,
                ])
            );
            $scenarioIds[] = $scenario->id;
        }

        $this->command->info('  Created '.count($scenarioIds).' quantification scenarios');

        // ── Simulation Runs + Results ───────────────────────────────────
        $simulations = [
            [
                'simulation_reference' => 'SIM-2026-001',
                'scenario_ids' => array_slice($scenarioIds, 0, 5),
                'iterations' => 10000,
                'horizon_years' => 1,
                'status' => 'completed',
                'runtime_seconds' => 12,
                'started_at' => now()->subDays(14),
                'completed_at' => now()->subDays(14)->addSeconds(12),
            ],
            [
                'simulation_reference' => 'SIM-2026-002',
                'scenario_ids' => $scenarioIds,
                'iterations' => 50000,
                'horizon_years' => 1,
                'status' => 'completed',
                'runtime_seconds' => 45,
                'started_at' => now()->subDays(7),
                'completed_at' => now()->subDays(7)->addSeconds(45),
            ],
            [
                'simulation_reference' => 'SIM-2026-003',
                'scenario_ids' => array_slice($scenarioIds, 0, 3),
                'iterations' => 10000,
                'horizon_years' => 3,
                'status' => 'completed',
                'runtime_seconds' => 8,
                'started_at' => now()->subDays(3),
                'completed_at' => now()->subDays(3)->addSeconds(8),
            ],
        ];

        $simRunIds = [];
        foreach ($simulations as $simData) {
            $simRun = SimulationRun::updateOrCreate(
                ['organization_id' => $orgId, 'simulation_reference' => $simData['simulation_reference']],
                array_merge($simData, [
                    'organization_id' => $orgId,
                    'correlation_method' => 'gaussian_copula',
                    'confidence_levels' => [90, 95, 99, 99.5, 99.9],
                    'initiated_by' => $userId,
                ])
            );
            $simRunIds[$simData['simulation_reference']] = $simRun->id;

            // Create scenario-level results
            foreach ($simData['scenario_ids'] as $sid) {
                $scenarioModel = QuantificationScenario::find($sid);
                if (! $scenarioModel) {
                    continue;
                }

                $eal = $scenarioModel->expected_annual_loss_kobo ?? 1000000000;
                $sigma = (float) ($scenarioModel->severity_sigma ?? 1.5);

                SimulationResult::updateOrCreate(
                    ['simulation_run_id' => $simRun->id, 'scenario_id' => $sid],
                    [
                        'result_type' => 'scenario',
                        'expected_annual_loss_kobo' => $eal,
                        'var_90_kobo' => round($eal * 1.8),
                        'var_95_kobo' => round($eal * 2.5),
                        'var_99_kobo' => round($eal * 4.2),
                        'var_99_9_kobo' => round($eal * 6.5),
                        'std_deviation_kobo' => round($eal * $sigma * 0.5),
                        'percentile_distribution' => [
                            'p5' => round($eal * 0.1),
                            'p10' => round($eal * 0.2),
                            'p25' => round($eal * 0.5),
                            'p50' => round($eal * 0.9),
                            'p75' => round($eal * 1.5),
                            'p90' => round($eal * 1.8),
                            'p95' => round($eal * 2.5),
                            'p99' => round($eal * 4.2),
                            'p99.5' => round($eal * 5.5),
                            'p99.9' => round($eal * 6.5),
                        ],
                        'risk_contributions' => [
                            'scenario_name' => $scenarioModel->name,
                            'frequency_mean' => (float) $scenarioModel->frequency_lambda,
                            'expected_loss_pct' => 0, // will be computed below
                        ],
                    ]
                );
            }

            // Create aggregate result
            $totalEal = QuantificationScenario::whereIn('id', $simData['scenario_ids'])->sum('expected_annual_loss_kobo');
            $count = count($simData['scenario_ids']);
            $diversificationFactor = max(0.75, 1 - ($count * 0.03));

            $aggEal = round($totalEal * $diversificationFactor);

            SimulationResult::updateOrCreate(
                ['simulation_run_id' => $simRun->id, 'scenario_id' => null, 'result_type' => 'aggregate'],
                [
                    'result_type' => 'aggregate',
                    'expected_annual_loss_kobo' => $aggEal,
                    'var_90_kobo' => round($aggEal * 1.6),
                    'var_95_kobo' => round($aggEal * 2.2),
                    'var_99_kobo' => round($aggEal * 3.8),
                    'var_99_9_kobo' => round($aggEal * 5.8),
                    'std_deviation_kobo' => round($aggEal * 0.65),
                    'percentile_distribution' => [
                        'p5' => round($aggEal * 0.15),
                        'p10' => round($aggEal * 0.25),
                        'p25' => round($aggEal * 0.55),
                        'p50' => round($aggEal * 0.85),
                        'p75' => round($aggEal * 1.3),
                        'p90' => round($aggEal * 1.6),
                        'p95' => round($aggEal * 2.2),
                        'p99' => round($aggEal * 3.8),
                        'p99.5' => round($aggEal * 5.0),
                        'p99.9' => round($aggEal * 5.8),
                    ],
                    'risk_contributions' => collect($simData['scenario_ids'])->mapWithKeys(function ($sid) use ($totalEal) {
                        $s = QuantificationScenario::find($sid);

                        return [$sid => $totalEal > 0 ? round((($s->expected_annual_loss_kobo ?? 0) / $totalEal) * 100, 1) : 0];
                    })->toArray(),
                ]
            );
        }

        $this->command->info('  Created '.count($simulations).' simulation runs with results');

        // ── ICAAP Assessments ───────────────────────────────────────────
        $latestSimId = $simRunIds['SIM-2026-002'] ?? null;
        $stressSimId = $simRunIds['SIM-2026-003'] ?? null;

        $icaapData = [
            [
                'period' => '2025-H2',
                'cet1_capital_kobo' => 450000000000,   // ₦4.5T
                'tier1_capital_kobo' => 520000000000,   // ₦5.2T
                'tier2_capital_kobo' => 180000000000,   // ₦1.8T
                'total_qualifying_capital_kobo' => 700000000000, // ₦7.0T
                'total_rwa_kobo' => 4200000000000,  // ₦42.0T
                'car_actual' => 16.67,
                'cbn_minimum_car' => 10.00,
                'conservation_buffer' => 2.50,
                'pillar2a_credit_kobo' => 210000000000,   // ₦2.1T
                'pillar2a_market_kobo' => 84000000000,    // ₦840B
                'pillar2a_operational_kobo' => 126000000000,   // ₦1.26T
                'pillar2a_other_kobo' => 42000000000,    // ₦420B
                'pillar2b_stress_buffer_kobo' => 63000000000,    // ₦630B
                'base_simulation_id' => $latestSimId,
                'stress_simulation_id' => $stressSimId,
                'prepared_by' => $userId,
                'reviewed_by' => $userId,
                'board_approval_date' => now()->subDays(30),
            ],
            [
                'period' => '2025-H1',
                'cet1_capital_kobo' => 420000000000,
                'tier1_capital_kobo' => 490000000000,
                'tier2_capital_kobo' => 170000000000,
                'total_qualifying_capital_kobo' => 660000000000,
                'total_rwa_kobo' => 4000000000000,
                'car_actual' => 16.50,
                'cbn_minimum_car' => 10.00,
                'conservation_buffer' => 2.50,
                'pillar2a_credit_kobo' => 200000000000,
                'pillar2a_market_kobo' => 80000000000,
                'pillar2a_operational_kobo' => 120000000000,
                'pillar2a_other_kobo' => 40000000000,
                'pillar2b_stress_buffer_kobo' => 60000000000,
                'prepared_by' => $userId,
                'reviewed_by' => $userId,
                'board_approval_date' => now()->subMonths(7),
            ],
        ];

        foreach ($icaapData as $data) {
            IcaapAssessment::updateOrCreate(
                ['organization_id' => $orgId, 'period' => $data['period']],
                array_merge($data, [
                    'organization_id' => $orgId,
                    'status' => 'approved',
                ])
            );
        }

        $this->command->info('  Created '.count($icaapData).' ICAAP assessments');
        $this->command->info('Quantification seeding complete!');
    }
}
