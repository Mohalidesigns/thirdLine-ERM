<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * Audited or management financial statements.
 *
 * NOTHING IS DERIVED. Not a current ratio, not net assets from the two sides
 * of a balance sheet, not a margin. Every figure is either read off the
 * statements or null. The ratios this feeds are used in a supplier VIABILITY
 * judgement — whether this vendor will still be delivering the service in
 * eighteen months — and a computed figure that disagrees with the statement it
 * came from is worse than an absent one, because it looks like a fact.
 *
 * `units` is captured rather than normalised for the same reason: statements
 * presented in thousands and read as units understate a vendor's revenue by a
 * factor of a thousand, and the resulting concentration analysis would be
 * confidently wrong.
 */
class FinancialsExtractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::Financials;
    }

    /** @var list<string> */
    private const FIGURES = [
        'revenue', 'profit_before_tax', 'net_assets', 'total_assets',
        'current_assets', 'current_liabilities', 'cash', 'total_debt',
    ];

    public function schema(): array
    {
        $schema = [
            'entity_name' => ['type' => 'string', 'required' => true],
            'period_end' => ['type' => 'date', 'required' => true],
            'currency' => ['type' => 'string', 'required' => true],
            'units' => ['type' => 'string', 'in' => ['units', 'thousands', 'millions'], 'required' => true],
            'audited' => ['type' => 'bool', 'required' => true],
            'auditor' => ['type' => 'string'],
            'opinion' => ['type' => 'string'],
            'going_concern_emphasis' => ['type' => 'bool'],
            'prior_period' => ['type' => 'object'],
        ];

        foreach (self::FIGURES as $figure) {
            $schema[$figure] = ['type' => 'number'];
        }

        return $schema;
    }

    public function normalise(array $raw): array
    {
        $normalised = [
            'entity_name' => $this->str($raw['entity_name'] ?? null),
            'period_end' => $raw['period_end'] ?? null,
            'currency' => is_string($raw['currency'] ?? null) && strlen(trim($raw['currency'])) === 3
                ? strtoupper(trim($raw['currency']))
                : null,
            'units' => in_array($raw['units'] ?? null, ['units', 'thousands', 'millions'], true)
                ? $raw['units']
                : null,
            'audited' => is_bool($raw['audited'] ?? null) ? $raw['audited'] : null,
            'auditor' => $this->str($raw['auditor'] ?? null),
            'opinion' => $this->str($raw['opinion'] ?? null),
            // The single most predictive line in a supplier's accounts, and
            // the one a reviewer skimming a PDF misses.
            'going_concern_emphasis' => is_bool($raw['going_concern_emphasis'] ?? null)
                ? $raw['going_concern_emphasis']
                : null,
        ];

        foreach (self::FIGURES as $figure) {
            $normalised[$figure] = is_numeric($raw[$figure] ?? null) ? (float) $raw[$figure] : null;
        }

        $prior = is_array($raw['prior_period'] ?? null) ? $raw['prior_period'] : [];
        $normalised['prior_period'] = [];

        foreach (self::FIGURES as $figure) {
            $normalised['prior_period'][$figure] = is_numeric($prior[$figure] ?? null) ? (float) $prior[$figure] : null;
        }

        return $normalised;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
