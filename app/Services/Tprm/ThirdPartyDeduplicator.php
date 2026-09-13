<?php

namespace App\Services\Tprm;

use App\Models\Tprm\ThirdParty;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * FR-TPR-02 — deduplication on create.
 *
 * "Present a merge candidate list RATHER THAN SILENTLY BLOCKING." The wording
 * is the requirement: a hard block on a near-match is wrong, because
 * "Interlink Systems Ltd" and "Interlink Systems Nigeria Ltd" are routinely
 * two real companies, and a user prevented from registering the second one
 * simply registers it as "Interlink Systems Nig" instead — which corrupts the
 * register more thoroughly than the duplicate would have.
 *
 * TWO TIERS OF MATCH, and they are different claims:
 *
 *   EXACT   — the same RC number, TIN or LEI. These are registry identifiers;
 *             two records sharing one are the same legal entity, and this is
 *             as close to certain as the data allows.
 *
 *   FUZZY   — a similar name. This is a suggestion. It is computed after
 *             stripping the corporate-form noise ("limited", "plc", "nigeria")
 *             that makes every Nigerian company name look 70% like every
 *             other, because without that step the suggestion list is all
 *             noise and gets ignored.
 *
 * The comparison is done in PHP over a narrowed candidate set rather than in
 * SQL. `SOUNDEX` and trigram similarity are not portable between this
 * product's two drivers — SQLite has neither — so a SQL implementation would
 * behave differently in tests than in production, which is precisely how a
 * deduplication rule ends up untested.
 */
class ThirdPartyDeduplicator
{
    /** Names below this similarity are not worth showing. */
    private const FUZZY_THRESHOLD = 82.0;

    /** Corporate-form words that carry no distinguishing information. */
    private const NOISE = [
        'limited', 'ltd', 'plc', 'incorporated', 'inc', 'company', 'co',
        'nigeria', 'nigerian', 'ng', 'international', 'global', 'group',
        'holdings', 'services', 'service', 'solutions', 'enterprises', 'ventures',
        'and', 'the',
    ];

    /**
     * Candidates that may be the same entity as the one being registered.
     *
     * @param  array<string, string|null>  $attributes  legal_name, registration_number, tax_id, lei
     * @return Collection<int, array{third_party: ThirdParty, kind: string, on: string, confidence: float}>
     */
    public function candidatesFor(array $attributes, ?int $excludeId = null): Collection
    {
        $matches = collect();

        foreach (['registration_number' => 'RC number', 'tax_id' => 'TIN', 'lei' => 'LEI'] as $column => $label) {
            $value = $this->normaliseIdentifier($attributes[$column] ?? null);

            if ($value === null) {
                continue;
            }

            ThirdParty::query()
                ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
                ->whereNotNull($column)
                ->get()
                ->filter(fn (ThirdParty $t) => $this->normaliseIdentifier($t->{$column}) === $value)
                ->each(function (ThirdParty $t) use (&$matches, $label) {
                    $matches->push([
                        'third_party' => $t,
                        'kind' => 'exact',
                        'on' => $label,
                        'confidence' => 100.0,
                    ]);
                });
        }

        $name = $attributes['legal_name'] ?? null;

        if (is_string($name) && $name !== '') {
            $needle = $this->normaliseName($name);

            ThirdParty::query()
                ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
                ->get(['id', 'uuid', 'legal_name', 'trading_name', 'slug', 'registration_number', 'status', 'organization_id'])
                ->each(function (ThirdParty $t) use (&$matches, $needle) {
                    $score = $this->similarity($needle, $this->normaliseName((string) $t->legal_name));

                    if ($t->trading_name) {
                        $score = max($score, $this->similarity($needle, $this->normaliseName($t->trading_name)));
                    }

                    if ($score >= self::FUZZY_THRESHOLD) {
                        $matches->push([
                            'third_party' => $t,
                            'kind' => 'fuzzy',
                            'on' => 'Name',
                            'confidence' => round($score, 1),
                        ]);
                    }
                });
        }

        // One row per candidate, keeping the strongest reason. A vendor that
        // matches on both RC number and name should be listed once, as an
        // exact match.
        return $matches
            ->sortByDesc('confidence')
            ->unique(fn (array $match) => $match['third_party']->getKey())
            ->values();
    }

    /** Whether any candidate is an exact identifier match. */
    public function hasExactMatch(Collection $candidates): bool
    {
        return $candidates->contains(fn (array $match) => $match['kind'] === 'exact');
    }

    /**
     * Identifiers compare case-insensitively with punctuation and spacing
     * removed: "RC 441290", "rc-441290" and "RC441290" are one number written
     * three ways, and a register that treats them as three companies is the
     * thing this method exists to prevent.
     */
    private function normaliseIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');

        return $normalised === '' ? null : $normalised;
    }

    /** Lower-cased, punctuation-stripped, corporate-form words removed. */
    private function normaliseName(string $name): string
    {
        $words = preg_split('/\s+/', Str::lower(preg_replace('/[^A-Za-z0-9\s]/', ' ', $name) ?? '')) ?: [];

        $meaningful = array_values(array_filter(
            $words,
            fn (string $word) => $word !== '' && ! in_array($word, self::NOISE, true)
        ));

        // A name made entirely of noise ("Nigeria Limited") keeps its original
        // words rather than becoming an empty string that matches everything.
        return implode(' ', $meaningful !== [] ? $meaningful : array_filter($words));
    }

    /** Percentage similarity, symmetric. */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        return (float) $percent;
    }
}
