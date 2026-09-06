<?php

namespace ThirdLine\Platform\Licensing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Licensing\LicenseManager;
use ThirdLine\Platform\Licensing\LicensingConfig;

class EnsureLicenseFeature
{
    public function __construct(private LicenseManager $licenseManager) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Load + validate the license first (cached ~5 min). This also populates
        // the EnforcementEngine's claims so hasFeature() reads the real entitlement
        // set rather than an empty one on a fresh request.
        $status = $this->licenseManager->validate();

        // Enforce module entitlements ONLY when a valid license is present. With no
        // usable license (never activated / locked / test env), the app's normal
        // unlicensed handling governs access — we don't add a second redirect here,
        // so this middleware never hard-locks an install that simply hasn't paired
        // yet. The hard boundary for the "activated but unticked" case is below.
        if (($status['valid'] ?? false) && ! $this->licenseManager->hasFeature($feature)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'feature_not_licensed',
                    'message' => "The '{$feature}' module is not included in your current license plan.",
                    'feature' => $feature,
                ], 403);
            }

            return LicensingConfig::redirectHome(
                "The '{$feature}' module is not included in your current license plan. Please upgrade your license."
            );
        }

        return $next($request);
    }
}
