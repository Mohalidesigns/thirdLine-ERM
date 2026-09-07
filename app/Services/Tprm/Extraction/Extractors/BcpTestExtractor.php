<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * A business continuity or disaster recovery test report.
 *
 * TARGETS AND ACHIEVED FIGURES ARE SEPARATE FIELDS, and that is the point of
 * reading these at all. A vendor's RTO commitment is in the contract; whether
 * it has ever been met is in this document, and the two are routinely
 * different. FR-EXT and the resilience questions both ask "demonstrated in a
 * test rather than only stated", which is a question only these four numbers
 * can answer.
 *
 * The test type is recorded as stated and never upgraded. A tabletop exercise
 * where people discussed a failover is not a failover.
 */
class BcpTestExtractor implements Extractor
{
    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::BcpTest;
    }

    public function schema(): array
    {
        return [
            'test_date' => ['type' => 'date', 'required' => true],
            'test_type' => [
                'type' => 'string',
                'in' => ['tabletop', 'walkthrough', 'simulation', 'parallel', 'full_interruption', 'other'],
                'required' => true,
            ],
            'scope' => ['type' => 'string'],
            'rto_target_hours' => ['type' => 'number'],
            'rto_achieved_hours' => ['type' => 'number'],
            'rpo_target_minutes' => ['type' => 'number'],
            'rpo_achieved_minutes' => ['type' => 'number'],
            'objectives_met' => ['type' => 'bool'],
            'issues_identified' => ['type' => 'list', 'required' => true],
            'next_test_due' => ['type' => 'date'],
        ];
    }

    public function normalise(array $raw): array
    {
        return [
            'test_date' => $raw['test_date'] ?? null,
            'test_type' => $raw['test_type'] ?? null,
            'scope' => $this->str($raw['scope'] ?? null),
            'rto_target_hours' => $this->num($raw['rto_target_hours'] ?? null),
            'rto_achieved_hours' => $this->num($raw['rto_achieved_hours'] ?? null),
            'rpo_target_minutes' => $this->num($raw['rpo_target_minutes'] ?? null),
            'rpo_achieved_minutes' => $this->num($raw['rpo_achieved_minutes'] ?? null),
            // Null where the report reaches no verdict. A test report that
            // does not say whether it passed is a common and telling artefact,
            // and recording it as a failure would be our judgement wearing the
            // report's clothes.
            'objectives_met' => is_bool($raw['objectives_met'] ?? null) ? $raw['objectives_met'] : null,
            'issues_identified' => array_values(array_map(fn (array $row) => [
                'description' => $this->str($row['description'] ?? null) ?? '',
                'owner' => $this->str($row['owner'] ?? null),
                'action' => $this->str($row['action'] ?? null),
            ], array_filter((array) ($raw['issues_identified'] ?? []), 'is_array'))),
            'next_test_due' => $raw['next_test_due'] ?? null,
        ];
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
