<?php

namespace App\Enums\Bcms;

/**
 * What a tree is for (`bcms_call_trees.tree_type`, Blueprint §6.1).
 *
 * FIVE TYPES, NOT ONE TABLE OF TREES WITH A LABEL. The type decides three
 * things a screen has to know without asking: whether the tree is proposed from
 * the AD manager chain (a department follows reporting lines; a crisis team
 * does not), how often it must be reviewed, and whether Tier 0 is a person or a
 * duty rota. A `crisis_team` tree generated from the manager chain would put
 * the CFO above the Head of Security in an evacuation, which is not who is in
 * charge of one.
 */
enum CallTreeType: string
{
    case Department = 'department';
    case CrisisTeam = 'crisis_team';
    case Site = 'site';
    case ItDr = 'it_dr';
    case Executive = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Department',
            self::CrisisTeam => 'Crisis management team',
            self::Site => 'Site / branch',
            self::ItDr => 'IT disaster recovery',
            self::Executive => 'Executive',
        };
    }

    /**
     * Can this type sensibly be proposed from the reporting hierarchy?
     *
     * A department and a branch ARE the reporting hierarchy. A crisis team, a
     * DR rota and the executive cascade are appointments, and proposing them
     * from `manager_user_id` produces a tree that looks authoritative and names
     * the wrong people.
     */
    public function followsManagerChain(): bool
    {
        return $this === self::Department || $this === self::Site;
    }

    /**
     * The default review cadence in days.
     *
     * The crisis team and the executive cascade turn over faster than a branch
     * roster does — a promotion changes who the deputy CEO is — so they are
     * reviewed quarterly and everything else half-yearly. These are defaults a
     * client moves, not a rule from a regulator, and nothing here cites one.
     */
    public function defaultReviewDays(): int
    {
        return match ($this) {
            self::CrisisTeam, self::Executive => 90,
            self::ItDr => 120,
            self::Department, self::Site => 180,
        };
    }

    public function tierZeroLabel(): string
    {
        return match ($this) {
            self::Department => 'Head of department',
            self::CrisisTeam => 'Crisis manager',
            self::Site => 'Branch manager',
            self::ItDr => 'Duty DR officer',
            self::Executive => 'Managing Director / CEO',
        };
    }
}
