<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 Appendix B known-answer vectors (SHA-1, 30-second step, secret
 * "12345678901234567890"). The retired implementation packed the counter
 * into four bytes and could never produce these.
 */
class TotpTest extends TestCase
{
    private const SECRET_BYTES = '12345678901234567890';

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfc6238Vectors(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[Test]
    #[DataProvider('rfc6238Vectors')]
    public function it_matches_the_rfc_6238_vectors(int $timestamp, string $expected): void
    {
        $secret = Totp::base32Encode(self::SECRET_BYTES);

        $this->assertSame($expected, Totp::at($secret, $timestamp, 8));
        $this->assertSame(substr($expected, -6), Totp::at($secret, $timestamp));
    }

    #[Test]
    public function the_counter_is_eight_bytes_big_endian(): void
    {
        // pack('N') — the old four-byte counter — yields a different code for
        // the same instant; the vectors above are only reachable with pack('J').
        $wrong = hash_hmac('sha1', pack('N', intdiv(59, 30)), self::SECRET_BYTES, true);
        $offset = ord($wrong[19]) & 0x0F;
        $wrongCode = str_pad((string) ((unpack('N', substr($wrong, $offset, 4))[1] & 0x7FFFFFFF) % 100000000), 8, '0', STR_PAD_LEFT);

        $this->assertNotSame('94287082', $wrongCode);
    }

    #[Test]
    public function verify_accepts_one_step_either_side_and_nothing_further(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue(Totp::verify(Totp::at($secret, $now), $secret, now: $now));
        $this->assertTrue(Totp::verify(Totp::at($secret, $now - 30), $secret, now: $now));
        $this->assertTrue(Totp::verify(Totp::at($secret, $now + 30), $secret, now: $now));
        $this->assertFalse(Totp::verify(Totp::at($secret, $now - 60), $secret, now: $now));
        $this->assertFalse(Totp::verify(Totp::at($secret, $now + 60), $secret, now: $now));
    }

    #[Test]
    public function verify_rejects_malformed_codes(): void
    {
        $secret = Totp::generateSecret();

        $this->assertFalse(Totp::verify('', $secret));
        $this->assertFalse(Totp::verify('12345', $secret));
        $this->assertFalse(Totp::verify('abcdef', $secret));
        $this->assertTrue(Totp::verify(' '.Totp::at($secret, time()).' ', $secret), 'Surrounding whitespace is tolerated.');
    }

    #[Test]
    public function base32_round_trips_and_secrets_are_160_bits(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode(self::SECRET_BYTES));
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', Totp::generateSecret());
    }

    #[Test]
    public function the_otpauth_uri_names_the_issuer_and_account_and_no_host(): void
    {
        $uri = Totp::otpauthUri('Atheris ERM', 'cro@bank.test', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/Atheris%20ERM:cro%40bank.test?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Atheris%20ERM', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringNotContainsString('http', $uri);
    }
}
