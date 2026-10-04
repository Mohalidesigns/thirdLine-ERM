<?php

namespace App\Services\Bcms\Notification\Channels;

/**
 * How many billable SMS one body actually is, and where to cut it.
 *
 * THE GSM-7 / UCS-2 SPLIT IS THE WHOLE POINT. A message of plain Latin text
 * fits 160 characters per segment; one containing a single character outside
 * the GSM 03.38 alphabet — a curly apostrophe pasted from Word, a Naira sign,
 * an accented name, an emoji — drops the whole message to UCS-2 and 70
 * characters. An operator who types a perfectly reasonable 150-character alert
 * with one smart quote in it sends three segments instead of one and pays three
 * times, and nobody can see why.
 *
 * TRUNCATION IS ON A WORD BOUNDARY, NEVER MID-WORD (criterion 10). "Assemble at
 * the car pa" is worse than a message one word shorter.
 */
final class SmsSegmenter
{
    /** GSM 03.38, including the extension characters that cost two units. */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** These occupy two GSM units each. */
    private const GSM_EXTENDED = '^{}\\[~]|€';

    public const GSM_SINGLE = 160;

    public const GSM_CONCAT = 153;

    public const UCS2_SINGLE = 70;

    public const UCS2_CONCAT = 67;

    /**
     * A NARROW, NAMED PUNCTUATION MAP — em/en dash, curly quotes, the
     * ellipsis character, and a non-breaking space — to their GSM-7
     * equivalents. Every one of these is typography a word processor or a
     * pasted site name introduces without anyone intending an SMS
     * consequence: `TemplateRenderer::siteNameFromAudienceRule()` derives
     * a site's name verbatim from `bcms_sites.name`, and a name like
     * "Kano Heritage Bank — Head Office, Kano" (one em dash) was dropping
     * an otherwise-plain-ASCII `EVACUATE` SMS from GSM-7 to UCS-2 — 160
     * characters and one segment becoming 70 and three, silently, for a
     * character nobody chose for this purpose.
     *
     * DELIBERATELY NOT A GENERAL TRANSLITERATOR. Genuinely non-GSM-7 text
     * — Hausa diacritics, an actual emoji — stays exactly what it is and
     * correctly prices as UCS-2; only this named, closed set of
     * "typographic punctuation with an ASCII equivalent nobody would miss"
     * is mapped. `nonGsmCharacters()` and `isGsm7()` are NOT changed to
     * apply this map: the template-authoring preview (`inspect()`) must
     * keep showing an author the exact character that cost them, which
     * this map would otherwise hide from the one screen whose job is to
     * show it.
     *
     * @var array<string, string>
     */
    private const GSM7_TRANSLITERATIONS = [
        "\u{2014}" => '-',  // em dash —
        "\u{2013}" => '-',  // en dash –
        "\u{2018}" => "'",  // left single quotation mark '
        "\u{2019}" => "'",  // right single quotation mark '
        "\u{201C}" => '"',  // left double quotation mark "
        "\u{201D}" => '"',  // right double quotation mark "
        "\u{2026}" => '...', // horizontal ellipsis …
        "\u{00A0}" => ' ',  // non-breaking space
    ];

    /**
     * Applied to the WIRE body only — the text about to be shaped for SMS
     * or USSD (`TemplateRenderer::shapeForChannel()`) — and BEFORE
     * segmenting, never to what is stored. A body already GSM-7 is
     * returned unchanged; one that becomes GSM-7 only after this map is
     * applied now segments and prices as GSM-7 correctly. One that is
     * still not GSM-7 afterwards (genuine non-Latin text) is untouched by
     * this method — the split correctly stays UCS-2 for it.
     */
    public static function transliterateForGsm7(string $body): string
    {
        return strtr($body, self::GSM7_TRANSLITERATIONS);
    }

    public static function isGsm7(string $body): bool
    {
        foreach (mb_str_split($body) as $char) {
            if (! str_contains(self::GSM_BASIC, $char) && ! str_contains(self::GSM_EXTENDED, $char)) {
                return false;
            }
        }

        return true;
    }

    /** Billable units: GSM extension characters count twice. */
    public static function units(string $body): int
    {
        if (! self::isGsm7($body)) {
            return mb_strlen($body);
        }

        $units = 0;

        foreach (mb_str_split($body) as $char) {
            $units += str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;
        }

        return $units;
    }

    public static function segments(string $body): int
    {
        if ($body === '') {
            return 0;
        }

        $units = self::units($body);
        $gsm = self::isGsm7($body);

        $single = $gsm ? self::GSM_SINGLE : self::UCS2_SINGLE;
        $concat = $gsm ? self::GSM_CONCAT : self::UCS2_CONCAT;

        return $units <= $single ? 1 : (int) ceil($units / $concat);
    }

    /**
     * The characters that pushed a message out of GSM-7, so a template author
     * can see what a single pasted apostrophe cost them.
     *
     * @return list<string>
     */
    public static function nonGsmCharacters(string $body): array
    {
        $found = [];

        foreach (mb_str_split($body) as $char) {
            if (! str_contains(self::GSM_BASIC, $char) && ! str_contains(self::GSM_EXTENDED, $char)) {
                $found[$char] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The GSM-7-safe truncation marker. Three plain stops, not `…` (U+2026):
     * the single curly character is outside the GSM 03.38 alphabet, so
     * appending it to an otherwise-GSM-7 body was dropping the WHOLE message
     * to UCS-2 — a two-segment SMS going out as five, for one character that
     * was never actually sent to fit inside the segment count it was
     * computed against. A UCS-2 body was already outside GSM-7 before
     * truncation and stays that way; only the GSM-7 case needed the swap.
     */
    private const GSM7_MARKER = '...';

    private const UCS2_MARKER = '…';

    /**
     * Cut to fit `$segments` segments, on a word boundary, with a marker safe
     * for the body's own alphabet.
     *
     * Returns the body unchanged when it already fits — an alert that fits is
     * never rewritten, because a template author's exact words are what was
     * approved.
     *
     * THE BUDGET IS IN UNITS, NOT CHARACTERS. `GSM_SINGLE`/`GSM_CONCAT` are
     * unit ceilings — `units()` already weighs a GSM-7 extended character
     * (`^{}\[~]|€`) as 2 — and cutting by `mb_substr($body, 0, $limit)`
     * (a CHARACTER count) silently mismatched that the moment an extended
     * character was in play: a body with, say, six `€` signs among its
     * first 160 characters is actually 166 units, over budget, but a
     * character-counted cut kept all six anyway. The recount below
     * (`cutToUnitBudget()`) walks the body accumulating the SAME per-character
     * cost `units()` uses, so what is cut is measured the same way the
     * budget itself is.
     */
    public static function truncate(string $body, int $segments = 1): string
    {
        if (self::segments($body) <= $segments) {
            return $body;
        }

        $gsm = self::isGsm7($body);
        $marker = $gsm ? self::GSM7_MARKER : self::UCS2_MARKER;

        $limit = $segments === 1
            ? ($gsm ? self::GSM_SINGLE : self::UCS2_SINGLE)
            : $segments * ($gsm ? self::GSM_CONCAT : self::UCS2_CONCAT);

        $budget = $limit - self::units($marker); // room for the marker, in the body's own units

        $cut = self::cutToUnitBudget($body, $budget, $gsm);

        $lastSpace = mb_strrpos($cut, ' ');

        // A single word longer than a whole segment has no boundary to cut on.
        // Cutting mid-word is then the only option and is better than sending
        // nothing, but it is the exception rather than the rule. Compared
        // against the CUT STRING'S OWN character length, not the unit
        // budget — the two can differ once extended characters are in play.
        if ($lastSpace !== false && $lastSpace > mb_strlen($cut) * 0.5) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        // THE SEGMENT COUNT IS WHATEVER `segments()` SAYS ABOUT THE TEXT
        // ACTUALLY RETURNED, never inferred from the pre-truncation body. A
        // caller asking "how many segments is this" after truncation (the
        // console's live preview, `inspect()`'s `sms_preview`) must call
        // `segments()` again on THIS string — the marker is chosen
        // (`GSM7_MARKER`/`UCS2_MARKER`) so that doing so agrees with the
        // budget truncation was computed against, instead of the previous
        // `…` silently moving a GSM-7 body to UCS-2 and turning a two-segment
        // truncation into five, and the unit-weighted cut below is what
        // makes that agreement hold even when extended characters are
        // involved.
        return rtrim($cut, " \t\n\r,.;:-").$marker;
    }

    /**
     * The longest PREFIX of `$body` whose own `units()` cost is at most
     * `$unitBudget` — a plain character cut for a UCS-2 body (1 unit per
     * character, always), a running unit tally for a GSM-7 one, since an
     * extended character there costs 2.
     */
    private static function cutToUnitBudget(string $body, int $unitBudget, bool $gsm): string
    {
        if (! $gsm) {
            return mb_substr($body, 0, max(0, $unitBudget));
        }

        $kept = '';
        $used = 0;

        foreach (mb_str_split($body) as $char) {
            $cost = str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;

            if ($used + $cost > $unitBudget) {
                break;
            }

            $kept .= $char;
            $used += $cost;
        }

        return $kept;
    }
}
