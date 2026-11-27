<?php

namespace App\Enums;

/**
 * Canonical Basel II/III Level 2 Operational Risk Event Types (BCBS Annex 9 / OPE25).
 *
 * All 20 canonical Level 2 categories mapped to their parent Level 1 event type.
 */
enum BaselEventTypeL2: string
{
    // ET01 — Internal Fraud (2)
    case UnauthorizedActivity = 'unauthorized_activity';
    case TheftAndFraudInternal = 'theft_and_fraud_internal';

    // ET02 — External Fraud (2)
    case TheftAndFraudExternal = 'theft_and_fraud_external';
    case SystemsSecurity = 'systems_security';

    // ET03 — Employment Practices & Workplace Safety (3)
    case EmployeeRelations = 'employee_relations';
    case SafeEnvironment = 'safe_environment';
    case DiversityDiscrimination = 'diversity_discrimination';

    // ET04 — Clients, Products & Business Practices (5)
    case SuitabilityDisclosureFiduciary = 'suitability_disclosure_fiduciary';
    case ImproperBusinessMarketPractices = 'improper_business_market_practices';
    case ProductFlaws = 'product_flaws';
    case SelectionSponsorshipExposure = 'selection_sponsorship_exposure';
    case AdvisoryActivities = 'advisory_activities';

    // ET05 — Damage to Physical Assets (1)
    case DisastersOtherEvents = 'disasters_other_events';

    // ET06 — Business Disruption & System Failures (1)
    case SystemsFailure = 'systems_failure';

    // ET07 — Execution, Delivery & Process Management (6)
    case TransactionCapture = 'transaction_capture';
    case MonitoringReporting = 'monitoring_reporting';
    case CustomerIntakeDocumentation = 'customer_intake_documentation';
    case CustomerAccountManagement = 'customer_account_management';
    case TradeCounterparties = 'trade_counterparties';
    case VendorsSuppliers = 'vendors_suppliers';

    public function l1(): BaselEventTypeL1
    {
        return match ($this) {
            self::UnauthorizedActivity,
            self::TheftAndFraudInternal => BaselEventTypeL1::InternalFraud,

            self::TheftAndFraudExternal,
            self::SystemsSecurity => BaselEventTypeL1::ExternalFraud,

            self::EmployeeRelations,
            self::SafeEnvironment,
            self::DiversityDiscrimination => BaselEventTypeL1::EmploymentPractices,

            self::SuitabilityDisclosureFiduciary,
            self::ImproperBusinessMarketPractices,
            self::ProductFlaws,
            self::SelectionSponsorshipExposure,
            self::AdvisoryActivities => BaselEventTypeL1::ClientsProducts,

            self::DisastersOtherEvents => BaselEventTypeL1::DamagePhysicalAssets,

            self::SystemsFailure => BaselEventTypeL1::BusinessDisruption,

            self::TransactionCapture,
            self::MonitoringReporting,
            self::CustomerIntakeDocumentation,
            self::CustomerAccountManagement,
            self::TradeCounterparties,
            self::VendorsSuppliers => BaselEventTypeL1::ExecutionDelivery,
        };
    }

    public function parentL1(): BaselEventTypeL1
    {
        return $this->l1();
    }

    public function label(): string
    {
        return match ($this) {
            self::UnauthorizedActivity => 'Unauthorized Activity',
            self::TheftAndFraudInternal => 'Theft and Fraud (Internal)',
            self::TheftAndFraudExternal => 'Theft and Fraud (External)',
            self::SystemsSecurity => 'Systems Security',
            self::EmployeeRelations => 'Employee Relations',
            self::SafeEnvironment => 'Safe Environment',
            self::DiversityDiscrimination => 'Diversity & Discrimination',
            self::SuitabilityDisclosureFiduciary => 'Suitability, Disclosure & Fiduciary',
            self::ImproperBusinessMarketPractices => 'Improper Business or Market Practices',
            self::ProductFlaws => 'Product Flaws',
            self::SelectionSponsorshipExposure => 'Selection, Sponsorship & Exposure',
            self::AdvisoryActivities => 'Advisory Activities',
            self::DisastersOtherEvents => 'Disasters and Other Events',
            self::SystemsFailure => 'Systems',
            self::TransactionCapture => 'Transaction Capture, Execution & Maintenance',
            self::MonitoringReporting => 'Monitoring and Reporting',
            self::CustomerIntakeDocumentation => 'Customer Intake and Documentation',
            self::CustomerAccountManagement => 'Customer / Client Account Management',
            self::TradeCounterparties => 'Trade Counterparties',
            self::VendorsSuppliers => 'Vendors & Suppliers',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public static function forL1(BaselEventTypeL1 $l1): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case) => $case->l1() === $l1
        ));
    }

    /**
     * @return list<array{value: string, label: string, l1: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'l1' => $case->l1()->value,
        ], self::cases());
    }
}
