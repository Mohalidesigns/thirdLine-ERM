<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * What kind of arrangement the engagement is.
 *
 * The type drives which regulatory set applies, which is why it is an enum and
 * not a free-text service category. `Agency` in particular carries a specific
 * Nigerian consequence: the institution is liable for the acts of its agents
 * (REG-NG-05; BOFIA agency rules), so an agency engagement pulls the KO-REGACT
 * knockout floor and the complaint-attribution requirement in FR-INC-06.
 *
 * `IntraGroup` is a type rather than a flag on the entity because a group
 * company can be both: shared services from the parent are intra-group, while
 * a commercial contract with the same parent is not, and DORA Art. 28 treats
 * them alike for register purposes but the approval chain does not.
 */
enum EngagementType: string
{
    use EnumHelpers;

    case IctService = 'ict_service';
    case Outsourcing = 'outsourcing';
    case Agency = 'agency';
    case ProfessionalService = 'professional_service';
    case Supply = 'supply';
    case IntraGroup = 'intra_group';

    public function label(): string
    {
        return match ($this) {
            self::IctService => 'ICT service',
            self::Outsourcing => 'Outsourcing',
            self::Agency => 'Agency',
            self::ProfessionalService => 'Professional service',
            self::Supply => 'Supply',
            self::IntraGroup => 'Intra-group',
        };
    }

    /**
     * Whether this engagement belongs in the CBN Appendix II §1.4 ICT register
     * and the DORA register of information.
     */
    public function isIctArrangement(): bool
    {
        return in_array($this, [self::IctService, self::Outsourcing, self::IntraGroup], true);
    }

    /** Whether the KO-REGACT knockout applies by type alone. */
    public function isRegulatedActivity(): bool
    {
        return $this === self::Agency;
    }
}
