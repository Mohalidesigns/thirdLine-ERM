<?php

namespace Database\Seeders;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\QuantificationScenario;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('cbn_institution_code', 'NGN/COM/0001')->firstOrFail();
        $orgId = $org->id;
        $admin = User::where('email', 'admin@risk.test')->firstOrFail();

        // ================================================================
        // 1. USERS
        // ================================================================

        $riskManager = User::create([
            'name' => 'Adebayo Ogundimu', 'email' => 'adebayo.ogundimu@risk.test',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'organization_id' => $orgId, 'staff_id' => 'RM-001',
            'job_title' => 'Head, Enterprise Risk Management', 'department' => 'Risk Management',
            'phone' => '+234-802-345-6789', 'is_active' => true,
        ]);
        $riskManager->assignRole('risk-manager');

        $cro = User::create([
            'name' => 'Ngozi Adekunle', 'email' => 'ngozi.adekunle@risk.test',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'organization_id' => $orgId, 'staff_id' => 'CRO-001',
            'job_title' => 'Chief Risk Officer', 'department' => 'Executive Management',
            'phone' => '+234-803-456-7890', 'is_active' => true,
        ]);
        $cro->assignRole('chief-risk-officer');

        $analyst = User::create([
            'name' => 'Emeka Nwosu', 'email' => 'emeka.nwosu@risk.test',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'organization_id' => $orgId, 'staff_id' => 'RA-001',
            'job_title' => 'Senior Risk Analyst', 'department' => 'Risk Management',
            'phone' => '+234-805-567-8901', 'is_active' => true,
        ]);
        $analyst->assignRole('risk-analyst');

        $compliance = User::create([
            'name' => 'Fatima Bello', 'email' => 'fatima.bello@risk.test',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'organization_id' => $orgId, 'staff_id' => 'CO-001',
            'job_title' => 'Chief Compliance Officer', 'department' => 'Compliance',
            'phone' => '+234-806-678-9012', 'is_active' => true,
        ]);
        $compliance->assignRole('compliance-officer');

        $lossManager = User::create([
            'name' => 'Oluwaseun Adeyemi', 'email' => 'oluwaseun.adeyemi@risk.test',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'organization_id' => $orgId, 'staff_id' => 'LM-001',
            'job_title' => 'Loss Event Manager', 'department' => 'Operational Risk',
            'phone' => '+234-807-789-0123', 'is_active' => true,
        ]);
        $lossManager->assignRole('loss-event-manager');

        $allUsers = [$admin, $riskManager, $cro, $analyst, $compliance, $lossManager];

        // ================================================================
        // 2. BUSINESS UNITS
        // ================================================================

        $buDefs = [
            ['BU-RT', 'Retail', 'Retail banking products and services including savings, current accounts, personal loans, and cards.', $riskManager->id],
            ['BU-IB', 'Institutional Banking', 'Corporate and institutional banking services including commercial lending, trade finance, and advisory.', $cro->id],
            ['BU-TR', 'Treasury', 'Money market operations, FX trading, fixed income, and liquidity management.', $analyst->id],
            ['BU-CIC', 'Compliance and Internal Control', 'Regulatory compliance monitoring, internal control frameworks, and policy adherence.', $compliance->id],
            ['BU-DB', 'Digital Banking', 'Digital channels including mobile banking, internet banking, USSD, and digital payment solutions.', $riskManager->id],
            ['BU-ERM', 'Enterprise Risk Management', 'Enterprise-wide risk identification, assessment, monitoring, and reporting.', $cro->id],
            ['BU-FC', 'Financial Control', 'Financial reporting, accounting operations, regulatory returns, and financial controls.', $analyst->id],
            ['BU-SBP', 'Strategy and Business Process', 'Strategic planning, business process management, and organizational transformation.', $cro->id],
            ['BU-HR', 'Human Resources & Business Services', 'Human capital management, talent development, and business support services.', $admin->id],
            ['BU-BM', 'Brand Management', 'Brand strategy, marketing communications, and corporate identity management.', $admin->id],
            ['BU-IT', 'Information Technology', 'IT infrastructure, application development, cybersecurity, and technology operations.', $riskManager->id],
            ['BU-IA', 'Internal Audit', 'Independent assurance on governance, risk management, and internal control processes.', $compliance->id],
            ['BU-LG', 'Legal', 'Legal advisory, contract management, litigation, and regulatory legal matters.', $compliance->id],
            ['BU-CX', 'Customer Experience', 'Customer service delivery, complaints management, and customer satisfaction monitoring.', $riskManager->id],
            ['BU-PB', 'Private Banking', 'Wealth management, private banking advisory, and high-net-worth individual services.', $cro->id],
            ['BU-RES', 'Real Estate Sales', 'Real estate sales, property management, and real estate finance services.', $admin->id],
            ['BU-OP', 'Operations', 'Core banking operations, transaction processing, and operational support services.', $lossManager->id],
            ['BU-SB', 'Structured Banking', 'Structured finance, project finance, and complex financial product structuring.', $cro->id],
            ['BU-LMDR', 'Loan Monitoring and Debt Recovery', 'Loan portfolio monitoring, early warning systems, and debt recovery management.', $lossManager->id],
        ];

        $bus = [];
        foreach ($buDefs as $i => $bd) {
            $bus[] = BusinessUnit::create([
                'organization_id' => $orgId, 'code' => $bd[0], 'name' => $bd[1],
                'description' => $bd[2], 'head_id' => $bd[3],
                'is_active' => true, 'sort_order' => $i + 1,
            ]);
        }

        // ================================================================
        // 3. BUSINESS PROCESSES
        // ================================================================

        $procs = [];
        $procs[] = BusinessProcess::create(['organization_id' => $orgId, 'business_unit_id' => $bus[0]->id, 'code' => 'BP-LN', 'name' => 'Loan Origination & Disbursement', 'description' => 'End-to-end retail loan application processing, credit assessment, approval, and disbursement.', 'owner_id' => $riskManager->id, 'criticality' => 'high', 'is_active' => true]);
        $procs[] = BusinessProcess::create(['organization_id' => $orgId, 'business_unit_id' => $bus[0]->id, 'code' => 'BP-PY', 'name' => 'Payment Processing', 'description' => 'NIBSS, NIP, NEFT, and card payment transaction processing and settlement.', 'owner_id' => $analyst->id, 'criticality' => 'critical', 'is_active' => true]);
        $procs[] = BusinessProcess::create(['organization_id' => $orgId, 'business_unit_id' => $bus[1]->id, 'code' => 'BP-TF', 'name' => 'Trade Finance Operations', 'description' => 'Letters of credit, Form M processing, bill for collection, and guarantees.', 'owner_id' => $cro->id, 'criticality' => 'high', 'is_active' => true]);
        $procs[] = BusinessProcess::create(['organization_id' => $orgId, 'business_unit_id' => $bus[2]->id, 'code' => 'BP-FX', 'name' => 'FX Trading & Settlement', 'description' => 'Foreign exchange trading, position management, and settlement including CBN interventions.', 'owner_id' => $analyst->id, 'criticality' => 'critical', 'is_active' => true]);
        $procs[] = BusinessProcess::create(['organization_id' => $orgId, 'business_unit_id' => $bus[0]->id, 'code' => 'BP-KY', 'name' => 'Customer Onboarding & KYC', 'description' => 'Know Your Customer verification, account opening, and ongoing due diligence.', 'owner_id' => $compliance->id, 'criticality' => 'high', 'is_active' => true]);

        // ================================================================
        // 4. RISKS (25)
        // ================================================================

        $catByCode = RiskCategory::where('organization_id', $orgId)->pluck('id', 'code')->toArray();

        $risksDef = $this->getRisksDefinition();
        $risks = [];
        foreach ($risksDef as $rd) {
            $categoryId = $catByCode[$rd['cat']] ?? $catByCode[explode('-', $rd['cat'])[0]] ?? RiskCategory::where('organization_id', $orgId)->first()->id;
            $inhScore = $rd['il'] * $rd['ii'];
            $inhRating = $inhScore >= 20 ? 'Critical' : ($inhScore >= 12 ? 'High' : ($inhScore >= 6 ? 'Medium' : 'Low'));
            $resScore = ($rd['rl'] && $rd['ri']) ? $rd['rl'] * $rd['ri'] : null;
            $resRating = $resScore !== null ? ($resScore >= 20 ? 'Critical' : ($resScore >= 12 ? 'High' : ($resScore >= 6 ? 'Medium' : 'Low'))) : null;

            $risks[] = Risk::create([
                'organization_id' => $orgId, 'risk_code' => $rd['code'], 'title' => $rd['title'],
                'description' => $rd['desc'], 'category_id' => $categoryId,
                'business_unit_id' => $bus[$rd['bu']]->id, 'process_id' => isset($rd['proc']) ? $procs[$rd['proc']]->id : null,
                'risk_owner_id' => $allUsers[$rd['owner']]->id, 'risk_steward_id' => $analyst->id,
                'identified_by' => $allUsers[$rd['owner']]->id, 'risk_source' => $rd['src'],
                'status' => $rd['status'], 'inherent_likelihood' => $rd['il'], 'inherent_impact' => $rd['ii'],
                'inherent_score' => $inhScore, 'inherent_rating' => $inhRating,
                'residual_likelihood' => $rd['rl'], 'residual_impact' => $rd['ri'],
                'residual_score' => $resScore, 'residual_rating' => $resRating,
                'treatment_strategy' => $rd['strat'],
                'last_assessment_date' => now()->subDays(rand(10, 90)),
                'next_review_date' => now()->addDays(rand(30, 180)),
                'financial_exposure_ngn' => rand(50, 5000) * 1000000.00,
                'created_by' => $admin->id,
            ]);
        }

        // ================================================================
        // 5. CONTROLS (10)
        // ================================================================

        $ctlDef = [
            ['CTL-001','Credit Scoring Model Validation','Quarterly validation of retail credit scoring models including Gini coefficient and KS statistic checks.','detective','manual','quarterly','manual',0,1,85.00],
            ['CTL-002','Single Obligor Limit Monitoring','Daily automated monitoring of single obligor exposure limits per CBN prudential guidelines.','preventive','automated','daily','fully_automated',1,2,95.00],
            ['CTL-003','FX Position Limit Control','Real-time monitoring of net open position against CBN NOP limit of 20% of shareholders funds.','preventive','automated','real_time','fully_automated',2,3,92.00],
            ['CTL-004','Core Banking DR/BCP Testing','Semi-annual disaster recovery failover testing for core banking system with RTO/RPO validation.','preventive','manual','semi_annual','manual',0,1,78.00],
            ['CTL-005','SOC/SIEM Real-time Monitoring','Security Operations Centre continuous monitoring via SIEM platform with automated incident detection.','detective','automated','real_time','fully_automated',0,1,88.00],
            ['CTL-006','Card Fraud Rule Engine','Real-time card transaction fraud detection using ML-based rule engine with velocity checks.','detective','automated','real_time','fully_automated',0,5,82.00],
            ['CTL-007','Dual Authorization for High-Value Transfers','Maker-checker control requiring dual authorization for all transfers above NGN 10 million.','preventive','automated','per_transaction','semi_automated',0,1,90.00],
            ['CTL-008','KYC/CDD Periodic Review','Annual review of customer due diligence documentation with enhanced scrutiny for PEPs and high-risk customers.','detective','manual','annually','manual',0,4,75.00],
            ['CTL-009','Liquidity Coverage Ratio Monitoring','Daily calculation and monitoring of LCR and NSFR against CBN minimum requirements.','detective','automated','daily','fully_automated',2,2,91.00],
            ['CTL-010','Vault and Cash Reconciliation','Daily vault counts and cash reconciliation across all branches with end-of-day balancing.','detective','manual','daily','manual',0,5,80.00],
        ];

        $controls = [];
        foreach ($ctlDef as $c) {
            $controls[] = Control::create([
                'organization_id' => $orgId, 'control_code' => $c[0], 'name' => $c[1], 'description' => $c[2],
                'control_type' => $c[3], 'control_nature' => $c[4], 'frequency' => $c[5], 'automation_level' => $c[6],
                'owner_id' => $allUsers[$c[8]]->id, 'business_unit_id' => $bus[$c[7]]->id,
                'effectiveness_rating' => $c[9] >= 90 ? 'effective' : ($c[9] >= 70 ? 'partially_effective' : 'ineffective'),
                'effectiveness_pct' => $c[9], 'last_test_date' => now()->subDays(rand(15, 120)),
                'next_test_due' => now()->addDays(rand(30, 180)), 'status' => 'active', 'created_by' => $admin->id,
            ]);
        }

        // ================================================================
        // 6. RISK-CONTROL MAPPINGS
        // ================================================================

        $rcMaps = [
            [0,0,'Credit scoring validates borrower creditworthiness',0.80,true],
            [1,1,'Single obligor monitoring prevents concentration breaches',1.00,true],
            [5,2,'NOP limit monitoring prevents FX position breaches',1.00,true],
            [9,3,'DR testing ensures core banking recoverability',0.60,true],
            [10,4,'SIEM monitoring detects cyber intrusion attempts',0.70,true],
            [10,5,'SOC monitoring provides secondary cyber defence layer',0.30,false],
            [11,5,'Fraud rule engine detects card skimming patterns',1.00,true],
            [12,9,'Daily vault reconciliation detects cash irregularities',0.80,true],
            [12,6,'Dual authorization prevents unauthorized transfers',0.20,false],
            [13,7,'KYC review ensures AML compliance',1.00,true],
            [17,8,'LCR monitoring ensures liquidity compliance',1.00,true],
            [15,4,'SIEM detects data exfiltration attempts',0.70,true],
        ];
        foreach ($rcMaps as $m) {
            DB::table('risk_control_mapping')->insert([
                'risk_id' => $risks[$m[0]]->id, 'control_id' => $controls[$m[1]]->id,
                'mapping_rationale' => $m[2], 'control_weight' => $m[3], 'is_key_control' => $m[4],
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ================================================================
        // 7. RISK ASSESSMENTS (10)
        // ================================================================

        $assDef = [
            [0,'full',4,5,3,4,4,3,4,'fast','approved'],
            [1,'full',3,5,2,3,5,2,4,'moderate','approved'],
            [5,'full',4,4,3,2,4,3,3,'fast','approved'],
            [9,'targeted',4,5,5,4,3,2,4,'very_fast','approved'],
            [10,'full',3,5,4,5,3,2,4,'fast','approved'],
            [13,'full',3,4,3,5,5,2,4,'moderate','reviewed'],
            [17,'targeted',3,4,3,2,4,2,3,'moderate','reviewed'],
            [19,'full',4,3,3,4,2,3,2,'slow','submitted'],
            [21,'full',3,4,2,3,4,2,3,'moderate','draft'],
            [23,'targeted',4,3,2,4,2,3,2,'fast','draft'],
        ];
        foreach ($assDef as $j => $a) {
            $impScore = max($a[3],$a[4],$a[5],$a[6]);
            $overall = $a[2] * $impScore;
            $oRating = $overall >= 20 ? 'Critical' : ($overall >= 12 ? 'High' : ($overall >= 6 ? 'Medium' : 'Low'));
            $rs = $a[7] * $a[8];
            $rRating = $rs >= 20 ? 'Critical' : ($rs >= 12 ? 'High' : ($rs >= 6 ? 'Medium' : 'Low'));
            $daysAgo = 90 - ($j * 8);

            RiskAssessment::create([
                'organization_id' => $orgId, 'risk_id' => $risks[$a[0]]->id,
                'assessment_type' => $a[1], 'assessment_date' => now()->subDays(max(1, $daysAgo)),
                'assessor_id' => $allUsers[array_rand($allUsers)]->id,
                'likelihood_score' => $a[2], 'likelihood_rationale' => 'Based on historical incident data and current control environment.',
                'impact_financial' => $a[3], 'impact_operational' => $a[4],
                'impact_reputational' => $a[5], 'impact_regulatory' => $a[6],
                'impact_score' => $impScore, 'overall_score' => $overall, 'overall_rating' => $oRating,
                'residual_likelihood' => $a[7], 'residual_impact' => $a[8],
                'residual_score' => $rs, 'residual_rating' => $rRating, 'risk_velocity' => $a[9],
                'justification' => 'Assessment conducted per quarterly risk review cycle.',
                'status' => $a[10],
                'reviewer_id' => in_array($a[10], ['reviewed','approved']) ? $riskManager->id : null,
                'review_date' => in_array($a[10], ['reviewed','approved']) ? now()->subDays(max(1, $daysAgo - 5)) : null,
                'approved_by' => $a[10] === 'approved' ? $cro->id : null,
                'approved_date' => $a[10] === 'approved' ? now()->subDays(max(1, $daysAgo - 10)) : null,
            ]);
        }

        // ================================================================
        // 8. TREATMENT PLANS (8)
        // ================================================================

        $tpDef = [
            [0,'mitigate','Strengthen retail credit underwriting criteria','Revise minimum credit score thresholds and reduce maximum DTI ratios for retail loans.','high','in_progress',65,25000000],
            [1,'mitigate','Oil & gas exposure reduction programme','Planned reduction of oil & gas sector exposure from 30% to below 20% through portfolio diversification.','critical','in_progress',40,0],
            [5,'mitigate','Implement automated NOP hedging system','Deploy automated FX hedging solution to dynamically manage net open position within CBN limits.','high','not_started',0,150000000],
            [9,'mitigate','Core banking system high-availability upgrade','Upgrade to active-active cluster configuration with zero-downtime patching capability.','critical','in_progress',30,500000000],
            [10,'mitigate','Deploy next-gen endpoint detection and response','Implement CrowdStrike/SentinelOne EDR across all endpoints with managed detection.','high','completed',100,85000000],
            [13,'mitigate','AML/CFT programme enhancement','Upgrade transaction monitoring, implement AI-based suspicious activity detection, and conduct staff training.','high','in_progress',55,120000000],
            [19,'mitigate','Digital banking transformation roadmap','Accelerate digital product launches including neo-banking features and instant lending.','medium','in_progress',20,2000000000],
            [20,'mitigate','Capital raising plan for recapitalisation','Execute rights issue and evaluate strategic investor options to meet CBN requirements.','critical','not_started',0,50000000],
        ];
        foreach ($tpDef as $t) {
            TreatmentPlan::create([
                'organization_id' => $orgId, 'risk_id' => $risks[$t[0]]->id,
                'strategy' => $t[1], 'action_title' => $t[2], 'action_description' => $t[3],
                'owner_id' => $allUsers[rand(1,3)]->id,
                'target_date' => $t[5] === 'completed' ? now()->subDays(10) : now()->addDays(rand(60,270)),
                'priority' => $t[4], 'status' => $t[5], 'progress_pct' => $t[6],
                'progress_notes' => $t[5] === 'in_progress' ? 'Implementation progressing as planned.' : null,
                'cost_estimate_ngn' => $t[7],
                'actual_cost_ngn' => $t[5] === 'completed' ? $t[7] * 0.95 : ($t[5] === 'in_progress' ? $t[7] * ($t[6]/100) : null),
                'completion_date' => $t[5] === 'completed' ? now()->subDays(10) : null,
                'expected_risk_reduction' => ['likelihood_reduction' => 1, 'impact_reduction' => 1],
                'created_by' => $admin->id,
            ]);
        }

        // ================================================================
        // 9. KEY RISK INDICATORS (10) + MEASUREMENTS
        // ================================================================

        $kriDef = $this->getKriDefinitions();
        $kris = [];
        foreach ($kriDef as $kd) {
            $lastVal = end($kd['vals']);
            if ($kd['dir'] === 'higher_worse') {
                $curSt = $lastVal <= $kd['gmax'] ? 'green' : ($lastVal <= $kd['amax'] ? 'amber' : 'red');
            } else {
                $curSt = $lastVal >= $kd['gmin'] ? 'green' : ($lastVal >= $kd['amin'] ? 'amber' : 'red');
            }
            $prevVal = $kd['vals'][count($kd['vals']) - 2];
            $trend = ($lastVal > $prevVal) ? ($kd['dir'] === 'higher_worse' ? 'deteriorating' : 'improving') : ($kd['dir'] === 'higher_worse' ? 'improving' : 'deteriorating');

            $kri = KeyRiskIndicator::create([
                'organization_id' => $orgId, 'kri_code' => $kd['code'], 'name' => $kd['name'],
                'description' => $kd['desc'], 'metric_formula' => $kd['formula'], 'data_source' => $kd['source'],
                'measurement_frequency' => $kd['freq'], 'unit_of_measure' => $kd['unit'],
                'baseline_value' => $kd['baseline'], 'green_threshold_min' => $kd['gmin'],
                'green_threshold_max' => $kd['gmax'], 'amber_threshold_min' => $kd['amin'],
                'amber_threshold_max' => $kd['amax'], 'red_threshold_min' => $kd['rmin'],
                'red_threshold_max' => $kd['rmax'], 'threshold_direction' => $kd['dir'],
                'current_value' => $lastVal, 'current_status' => $curSt, 'trend_direction' => $trend,
                'owner_id' => $allUsers[$kd['owner']]->id, 'is_automated' => in_array($kd['freq'], ['daily','real_time']),
                'last_measurement_at' => now()->subDays(5), 'created_by' => $admin->id,
            ]);
            $kris[] = $kri;

            foreach ($kd['vals'] as $mi => $val) {
                $measDate = now()->subMonths(6 - $mi)->startOfMonth()->addDays(14);
                if ($kd['dir'] === 'higher_worse') {
                    $ms = $val <= $kd['gmax'] ? 'green' : ($val <= $kd['amax'] ? 'amber' : 'red');
                } else {
                    $ms = $val >= $kd['gmin'] ? 'green' : ($val >= $kd['amin'] ? 'amber' : 'red');
                }
                KriMeasurement::create([
                    'kri_id' => $kri->id, 'measurement_date' => $measDate, 'value' => $val,
                    'status' => $ms, 'entered_by' => $allUsers[$kd['owner']]->id,
                    'data_source' => $kd['source'],
                    'notes' => $mi === count($kd['vals']) - 1 ? 'Latest measurement.' : null,
                ]);
            }
        }

        // ================================================================
        // 10. RISK-KRI MAPPINGS
        // ================================================================

        $rkMaps = [[0,0,'leading'],[1,0,'leading'],[17,1,'leading'],[18,1,'lagging'],[9,2,'leading'],[10,3,'leading'],[11,4,'lagging'],[5,5,'leading'],[13,6,'leading'],[23,7,'lagging'],[20,8,'leading'],[19,9,'lagging']];
        foreach ($rkMaps as $km) {
            DB::table('risk_kri_mapping')->insert([
                'risk_id' => $risks[$km[0]]->id, 'kri_id' => $kris[$km[1]]->id,
                'correlation_type' => $km[2], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ================================================================
        // 11. LOSS EVENTS (5)
        // ================================================================

        $leDef = [
            [
                'ref' => 'LE-2026-0001', 'title' => 'ATM card skimming incident at Victoria Island branch',
                'desc' => 'Card skimming devices discovered on 3 ATMs at the Victoria Island main branch. 450 customer cards compromised with 87 fraudulent transactions totalling NGN 12.3 million.',
                'root' => 'Insufficient ATM anti-skimming controls and delayed tamper alert notification.',
                'dloss' => 45, 'ddisc' => 43, 'bu' => 0, 'dept' => 'E-Business', 'branch' => 'Victoria Island Main',
                'bl1' => 'External Fraud', 'bl2' => 'Theft and Fraud', 'bl3' => 'Card Skimming',
                'cc' => 'External Fraud', 'ce' => 'Card Fraud', 'cp' => 'Retail Banking',
                'gross' => 1230000000, 'ins' => 0, 'other' => 350000000, 'pend' => 450000000,
                'lcat' => 'actual', 'sev' => 'HIGH', 'st' => 'INVESTIGATING',
                'cbn' => true, 'nfiu' => false, 'off' => 5, 'asgn' => 5, 'riskIdx' => 11,
            ],
            [
                'ref' => 'LE-2026-0002', 'title' => 'Phishing attack compromising staff email credentials',
                'desc' => 'Targeted spear-phishing resulted in 12 staff email accounts compromised. Attackers initiated 5 fraudulent wire transfers totalling NGN 45 million before detection.',
                'root' => 'Lack of multi-factor authentication on email platform and insufficient staff cyber awareness training.',
                'dloss' => 90, 'ddisc' => 88, 'bu' => 0, 'dept' => 'Operations', 'branch' => 'Head Office',
                'bl1' => 'External Fraud', 'bl2' => 'Systems Security', 'bl3' => 'Phishing/Social Engineering',
                'cc' => 'External Fraud', 'ce' => 'Cyber Attack', 'cp' => 'Corporate Banking',
                'gross' => 4500000000, 'ins' => 2000000000, 'other' => 1500000000, 'pend' => 0,
                'lcat' => 'actual', 'sev' => 'CRITICAL', 'st' => 'RCA_COMPLETE',
                'cbn' => true, 'nfiu' => true, 'off' => 1, 'asgn' => 5, 'riskIdx' => 10,
            ],
            [
                'ref' => 'LE-2026-0003', 'title' => 'System error causing duplicate salary credits',
                'desc' => 'Batch processing error in salary upload module caused duplicate credits for 2,300 employees. Total duplicate amount NGN 180 million; NGN 165 million recovered.',
                'root' => 'Missing validation check in batch upload module for duplicate detection.',
                'dloss' => 60, 'ddisc' => 59, 'bu' => 1, 'dept' => 'Transaction Banking', 'branch' => null,
                'bl1' => 'Execution, Delivery & Process Management', 'bl2' => 'Transaction Processing', 'bl3' => 'Data Entry Error',
                'cc' => 'Process Failure', 'ce' => 'Transaction Error', 'cp' => 'Corporate Banking',
                'gross' => 18000000000, 'ins' => 0, 'other' => 16500000000, 'pend' => 1200000000,
                'lcat' => 'actual', 'sev' => 'HIGH', 'st' => 'CLOSED',
                'cbn' => false, 'nfiu' => false, 'off' => 1, 'asgn' => 1, 'riskIdx' => null,
            ],
            [
                'ref' => 'LE-2026-0004', 'title' => 'Branch robbery at Abeokuta outlet',
                'desc' => 'Armed robbery at Abeokuta branch resulted in theft of NGN 8.5 million in cash and injuries to two security personnel.',
                'root' => 'Inadequate physical security measures and delayed police response.',
                'dloss' => 120, 'ddisc' => 120, 'bu' => 0, 'dept' => 'Branch Operations', 'branch' => 'Abeokuta Branch',
                'bl1' => 'External Fraud', 'bl2' => 'Theft and Fraud', 'bl3' => 'Robbery',
                'cc' => 'External Fraud', 'ce' => 'Armed Robbery', 'cp' => 'Retail Banking',
                'gross' => 850000000, 'ins' => 700000000, 'other' => 0, 'pend' => 0,
                'lcat' => 'actual', 'sev' => 'HIGH', 'st' => 'CLOSED',
                'cbn' => true, 'nfiu' => false, 'off' => 5, 'asgn' => 5, 'riskIdx' => null,
            ],
            [
                'ref' => 'LE-2026-0005', 'title' => 'Potential money laundering through dormant accounts',
                'desc' => 'Suspicious transactions identified across 15 dormant accounts reactivated within 2 weeks, with rapid turnover totalling NGN 350 million. STR filed with NFIU.',
                'root' => 'Dormant account reactivation process lacks enhanced due diligence checks.',
                'dloss' => 30, 'ddisc' => 25, 'bu' => 0, 'dept' => 'Compliance', 'branch' => 'Multiple Branches',
                'bl1' => 'External Fraud', 'bl2' => 'Theft and Fraud', 'bl3' => 'Money Laundering',
                'cc' => 'Compliance Failure', 'ce' => 'AML Breach', 'cp' => 'Retail Banking',
                'gross' => 0, 'ins' => 0, 'other' => 0, 'pend' => 0,
                'lcat' => 'near_miss', 'sev' => 'CRITICAL', 'st' => 'INVESTIGATING',
                'cbn' => true, 'nfiu' => true, 'off' => 4, 'asgn' => 4, 'riskIdx' => null,
            ],
        ];

        $lossEvents = [];
        foreach ($leDef as $le) {
            $dateLoss = now()->subDays($le['dloss']);
            $dateDisc = now()->subDays($le['ddisc']);

            $lossEvents[] = LossEvent::create([
                'organization_id' => $orgId, 'event_reference' => $le['ref'],
                'title' => $le['title'], 'description' => $le['desc'],
                'initial_root_cause' => $le['root'],
                'date_of_loss' => $dateLoss, 'date_discovered' => $dateDisc,
                'date_reported' => $dateDisc->copy()->addDay(),
                'business_unit_id' => $bus[$le['bu']]->id, 'department' => $le['dept'],
                'branch_name' => $le['branch'], 'responsible_officer_id' => $allUsers[$le['off']]->id,
                'basel_l1_category' => $le['bl1'], 'basel_l2_category' => $le['bl2'], 'basel_l3_detail' => $le['bl3'],
                'cbn_risk_category' => $le['cc'], 'cbn_orms_event_type' => $le['ce'], 'cbn_product_line' => $le['cp'],
                'gross_loss_amount_kobo' => $le['gross'], 'insurance_recovery_kobo' => $le['ins'],
                'other_recovery_kobo' => $le['other'], 'pending_recovery_kobo' => $le['pend'],
                'actual_recovery_kobo' => $le['ins'] + $le['other'],
                'loss_category' => $le['lcat'], 'insurance_covered' => $le['ins'] > 0,
                'event_severity' => $le['sev'], 'current_status' => $le['st'],
                'cbn_reportable' => $le['cbn'], 'cbn_notification_sent' => $le['st'] === 'CLOSED',
                'nfiu_reportable' => $le['nfiu'], 'nfiu_report_filed' => $le['nfiu'] && $le['st'] === 'CLOSED',
                'risk_register_id' => $le['riskIdx'] !== null ? $risks[$le['riskIdx']]->id : null,
                'assigned_to_id' => $allUsers[$le['asgn']]->id,
                'customer_impact_rating' => in_array($le['sev'], ['CRITICAL','HIGH']) ? 'significant' : 'moderate',
                'reputational_impact_rating' => $le['sev'] === 'CRITICAL' ? 'high' : 'medium',
                'created_by' => $allUsers[$le['off']]->id,
            ]);
        }

        // ================================================================
        // 12. ISSUES (8)
        // ================================================================

        $issDef = [
            ['ISS-2026-0001','Inadequate ATM anti-skimming controls across branch network','Current ATM fleet lacks anti-skimming jitter technology. Only 30% upgraded.','Physical inspection of 50 ATMs revealed 35 without anti-skimming devices.','INTERNAL_AUDIT','IA-2025-Q4-032',60,'OPERATIONAL','HIGH','IN_PROGRESS',0,'E-Business',1,false,false,false,30,11,0,'ATM upgrade programme initiated. Procurement in progress.','Deploy anti-skimming devices to all ATMs.',2500000000],
            ['ISS-2026-0002','CBN examination finding: Insufficient CRR maintenance','CBN examination identified 8 instances of CRR shortfall during the period.','Daily CRR position review showed the bank fell below required ratio on 8 business days.','CBN_EXAMINATION','CBN/RSD/EXM/2025/087',90,'REGULATORY','CRITICAL','OPEN',2,'Treasury',2,true,true,true,-10,17,null,'Enhanced daily liquidity forecasting being implemented.','Implement real-time CRR monitoring with early warning alerts.',5000000000],
            ['ISS-2026-0003','Missing KYC documentation for 1,200 high-risk customers','Periodic KYC review found 1,200 high-risk customers with incomplete or outdated documentation.','Sample review of 500 high-risk files showed 40% with expired ID documents.','COMPLIANCE_REVIEW',null,null,'COMPLIANCE','HIGH','IN_PROGRESS',0,'Compliance',4,true,false,false,60,13,null,'Dedicated KYC remediation team formed with 90-day target.','Complete KYC refresh and implement automated document expiry tracking.',1000000000],
            ['ISS-2026-0004','DR site failover test failure for core banking','Semi-annual DR failover test failed to achieve 4-hour RTO target. Actual recovery: 11 hours.','DR test log showed 7-hour database sync lag and 3 middleware startup failures.','INTERNAL_AUDIT','IA-2025-Q3-018',120,'OPERATIONAL','CRITICAL','IN_PROGRESS',0,'IT Infrastructure',1,false,false,false,15,9,null,'IT team conducting root cause analysis. Middleware upgrade scheduled.','Upgrade DR replication to synchronous mode.',10000000000],
            ['ISS-2026-0005','CBN examination finding: Related party lending disclosure gaps','CBN examination identified incomplete disclosure of related party transactions in regulatory returns.','Three director-related facilities totalling NGN 2.1 billion not disclosed in S293 returns.','CBN_EXAMINATION','CBN/BSD/EXM/2025/087',90,'REGULATORY','CRITICAL','OVERDUE',1,'Credit Admin',2,true,true,true,-30,24,null,'Complete review of insider-related credits initiated.','Submit corrected S293 returns and implement automated related party identification.',3000000000],
            ['ISS-2026-0006','Weak password policy for critical systems','Password policies for core banking and treasury do not meet CBN IT Standards Framework.','Passwords allow 6 characters without special characters. No MFA for privileged accounts.','RISK_ASSESSMENT',null,null,'TECHNOLOGY','HIGH','IN_PROGRESS',0,'Information Security',1,false,false,false,45,10,1,'Enterprise-wide password policy revision approved. MFA rollout commenced.','Enforce 12-character minimum passwords with MFA across all critical systems.',500000000],
            ['ISS-2026-0007','NDPA compliance gap in customer consent management','Consent management framework does not fully comply with NDPA 2023 requirements.','Onboarding forms lack granular consent options. No mechanism for consent withdrawal.','COMPLIANCE_REVIEW',null,null,'COMPLIANCE','MEDIUM','OPEN',0,'Legal & Compliance',4,true,false,false,90,21,null,'Legal team reviewing NDPA requirements. External consultants engaged.','Implement consent management platform with granular capture and self-service portal.',2000000000],
            ['ISS-2026-0008','Third-party vendor risk assessment backlog','Annual vendor risk assessments overdue for 45 of 120 critical third-party providers.','Vendor register shows 37.5% of critical vendors not assessed in current cycle.','INTERNAL_AUDIT','IA-2025-Q4-041',45,'OPERATIONAL','MEDIUM','OPEN',0,'Procurement',1,false,false,false,60,14,null,'Vendor assessment team to be augmented. Prioritised schedule prepared.','Complete overdue assessments and implement automated vendor monitoring.',800000000],
        ];

        foreach ($issDef as $is) {
            $remDue = $is[16] !== null ? ($is[16] >= 0 ? now()->addDays($is[16]) : now()->subDays(abs($is[16]))) : null;
            Issue::create([
                'organization_id' => $orgId, 'issue_reference' => $is[0], 'title' => $is[1],
                'description' => $is[2], 'observation' => $is[3], 'issue_source' => $is[4],
                'examination_ref' => $is[5], 'examination_date' => $is[6] !== null ? now()->subDays($is[6]) : null,
                'issue_category' => $is[7], 'priority' => $is[8], 'issue_status' => $is[9],
                'business_unit_id' => $bus[$is[10]]->id, 'department' => $is[11],
                'responsible_owner_id' => $allUsers[$is[12]]->id,
                'regulatory_reportable' => $is[13], 'cbn_reportable' => $is[14],
                'cbn_examination_finding' => $is[15],
                'cbn_response_deadline' => $is[15] ? $remDue : null,
                'remediation_due_date' => $remDue,
                'management_response' => $is[19], 'action_plan' => $is[20],
                'current_escalation_level' => $is[9] === 'OVERDUE' ? 'CRO' : null,
                'potential_loss_kobo' => $is[21],
                'risk_register_id' => $is[17] !== null ? $risks[$is[17]]->id : null,
                'loss_event_id' => $is[18] !== null ? $lossEvents[$is[18]]->id : null,
                'created_by' => $admin->id,
            ]);
        }

        // ================================================================
        // 13. RISK APPETITE (3)
        // ================================================================

        $crCatId = $catByCode['CR'] ?? null;
        $orCatId = $catByCode['OR'] ?? null;
        $mrCatId = $catByCode['MR'] ?? null;

        $apDef = [
            [$crCatId,'moderate','NPL Ratio','The Board has a moderate appetite for credit risk, accepting NPL ratios up to 5% of gross loans while maintaining portfolio quality aligned with CBN prudential standards.',5.0,1.0,3.0,4.5,'percentage'],
            [$orCatId,'low','Operational Loss as % of Gross Income','The Board has a low appetite for operational risk, targeting operational losses below 1% of gross income with zero tolerance for fraud losses exceeding NGN 100 million.',1.5,0,0.8,1.1,'percentage'],
            [$mrCatId,'moderate','Value at Risk (% of Trading Capital)','The Board has a moderate appetite for market risk in the trading book, with VaR not to exceed 3% of allocated trading capital under normal conditions.',3.0,0.5,2.0,1.8,'percentage'],
        ];
        foreach ($apDef as $ap) {
            if (!$ap[0]) continue;
            DB::table('risk_appetite')->insert([
                'uuid' => (string) Str::uuid(), 'organization_id' => $orgId,
                'risk_category_id' => $ap[0], 'appetite_level' => $ap[1],
                'appetite_statement' => $ap[3], 'tolerance_metric' => $ap[2],
                'max_tolerance' => $ap[4], 'target_min' => $ap[5], 'target_max' => $ap[6],
                'current_position' => $ap[7], 'unit_of_measure' => $ap[8],
                'effective_date' => now()->startOfYear(), 'expiry_date' => now()->endOfYear(),
                'approved_by' => $cro->id, 'approved_date' => now()->subDays(30),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ================================================================
        // 14. QUANTIFICATION SCENARIOS (2)
        // ================================================================

        QuantificationScenario::create([
            'organization_id' => $orgId, 'scenario_reference' => 'QS-2026-0001',
            'risk_register_id' => $risks[10]->id, 'scenario_type' => 'single_risk',
            'name' => 'Cyber Attack Loss Distribution - Ransomware Scenario',
            'description' => 'Monte Carlo simulation of potential ransomware attack losses based on Nigerian banking sector cyber incidents.',
            'cbn_risk_category' => 'Operational Risk', 'basel_l1_category' => 'External Fraud',
            'frequency_distribution' => 'poisson', 'frequency_lambda' => 2.5,
            'severity_distribution' => 'lognormal', 'severity_mu' => 18.42, 'severity_sigma' => 1.85,
            'severity_min_kobo' => 500000000, 'severity_max_kobo' => 50000000000,
            'expected_annual_frequency' => 2.5, 'expected_loss_per_event_kobo' => 5000000000,
            'expected_annual_loss_kobo' => 12500000000,
            'cbn_stress_scenario' => 'adverse', 'stress_multiplier_frequency' => 2.0, 'stress_multiplier_severity' => 1.5,
            'status' => 'APPROVED', 'data_quality_score' => 3,
            'calibrated_by' => $analyst->id, 'calibration_date' => now()->subDays(45),
            'approved_by' => $cro->id, 'approval_date' => now()->subDays(30), 'created_by' => $analyst->id,
        ]);

        QuantificationScenario::create([
            'organization_id' => $orgId, 'scenario_reference' => 'QS-2026-0002',
            'risk_register_id' => $risks[0]->id, 'scenario_type' => 'single_risk',
            'name' => 'Credit Portfolio Loss - NPL Stress Scenario',
            'description' => 'Stress testing of retail credit portfolio under adverse macroeconomic conditions including naira depreciation.',
            'cbn_risk_category' => 'Credit Risk', 'basel_l1_category' => 'Credit Risk',
            'frequency_distribution' => 'negative_binomial', 'frequency_n' => 50.0, 'frequency_p' => 0.08,
            'severity_distribution' => 'lognormal', 'severity_mu' => 16.50, 'severity_sigma' => 2.10,
            'severity_min_kobo' => 100000000, 'severity_max_kobo' => 100000000000,
            'expected_annual_frequency' => 4.0, 'expected_loss_per_event_kobo' => 15000000000,
            'expected_annual_loss_kobo' => 60000000000,
            'cbn_stress_scenario' => 'severely_adverse', 'stress_multiplier_frequency' => 3.0, 'stress_multiplier_severity' => 2.5,
            'status' => 'DRAFT', 'data_quality_score' => 2,
            'calibrated_by' => $analyst->id, 'calibration_date' => now()->subDays(15), 'created_by' => $analyst->id,
        ]);

        $this->command->info('Demo data seeded successfully!');
        $this->command->info('  - 5 users with roles');
        $this->command->info('  - 19 business units, 5 business processes');
        $this->command->info('  - 25 risks across categories');
        $this->command->info('  - 10 controls with 12 risk-control mappings');
        $this->command->info('  - 10 risk assessments');
        $this->command->info('  - 8 treatment plans');
        $this->command->info('  - 10 KRIs with 60 measurements, 12 risk-KRI mappings');
        $this->command->info('  - 5 loss events');
        $this->command->info('  - 8 issues');
        $this->command->info('  - 3 risk appetite entries');
        $this->command->info('  - 2 quantification scenarios');
    }

    private function getRisksDefinition(): array
    {
        return [
            ['code'=>'RK-2026-0001','title'=>'Elevated NPL ratio in retail loan portfolio','desc'=>'Non-performing loan ratio for the retail segment trending upward due to macroeconomic pressures, naira devaluation, and rising inflation.','cat'=>'CR-DR-1','bu'=>0,'proc'=>0,'owner'=>1,'il'=>4,'ii'=>5,'rl'=>3,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0002','title'=>'Oil & gas sector concentration exceeding CBN limit','desc'=>'Exposure to upstream oil and gas exceeds 30% of risk assets, breaching CBN prudential guidelines on sectoral concentration.','cat'=>'CR-CN-1','bu'=>1,'proc'=>2,'owner'=>2,'il'=>3,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0003','title'=>'Trade finance counterparty default on LC obligations','desc'=>'Risk of default by international counterparties on letters of credit amid supply chain disruptions.','cat'=>'CR-DR-2','bu'=>1,'proc'=>2,'owner'=>2,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'transfer','src'=>'external'],
            ['code'=>'RK-2026-0004','title'=>'Sovereign exposure risk from FGN bond holdings','desc'=>'Significant FGN bond holdings expose the bank to sovereign credit risk given the fiscal deficit trajectory.','cat'=>'CR-CT-1','bu'=>2,'proc'=>3,'owner'=>3,'il'=>2,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'accept','src'=>'external'],
            ['code'=>'RK-2026-0005','title'=>'Insider-related credit exposure above regulatory threshold','desc'=>'Aggregate insider-related lending approaching CBN maximum of 10% of shareholders funds unimpaired by losses.','cat'=>'CR-CN-2','bu'=>1,'proc'=>0,'owner'=>1,'il'=>2,'ii'=>4,'rl'=>1,'ri'=>3,'status'=>'draft','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0006','title'=>'FX open position exceeding NOP limit','desc'=>'Net open position in foreign currencies at risk of exceeding CBN 20% of shareholders funds threshold.','cat'=>'MR-FX-1','bu'=>2,'proc'=>3,'owner'=>3,'il'=>4,'ii'=>4,'rl'=>3,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0007','title'=>'Interest rate repricing gap in banking book','desc'=>'Mismatch between rate-sensitive assets and liabilities creating earnings-at-risk from MPR changes.','cat'=>'MR-IR-1','bu'=>2,'proc'=>3,'owner'=>3,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0008','title'=>'Basis risk from NIBOR-MPR spread widening','desc'=>'Widening NIBOR-MPR spread creating basis risk on floating-rate loan portfolios benchmarked to different rates.','cat'=>'MR-IR-2','bu'=>2,'proc'=>3,'owner'=>3,'il'=>2,'ii'=>3,'rl'=>null,'ri'=>null,'status'=>'draft','strat'=>null,'src'=>'internal'],
            ['code'=>'RK-2026-0009','title'=>'Equity portfolio mark-to-market losses on NSE downturn','desc'=>'Potential unrealised losses from available-for-sale equity positions during sustained NSE decline.','cat'=>'MR-EQ','bu'=>2,'proc'=>3,'owner'=>3,'il'=>3,'ii'=>3,'rl'=>2,'ri'=>2,'status'=>'active','strat'=>'accept','src'=>'external'],
            ['code'=>'RK-2026-0010','title'=>'Core banking system outage affecting transaction processing','desc'=>'Prolonged downtime of core banking platform impacting all channels including branches, ATMs, USSD, mobile, and internet banking.','cat'=>'OR-TC-1','bu'=>0,'proc'=>1,'owner'=>1,'il'=>4,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0011','title'=>'Ransomware attack on enterprise network','desc'=>'Cyber threat of ransomware targeting IT infrastructure, potentially encrypting critical systems.','cat'=>'OR-TC-2','bu'=>0,'proc'=>1,'owner'=>1,'il'=>3,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0012','title'=>'ATM/POS card skimming and fraud losses','desc'=>'Increasing card skimming at ATM terminals and compromised POS devices leading to unauthorized transactions.','cat'=>'OR-IF','bu'=>0,'proc'=>1,'owner'=>5,'il'=>4,'ii'=>3,'rl'=>3,'ri'=>2,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0013','title'=>'Staff fraud in cash operations and vault management','desc'=>'Internal fraud through cash handling irregularities, teller shortages, and unauthorized vault access.','cat'=>'OR-IF','bu'=>0,'proc'=>1,'owner'=>5,'il'=>3,'ii'=>3,'rl'=>2,'ri'=>2,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0014','title'=>'KYC/AML compliance failure leading to NFIU sanctions','desc'=>'Inadequate customer due diligence and STR reporting exposing the bank to regulatory sanctions.','cat'=>'OR-CP','bu'=>0,'proc'=>4,'owner'=>4,'il'=>3,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0015','title'=>'Third-party payment processor service disruption','desc'=>'Dependency on third-party processors (Interswitch, NIBSS) creating single points of failure.','cat'=>'OR-TC-1','bu'=>0,'proc'=>1,'owner'=>1,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'transfer','src'=>'external'],
            ['code'=>'RK-2026-0016','title'=>'Data breach exposing customer PII and financial records','desc'=>'Potential unauthorized access to customer data through API vulnerabilities or social engineering.','cat'=>'OR-TC-2','bu'=>0,'proc'=>4,'owner'=>1,'il'=>3,'ii'=>5,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0017','title'=>'Business continuity failure during natural disaster','desc'=>'Inability to maintain critical operations during flooding, protests, or other disruptive events.','cat'=>'OR-BC','bu'=>0,'proc'=>1,'owner'=>1,'il'=>2,'ii'=>5,'rl'=>1,'ri'=>4,'status'=>'dormant','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0018','title'=>'CRR compliance shortfall with CBN requirements','desc'=>'Risk of failing to maintain Cash Reserve Ratio at CBN-mandated level, triggering penalties.','cat'=>'LR-FL','bu'=>2,'proc'=>3,'owner'=>2,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0019','title'=>'Deposit concentration and wholesale funding withdrawal','desc'=>'Over-reliance on top 20 depositors creating vulnerability to sudden large-scale withdrawals.','cat'=>'LR-FL','bu'=>2,'proc'=>3,'owner'=>2,'il'=>3,'ii'=>5,'rl'=>2,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0020','title'=>'Fintech disruption eroding retail market share','desc'=>'Aggressive competition from fintechs (OPay, PalmPay, Moniepoint) capturing mass market volumes.','cat'=>'SR-CP','bu'=>0,'proc'=>1,'owner'=>2,'il'=>4,'ii'=>3,'rl'=>3,'ri'=>2,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0021','title'=>'Failure to meet CBN recapitalisation deadline','desc'=>'Risk of not achieving revised minimum capital requirements by CBN deadline.','cat'=>'SR-CP','bu'=>1,'proc'=>2,'owner'=>2,'il'=>2,'ii'=>5,'rl'=>1,'ri'=>4,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0022','title'=>'NDPA data protection compliance gaps','desc'=>'Non-compliance with Nigeria Data Protection Act 2023 requirements for data processing and consent.','cat'=>'OR-CP','bu'=>0,'proc'=>4,'owner'=>4,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0023','title'=>'IFRS 9 ECL model risk and provisioning adequacy','desc'=>'Risk that Expected Credit Loss model produces inaccurate provisions due to data quality issues.','cat'=>'OR-CP','bu'=>1,'proc'=>0,'owner'=>3,'il'=>3,'ii'=>4,'rl'=>2,'ri'=>3,'status'=>'active','strat'=>'mitigate','src'=>'internal'],
            ['code'=>'RK-2026-0024','title'=>'Social media crisis from service outage publicity','desc'=>'Viral negative publicity on Twitter/X from extended service outages causing reputational damage.','cat'=>'RR','bu'=>0,'proc'=>1,'owner'=>1,'il'=>4,'ii'=>3,'rl'=>3,'ri'=>2,'status'=>'active','strat'=>'mitigate','src'=>'external'],
            ['code'=>'RK-2026-0025','title'=>'Adverse media from insider-related party transactions','desc'=>'Reputational risk from public disclosure of related party lending violating CBN governance guidelines.','cat'=>'RR','bu'=>1,'proc'=>0,'owner'=>4,'il'=>2,'ii'=>4,'rl'=>null,'ri'=>null,'status'=>'draft','strat'=>null,'src'=>'internal'],
        ];
    }

    private function getKriDefinitions(): array
    {
        return [
            ['code'=>'KRI-001','name'=>'Non-Performing Loan Ratio','desc'=>'Ratio of non-performing loans to total gross loans per CBN prudential guidelines.','formula'=>'(NPL Balance / Total Gross Loans) * 100','source'=>'Core Banking System','freq'=>'monthly','unit'=>'percentage','dir'=>'higher_worse','gmin'=>0,'gmax'=>3.0,'amin'=>3.0,'amax'=>5.0,'rmin'=>5.0,'rmax'=>100.0,'baseline'=>2.5,'owner'=>1,'vals'=>[2.8,3.1,3.5,3.2,4.1,4.5]],
            ['code'=>'KRI-002','name'=>'Liquidity Coverage Ratio','desc'=>'HQLA divided by total net cash outflows over 30-day stress scenario.','formula'=>'(HQLA / Net Cash Outflows 30d) * 100','source'=>'Treasury Management System','freq'=>'daily','unit'=>'percentage','dir'=>'lower_worse','gmin'=>120.0,'gmax'=>999.0,'amin'=>100.0,'amax'=>120.0,'rmin'=>0,'rmax'=>100.0,'baseline'=>150.0,'owner'=>2,'vals'=>[145.0,138.0,125.0,118.0,105.0,112.0]],
            ['code'=>'KRI-003','name'=>'Core Banking System Uptime','desc'=>'Percentage of time core banking system is available during business hours.','formula'=>'(Available Hours / Business Hours) * 100','source'=>'IT Service Management','freq'=>'monthly','unit'=>'percentage','dir'=>'lower_worse','gmin'=>99.5,'gmax'=>100.0,'amin'=>98.0,'amax'=>99.5,'rmin'=>0,'rmax'=>98.0,'baseline'=>99.8,'owner'=>1,'vals'=>[99.9,99.7,98.5,99.2,97.8,99.1]],
            ['code'=>'KRI-004','name'=>'Cyber Security Incidents','desc'=>'Number of confirmed P1 or P2 cyber security incidents per month.','formula'=>'Count of P1+P2 incidents per month','source'=>'SOC/SIEM Platform','freq'=>'monthly','unit'=>'count','dir'=>'higher_worse','gmin'=>0,'gmax'=>2.0,'amin'=>2.0,'amax'=>5.0,'rmin'=>5.0,'rmax'=>999.0,'baseline'=>1.0,'owner'=>1,'vals'=>[1,0,3,2,1,4]],
            ['code'=>'KRI-005','name'=>'Card Fraud Loss Rate','desc'=>'Card fraud losses as percentage of total card transaction volume.','formula'=>'(Card Fraud Losses / Total Card Txn Volume) * 10000 bps','source'=>'Card Management System','freq'=>'monthly','unit'=>'basis_points','dir'=>'higher_worse','gmin'=>0,'gmax'=>5.0,'amin'=>5.0,'amax'=>10.0,'rmin'=>10.0,'rmax'=>999.0,'baseline'=>3.0,'owner'=>5,'vals'=>[3.5,4.2,6.1,5.8,7.2,8.5]],
            ['code'=>'KRI-006','name'=>'FX Net Open Position Utilisation','desc'=>'Net open position as percentage of CBN limit of 20% of shareholders funds.','formula'=>'(NOP / (SHF * 0.20)) * 100','source'=>'Treasury Management System','freq'=>'daily','unit'=>'percentage','dir'=>'higher_worse','gmin'=>0,'gmax'=>60.0,'amin'=>60.0,'amax'=>85.0,'rmin'=>85.0,'rmax'=>100.0,'baseline'=>45.0,'owner'=>3,'vals'=>[52.0,58.0,65.0,72.0,68.0,78.0]],
            ['code'=>'KRI-007','name'=>'Regulatory Findings Open','desc'=>'Number of CBN examination findings remaining open past remediation deadline.','formula'=>'Count of overdue CBN findings','source'=>'Compliance Tracking System','freq'=>'monthly','unit'=>'count','dir'=>'higher_worse','gmin'=>0,'gmax'=>2.0,'amin'=>2.0,'amax'=>5.0,'rmin'=>5.0,'rmax'=>999.0,'baseline'=>0,'owner'=>4,'vals'=>[1,2,3,3,4,2]],
            ['code'=>'KRI-008','name'=>'Customer Complaints Ratio','desc'=>'Unresolved customer complaints per 10,000 active accounts.','formula'=>'(Unresolved Complaints / Active Accounts) * 10000','source'=>'CRM System','freq'=>'monthly','unit'=>'per_10k_accounts','dir'=>'higher_worse','gmin'=>0,'gmax'=>5.0,'amin'=>5.0,'amax'=>10.0,'rmin'=>10.0,'rmax'=>999.0,'baseline'=>3.0,'owner'=>1,'vals'=>[4.2,5.1,6.3,5.8,7.1,6.5]],
            ['code'=>'KRI-009','name'=>'Capital Adequacy Ratio','desc'=>'Total qualifying capital as percentage of risk-weighted assets per CBN requirements.','formula'=>'(Qualifying Capital / RWA) * 100','source'=>'Finance/Regulatory Reporting','freq'=>'monthly','unit'=>'percentage','dir'=>'lower_worse','gmin'=>18.0,'gmax'=>999.0,'amin'=>15.0,'amax'=>18.0,'rmin'=>0,'rmax'=>15.0,'baseline'=>20.0,'owner'=>2,'vals'=>[19.5,18.8,17.5,16.2,15.8,16.5]],
            ['code'=>'KRI-010','name'=>'Staff Attrition Rate - Risk Function','desc'=>'Monthly voluntary turnover rate of risk management department staff.','formula'=>'(Voluntary Exits / Average Headcount) * 100','source'=>'HR Information System','freq'=>'monthly','unit'=>'percentage','dir'=>'higher_worse','gmin'=>0,'gmax'=>2.0,'amin'=>2.0,'amax'=>5.0,'rmin'=>5.0,'rmax'=>100.0,'baseline'=>1.5,'owner'=>1,'vals'=>[1.2,1.8,2.5,3.1,2.8,2.2]],
        ];
    }
}
