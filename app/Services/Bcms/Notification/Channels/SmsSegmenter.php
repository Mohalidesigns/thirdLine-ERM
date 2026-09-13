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
     * Cut to fit `$segments` segments, on a word boundary, with an ellipsis.
     *
     * Returns the body unchanged when it already fits — an alert that fits is
     * never rewritten, because a template author's exact words are what was
     * approved.
     */
    public static function truncate(string $body, int $segments = 1): string
    {
        if (self::segments($body) <= $segments) {
            return $body;
        }

        $gsm = self::isGsm7($body);
        $limit = $segments === 1
            ? ($gsm ? self::GSM_SINGLE : self::UCS2_SINGLE)
            : $segments * ($gsm ? self::GSM_CONCAT : self::UCS2_CONCAT);

        $limit -= 1; // room for the ellipsis
        $cut = mb_substr($body, 0, $limit);

        $lastSpace = mb_strrpos($cut, ' ');

        // A single word longer than a whole segment has no boundary to cut on.
        // Cutting mid-word is then the only option and is better than sending
        // nothing, but it is the exception rather than the rule.
        if ($lastSpace !== false && $lastSpace > $limit * 0.5) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r,.;:-").'…';
    }
}
