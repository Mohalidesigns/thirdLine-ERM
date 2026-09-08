<?php

namespace App\Services\Tprm\Incidents;

use App\Enums\Tprm\Regulator;
use Illuminate\Support\Carbon;

/**
 * Whether one regulator's clock runs on one incident, and why.
 *
 * `undetermined` IS A THIRD STATE AND THE REASON THIS IS A CLASS RATHER THAN A
 * BOOLEAN. The CBN materiality test needs the bank's shareholders' funds; a
 * tenant that has not recorded them cannot compute it. Collapsing that into
 * "not reportable" produces a missed twenty-four-hour deadline nobody knows
 * about until the supervisor asks, and collapsing it into "reportable"
 * produces notifications a bank did not owe. It is its own answer, it carries
 * what is missing, and the screen shows it as a question for a person.
 */
class ClockAssessment
{
    /**
     * @param  list<string>  $triggers  the definition limbs that were met
     */
    private function __construct(
        public readonly Regulator $regulator,
        public readonly bool $reportable,
        public readonly bool $undetermined,
        public readonly array $triggers,
        public readonly string $rationale,
        public readonly ?Carbon $deadlineAt,
    ) {}

    /**
     * @param  list<string>  $triggers
     */
    public static function reportable(Regulator $regulator, array $triggers, string $rationale, Carbon $deadlineAt): self
    {
        return new self($regulator, true, false, $triggers, $rationale, $deadlineAt);
    }

    public static function notReportable(Regulator $regulator, string $rationale): self
    {
        return new self($regulator, false, false, [], $rationale, null);
    }

    /**
     * The test could not be run.
     *
     * NO DEADLINE IS SET. A countdown rendered from a guess is worse than no
     * countdown, because a screen showing hours remaining gets believed and
     * planned around.
     */
    public static function undetermined(Regulator $regulator, string $rationale): self
    {
        return new self($regulator, false, true, [], $rationale, null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'regulator' => $this->regulator->value,
            'reportable' => $this->reportable,
            'undetermined' => $this->undetermined,
            'triggers' => $this->triggers,
            'rationale' => $this->rationale,
            'deadline_at' => $this->deadlineAt?->toIso8601String(),
        ];
    }
}
