<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseValid
{
    public function __construct(private LicenseManager $licenseManager) {}

    public function handle(Request $request, Closure $next, ?string $requiredMode = null): Response
    {
        // Emergency valve / test env: skip server-side enforcement entirely.
        if (! config('licensing.enforce_valid', true)) {
            return $next($request);
        }

        // Never gate the routes needed to RECOVER from an invalid license, or we'd
        // trap the user: license activation (we redirect here), profile, and logout
        // must stay reachable while unlicensed/locked. This also prevents a redirect
        // loop when the gate is applied to the same group that serves settings.license.
        $name = $request->route()?->getName() ?? '';
        if (str_starts_with($name, 'admin.license')
            || str_starts_with($name, 'profile.')
            || $name === 'logout') {
            return $next($request);
        }

        $status = $this->licenseManager->validate();

        // If no license at all, redirect to license page
        if (! ($status['valid'] ?? false) && ($status['mode'] ?? 'unlicensed') === 'unlicensed') {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'no_license',
                    'message' => 'No active license found. Please activate your license.',
                ], 403);
            }

            return redirect()->route('admin.license')
                ->with('error', 'Please activate your license to continue.');
        }

        // If locked mode (tampered, revoked, grace expired), block everything
        if (($status['mode'] ?? '') === 'locked') {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'license_locked',
                    'message' => $status['message'] ?? 'License is locked. Please contact support.',
                ], 403);
            }

            return redirect()->route('admin.license')
                ->with('error', $status['message'] ?? 'Your license is locked. Please contact support.');
        }

        // If requiredMode is 'write' and we're in read_only, block writes
        if ($requiredMode === 'write' && ($status['mode'] ?? '') === 'read_only') {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'license_read_only',
                    'message' => 'Your license has expired. The system is in read-only mode.',
                ], 403);
            }

            return redirect()->back()
                ->with('error', 'Your license has expired. The system is in read-only mode.');
        }

        // Inject license status into request for downstream use
        $request->merge(['_license_status' => $status]);

        return $next($request);
    }
}
