<?php

namespace App\Enums;

/**
 * Canonical Basel II/III Level 1 Operational Risk Event Types (BCBS Annex 9 / OPE25).
 *
 * Values match LossEvent::BASEL_EVENT_TYPES so Risk Library definitions align
 * directly with CBN ORMS loss-event reporting without a separate lookup table.
 */
enum BaselEventTypeL1: string
{
    case InternalFraud = 'internal_fraud';
    case ExternalFraud = 'external_fraud';
    case EmploymentPractices = 'employment_practices';
    case ClientsProducts = 'clients_products';
    case DamagePhysicalAssets = 'damage_physical_assets';
    case BusinessDisruption = 'business_disruption';
    case ExecutionDelivery = 'execution_delivery';

    public function label(): string
    {
        return match ($this) {
            self::InternalFraud => 'Internal Fraud',
            self::ExternalFraud => 'External Fraud',
            self::EmploymentPractices => 'Employment Practices & Workplace Safety',
            self::ClientsProducts => 'Clients, Products & Business Practices',
            self::DamagePhysicalAssets => 'Damage to Physical Assets',
            self::BusinessDisruption => 'Business Disruption & System Failures',
            self::ExecutionDelivery => 'Execution, Delivery & Process Management',
        };
    }

    public function code(): string
    {
        return match ($this) {
            self::InternalFraud => 'ET01',
            self::ExternalFraud => 'ET02',
            self::EmploymentPractices => 'ET03',
            self::ClientsProducts => 'ET04',
            self::DamagePhysicalAssets => 'ET05',
            self::BusinessDisruption => 'ET06',
            self::ExecutionDelivery => 'ET07',
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
     * @return list<array{value: string, label: string, code: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'code' => $case->code(),
        ], self::cases());
    }
}
