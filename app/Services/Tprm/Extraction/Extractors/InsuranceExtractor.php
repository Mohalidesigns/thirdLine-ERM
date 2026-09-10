<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * An insurance certificate or schedule.
 *
 * AMOUNTS ARE NEVER CONVERTED AND CURRENCIES ARE NEVER ASSUMED. A limit of
 * ₦500,000,000 and one of $500,000,000 differ by three orders of magnitude,
 * and a module that normalised silently to a base currency at an unstated rate
 * would produce a cover adequacy judgement nobody could reproduce. The
 * currency is carried as stated and the conversion, if one is ever wanted, is
 * a decision made visibly somewhere else.
 *
 * `limit_basis` is null when unstated rather than defaulted to aggregate,
 * because the difference between per-claim and aggregate is the difference
 * between one loss covered and a year's losses covered.
 */
class InsuranceExtractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::Insurance;
    }

    public function schema(): array
    {
        return [
            'insurer' => ['type' => 'string', 'required' => true],
            'broker' => ['type' => 'string'],
            'policy_number' => ['type' => 'string'],
            'insured_entity' => ['type' => 'string', 'required' => true],
            'cover_types' => ['type' => 'list', 'required' => true],
            'period_from' => ['type' => 'date'],
            'period_to' => ['type' => 'date', 'required' => true],
            'territorial_limits' => ['type' => 'string'],
            'key_exclusions' => ['type' => 'list'],
        ];
    }

    public function normalise(array $raw): array
    {
        return [
            'insurer' => $this->str($raw['insurer'] ?? null),
            'broker' => $this->str($raw['broker'] ?? null),
            'policy_number' => $this->str($raw['policy_number'] ?? null),
            // Compared against the contracting entity by the reviewer: a
            // policy naming a parent company is not cover for the subsidiary
            // we contracted with.
            'insured_entity' => $this->str($raw['insured_entity'] ?? null),
            'cover_types' => array_values(array_map(fn (array $row) => [
                'type' => in_array($row['type'] ?? null, self::COVER_TYPES, true) ? $row['type'] : 'other',
                'limit_amount' => is_numeric($row['limit_amount'] ?? null) ? (float) $row['limit_amount'] : null,
                'limit_currency' => $this->currency($row['limit_currency'] ?? null),
                'limit_basis' => in_array($row['limit_basis'] ?? null, ['per_claim', 'aggregate'], true)
                    ? $row['limit_basis']
                    : null,
                'excess' => is_numeric($row['excess'] ?? null) ? (float) $row['excess'] : null,
            ], array_filter((array) ($raw['cover_types'] ?? []), 'is_array'))),
            'period_from' => $raw['period_from'] ?? null,
            'period_to' => $raw['period_to'] ?? null,
            'territorial_limits' => $this->str($raw['territorial_limits'] ?? null),
            'key_exclusions' => array_values(array_filter((array) ($raw['key_exclusions'] ?? []), 'is_string')),
        ];
    }

    /** @var list<string> */
    private const COVER_TYPES = [
        'cyber', 'professional_indemnity', 'public_liability', 'employers_liability', 'crime', 'other',
    ];

    /** A three-letter ISO code or nothing. An invented currency is worse than none. */
    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) === 1
            ? strtoupper(trim($value))
            : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
