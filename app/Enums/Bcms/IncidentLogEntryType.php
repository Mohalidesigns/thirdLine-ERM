<?php

namespace App\Enums\Bcms;

/**
 * `bcms_incident_log.entry_type` (ADR 0020 §3).
 *
 * `decision` is the ISO 22361 artefact (clause map §1.2) and the default
 * choice on the crisis-room composer for exactly that reason. SitReps are
 * `situation_report` entries chained by `supersedes_entry_id` — that chain is
 * the versioning the prompt asks for; there is no separate SitRep table.
 */
enum IncidentLogEntryType: string
{
    case Decision = 'decision';
    case Action = 'action';
    case Communication = 'communication';
    case SituationReport = 'situation_report';
    case Escalation = 'escalation';

    public function label(): string
    {
        return match ($this) {
            self::Decision => 'Decision',
            self::Action => 'Action',
            self::Communication => 'Communication',
            self::SituationReport => 'Situation report',
            self::Escalation => 'Escalation',
        };
    }

    /**
     * Whether "options considered" and "rationale" are required alongside
     * `content` — clause map §1.2/`crisis-room.md` §2: captured at the
     * moment, not reconstructed later, and only for a `decision` entry.
     */
    public function requiresOptionsAndRationale(): bool
    {
        return $this === self::Decision;
    }
}
