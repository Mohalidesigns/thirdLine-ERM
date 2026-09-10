<?php

namespace App\Enums\Bcms;

/**
 * How a process relates to the thing it depends on
 * (`bcms_dependencies.dependency_type`).
 *
 * `Upstream` AND `Downstream` ARE BOTH DEPENDENCIES AND THEY FAIL DIFFERENTLY.
 * An upstream dependency stops this process when IT fails — payments cannot run
 * without the switch. A downstream one is a process that stops when THIS one
 * does, and recording it matters because the reverse-impact view is what makes
 * an outage's real blast radius visible: an IT director asking "what breaks if
 * I take this down" is asking a downstream question, and a register that only
 * models upstream cannot answer it.
 *
 * `Supporting` is the thing that degrades rather than stops the process —
 * reporting, reconciliation — and `Infrastructure` is the shared substrate
 * everything sits on. They are separated from `Upstream` because a single
 * points-of-failure register that treats the building's power the same as the
 * core banking platform produces a list nobody prioritises.
 */
enum DependencyRelation: string
{
    case Upstream = 'upstream';
    case Downstream = 'downstream';
    case Supporting = 'supporting';
    case Infrastructure = 'infrastructure';

    /** Does this process STOP when the dependency fails? */
    public function halts(): bool
    {
        return in_array($this, [self::Upstream, self::Infrastructure], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Upstream => 'Upstream — this process stops without it',
            self::Downstream => 'Downstream — it stops without this process',
            self::Supporting => 'Supporting — the process degrades without it',
            self::Infrastructure => 'Infrastructure — shared substrate',
        };
    }
}
