<?php

namespace App\Services\Tprm\Screening\Drivers;

use App\Models\Tprm\SanctionsEntry;
use App\Models\Tprm\SanctionsList;
use App\Services\Tprm\Screening\ScreeningDriver;
use App\Services\Tprm\Screening\ScreeningResult;

/**
 * Screening against a locally held list — the UNSCR consolidated list and the
 * Nigeria Sanctions List, both free and both shipped.
 *
 * THIS IS WHAT MAKES SCREENING TRUE ON DAY ONE. Every competitor's sanctions
 * screening is a paid API, so a Nigerian bank with no data budget buys an AML
 * control that does nothing. Two lists that cost nothing, held locally and
 * searched here, cover the designations a CBN examiner will actually ask
 * about.
 *
 * AN EMPTY LIST NEVER REPORTS CLEAR. A list nobody has refreshed holds no
 * entries, and a search of it finds nothing — which is indistinguishable from
 * a genuine clear result unless the driver says so. It fails instead, with the
 * reason, because "we screened and found nothing" written against an empty
 * list is the single most dangerous sentence this module could produce.
 *
 * MATCHING IS DELIBERATELY BROAD. The token-sorted normalised form catches
 * inverted and punctuated spellings, and a partial token overlap catches
 * "Yasin Qadi" against "Yasin Abdullah Ezzedine al-Qadi". Broad matching
 * produces false positives, which a human dismisses with a rationale in
 * thirty seconds; narrow matching produces false negatives, which nobody ever
 * sees.
 */
class LocalListDriver implements ScreeningDriver
{
    public function __construct(private readonly string $listCode) {}

    public function key(): string
    {
        return $this->listCode;
    }

    public function name(): string
    {
        return $this->list()->name ?? $this->listCode;
    }

    /** @return list<string> */
    public function listTypes(): array
    {
        return ['sanctions'];
    }

    /** @return array{available: bool, reason: string|null} */
    public function availability(): array
    {
        $list = $this->list();

        if ($list === null) {
            return [
                'available' => false,
                'reason' => 'The '.$this->listCode.' list is not installed.',
            ];
        }

        if (! $list->is_active) {
            return ['available' => false, 'reason' => $list->name.' is switched off.'];
        }

        if ($list->isEmpty()) {
            return [
                'available' => false,
                // Named precisely, because the tempting failure is to treat an
                // empty list as a clear result.
                'reason' => $list->name.' holds no entries. It has to be refreshed before a search of it '
                    .'means anything — a clear result against an empty list is not a clear result.',
            ];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function search(string $name, array $context = []): ScreeningResult
    {
        $availability = $this->availability();

        if (! $availability['available']) {
            return ScreeningResult::failed((string) $availability['reason']);
        }

        $list = $this->list();
        $normalised = SanctionsEntry::normalise($name);
        $tokens = array_values(array_filter(explode(' ', $normalised)));

        if ($tokens === []) {
            return ScreeningResult::failed('There is no name to search for.');
        }

        // Candidates first, scored second. Pulling every entry whose
        // normalised name contains any one of the subject's tokens keeps the
        // query indexable; scoring in PHP over that candidate set is cheap and
        // lets the scoring rule change without a migration.
        $candidates = SanctionsEntry::query()
            ->where('list_id', $list->getKey())
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    if (strlen($token) < 3) {
                        continue;
                    }

                    $query->orWhere('normalised_name', 'like', '%'.$token.'%');
                }
            })
            ->limit(500)
            ->get();

        $matches = [];

        foreach ($candidates as $entry) {
            $score = $this->score($tokens, $entry);

            if ($score < 0.5) {
                continue;
            }

            $matches[] = [
                'list_name' => $list->name,
                'matched_name' => $entry->name,
                'score' => round($score * 100, 2),
                'details' => [
                    'external_id' => $entry->external_id,
                    'entity_type' => $entry->entity_type,
                    'country' => $entry->country,
                    'date_of_birth' => $entry->date_of_birth,
                    'programme' => $entry->programme,
                    'listed_on' => $entry->listed_on?->toDateString(),
                    'aliases' => $entry->aliases,
                ],
            ];
        }

        // Strongest first: a reviewer works down a match list and the exact
        // name should not be third.
        usort($matches, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $raw = [
            'provider' => $this->listCode,
            'list_name' => $list->name,
            'list_refreshed_at' => $list->last_refreshed_at?->toIso8601String(),
            'list_entry_count' => $list->entry_count,
            'searched_name' => $name,
            'normalised_name' => $normalised,
            'candidates_examined' => $candidates->count(),
            'searched_at' => now()->toIso8601String(),
            'matches' => $matches,
        ];

        return $matches === [] ? ScreeningResult::clear($raw) : ScreeningResult::found($matches, $raw);
    }

    /**
     * How much of the subject's name the entry accounts for.
     *
     * The share of the subject's tokens that appear in the entry, taking the
     * best of the entry's primary name and its aliases. Asymmetric on purpose,
     * exactly as the certificate scope matcher is: a designation carrying four
     * given names should still match a subject recorded with two of them, and
     * penalising the entry for being fuller would miss it.
     *
     * @param  list<string>  $tokens
     */
    private function score(array $tokens, SanctionsEntry $entry): float
    {
        $best = 0.0;

        foreach ($entry->normalisedForms() as $form) {
            $formTokens = array_values(array_filter(explode(' ', $form)));

            if ($formTokens === []) {
                continue;
            }

            $matched = 0;

            foreach ($tokens as $token) {
                foreach ($formTokens as $candidate) {
                    if ($token === $candidate
                        || (strlen($token) > 3 && str_starts_with($candidate, $token))
                        || (strlen($candidate) > 3 && str_starts_with($token, $candidate))) {
                        $matched++;

                        break;
                    }
                }
            }

            $best = max($best, $matched / count($tokens));
        }

        return $best;
    }

    private function list(): ?SanctionsList
    {
        return SanctionsList::query()->where('code', $this->listCode)->first();
    }
}
