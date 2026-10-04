<?php

namespace App\Enums\Bcms;

/**
 * `bcms_identity_sync_runs.status` — a fact about a past event, computed and
 * stored here on purpose (ADR 0018 §2.3), unlike call-tree staleness which
 * ADR 0013 refused to store because it is a fact about the present.
 */
enum SyncRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Success => 'Success',
            self::Partial => 'Partial',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
