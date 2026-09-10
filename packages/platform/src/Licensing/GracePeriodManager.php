<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Support\Facades\Cache;

class GracePeriodManager
{
    public function getStatus(?string $plan = null): array
    {
        $lastSync = Cache::get('license.last_sync', 0);
        $graceDays = $plan ? $this->getGraceDaysForPlan($plan) : config('licensing.grace_period_days', 7);

        if ($lastSync === 0) {
            return [
                'active' => false,
                'expired' => false,
                'days_remaining' => $graceDays,
                'days_since_sync' => 0,
                'grace_days' => $graceDays,
                'message' => 'License has never been validated online.',
            ];
        }

        $daysSinceSync = (int) floor((time() - $lastSync) / 86400);

        if ($daysSinceSync <= 0) {
            return [
                'active' => false,
                'expired' => false,
                'days_remaining' => $graceDays,
                'days_since_sync' => 0,
                'grace_days' => $graceDays,
                'message' => 'License is up to date.',
            ];
        }

        // Use the raw delta for the active/expired flags so the boundary
        // day (daysSinceSync === graceDays) is the *last* allowed day
        // rather than the first denied one. Display days_remaining is
        // still clamped at zero.
        $rawRemaining = $graceDays - $daysSinceSync;
        $daysRemaining = max(0, $rawRemaining);

        return [
            'active' => $rawRemaining >= 0,
            'expired' => $rawRemaining < 0,
            'days_remaining' => $daysRemaining,
            'days_since_sync' => $daysSinceSync,
            'grace_days' => $graceDays,
            'message' => $rawRemaining >= 0
                ? "Offline grace period: {$daysRemaining} days remaining."
                : 'Grace period has expired. Please connect to the licensing server.',
        ];
    }

    public function isAccessAllowed(?string $plan = null): bool
    {
        // Never synced -> no access. The license has to be validated
        // online at least once before it's usable; getStatus() reports
        // this as active=false / expired=false (neither active grace
        // nor formally expired), so we have to check the sync cache
        // separately here.
        if (Cache::get('license.last_sync', 0) === 0) {
            return false;
        }

        $status = $this->getStatus($plan);

        return ! ($status['expired'] ?? true);
    }

    public function getGraceDaysForPlan(string $plan): int
    {
        return config("licensing.plans.{$plan}.grace_days", match ($plan) {
            'enterprise' => 14,
            'professional' => 7,
            'starter' => 3,
            default => 3,
        });
    }
}
