<?php

namespace App\Console\Commands;

use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\RiskTaxonomy;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioAction;
use App\Models\Sm\Scorecard;
use App\Models\User;
use App\Services\Sm\AllocationService;
use App\Services\Sm\CauseService;
use App\Services\Sm\ScenarioActionService;
use App\Services\Sm\ScenarioQuantitativeService;
use App\Services\Sm\ScenarioService;
use App\Services\Sm\ScenarioVariantService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDemoScenarioCommand extends Command
{
    protected $signature = 'sm:create-demo-scenario {--org=1 : Organization ID}';

    protected $description = 'Creates a realistic end-to-end operational risk scenario modelled across all 8 tabs';

    public function handle(
        ScenarioService $scenarioService,
        CauseService $causeService,
        AllocationService $allocationService,
        ScenarioQuantitativeService $quantitativeService,
        ScenarioVariantService $variantService,
        ScenarioActionService $actionService,
    ): int {
        $orgId = (int) $this->option('org');
        $organization = Organization::find($orgId);

        if (! $organization) {
            $this->error("Organization with ID {$orgId} not found.");
            return self::FAILURE;
        }

        $user = User::where('organization_id', $orgId)->first();
        if (! $user) {
            $this->error("No user found for organization {$orgId}.");
            return self::FAILURE;
        }

        $itBu = BusinessUnit::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('name', 'like', '%Information Technology%')
                    ->orWhere('name', 'like', '%Technology%');
            })->first() ?? BusinessUnit::where('organization_id', $orgId)->first();

        $retailBu = BusinessUnit::where('organization_id', $orgId)
            ->where('name', 'like', '%Retail%')->first() ?? $itBu;

        $digitalBu = BusinessUnit::where('organization_id', $orgId)
            ->where('name', 'like', '%Digital%')->first() ?? $itBu;

        $opTaxonomy = RiskTaxonomy::where('organization_id', $orgId)
            ->where('name', 'like', '%OP10%')
            ->first() ?? RiskTaxonomy::where('organization_id', $orgId)->whereNotNull('parent_id')->first();

        $this->info("Creating End-to-End Operational Risk Scenario for Organization [{$organization->name}]...");

        // 1. Create Scenario Header
        $scenario = $scenarioService->create([
            'organization_id' => $orgId,
            'name' => 'Core Banking Ransomware Outage & Data Exfiltration',
            'business_unit_id' => $itBu?->id,
            'risk_taxonomy_id' => $opTaxonomy?->id,
            'nature' => 'severe_but_plausible',
            'weighting' => 'high',
            'currency' => 'NGN',
            'background' => 'A simulated coordinated double-extortion ransomware campaign targeting core transaction processing nodes and database replica journals during peak month-end payroll reconciliation and interbank settlement.',
        ], $user);

        $this->line("  [1/8] Created Scenario [{$scenario->reference}] (ID: {$scenario->id})");

        // 2. Overview & Governance Details
        $scenarioService->update($scenario, [
            'rationale' => 'Multi-dimensional stress testing to evaluate operational resilience, liquidity buffer sufficiency, regulatory disclosure triggers, and insurance mitigation under prolonged 72-hour system unavailability.',
            'designed_on' => CarbonImmutable::now()->toDateString(),
            'review_due_on' => CarbonImmutable::now()->addYear()->toDateString(),
            'reputational_exposure' => 'major',
            'legal_compliance_exposure' => 'severe',
        ], $user);
        $this->line("  [2/8] Updated Overview & Governance attributes");

        // 3. Qualitative: Causes & Business Line Allocations
        $causeService->sync($scenario, [
            'system_inadequately_designed' => [
                'selected' => true,
                'note' => 'Inadequate network segmentation between corporate DMZ and core transaction zone.',
            ],
            'system_inadequately_maintained' => [
                'selected' => true,
                'note' => 'Delayed operating system and firmware patch deployment on administrative servers.',
            ],
            'ext_technological' => [
                'selected' => true,
                'note' => 'Zero-day vulnerability exploited in edge VPN gateway.',
            ],
        ]);

        if ($retailBu && $digitalBu) {
            $allocationService->sync($scenario, [
                [
                    'business_unit_id' => $itBu->id,
                    'percent' => '50.00',
                    'justification' => 'Direct infrastructure rebuild, server sanitization, and technical recovery operations.',
                ],
                [
                    'business_unit_id' => $retailBu->id,
                    'percent' => '30.00',
                    'justification' => 'Branch operations disruption and frontline customer complaint handling.',
                ],
                [
                    'business_unit_id' => $digitalBu->id,
                    'percent' => '20.00',
                    'justification' => 'Mobile banking and internet banking transaction failures and customer redress.',
                ],
            ], $user->id);
        }
        $this->line("  [3/8] Saved Qualitative Causes and 100% Business Line Allocations");

        // 4. Scorecard: COSO Components & Control Environment
        $quantitativeService->updateScorecard($scenario, [
            'control_nature' => 'mixed',
            'components' => [
                ['component_code' => 'control_environment', 'efficiency_level' => 3, 'weighting_factor' => 4, 'notes' => 'Board Risk Committee oversight and documented cybersecurity charter in place.'],
                ['component_code' => 'risk_assessment', 'efficiency_level' => 3, 'weighting_factor' => 3, 'notes' => 'Annual cyber risk posture assessments and threat vulnerability scanning.'],
                ['component_code' => 'control_activities', 'efficiency_level' => 2, 'weighting_factor' => 4, 'notes' => 'Legacy privileged access management still requires manual token generation.'],
                ['component_code' => 'information_communication', 'efficiency_level' => 3, 'weighting_factor' => 3, 'notes' => 'Automated SIEM alerting with 24/7 Security Operations Center monitoring.'],
                ['component_code' => 'monitoring_activities', 'efficiency_level' => 3, 'weighting_factor' => 3, 'notes' => 'Quarterly penetration testing and internal audit review.'],
            ],
        ], $user);
        $this->line("  [4/8] Saved COSO Scorecard (Nature: mixed, Global Score evaluated)");

        // 5. Frequency: Drivers & Poisson Recurrence
        $quantitativeService->updateFrequency($scenario, [
            [
                'driver_type' => 'baseline_count',
                'criterion' => 'Annual industry incidence rate for severe banking ransomware',
                'computation_role' => 'count',
                'value' => '0.25',
                'data_source' => 'Global Cyber Loss Database 2026',
                'use_scorecard' => false,
            ],
            [
                'driver_type' => 'control_score_complement',
                'criterion' => 'COSO Scorecard Efficiency Complement Factor',
                'computation_role' => 'control_score_complement',
                'value' => '0.50',
                'data_source' => 'Scorecard',
                'use_scorecard' => true,
            ],
            [
                'driver_type' => 'threat_probability',
                'criterion' => 'Probability of threat actor successfully initiating encryption sequence',
                'computation_role' => 'probability',
                'value' => '0.85',
                'data_source' => 'Threat Intelligence Assessment',
                'use_scorecard' => false,
            ],
        ], $user);
        $this->line("  [5/8] Saved Frequency Drivers & calculated Poisson recurrence interval");

        // 6. Impact: Direct Financial Loss Types
        $quantitativeService->updateImpact($scenario, [
            [
                'name' => 'Direct Customer Compensation & Restitution',
                'aggregation_template' => 'staff_turnover_interval',
                'eligible_to_transfer' => true,
                'criteria' => [
                    ['label' => 'Affected customer accounts needing reimbursement', 'unit' => 'accounts', 'computation_role' => 'count', 'typical_value' => '1500', 'serious_value' => '4000', 'extreme_value' => '10000', 'data_source' => 'Customer Accounts Division'],
                    ['label' => 'Average reimbursement per affected account', 'unit' => 'NGN', 'computation_role' => 'unit_amount', 'typical_value' => '15000.00', 'serious_value' => '25000.00', 'extreme_value' => '50000.00', 'data_source' => 'Claims Historical Data'],
                    ['label' => 'Restitution claim dispute contingency probability', 'unit' => '%', 'computation_role' => 'probability', 'typical_value' => '0.80', 'serious_value' => '0.90', 'extreme_value' => '1.00', 'data_source' => 'Legal Department'],
                ],
            ],
            [
                'name' => 'Forensic Incident Response & Rebuild Consulting',
                'aggregation_template' => 'litigation_probability',
                'eligible_to_transfer' => true,
                'criteria' => [
                    ['label' => 'External incident response firm forensic retainer', 'unit' => 'NGN', 'computation_role' => 'flat_fee', 'typical_value' => '15000000.00', 'serious_value' => '35000000.00', 'extreme_value' => '75000000.00', 'data_source' => 'Cyber Forensics Retainer Rate Card'],
                ],
            ],
            [
                'name' => 'Statutory Regulatory Fine & NDPC Sanctions',
                'aggregation_template' => 'litigation_probability',
                'eligible_to_transfer' => false,
                'criteria' => [
                    ['label' => 'Regulatory compliance sanction for data breach', 'unit' => 'NGN', 'computation_role' => 'flat_fee', 'typical_value' => '5000000.00', 'serious_value' => '25000000.00', 'extreme_value' => '100000000.00', 'data_source' => 'Legal Counsel Assessment'],
                ],
            ],
        ], $user);
        $this->line("  [6/8] Saved Impact loss models (Gross loss typical, serious & extreme)");

        // 7. Insurance: Deductible, Limits & Net Loss Calculation
        $quantitativeService->updateInsurance($scenario, [
            [
                'severity' => 'typical',
                'deductible_local' => '10000000.00',
                'limit_local' => '150000000.00',
                'deductible_group' => '0.00',
                'limit_group' => '0.00',
                'recovery_rate' => '0.9000',
                'probability_of_cover' => '0.9500',
                'insurer_default_haircut' => '0.0200',
            ],
            [
                'severity' => 'serious',
                'deductible_local' => '10000000.00',
                'limit_local' => '150000000.00',
                'deductible_group' => '0.00',
                'limit_group' => '0.00',
                'recovery_rate' => '0.9000',
                'probability_of_cover' => '0.9000',
                'insurer_default_haircut' => '0.0500',
            ],
            [
                'severity' => 'extreme',
                'deductible_local' => '10000000.00',
                'limit_local' => '150000000.00',
                'deductible_group' => '0.00',
                'limit_group' => '0.00',
                'recovery_rate' => '0.8500',
                'probability_of_cover' => '0.8000',
                'insurer_default_haircut' => '0.0800',
            ],
        ], $user);
        $this->line("  [7/8] Saved Insurance policies & computed Net Mitigated Loss");

        // 8. Variant Decks: S1, S2, S3 Severity Tiers
        $variantService->saveVariants($scenario, [
            [
                'tier' => 'tier_1',
                'name' => 'S1: Perimeter Breach & Containment',
                'narrative' => 'Malware isolated on DMZ edge hosts. Core database unaffected; minor payment gateway latency.',
                'is_cumulative' => false,
                'triggers' => [
                    'EDR sensor triggers ransomware heuristic alert on DMZ proxy',
                    'Abnormal outbound beaconing to foreign command-and-control IP',
                ],
                'operational_impacts' => [
                    'Partial 2-hour payment gateway queuing',
                    'Zero core banking data loss or corruption',
                ],
                'responses' => [
                    'Isolate affected edge server subnet immediately',
                    'Revoke active VPN sessions and trigger administrative credential reset',
                ],
                'response_owners' => [
                    'Security Operations Center (SOC) Lead',
                    'Network Security Manager',
                ],
            ],
            [
                'tier' => 'tier_2',
                'name' => 'S2: Core System Interruption & DR Switchover',
                'narrative' => 'Lateral infection encrypts primary database replica logs. 12-hour core banking outage before DR failover completes.',
                'is_cumulative' => true,
                'triggers' => [
                    'Database replication journals corrupted on primary SAN',
                    'Core banking API response latency exceeds 30 minutes',
                ],
                'operational_impacts' => [
                    'Customer mobile banking and ATM network unavailable for 12 hours',
                    'Interbank clearing deferred to subsequent cycle',
                ],
                'responses' => [
                    'Declare Major Incident and trigger disaster recovery data center switchover',
                    'Deploy secondary clean standby transaction processing cluster',
                    'Initiate mandatory regulatory disclosure to Central Bank liaison',
                ],
                'response_owners' => [
                    'Head of IT Infrastructure',
                    'Chief Information Security Officer',
                ],
            ],
            [
                'tier' => 'tier_3',
                'name' => 'S3: Full Double-Extortion & Backup Encryption',
                'narrative' => 'Threat actor encrypts online backup repositories and threatens public release of customer financial records.',
                'is_cumulative' => true,
                'triggers' => [
                    'Ransom note detected on primary Active Directory domain controllers',
                    'Online backup catalog deleted or encrypted simultaneously',
                ],
                'operational_impacts' => [
                    '72-hour complete operational unavailability of core banking services',
                    'Extortion threat published on dark web data leak site',
                    'Heavy brand reputation damage and potential class action scrutiny',
                ],
                'responses' => [
                    'Activate Crisis Management Team and Board Risk Committee',
                    'Engage external digital forensics, breach counsel, and negotiation experts',
                    'Execute cold offline tape restoration protocol for core banking ledger',
                    'Issue transparent public communication and customer support helpline',
                ],
                'response_owners' => [
                    'Executive Committee (ExCo)',
                    'Chief Risk Officer',
                    'Head of Corporate Communications',
                ],
            ],
        ], $user);
        $this->line("  [8/8] Saved 3 Escalating Variant Decks (S1, S2, S3)");

        // 9. Remediation Actions & Immediate Roadmap
        $action1 = $actionService->createAction($scenario, [
            'kind' => ScenarioAction::KIND_GAP_REMEDIATION,
            'risk_area' => 'Immutable Air-Gapped Backup Architecture',
            'current_position' => 'Online SAN replication between primary data center and secondary DR site.',
            'identified_gap' => 'Both replication sites share domain credentials, leaving backup copies vulnerable to lateral ransomware encryption.',
            'action_plan' => 'Implement write-once-read-many (WORM) storage appliances with air-gapped offline tape rotation protocols.',
            'department' => 'Information Technology',
            'business_unit_id' => $itBu?->id,
            'owner_id' => $user->id,
            'due_date' => CarbonImmutable::now()->addDays(45)->toDateString(),
            'status' => ScenarioAction::STATUS_IN_PROGRESS,
        ], $user);

        $action2 = $actionService->createAction($scenario, [
            'kind' => ScenarioAction::KIND_GAP_REMEDIATION,
            'risk_area' => 'Tier-0 Privileged Access Management (PAM) Enforcement',
            'current_position' => 'Administrative passwords stored locally by senior system engineers.',
            'identified_gap' => 'Absence of ephemeral, just-in-time session management enables credential dumping on compromised servers.',
            'action_plan' => 'Deploy enterprise PAM platform requiring dual-authorization and hardware MFA tokens for domain controllers.',
            'department' => 'Information Security',
            'business_unit_id' => $itBu?->id,
            'owner_id' => $user->id,
            'due_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
            'status' => ScenarioAction::STATUS_NOT_STARTED,
        ], $user);

        $immediate1 = $actionService->createAction($scenario, [
            'kind' => ScenarioAction::KIND_IMMEDIATE,
            'time_horizon' => ScenarioAction::HORIZON_TODAY,
            'risk_area' => 'Active Directory KRBTGT Password Emergency Rotation',
            'action_plan' => 'Perform initial 2-step rotation of Active Directory KRBTGT service account keys across all domain forests.',
            'department' => 'IT Infrastructure',
            'business_unit_id' => $itBu?->id,
            'owner_id' => $user->id,
            'due_date' => CarbonImmutable::now()->toDateString(),
            'status' => ScenarioAction::STATUS_IN_PROGRESS,
            'proof_of_closure' => 'Domain controller event logs verifying ticket generation with new key ID.',
        ], $user);

        $immediate2 = $actionService->createAction($scenario, [
            'kind' => ScenarioAction::KIND_IMMEDIATE,
            'time_horizon' => ScenarioAction::HORIZON_WITHIN_7_DAYS,
            'risk_area' => 'Hardware MFA Enforcement on Critical Admin Jumphosts',
            'action_plan' => 'Mandate FIDO2 hardware security keys for all server console sessions and disable legacy SMS OTP fallback.',
            'department' => 'Information Security',
            'business_unit_id' => $itBu?->id,
            'owner_id' => $user->id,
            'due_date' => CarbonImmutable::now()->addDays(7)->toDateString(),
            'status' => ScenarioAction::STATUS_NOT_STARTED,
        ], $user);

        $immediate3 = $actionService->createAction($scenario, [
            'kind' => ScenarioAction::KIND_IMMEDIATE,
            'time_horizon' => ScenarioAction::HORIZON_WITHIN_30_DAYS,
            'risk_area' => 'Cold Offline Tape Backup Recovery Simulation',
            'action_plan' => 'Conduct unannounced tabletop exercise restoring customer accounts database from offline tape media.',
            'department' => 'Business Continuity Management',
            'business_unit_id' => $itBu?->id,
            'owner_id' => $user->id,
            'due_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
            'status' => ScenarioAction::STATUS_NOT_STARTED,
        ], $user);

        $this->info("\n=== Scenario Successfully Created & Fully Modelled! ===");
        $this->table(
            ['Key', 'Value'],
            [
                ['Scenario Reference', $scenario->reference],
                ['Title', $scenario->name],
                ['Status', $scenario->status],
                ['Nature', $scenario->nature],
                ['Currency', $scenario->currency],
                ['Workspace URL', "http://127.0.0.1:8000/risk/scenarios/{$scenario->id}"],
                ['Presentation Deck URL', "http://127.0.0.1:8000/risk/scenarios/{$scenario->id}/deck"],
                ['Action Tracker URL', 'http://127.0.0.1:8000/risk/scenarios/actions'],
            ]
        );

        return self::SUCCESS;
    }
}
