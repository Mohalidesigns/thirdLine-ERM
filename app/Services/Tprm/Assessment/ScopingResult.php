<?php

namespace App\Services\Tprm\Assessment;

/**
 * Which questions were asked, and — for every question in the template — why.
 *
 * The trace is persisted onto `tp_assessments.scoping_trace` and is the
 * supervisory defence for a short questionnaire. It covers the EXCLUDED
 * questions as well as the included ones, because the excluded ones are the
 * only half anybody will ever query.
 */
class ScopingResult
{
    /**
     * @param  list<string>  $includedCodes
     * @param  array<string, array{included: bool, decided_by: string, section: string, reason: string, rule: array<string, mixed>|null}>  $trace
     * @param  list<string>  $unresolvedFacts
     */
    public function __construct(
        public readonly array $includedCodes,
        public readonly array $trace,
        public readonly array $unresolvedFacts = [],
    ) {}

    public function includes(string $questionCode): bool
    {
        return in_array($questionCode, $this->includedCodes, true);
    }

    public function includedCount(): int
    {
        return count($this->includedCodes);
    }

    public function totalCount(): int
    {
        return count($this->trace);
    }

    public function excludedCount(): int
    {
        return $this->totalCount() - $this->includedCount();
    }

    /**
     * The excluded questions grouped by the reason they were excluded, for the
     * "why is this assessment only forty questions" panel.
     *
     * @return array<string, list<string>>
     */
    public function exclusionsByReason(): array
    {
        $grouped = [];

        foreach ($this->trace as $code => $entry) {
            if ($entry['included']) {
                continue;
            }

            $grouped[$entry['reason']][] = $code;
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'included' => $this->includedCodes,
            'included_count' => $this->includedCount(),
            'total_count' => $this->totalCount(),
            'excluded_count' => $this->excludedCount(),
            'unresolved_facts' => $this->unresolvedFacts,
            'trace' => $this->trace,
        ];
    }
}
