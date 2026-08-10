<?php

namespace Database\Seeders;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Business Process seeder — idempotent.
 *
 * Adds (or keeps) a comprehensive catalogue of business processes, with an
 * emphasis on IT processes that were previously thin. Uses `firstOrCreate`
 * keyed on (organization_id, code) so it can be run on an already-populated
 * database without creating duplicates.
 *
 *     php artisan db:seed --class=BusinessProcessSeeder
 */
class BusinessProcessSeeder extends Seeder
{
    public function run(): void
    {
        // Seed every organization present in the system. Keeps the process
        // catalogue consistent across tenants.
        $orgIds = Organization::query()->pluck('id');
        if ($orgIds->isEmpty()) {
            $orgIds = collect([1]);
        }

        foreach ($orgIds as $orgId) {
            $this->seedForOrganization((int) $orgId);
        }
    }

    protected function seedForOrganization(int $orgId): void
    {
        // Map business-unit codes to IDs for this org. Fallback — if a
        // matching BU is missing, we attach the process to the first BU so
        // seeding never fails silently.
        $buByCode = BusinessUnit::where('organization_id', $orgId)
            ->pluck('id', 'code');
        $fallbackBu = $buByCode->first();

        // Default process owner — any user in the org.
        $defaultOwner = User::where('organization_id', $orgId)->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');

        $bu = fn (string $code) => $buByCode[$code] ?? $fallbackBu;

        $processes = [
            // ────────────────────────────────────────────────────────────
            // Banking & Customer-Facing
            // ────────────────────────────────────────────────────────────
            ['BP-LN',  'BU-RT',  'Loan Origination & Disbursement', 'End-to-end retail loan application processing, credit assessment, approval, and disbursement.', 'high'],
            ['BP-PY',  'BU-RT',  'Payment Processing',              'NIBSS, NIP, NEFT, and card payment transaction processing and settlement.', 'critical'],
            ['BP-TF',  'BU-IB',  'Trade Finance Operations',        'Letters of credit, Form M processing, bill for collection, and guarantees.', 'high'],
            ['BP-FX',  'BU-TR',  'FX Trading & Settlement',         'Foreign exchange trading, position management, and settlement including CBN interventions.', 'critical'],
            ['BP-KY',  'BU-RT',  'Customer Onboarding & KYC',       'Know Your Customer verification, account opening, and ongoing due diligence.', 'high'],
            ['BP-CRD', 'BU-RT',  'Card Issuance & Management',      'Debit/credit card issuance, activation, PIN management, and lifecycle operations.', 'high'],
            ['BP-MLN', 'BU-IB',  'Corporate Loan Monitoring',       'Ongoing credit review, covenant monitoring, and portfolio quality management for corporate exposures.', 'high'],
            ['BP-DBT', 'BU-LMDR', 'Debt Recovery & Collections',     'Delinquency management, restructuring, recoveries, and write-off administration.', 'high'],
            ['BP-WM',  'BU-PB',  'Wealth Management & Advisory',    'Portfolio advisory, investment placement, and high-net-worth relationship management.', 'medium'],
            ['BP-CSR', 'BU-CX',  'Customer Service & Complaints',   'Contact centre, branch enquiries, complaints capture, and resolution SLA monitoring.', 'medium'],

            // ────────────────────────────────────────────────────────────
            // IT — Infrastructure & Operations
            // ────────────────────────────────────────────────────────────
            ['BP-IT-DC',   'BU-IT', 'Data Centre Operations',              'Primary and disaster-recovery data centre availability, capacity, and environmental controls.', 'critical'],
            ['BP-IT-NET',  'BU-IT', 'Network & Connectivity Management',   'LAN/WAN, SD-WAN, MPLS, internet egress, firewalls, and inter-branch connectivity.', 'critical'],
            ['BP-IT-SVR',  'BU-IT', 'Server & Virtualisation Management',  'Server provisioning, hypervisor operations, patching, and lifecycle management.', 'high'],
            ['BP-IT-CLD',  'BU-IT', 'Cloud Infrastructure Management',     'Public/private cloud workloads, IaaS/PaaS administration, cost and tenancy governance.', 'high'],
            ['BP-IT-BCK',  'BU-IT', 'Backup & Restoration',                'Backup scheduling, offsite replication, restore testing, and retention compliance.', 'critical'],
            ['BP-IT-DR',   'BU-IT', 'Disaster Recovery & Failover',        'DR site readiness, RTO/RPO testing, failover orchestration, and runbook maintenance.', 'critical'],
            ['BP-IT-BCM',  'BU-IT', 'Business Continuity Management',      'BIA, continuity plans, crisis communications, and BCM exercise scheduling.', 'critical'],
            ['BP-IT-EUC',  'BU-IT', 'End-User Computing & Service Desk',   'Workstation provisioning, ticket resolution, MDM, and user support SLAs.', 'medium'],
            ['BP-IT-DB',   'BU-IT', 'Database Administration',             'DBA operations covering tuning, replication, schema changes, and high-availability.', 'high'],
            ['BP-IT-MON',  'BU-IT', 'IT Monitoring & Observability',       'SIEM/APM/log ingestion, alerting, dashboards, and 24x7 monitoring coverage.', 'high'],

            // ────────────────────────────────────────────────────────────
            // IT — Applications, Change & Delivery
            // ────────────────────────────────────────────────────────────
            ['BP-IT-CBS',  'BU-IT', 'Core Banking System Operations',      'T24/Finacle/Flexcube core banking administration, month-end, and patch releases.', 'critical'],
            ['BP-IT-DEV',  'BU-IT', 'Application Development & Delivery',  'In-house development, CI/CD pipelines, code review, and release governance.', 'high'],
            ['BP-IT-CHG',  'BU-IT', 'Change & Release Management',         'Change Advisory Board, release calendars, post-implementation reviews, and rollback.', 'high'],
            ['BP-IT-PRB',  'BU-IT', 'Incident & Problem Management',       'Major incident response, root-cause analysis, and ITIL problem workflows.', 'high'],
            ['BP-IT-CFG',  'BU-IT', 'Configuration & Asset Management',    'CMDB accuracy, hardware/software inventory, and license compliance.', 'medium'],
            ['BP-IT-API',  'BU-IT', 'API Gateway & Open Banking',          'Partner integrations, API lifecycle, consent management, and Open Banking compliance.', 'high'],
            ['BP-IT-BOT',  'BU-IT', 'RPA & Automation Operations',         'Robotic process automation bots for reconciliations, reporting, and back-office tasks.', 'medium'],
            ['BP-IT-DAT',  'BU-IT', 'Data Warehousing & BI',               'Data pipelines, warehouse refreshes, BI reporting, and data quality checks.', 'high'],
            ['BP-IT-AI',   'BU-IT', 'AI / ML Model Operations',            'Model training, deployment, monitoring for drift, and explainability governance.', 'medium'],
            ['BP-IT-ITAM', 'BU-IT', 'IT Vendor & Contract Management',     'Technology supplier onboarding, SLAs, renewals, and exit planning.', 'medium'],

            // ────────────────────────────────────────────────────────────
            // IT — Cybersecurity
            // ────────────────────────────────────────────────────────────
            ['BP-SEC-IAM', 'BU-IT', 'Identity & Access Management',        'Joiner/Mover/Leaver provisioning, privileged access, SSO, and MFA enforcement.', 'critical'],
            ['BP-SEC-VM',  'BU-IT', 'Vulnerability & Patch Management',    'Vulnerability scanning, patch cadence, and compensating control tracking.', 'high'],
            ['BP-SEC-SOC', 'BU-IT', 'Security Operations Centre (SOC)',    '24x7 monitoring, threat detection, triage, and containment.', 'critical'],
            ['BP-SEC-IR',  'BU-IT', 'Cyber Incident Response',             'Playbook execution, forensics, regulator notification, and post-incident review.', 'critical'],
            ['BP-SEC-PEN', 'BU-IT', 'Penetration Testing & Red Team',      'Scheduled and ad-hoc offensive security testing and remediation tracking.', 'high'],
            ['BP-SEC-DLP', 'BU-IT', 'Data Loss Prevention & Classification', 'DLP policy administration, data classification, and egress monitoring.', 'high'],
            ['BP-SEC-CRY', 'BU-IT', 'Cryptography & Key Management',       'HSM operations, certificate lifecycle, and key rotation policies.', 'high'],
            ['BP-SEC-AWR', 'BU-IT', 'Security Awareness & Phishing Drills', 'Staff training, phishing simulations, and culture metrics.', 'medium'],
            ['BP-SEC-TPR', 'BU-IT', 'Third-Party Cyber Risk Assessment',   'Vendor security due diligence, SOC 2 review, and ongoing monitoring.', 'high'],
            ['BP-SEC-FRD', 'BU-DB', 'Fraud Monitoring & Prevention',       'Real-time fraud scoring on channels, rule tuning, and disputed-transaction workflow.', 'critical'],

            // ────────────────────────────────────────────────────────────
            // Digital Channels
            // ────────────────────────────────────────────────────────────
            ['BP-CH-MOB',  'BU-DB', 'Mobile Banking Channel Operations',   'Mobile app availability, release management, customer onboarding, and transactions.', 'critical'],
            ['BP-CH-WEB',  'BU-DB', 'Internet Banking Channel Operations', 'Internet banking portal uptime, feature releases, and customer authentication.', 'critical'],
            ['BP-CH-USSD', 'BU-DB', 'USSD Channel Operations',             'USSD short-code sessions, aggregator management, and transaction settlement.', 'high'],
            ['BP-CH-ATM',  'BU-OP', 'ATM & POS Channel Operations',        'ATM and POS availability, cash replenishment, dispute handling, and reconciliation.', 'high'],

            // ────────────────────────────────────────────────────────────
            // Finance, Risk & Control
            // ────────────────────────────────────────────────────────────
            ['BP-FIN-REG', 'BU-FC',  'Regulatory Reporting',               'CBN, NDIC, NFIU, FIRS, and IFRS 9 regulatory returns production and submission.', 'critical'],
            ['BP-FIN-CLS', 'BU-FC',  'Financial Close & Reconciliation',   'Month-end close, GL reconciliations, nostro/vostro, and suspense clearance.', 'high'],
            ['BP-TRE-LIQ', 'BU-TR',  'Liquidity & ALM',                    'Asset-liability management, liquidity coverage ratio, and funding gap monitoring.', 'critical'],
            ['BP-ERM-ASS', 'BU-ERM', 'Enterprise Risk Assessment (RCSA)',  'Risk and control self-assessment cycles, heat-mapping, and action tracking.', 'high'],
            ['BP-ERM-LE',  'BU-ERM', 'Loss Event Capture & Analysis',      'Operational loss event intake, root cause, Basel categorisation, and board reporting.', 'high'],
            ['BP-CIC-AML', 'BU-CIC', 'AML / CFT Transaction Monitoring',   'Name screening, transaction monitoring, SAR/STR filings, and sanctions compliance.', 'critical'],
            ['BP-CIC-POL', 'BU-CIC', 'Policy & Procedure Management',      'Policy authoring, review cycles, approvals, and attestation tracking.', 'medium'],
            ['BP-IA-AUD',  'BU-IA',  'Internal Audit Execution',           'Audit planning, fieldwork, issue management, and closure validation.', 'high'],
            ['BP-HR-LC',   'BU-HR',  'Human Capital Lifecycle',            'Recruitment, onboarding, performance, training, and offboarding.', 'medium'],
            ['BP-LG-CTR',  'BU-LG',  'Contract & Legal Review',            'Contract review, litigation management, and legal opinion issuance.', 'medium'],
            ['BP-PRC-PRO', 'BU-SBP', 'Procurement & Sourcing',             'Vendor sourcing, RFP management, contract negotiation, and supplier governance.', 'medium'],
        ];

        foreach ($processes as [$code, $buCode, $name, $desc, $criticality]) {
            BusinessProcess::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'code' => $code,
                ],
                [
                    'business_unit_id' => $bu($buCode),
                    'name' => $name,
                    'description' => $desc,
                    'owner_id' => $defaultOwner,
                    'criticality' => $criticality,
                    'is_active' => true,
                ]
            );
        }
    }
}
