<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\TrustProfileShare;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use App\Support\Tprm\TrustProfileSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The vendor's own trust profile, and who may read it — FR-PRT-04.
 *
 * THE COMPLETENESS PANEL LEADS WITH WHAT THE VENDOR GETS, not with a
 * percentage. "Answer these ten questions once and new assessments arrive
 * part-answered" is a reason to spend an afternoon; "your profile is 43%
 * complete" is a scold. The percentage is on the page because it makes
 * progress visible, not because it is the argument.
 */
class TrustProfileController extends Controller
{
    public function __construct(
        private readonly TrustProfileService $profiles,
        private readonly VendorIdentityService $identities,
    ) {}

    public function show(Request $request)
    {
        $user = $this->user($request);
        $profile = $this->identities->profileFor($user);

        $completeness = $profile->completeness();

        return Inertia::render('TprmPortal/TrustProfile', [
            'profile' => [
                'uuid' => $profile->uuid,
                'draft' => $profile->draft ?? [],
                'published_version' => $profile->published_version,
                'last_published_at' => $profile->last_published_at?->toDayDateTimeString(),
                'has_unpublished_changes' => ($profile->draft ?? []) !== ($profile->published ?? []),
            ],
            'schema' => collect(TrustProfileSchema::SECTIONS)
                ->map(fn (array $section, string $key): array => [
                    'key' => $key,
                    'label' => $section['label'],
                    'fields' => $section['fields'],
                    'unlocks' => $section['unlocks'],
                    'weight' => $section['weight'],
                ])->values(),
            'completeness' => $completeness,
            'nextBest' => $this->profiles->nextBestActions($profile),
            'tenantCount' => $profile->vendorIdentity?->tenantCount() ?? 1,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $validated = $request->validate([
            'sections' => ['required', 'array'],
        ]);

        $profile = $this->identities->profileFor($this->user($request));

        $this->profiles->saveDraft($profile, $validated['sections'], $this->user($request));

        return back()->with('success', 'Saved. Publish when you are ready for your clients to see it.');
    }

    public function publish(Request $request)
    {
        $user = $this->user($request);
        $profile = $this->identities->profileFor($user);

        $this->profiles->publish($profile, $user);

        return back()->with('success', sprintf(
            'Published version %d. Clients you have approved will see it from now on.',
            $profile->refresh()->published_version,
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Sharing */
    /* ------------------------------------------------------------------ */

    public function sharing(Request $request)
    {
        $user = $this->user($request);
        $profile = $this->identities->profileFor($user);

        return Inertia::render('TprmPortal/Sharing', [
            'shares' => $this->profiles->sharesFor($profile)
                ->map(fn (TrustProfileShare $share): array => [
                    'id' => $share->getKey(),
                    'client' => $share->organization?->name,
                    'status' => $share->status,
                    'sections' => $share->sections(),
                    'approved_at' => $share->approved_by_vendor_at?->toDateString(),
                    'revoked_at' => $share->revoked_at?->toDateString(),
                    'requested_at' => $share->requested_at?->toDateString(),
                ])->values(),
            'sections' => collect(TrustProfileSchema::SECTIONS)
                ->map(fn (array $s, string $key): array => ['key' => $key, 'label' => $s['label']])
                ->values(),
        ]);
    }

    public function approveShare(Request $request, TrustProfileShare $share)
    {
        $validated = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string'],
        ]);

        $user = $this->user($request);

        if (! $this->ownsShare($share, $user)) {
            abort(404);
        }

        $this->profiles->approveShare($share, $user, $validated['sections'] ?? null);

        return back()->with('success', sprintf(
            '%s can now read your profile.',
            $share->refresh()->organization->name ?? 'That client',
        ));
    }

    public function declineShare(Request $request, TrustProfileShare $share)
    {
        $user = $this->user($request);

        if (! $this->ownsShare($share, $user)) {
            abort(404);
        }

        $this->profiles->declineShare($share, $user);

        return back()->with('success', 'Declined.');
    }

    public function revokeShare(Request $request, TrustProfileShare $share)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $user = $this->user($request);

        if (! $this->ownsShare($share, $user)) {
            abort(404);
        }

        $this->profiles->revokeShare($share, $user, $validated['reason'] ?? null);

        return back()->with('success', 'Access withdrawn.');
    }

    /**
     * Whether this share belongs to this vendor's profile.
     *
     * A 404 RATHER THAN A 403 when it does not. A vendor probing share ids
     * should not be able to tell the difference between "that share does not
     * exist" and "that share belongs to somebody else" — the second answer is
     * a confirmation that another vendor's share has that id.
     */
    private function ownsShare(TrustProfileShare $share, PortalUser $user): bool
    {
        $profile = $this->identities->profileFor($user);

        return (int) $share->trust_profile_id === $profile->getKey();
    }

    private function user(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return $user;
    }
}
