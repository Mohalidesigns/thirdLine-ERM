<?php

namespace App\Enums\Bcms;

/**
 * The eight live sources a plan section can be bound to (Blueprint §9.2).
 *
 * THIS IS THE MODULE'S DIFFERENTIATOR IN ONE ENUM. A plan whose recovery-time
 * table was typed is accurate on the day it was typed and wrong from the next
 * BIA cycle onwards, and nobody finds out until the exercise. A plan whose
 * recovery-time table is BOUND shows what the BIA says today, carries the date
 * it last agreed with it, and raises its hand when it stops agreeing.
 *
 * Every binding resolves to a plain array — never a model, never a query
 * builder — because that array is hashed into `source_fingerprint`, rendered
 * into the PDF and written into the offline bundle, and those three have to be
 * the same content or the printed plan and the phone disagree during an event.
 */
enum PlanSectionSource: string
{
    case BiaRto = 'bia.rto';
    case BiaDependencies = 'bia.dependencies';
    case Strategy = 'strategy';
    case CallTree = 'call_tree';
    case CrisisTeamContacts = 'contacts.crisis_team';
    case AssemblyPoints = 'sites.assembly';
    case CriticalVendors = 'vendors.critical';
    case DrSystems = 'dr.systems';

    public function label(): string
    {
        return match ($this) {
            self::BiaRto => 'Recovery objectives (from the BIA)',
            self::BiaDependencies => 'Dependencies and single points of failure',
            self::Strategy => 'Approved recovery strategies',
            self::CallTree => 'Call tree',
            self::CrisisTeamContacts => 'Crisis team contacts',
            self::AssemblyPoints => 'Sites, addresses and recovery locations',
            self::CriticalVendors => 'Critical vendors and escalation contacts',
            self::DrSystems => 'IT recovery tiers and runbooks',
        };
    }

    public function renders(): string
    {
        return match ($this) {
            self::BiaRto => 'The current RTO, RPO and MTPD for the processes this plan covers, from the latest approved assessment.',
            self::BiaDependencies => 'What those processes depend on, with single points of failure flagged.',
            self::Strategy => 'The recovery strategy selected and approved for each process, and the gap it leaves.',
            self::CallTree => 'The current approved cascade tree for this plan\'s part of the organisation.',
            self::CrisisTeamContacts => 'The people holding crisis roles, with the channels they can be reached on.',
            self::AssemblyPoints => 'Sites, their addresses, headcount and designated recovery location. '
                .'The muster point itself is authored below the table — the site register does not hold '
                .'one, and printing a guess would send people to the wrong car park.',
            self::CriticalVendors => 'Critical third parties from the vendor register, with their escalation contacts.',
            self::DrSystems => 'Systems by recovery tier, their targets and their runbook references.',
        };
    }

    /**
     * Whether this source is scoped by the processes the plan covers.
     *
     * A binding that is process-scoped and resolves to no processes renders an
     * empty section that SAYS it is empty. It does not fall back to every
     * process in the organisation: a departmental BCP that silently printed the
     * whole bank's recovery objectives would be worse than a blank page,
     * because somebody would act on it.
     */
    public function isProcessScoped(): bool
    {
        return in_array($this, [self::BiaRto, self::BiaDependencies, self::Strategy, self::CriticalVendors], true);
    }

    /**
     * Whether the data behind this source is personal data under the NDPA.
     *
     * It decides whether the offline bundle may carry it to a phone, and
     * whether the PDF prints numbers or role names. Contact blocks are the
     * reason `bcms.contact.export` is a permission of its own.
     */
    public function isPersonalData(): bool
    {
        return in_array($this, [self::CallTree, self::CrisisTeamContacts], true);
    }

    /** @return list<array{value: string, label: string, renders: string, process_scoped: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'renders' => $case->renders(),
            'process_scoped' => $case->isProcessScoped(),
        ], self::cases());
    }
}
