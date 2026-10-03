<?php

namespace App\Support\Bcms;

/**
 * The seventeen resilience KRI definitions — Blueprint §17, phase-11-spec §2.5,
 * ADR 0021 §2.
 *
 * DEFINITIONS ARE CONTENT; MEASUREMENTS STAY WITH THE PRODUCING PHASE. This
 * catalogue is adopted per tenant by `App\Services\Bcms\ResilienceKriPublisher`
 * — the same shape TPRM's `KriPublisher`/`KriCatalogue` already settled:
 * `adopt()` creates whatever is missing by `kri_code` and never overwrites a
 * threshold, name or owner a tenant has since tuned. There is no
 * `bcms_kri_links` table — unlike TPRM, BCMS's own internal metric code and the
 * register's `kri_code` are the SAME string (`BCMS-*`), so the join is
 * `kri_code` directly and a link table would be a second name for one thing.
 *
 * SIXTEEN OF SEVENTEEN ARE MEASURED ELSEWHERE. `BCMS-VENDOR-ATTEST` is the one
 * measurement this phase writes itself (§4 of the spec, supplier resilience);
 * every other code is filed by the phase that already computes it — four by
 * `TreeHealthService` today, the rest by phases 4, 5, 7, 9 and 10 as and when
 * they wire their own producer onto `KriMeasureBridge::recordMeasurement()`.
 * Seeding a definition here is not a promise that this phase measures it.
 *
 * THE THRESHOLDS SHIPPED HERE ARE DEFAULTS, DERIVED FROM ONE TARGET NUMBER PER
 * KRI, not a second number the spec does not give. A tenant that wants a
 * different amber band edits it on the KRI screen once adopted, and
 * `adopt()`'s idempotency is what keeps that edit safe across a re-run.
 */
class ResilienceKris
{
    public const LOWER_IS_WORSE = 'lower_is_worse';

    public const HIGHER_IS_WORSE = 'higher_is_worse';

    /**
     * @return list<array{
     *     kri_code: string, name: string, description: string, unit: string,
     *     direction: string, target: float, frequency: string, producer: string
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'kri_code' => 'BCMS-EX-COMPLETION',
                'name' => 'Exercise programme completion rate',
                'description' => 'Of the exercises approved in this year\'s programme, how many were actually delivered.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 95.0,
                'frequency' => 'quarterly', 'producer' => 'Phase 4 / Phase 9',
            ],
            [
                'kri_code' => 'BCMS-EX-ONDATE',
                'name' => 'Exercises executed on originally scheduled date',
                'description' => 'Whether the countdown alerting actually keeps an exercise on the date it was scheduled for, rather than slipping.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 80.0,
                'frequency' => 'quarterly', 'producer' => 'Phase 4',
            ],
            [
                'kri_code' => 'BCMS-RD-T1',
                'name' => 'Readiness task completion by T-1',
                'description' => 'Blocking and non-blocking readiness tasks cleared by the day before an exercise.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 90.0,
                'frequency' => 'monthly', 'producer' => 'Phase 5',
            ],
            [
                'kri_code' => 'BCMS-AAR-7D',
                'name' => 'AAR completed within 7 days of exercise',
                'description' => 'How quickly the after-action report is finalised once an occurrence closes.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 95.0,
                'frequency' => 'monthly', 'producer' => 'Phase 9',
            ],
            [
                'kri_code' => 'BCMS-CAPA-ONTIME',
                'name' => 'Corrective actions closed by due date',
                'description' => 'Of the corrective actions raised, how many were closed on or before their own due date.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 85.0,
                'frequency' => 'monthly', 'producer' => 'Phase 1',
            ],
            [
                'kri_code' => 'BCMS-PLAN-CURRENT',
                'name' => 'Plans reviewed within cycle',
                'description' => 'Approved plans whose next review date has not yet passed.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 100.0,
                'frequency' => 'monthly', 'producer' => 'Phase 3',
            ],
            [
                'kri_code' => 'BCMS-CT-COMPLETION',
                'name' => 'Call tree cascade completion',
                'description' => 'Mean completion rate of call tree tests run in the last twelve months.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 98.0,
                'frequency' => 'monthly', 'producer' => 'Phase 6 — already computed',
            ],
            [
                'kri_code' => 'BCMS-CT-CONFIDENCE',
                'name' => 'Call tree data confidence (verified contacts)',
                'description' => 'Active contacts verified within the last ninety days.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 95.0,
                'frequency' => 'monthly', 'producer' => 'Phase 6 — already computed',
            ],
            [
                'kri_code' => 'BCMS-CT-STALE',
                'name' => 'Call trees overdue for review',
                'description' => 'Current call trees past their review cadence.',
                'unit' => 'trees', 'direction' => self::HIGHER_IS_WORSE, 'target' => 0.0,
                'frequency' => 'monthly', 'producer' => 'Phase 6 — already computed',
            ],
            [
                'kri_code' => 'BCMS-CT-DEPUTY-GAP',
                'name' => 'Must-reach nodes with no deputy',
                'description' => 'Nodes marked must-reach on a call tree with nobody to escalate to.',
                'unit' => 'nodes', 'direction' => self::HIGHER_IS_WORSE, 'target' => 0.0,
                'frequency' => 'monthly', 'producer' => 'Phase 6 — already computed',
            ],
            [
                'kri_code' => 'BCMS-EMNS-ACK15',
                'name' => 'EMNS acknowledgement within 15 minutes',
                'description' => 'Alert recipients who acknowledged within fifteen minutes of dispatch.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 90.0,
                'frequency' => 'monthly', 'producer' => 'Phase 7',
            ],
            [
                'kri_code' => 'BCMS-T1-TESTED',
                'name' => 'Tier-1 processes with tested recovery in last 12 months',
                'description' => 'Tier-1 processes with a recovery test above a walkthrough in the last year.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 100.0,
                'frequency' => 'quarterly', 'producer' => 'Phase 9 (LadderAdvisor::coverageMatrix())',
            ],
            [
                'kri_code' => 'BCMS-DR-RTO',
                'name' => 'RTO achievement rate in DR tests',
                'description' => 'DR tests in the period that met their recovery time objective.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 90.0,
                'frequency' => 'quarterly', 'producer' => 'Phase 10',
            ],
            [
                'kri_code' => 'BCMS-SMS-DELIVERY',
                'name' => 'Per-provider SMS delivery rate',
                'description' => 'SMS notifications confirmed delivered, by provider.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 95.0,
                'frequency' => 'monthly', 'producer' => 'Phase 7 (ProviderHealth)',
            ],
            [
                'kri_code' => 'BCMS-DISPATCH-LAG',
                'name' => 'EMNS dispatch latency (p95, seconds)',
                'description' => 'Ninety-fifth percentile time from approval to first delivery attempt.',
                'unit' => 'seconds', 'direction' => self::HIGHER_IS_WORSE, 'target' => 60.0,
                'frequency' => 'monthly', 'producer' => 'Phase 7',
            ],
            [
                'kri_code' => 'BCMS-SCHED-LAG',
                'name' => 'Scheduler lag (minutes)',
                'description' => 'How far behind schedule the T-10 countdown watchdog is running.',
                'unit' => 'minutes', 'direction' => self::HIGHER_IS_WORSE, 'target' => 15.0,
                'frequency' => 'monthly', 'producer' => 'Phase 5 watchdog',
            ],
            [
                'kri_code' => 'BCMS-VENDOR-ATTEST',
                'name' => 'Critical vendors with current continuity evidence',
                'description' => 'BCMS-critical vendors whose latest BCP test evidence has not passed its next-due date.',
                'unit' => '%', 'direction' => self::LOWER_IS_WORSE, 'target' => 95.0,
                'frequency' => 'monthly', 'producer' => 'Phase 11 (supplier resilience)',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $kriCode): ?array
    {
        foreach (self::all() as $definition) {
            if ($definition['kri_code'] === $kriCode) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::all(), 'kri_code');
    }
}
