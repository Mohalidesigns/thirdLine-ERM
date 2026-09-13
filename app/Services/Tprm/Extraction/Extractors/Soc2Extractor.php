<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * The full FR-EVD-04 field set for a SOC 2 report.
 *
 * The three child collections — exceptions, CUECs and subservice organisations
 * — are `required` in the schema while their CONTENTS may be empty. That
 * distinction is the whole design. A report with no exceptions returns
 * `"exceptions": []`, which is a finding of fact worth recording. A model that
 * OMITS the key has not read Section 4, and treating the two alike would let a
 * skipped section read as a clean report.
 */
class Soc2Extractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::Soc2;
    }

    public function schema(): array
    {
        return [
            'report_type' => ['type' => 'string', 'in' => ['type_i', 'type_ii'], 'required' => true],
            'period_start' => ['type' => 'date'],
            'period_end' => ['type' => 'date', 'required' => true],
            'service_auditor' => ['type' => 'string'],
            'scope_description' => ['type' => 'string'],
            'tsc_categories' => [
                'type' => 'list',
                'in' => ['security', 'availability', 'confidentiality', 'processing_integrity', 'privacy'],
                'required' => true,
            ],
            'opinion_type' => ['type' => 'string', 'in' => ['unqualified', 'qualified', 'adverse', 'disclaimer']],
            'qualification_basis' => ['type' => 'string'],
            'subservice_method' => ['type' => 'string', 'in' => ['carve_out', 'inclusive', 'none']],
            'exceptions' => ['type' => 'list', 'required' => true],
            'cuecs' => ['type' => 'list', 'required' => true],
            'subservice_orgs' => ['type' => 'list', 'required' => true],
        ];
    }

    public function normalise(array $raw): array
    {
        $exceptions = array_values(array_map(fn (array $row) => [
            'control_reference' => $this->str($row['control_reference'] ?? null),
            'description' => $this->str($row['description'] ?? null) ?? '',
            'population' => $this->str($row['population'] ?? null),
            'exceptions_noted' => $this->str($row['exceptions_noted'] ?? null),
            'management_response' => $this->str($row['management_response'] ?? null),
            // Never populated from the model. A severity is a judgement about
            // OUR exposure, and the report's author has no view on it; the
            // cascade proposes Medium and a reviewer decides.
            'severity_assessment' => null,
        ], $this->rows($raw['exceptions'] ?? [])));

        $cuecs = array_values(array_map(fn (array $row) => [
            'cuec_reference' => $this->str($row['cuec_reference'] ?? null),
            'description' => $this->str($row['description'] ?? null) ?? '',
        ], $this->rows($raw['cuecs'] ?? [])));

        $subservice = array_values(array_map(fn (array $row) => [
            'name' => $this->str($row['name'] ?? null) ?? '',
            'services' => $this->str($row['services'] ?? null),
            'method' => in_array($row['method'] ?? null, ['carve_out', 'inclusive'], true)
                ? $row['method']
                // Unstated defaults to carve-out, the conservative reading: it
                // records an assurance gap for a human to close, where
                // defaulting to inclusive would silently claim coverage the
                // report never gave.
                : 'carve_out',
        ], $this->rows($raw['subservice_orgs'] ?? [])));

        return [
            'report_type' => $raw['report_type'] ?? null,
            'period_start' => $raw['period_start'] ?? null,
            'period_end' => $raw['period_end'] ?? null,
            'service_auditor' => $this->str($raw['service_auditor'] ?? null),
            'scope_description' => $this->str($raw['scope_description'] ?? null),
            'tsc_categories' => array_values(array_filter((array) ($raw['tsc_categories'] ?? []), 'is_string')),
            'opinion_type' => $raw['opinion_type'] ?? null,
            'qualification_basis' => $this->str($raw['qualification_basis'] ?? null),
            'subservice_method' => $raw['subservice_method'] ?? ($subservice === [] ? 'none' : 'carve_out'),
            'exception_count' => count($exceptions),
            'exceptions' => $exceptions,
            'cuecs' => $cuecs,
            'subservice_orgs' => $subservice,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
