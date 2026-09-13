<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enterprise Gap Seeder — idempotent.
 *
 * Fills demo-data gaps for tables that were empty or nearly empty:
 * issue_attachments, control_test_evidence, workflow_instances/actions,
 * regulatory_filings, issue_escalation_rules, issue_escalation_log, and
 * open/investigating near_misses.
 *
 * Each section is guarded by a row-count check so re-running the seeder
 * never duplicates data.
 *
 *     php artisan db:seed --class=EnterpriseGapSeeder
 */
class EnterpriseGapSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedIssueAttachments($now);
        $this->seedControlTestEvidence($now);
        $this->seedWorkflows($now);
        $this->seedRegulatoryFilings($now);
        $this->seedEscalationRules($now);
        $this->seedEscalationLog($now);
        $this->seedNearMisses($now);
    }

    /* ------------------------------------------------------------------ */
    /*  1. Issue attachments */
    /* ------------------------------------------------------------------ */

    protected function seedIssueAttachments($now): void
    {
        if (DB::table('issue_attachments')->count() > 0) {
            $this->command?->info('issue_attachments already seeded — skipping.');

            return;
        }

        $issueIds = DB::table('issues')->orderBy('id')->pluck('id')->all();
        if (empty($issueIds)) {
            return;
        }

        // [issue index (into $issueIds), file_name, size, type, document_type, is_regulatory, uploaded_by]
        $rows = [
            [0, 'remediation_plan_ATM_reconciliation.xlsx', 148_212, 'xlsx', 'remediation_plan', false, 2],
            [0, 'closure_evidence_Q2.pdf', 812_455, 'pdf', 'evidence', false, 4],
            [1, 'CBN_examination_response_letter.pdf', 1_204_887, 'pdf', 'report', true, 5],
            [2, 'internal_audit_finding_workpaper.pdf', 645_320, 'pdf', 'report', false, 4],
            [3, 'IT_patch_deployment_log_June2026.xlsx', 96_774, 'xlsx', 'evidence', false, 2],
            [4, 'revised_AML_monitoring_procedure_v3.docx', 388_140, 'docx', 'remediation_plan', true, 5],
            [5, 'vendor_SLA_review_minutes.pdf', 273_902, 'pdf', 'evidence', false, 6],
            [6, 'staff_training_attendance_register.pdf', 154_631, 'pdf', 'evidence', false, 4],
            [7, 'reconciliation_exception_report_May2026.xlsx', 210_449, 'xlsx', 'report', false, 2],
        ];

        $insert = [];
        foreach ($rows as [$idx, $name, $size, $type, $docType, $regulatory, $uploadedBy]) {
            $issueId = $issueIds[$idx % count($issueIds)];
            $insert[] = [
                'uuid' => (string) Str::uuid(),
                'issue_id' => $issueId,
                'file_name' => $name,
                'file_size_bytes' => $size,
                'file_type' => $type,
                'storage_path' => "issue-attachments/{$issueId}/{$name}",
                'document_type' => $docType,
                'is_regulatory' => $regulatory,
                'uploaded_by' => $uploadedBy,
                'created_at' => $now->copy()->subDays(rand(5, 60)),
                'updated_at' => $now,
            ];
        }

        DB::table('issue_attachments')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' issue_attachments.');
    }

    /* ------------------------------------------------------------------ */
    /*  2. Control test evidence */
    /* ------------------------------------------------------------------ */

    protected function seedControlTestEvidence($now): void
    {
        if (DB::table('control_test_evidence')->count() > 2) {
            $this->command?->info('control_test_evidence already seeded — skipping.');

            return;
        }

        $testIds = DB::table('control_tests')->orderBy('id')->pluck('id')->all();
        if (empty($testIds)) {
            return;
        }

        $rows = [
            ['sample_selection_worksheet.xlsx', 'xlsx', 84_223, 'Sample of 25 transactions selected for re-performance testing.', 4],
            ['test_execution_screenshots.pdf', 'pdf', 1_534_002, 'Screenshots evidencing maker-checker enforcement in core banking.', 4],
            ['access_review_report_Q2_2026.pdf', 'pdf', 692_310, 'Quarterly privileged access review output from IAM tool.', 2],
            ['reconciliation_signoff_June2026.pdf', 'pdf', 233_871, 'Signed-off daily GL reconciliation for the test period.', 6],
            ['exception_log_extract.csv', 'csv', 41_990, 'Exception log extract showing all overrides during sample window.', 4],
            ['BCP_failover_test_report.pdf', 'pdf', 903_144, 'DR failover test results including RTO/RPO measurements.', 2],
            ['walkthrough_notes_credit_approval.docx', 'docx', 128_450, 'Walkthrough notes with the credit operations team.', 4],
            ['limit_monitoring_dashboard_export.xlsx', 'xlsx', 176_208, 'Treasury limit monitoring dashboard export for testing period.', 6],
        ];

        $insert = [];
        foreach ($rows as $i => [$name, $type, $size, $desc, $uploadedBy]) {
            $testId = $testIds[($i * 2) % count($testIds)];
            $insert[] = [
                'control_test_id' => $testId,
                'file_name' => $name,
                'file_path' => "control-test-evidence/{$testId}/{$name}",
                'file_type' => $type,
                'file_size' => $size,
                'description' => $desc,
                'uploaded_by' => $uploadedBy,
                'created_at' => $now->copy()->subDays(rand(10, 90)),
                'updated_at' => $now,
            ];
        }

        DB::table('control_test_evidence')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' control_test_evidence rows.');
    }

    /* ------------------------------------------------------------------ */
    /*  3. Workflow instances + actions */
    /* ------------------------------------------------------------------ */

    /**
     * WP-06 — demo data for the workflow engine.
     *
     * Rewritten from the pre-WP-06 version, which inserted workflow_instances
     * directly with an FQCN entity_type, no organization_id, no current_nodes
     * and a risk_assessment definition pointed at a Risk. None of those rows
     * could be advanced by the engine, so the demo showed a workflow screen
     * with nothing that worked.
     *
     * These are started THROUGH the engine, so every one of them is a real
     * running process: the tasks are assignable, the SLAs are live, and the
     * escalation sweeper acts on them.
     */
    protected function seedWorkflows($now): void
    {
        if (DB::table('workflow_tasks')->count() > 0) {
            $this->command?->info('workflow tasks already seeded — skipping.');

            return;
        }

        $organizationId = (int) (DB::table('organizations')->orderBy('id')->value('id') ?? 0);

        if ($organizationId === 0) {
            return;
        }

        \ThirdLine\Platform\Tenancy\TenantContext::actingAs($organizationId, function () use ($organizationId, $now) {
            $engine = app(\App\Services\Workflow\WorkflowEngine::class);
            $initiator = \App\Models\User::where('organization_id', $organizationId)->orderBy('id')->first();

            $started = 0;

            $subjects = [
                ['loss_event_approval', \App\Models\LossEvent::class, 3],
                ['treatment_plan_approval', \App\Models\TreatmentPlan::class, 2],
                ['issue_closure_approval', \App\Models\Issue::class, 2],
                ['risk_assessment_approval', \App\Models\RiskAssessment::class, 3],
            ];

            foreach ($subjects as [$code, $class, $count]) {
                $records = $class::where('organization_id', $organizationId)
                    ->orderByDesc('id')
                    ->limit($count)
                    ->get();

                foreach ($records as $index => $record) {
                    if ($engine->openInstanceFor($record) !== null) {
                        continue;
                    }

                    $instance = $engine->startFor($code, $record, [], $initiator);

                    if ($instance === null) {
                        continue;
                    }

                    $started++;

                    // Backdate the first one of each kind so the queue shows a
                    // breach, and the SLA sweeper has something to act on the
                    // first time it runs in a demo environment.
                    if ($index === 0) {
                        $instance->tasks()->update([
                            'created_at' => $now->copy()->subDays(6),
                            'due_at' => $now->copy()->subDays(2),
                        ]);
                        $instance->forceFill([
                            'started_at' => $now->copy()->subDays(6),
                            'sla_due_at' => $now->copy()->subDays(2),
                        ])->save();
                    }

                    // Take the second one all the way through, so the history
                    // and the completed counters are not empty either.
                    if ($index === 1) {
                        $task = $instance->openTasks()->first();

                        if ($task !== null) {
                            $engine->advance($task, 'approve', [
                                'comments' => 'Reviewed against the evidence attached. Approved.',
                            ], $initiator);
                        }
                    }
                }
            }

            $this->command?->info('Started '.$started.' workflow instances through the engine.');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  4. Regulatory filings */
    /* ------------------------------------------------------------------ */

    protected function seedRegulatoryFilings($now): void
    {
        if (DB::table('regulatory_filings')->count() > 1) {
            $this->command?->info('regulatory_filings already seeded — skipping.');

            return;
        }

        $deadlineIds = DB::table('regulatory_deadlines')->orderBy('id')->pluck('id')->all();
        if (empty($deadlineIds)) {
            return;
        }

        // [deadline index, days ago, filed_by, status, document_ref, notes]
        $rows = [
            [0, 34, 6, 'accepted', 'CBN/RET/2026/0142', 'June ORMS return submitted via FinA portal and acknowledged by CBN.'],
            [0, 4, 6, 'submitted', 'CBN/RET/2026/0198', 'July ORMS return submitted; acknowledgement pending.'],
            [1, 48, 3, 'accepted', 'CBN/ICAAP/2026/0027', 'H1 2026 ICAAP document submitted with board approval minutes attached.'],
            [2, 33, 5, 'resubmitted', 'CBN/CAR/2026/0611', 'Initial June CAR return rejected due to Tier 2 capital misclassification; corrected and resubmitted.'],
            [3, 20, 5, 'accepted', 'CBN/CRM/2026/0356', 'Q2 credit risk management return accepted without query.'],
            [4, 95, 2, 'accepted', 'NDIC/PRM/2026/0074', '2026 premium declaration filed with NDIC; assessment notice received.'],
            [6, 10, 2, 'draft', 'CBN/STR/2026/0489', 'Annual stress testing results report in final internal review before submission.'],
            [7, 26, 6, 'rejected', 'CBN/MRK/2026/0233', 'Q2 market risk exposure report rejected — FX net open position schedule incomplete. Resubmission in progress.'],
        ];

        $insert = [];
        foreach ($rows as [$idx, $daysAgo, $filedBy, $status, $ref, $notes]) {
            $insert[] = [
                'deadline_id' => $deadlineIds[$idx % count($deadlineIds)],
                'filing_date' => $now->copy()->subDays($daysAgo)->toDateString(),
                'filed_by' => $filedBy,
                'status' => $status,
                'document_ref' => $ref,
                'notes' => $notes,
                'created_at' => $now->copy()->subDays($daysAgo),
                'updated_at' => $now,
            ];
        }

        DB::table('regulatory_filings')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' regulatory_filings.');
    }

    /* ------------------------------------------------------------------ */
    /*  5. Issue escalation rules */
    /* ------------------------------------------------------------------ */

    protected function seedEscalationRules($now): void
    {
        if (DB::table('issue_escalation_rules')->count() > 0) {
            $this->command?->info('issue_escalation_rules already seeded — skipping.');

            return;
        }

        // [priority, issue_source, level, role, days_overdue]
        $rules = [
            ['CRITICAL', null, 1, 'risk-manager', 7],
            ['CRITICAL', null, 2, 'chief-risk-officer', 14],
            ['CRITICAL', 'CBN_EXAMINATION', 1, 'compliance-officer', 7],
            ['HIGH', null, 1, 'issue-manager', 14],
            ['HIGH', null, 2, 'risk-manager', 30],
            ['HIGH', 'INTERNAL_AUDIT', 3, 'chief-risk-officer', 60],
            ['MEDIUM', null, 1, 'issue-manager', 30],
            ['MEDIUM', 'COMPLIANCE_REVIEW', 2, 'compliance-officer', 60],
        ];

        $insert = [];
        foreach ($rules as [$priority, $source, $level, $role, $days]) {
            $insert[] = [
                'organization_id' => 1,
                'priority' => $priority,
                'issue_source' => $source,
                'escalation_level' => $level,
                'escalation_to_role' => $role,
                'days_overdue_trigger' => $days,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('issue_escalation_rules')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' issue_escalation_rules.');
    }

    /* ------------------------------------------------------------------ */
    /*  6. Issue escalation log */
    /* ------------------------------------------------------------------ */

    protected function seedEscalationLog($now): void
    {
        if (DB::table('issue_escalation_log')->count() > 5) {
            $this->command?->info('issue_escalation_log already seeded — skipping.');

            return;
        }

        $issueIds = DB::table('issues')->orderBy('id')->pluck('id')->all();
        if (empty($issueIds)) {
            return;
        }

        // [issue index, level, role, to_user, is_auto, reason, escalated_by, days ago, acknowledged]
        $rows = [
            [1, 1, 'risk-manager', 2, true, 'Auto-escalated: issue exceeded 7 days past target closure date (CRITICAL priority rule).', null, 9, true],
            [2, 1, 'issue-manager', 4, true, 'Auto-escalated: remediation overdue by 14 days with no status update recorded.', null, 6, false],
            [3, 2, 'chief-risk-officer', 3, true, 'Auto-escalated: level 1 escalation unacknowledged after 14 days on CBN examination finding.', null, 3, false],
            [4, 1, 'compliance-officer', 5, false, 'Manually escalated ahead of CBN follow-up examination — evidence of remediation required before examiner arrival.', 2, 11, true],
            [5, 1, 'risk-manager', 2, false, 'Escalated by issue owner: dependency on vendor patch release is blocking remediation beyond agreed date.', 4, 5, false],
        ];

        $insert = [];
        foreach ($rows as [$idx, $level, $role, $toUser, $auto, $reason, $by, $daysAgo, $ack]) {
            $insert[] = [
                'issue_id' => $issueIds[$idx % count($issueIds)],
                'escalation_level' => $level,
                'escalated_to_role' => $role,
                'escalated_to_user_id' => $toUser,
                'is_auto' => $auto,
                'reason' => $reason,
                'acknowledged' => $ack,
                'acknowledged_at' => $ack ? $now->copy()->subDays(max($daysAgo - 2, 0)) : null,
                'escalated_by' => $by,
                'escalated_at' => $now->copy()->subDays($daysAgo),
            ];
        }

        DB::table('issue_escalation_log')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' issue_escalation_log rows.');
    }

    /* ------------------------------------------------------------------ */
    /*  7. Open / investigating near misses */
    /* ------------------------------------------------------------------ */

    protected function seedNearMisses($now): void
    {
        // Determine next reference number from the existing NM-2026-XXX sequence.
        $maxRef = DB::table('near_misses')
            ->where('reference', 'like', 'NM-2026-%')
            ->max('reference');
        $next = $maxRef ? ((int) substr($maxRef, -3)) + 1 : 1;

        if ($next > 9) {
            // Sequence already extended beyond the base 8 — assume seeded.
            $this->command?->info('near_misses already extended — skipping.');

            return;
        }

        $buIds = DB::table('business_units')->where('organization_id', 1)->orderBy('id')->pluck('id')->all();
        $controlIds = DB::table('controls')->orderBy('id')->pluck('id')->all();
        $riskIds = DB::table('risks')->orderBy('id')->pluck('id')->all();

        // [title, description, days occurred ago, potential loss kobo, severity, gap?, gap desc, status, investigator, deadline days ahead, reported_by]
        $rows = [
            [
                'Attempted USSD SIM-Swap Fraud Blocked at Authentication',
                'Fraudster attempted account takeover via SIM-swap on a high-value customer. Transaction of N8.5m blocked by device-binding check before completion. Telco confirmation of swap received post-event.',
                3, 850_000_000, 'high', true,
                'SIM-swap alert feed from telco processed with 4-hour delay; real-time integration not yet live.',
                'open', null, 14, 6,
            ],
            [
                'Core Banking EOD Batch Near-Miss During Patch Window',
                'End-of-day batch on core banking narrowly completed before online opening after an unplanned patch overran its change window by 90 minutes. No customer impact, but cutover buffer was fully consumed.',
                6, 0, 'medium', true,
                'Change window sizing does not include contingency for patch rollback testing.',
                'open', null, 21, 2,
            ],
            [
                'Duplicate NIP Settlement File Detected Before Posting',
                'Operations detected a duplicate NIBSS settlement file staged for posting. Duplicate would have double-credited approximately N214m across 1,880 transactions. Caught by four-eyes review, not by system validation.',
                10, 21_400_000_000, 'critical', true,
                'Settlement file ingestion lacks automated duplicate-hash validation; reliance on manual review.',
                'investigating', 2, 10, 6,
            ],
            [
                'Treasury Dealer Limit Breach Averted by Pre-Trade Check',
                'Proposed FX forward would have breached the single-counterparty limit by N120m. Pre-trade compliance check flagged the trade and it was restructured before execution.',
                15, 0, 'medium', false,
                null,
                'investigating', 4, 12, 4,
            ],
        ];

        $insert = [];
        foreach ($rows as $i => [$title, $desc, $occurredAgo, $lossKobo, $severity, $gap, $gapDesc, $status, $investigator, $deadlineAhead, $reportedBy]) {
            $ref = sprintf('NM-2026-%03d', $next + $i);
            $insert[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => 1,
                'reference' => $ref,
                'event_reference' => $ref,
                'title' => $title,
                'description' => $desc,
                'date_occurred' => $now->copy()->subDays($occurredAgo)->toDateString(),
                'date_reported' => $now->copy()->subDays(max($occurredAgo - 1, 0))->toDateString(),
                'business_unit_id' => $buIds[$i % max(count($buIds), 1)] ?? null,
                'potential_loss_kobo' => $lossKobo,
                'severity' => $severity,
                'control_gap_identified' => $gap,
                'control_gap_description' => $gapDesc,
                'linked_control_id' => $gap ? ($controlIds[$i % max(count($controlIds), 1)] ?? null) : null,
                'status' => $status,
                'investigator_id' => $investigator,
                'investigation_deadline' => $status === 'investigating' || $status === 'open'
                    ? $now->copy()->addDays($deadlineAhead)->toDateString()
                    : null,
                'risk_register_id' => $riskIds[($i * 3) % max(count($riskIds), 1)] ?? null,
                'reported_by' => $reportedBy,
                'created_at' => $now->copy()->subDays($occurredAgo),
                'updated_at' => $now,
            ];
        }

        DB::table('near_misses')->insert($insert);
        $this->command?->info('Seeded '.count($insert).' near_misses (open/investigating).');
    }
}
