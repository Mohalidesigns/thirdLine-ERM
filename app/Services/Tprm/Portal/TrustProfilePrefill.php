<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Question;
use App\Models\Tprm\ThirdParty;
use App\Support\Tprm\TrustProfileSchema;
use Illuminate\Support\Facades\DB;

/**
 * Carrying a published trust profile into a newly issued questionnaire —
 * FR-PRT-04's payoff, and the reason a vendor completes the profile at all.
 *
 * EVERY PRE-FILL IS A PROPOSAL SHOWN AS CONFIRM-OR-CORRECT, never a submitted
 * answer. The phase prompt is explicit about it and it is also the only honest
 * reading: the vendor wrote those words for a different client, possibly a
 * year ago, about a different service. `is_auto_answered` stays true and
 * `reviewer_status` stays pending, so nothing reaches a score until the vendor
 * has looked at it and the client's reviewer has accepted it.
 *
 * `auto_answer_source` CITES THE PROFILE AND ITS VERSION. A pre-filled answer
 * with no provenance is indistinguishable from one the vendor typed, and when
 * the two disagree a year later — profile at version 7, questionnaire answered
 * from version 3 — the version is the only thing that explains it.
 *
 * MATCHING IS BY EXPLICIT QUESTION MAPPING, NOT BY GUESSING AT TEXT. A
 * similarity score between "Do you encrypt data at rest?" and the profile's
 * `encryption_at_rest` would be right often enough to be trusted and wrong
 * often enough to matter. The map below is short, readable and auditable, and
 * a question outside it is simply not pre-filled — which costs the vendor a
 * few keystrokes and costs nobody a wrong answer under their name.
 */
class TrustProfilePrefill
{
    /**
     * Exact question code => [profile section, profile field].
     *
     * KEYED ON THE CODES THE SHIPPED PACKS ACTUALLY USE, verified against
     * `QuestionnairePacks` rather than invented. The first version of this map
     * was written from plausible-looking abbreviations and matched exactly one
     * of the forty-one shipped questions — a feature that would have looked
     * built, run on every issue, and pre-filled nothing.
     *
     * EXACT CODES, NOT PREFIXES OR SIMILARITY. `CBN-ACC-01` (least privilege)
     * and `CBN-ACC-02` (MFA) share a prefix and are different questions; a
     * prefix rule would answer one with the other's text. A similarity score
     * against the question wording would be right often enough to be trusted
     * and wrong often enough to matter — and the vendor's name is on the
     * answer.
     *
     * A question outside this map is simply not pre-filled. That costs the
     * vendor some keystrokes and costs nobody a wrong answer.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAP = [
        // Access control.
        'CBN-ACC-01' => ['security', 'access_control'],
        'CBN-ACC-02' => ['security', 'access_control'],
        'CBN-ACC-03' => ['security', 'logging_and_monitoring'],
        'CBN-ACC-04' => ['security', 'access_control'],

        // Independent assurance.
        'CBN-ASR-01' => ['certifications', 'soc2'],
        'CBN-ASR-03' => ['security', 'penetration_testing'],

        // Governance and policy.
        'CBN-GOV-01' => ['policies', 'information_security'],

        // Resilience.
        'CBN-RES-01' => ['security', 'business_continuity'],
        'CBN-RES-02' => ['security', 'incident_response'],

        // Supply chain.
        'CBN-SUP-01' => ['subprocessors', 'subprocessors'],
        'ISO-SUP-01' => ['subprocessors', 'subprocessors'],
        'NDPA-SP-01' => ['subprocessors', 'subprocessors'],

        // Exit.
        'ISO-EXIT-01' => ['policies', 'business_continuity'],

        // Data protection.
        'NDPA-BR-01' => ['security', 'incident_response'],
        'NDPA-DEL-01' => ['data_protection', 'retention'],
        'NDPA-DSR-01' => ['data_protection', 'lawful_basis'],
        'NDPA-LAW-01' => ['data_protection', 'lawful_basis'],
        'NDPA-SEC-01' => ['security', 'encryption_at_rest'],
        'NDPA-XB-01' => ['data_protection', 'cross_border_transfers'],

        // Card data.
        'PCI-CHD-02' => ['security', 'encryption_in_transit'],
        'PCI-CS-01' => ['certifications', 'pci_dss'],
    ];

    public function __construct(private readonly TrustProfileService $profiles) {}

    /**
     * Pre-fill an issued assessment from whatever the client is allowed to see.
     *
     * @return array{filled: int, version: int|null, skipped_no_share: bool}
     */
    public function apply(Assessment $assessment): array
    {
        $assessment->loadMissing('engagement');

        // Through the engagement, which is the only path: an assessment
        // belongs to an engagement and an engagement to a third party.
        $thirdParty = ThirdParty::query()->find($assessment->engagement?->third_party_id);

        if ($thirdParty === null) {
            return ['filled' => 0, 'version' => null, 'skipped_no_share' => false];
        }

        $document = $this->profiles->documentFor($thirdParty, (int) $assessment->organization_id);

        if ($document === null) {
            /*
             * No live share. Reported as its own outcome rather than as zero
             * fills, because "the vendor has not agreed to share with you" and
             * "the vendor's profile had nothing relevant" are different facts
             * and only the first has an action attached.
             */
            return ['filled' => 0, 'version' => null, 'skipped_no_share' => true];
        }

        $filled = 0;

        DB::transaction(function () use ($assessment, $document, &$filled): void {
            $responses = AssessmentResponse::query()
                ->where('assessment_id', $assessment->getKey())
                ->with('question')
                ->get();

            foreach ($responses as $response) {
                if ($response->question === null || $response->isAnswered()) {
                    continue;
                }

                $value = $this->valueFor($response->question, $document['sections']);

                if ($value === null) {
                    continue;
                }

                $response->forceFill([
                    'value' => $value,
                    'is_auto_answered' => true,
                    'auto_answer_source' => [
                        'source' => 'trust_profile',
                        'version' => $document['version'],
                        'published_at' => $document['published_at'],
                        // The sentence the vendor reads above the field.
                        'note' => sprintf(
                            'From your trust profile, version %d. Confirm it still applies to this service, '
                            .'or correct it.',
                            $document['version'],
                        ),
                    ],
                    'reviewer_status' => AssessmentResponse::REVIEW_PENDING,
                ])->save();

                $filled++;
            }
        });

        return ['filled' => $filled, 'version' => $document['version'], 'skipped_no_share' => false];
    }

    /**
     * The profile value for one question, or null.
     *
     * @param  array<string, mixed>  $sections
     */
    private function valueFor(Question $question, array $sections): ?string
    {
        $mapping = $this->mappingFor((string) $question->code);

        if ($mapping === null) {
            return null;
        }

        [$section, $field] = $mapping;

        if (! in_array($section, TrustProfileSchema::sectionKeys(), true)) {
            return null;
        }

        $value = $sections[$section][$field] ?? null;

        if (is_array($value)) {
            $value = implode('; ', array_filter(array_map(
                static fn ($item): string => is_scalar($item) ? (string) $item : '',
                $value,
            )));
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Exact match only.
     *
     * See the map's comment: a prefix rule answers `CBN-ACC-02` with
     * `CBN-ACC-01`'s text, which is a wrong answer under the vendor's name.
     *
     * @return array{0: string, 1: string}|null
     */
    private function mappingFor(string $code): ?array
    {
        return self::MAP[mb_strtoupper(trim($code))] ?? null;
    }
}
