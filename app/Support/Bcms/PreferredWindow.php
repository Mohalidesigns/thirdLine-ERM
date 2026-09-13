<?php

namespace App\Support\Bcms;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "Second week of the month, Tuesday to Thursday, 09:00–11:00", as a value
 * object.
 *
 * Stored in `bcms_exercise_definitions.preferred_window`:
 *
 *   {"weekdays": ["tue","wed","thu"], "week_of_month": 2,
 *    "start_time": "09:00", "duration_minutes": 120, "months": [3,6,9,12]}
 *
 * EVERY FIELD IS A PREFERENCE, NOT A CONSTRAINT, and that distinction is the
 * whole point. The generator snaps to the window where it can and places the
 * occurrence anyway where it cannot, recording in the generation log that the
 * preference was not met. A window treated as a hard rule turns a mild
 * inconvenience — "we like Tuesdays" — into a `needs_scheduling` gap, and the
 * BC officer then has to unpick which of their own preferences broke the
 * calendar. `months` is the one exception: under `month_specific` distribution
 * it IS the instruction, because a definition told to run in March and
 * September and placed in July has been ignored rather than accommodated.
 *
 * A RAW ARRAY REACHING THE GENERATOR IS A BUG, for the same reason as
 * `AudienceRule` and `PlanBinding`: the grammar lives in customer JSON, and an
 * unvalidated window is a definition that quietly stops honouring its own
 * scheduling rules.
 */
final readonly class PreferredWindow
{
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @param array<string, mixed> $window */
    private function __construct(public array $window) {}

    /** @param array<string, mixed> $window */
    public static function fromArray(array $window): self
    {
        $weekdays = $window['weekdays'] ?? null;

        if ($weekdays !== null) {
            if (! is_array($weekdays) || $weekdays === []) {
                throw new InvalidArgumentException('A preferred window\'s "weekdays" must be a non-empty array.');
            }

            foreach ($weekdays as $day) {
                if (! is_string($day) || ! in_array(strtolower($day), self::WEEKDAYS, true)) {
                    throw new InvalidArgumentException('Unknown weekday "'.json_encode($day).'" in a preferred window.');
                }
            }
        }

        $week = $window['week_of_month'] ?? null;

        if ($week !== null && (! is_numeric($week) || (int) $week < 1 || (int) $week > 5)) {
            throw new InvalidArgumentException('A preferred window\'s "week_of_month" must be between 1 and 5.');
        }

        foreach (['start_time'] as $key) {
            $value = $window[$key] ?? null;

            if ($value !== null && (! is_string($value) || preg_match('/^\d{1,2}:\d{2}$/', $value) !== 1)) {
                throw new InvalidArgumentException("A preferred window's \"{$key}\" must be HH:MM.");
            }
        }

        $months = $window['months'] ?? null;

        if ($months !== null) {
            if (! is_array($months) || $months === []) {
                throw new InvalidArgumentException('A preferred window\'s "months" must be a non-empty array.');
            }

            foreach ($months as $month) {
                if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
                    throw new InvalidArgumentException('A preferred window\'s "months" must be numbers 1 to 12.');
                }
            }
        }

        return new self($window);
    }

    /** @param array<string, mixed>|string|null $value */
    public static function fromJson(mixed $value): ?self
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('A preferred window must be a JSON object.');
        }

        return self::fromArray($value);
    }

    /** @return list<string>|null */
    public function weekdays(): ?array
    {
        $days = $this->window['weekdays'] ?? null;

        return is_array($days) ? array_values(array_map(fn ($d) => strtolower((string) $d), $days)) : null;
    }

    public function weekOfMonth(): ?int
    {
        return isset($this->window['week_of_month']) ? (int) $this->window['week_of_month'] : null;
    }

    public function startTime(): ?string
    {
        return isset($this->window['start_time']) ? (string) $this->window['start_time'] : null;
    }

    /** @return list<int>|null */
    public function months(): ?array
    {
        $months = $this->window['months'] ?? null;

        return is_array($months) ? array_values(array_map('intval', $months)) : null;
    }

    /** Does this date sit inside the window's day preferences? */
    public function matches(Carbon $date): bool
    {
        $weekdays = $this->weekdays();

        if ($weekdays !== null && ! in_array(strtolower($date->format('D')), $weekdays, true)) {
            return false;
        }

        $week = $this->weekOfMonth();

        if ($week !== null && (int) ceil($date->day / 7) !== $week) {
            return false;
        }

        $months = $this->months();

        return $months === null || in_array($date->month, $months, true);
    }

    /**
     * How well this date fits, as a count of unmet preferences.
     *
     * The generator prefers the best-fitting day in a segment rather than the
     * first acceptable one, so "Tuesday of the second week" beats "any Tuesday"
     * beats "any day at all" — and every one of the three still gets scheduled.
     */
    public function misses(Carbon $date): int
    {
        $misses = 0;
        $weekdays = $this->weekdays();

        if ($weekdays !== null && ! in_array(strtolower($date->format('D')), $weekdays, true)) {
            $misses++;
        }

        $week = $this->weekOfMonth();

        if ($week !== null && (int) ceil($date->day / 7) !== $week) {
            $misses++;
        }

        $months = $this->months();

        if ($months !== null && ! in_array($date->month, $months, true)) {
            $misses++;
        }

        return $misses;
    }

    /** A sentence for the generation log. */
    public function describe(): string
    {
        $parts = [];
        $weekdays = $this->weekdays();

        if ($weekdays !== null) {
            $parts[] = implode('/', array_map('ucfirst', $weekdays));
        }

        if ($this->weekOfMonth() !== null) {
            $parts[] = 'week '.$this->weekOfMonth().' of the month';
        }

        if ($this->months() !== null) {
            $parts[] = 'in months '.implode(', ', $this->months());
        }

        if ($this->startTime() !== null) {
            $parts[] = 'from '.$this->startTime();
        }

        return $parts === [] ? 'no preference' : implode(', ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->window;
    }
}
