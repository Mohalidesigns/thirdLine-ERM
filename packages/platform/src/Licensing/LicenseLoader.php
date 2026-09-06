<?php

namespace ThirdLine\Platform\Licensing;

use ThirdLine\Platform\Licensing\Exceptions\LicenseException;
use Illuminate\Support\Facades\Crypt;

class LicenseLoader
{
    private string $licensePath;

    private string $signaturePath;

    public function __construct()
    {
        $this->licensePath = storage_path('licensing/license.enc');
        $this->signaturePath = storage_path('licensing/license.sig');
    }

    public function load(): string
    {
        if (! file_exists($this->licensePath)) {
            throw new LicenseException('No license file found. Please activate your license.');
        }

        $this->verifyFileIntegrity();

        $encrypted = file_get_contents($this->licensePath);

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception $e) {
            throw new LicenseException('License file is corrupted or has been tampered with.');
        }
    }

    public function store(string $jwtToken): void
    {
        $directory = dirname($this->licensePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $encrypted = Crypt::encryptString($jwtToken);
        file_put_contents($this->licensePath, $encrypted);

        $signature = hash_hmac('sha256', $encrypted, config('app.key'));
        file_put_contents($this->signaturePath, $signature);

        chmod($this->licensePath, 0600);
        chmod($this->signaturePath, 0600);
    }

    private function verifyFileIntegrity(): void
    {
        if (! file_exists($this->signaturePath)) {
            throw new LicenseException('License integrity signature missing.');
        }

        $encrypted = file_get_contents($this->licensePath);
        $storedSig = file_get_contents($this->signaturePath);
        $computedSig = hash_hmac('sha256', $encrypted, config('app.key'));

        if (! hash_equals($storedSig, $computedSig)) {
            app(LicenseAuditLogger::class)->log('tamper_detected', [
                'type' => 'file_modification',
                'file' => $this->licensePath,
            ]);

            throw new LicenseException('License file integrity check failed. Possible tampering detected.');
        }
    }

    public function exists(): bool
    {
        return file_exists($this->licensePath);
    }

    public function remove(): void
    {
        if (file_exists($this->licensePath)) {
            unlink($this->licensePath);
        }
        if (file_exists($this->signaturePath)) {
            unlink($this->signaturePath);
        }
    }
}
