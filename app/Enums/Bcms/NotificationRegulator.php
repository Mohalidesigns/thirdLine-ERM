<?php

namespace App\Enums\Bcms;

/**
 * `bcms_incident_notifications.regulator` (ADR 0020 §2). NigFinCERT is
 * modelled as an additional recipient of a CBN notification, not a fourth
 * case with its own clock (clause map §2.1) — there is no corroborated
 * NigFinCERT-specific window.
 */
enum NotificationRegulator: string
{
    case Cbn = 'cbn';
    case Ndpc = 'ndpc';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cbn => 'CBN',
            self::Ndpc => 'NDPC',
            self::Other => 'Other',
        };
    }

    /** The obligation's basis clause ref — the "why we owe this" record. */
    public function basisClauseRef(): IsoClauseRef
    {
        return match ($this) {
            self::Cbn => IsoClauseRef::Cbn_rcf_incident,
            self::Ndpc => IsoClauseRef::Ndpa_breach_notification,
            self::Other => IsoClauseRef::Bofia_continuity,
        };
    }

    /**
     * The deployment-wide reporting window, in hours, from awareness — law,
     * not a tenant setting (ADR 0020 §2 point 3). `Other` carries no
     * corroborated window and is never auto-clocked.
     */
    public function windowHours(): ?int
    {
        return match ($this) {
            self::Cbn => (int) config('bcms.incident.notification_windows.cbn_hours', 24),
            self::Ndpc => (int) config('bcms.incident.notification_windows.ndpa_hours', 72),
            self::Other => null,
        };
    }
}
