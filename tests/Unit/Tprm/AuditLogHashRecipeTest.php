<?php

namespace Tests\Unit\Tprm;

use App\Models\Tprm\AuditLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The recipe pin (ADR 0025 section 4, test 5).
 *
 * If this fails, the recipe, the encoder flags or HASHED_FIELDS changed. Every
 * stored hash in every environment now disagrees with what the code computes.
 * That is not a thing to fix by pasting the new digest here: a recipe change is
 * a new HASH_RECIPE tag and the in-chain cutover row described in ADR 0025
 * section 3, and a re-seal is forbidden.
 */
class AuditLogHashRecipeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return [
            'organization_id' => 7,
            'auditable_type' => 'App\\Models\\Tprm\\ThirdParty',
            'auditable_id' => 42,
            'event' => 'updated',
            'actor_type' => 'user',
            'actor_id' => 3,
            'actor_label' => 'Amina Bello',
            'before' => '{"trading_name":"Old \/ Name","tier":1}',
            'after' => '{"tier":2,"trading_name":"Café \/ Ltd","tags":["b","a"]}',
            'ip' => '10.0.0.1',
            'user_agent' => 'phpunit',
            'correlation_id' => 'corr-1',
            'created_at' => '2026-10-03 09:30:00',
        ];
    }

    #[Test]
    public function the_recipe_digest_of_a_fixed_row_is_a_literal(): void
    {
        $this->assertSame('tp_audit_logs/v2', AuditLog::HASH_RECIPE);

        $this->assertSame(
            'cdbffa5db934d34aede45872783f9e25da7323eee9fbc68692ccb1816efb3688',
            AuditLog::chainHash($this->fixture(), str_repeat('ab', 32)),
            'The audit-chain recipe changed. Read ADR 0025 before touching this literal.',
        );
    }

    #[Test]
    public function the_first_row_of_a_chain_hashes_an_empty_previous_hash(): void
    {
        $this->assertSame(
            AuditLog::chainHash($this->fixture(), null),
            AuditLog::chainHash($this->fixture(), null),
        );
        $this->assertNotSame(
            AuditLog::chainHash($this->fixture(), null),
            AuditLog::chainHash($this->fixture(), str_repeat('ab', 32)),
        );
    }

    #[Test]
    public function the_text_form_of_the_payload_does_not_change_the_digest(): void
    {
        $normalised = $this->fixture();
        $normalised['before'] = '{"tier": 1, "trading_name": "Old / Name"}';
        $normalised['after'] = '{"tags": ["b", "a"], "tier": 2, "trading_name": "Café / Ltd"}';

        $this->assertSame(
            AuditLog::chainHash($this->fixture(), 'x'),
            AuditLog::chainHash($normalised, 'x'),
        );
    }

    #[Test]
    public function a_quoted_float_and_an_unquoted_float_hash_differently(): void
    {
        // Pins WHERE the float rule lives (ADR 0025 section 2a): in the
        // `creating` hook, not in chainHash(). If chainHash() quoted floats, a
        // raw edit of "72.5" to 72.5 would verify.
        $number = $this->fixture();
        $number['after'] = '{"v":72.5}';
        $string = $this->fixture();
        $string['after'] = '{"v":"72.5"}';

        $this->assertNotSame(
            AuditLog::chainHash($number, 'x'),
            AuditLog::chainHash($string, 'x'),
        );
    }

    /** @return array<string, array{float, string}> */
    public static function floats(): array
    {
        return [
            'an unrounded ratio' => [478 / 53, '9.018867924528301'],
            'a large exponent' => [1.0e25, '1.0e+25'],
            'a sum that (string) would round' => [0.1 + 0.2, '0.30000000000000004'],
            'negative zero' => [-0.0, '-0.0'],
            'the smallest denormal' => [5.0e-324, '5.0e-324'],
            'the largest double' => [1.7976931348623157e308, '1.7976931348623157e+308'],
            'PHP_INT_MAX as a float' => [(float) PHP_INT_MAX, '9.223372036854776e+18'],
            'a plain fraction' => [75.9, '75.9'],
        ];
    }

    #[Test]
    #[DataProvider('floats')]
    public function a_float_becomes_its_shortest_round_trip_text(float $float, string $expected): void
    {
        $this->assertSame($expected, AuditLog::quoteFloats($float));
        $this->assertSame($expected, AuditLog::quoteFloats(['x' => ['y' => [$float]]])['x']['y'][0]);
    }

    #[Test]
    #[DataProvider('floats')]
    public function the_float_text_does_not_depend_on_the_ambient_serialize_precision(float $float, string $expected): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $this->assertSame($expected, AuditLog::quoteFloats($float));
            $this->assertSame('17', ini_get('serialize_precision'), 'The pin must be restored.');
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    #[Test]
    public function everything_that_is_not_a_float_passes_through_identical(): void
    {
        foreach ([PHP_INT_MIN, PHP_INT_MAX, 0, true, false, null, '', '12.50', 'x'] as $value) {
            $this->assertSame($value, AuditLog::quoteFloats($value));
        }
    }

    #[Test]
    public function keys_and_list_order_are_unchanged_and_nesting_is_recursive(): void
    {
        $in = ['z' => 1, 'a' => [3, 1, 0.5, ['q' => 0.25, 'b' => [2.5]]], 'm' => null];

        $this->assertSame(
            ['z' => 1, 'a' => [3, 1, '0.5', ['q' => '0.25', 'b' => ['2.5']]], 'm' => null],
            AuditLog::quoteFloats($in),
        );
        $this->assertSame(['z', 'a', 'm'], array_keys(AuditLog::quoteFloats($in)));
    }

    #[Test]
    public function applying_it_twice_gives_the_same_result_as_once(): void
    {
        $once = AuditLog::quoteFloats(['r' => 478 / 53, 'n' => 7, 's' => '1.5', 'l' => [0.1 + 0.2]]);

        $this->assertSame($once, AuditLog::quoteFloats($once));
    }

    #[Test]
    public function the_pin_is_restored_after_a_non_finite_float_throws(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            foreach ([NAN, INF, -INF] as $bad) {
                try {
                    AuditLog::quoteFloats(['v' => [$bad]]);
                    $this->fail('A non-finite float was quoted.');
                } catch (\JsonException) {
                    $this->assertSame('17', ini_get('serialize_precision'));
                }
            }
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }
}
