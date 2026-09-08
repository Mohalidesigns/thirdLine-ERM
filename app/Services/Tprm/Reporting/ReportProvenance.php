<?php

namespace App\Services\Tprm\Reporting;

use Carbon\CarbonImmutable;

/**
 * What a supervisory return has to say about itself — AC-13 and FR-RPT-01.
 *
 * A register export is not a spreadsheet, it is a statement made to a
 * regulator, and the acceptance criterion is explicit about the four things it
 * must carry: an as-at timestamp, a preparer, a reviewer, and the filter
 * provenance. This object is those four, assembled once and rendered into
 * every output — the PDF cover, the spreadsheet's stamp block and the screen's
 * header — so that the three can never disagree about which view produced
 * which numbers.
 *
 * THE UNREVIEWED CASE IS STATED, NOT OMITTED. `reviewedBy` is nullable and the
 * renderers print "Not reviewed" rather than dropping the row: an absent line
 * on a cover page reads as though review happened and nobody bothered to say
 * who did it, which is the more comfortable and less true of the two readings.
 *
 * `asAt` IS A POSITION, NOT A CLOCK READING. It is separate from
 * `generatedAt`, which the layout stamps itself: a pack produced in April for
 * the March position must say March on its cover and April in its footer, and
 * collapsing the two is how last quarter's return gets reprinted as this
 * quarter's.
 */
final class ReportProvenance
{
    /**
     * @param  array<string, string>  $filters  humanised label => value pairs
     *                                          describing the view the rows were taken from. Never empty: a
     *                                          builder with no filters applied records "All records", because a
     *                                          blank block is indistinguishable from a block nobody filled in.
     * @param  array<string, string>  $versions  the engine and ruleset versions
     *                                           the scores in this export were produced under (TRD §7.9).
     */
    public function __construct(
        public readonly string $title,
        public readonly CarbonImmutable $asAt,
        public readonly ?string $preparedBy = null,
        public readonly ?string $reviewedBy = null,
        public readonly array $filters = [],
        public readonly int $rowCount = 0,
        public readonly array $versions = [],
        public readonly ?string $authority = null,
    ) {}

    public function withRowCount(int $rowCount): self
    {
        return new self(
            $this->title,
            $this->asAt,
            $this->preparedBy,
            $this->reviewedBy,
            $this->filters,
            $rowCount,
            $this->versions,
            $this->authority,
        );
    }

    /**
     * The filter block, guaranteed non-empty.
     *
     * @return array<string, string>
     */
    public function filterProvenance(): array
    {
        return $this->filters === [] ? ['Scope' => 'All records'] : $this->filters;
    }

    /**
     * The stamp written above a spreadsheet's table, and read back by the
     * reconciliation test.
     *
     * @return array<string, string>
     */
    public function toMeta(): array
    {
        $meta = array_filter([
            'Report' => $this->title,
            'Authority' => $this->authority,
            'Position as at' => $this->asAt->format('d F Y H:i'),
            'Generated' => CarbonImmutable::now()->format('d F Y H:i'),
            'Prepared by' => $this->preparedBy,
            'Reviewed by' => $this->reviewedBy ?? 'Not reviewed',
            'Rows' => (string) $this->rowCount,
        ], fn (?string $value) => $value !== null && $value !== '');

        foreach ($this->versions as $label => $value) {
            $meta[$label] = $value;
        }

        foreach ($this->filterProvenance() as $label => $value) {
            // Prefixed so a filter named "Rows" cannot overwrite the row count.
            $meta['Filter — '.$label] = $value;
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'authority' => $this->authority,
            'as_at' => $this->asAt->toIso8601String(),
            'as_at_label' => $this->asAt->format('d F Y \a\t H:i'),
            'prepared_by' => $this->preparedBy,
            'reviewed_by' => $this->reviewedBy,
            'filters' => $this->filterProvenance(),
            'row_count' => $this->rowCount,
            'versions' => $this->versions,
        ];
    }
}
