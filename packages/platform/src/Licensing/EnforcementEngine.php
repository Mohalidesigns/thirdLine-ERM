<?php

namespace ThirdLine\Platform\Licensing;

use ThirdLine\Platform\Licensing\Exceptions\FeatureNotLicensedException;

class EnforcementEngine
{
    private ?object $claims = null;

    private string $currentMode = 'normal';

    public function setClaims(object $claims): void
    {
        $this->claims = $claims;
    }

    public function setMode(string $mode): void
    {
        $this->currentMode = $mode;
    }

    public function hasFeature(string $feature): bool
    {
        if (! $this->claims) {
            return false;
        }

        $features = (array) ($this->claims->feat ?? []);

        return isset($features[$feature]) && $features[$feature] === true;
    }

    public function requireFeature(string $feature): void
    {
        if (! $this->hasFeature($feature)) {
            app(LicenseAuditLogger::class)->log('feature_access_denied', [
                'feature' => $feature,
                'license_id' => $this->claims->jti ?? 'unknown',
                'plan' => $this->claims->plan ?? 'unknown',
            ]);

            throw new FeatureNotLicensedException(
                "The '{$feature}' module is not included in your current license plan."
            );
        }
    }

    public function checkUserLimit(int $currentUsers): bool
    {
        $maxUsers = $this->claims->mu ?? 0;

        return $currentUsers <= $maxUsers;
    }

    public function getMode(): string
    {
        return $this->currentMode;
    }

    public function canWrite(): bool
    {
        return in_array($this->currentMode, ['normal', 'grace']);
    }

    public function canRead(): bool
    {
        return in_array($this->currentMode, ['normal', 'grace', 'read_only']);
    }

    public function getFeatures(): array
    {
        return (array) ($this->claims->feat ?? []);
    }

    public function getPlan(): string
    {
        return $this->claims->plan ?? 'unknown';
    }

    public function getDaysRemaining(): int
    {
        if (! $this->claims || ! isset($this->claims->exp)) {
            return 0;
        }
        $diff = $this->claims->exp - time();

        return max(0, (int) floor($diff / 86400));
    }

    public function determineMode(
        bool $isExpired,
        bool $isRevoked,
        bool $serverReachable,
        int $daysSinceLastSync
    ): string {
        if ($isRevoked) {
            $this->currentMode = 'locked';
        } elseif ($isExpired) {
            $this->currentMode = 'read_only';
        } elseif (! $serverReachable && $daysSinceLastSync > config('licensing.grace_period_days', 7)) {
            $this->currentMode = 'locked';
        } elseif (! $serverReachable && $daysSinceLastSync > 0) {
            $this->currentMode = 'grace';
        } else {
            $this->currentMode = 'normal';
        }

        return $this->currentMode;
    }
}
