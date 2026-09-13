<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A SOC 2 report, decomposed — TRD §8.5 and FR-EVD-05.
 *
 * "A SOC 2 Type II is the densest evidence artefact a vendor produces and
 * every competitor treats it as a PDF attachment." This row and its three
 * children are the alternative: the report as structured facts that other
 * parts of the module can reason about.
 *
 * The columns are the questions a reviewer would otherwise have to answer by
 * reading ninety pages: which trust services criteria, over what period, by
 * which auditor, with what opinion, how many exceptions, and whether the
 * subservice organisations were carved out.
 */
class Soc2Detail extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_soc2_details';

    public const TYPE_I = 'type_i';

    public const TYPE_II = 'type_ii';

    /** @var list<string> */
    public const OPINIONS = ['unqualified', 'qualified', 'adverse', 'disclaimer'];

    protected $fillable = [
        'organization_id', 'document_id', 'report_type', 'period_start', 'period_end',
        'service_auditor', 'scope_description', 'tsc_categories', 'opinion_type',
        'qualification_basis', 'exception_count', 'subservice_method',
        'bridge_letter_document_id', 'bridge_covers_to',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'bridge_covers_to' => 'date',
        'tsc_categories' => 'array',
        'exception_count' => 'integer',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function bridgeLetter(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'bridge_letter_document_id');
    }

    /** @return HasMany<Soc2Exception, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(Soc2Exception::class, 'soc2_id');
    }

    /** @return HasMany<Soc2Cuec, $this> */
    public function cuecs(): HasMany
    {
        return $this->hasMany(Soc2Cuec::class, 'soc2_id');
    }

    /** @return HasMany<Soc2SubserviceOrg, $this> */
    public function subserviceOrgs(): HasMany
    {
        return $this->hasMany(Soc2SubserviceOrg::class, 'soc2_id');
    }

    /**
     * Trust services category => the criteria series a report covering it
     * opines on.
     *
     * A SOC 2 declares its scope as CATEGORIES ("Security, Availability and
     * Confidentiality"), while a question is mapped to a criteria series
     * ("CC6", "A1.3"). Without this map the two vocabularies never meet, and a
     * cascade comparing them directly would match nothing while looking as
     * though it worked.
     *
     * Security is always in scope — every SOC 2 includes the common criteria,
     * which is why they are common — so a report whose categories were never
     * captured is treated as Security-only rather than as covering nothing.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORY_SERIES = [
        'security' => ['CC1', 'CC2', 'CC3', 'CC4', 'CC5', 'CC6', 'CC7', 'CC8', 'CC9'],
        'availability' => ['A1.1', 'A1.2', 'A1.3'],
        'confidentiality' => ['C1.1', 'C1.2'],
        'processing_integrity' => ['PI1.1', 'PI1.2', 'PI1.3', 'PI1.4', 'PI1.5'],
        'privacy' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8'],
    ];

    /**
     * Every criteria series this report opines on.
     *
     * @return list<string>
     */
    public function coveredCriteria(): array
    {
        $categories = array_values(array_filter(array_map(
            fn ($category) => strtolower(str_replace([' ', '-'], '_', (string) $category)),
            (array) $this->tsc_categories
        )));

        if ($categories === []) {
            $categories = ['security'];
        }

        $criteria = [];

        foreach ($categories as $category) {
            foreach (self::CATEGORY_SERIES[$category] ?? [] as $series) {
                $criteria[] = $series;
            }
        }

        return array_values(array_unique($criteria));
    }

    public function isTypeII(): bool
    {
        return $this->report_type === self::TYPE_II;
    }

    /**
     * Whether the report's audited period covers a date.
     *
     * A Type I has no period — it is an opinion about one day — so a Type I
     * covers only its `period_end`, and asking whether it covers a range is
     * how a reviewer talks themselves into treating a design opinion as an
     * operating one.
     */
    public function coversPeriod(\DateTimeInterface $date): bool
    {
        if ($this->period_end === null) {
            return false;
        }

        if (! $this->isTypeII()) {
            return $this->period_end->isSameDay($date);
        }

        return $this->period_start !== null
            && ! $this->period_start->isAfter($date)
            && ! $this->period_end->isBefore($date);
    }

    /**
     * The gap between the end of the audited period and today, in days.
     *
     * This is the number the bridge letter exists to cover, and the number a
     * reviewer should see beside the words "bridge letter" — a two-week gap
     * and a nine-month gap are not the same reliance.
     */
    public function gapDays(): ?int
    {
        if ($this->period_end === null) {
            return null;
        }

        $days = (int) $this->period_end->diffInDays(now()->startOfDay(), false);

        return max(0, $days);
    }

    public function hasCleanOpinion(): bool
    {
        return $this->opinion_type === null || $this->opinion_type === 'unqualified';
    }
}
