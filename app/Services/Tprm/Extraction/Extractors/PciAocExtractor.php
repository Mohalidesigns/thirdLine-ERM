<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * A PCI DSS Attestation of Compliance.
 *
 * `services_not_assessed` is here because it is the field everyone skips. An
 * AOC listing five services and excluding a sixth is routinely filed as "they
 * are PCI compliant", and the excluded service is often the one we consume.
 *
 * `assessment_type` matters for the same reason a Type I differs from a Type
 * II: an SAQ is the vendor's own assessment of itself. The assurance level a
 * self-assessment supports is not the one a QSA-signed ROC supports, and
 * flattening the two is how a self-attestation scores as independent
 * assurance.
 */
class PciAocExtractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::PciAoc;
    }

    public function schema(): array
    {
        return [
            'pci_version' => ['type' => 'string'],
            'assessment_type' => ['type' => 'string', 'in' => ['roc', 'saq'], 'required' => true],
            'saq_type' => ['type' => 'string'],
            'qsa_company' => ['type' => 'string'],
            'assessed_entity' => ['type' => 'string', 'required' => true],
            'services_assessed' => ['type' => 'list', 'required' => true],
            'services_not_assessed' => ['type' => 'list', 'required' => true],
            'assessment_date' => ['type' => 'date'],
            'expiry_date' => ['type' => 'date'],
            'compliance_status' => [
                'type' => 'string',
                'in' => ['compliant', 'non_compliant', 'compliant_with_legal_exception'],
                'required' => true,
            ],
            'requirements_not_applicable' => ['type' => 'list'],
        ];
    }

    public function normalise(array $raw): array
    {
        $type = in_array($raw['assessment_type'] ?? null, ['roc', 'saq'], true) ? $raw['assessment_type'] : null;

        return [
            'pci_version' => $this->str($raw['pci_version'] ?? null),
            'assessment_type' => $type,
            'saq_type' => $type === 'saq' ? $this->str($raw['saq_type'] ?? null) : null,
            // A QSA firm on a self-assessment is a contradiction the document
            // cannot have stated, so it is dropped rather than recorded.
            'qsa_company' => $type === 'roc' ? $this->str($raw['qsa_company'] ?? null) : null,
            'assessed_entity' => $this->str($raw['assessed_entity'] ?? null),
            'services_assessed' => $this->strings($raw['services_assessed'] ?? []),
            'services_not_assessed' => $this->strings($raw['services_not_assessed'] ?? []),
            'assessment_date' => $raw['assessment_date'] ?? null,
            'expiry_date' => $raw['expiry_date'] ?? null,
            'compliance_status' => $raw['compliance_status'] ?? null,
            'requirements_not_applicable' => $this->strings($raw['requirements_not_applicable'] ?? []),
        ];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
