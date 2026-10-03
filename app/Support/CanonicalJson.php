<?php

namespace App\Support;

/**
 * One canonical text for a JSON value, so a digest over it is the same on every
 * engine, every PHP and every key order. See ADR 0025.
 *
 * THE PRINCIPLE: decode first, then hash the value, on both paths. MariaDB
 * returns a `json` column as the text that was stored; MySQL 8 returns native
 * JSON re-serialised (keys re-ordered, `": "` spacing, slashes and unicode
 * unescaped). A digest over the column's text therefore matches on one and
 * reports every honest row as tampered on the other. Decoding to a PHP value
 * and running it through one encoder with fixed flags removes the engine from
 * the arithmetic.
 *
 * KEY ORDER USES `SORT_STRING`, NOT THE DEFAULT. `json_decode` turns numeric
 * keys into ints, and the default `SORT_REGULAR` comparison over a mix of
 * ints and strings (`10`, `"1a"`, `9`) is not transitive: on PHP 8.4 the three
 * input orders produced three different "sorted" outputs. MySQL's key order is
 * not PHP's, so the default would bring back the very dependence on input
 * order this class removes. `SORT_STRING` compares bytes and is stable.
 *
 * Deliberate equivalences (the digest covers what the application can read):
 * `{}` and `[]`; `{"0":"a","1":"b"}` and `["a","b"]`; key order, whitespace and
 * escaping. Real changes stay distinct: `true` vs `1`, `1` vs `"1"`, `1` vs
 * `1.0`, `null` vs `""`, and the order of a list.
 *
 * LIMIT: a digest over a MySQL 8 `json` column is stable only for values with no
 * non-integral or out-of-range doubles. MySQL 8.0.46's own number parser reads
 * some doubles back as a neighbouring value (`9.018867924528301` returns as
 * `9.0188679245283`), and no canonicaliser can undo that after the insert. The
 * WRITER must quote or quantise floats before storing; this class does not.
 * See ADR 0025 section 2a and `App\Models\Tprm\AuditLog::quoteFloats()`.
 */
final class CanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * Sort every associative array's keys, recursively. A list keeps its order.
     */
    public static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalise($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * The canonical text of a PHP value.
     *
     * @throws \JsonException when the value cannot be encoded; never `''`.
     */
    public static function encode(mixed $value): string
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return json_encode(self::normalise($value), self::FLAGS);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    /**
     * The canonical text of what a `json` column holds, however it arrived.
     *
     * A string is what the database returned (or what the `array` cast encoded
     * at insert). An array is a value not yet cast, which is first put through
     * the cast's own encoding so both paths decode the same thing.
     *
     * @throws \JsonException on malformed JSON or an unencodable value.
     */
    public static function fromColumn(string|array|null $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            $raw = json_encode($raw, JSON_THROW_ON_ERROR);
        }

        return self::encode(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }
}
