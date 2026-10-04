<?php

namespace Tests\Support;

/**
 * Compare a decoded JSON value without depending on the order of object keys.
 *
 * MySQL 8 stores a native `json` column in its binary format, which keeps an
 * object's keys sorted by length and then bytewise, so what comes back is not
 * the order that went in. MariaDB 10.4 stores JSON as text and returns it
 * verbatim, which is why a bare assertSame() on an associative array passes
 * there and fails on the engine production runs.
 *
 * Only OBJECT key order is ignored. A list keeps its order — band 0 must still
 * be the first band — and values are still compared strictly, so '1' is not 1.
 * Use it only where no production code reads the keys in stored order.
 */
trait AssertsCanonicalJson
{
    /**
     * @param  mixed  $expected
     * @param  mixed  $actual
     */
    protected function assertSameJson($expected, $actual, string $message = ''): void
    {
        $this->assertSame(self::canonicaliseJson($expected), self::canonicaliseJson($actual), $message);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function canonicaliseJson($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'canonicaliseJson'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
