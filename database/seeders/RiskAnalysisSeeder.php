<?php

namespace Database\Seeders;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\LossEvent;
use App\Models\KeyRiskIndicator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RiskAnalysisSeeder extends Seeder
{
    /**
     * Seed data required for analysis views: heatmap, bow-tie, trends, correlation.
     */
    public function run(): void
    {
        $orgId = 1;
        $userId = 1;

        $this->command->info('Seeding risk analysis data...');

        $risks = Risk::where('organization_id', $orgId)->get();

        if ($risks->isEmpty()) {
            $this->command->warn('  No risks found. Run DemoDataSeeder first.');
            return;
        }

        // Ensure all risks have inherent_score computed
        foreach ($risks as $risk) {
            if (!$risk->inherent_score && $risk->inherent_likelihood && $risk->inherent_impact) {
                $score = $risk->inherent_likelihood * $risk->inherent_impact;
                $rating = $score >= 20 ? 'Critical' : ($score >= 12 ? 'High' : ($score >= 5 ? 'Medium' : 'Low'));
                $risk->update(['inherent_score' => $score, 'inherent_rating' => $rating]);
            }
        }

        $this->seedRiskAssessments($orgId, $userId, $risks);
        $this->seedLossEvents($orgId, $userId, $risks);
        $this->seedKriBreaches($orgId, $risks);
        $this->enrichRisksForBowtie($risks);

        $this->command->info('Risk analysis seeding complete!');
    }

    private function seedRiskAssessments(int $orgId, int $userId, $risks): void
    {
        if (!DB::getSchemaBuilder()->hasTable('risk_assessments')) {
            $this->command->warn('  Skipping risk assessments - table does not exist');
            return;
        }

        $count = 0;
        foreach ($risks->take(15) as $risk) {
            for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo -= 3) {
                $assessmentDate = now()->subMonths($monthsAgo);
                $likelihood = $risk->inherent_likelihood ?? 3;
                $impactFin  = $risk->inherent_impact ?? 3;

                // Simulate slight variation over time
                $variation = rand(-1, 1);
                $adjustedLikelihood = max(1, min(5, $likelihood + $variation));
                $adjustedImpact     = max(1, min(5, $impactFin + rand(-1, 0)));
                $overallScore = $adjustedLikelihood * $adjustedImpact;
                $rating = $overallScore >= 20 ? 'Critical' : ($overallScore >= 12 ? 'High' : ($overallScore >= 5 ? 'Medium' : 'Low'));

                try {
                    RiskAssessment::updateOrCreate(
                        [
                            'organization_id' => $orgId,
                            'risk_id'         => $risk->id,
                            'assessment_date' => $assessmentDate->format('Y-m-d'),
                        ],
                        [
                            'assessment_type'      => 'periodic',
                            'status'               => 'approved',
                            'likelihood_score'     => $adjustedLikelihood,
                            'impact_financial'     => $adjustedImpact,
                            'impact_operational'   => max(1, $adjustedImpact + rand(-1, 1)),
                            'impact_reputational'  => max(1, $adjustedImpact + rand(-2, 0)),
                            'impact_regulatory'    => max(1, $adjustedImpact + rand(-1, 0)),
                            'impact_score'         => $adjustedImpact,
                            'overall_score'        => $overallScore,
                            'overall_rating'       => $rating,
                            'residual_likelihood'  => max(1, $adjustedLikelihood - rand(0, 2)),
                            'residual_impact'      => max(1, $adjustedImpact - rand(0, 1)),
                            'residual_score'       => max(1, ($adjustedLikelihood - 1) * ($adjustedImpact - 1)),
                            'residual_rating'      => 'Medium',
                            'assessor_id'          => $userId,
                            'approved_by'          => $userId,
                        ]
                    );
                    $count++;
                } catch (\Exception $e) {
                    // Log and break on first failure (schema issue)
                    $this->command->warn("  Assessment seed error: " . $e->getMessage());
                    break 2;
                }
            }
        }

        $this->command->info("  Created {$count} risk assessments for trend analysis");
    }

    private function seedLossEvents(int $orgId, int $userId, $risks): void
    {
        if (!DB::getSchemaBuilder()->hasTable('loss_events')) {
            $this->command->warn('  Skipping loss events - table does not exist');
            return;
        }

        $existingCount = LossEvent::where('organization_id', $orgId)->count();
        if ($existingCount >= 10) {
            $this->command->info("  Loss events already seeded ({$existingCount} records), skipping");
            return;
        }

        // [ref, title, desc, gross_kobo, recovery_kobo, risk_idx, basel_l1, cbn_cat]
        $lossEventsData = [
            ['LE-2025-001', 'Unauthorized card transactions', 'Card fraud via skimming at Lagos ATMs', 4500000000, 1200000000, 5, 'External Fraud', 'Fraud'],
            ['LE-2025-002', 'Core banking outage - 8 hrs', 'Database failover failure during maintenance', 12000000000, 0, 3, 'Business Disruption & System Failures', 'Technology'],
            ['LE-2025-003', 'FX trading loss from devaluation', 'Unhedged position exposed to 15% move', 35000000000, 5000000000, 2, 'Execution Delivery & Process Mgmt', 'Trading'],
            ['LE-2025-004', 'CBN fine for AML breach', 'Late STR filings above 72-hour deadline', 20000000000, 0, 4, 'Clients Products & Business Practices', 'Compliance'],
            ['LE-2025-005', 'Phishing attack on email', 'BEC leading to wire fraud', 8500000000, 3000000000, 1, 'External Fraud', 'Fraud'],
            ['LE-2025-006', 'Data breach - 5,000 records', 'SQL injection in mobile API', 15000000000, 0, 0, 'External Fraud', 'Technology'],
            ['LE-2026-001', 'Branch robbery at Ikeja', 'Armed robbery during CIT', 2500000000, 2000000000, 5, 'Damage to Physical Assets', 'Physical Security'],
            ['LE-2026-002', 'Staff loan fraud', 'Fictitious loan to ghost accounts', 18000000000, 5000000000, 6, 'Internal Fraud', 'Fraud'],
            ['LE-2026-003', 'NEFT processing failure', 'Delayed NEFT clearing', 3000000000, 0, 3, 'Business Disruption & System Failures', 'Technology'],
            ['LE-2026-004', 'Vendor data leak', 'Cloud misconfiguration exposed PII', 9500000000, 0, 0, 'External Fraud', 'Technology'],
            ['LE-2026-005', 'Interest rate model error', 'Pricing model underpriced loans', 28000000000, 0, 2, 'Execution Delivery & Process Mgmt', 'Model Risk'],
            ['LE-2026-006', 'Data center power failure', 'UPS failure caused 4-hour outage', 4500000000, 0, 3, 'Business Disruption & System Failures', 'Technology'],
        ];

        $count = 0;
        $riskIds = $risks->pluck('id')->toArray();

        foreach ($lossEventsData as $idx => $le) {
            $monthsAgo  = rand(0, 11);
            $dateOfLoss = now()->subMonths($monthsAgo)->subDays(rand(0, 28));
            $grossKobo  = $le[3];
            $recoveryKobo = $le[4];
            $severity   = $grossKobo >= 20000000000 ? 'HIGH' : ($grossKobo >= 5000000000 ? 'MEDIUM' : 'LOW');
            $status     = $idx < 8 ? 'CLOSED' : 'UNDER_INVESTIGATION';

            try {
                LossEvent::updateOrCreate(
                    ['organization_id' => $orgId, 'event_reference' => $le[0]],
                    [
                        'organization_id'       => $orgId,
                        'event_reference'       => $le[0],
                        'title'                 => $le[1],
                        'description'           => $le[2],
                        'risk_register_id'      => $riskIds[$le[5] % count($riskIds)] ?? $riskIds[0],
                        'date_of_loss'          => $dateOfLoss,
                        'date_discovered'       => $dateOfLoss->copy()->addDays(rand(0, 3)),
                        'date_reported'         => $dateOfLoss->copy()->addDays(rand(1, 5)),
                        'business_unit_id'      => 1,
                        'department'            => 'Operations',
                        'responsible_officer_id' => $userId,
                        'basel_l1_category'     => $le[6],
                        'basel_l2_category'     => $le[6],
                        'cbn_risk_category'     => $le[7],
                        'cbn_orms_event_type'   => $le[7],
                        'cbn_product_line'      => 'Commercial Banking',
                        'gross_loss_amount_kobo' => $grossKobo,
                        'actual_recovery_kobo'  => $recoveryKobo,
                        'other_recovery_kobo'   => 0,
                        'insurance_recovery_kobo' => 0,
                        'pending_recovery_kobo' => 0,
                        'loss_category'         => 'Operational',
                        'event_severity'        => $severity,
                        'current_status'        => $status,
                        // Alignment columns for controller compatibility
                        'gross_loss_amount'     => round($grossKobo / 100, 2),
                        'net_loss_amount'       => round(($grossKobo - $recoveryKobo) / 100, 2),
                        'recovery_amount'       => round($recoveryKobo / 100, 2),
                        'status'                => strtolower($status),
                        'severity'              => strtolower($severity),
                        'reported_by'           => $userId,
                        'created_by'            => $userId,
                    ]
                );
                $count++;
            } catch (\Exception $e) {
                $this->command->warn("  Loss event seed error: " . $e->getMessage());
                continue;
            }
        }

        $this->command->info("  Created {$count} loss events for trend analysis");
    }

    private function seedKriBreaches(int $orgId, $risks): void
    {
        if (!DB::getSchemaBuilder()->hasTable('key_risk_indicators')) {
            $this->command->warn('  Skipping KRI data - table does not exist');
            return;
        }

        $existingCount = KeyRiskIndicator::where('organization_id', $orgId)->count();
        if ($existingCount >= 5) {
            $this->command->info("  KRIs already seeded ({$existingCount} records), skipping");
            return;
        }

        $this->command->info("  KRI seeding skipped - DemoDataSeeder handles KRIs");
    }

    private function enrichRisksForBowtie($risks): void
    {
        // Check if risks table has risk_trigger column
        if (!DB::getSchemaBuilder()->hasColumn('risks', 'risk_trigger')) {
            $this->command->info("  Skipping bowtie enrichment - risk_trigger column not found");
            return;
        }

        $causesData = [
            'Inadequate internal controls and governance framework',
            'Human error in transaction processing',
            'External cyber threat actors targeting vulnerabilities',
            'Macroeconomic downturn affecting borrower repayment',
            'Regulatory changes requiring rapid adaptation',
            'Third-party vendor service failures',
            'Technology obsolescence and legacy systems',
        ];

        $consequencesData = [
            'Direct financial loss and reduced profitability',
            'Regulatory sanctions and CBN penalties',
            'Reputational damage and loss of customer confidence',
            'Increased capital requirements and RWA charges',
            'Legal liability and litigation costs',
            'Business disruption and operational downtime',
        ];

        $count = 0;
        foreach ($risks->take(10) as $idx => $risk) {
            $updates = [];

            if (empty($risk->risk_trigger)) {
                $selectedCauses = array_slice($causesData, $idx % 4, 3);
                $updates['risk_trigger'] = implode("\n", $selectedCauses);
            }

            if (DB::getSchemaBuilder()->hasColumn('risks', 'risk_consequence') && empty($risk->risk_consequence)) {
                $selectedConsequences = array_slice($consequencesData, $idx % 3, 3);
                $updates['risk_consequence'] = implode("\n", $selectedConsequences);
            }

            if (!empty($updates)) {
                try {
                    $risk->update($updates);
                    $count++;
                } catch (\Exception $e) {
                    // skip gracefully
                }
            }
        }

        if ($count > 0) {
            $this->command->info("  Enriched {$count} risks with bow-tie cause/consequence data");
        }
    }
}
