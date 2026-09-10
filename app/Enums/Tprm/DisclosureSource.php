<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * How we came to know about a sub-processor — TRD §8.8, `tp_nth_party_edges`.
 *
 * This is the column that makes FR-NTH's best feature possible: an entity that
 * appears in `Soc2Carveout` or `Discovered` but never in `VendorDeclared` is an
 * UNDECLARED sub-processor, and that gap is a finding in its own right rather
 * than a data-quality note. A vendor whose SOC 2 carves out a hosting provider
 * it never told us about has broken its disclosure obligation, and the module
 * can prove it from two rows.
 */
enum DisclosureSource: string
{
    use EnumHelpers;

    case VendorDeclared = 'vendor_declared';
    case ContractAnnex = 'contract_annex';
    case DpaAnnex = 'dpa_annex';
    case Soc2Carveout = 'soc2_carveout';
    case PublicPage = 'public_page';
    case Discovered = 'discovered';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::VendorDeclared => 'Vendor declared',
            self::ContractAnnex => 'Contract annex',
            self::DpaAnnex => 'DPA annex',
            self::Soc2Carveout => 'SOC 2 carve-out',
            self::PublicPage => 'Public sub-processor page',
            self::Discovered => 'Discovered',
            self::Manual => 'Manually recorded',
        };
    }

    /**
     * Whether the vendor itself is the source. An edge known only from
     * sources where this is false is undeclared.
     */
    public function isVendorDisclosure(): bool
    {
        return in_array($this, [self::VendorDeclared, self::ContractAnnex, self::DpaAnnex, self::PublicPage], true);
    }
}
