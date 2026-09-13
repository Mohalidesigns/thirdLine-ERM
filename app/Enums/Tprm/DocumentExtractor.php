<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * Which extractor handles a document type — TRD §12.2, the routing key for
 * `ExtractionDispatcher`.
 *
 * `Generic` is the fallback and does nothing clever: it records the document
 * with its metadata and no structured extraction. It is not a failure state.
 * With AI disabled entirely (AC-16) every document type behaves as `Generic`
 * and every field an extractor would have populated stays enterable by hand.
 */
enum DocumentExtractor: string
{
    use EnumHelpers;

    case Soc2 = 'soc2';
    case IsoCert = 'iso_cert';
    case PciAoc = 'pci_aoc';
    case Pentest = 'pentest';
    case Insurance = 'insurance';
    case Financials = 'financials';
    case Dpa = 'dpa';
    case BcpTest = 'bcp_test';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Soc2 => 'SOC 1 / SOC 2 report',
            self::IsoCert => 'ISO certificate',
            self::PciAoc => 'PCI DSS Attestation of Compliance',
            self::Pentest => 'Penetration test report',
            self::Insurance => 'Insurance policy or certificate',
            self::Financials => 'Financial statements',
            self::Dpa => 'Data processing agreement',
            self::BcpTest => 'BCP / DR test report',
            self::Generic => 'No structured extraction',
        };
    }

    /**
     * The assurance level a confirmed extraction from this document type can
     * support, before the modifiers in TRD §7.4 are applied.
     *
     * A pen test and a BCP test are evidence that something was INSPECTED by a
     * third party, which is `independently_assured`. A DPA is a contract — it
     * proves an undertaking, not an operating control — so it tops out at
     * `documented`, and an insurance certificate likewise.
     */
    public function supportsAssuranceLevel(): AssuranceLevel
    {
        return match ($this) {
            self::Soc2, self::PciAoc, self::IsoCert, self::Pentest, self::BcpTest => AssuranceLevel::IndependentlyAssured,
            self::Dpa, self::Insurance, self::Financials => AssuranceLevel::Documented,
            self::Generic => AssuranceLevel::SelfAttested,
        };
    }

    /** Whether this extractor needs an AI service to do anything at all. */
    public function requiresAi(): bool
    {
        return $this !== self::Generic;
    }
}
