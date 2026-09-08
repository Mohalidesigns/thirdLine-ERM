<?php

namespace App\Enums\Bcms;

/**
 * How a tree came to exist (`bcms_call_trees.source`).
 *
 * IT IS RECORDED BECAUSE IT CHANGES WHAT A REVIEW MEANS. A tree the system
 * proposed from the manager chain and a human never touched has been checked by
 * nobody, however recent its `last_reviewed_at`; the health dashboard says so.
 * `hybrid` is the honest state of almost every real tree: generated, then
 * corrected by the person who knows that the Head of Operations is on secondment.
 */
enum CallTreeSource: string
{
    case Manual = 'manual';
    case AdGenerated = 'ad_generated';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Built by hand',
            self::AdGenerated => 'Generated from the directory',
            self::Hybrid => 'Generated, then edited',
        };
    }

    /** Generation followed by any human edit is `hybrid`, not `ad_generated`. */
    public function afterHumanEdit(): self
    {
        return $this === self::AdGenerated ? self::Hybrid : $this;
    }
}
