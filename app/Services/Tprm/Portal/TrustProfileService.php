<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\AuditLog;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TrustProfile;
use App\Models\Tprm\TrustProfileShare;
use App\Support\Tprm\TrustProfileSchema;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * Writing, publishing and sharing the reusable trust profile — FR-PRT-04.
 *
 * THE ONLY WAY AN INTERNAL CALLER READS A PROFILE IS `documentFor()`, which
 * takes an organisation and returns null unless a live share says otherwise.
 * Nothing else in the application should touch `TrustProfile::published`
 * directly, because the profile sits outside the tenant scope and the share
 * row IS the access control. One door, so there is one place to get it right.
 */
class TrustProfileService
{
    /* ------------------------------------------------------------------ */
    /*  The vendor's side */
    /* ------------------------------------------------------------------ */

    /**
     * Save the vendor's working copy. Changes nothing any client can read.
     *
     * @param  array<string, mixed>  $sections
     */
    public function saveDraft(TrustProfile $profile, array $sections, ?PortalUser $user = null): TrustProfile
    {
        $draft = (array) ($profile->draft ?? []);

        // Merged per section rather than replaced wholesale: the editor saves
        // one section at a time, and a whole-document write would silently
        // blank every section not on the form that happened to post.
        foreach ($sections as $key => $values) {
            if (! array_key_exists($key, TrustProfileSchema::SECTIONS)) {
                continue;
            }

            $draft[$key] = array_merge((array) ($draft[$key] ?? []), (array) $values);
        }

        $profile->forceFill(['draft' => $draft])->save();

        return $profile;
    }

    /**
     * Publish the draft — the act that changes what clients read.
     *
     * VERSIONED AND TIMESTAMPED, because a client that pre-filled a
     * questionnaire from version 3 needs to be able to say so a year later
     * when the vendor is on version 7 and the answers differ.
     */
    public function publish(TrustProfile $profile, ?PortalUser $user = null): TrustProfile
    {
        $draft = (array) ($profile->draft ?? []);

        $profile->forceFill([
            'published' => $draft,
            'published_version' => (int) $profile->published_version + 1,
            'last_published_at' => now(),
            'completeness_pct' => TrustProfile::scoreDocument($draft)['pct'],
        ])->save();

        if ($user !== null) {
            $this->audit($user, 'portal_trust_profile_published', [
                'trust_profile_id' => $profile->getKey(),
                'version' => $profile->published_version,
                'completeness_pct' => $profile->completeness_pct,
            ]);
        }

        return $profile;
    }

    /**
     * What to do next, heaviest win first — the behavioural lever.
     *
     * ORDERED BY POINTS STILL AVAILABLE, not by how incomplete a section is.
     * A section worth 30 that is half done offers more than a section worth 5
     * that is untouched, and a vendor with an hour should spend it on the
     * first. Sections already finished drop off the list entirely rather than
     * sitting there as green ticks — a to-do list is for things to do.
     *
     * @return list<array<string, mixed>>
     */
    public function nextBestActions(TrustProfile $profile, int $limit = 3): array
    {
        $sections = $profile->completeness()['sections'];

        $outstanding = collect($sections)
            ->filter(fn (array $section): bool => $section['points_available'] > 0)
            ->sortByDesc('points_available')
            ->take($limit)
            ->values()
            ->all();

        return $outstanding;
    }

    /* ------------------------------------------------------------------ */
    /*  Sharing */
    /* ------------------------------------------------------------------ */

    /**
     * A client asks to read the profile. Grants nothing.
     *
     * @param  list<string>|null  $sections
     */
    public function requestShare(
        TrustProfile $profile,
        int $organizationId,
        ?int $thirdPartyId = null,
        ?array $sections = null,
        ?int $userId = null,
    ): TrustProfileShare {
        /** @var TrustProfileShare|null $existing */
        $existing = TrustProfileShare::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('trust_profile_id', $profile->getKey())
            ->where('organization_id', $organizationId)
            ->first();

        if ($existing !== null) {
            /*
             * A second request does NOT reset an approval. A client that could
             * re-request its way back to access after the vendor revoked it
             * would make revocation decorative.
             */
            if ($existing->status === TrustProfileShare::STATUS_REVOKED) {
                return $existing;
            }

            return $existing;
        }

        return TrustProfileShare::create([
            'trust_profile_id' => $profile->getKey(),
            'organization_id' => $organizationId,
            'third_party_id' => $thirdPartyId,
            'scope' => $sections,
            'requested_at' => now(),
            'requested_by' => $userId,
        ]);
    }

    /**
     * The vendor approves one client — FR-PRT-04's explicit per-client consent.
     *
     * @param  list<string>|null  $sections
     */
    public function approveShare(
        TrustProfileShare $share,
        PortalUser $user,
        ?array $sections = null,
    ): TrustProfileShare {
        $share->forceFill([
            'status' => TrustProfileShare::STATUS_APPROVED,
            'approved_by_vendor_at' => now(),
            'approved_by_portal_user_id' => $user->getKey(),
            'revoked_at' => null,
            'revocation_reason' => null,
        ]);

        if ($sections !== null) {
            $share->scope = array_values(array_intersect($sections, TrustProfileSchema::shareableSections()));
        }

        $share->save();

        $this->audit($user, 'portal_trust_profile_shared', [
            'trust_profile_id' => $share->trust_profile_id,
            'organization_id' => $share->organization_id,
            'sections' => $share->sections(),
        ]);

        return $share;
    }

    public function declineShare(TrustProfileShare $share, PortalUser $user): TrustProfileShare
    {
        $share->forceFill([
            'status' => TrustProfileShare::STATUS_DECLINED,
            'approved_by_vendor_at' => null,
        ])->save();

        $this->audit($user, 'portal_trust_profile_share_declined', [
            'trust_profile_id' => $share->trust_profile_id,
            'organization_id' => $share->organization_id,
        ]);

        return $share;
    }

    public function revokeShare(TrustProfileShare $share, PortalUser $user, ?string $reason = null): TrustProfileShare
    {
        $share->forceFill([
            'status' => TrustProfileShare::STATUS_REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $this->audit($user, 'portal_trust_profile_share_revoked', [
            'trust_profile_id' => $share->trust_profile_id,
            'organization_id' => $share->organization_id,
            'reason' => $reason,
        ]);

        return $share;
    }

    /**
     * Every client's standing with this profile, for the vendor's Sharing
     * screen.
     *
     * @return Collection<int, TrustProfileShare>
     */
    public function sharesFor(TrustProfile $profile): Collection
    {
        return TrustProfileShare::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('trust_profile_id', $profile->getKey())
            ->with('organization:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  The client's side — the one door */
    /* ------------------------------------------------------------------ */

    /**
     * The published document this organisation may read, or null.
     *
     * NULL IS THE ANSWER FOR EVERY DOUBTFUL CASE: no identity, no profile,
     * nothing published, no share, a share the vendor never approved, a share
     * the vendor revoked. A caller that gets a document knows it was
     * authorised; a caller that gets null does not need to know which of the
     * six it was.
     *
     * @return array{version: int, published_at: string|null, sections: array<string, mixed>}|null
     */
    public function documentFor(ThirdParty $thirdParty, int $organizationId): ?array
    {
        if ($thirdParty->vendor_identity_id === null) {
            return null;
        }

        /** @var TrustProfile|null $profile */
        $profile = TrustProfile::query()
            ->where('vendor_identity_id', $thirdParty->vendor_identity_id)
            ->first();

        if ($profile === null || ! $profile->isPublished()) {
            return null;
        }

        /** @var TrustProfileShare|null $share */
        $share = TrustProfileShare::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('trust_profile_id', $profile->getKey())
            ->where('organization_id', $organizationId)
            ->live()
            ->first();

        if ($share === null) {
            return null;
        }

        return [
            'version' => (int) $profile->published_version,
            'published_at' => $profile->last_published_at?->toDateString(),
            'sections' => $profile->publishedFor($share->sections()),
        ];
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(PortalUser $user, string $event, array $after): void
    {
        AuditLog::create([
            'organization_id' => $user->organization_id,
            'auditable_type' => PortalUser::class,
            'auditable_id' => $user->getKey(),
            'event' => $event,
            'actor_type' => 'portal',
            'actor_id' => null,
            'before' => null,
            'after' => $after + ['email' => $user->email],
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500) ?: null,
        ]);
    }
}
