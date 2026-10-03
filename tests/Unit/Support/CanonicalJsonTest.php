<?php

namespace Tests\Unit\Support;

use App\Enums\Tprm\RiskBand;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0025. A digest over a json column must be the same whether the engine
 * returned the text PHP stored (MariaDB) or its own re-serialisation (MySQL 8).
 *
 * PHPUnit's TestCase, not Laravel's: the canonicaliser touches no container,
 * and a test that boots the framework to prove that would still pass if it
 * stopped being true.
 */
class CanonicalJsonTest extends TestCase
{
    /**
     * What PHP's `array` cast writes at insert: default flags, so `/` becomes
     * `\/` and the accent becomes `\u00e9`, in insertion order.
     */
    private const PHP_VERBATIM = '{"trading_name":"Caf\u00e9 \/ Ltd","b":1,"a":[3,1,2],"n":null,"t":true,"f":75.9,"e":[],"o":{"z":1,"y":2},"d":"12.50"}';

    /** What MySQL 8 hands back for the same value. */
    private const MYSQL_NORMALISED = '{"a": [3, 1, 2], "b": 1, "d": "12.50", "e": [], "f": 75.9, "n": null, "o": {"y": 2, "z": 1}, "t": true, "trading_name": "Café / Ltd"}';

    #[Test]
    public function the_php_text_and_the_mysql_normalised_text_of_one_value_give_the_same_output(): void
    {
        $this->assertSame(
            CanonicalJson::fromColumn(self::PHP_VERBATIM),
            CanonicalJson::fromColumn(self::MYSQL_NORMALISED),
        );

        $this->assertSame(
            '{"a":[3,1,2],"b":1,"d":"12.50","e":[],"f":75.9,"n":null,"o":{"y":2,"z":1},"t":true,"trading_name":"Café / Ltd"}',
            CanonicalJson::fromColumn(self::MYSQL_NORMALISED),
        );
    }

    #[Test]
    public function the_array_path_equals_the_string_path_for_carbon_enum_and_a_whole_float(): void
    {
        $value = [
            'when' => CarbonImmutable::parse('2026-10-03 09:30:00', 'UTC'),
            'band' => RiskBand::cases()[0],
            'whole' => 1.0,
            'fraction' => 75.9,
            'nested' => ['z' => 1, 'a' => [2, 1]],
        ];

        // The `array` cast's own encoding is json_encode with flags 0.
        $castText = json_encode($value, 0);

        $this->assertSame(CanonicalJson::fromColumn($castText), CanonicalJson::fromColumn($value));
        $this->assertNotNull(CanonicalJson::fromColumn($value));
    }

    #[Test]
    public function a_null_column_is_null_not_an_empty_string(): void
    {
        $this->assertNull(CanonicalJson::fromColumn(null));
    }

    #[Test]
    public function the_three_orderings_of_mixed_numeric_and_string_keys_give_one_output(): void
    {
        // json_decode turns "10" and "9" into int keys; the default ksort flag
        // over int and non-numeric string keys is not transitive, so the
        // result depended on the input order. Removing SORT_STRING from
        // CanonicalJson::normalise() makes this fail.
        $orderings = [
            '{"10":1,"1a":2,"9":3}',
            '{"1a":2,"9":3,"10":1}',
            '{"9":3,"10":1,"1a":2}',
        ];

        $outputs = array_unique(array_map(CanonicalJson::fromColumn(...), $orderings));

        $this->assertCount(1, $outputs, 'Key order in the input changed the canonical output.');
        $this->assertSame('{"10":1,"1a":2,"9":3}', array_values($outputs)[0]);
    }

    /** @return array<string, array{string, string}> */
    public static function equivalences(): array
    {
        return [
            'an empty object and an empty list' => ['{}', '[]'],
            'an object keyed 0..n and the list' => ['{"0":"a","1":"b"}', '["a","b"]'],
            'key order' => ['{"b":1,"a":2}', '{"a":2,"b":1}'],
            'whitespace' => ['{"a":1,"b":[1,2]}', "{ \"a\" : 1,\n \"b\" : [ 1 , 2 ] }"],
            'an escaped slash' => ['{"a":"x\/y"}', '{"a":"x/y"}'],
            'an escaped and a literal accent' => ['{"a":"caf\\u00e9"}', '{"a":"café"}'],
            'four spellings of 100, first and second' => ['{"a":1e2}', '{"a":1.0e2}'],
            'four spellings of 100, second and third' => ['{"a":1.0e2}', '{"a":100.0}'],
            'four spellings of 100, third and fourth' => ['{"a":100.0}', '{"a":100.00}'],
            'digits beyond double precision' => ['{"a":0.1}', '{"a":0.1000000000000000055511}'],
            'two integers above PHP_INT_MAX' => ['{"a":18446744073709551616}', '{"a":18446744073709551617}'],
            'nested key order' => ['{"o":{"z":1,"y":{"q":1,"p":2}}}', '{"o":{"y":{"p":2,"q":1},"z":1}}'],
        ];
    }

    #[Test]
    #[DataProvider('equivalences')]
    public function each_deliberate_equivalence_holds(string $left, string $right): void
    {
        $this->assertSame(CanonicalJson::fromColumn($left), CanonicalJson::fromColumn($right));
    }

    /** @return array<string, array{string, string}> */
    public static function realChanges(): array
    {
        return [
            'true against 1' => ['{"a":true}', '{"a":1}'],
            '1 against "1"' => ['{"a":1}', '{"a":"1"}'],
            '1 against 1.0' => ['{"a":1}', '{"a":1.0}'],
            'null against ""' => ['{"a":null}', '{"a":""}'],
            '1e2 against 100' => ['{"a":1e2}', '{"a":100}'],
            '72.5 against "72.5"' => ['{"a":72.5}', '{"a":"72.5"}'],
            'false against 0' => ['{"a":false}', '{"a":0}'],
            'the order of a list' => ['{"a":[3,1,2]}', '{"a":[1,2,3]}'],
            'a changed value' => ['{"a":"x"}', '{"a":"y"}'],
            'a renamed key' => ['{"a":1}', '{"b":1}'],
            'a list inside a list, re-ordered' => ['{"a":[[1,2],[3]]}', '{"a":[[3],[1,2]]}'],
        ];
    }

    #[Test]
    #[DataProvider('realChanges')]
    public function each_real_change_is_detected(string $left, string $right): void
    {
        $this->assertNotSame(CanonicalJson::fromColumn($left), CanonicalJson::fromColumn($right));
    }

    #[Test]
    public function the_output_is_stable_under_a_different_serialize_precision(): void
    {
        $text = '{"f":0.1,"g":75.9,"h":[0.30000000000000004]}';
        $baseline = CanonicalJson::fromColumn($text);

        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $this->assertSame($baseline, CanonicalJson::fromColumn($text));
            $this->assertSame('{"f":0.1,"g":75.9,"h":[0.30000000000000004]}', $baseline);
            $this->assertSame('17', ini_get('serialize_precision'), 'The pin must be restored, not left at -1.');
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    #[Test]
    public function a_value_that_cannot_be_encoded_throws_and_does_not_return_an_empty_string(): void
    {
        $this->expectException(\JsonException::class);

        CanonicalJson::encode(['bad' => "\xB1\x31"]);
    }

    #[Test]
    public function an_unencodable_float_throws(): void
    {
        $this->expectException(\JsonException::class);

        CanonicalJson::encode(['nan' => NAN]);
    }

    #[Test]
    public function malformed_column_text_throws(): void
    {
        $this->expectException(\JsonException::class);

        CanonicalJson::fromColumn('{not json');
    }

    #[Test]
    public function the_pin_is_restored_after_a_failed_encode(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            try {
                CanonicalJson::encode(NAN);
                $this->fail('NAN was encoded.');
            } catch (\JsonException) {
                $this->assertSame('17', ini_get('serialize_precision'), 'The pin leaked after a failed encode.');
            }
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    #[Test]
    public function a_json_null_literal_is_the_text_null_and_not_a_sql_null(): void
    {
        $this->assertSame('null', CanonicalJson::fromColumn('null'));
        $this->assertNotSame(CanonicalJson::fromColumn(null), CanonicalJson::fromColumn('null'));
    }

    #[Test]
    public function a_list_keeps_its_order_and_scalars_pass_through(): void
    {
        $this->assertSame([3, 1, 2], CanonicalJson::normalise([3, 1, 2]));
        $this->assertSame(['a' => 1, 'b' => 2], CanonicalJson::normalise(['b' => 2, 'a' => 1]));
        $this->assertSame('x', CanonicalJson::normalise('x'));
        $this->assertNull(CanonicalJson::normalise(null));
    }
}
