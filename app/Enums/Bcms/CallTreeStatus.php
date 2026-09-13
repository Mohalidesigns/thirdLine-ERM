<?php

namespace App\Enums\Bcms;

/**
 * A tree's lifecycle (`bcms_call_trees.status`).
 *
 * THREE VALUES, AND `stale` IS DELIBERATELY NOT ONE OF THEM even though the
 * Phase 0 migration's comment names it. Staleness is a HEALTH fact — "nobody
 * has checked this since March" — and the lifecycle is a GOVERNANCE fact —
 * "the head of department approved this version". Writing staleness into the
 * same column destroys the second: an approved tree that goes stale would stop
 * reading as approved, and the version an examiner was shown would change
 * meaning because a date passed.
 *
 * It would also make staleness depend on a job having run. A tree is overdue at
 * midnight whether or not the scheduler woke up, and a dashboard that says
 * otherwise is wrong in the direction that matters. `CallTreeService::isStale()`
 * computes it from `last_reviewed_at + review_frequency_days`, so it is true the
 * moment it is true. See `docs/adr/0013-phase-6-call-tree-versions.md`.
 *
 * `archived` is what a superseded version becomes. An approved tree is never
 * edited — v2 is a new row pointing back at v1 — because the roster that was
 * cascaded in March has to still be the roster that was cascaded in March.
 */
enum CallTreeStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Archived => 'Superseded',
        };
    }

    /** Only a draft can be edited. An approved version is superseded instead. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Only an approved, current version may be cascaded for real. */
    public function isCascadable(): bool
    {
        return $this === self::Approved;
    }
}
