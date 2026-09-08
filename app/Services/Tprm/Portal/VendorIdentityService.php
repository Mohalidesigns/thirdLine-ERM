<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\AuditLog;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TrustProfile;
use App\Models\Tprm\VendorIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * How one company's supplier records across several tenants become one
 * identity — the join that makes the trust profile reusable (FR-PRT-04).
 *
 * NOTHING HERE MATCHES ON A NAME, and nothing links automatically. A wrong
 * link does not mis-cluster a report; it shows Bank A the security posture
 * that Bank B's vendor declared in confidence, which is a disclosure incident
 * with two customers in it. The Phase 7 lesson about `matchToRegister` being
 * deliberately non-fuzzy applies here with much more at stake.
 *
 * THE LINK IS MADE BY THE VENDOR, AND THE EVIDENCE IS THE VENDOR'S OWN
 * SESSION. `candidatesFor()` looks for portal accounts carrying the SAME EMAIL
 * ADDRESS in other tenants — an address this person has just authenticated
 * against, with a password and a second factor, in the tenant they are in now.
 * They are offered the link and must accept it. That is the strongest evidence
 * available to us and it costs the vendor one click, where a registration
 * number typed by a procurement officer is neither.
 */
class VendorIdentityService
{
    /**
     * Other tenants where this person already has a portal account.
     *
     * DELIBERATELY CROSSES THE TENANT SCOPE, which is why it is here and not
     * on a model somebody might call from an internal controller. What it
     * returns is shown only to the vendor, about the vendor's own accounts —
     * a client must never learn which other banks a vendor serves.
     *
     * @return Collection<int, PortalUser>
     */
    public function candidatesFor(PortalUser $user): Collection
    {
        return PortalUser::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('email', $user->email)
            ->where('id', '!=', $user->getKey())
            ->where('status', PortalUser::STATUS_ACTIVE)
            ->with('thirdParty')
            ->get();
    }

    /**
     * The identity this account's vendor is already linked to, if any.
     */
    public function identityFor(PortalUser $user): ?VendorIdentity
    {
        $thirdParty = ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->find($user->third_party_id);

        if ($thirdParty?->vendor_identity_id === null) {
            return null;
        }

        return VendorIdentity::query()->find($thirdParty->vendor_identity_id);
    }

    /**
     * An identity this person could claim, because one of their other accounts
     * already has one.
     *
     * Returns null when there is nothing to offer — either no other account,
     * or none of them has claimed an identity yet.
     */
    public function claimableIdentity(PortalUser $user): ?VendorIdentity
    {
        foreach ($this->candidatesFor($user) as $other) {
            $identity = $this->identityFor($other);

            if ($identity !== null) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * Create an identity for this vendor, or attach the one it claims.
     *
     * IDEMPOTENT. A vendor pressing the button twice, or signing in to a third
     * bank, must land on the same identity rather than forking a second one —
     * two identities for one company is two half-finished profiles and a
     * client reading the wrong one.
     */
    public function claim(PortalUser $user, ?VendorIdentity $identity = null): VendorIdentity
    {
        return DB::transaction(function () use ($user, $identity): VendorIdentity {
            /** @var ThirdParty $thirdParty */
            $thirdParty = ThirdParty::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($user->third_party_id);

            $existing = $thirdParty->vendor_identity_id === null
                ? null
                : VendorIdentity::query()->find($thirdParty->vendor_identity_id);

            if ($existing !== null) {
                return $existing;
            }

            $identity ??= VendorIdentity::create([
                'canonical_name' => $thirdParty->legal_name,
                // Copied from the tenant's record as a STARTING POINT the
                // vendor can correct. Not used to match anything — see the
                // class comment.
                'registration_number' => $thirdParty->registration_number,
                'country_of_incorporation' => $thirdParty->country_of_incorporation,
                'lei' => $thirdParty->lei,
                'primary_domain' => $this->domainOf($user->email),
            ]);

            $thirdParty->forceFill(['vendor_identity_id' => $identity->getKey()])->save();

            TrustProfile::firstOrCreate(['vendor_identity_id' => $identity->getKey()]);

            $this->audit($user, 'portal_identity_claimed', [
                'vendor_identity_id' => $identity->getKey(),
                'third_party_id' => $thirdParty->getKey(),
                'canonical_name' => $identity->canonical_name,
            ]);

            return $identity;
        });
    }

    /**
     * The vendor's profile, creating the identity on first use.
     */
    public function profileFor(PortalUser $user): TrustProfile
    {
        $identity = $this->identityFor($user) ?? $this->claim($user);

        return TrustProfile::firstOrCreate(['vendor_identity_id' => $identity->getKey()]);
    }

    private function domainOf(string $email): ?string
    {
        $domain = mb_strtolower(trim((string) mb_strstr($email, '@', false)));
        $domain = ltrim($domain, '@');

        return $domain === '' ? null : $domain;
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
