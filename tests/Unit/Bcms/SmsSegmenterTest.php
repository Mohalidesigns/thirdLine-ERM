<?php

namespace Tests\Unit\Bcms;

use App\Services\Bcms\Notification\Channels\SmsSegmenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Segment counting and truncation touch no container, config or database —
 * extends PHPUnit's TestCase directly, same reasoning as
 * `Tests\Unit\Tprm\RuleEvaluatorTest`.
 *
 * THE DEFECT: `truncate()` used to end every cut message with `…` (U+2026),
 * one character outside the GSM 03.38 alphabet. Appending it to an
 * otherwise-GSM-7 body silently moved the WHOLE message to UCS-2 — 70
 * characters and 67-per-segment instead of 160 and 153 — so a body truncated
 * to "fit two segments" could actually go out as five, at five times the
 * cost, for a character nobody would ever notice was there.
 */
class SmsSegmenterTest extends TestCase
{
    #[Test]
    public function a_gsm7_body_stays_gsm7_after_truncation(): void
    {
        $body = str_repeat('Evacuate the building now and go to the assembly point immediately. ', 5);

        $this->assertTrue(SmsSegmenter::isGsm7($body), 'Sanity: the body is plain GSM-7 before truncation.');
        $this->assertGreaterThan(2, SmsSegmenter::segments($body), 'Sanity: it does not already fit two segments.');

        $truncated = SmsSegmenter::truncate($body, 2);

        $this->assertTrue(
            SmsSegmenter::isGsm7($truncated),
            'A GSM-7 body must still be GSM-7 after truncation — the defect this fix closes.'
        );
        $this->assertStringNotContainsString('…', $truncated, 'The GSM-7-unsafe ellipsis must never appear.');
        $this->assertStringEndsWith('...', $truncated);
        $this->assertLessThanOrEqual(
            2,
            SmsSegmenter::segments($truncated),
            'The truncated body must actually fit inside the segment budget it was cut for.'
        );
    }

    #[Test]
    public function a_ucs2_body_stays_ucs2_and_correctly_counted_after_truncation(): void
    {
        // A single Naira sign is enough to push the whole body to UCS-2.
        $body = str_repeat('Naira ₦ rates have changed for every branch today, please review immediately. ', 4);

        $this->assertFalse(SmsSegmenter::isGsm7($body), 'Sanity: the ₦ sign forces UCS-2.');

        $truncated = SmsSegmenter::truncate($body, 2);

        $this->assertFalse(SmsSegmenter::isGsm7($truncated), 'A body that was already UCS-2 stays UCS-2.');
        $this->assertStringEndsWith('…', $truncated, 'UCS-2 bodies keep the single-character ellipsis.');
        $this->assertLessThanOrEqual(2, SmsSegmenter::segments($truncated));
    }

    #[Test]
    public function segment_counts_are_correct_before_and_after_truncation(): void
    {
        $short = 'Evacuate now.';
        $this->assertSame(1, SmsSegmenter::segments($short));
        $this->assertSame($short, SmsSegmenter::truncate($short, 1), 'A body that already fits is never rewritten.');

        $long = str_repeat('Evacuate the building now and proceed to the assembly point. ', 6);
        $before = SmsSegmenter::segments($long);
        $this->assertGreaterThan(1, $before);

        $after = SmsSegmenter::segments(SmsSegmenter::truncate($long, 1));
        $this->assertSame(1, $after, 'Recounted against the truncated text, not assumed from the original.');
    }

    /**
     * ITEM 8, CODE REVIEW BLOCKING DEFECT, PERMANENT REGRESSION TEST.
     * `truncate()` used to cut by CHARACTER count (`mb_substr($body, 0,
     * $limit)`) even though the budget (`GSM_SINGLE`/`GSM_CONCAT`) is a UNIT
     * ceiling, and a GSM-7 extended character (`^{}\[~]|€`) costs 2 units.
     * A body made mostly of extended characters could fit its full
     * character allowance and still land at roughly double the real unit
     * budget — a "truncated to one segment" message that was still two or
     * three.
     */
    #[Test]
    public function truncation_budgets_in_units_not_characters_for_extended_characters(): void
    {
        // Every character here is GSM-7 EXTENDED (2 units each) — the full
        // set `GSM_EXTENDED` names: € [ ] { } ~ | ^ \. 108 characters, but
        // 216 units — already 56 over a single segment's 160-unit budget.
        $extended = str_repeat('€{}[]~|^\\', 12);

        $this->assertTrue(SmsSegmenter::isGsm7($extended), 'Sanity: extended characters are still GSM-7.');
        $this->assertSame(
            mb_strlen($extended) * 2,
            SmsSegmenter::units($extended),
            'Sanity: every character in this body costs 2 units.',
        );
        $this->assertGreaterThan(SmsSegmenter::GSM_SINGLE, SmsSegmenter::units($extended));

        $truncated = SmsSegmenter::truncate($extended, 1);

        $this->assertLessThanOrEqual(
            SmsSegmenter::GSM_SINGLE,
            SmsSegmenter::units($truncated),
            'The cut must fit the budget in UNITS. A character-count cut would keep up to 157 '
            .'characters (160 minus the 3-character marker) regardless of their unit cost — 314 '
            .'units here, almost double the real budget.',
        );
        $this->assertSame(1, SmsSegmenter::segments($truncated));

        // The character-based cut this replaces would have kept 157
        // characters of body; the unit-based cut keeps roughly half that,
        // because each one costs 2.
        $this->assertLessThan(157, mb_strlen($truncated));
    }

    #[Test]
    public function truncation_never_cuts_mid_word_when_a_boundary_exists(): void
    {
        $body = str_repeat('word ', 60).'finalword';

        $truncated = SmsSegmenter::truncate($body, 1);

        $this->assertStringEndsWith('...', $truncated);
        $this->assertStringNotContainsString('wor...', $truncated, 'Must cut on a space, not mid-word.');
    }
}
