<?php

namespace App\Services\Tprm\Evidence;

use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;

/**
 * The scope-mismatch check — FR-DDL-07, and the ×0.7 modifier in TRD §7.4.
 *
 * THE PROBLEM IT SOLVES IS THE MOST COMMON REAL FAILURE IN VENDOR ASSURANCE. A
 * vendor produces an ISO 27001 certificate; the certificate's scope covers
 * "the development and support of the Acme Payments Platform at the Lagos
 * office"; we consume their reconciliation service, hosted in Dublin, and it
 * is not in the scope. Every party has behaved honestly and the certificate
 * evidences nothing about the service we buy. Almost nobody checks, because
 * checking means reading a scope statement against a service description and
 * noticing they are different — which is exactly the boring comparison a tool
 * should do.
 *
 * IT FLAGS, IT DOES NOT DECIDE. The output is a flag and a reason, shown to a
 * reviewer who can confirm or dismiss it. The comparison is textual and
 * language is not; a scope reading "cloud infrastructure services" plainly
 * covers an engagement described as "IaaS hosting" and no token overlap will
 * see it. So a mismatch is a QUESTION put to a human — "does this certificate
 * cover this service?" — and the ×0.7 applies only once the human agrees it
 * does not. A silently applied score penalty from a word-overlap heuristic
 * would be exactly the kind of unexplainable number TRD §7.9 exists to forbid.
 */
class ScopeMatcher
{
    /**
     * Below this overlap, the reviewer is asked. Set low deliberately: the
     * cost of a false flag is one dismissal, and the cost of a missed one is
     * an engagement scored as independently assured on a certificate that
     * covers something else.
     */
    public const OVERLAP_THRESHOLD = 0.25;

    /**
     * Words that appear in every scope statement and every service
     * description, and so distinguish nothing. Left deliberately short — a
     * long stop list starts removing the domain words that carry the meaning.
     *
     * @var list<string>
     */
    private const NOISE = [
        'the', 'and', 'or', 'of', 'for', 'to', 'in', 'at', 'on', 'a', 'an', 'with', 'by', 'from',
        'services', 'service', 'systems', 'system', 'provision', 'provided', 'including', 'related',
        'management', 'support', 'solutions', 'solution', 'limited', 'ltd', 'plc', 'inc',
    ];

    /**
     * Compare a document's scope against an engagement's service description.
     *
     * @return array{checked: bool, mismatch: bool, overlap: float, reason: string, modifier: float}
     */
    public function check(Document $document, Engagement $engagement): array
    {
        $scope = trim((string) $document->scope_text);
        $service = trim(implode(' ', array_filter([
            $engagement->name,
            $engagement->service_description,
            $engagement->serviceType?->name,
        ])));

        if ($scope === '') {
            return $this->result(
                checked: false,
                mismatch: false,
                overlap: 0.0,
                reason: 'This document records no scope statement, so there is nothing to compare. A '
                    .'certificate whose scope has not been captured cannot be relied on for a specific service '
                    .'until somebody reads it.',
            );
        }

        if ($service === '') {
            return $this->result(
                checked: false,
                mismatch: false,
                overlap: 0.0,
                reason: 'The engagement has no service description to compare the scope against.',
            );
        }

        $overlap = $this->overlap($scope, $service);

        if ($overlap >= self::OVERLAP_THRESHOLD) {
            return $this->result(
                checked: true,
                mismatch: false,
                overlap: $overlap,
                reason: 'The document\'s scope statement and the engagement\'s service description share their '
                    .'main terms.',
            );
        }

        return $this->result(
            checked: true,
            mismatch: true,
            overlap: $overlap,
            reason: 'The scope on this document does not appear to name the service this engagement covers. '
                .'Scope: "'.\Illuminate\Support\Str::limit($scope, 200).'". Service: "'
                .\Illuminate\Support\Str::limit($service, 200).'". Confirm whether the document covers this '
                .'service — if it does not, the confidence in every answer it evidences is reduced.',
        );
    }

    /**
     * The share of the engagement's meaningful words that appear in the scope.
     *
     * ASYMMETRIC ON PURPOSE, and this is the whole subtlety. The question is
     * not "how similar are these two texts" but "does the certificate's scope
     * COVER the service" — so the denominator is the service's words. A
     * certificate with a sprawling scope covering forty systems including ours
     * scores high, correctly; a certificate whose scope is one narrow system
     * that is not ours scores low, also correctly. A symmetric measure would
     * penalise the first for being broad, which is backwards.
     */
    public function overlap(string $scope, string $service): float
    {
        $scopeWords = $this->tokenise($scope);
        $serviceWords = $this->tokenise($service);

        if ($serviceWords === []) {
            return 0.0;
        }

        $matched = 0;

        foreach ($serviceWords as $word) {
            foreach ($scopeWords as $candidate) {
                // Prefix matching so "hosting" matches "hosted" and
                // "reconciliation" matches "reconciliations", without a
                // stemmer this product does not have and does not need for the
                // sake of a flag a human reviews.
                if (str_starts_with($candidate, $word) || str_starts_with($word, $candidate)) {
                    $matched++;

                    break;
                }
            }
        }

        return round($matched / count($serviceWords), 3);
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            // Words of three characters or fewer carry no distinguishing
            // weight here and inflate the overlap when they happen to match.
            fn (string $word) => mb_strlen($word) > 3 && ! in_array($word, self::NOISE, true)
        )));
    }

    /**
     * @return array{checked: bool, mismatch: bool, overlap: float, reason: string, modifier: float}
     */
    private function result(bool $checked, bool $mismatch, float $overlap, string $reason): array
    {
        return [
            'checked' => $checked,
            'mismatch' => $mismatch,
            'overlap' => $overlap,
            'reason' => $reason,
            // The modifier this WOULD apply once a reviewer confirms the
            // mismatch. Returned rather than applied, so the screen can say
            // what is at stake before anyone clicks.
            'modifier' => $mismatch ? (float) config('tprm.scoring.modifiers.scope_mismatch', 0.7) : 1.0,
        ];
    }
}
