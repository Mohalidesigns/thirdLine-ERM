<?php

namespace App\Services\Tprm\Graph;

use App\Enums\Tprm\DisclosureSource;
use App\Models\Tprm\Document;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Extraction\DocumentTextExtractor;
use App\Support\Tprm\KnownProviders;
use Illuminate\Support\Collection;

/**
 * Propose sub-processor edges from a document's text — FR-NTH-06, TRD §12.4.
 *
 * EVERY EDGE THIS PRODUCES IS A PROPOSAL. Nothing here confirms anything, the
 * graph traversals ignore proposals until a person accepts them, and the
 * concentration analysis does not count them. That is not caution for its own
 * sake: a discovered edge is a machine's reading of a PDF, and a supply chain
 * map that quietly filled itself from PDF text would be worse than no map,
 * because somebody would trust it.
 *
 * MATCHING IS DICTIONARY-BASED, NOT FUZZY, and not a language model. See
 * `KnownProviders` for why. The cost is real: a sub-processor outside the
 * dictionary is not found, and `unmatched()` reports how much text was
 * skipped so nobody mistakes a short proposal list for a short supply chain.
 *
 * THE PARSER IS DELIBERATELY CRUDE. It scans for known names and takes the
 * line they sit on as the service description. A DPA annex is usually a table,
 * and reconstructing table structure from extracted text is a research
 * problem, not a feature — the line is enough for a reviewer to recognise the
 * row and correct it.
 */
class SubprocessorDiscoverer
{
    public function __construct(
        private readonly NthPartyService $edges,
        private readonly DocumentTextExtractor $text,
    ) {}

    /**
     * Read text and propose edges under the given parent.
     *
     * @return array{proposed: list<NthPartyEdge>, matched: list<string>, unmatched_lines: int, scanned_lines: int}
     */
    public function discover(
        ThirdParty $parent,
        string $text,
        DisclosureSource $source = DisclosureSource::Discovered,
        ?int $userId = null,
    ): array {
        $index = KnownProviders::index();
        $lines = $this->lines($text);

        $proposed = [];
        $matched = [];
        $hits = 0;

        foreach ($lines as $line) {
            $found = $this->namesIn($line, $index);

            if ($found === []) {
                continue;
            }

            $hits++;

            foreach ($found as $canonical) {
                if (in_array($canonical, $matched, true)) {
                    continue;
                }

                /*
                 * A vendor listing ITSELF in its own sub-processor annex is
                 * common — the header says who the annex belongs to — and
                 * proposing it would offer the reviewer a self-loop the graph
                 * would then refuse.
                 */
                if (KnownProviders::normalise($parent->legal_name) === KnownProviders::normalise($canonical)) {
                    continue;
                }

                $matched[] = $canonical;

                $edge = $this->propose($parent, $canonical, $line, $source, $userId);

                if ($edge !== null) {
                    $proposed[] = $edge;
                }
            }
        }

        return [
            'proposed' => $proposed,
            'matched' => $matched,
            'unmatched_lines' => max(0, count($lines) - $hits),
            'scanned_lines' => count($lines),
        ];
    }

    /**
     * Discover from a document that has already been text-extracted.
     *
     * Returns an empty result with a REASON where there is no text, rather
     * than an empty result that looks like a clean supply chain. The product
     * ships without a bundled PDF reader (Phase 3 detects poppler and says so
     * when it is absent), so on many installations this is the common path and
     * it has to be visibly different from "we looked and found nothing".
     *
     * @return array{proposed: list<NthPartyEdge>, matched: list<string>, unmatched_lines: int, scanned_lines: int, unavailable: string|null}
     */
    public function discoverFromDocument(Document $document, ?int $userId = null): array
    {
        ['text' => $text, 'reason' => $reason] = $this->text->textFor($document);

        if ($text === null || trim($text) === '') {
            return [
                'proposed' => [],
                'matched' => [],
                'unmatched_lines' => 0,
                'scanned_lines' => 0,
                'unavailable' => 'Nothing was scanned, so this is not a finding of "no sub-processors". '
                    .($reason ?? 'The document produced no readable text.'),
            ];
        }

        $parent = $this->parentFor($document);

        if ($parent === null) {
            return [
                'proposed' => [],
                'matched' => [],
                'unmatched_lines' => 0,
                'scanned_lines' => 0,
                'unavailable' => 'This document is not attached to a third party, so any sub-processor found in it '
                    .'would have nothing to hang from.',
            ];
        }

        $source = $document->documentType?->code === 'dpa'
            ? DisclosureSource::DpaAnnex
            : DisclosureSource::Discovered;

        return $this->discover($parent, $text, $source, $userId) + ['unavailable' => null];
    }

    /**
     * @param  array<string, string>  $index
     * @return list<string>
     */
    private function namesIn(string $line, array $index): array
    {
        $haystack = ' '.KnownProviders::normalise($line).' ';
        $found = [];

        foreach ($index as $needle => $canonical) {
            if ($needle === '') {
                continue;
            }

            /*
             * Whole-token match. Substring matching turns "Meta" into a hit on
             * "metadata" and the dictionary's short names — AWS, GCP, T24 —
             * are exactly the ones that would misfire.
             */
            if (str_contains($haystack, ' '.$needle.' ') && ! in_array($canonical, $found, true)) {
                $found[] = $canonical;
            }
        }

        return $found;
    }

    private function propose(
        ThirdParty $parent,
        string $canonical,
        string $line,
        DisclosureSource $source,
        ?int $userId,
    ): ?NthPartyEdge {
        $child = $this->edges->matchToRegister($canonical, (int) $parent->organization_id);

        try {
            return $this->edges->record(
                $parent,
                $canonical,
                $child,
                $source,
                [
                    'service_description' => mb_substr(trim($line), 0, 500),
                    'rank' => 1,
                ],
                $userId,
            );
        } catch (\InvalidArgumentException) {
            /*
             * The cycle guard refused it. A discovery pass is not the place to
             * surface that as an error — the document says what it says — so
             * the edge is dropped and the reviewer sees one fewer proposal.
             */
            return null;
        }
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return collect(preg_split('/\R+/', $text) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    private function parentFor(Document $document): ?ThirdParty
    {
        if ($document->owner_type === 'third_party') {
            return ThirdParty::query()->find($document->owner_id);
        }

        if ($document->owner_type === 'engagement') {
            return ThirdParty::query()
                ->whereHas('engagements', fn ($q) => $q->whereKey($document->owner_id))
                ->first();
        }

        return null;
    }

    /**
     * The proposals still awaiting a person, newest first.
     *
     * @return Collection<int, NthPartyEdge>
     */
    public function pending(int $organizationId)
    {
        return NthPartyEdge::query()
            ->where('organization_id', $organizationId)
            ->proposed()
            ->whereIn('disclosure_source', [
                DisclosureSource::Discovered->value,
                DisclosureSource::DpaAnnex->value,
                DisclosureSource::PublicPage->value,
            ])
            ->with(['parent:id,uuid,legal_name', 'child:id,uuid,legal_name'])
            ->orderByDesc('id')
            ->get();
    }
}
