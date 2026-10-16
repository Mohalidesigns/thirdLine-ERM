<?php

namespace Tests\Unit\Support\Audit;

use App\Support\Audit\AuditText;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared guard behind every audit writer's truncation: whatever a caller
 * sends, the value that comes out is valid UTF-8 within the column's length, so
 * the insert cannot be refused and the audit row cannot be silently lost.
 */
class AuditTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function hostileHeaders(): array
    {
        return [
            'two-byte characters' => [str_repeat('é', 600)],
            'three-byte characters' => [str_repeat('€', 600)],
            'four-byte emoji straddling the limit' => [str_repeat('a', 499).'😀'.str_repeat('😀', 100)],
            'emoji at the byte boundary' => [str_repeat('a', 498).str_repeat('😀', 100)],
            'invalid continuation' => ["Mozilla/5.0 \xC3\x28 (X11)"],
            'lone continuation byte' => [str_repeat("\x80", 700)],
            'truncated sequence at the end' => [str_repeat('a', 600)."\xF0\x9F"],
        ];
    }

    #[Test]
    #[DataProvider('hostileHeaders')]
    public function the_clipped_value_is_valid_utf8_within_the_limit(string $raw): void
    {
        $clipped = AuditText::clip($raw, 500);

        $this->assertNotNull($clipped);
        $this->assertTrue(mb_check_encoding($clipped, 'UTF-8'));
        $this->assertLessThanOrEqual(500, mb_strlen($clipped, 'UTF-8'));
        // A valid value must also survive the hash chain's own encoding.
        $this->assertNotFalse(json_encode($clipped, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function an_invalid_byte_is_scrubbed_rather_than_dropped(): void
    {
        $this->assertSame('?(', AuditText::clip("\xC3\x28", 500));
    }

    #[Test]
    public function ascii_is_unchanged_so_existing_digests_stand(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36';

        $this->assertSame($ua, AuditText::clip($ua, 500));
        $this->assertSame(str_repeat('a', 500), AuditText::clip(str_repeat('a', 900), 500));
    }

    #[Test]
    public function a_multibyte_value_is_cut_on_a_character_boundary(): void
    {
        $this->assertSame(str_repeat('é', 500), AuditText::clip(str_repeat('é', 900), 500));
    }

    #[Test]
    public function null_and_empty_stay_null(): void
    {
        $this->assertNull(AuditText::clip(null, 500));
        $this->assertNull(AuditText::clip('', 500));
    }

    #[Test]
    public function the_user_agent_is_read_from_the_request_and_clipped(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => str_repeat('é', 700)]);

        $this->assertSame(str_repeat('é', 500), AuditText::userAgent($request));
        $this->assertSame(str_repeat('é', 512), AuditText::userAgent($request, 512));
        $bare = Request::create('/');
        $bare->headers->remove('User-Agent');
        $this->assertNull(AuditText::userAgent($bare));
    }
}
