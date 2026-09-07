<?php

namespace App\Services\Tprm\Evidence;

use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use Illuminate\Support\Collection;

/**
 * Evidence expiry — FR-EVD-02.
 *
 * THE POINT IS NOT THE REMINDER, IT IS THE DECAY. A control evidenced by a
 * SOC 2 that expired in March is not evidenced today, and TRD §7.4 says the
 * assurance coefficient falls accordingly. The 90/60/30/7-day notices exist so
 * that the decay is never a surprise — a relationship owner who is told four
 * times and does nothing has made a decision, which is a different thing from
 * a score that moved without explanation.
 *
 * THE WINDOWS ARE EXACT-DAY, NOT CUMULATIVE. A document 45 days from expiry
 * fires nothing: it fired at 90 and will fire at 30. Cumulative windows would
 * mean a notice every single day for the last ninety days of every document's
 * life, which is how a notification channel gets muted, and a muted channel is
 * worse than no channel because everyone believes it is working.
 */
class ExpiryMonitor
{
    /** @var list<int> */
    public const WINDOWS = [90, 60, 30, 7];

    /**
     * Documents that cross a notice threshold exactly today.
     *
     * @return Collection<int, array{document: Document, days: int, window: int}>
     */
    public function due(?int $organizationId = null): Collection
    {
        $dates = [];

        foreach (self::WINDOWS as $window) {
            $dates[now()->addDays($window)->toDateString()] = $window;
        }

        return Document::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_superseded', false)
            ->whereNotNull('valid_to')
            // `whereDate` per window rather than `whereIn` on the raw column.
            // The `date` cast writes `Y-m-d H:i:s` into a DATE column, so a
            // literal string comparison matches nothing on either driver — a
            // silent no-op that would have shipped as "the monitor never
            // fires", which is the worst shape a scheduled job can fail in.
            ->where(function ($query) use ($dates) {
                foreach (array_keys($dates) as $date) {
                    $query->orWhereDate('valid_to', $date);
                }
            })
            ->with('documentType')
            ->get()
            ->map(function (Document $document) use ($dates): array {
                /** @var int $window */
                $window = $dates[$document->valid_to->toDateString()];

                return [
                    'document' => $document,
                    'days' => (int) $document->daysUntilExpiry(),
                    'window' => $window,
                ];
            })
            ->values();
    }

    /**
     * Documents that expired since the last run and have not been replaced.
     *
     * Reported separately from the notice windows because it is a different
     * message to a different reader: the notices ask someone to chase a
     * renewal, and this one tells them a score has already moved.
     *
     * @return Collection<int, Document>
     */
    public function newlyExpired(int $sinceDays = 1, ?int $organizationId = null): Collection
    {
        return Document::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_superseded', false)
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<', now()->toDateString())
            ->whereDate('valid_to', '>=', now()->subDays($sinceDays)->toDateString())
            ->get();
    }

    /**
     * Who to tell about a document.
     *
     * The relationship owner, because they are the person who can actually
     * ask the vendor for the new certificate. The executive sponsor is NOT
     * copied on a 90-day notice — an executive who receives every routine
     * renewal reminder stops reading the ones that matter.
     *
     * From Phase 8 the vendor portal contact is copied too; that recipient is
     * absent here rather than stubbed, so the notification code has one list
     * to extend rather than a null to interpret.
     *
     * @return list<int> user ids
     */
    public function recipientsFor(Document $document): array
    {
        $engagement = $document->owner_type === Document::OWNER_ENGAGEMENT
            ? Engagement::query()->find($document->owner_id)
            : null;

        return array_values(array_unique(array_filter([
            $engagement?->relationship_owner_id,
            $document->uploaded_by,
        ])));
    }

    /**
     * The evidence heat map for the documents grid — how much of the library
     * is current, expiring or gone.
     *
     * @return array<string, int>
     */
    public function summary(?int $organizationId = null): array
    {
        $base = fn () => Document::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_superseded', false);

        return [
            'total' => $base()->count(),
            'expired' => $base()->expired()->count(),
            'expiring_30' => $base()->expiringWithin(30)->count(),
            'expiring_90' => $base()->expiringWithin(90)->count(),
            // Evidence with no expiry at all. Not a problem in itself — a
            // penetration test report has no expiry date printed on it — but
            // a number worth seeing beside the others, because a library that
            // is mostly this is a library the expiry monitor cannot help with.
            'no_expiry' => $base()->whereNull('valid_to')->count(),
        ];
    }
}
