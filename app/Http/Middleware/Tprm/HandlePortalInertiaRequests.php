<?php

namespace App\Http\Middleware\Tprm;

use App\Models\Organization;
use App\Models\Tprm\PortalUser;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * What every portal page is told — and, more to the point, what it is not.
 *
 * This does NOT extend `HandleInertiaRequests`. It shares no navigation, no
 * permission list, no period, no feature flags and no internal user. Every one
 * of those is a description of the client's own installation, and none of it
 * is a vendor's business. Inheriting and then unsetting would have been
 * shorter and would have leaked the next prop somebody adds to the parent.
 *
 * THE TENANT BLOCK IS DELIBERATELY THINNER than the internal one: a name and
 * the branding colours, because the vendor has to know whose portal they are
 * in. Not the id, not the short code, not the settings.
 */
class HandlePortalInertiaRequests extends Middleware
{
    protected $rootView = 'tprm-portal';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var PortalUser|null $user */
        $user = $request->user('tprm-portal');

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user === null ? null : [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'mfa_enabled' => $user->mfa_enabled,
                    'vendor' => $user->thirdParty?->legal_name,
                ],
            ],

            'client' => fn () => $this->client($user),

            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * The bank whose portal this is.
     *
     * @return array{name: string}|null
     */
    private function client(?PortalUser $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $organization = Organization::query()->find($user->organization_id);

        return $organization === null ? null : ['name' => $organization->name];
    }
}
