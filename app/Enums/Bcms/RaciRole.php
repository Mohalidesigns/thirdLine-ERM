<?php

namespace App\Enums\Bcms;

/**
 * The four RACI letters (`bcms_raci_assignments.raci_role`).
 *
 * ACCOUNTABLE IS SINGULAR AND THE OTHERS ARE NOT. That is the whole content of
 * the model and it is what the gap report tests: a process with three
 * responsible people and nobody accountable has no owner, and a process with two
 * accountable people has no owner either, because when it fails they will each
 * reasonably believe it was the other's.
 */
enum RaciRole: string
{
    case Responsible = 'R';
    case Accountable = 'A';
    case Consulted = 'C';
    case Informed = 'I';

    /** May more than one person hold this letter on one object? */
    public function allowsMultiple(): bool
    {
        return $this !== self::Accountable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Responsible => 'Responsible',
            self::Accountable => 'Accountable',
            self::Consulted => 'Consulted',
            self::Informed => 'Informed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Responsible => 'Does the work.',
            self::Accountable => 'Answers for it. Exactly one person, and not the same question as who does the work.',
            self::Consulted => 'Asked before a decision, and their view is sought.',
            self::Informed => 'Told after a decision.',
        };
    }
}
