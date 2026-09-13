<?php

namespace App\Services;

use App\Models\LossEvent;

class RegulatoryThresholdService
{
    // CBN mandatory reporting threshold: NGN 5,000,000 (500,000,000 kobo)
    const CBN_THRESHOLD_KOBO = 500000000;

    // NFIU STR threshold for single cash transaction: NGN 5,000,000
    const NFIU_STR_THRESHOLD_KOBO = 500000000;

    // NFIU CTR aggregate daily threshold: NGN 10,000,000
    const NFIU_CTR_THRESHOLD_KOBO = 1000000000;

    // NDIC deposit exposure threshold: NGN 500,000
    const NDIC_THRESHOLD_KOBO = 50000000;

    // EFCC fraud reporting threshold: NGN 1,000,000
    const EFCC_THRESHOLD_KOBO = 100000000;

    /**
     * Evaluate all Nigerian regulatory thresholds for a loss event
     * Returns array of regulatory alerts
     */
    public function evaluateThresholds(LossEvent $event): array
    {
        $alerts = [];

        // CBN Reporting
        if ($event->gross_loss_amount_kobo >= self::CBN_THRESHOLD_KOBO) {
            $event->update([
                'cbn_reportable' => true,
                'cbn_reporting_deadline' => now()->addDays(7)->toDateString(),
            ]);

            $alerts[] = [
                'type' => 'CBN_REPORTING_REQUIRED',
                'message' => 'Gross loss exceeds CBN mandatory reporting threshold of NGN 5,000,000. CBN notification required within 7 days.',
                'deadline' => now()->addDays(7)->toIso8601String(),
                'regulatory_ref' => 'CBN BSD/DIR/GEN/LAB/07/014',
            ];
        }

        // NFIU STR - Suspicious patterns
        if (in_array($event->basel_l1_category, ['INTERNAL_FRAUD', 'EXTERNAL_FRAUD']) ||
            $event->nfiu_report_type === 'STR') {
            $event->update(['nfiu_reportable' => true]);

            $alerts[] = [
                'type' => 'NFIU_STR_REQUIRED',
                'message' => 'Loss event involves suspected fraud. NFIU STR must be filed within 24 hours per AML/CFT Act 2022 Section 6(1).',
                'deadline' => now()->addHours(24)->toIso8601String(),
                'regulatory_ref' => 'AML/CFT Act 2022 §6(1); NFIU Regulations 2024 §4.2',
            ];
        }

        // NDIC Reporting
        if ($event->gross_loss_amount_kobo >= self::NDIC_THRESHOLD_KOBO) {
            $event->update(['ndic_reportable' => true]);

            $alerts[] = [
                'type' => 'NDIC_REPORTING_REQUIRED',
                'message' => 'Loss event may affect insured deposits. NDIC notification required.',
                'regulatory_ref' => 'NDIC Act 2023 §41',
            ];
        }

        // EFCC - Fraud above threshold
        if (in_array($event->basel_l1_category, ['INTERNAL_FRAUD', 'EXTERNAL_FRAUD']) &&
            $event->gross_loss_amount_kobo >= self::EFCC_THRESHOLD_KOBO) {
            $alerts[] = [
                'type' => 'EFCC_REPORTING_RECOMMENDED',
                'message' => 'Fraud event exceeds NGN 1,000,000. EFCC reporting is mandatory.',
                'regulatory_ref' => 'EFCC Act 2004 (as amended)',
            ];
        }

        // Law enforcement for cyber-enabled fraud
        if (in_array($event->cbn_risk_category, ['TECHNOLOGY_RISK']) &&
            in_array($event->basel_l1_category, ['EXTERNAL_FRAUD', 'INTERNAL_FRAUD'])) {
            $alerts[] = [
                'type' => 'LAW_ENFORCEMENT_NOTIFICATION',
                'message' => 'Cyber-enabled fraud requires law enforcement notification within 24 hours per CBN Cyber Security Framework 2021.',
                'deadline' => now()->addHours(24)->toIso8601String(),
                'regulatory_ref' => 'CBN Cyber Security Framework 2021',
            ];
        }

        return $alerts;
    }
}
