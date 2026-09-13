<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Requests\Auth\DiscoverSsoRequest;
use App\Models\OrganizationSsoSetting;
use App\Services\SsoProvisioningService;
use App\Support\Sso\OidcProvider;
use App\Support\Sso\SamlDriver;
use App\Support\Sso\SsoAuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Single sign-on entry points, one set per organization.
 *
 * Each client configures its own identity provider in the admin UI and gets a
 * sign-in slug, so the flow can tell which tenant it is acting for before
 * anyone is authenticated. SAML requires this regardless: the Assertion
 * Consumer Service URL is registered per service provider with the IdP.
 *
 * Both protocols converge on SsoProvisioningService, so the rules about who
 * may sign in are written once.
 */
class SsoController extends Controller
{
    public function __construct(private readonly SsoProvisioningService $provisioning) {}

    /**
     * Start sign-in. Works for either driver.
     */
    public function redirect(Request $request, string $slug)
    {
        try {
            $setting = $this->setting($slug);

            if ($setting->isSaml()) {
                $driver = new SamlDriver($setting);

                return redirect()->away($driver->loginUrl($request->query('redirect')));
            }

            return $this->oidcDriver($setting)->redirect();
        } catch (SsoAuthenticationException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return $this->genericFailure($slug, $e);
        }
    }

    /**
     * OIDC callback.
     */
    public function callback(Request $request, string $slug)
    {
        try {
            $setting = $this->setting($slug);

            if (! $setting->isOidc()) {
                throw new SsoAuthenticationException('This organization is not configured for OIDC sign-in.');
            }

            // The IdP reports a refusal (consent denied, admin block) in the
            // query string. Surface it as a failed sign-in, not a 500.
            if ($request->filled('error')) {
                throw new SsoAuthenticationException('The identity provider refused the sign-in request.');
            }

            $idpUser = $this->oidcDriver($setting)->user();

            $user = $this->provisioning->resolve($slug, $setting->toProviderConfig(), $idpUser);
        } catch (SsoAuthenticationException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return $this->genericFailure($slug, $e);
        }

        return $this->completeSignIn($request, $user);
    }

    /**
     * SAML Assertion Consumer Service.
     *
     * The IdP POSTs here from the user's browser, so there is no session
     * CSRF token to present — the signed assertion is the authentication, and
     * the route is excluded from CSRF verification for that reason.
     */
    public function acs(Request $request, string $slug)
    {
        try {
            $setting = $this->setting($slug);

            if (! $setting->isSaml()) {
                throw new SsoAuthenticationException('This organization is not configured for SAML sign-in.');
            }

            $idpUser = (new SamlDriver($setting))->processResponse(
                $request->session()->pull('saml_request_id')
            );

            $user = $this->provisioning->resolve($slug, $setting->toProviderConfig(), $idpUser);
        } catch (SsoAuthenticationException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return $this->genericFailure($slug, $e);
        }

        return $this->completeSignIn($request, $user);
    }

    /**
     * SP metadata, for the client to hand to their IdP administrator.
     *
     * Public by design: metadata contains only the entity id, ACS URL and the
     * SP's public certificate, all of which the IdP needs and none of which is
     * a secret.
     */
    public function metadata(string $slug): Response
    {
        try {
            $setting = $this->setting($slug, requireUsable: false);

            if (! $setting->isSaml()) {
                abort(404);
            }

            return response((new SamlDriver($setting))->metadata(), 200, [
                'Content-Type' => 'application/samlmetadata+xml',
                'Content-Disposition' => 'attachment; filename="'.$setting->slug.'-sp-metadata.xml"',
            ]);
        } catch (SsoAuthenticationException $e) {
            abort(404, $e->getMessage());
        }
    }

    /**
     * Home-realm discovery: turn an email address into the right sign-in URL
     * so users do not have to know their organization's slug.
     */
    public function discover(DiscoverSsoRequest $request)
    {
        $validated = $request->validated();

        $setting = OrganizationSsoSetting::resolveByEmailDomain($validated['email']);

        if (! $setting || ! $setting->isUsable()) {
            // Deliberately vague: whether a domain is federated is information
            // about a customer, and enumerating it should not be free.
            return redirect()->route('login')
                ->with('error', 'Single sign-on is not available for that email address.')
                ->withInput($request->only('email'));
        }

        return redirect()->to($setting->signInUrl());
    }

    /* ------------------------------------------------------------------ */
    /*  Shared */
    /* ------------------------------------------------------------------ */

    private function completeSignIn(Request $request, $user)
    {
        $request->session()->regenerate();

        Auth::login($user, remember: false);

        $user->forceFill([
            'last_login_at' => now(),
            'last_activity_at' => now(),
            'login_attempts' => 0,
        ])->saveQuietly();

        /*
         * MFA — behind features.mfa_totp (rebuilt in migration Phase 1; see
         * config/features.php). The IdP authenticated the user; it did not
         * perform this platform's second factor. If their role requires MFA
         * they still have to pass it: an enrolled user verifies a code
         * (MfaVerifyController completes an already-authenticated session), an
         * unenrolled one is sent to enrol. With the flag off the session is
         * marked verified and they proceed.
         */ if (EnsureMfaVerified::featureEnabled() && $this->mfaRequiredFor($user)) {
            $request->session()->forget('mfa_verified');

            return redirect()->route($user->mfa_enabled ? 'mfa.verify' : 'mfa.setup');
        }

        $request->session()->put('mfa_verified', true);

        return redirect()->intended(route('risk.dashboard'));
    }

    /**
     * @throws SsoAuthenticationException
     */
    private function setting(string $slug, bool $requireUsable = true): OrganizationSsoSetting
    {
        if (! config('sso.enabled')) {
            throw new SsoAuthenticationException('Single sign-on is not enabled for this deployment.');
        }

        $setting = OrganizationSsoSetting::resolveBySlug($slug);

        if (! $setting) {
            throw new SsoAuthenticationException('Unknown or disabled single sign-on provider.');
        }

        if ($requireUsable && ($missing = $setting->missingRequirements()) !== []) {
            throw new SsoAuthenticationException(
                'Single sign-on is not fully configured for this organization (missing: '.implode(', ', $missing).').'
            );
        }

        return $setting;
    }

    private function oidcDriver(OrganizationSsoSetting $setting): OidcProvider
    {
        $config = $setting->toProviderConfig();

        Socialite::extend($setting->slug, fn () => Socialite::buildProvider(OidcProvider::class, [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => $config['redirect'],
        ]));

        /** @var OidcProvider $driver */
        $driver = Socialite::driver($setting->slug);

        return $driver->setProviderConfig($config)->scopes($config['scopes']);
    }

    private function genericFailure(string $slug, Throwable $e)
    {
        // Integration faults are logged in full and shown as nothing: the
        // detail describes our internals and the IdP exchange.
        logger()->error('SSO failed', [
            'slug' => $slug,
            'exception' => $e->getMessage(),
        ]);

        return redirect()->route('login')
            ->with('error', 'Single sign-on failed. Please contact your administrator.');
    }

    private function mfaRequiredFor($user): bool
    {
        $required = (array) ($user->organization?->settings['mfa_required_roles'] ?? []);

        return $required !== [] && $user->hasAnyRole($required);
    }
}
