<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssertsCanonicalJson;

/**
 * assertSameJson() exists so a MySQL 8 key re-ordering does not fail a test.
 * It must not become a way to stop asserting: these cases pin what it still
 * refuses. No database — it is pure comparison.
 */
class AssertsCanonicalJsonTest extends TestCase
{
    use AssertsCanonicalJson;

    private function assertRejected(mixed $expected, mixed $actual): void
    {
        try {
            $this->assertSameJson($expected, $actual);
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('assertSameJson accepted a real difference');
    }

    #[Test]
    public function object_key_order_is_ignored_at_every_depth(): void
    {
        $this->assertSameJson(
            ['b' => 1, 'a' => ['y' => 2, 'x' => ['n' => 'v', 'm' => 'w']], 'bands' => [['to' => 4, 'from' => 1]]],
            ['bands' => [['from' => 1, 'to' => 4]], 'a' => ['x' => ['m' => 'w', 'n' => 'v'], 'y' => 2], 'b' => 1],
        );
    }

    #[Test]
    public function list_order_is_still_checked(): void
    {
        $this->assertRejected(['low', 'high'], ['high', 'low']);
        $this->assertRejected([['code' => 'a'], ['code' => 'b']], [['code' => 'b'], ['code' => 'a']]);
        // ...including a list nested in a reordered object.
        $this->assertRejected(['k' => [1, 2, 3], 'j' => 1], ['j' => 1, 'k' => [3, 2, 1]]);
    }

    #[Test]
    public function types_are_still_strict(): void
    {
        $this->assertRejected(['n' => 1], ['n' => '1']);
        $this->assertRejected(['n' => 1], ['n' => 1.0]);
        $this->assertRejected(['n' => true], ['n' => 1]);
        $this->assertRejected(['n' => null], ['n' => 0]);
        $this->assertRejected(['n' => ['a' => 1]], ['n' => ['a' => '1']]);
    }

    #[Test]
    public function a_missing_extra_or_renamed_key_is_a_difference(): void
    {
        $this->assertRejected(['a' => 1, 'b' => 2], ['a' => 1]);
        $this->assertRejected(['a' => 1], ['a' => 1, 'b' => 2]);
        $this->assertRejected(['a' => 1], ['z' => 1]);
        $this->assertRejected(['a' => ['b' => 1]], ['a' => ['c' => 1]]);
    }

    #[Test]
    public function a_value_difference_is_a_difference(): void
    {
        $this->assertRejected(['a' => 'x'], ['a' => 'y']);
        $this->assertRejected(['a' => ['b' => 1]], ['a' => ['b' => 2]]);
        $this->assertRejected([], ['a' => 1]);
    }
}
