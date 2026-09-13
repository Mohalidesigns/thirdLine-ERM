<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\ComplianceLevel;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use Illuminate\Support\Collection;

/**
 * Reading a vendor's answers before a reviewer does — TRD §12.5.
 *
 * THE PROBLEM IS REAL AND EVERYONE IN THIS MARKET HAS IT. A vendor completing
 * its fourth questionnaire of the quarter pastes the same paragraph into
 * fifteen boxes, answers "yes" to a question that asks "how", and attaches a
 * certificate whose scope excludes the service. A reviewer working through
 * forty answers at the end of a Friday accepts all of it, and the assurance
 * score that comes out is arithmetically perfect and worth nothing.
 *
 * EVERY FLAG CARRIES THE TWO TEXTS IN TENSION. "Non-responsive" on its own is
 * an accusation a vendor will dispute and a reviewer cannot adjudicate; the
 * question and the answer side by side is a judgement anybody can make in five
 * seconds. That is the difference between a checker people use and one they
 * switch off.
 *
 * IT IS DETERMINISTIC, NOT A MODEL. Every check below is a rule over text the
 * system already holds, so it runs on every submission with AI switched off,
 * costs nothing, and produces the same verdict twice. §12.6's adverse-media
 * triage is where a model earns its place; telling a reviewer that two answers
 * are byte-identical does not need one.
 *
 * THE FLAGS REORDER THE QUEUE RATHER THAN REJECTING THE ANSWER. A flag is a
 * reason to look first, not a verdict — and the ×0.5 confidence modifier is
 * applied only where the reviewer agrees, because a checker that silently
 * halved a score on a heuristic would be making an assurance judgement no
 * human made.
 */
class ResponseQualityChecker
{
    public const NON_RESPONSIVE = 'non_responsive';

    public const BOILERPLATE = 'boilerplate';

    public const INTERNAL_INCONSISTENCY = 'internal_inconsistency';

    public const EVIDENCE_CONTRADICTION = 'evidence_contradiction';

    public const UNEVIDENCED_CLAIM = 'unevidenced_claim';

    /**
     * Check every answer in an assessment and store the flags.
     *
     * @return array{flagged: int, flags: array<string, int>}
     */
    public function check(Assessment $assessment): array
    {
        $responses = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->with(['question', 'evidence.document'])
            ->get();

        $duplicates = $this->duplicateComments($responses);

        $flagged = 0;
        $counts = [];

        foreach ($responses as $response) {
            $flags = $this->flagsFor($response, $duplicates);

            // Written even when empty, so "checked and clean" is
            // distinguishable from "never checked" — a reviewer seeing no
            // flags should know which of those they are looking at.
            $response->forceFill(['quality_flags' => $flags])->save();

            if ($flags !== []) {
                $flagged++;

                foreach ($flags as $flag) {
                    $counts[$flag['flag']] = ($counts[$flag['flag']] ?? 0) + 1;
                }
            }
        }

        return ['flagged' => $flagged, 'flags' => $counts];
    }

    /**
     * The flags on one answer.
     *
     * @param  array<string, int>  $duplicates  normalised comment => how many answers used it
     * @return list<array<string, mixed>>
     */
    public function flagsFor(AssessmentResponse $response, array $duplicates = []): array
    {
        $question = $response->question;

        if ($question === null || $response->compliance === ComplianceLevel::Unanswered) {
            return [];
        }

        $flags = [];
        $comment = trim((string) $response->vendor_comment);

        // A free-text question answered with nothing, or with a bare yes.
        if ($question->type === 'free_text' && $this->isNonResponsive($comment)) {
            $flags[] = [
                'flag' => self::NON_RESPONSIVE,
                'reason' => 'This question asks for a description and the answer does not give one.',
                'question' => $question->text,
                'answer' => $comment === '' ? '(no comment given)' : $comment,
            ];
        }

        // The same paragraph in several boxes.
        if ($comment !== '' && ($duplicates[$this->fold($comment)] ?? 0) > 2) {
            $flags[] = [
                'flag' => self::BOILERPLATE,
                'reason' => sprintf(
                    'The same text answers %d questions in this assessment. It may be a genuine policy '
                    .'statement that covers all of them — or a paragraph pasted into every box.',
                    $duplicates[$this->fold($comment)],
                ),
                'question' => $question->text,
                'answer' => $comment,
            ];
        }

        // Compliant, evidence required by the question, nothing attached.
        if ($response->compliance === ComplianceLevel::Compliant
            && $question->evidence_required
            && $response->evidence->isEmpty()) {
            $flags[] = [
                'flag' => self::UNEVIDENCED_CLAIM,
                'reason' => 'The answer claims compliance and this question requires evidence, but none is '
                    .'attached. The claim scores as self-attested until something supports it.',
                'question' => $question->text,
                'answer' => $response->compliance->label(),
            ];
        }

        // A compliant answer whose own words say otherwise.
        if ($response->compliance === ComplianceLevel::Compliant && $this->contradictsCompliance($comment)) {
            $flags[] = [
                'flag' => self::INTERNAL_INCONSISTENCY,
                'reason' => 'The answer is marked compliant but its own wording describes a gap, a plan or an '
                    .'exception.',
                'question' => $question->text,
                'answer' => $comment,
            ];
        }

        // An answer relying on a document that has expired.
        foreach ($response->evidence as $evidence) {
            $document = $evidence->document ?? null;

            if ($document !== null && $document->isExpired()) {
                $flags[] = [
                    'flag' => self::EVIDENCE_CONTRADICTION,
                    'reason' => sprintf(
                        'The attached evidence expired on %s, so it does not support an answer about the '
                        .'current period.',
                        $document->valid_to?->toDateString(),
                    ),
                    'question' => $question->text,
                    'answer' => $document->title,
                ];

                break;
            }
        }

        return $flags;
    }

    /**
     * The review queue, flagged answers first — TRD §12.5's reordering.
     *
     * Within the flagged set, by how many flags: an answer that is
     * non-responsive AND boilerplate AND unevidenced is the one to open first,
     * and a reviewer working top-down should not have to notice that for
     * themselves.
     *
     * @return Collection<int, AssessmentResponse>
     */
    public function reviewQueue(Assessment $assessment): Collection
    {
        return AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->with(['question'])
            ->get()
            ->sortByDesc(fn (AssessmentResponse $response) => count((array) ($response->quality_flags ?? [])))
            ->values();
    }

    /**
     * Comments used by more than one answer.
     *
     * @param  Collection<int, AssessmentResponse>  $responses
     * @return array<string, int>
     */
    private function duplicateComments(Collection $responses): array
    {
        $counts = [];

        foreach ($responses as $response) {
            $comment = trim((string) $response->vendor_comment);

            if (strlen($comment) < 40) {
                // Short answers repeat legitimately — "Yes", "Annually", "N/A"
                // — and flagging those would bury the paragraph that matters.
                continue;
            }

            $key = $this->fold($comment);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function isNonResponsive(string $comment): bool
    {
        if ($comment === '') {
            return true;
        }

        $folded = strtolower(trim($comment, " \t\n\r.\u{00A0}"));

        // A question asking "how" or "what" answered with a bare affirmation.
        return in_array($folded, [
            'yes', 'no', 'n/a', 'na', 'none', 'not applicable', 'confidential',
            'as per policy', 'see policy', 'available on request', 'tbc', 'noted',
        ], true) || strlen($folded) < 12;
    }

    /**
     * Whether a comment describes something other than compliance.
     *
     * Phrases that mean the control is planned, partial or excepted. Kept
     * short and specific: a broad list would flag every honest answer that
     * mentions a roadmap, and a reviewer who dismisses four flags in a row
     * stops reading the fifth.
     */
    private function contradictsCompliance(string $comment): bool
    {
        if ($comment === '') {
            return false;
        }

        $patterns = [
            '/\bwill be (implemented|deployed|rolled out|completed)\b/i',
            '/\b(in progress|under way|underway|being implemented)\b/i',
            '/\b(planned for|scheduled for|targeted for) (q[1-4]|20\d\d)/i',
            '/\bnot (yet|currently) (implemented|in place|available)\b/i',
            '/\bwith the exception of\b/i',
            '/\bpartially\b/i',
            '/\bexcept for\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $comment) === 1) {
                return true;
            }
        }

        return false;
    }

    private function fold(string $text): string
    {
        return md5(preg_replace('/\s+/', ' ', strtolower(trim($text))) ?? $text);
    }
}
