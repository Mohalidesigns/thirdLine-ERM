<?php

namespace Tests\Feature\Bcms;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gate 2 rejection (second pass): the same defect class as criterion 10
 * reaching one screen further than the PHP fix did. No JavaScript executes
 * in this suite, so — in `RenderKeyDiscardAllowlistTest`'s shape — these are
 * blunt, mechanical greps over the shipped JSX rather than a rendered
 * assertion.
 *
 *   (a) `TreeHealthService::dataConfidence()` computes `consent_not_requested`
 *       exactly so a contact nobody has asked is visible on the dashboard
 *       panel before an incident, not only in the API response
 *       `Phase6ScreensTest` already asserts. A computed figure the screen
 *       never prints is invisible to the person the panel exists for.
 *   (b) `CallTreeController::candidates()` and `CallTreeService::nodeHealth()`
 *       both now carry `consent_reason`, so the designer can tell a
 *       withdrawal from a contact nobody has asked. A field the screen never
 *       reads is dead weight in the payload, not a fix.
 */
class CallTreeConsentJsxReachTest extends TestCase
{
    #[Test]
    public function the_dashboard_renders_the_no_consent_on_record_count(): void
    {
        $source = $this->stripJsComments((string) file_get_contents(
            base_path('resources/js/Pages/Bcms/CallTrees/Index.jsx')
        ));

        $this->assertStringContainsString(
            'data_confidence.consent_not_requested',
            $source,
            '`TreeHealthService::dataConfidence()` computes consent_not_requested precisely so this '
            .'exclusion is visible on the dashboard before an incident, and the screen never reads it.'
        );
    }

    #[Test]
    public function the_designer_reads_the_consent_reason_rather_than_only_the_boolean(): void
    {
        $source = $this->stripJsComments((string) file_get_contents(
            base_path('resources/js/Pages/Bcms/CallTrees/Designer.jsx')
        ));

        $this->assertStringContainsString(
            'consent_reason',
            $source,
            'The node detail panel must distinguish a withdrawal (respect it) from a contact nobody has '
            .'asked (capture consent) rather than rendering one literal sentence for every state that '
            .'blocks a personal channel.'
        );
    }

    #[Test]
    public function the_results_screen_does_not_claim_every_consent_exclusion_was_a_withdrawal(): void
    {
        $source = $this->stripJsComments((string) file_get_contents(
            base_path('resources/js/Pages/Bcms/CallTrees/Results.jsx')
        ));

        $this->assertStringNotContainsStringIgnoringCase(
            'consent withdrawn',
            $source,
            '`scorecard.consent_excluded` is every `CascadeOutcome::ConsentBlocked` row — contacts never '
            .'asked or awaiting an answer, not only withdrawals. This screen\'s scorecard is persisted and '
            .'feeds the ISO 22301 clause 8.5 exercise record, so a false "withdrawn" claim becomes evidence. '
            .'`Index.jsx` and `Designer.jsx` use the phrase legitimately; this assertion is scoped to '
            .'`Results.jsx` only.'
        );
    }

    private function stripJsComments(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
        $source = preg_replace('#//[^\n]*#', '', $source) ?? $source;

        return $source;
    }
}
