<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * An ISO management-system certificate.
 *
 * `scope_text` is the field this extractor exists for. FR-DDL-07's
 * scope-mismatch check compares it against the engagement's service
 * description, and a mismatch applies the ×0.7 modifier in TRD §7.4 — so a
 * paraphrased scope is not merely lower quality, it is an input to a score.
 * The prompt asks for it verbatim and this class does not touch it beyond
 * trimming.
 */
class IsoCertExtractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::IsoCert;
    }

    public function schema(): array
    {
        return [
            'standard' => ['type' => 'string', 'required' => true],
            'certificate_number' => ['type' => 'string'],
            'certified_entity' => ['type' => 'string', 'required' => true],
            'certification_body' => ['type' => 'string'],
            'accreditation_body' => ['type' => 'string'],
            'issue_date' => ['type' => 'date'],
            'valid_from' => ['type' => 'date'],
            'valid_to' => ['type' => 'date', 'required' => true],
            'scope_text' => ['type' => 'string', 'required' => true],
            'sites' => ['type' => 'list'],
        ];
    }

    public function normalise(array $raw): array
    {
        return [
            'standard' => $this->str($raw['standard'] ?? null),
            'certificate_number' => $this->str($raw['certificate_number'] ?? null),
            'certified_entity' => $this->str($raw['certified_entity'] ?? null),
            'certification_body' => $this->str($raw['certification_body'] ?? null),
            // An unaccredited certificate is a real thing and worth seeing: it
            // is a certificate no accreditation body stands behind, which is a
            // materially weaker claim than the same words with a UKAS mark.
            'accreditation_body' => $this->str($raw['accreditation_body'] ?? null),
            'issue_date' => $raw['issue_date'] ?? null,
            'valid_from' => $raw['valid_from'] ?? null,
            'valid_to' => $raw['valid_to'] ?? null,
            'scope_text' => $this->str($raw['scope_text'] ?? null),
            'sites' => array_values(array_filter((array) ($raw['sites'] ?? []), 'is_string')),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
