<?php

namespace App\Services\Tprm\Evidence;

/**
 * The one defence against a confident invention — TRD §12.
 *
 * "Every extraction returns page-level citations, and the CitationVerifier
 * rejects any claim whose quoted text is not found in the source document — an
 * extraction that cannot cite is returned as `low_confidence` and queued for
 * manual entry, never silently accepted."
 *
 * A model asked to read a SOC 2 will, on a bad day, return a plausible service
 * auditor and a plausible opinion for a report that says neither. Nothing else
 * in the pipeline can tell the difference: the JSON schema passes, the
 * confidence is high, the fields look right. Only checking the quoted text
 * against the document catches it.
 *
 * PURE, AND DELIBERATELY FORGIVING ABOUT WHITESPACE AND NOTHING ELSE. PDF text
 * extraction inserts line breaks mid-sentence, double spaces after full stops,
 * and ligatures; a verifier that demanded byte equality would reject every
 * true citation. So whitespace collapses and case is ignored — and that is the
 * whole of the latitude. A quote that differs in a WORD is not a quote.
 */
class CitationVerifier
{
    /**
     * Characters that PDF extraction commonly mangles, normalised before
     * comparison. Smart quotes and dashes are the usual culprits: a report
     * containing a typographic apostrophe and a model returning a straight one
     * are quoting the same text.
     *
     * @var array<string, string>
     */
    private const EQUIVALENTS = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{2026}" => '...',
        "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
    ];

    /**
     * Verify every citation in an extraction against the document's text.
     *
     * @param  list<array{field?: string, quote?: string, page?: int|string|null}>  $citations
     * @return CitationVerification
     */
    public function verify(array $citations, string $documentText): CitationVerification
    {
        $haystack = $this->normalise($documentText);

        $verified = [];
        $rejected = [];

        foreach ($citations as $citation) {
            $quote = trim((string) ($citation['quote'] ?? ''));
            $field = (string) ($citation['field'] ?? 'unknown');

            // A citation with no quote cites nothing. It is rejected rather
            // than passed over, because "the model gave a page number but no
            // text" is exactly the shape a fabrication takes.
            if ($quote === '') {
                $rejected[] = [
                    'field' => $field,
                    'quote' => '',
                    'page' => $citation['page'] ?? null,
                    'reason' => 'The citation quotes no text, so there is nothing to check it against.',
                ];

                continue;
            }

            $needle = $this->normalise($quote);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                $verified[] = [
                    'field' => $field,
                    'quote' => $quote,
                    'page' => $citation['page'] ?? null,
                ];

                continue;
            }

            $rejected[] = [
                'field' => $field,
                'quote' => $quote,
                'page' => $citation['page'] ?? null,
                'reason' => 'The quoted text does not appear in the document.',
            ];
        }

        return new CitationVerification($verified, $rejected);
    }

    /**
     * Whitespace collapsed, case folded, typographic characters normalised.
     *
     * Nothing else. Stripping punctuation as well would let "we do not
     * maintain a policy" verify a claim quoting "we maintain a policy", which
     * is the precise failure this class exists to prevent.
     */
    private function normalise(string $text): string
    {
        $text = strtr($text, self::EQUIVALENTS);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
