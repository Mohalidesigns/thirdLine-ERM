<?php

namespace App\Services\Bcms\Exercises;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The subscribable calendar feed — RFC 5545, by hand.
 *
 * NO LIBRARY. An ICS file for a list of timed events is a few hundred bytes of
 * a well-specified text format, and the two things that actually break
 * subscriptions — CRLF line endings and 75-octet line folding — are things a
 * dependency would do for us and that we would then never look at again. Both
 * are here, both are commented, and both are testable.
 *
 * THE URL IS SIGNED, NOT TOKENISED (ADR 0012). `URL::signedRoute()` gives a
 * per-user, tamper-evident address with no secret stored anywhere to leak or
 * rotate. A token column would be a credential in a database.
 *
 * THE FEED IS A SUBSCRIPTION, SO IT MUST BE STABLE. Every event's `UID` is
 * derived from the occurrence's uuid and never changes; a reschedule changes
 * `DTSTART` and bumps `SEQUENCE`, which is exactly what Outlook and Google need
 * in order to MOVE an appointment rather than create a second one. Regenerating
 * UIDs is the single commonest way an ICS feed fills somebody's calendar with
 * duplicates.
 *
 * UNANNOUNCED EXERCISES ARE NOT IN THE FEED. The point of an unannounced call
 * tree test is that the participants do not know; publishing it to their
 * Outlook would defeat the exercise, and it is the sort of thing nobody
 * notices until the results are meaningless.
 */
class IcsFeedBuilder
{
    private const CRLF = "\r\n";

    public function __construct(private readonly CalendarService $calendar) {}

    /** A stable, signed, per-user subscription URL. */
    public function urlFor(User $user): string
    {
        return URL::signedRoute('bcms.calendar.ics', ['user' => $user->getKey()]);
    }

    /**
     * The feed body.
     *
     * A twelve-month window either side of today: a subscription is a rolling
     * view, and shipping five years of history to a phone every refresh is how
     * a calendar client starts ignoring the feed.
     */
    public function build(User $user, ?Carbon $from = null, ?Carbon $to = null): string
    {
        $from ??= now()->subMonths(12)->startOfDay();
        $to ??= now()->addMonths(12)->endOfDay();

        $rows = array_filter(
            $this->calendar->forUser($user, $from, $to),
            fn (array $row) => ! $row['unannounced'] && $row['scheduled_start'] !== null,
        );

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NexusRisk//BCMS Resilience Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape('Business continuity exercises'),
            // Ninety minutes. Long enough not to hammer the app, short enough
            // that a reschedule reaches somebody's phone the same morning.
            'X-PUBLISHED-TTL:PT90M',
            'REFRESH-INTERVAL;VALUE=DURATION:PT90M',
        ];

        foreach ($rows as $row) {
            $lines = array_merge($lines, $this->event($row));
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map($this->fold(...), $lines)).self::CRLF;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function event(array $row): array
    {
        $start = Carbon::parse($row['scheduled_start'])->utc();
        $end = $row['scheduled_end'] !== null
            ? Carbon::parse($row['scheduled_end'])->utc()
            : $start->copy()->addHours(2);

        $description = array_filter([
            $row['type'],
            $row['ladder_label'] ? 'ISO 22398 level: '.$row['ladder_label'] : null,
            $row['mandatory'] ? 'Mandatory exercise.' : null,
            $row['facilitator'] ? 'Facilitator: '.$row['facilitator'] : null,
        ]);

        return [
            'BEGIN:VEVENT',
            // Stable for the life of the occurrence. This is what makes a
            // reschedule move the appointment instead of duplicating it.
            'UID:'.$row['uuid'].'@nexusrisk',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            // Incremented by every reschedule, which is precisely what
            // `SEQUENCE` means in RFC 5545 and precisely what
            // `reschedule_count` counts.
            'SEQUENCE:'.(int) $row['reschedule_count'],
            'SUMMARY:'.$this->escape((string) $row['title']),
            'DESCRIPTION:'.$this->escape(implode("\n", $description)),
            'LOCATION:'.$this->escape((string) ($row['location'] ?? $row['site'] ?? '')),
            'STATUS:'.($row['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'CATEGORIES:'.$this->escape('Business continuity'),
            'END:VEVENT',
        ];
    }

    /**
     * RFC 5545 §3.3.11 escaping: backslash, semicolon, comma and newline.
     *
     * Order matters — the backslash has to be escaped first or it re-escapes
     * the escapes that follow.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1: lines are folded at 75 octets, continuations start with a
     * space.
     *
     * OCTETS, NOT CHARACTERS, and that distinction bites in this product
     * specifically: a Nigerian bank's exercise is called something containing ₦
     * or a Yoruba diacritic often enough, and folding a multi-byte character in
     * half produces a feed that some clients reject outright and others render
     * as mojibake. `mb_strcut` cuts on a character boundary at or below the
     * octet limit.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $remaining = $line;
        $limit = 75;

        $guard = 0;

        while (strlen($remaining) > $limit && $guard++ < 200) {
            $chunk = mb_strcut($remaining, 0, $limit, 'UTF-8');

            // A cut that yields nothing would spin. It cannot happen with a
            // limit of 74 and valid UTF-8, and a feed generator is not the
            // place to find out that an encoding assumption was wrong.
            if ($chunk === '') {
                break;
            }

            $out[] = $chunk;
            $remaining = substr($remaining, strlen($chunk));
            // Continuation lines carry a leading space, which counts towards
            // the 75.
            $limit = 74;
            $remaining = ' '.$remaining;
        }

        $out[] = $remaining;

        return implode(self::CRLF, $out);
    }
}
