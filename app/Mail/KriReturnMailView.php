<?php

namespace App\Mail;

use App\Enums\KriContactRole;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\KriReturnNoticeRecipient;
use App\Support\Kri\KriWorkingCalendar;

/**
 * The data both KRI return mailables render, read from the return when the mail
 * is sent (ADR 0026 section 17), never carried from when it was requested.
 */
final class KriReturnMailView
{
    /**
     * @return array<string, mixed>
     */
    public static function for(KriReturnNoticeRecipient $recipient): array
    {
        $notice = $recipient->notice ?? $recipient->notice()->first();
        $return = KriReturn::query()->with(['businessUnit', 'period'])->findOrFail($notice->kri_return_id);

        $kris = KriReturnLine::query()
            ->where('kri_return_id', $return->id)
            ->with('kri')
            ->orderBy('key_risk_indicator_id')
            ->get()
            ->filter(fn (KriReturnLine $line) => $line->kri !== null)
            ->map(fn (KriReturnLine $line) => ['code' => (string) $line->kri->kri_code, 'name' => (string) $line->kri->name])
            ->sortBy('code')
            ->values()
            ->all();

        return [
            'department' => (string) ($return->businessUnit->name ?? ''),
            'period' => (string) ($return->period->name ?? ''),
            'due_on' => $return->due_on->format('D j M Y'),
            'days_overdue' => $return->daysOverdue(KriWorkingCalendar::today()),
            'kris' => $kris,
            // Per recipient (A1.1): champions complete the return; every other
            // contact role may only view it, and the policy admits every contact.
            'url' => $recipient->contact_role === 'risk_champion'
                ? route('risk.kri.my-return', ['department' => $return->business_unit_id, 'period' => $return->period_id])
                : route('risk.kri.returns.show', $return),
            'is_champion' => $recipient->contact_role === 'risk_champion',
            'recipient_name' => $recipient->name,
            'tier' => (int) $notice->tier,
            // The reminder number (0 for the monthly request) and the recipient's
            // lowest-tier role in this department, per recipient (specs 09 and 10).
            'sequence' => (int) $notice->sequence,
            'contact_role' => (string) $recipient->contact_role,
            'role_label' => KriContactRole::tryFrom((string) $recipient->contact_role)?->label() ?? '',
            'reached' => self::reached((int) $notice->tier),
            'earlier' => self::earlier((int) $notice->tier, (int) $notice->sequence),
            'kris_shown' => array_slice($kris, 0, 25),
            'kris_more' => max(0, count($kris) - 25),
            'due_long' => $return->due_on->format('l j F Y'),
            'due_short' => $return->due_on->format('D j M'),
            'app_name' => (string) config('app.name'),
        ];
    }

    /**
     * Roles reached up to a tier, in words ("risk champions and team leads").
     */
    public static function reached(int $tier): string
    {
        $words = ['risk champions', 'team leads', 'unit heads', 'group heads'];
        $words = array_slice($words, 0, max(1, min($tier, 4)));

        return count($words) === 1 ? $words[0] : implode(', ', array_slice($words, 0, -1)).' and '.end($words);
    }

    /** Roles reached BEFORE this tier (for "Earlier reminders went to ..."), or null at tier 1. */
    public static function earlier(int $tier, int $sequence): ?string
    {
        if ($sequence < 2) {
            return null;
        }

        return $sequence >= 5 ? 'all four levels' : self::reached(max(1, $tier - 1));
    }
}
