<?php

namespace App\Mail;

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
     * @return array{department: string, period: string, due_on: string, days_overdue: int|null, kris: list<array{code: string, name: string}>, url: string, is_champion: bool, recipient_name: string, tier: int}
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
            'department' => (string) ($return->businessUnit?->name ?? ''),
            'period' => (string) ($return->period?->name ?? ''),
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
        ];
    }
}
