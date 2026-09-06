<?php

namespace ThirdLine\Platform\Licensing;

use ThirdLine\Platform\Licensing\Exceptions\LicenseException;
use ThirdLine\Platform\Licensing\Exceptions\LicenseExpiredException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

class JwtValidator
{
    private string $publicKey;

    private string $algorithm;

    public function __construct()
    {
        $publicKeyPath = config('licensing.keys.public');
        if (! file_exists($publicKeyPath)) {
            throw new LicenseException('License public key not found.');
        }
        $this->publicKey = file_get_contents($publicKeyPath);
        $this->algorithm = config('licensing.algorithm', 'RS256');
    }

    public function validate(string $token): object
    {
        try {
            // VAPT-028: tolerate small NTP clock drift between this host and the
            // signing server so a valid licence isn't rejected over a few seconds.
            JWT::$leeway = (int) config('licensing.max_clock_drift_seconds', 60);

            $decoded = JWT::decode($token, new Key($this->publicKey, $this->algorithm));

            $this->validateIssuer($decoded);
            $this->validateVersion($decoded);
            $this->validateAudience($decoded);

            return $decoded;
        } catch (ExpiredException $e) {
            throw new LicenseExpiredException('License has expired.', 0, $e);
        } catch (SignatureInvalidException $e) {
            throw new LicenseException('License signature is invalid. Possible forgery.', 0, $e);
        } catch (LicenseExpiredException $e) {
            throw $e;
        } catch (LicenseException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new LicenseException('License validation failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function validateIssuer(object $claims): void
    {
        if (($claims->iss ?? '') !== config('licensing.issuer')) {
            throw new LicenseException('License issuer mismatch.');
        }
    }

    private function validateVersion(object $claims): void
    {
        $supportedVersions = ['1.0'];
        if (! in_array($claims->ver ?? '1.0', $supportedVersions)) {
            throw new LicenseException('Unsupported license version.');
        }
    }

    /**
     * VAPT-028: bind the licence to this install's client id when the token
     * carries an `aud` claim. Backward-compatible: tokens minted without `aud`
     * (or installs without a configured client id) are not rejected, but a
     * present-and-mismatched `aud` means the licence was issued for a different
     * customer and must not validate here.
     */
    private function validateAudience(object $claims): void
    {
        $expected = config('licensing.client_id');
        $aud = $claims->aud ?? null;

        if ($expected === null || $expected === '' || $aud === null) {
            return;
        }

        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($expected, $audiences, true)) {
            throw new LicenseException('License audience mismatch — issued for a different client.');
        }
    }
}
