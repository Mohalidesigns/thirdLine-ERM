<?php

namespace ThirdLine\Platform\Licensing;

use Carbon\Carbon;

/**
 * Derives the tiered expiry-warning banner shown across the app.
 *
 * The license server does not stamp a license "type" (demo/annual), so the tier
 * is inferred from the signed JWT duration (exp - iat):
 *   - Demo (<= 31 day total): a single red, daily alert once inside the tail
 *     window — 3 days out for a 15-day demo, 5 days out for a 16-20 day demo.
 *   - Annual / long: amber persistent at <= 60 days (~2 months), escalating to a
 *     red daily alert at <= 30 days (~1 month).
 *   - Already expired (read-only mode): a persistent red notice.
 */
class ExpiryNotifier
{
    private const AMBER_DAYS = 60;   // ~2 months

    private const RED_DAYS = 30;     // ~1 month

    private const DEMO_MAX_DAYS = 31;

    /**
     * @param  array{valid:bool,mode:string,days_remaining:int|float,expires_at:?string,issued_at:?string,type:?string}  $status
     * @return array{level:string,cadence:string,title:string,message:string,days_remaining:int,expires_at:?string,is_demo:bool}|null
     */
    public function evaluate(array $status): ?array
    {
        $mode = $status['mode'] ?? 'unlicensed';

        // A revoked / unlicensed / locked license is handled by the full block,
        // not a banner. Only an already-expired (read-only) license warns here
        // among the invalid states.
        if (empty($status['valid'])) {
            if ($mode === 'read_only') {
                return [
                    'level' => 'red',
                    'cadence' => 'persistent',
                    'title' => 'License expired',
                    'message' => 'Your ThirdLine license has expired and the application is in read-only mode. Renew now to restore full access.',
                    'days_remaining' => 0,
                    'expires_at' => $status['expires_at'] ?? null,
                    'is_demo' => false,
                ];
            }

            return null;
        }

        if (empty($status['expires_at'])) {
            return null;
        }

        try {
            $expires = Carbon::parse($status['expires_at']);
        } catch (\Exception $e) {
            return null;
        }

        // Use the engine's authoritative day count for threshold decisions so the
        // banner agrees with /settings/license; expires_at is only for display/duration.
        $daysRemaining = (int) max(0, $status['days_remaining'] ?? 0);
        $dateLabel = $expires->format('M j, Y');
        $whenLabel = $this->whenLabel($daysRemaining);

        $durationDays = null;
        if (! empty($status['issued_at'])) {
            try {
                $durationDays = (int) round(Carbon::parse($status['issued_at'])->diffInDays($expires, true));
            } catch (\Exception $e) {
                $durationDays = null;
            }
        }

        $isDemo = $durationDays !== null && $durationDays > 0 && $durationDays <= self::DEMO_MAX_DAYS;

        if ($isDemo) {
            $threshold = $durationDays <= 15 ? 3 : 5;

            if ($daysRemaining <= $threshold) {
                return [
                    'level' => 'red',
                    'cadence' => 'daily',
                    'title' => 'Demo license ending',
                    'message' => "Your {$durationDays}-day ThirdLine demo {$whenLabel} ({$dateLabel}). Contact ThirdLine to move to a full license before it ends.",
                    'days_remaining' => $daysRemaining,
                    'expires_at' => $expires->toIso8601String(),
                    'is_demo' => true,
                ];
            }

            return null;
        }

        if ($daysRemaining <= self::RED_DAYS) {
            return [
                'level' => 'red',
                'cadence' => 'daily',
                'title' => 'License expiring soon',
                'message' => "Your ThirdLine license {$whenLabel} ({$dateLabel}). Renew now — the application will stop working when it expires.",
                'days_remaining' => $daysRemaining,
                'expires_at' => $expires->toIso8601String(),
                'is_demo' => false,
            ];
        }

        if ($daysRemaining <= self::AMBER_DAYS) {
            return [
                'level' => 'amber',
                'cadence' => 'persistent',
                'title' => 'License renewal due',
                'message' => "Your ThirdLine license {$whenLabel} ({$dateLabel}). Please arrange renewal to avoid any interruption.",
                'days_remaining' => $daysRemaining,
                'expires_at' => $expires->toIso8601String(),
                'is_demo' => false,
            ];
        }

        return null;
    }

    private function whenLabel(int $daysRemaining): string
    {
        return match (true) {
            $daysRemaining <= 0 => 'expires today',
            $daysRemaining === 1 => 'expires tomorrow',
            default => "expires in {$daysRemaining} days",
        };
    }
}
