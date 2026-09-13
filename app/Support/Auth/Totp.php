<?php

namespace App\Support\Auth;

/**
 * RFC 6238 time-based one-time passwords over RFC 4226 HOTP.
 *
 * Extracted from the retired AuthController in migration Phase 1, and fixed
 * on the way: the counter is packed as an EIGHT-byte big-endian integer
 * (pack('J')), where the old code used pack('N') — four bytes — so no
 * authenticator app could ever produce a code it accepted. TotpTest holds
 * this class to the RFC 6238 Appendix B vectors.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const STEP_SECONDS = 30;

    public const DIGITS = 6;

    /**
     * A fresh shared secret, base32-encoded. 20 random bytes (160 bits) is the
     * length RFC 4226 recommends and every authenticator app accepts.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The otpauth:// URI an authenticator app enrols from. Rendered into a QR
     * code IN THE BROWSER (resources/js/Components/QrCode.jsx) — this string
     * carries the secret, so it must never be handed to an external service.
     */
    public static function otpauthUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Does $code match the secret at $now, allowing $window steps either side
     * for clock drift?
     */
    public static function verify(string $code, string $secret, int $window = 1, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv($now ?? time(), self::STEP_SECONDS);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::hotp(self::base32Decode($secret), $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code for a base32 secret at a Unix timestamp. */
    public static function at(string $secret, int $timestamp, int $digits = self::DIGITS): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp, self::STEP_SECONDS), $digits);
    }

    /**
     * RFC 4226 HOTP: HMAC-SHA1 over the eight-byte big-endian counter,
     * dynamically truncated to $digits decimal digits.
     */
    public static function hotp(string $secretBytes, int $counter, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $secretBytes, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = (unpack('N', substr($hash, $offset, 4))[1]) & 0x7FFFFFFF;

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data) ?? '');
        $decoded = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $buffer = ($buffer << 5) | strpos(self::ALPHABET, $data[$i]);
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
            }
        }

        return $decoded;
    }
}
