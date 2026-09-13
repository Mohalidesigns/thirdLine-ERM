<?php

namespace Tests\Feature\Tprm;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AC 21 / phase-11a-ai-contract.md §7.5 — the extraction confirmation
 * screen's completeness banner.
 *
 * NO JAVASCRIPT EXECUTES IN THIS SUITE, so this cannot certify that the
 * banner renders correctly for a real reviewer — that requires a browser.
 * What it CAN do, and does, is assert that the source contains the render
 * logic §7.5 requires at all: a screen that never reads
 * `extraction.extracted._meta.context_window` cannot possibly render any of
 * the three states the contract specifies, no matter how a browser exercises
 * it. This is a floor, not a substitute for browser verification — but a
 * screen that fails this floor has not been built, browser-verified or not.
 *
 * The negative half — "read in full" / "the model read" must appear
 * NOWHERE — is trivially true of a file that never mentions the feature at
 * all, so it is asserted here only as a companion to the positive checks,
 * never as evidence of correctness on its own.
 */
class DocumentShowCompletenessBannerSourceTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/Tprm/Documents/Show.jsx'));
    }

    /**
     * Comments stripped — this file's OWN docblocks discuss the forbidden
     * wording in prose while explaining why it must never render (e.g. "...
     * indistinguishable on screen from one it read in full unless this is
     * said out loud"), which is exactly the false positive
     * `LlmGatewayGuardTest::stripComments()` exists to avoid on the PHP side.
     * The negative assertions below must check rendered code, not commentary
     * about the rule.
     */
    private function sourceWithoutComments(): string
    {
        $source = $this->source();
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
        $source = preg_replace('#(?<!:)//.*$#m', '', $source) ?? $source;

        return $source;
    }

    #[Test]
    public function the_screen_reads_the_context_window_meta_at_all(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'context_window',
            $source,
            'phase-11a-ai-contract.md §7.5 requires resources/js/Pages/Tprm/Documents/Show.jsx to branch on '
            .'`_meta.context_window` (the confirmed/unconfirmed completeness states). The string does not '
            .'appear anywhere in the file: the backend computes and persists `_meta.context_window.fitted` '
            .'(ExtractionDispatcher::contextWindowMeta(), verified by DocumentExtractionContextWindowTest), '
            .'and ships it to the browser in `extraction.extracted._meta` (DocumentController::show()), but '
            .'nothing on the frontend ever reads it. This is the same shape as the two defects that caused '
            .'gate-2 rejection 3: a frozen, backend-computed requirement that never reaches the screen it '
            .'was written for.'
        );
    }

    #[Test]
    public function the_screen_branches_on_fitted(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'fitted',
            $source,
            'phase-11a-ai-contract.md §7.5\'s three states branch on `document_truncated === null` AND on '
            .'`context_window.fitted` (true / false / null). `fitted` must be read, never recomputed, in '
            .'JavaScript. The word does not appear in this file at all.'
        );
    }

    #[Test]
    public function the_confirmed_state_prints_sent_whole_only_next_to_both_numbers(): void
    {
        $source = $this->source();

        // §7.5 rule 2: "Sent whole" is permitted ONLY in the confirmed state
        // and ONLY next to the two numbers that make it checkable. This
        // cannot be satisfied by a file that never computes the confirmed
        // state's condition (`document_truncated === null && fitted === true`)
        // in the first place.
        $this->assertMatchesRegularExpression(
            '/fitted\s*===\s*true/',
            $source,
            '§7.5\'s confirmed state is reached only when `document_truncated` is null AND `fitted === true`. '
            .'No such condition exists in this file.'
        );
    }

    /**
     * The negative half of §7.5 rule 1 — asserted for completeness, but a
     * pass here proves nothing on its own (see class docblock): a file that
     * never implements the feature also never says the forbidden words.
     */
    #[Test]
    public function the_screen_never_claims_the_model_read_the_document_in_full(): void
    {
        $source = $this->sourceWithoutComments();

        $this->assertStringNotContainsStringIgnoringCase('read in full', $source);
        $this->assertStringNotContainsStringIgnoringCase('the model read', $source);
    }

    /**
     * Rejection-3 defects 1 and 2, the other two of the four named at the top
     * of this cycle — CONFIRMED FIXED here as a regression guard, not newly
     * discovered by this test. Both were read directly in source during this
     * gate: `lowTrustFields`/`truncation` are computed in `ExtractionCard`
     * and rendered as a badge-plus-sentence and a warning banner
     * respectively. This is the standing check that keeps them from quietly
     * reverting to "computed and shipped, never rendered" the way
     * `context_window`/`fitted` did.
     */
    /**
     * Isolates the `CompletenessBanner` function body so the assertions below
     * cannot be satisfied by text living elsewhere in the file (the module
     * docblock above the function discusses every one of these phrases in
     * prose while explaining the rule, which is exactly the false positive
     * `sourceWithoutComments()` exists to avoid).
     */
    private function completenessBannerBody(): string
    {
        $source = $this->sourceWithoutComments();

        $start = strpos($source, 'function CompletenessBanner(');
        $this->assertIsInt($start, 'function CompletenessBanner(...) not found in Show.jsx at all.');

        $end = strpos($source, 'function StatusChip(', $start);
        $this->assertIsInt($end, 'Could not bound the end of CompletenessBanner — StatusChip() not found after it.');

        return substr($source, $start, $end - $start);
    }

    #[Test]
    public function a_missing_context_window_renders_nothing_at_all(): void
    {
        // §7.5: an extraction with no `_meta.context_window` at all (an older
        // run, or a manually-entered one) is a DIFFERENT input from a
        // present-but-null `fitted`, and must be handled distinctly: nothing
        // measured means nothing said, not a "no data" claim rendered on
        // screen. This must be the FIRST thing the function does, before the
        // three `fitted`-branches are ever reached.
        $body = $this->completenessBannerBody();

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*contextWindow\s*\)\s*\{\s*[^}]*return\s+null\s*;/s',
            $body,
            'CompletenessBanner must return null immediately when contextWindow itself is absent — a document '
            .'with no recorded context-window meta must render nothing, not a null-fitted message.'
        );
    }

    #[Test]
    public function sent_whole_is_scoped_to_the_confirmed_branch_only(): void
    {
        // The existing regex check (`fitted === true` exists somewhere) does
        // not prove "sent whole" is CONFINED to that branch. A regression
        // that copied the phrase into the false/null branches would still
        // pass that check. Count it: §7.5 permits exactly one occurrence in
        // the whole component, and it must sit inside the `fitted === true`
        // block, not after it.
        $body = $this->completenessBannerBody();

        $occurrences = substr_count(strtolower($body), 'sent whole');
        $this->assertSame(
            1,
            $occurrences,
            '"sent whole" must appear exactly once in CompletenessBanner (the confirmed state only). Found '
            .$occurrences.' occurrence(s).'
        );

        $trueBranchStart = strpos($body, 'fitted === true');
        $sentWholeAt = stripos($body, 'sent whole');
        $falseBranchStart = strpos($body, 'fitted === false');

        $this->assertNotFalse($trueBranchStart);
        $this->assertNotFalse($sentWholeAt);
        $this->assertNotFalse($falseBranchStart);
        $this->assertTrue(
            $sentWholeAt > $trueBranchStart && $sentWholeAt < $falseBranchStart,
            '"sent whole" must fall between the `fitted === true` check and the `fitted === false` check — i.e. '
            .'inside the confirmed branch only.'
        );
    }

    #[Test]
    public function a_null_fitted_makes_no_completeness_claim_in_either_direction(): void
    {
        // §7.5 rule 3, and the sharpest edge of three-valued logic: `fitted
        // === null` means "we cannot tell", and the screen must say that —
        // not lean affirmative ("sent whole") and not lean negative (the
        // false-branch's "may have received less than we sent"). Scoped to
        // the text AFTER the `fitted === false` check so the false branch's
        // own wording cannot satisfy this by accident.
        $body = $this->completenessBannerBody();

        // Anchor on the false-branch's own closing phrase, not on `fitted
        // === false` itself — the condition check sits BEFORE the false
        // branch's body, so starting there would include the false branch's
        // own "may have received less" wording and make the assertion below
        // trivially unable to fail.
        $falseBranchEndsAt = strpos($body, 'may have received less than we sent.');
        $this->assertNotFalse($falseBranchEndsAt, 'fitted === false branch text not found.');

        $nullBranch = substr($body, $falseBranchEndsAt + strlen('may have received less than we sent.'));

        $this->assertStringNotContainsStringIgnoringCase(
            'sent whole',
            $nullBranch,
            'The null-fitted branch must not make the affirmative "sent whole" claim — nothing was confirmed.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'may have received less',
            $nullBranch,
            'The null-fitted branch must not repeat the false-branch\'s negative claim — a null fitted means '
            .'"cannot tell", not "probably did not fit".'
        );
        $this->assertStringContainsString(
            'nothing to check completeness against',
            $nullBranch,
            'The null-fitted branch must explicitly disclaim a completeness verdict rather than silently saying '
            .'nothing about it.'
        );
    }

    #[Test]
    public function low_trust_fields_and_document_truncated_are_still_rendered_not_only_computed(): void
    {
        $source = $this->sourceWithoutComments();

        $this->assertStringContainsString(
            'low_trust_fields',
            $source,
            'Rejection-3 defect 1: _meta.low_trust_fields must be read and rendered, not only computed and shipped.'
        );
        $this->assertStringContainsString(
            'known to get this field wrong',
            $source,
            'The low-trust badge must carry the reason, not just a styling difference — contract §7 / ADR 0015 §7(c).'
        );
        $this->assertStringContainsString(
            'document_truncated',
            $source,
            'Rejection-3 defect 2: _meta.document_truncated must be read and rendered, not only computed and shipped.'
        );
        $this->assertStringContainsString(
            'too long and was cut before reading',
            $source,
            'The truncation banner must state the fact in the confirmation screen, per ADR 0015 §6b.'
        );
    }
}
