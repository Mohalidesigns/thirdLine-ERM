<?php

namespace App\Enums\Bcms;

/**
 * The seven continuity strategy options of ISO 22331 (Blueprint §9.2).
 *
 * ACCEPT IS ONE OF THEM, and it is not a failure of the register. A process
 * whose outage the organisation can genuinely tolerate should be recorded as
 * accepted with a rationale, not left blank; a register in which every process
 * has an expensive strategy is one nobody believes and nobody funds. What
 * `accept` must never be is silent — `requiresRationale()` is why.
 */
enum StrategyType: string
{
    case Recover = 'recover';
    case Relocate = 'relocate';
    case Remote = 'remote';
    case ManualWorkaround = 'manual_workaround';
    case Reciprocal = 'reciprocal';
    case Outsource = 'outsource';
    case Accept = 'accept';

    public function label(): string
    {
        return match ($this) {
            self::Recover => 'Recover in place',
            self::Relocate => 'Relocate to another site',
            self::Remote => 'Remote working',
            self::ManualWorkaround => 'Manual workaround',
            self::Reciprocal => 'Reciprocal arrangement',
            self::Outsource => 'Outsource',
            self::Accept => 'Accept the loss',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Recover => 'Restore the activity where it normally runs, on repaired or replaced resources.',
            self::Relocate => 'Move the activity and its people to a recovery site.',
            self::Remote => 'Run the activity from staff homes or any connected location.',
            self::ManualWorkaround => 'Continue at reduced capacity without the failed system, and reconcile afterwards.',
            self::Reciprocal => 'Rely on a peer institution or sister branch under a standing arrangement.',
            self::Outsource => 'Transfer the activity to a third party for the duration of the disruption.',
            self::Accept => 'Tolerate the outage. Recorded deliberately, with a rationale, not by omission.',
        };
    }

    /**
     * Whether choosing this strategy obliges the author to say why.
     *
     * Accepting an outage and depending on somebody else's goodwill are the two
     * decisions a regulator asks about, and "it was in the register" is not an
     * answer to "why".
     */
    public function requiresRationale(): bool
    {
        return in_array($this, [self::Accept, self::Reciprocal], true);
    }

    /**
     * Whether the strategy depends on a party outside the organisation.
     *
     * A strategy that does is only as good as the contract behind it, which is
     * why the register links these to TPRM rather than describing them in prose.
     */
    public function dependsOnThirdParty(): bool
    {
        return in_array($this, [self::Reciprocal, self::Outsource], true);
    }

    /** @return list<array{value: string, label: string, description: string, requires_rationale: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'requires_rationale' => $case->requiresRationale(),
        ], self::cases());
    }
}
