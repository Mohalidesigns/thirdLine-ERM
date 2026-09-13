<?php

namespace Tests\Unit\Tprm\Evidence;

use App\Services\Tprm\Evidence\CitationVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one defence against a confident invention (TRD §12).
 *
 * These tests are the specification of exactly how forgiving the verifier is,
 * because both failure directions are damaging and they pull in opposite
 * directions. Too strict and every true citation from a PDF is rejected, the
 * feature is useless, and someone loosens it until it passes. Too loose and a
 * fabricated field verifies against text that does not say it.
 */
class CitationVerifierTest extends TestCase
{
    private CitationVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new CitationVerifier;
    }

    #[Test]
    public function a_quote_present_in_the_document_verifies(): void
    {
        $result = $this->verifier->verify(
            [['field' => 'service_auditor', 'quote' => 'Ernst & Young LLP', 'page' => 3]],
            'Report of Independent Service Auditor. Ernst & Young LLP was engaged to report.'
        );

        $this->assertTrue($result->isTrustworthy());
        $this->assertCount(1, $result->verified);
        $this->assertSame(3, $result->verified[0]['page']);
    }

    #[Test]
    public function a_quote_absent_from_the_document_is_rejected(): void
    {
        // The failure this class exists for: a plausible auditor for a report
        // that names a different one.
        $result = $this->verifier->verify(
            [['field' => 'service_auditor', 'quote' => 'Deloitte & Touche LLP', 'page' => 3]],
            'Report of Independent Service Auditor. Ernst & Young LLP was engaged to report.'
        );

        $this->assertFalse($result->isTrustworthy());
        $this->assertSame(['service_auditor'], $result->rejectedFields());
    }

    #[Test]
    public function pdf_line_breaks_and_double_spaces_do_not_defeat_a_true_citation(): void
    {
        $document = "The description of the system  covers the period\n1 January 2025 to\n31 December 2025.";

        $result = $this->verifier->verify(
            [['field' => 'period', 'quote' => 'covers the period 1 January 2025 to 31 December 2025']],
            $document
        );

        $this->assertTrue($result->isTrustworthy());
    }

    #[Test]
    public function typographic_characters_and_case_are_normalised(): void
    {
        $document = "The service organisation\u{2019}s controls \u{2013} as described \u{2013} operated effectively.";

        $result = $this->verifier->verify(
            [['field' => 'opinion', 'quote' => "SERVICE ORGANISATION'S CONTROLS - AS DESCRIBED"]],
            $document
        );

        $this->assertTrue($result->isTrustworthy());
    }

    #[Test]
    public function a_negation_does_not_verify_the_positive_claim(): void
    {
        // The precise reason normalisation stops at whitespace and case. If it
        // stripped punctuation or short words, this would pass, and the module
        // would report a control as operating on the strength of a sentence
        // saying it does not.
        $result = $this->verifier->verify(
            [['field' => 'policy', 'quote' => 'we maintain an information security policy']],
            'We do not maintain an information security policy.'
        );

        $this->assertFalse($result->isTrustworthy());
    }

    #[Test]
    public function a_citation_with_a_page_but_no_quote_is_rejected(): void
    {
        // "The model gave a page number but no text" is exactly the shape a
        // fabrication takes, so it fails rather than passing unchecked.
        $result = $this->verifier->verify(
            [['field' => 'opinion_type', 'page' => 2]],
            'Any document text at all.'
        );

        $this->assertFalse($result->isTrustworthy());
        $this->assertStringContainsString('quotes no text', $result->rejected[0]['reason']);
    }

    #[Test]
    public function one_bad_citation_condemns_the_whole_extraction(): void
    {
        $result = $this->verifier->verify([
            ['field' => 'auditor', 'quote' => 'Ernst & Young LLP'],
            ['field' => 'opinion', 'quote' => 'no exceptions were noted'],
        ], 'Ernst & Young LLP. Exceptions were noted in Section 4.');

        $this->assertCount(1, $result->verified);
        $this->assertFalse($result->isTrustworthy(), 'A verified citation beside a rejected one is not trust.');
    }

    #[Test]
    public function a_failed_verification_caps_confidence_in_the_low_band(): void
    {
        $trusted = $this->verifier->verify(
            [['field' => 'a', 'quote' => 'present']],
            'the word present appears here'
        );
        $untrusted = $this->verifier->verify(
            [['field' => 'a', 'quote' => 'absent']],
            'the word present appears here'
        );

        $this->assertSame(0.95, $trusted->adjustedConfidence(0.95));
        // Capped, not scaled: a high-confidence fabrication must not arrive
        // above the human-confirmation threshold.
        $this->assertSame(0.3, $untrusted->adjustedConfidence(0.95));
        // And a low reported confidence is not RAISED to the cap.
        $this->assertSame(0.1, $untrusted->adjustedConfidence(0.1));
    }

    #[Test]
    public function no_citations_at_all_is_trustworthy_and_that_is_the_callers_problem(): void
    {
        // Nothing was checked, so nothing failed. Whether an extraction is
        // ALLOWED to return no citations is a policy the dispatcher enforces;
        // this class only reports what the check found.
        $result = $this->verifier->verify([], 'Document text.');

        $this->assertTrue($result->isTrustworthy());
        $this->assertSame(0, $result->toArray()['verified_count']);
    }
}
