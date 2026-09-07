<?php

namespace App\Services\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Models\Tprm\Engagement;
use Illuminate\Support\Collection;

/**
 * FR-INT-05 — duplicate-service detection.
 *
 * "The cheapest concentration control there is", in the TRD's own words: an
 * institution about to sign a fourth call-centre contract usually does not
 * know it already has three. Surfacing them at intake costs nothing and
 * prevents the concentration that Phase 7's analyser would otherwise report
 * six months later.
 *
 * ADVISORY, NEVER BLOCKING. A second vendor for the same service is often the
 * correct answer — it is what resilience looks like. A hard block would be
 * wrong on the merits and would teach requesters to describe services
 * inaccurately to get past it, which would poison the register.
 */
class DuplicateServiceDetector
{
    /**
     * Live engagements that look like the same service as this one.
     *
     * Matching on the two attributes that are structured — service category
     * and engagement type — rather than on the free-text description. Fuzzy
     * text matching on a field people write "Support services (see SOW)" into
     * produces noise, and a noisy panel at intake is one requesters learn to
     * dismiss without reading.
     *
     * @return Collection<int, Engagement>
     */
    public function similarTo(Engagement $engagement, int $limit = 10): Collection
    {
        if ($engagement->service_type_id === null) {
            return collect();
        }

        $liveStatuses = array_values(array_filter(
            EngagementStatus::values(),
            fn (string $status) => EngagementStatus::from($status)->isLive()
        ));

        return Engagement::query()
            ->with(['thirdParty:id,legal_name,slug', 'serviceType:id,name'])
            ->where('service_type_id', $engagement->service_type_id)
            ->where('engagement_type', $engagement->engagement_type?->value)
            ->whereIn('status', $liveStatuses)
            ->when($engagement->exists, fn ($query) => $query->whereKeyNot($engagement->getKey()))
            // A different engagement with the SAME third party is not a
            // duplicate service, it is the same relationship — and telling a
            // requester "you already use this vendor" when they are extending
            // that vendor is noise.
            ->when(
                $engagement->third_party_id !== null,
                fn ($query) => $query->where('third_party_id', '!=', $engagement->third_party_id)
            )
            ->orderByDesc('annual_spend_minor')
            ->limit($limit)
            ->get();
    }
}
