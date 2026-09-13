<?php

namespace App\Support\Sso;

use App\Models\OrganizationSsoSetting;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Settings as SamlSettings;
use OneLogin\Saml2\Utils as SamlUtils;

/**
 * SAML 2.0 service provider, built per organization from its settings row.
 *
 * All protocol work — XML signature verification, canonicalisation, certificate
 * matching, Conditions/NotOnOrAfter/Destination/Audience/InResponseTo checks —
 * is delegated to onelogin/php-saml. That delegation is the point: XML
 * signature wrapping is the classic authentication *bypass* in SAML, and a
 * hand-written verifier is how deployments get owned. This class only builds
 * settings and maps the resulting attributes.
 *
 * The toolkit is configured to demand signed assertions and to reject
 * unsolicited responses, which are the two settings most often left permissive.
 */
class SamlDriver
{
    public function __construct(private readonly OrganizationSsoSetting $setting) {}

    /**
     * The URL to send the browser to in order to start sign-in.
     */
    public function loginUrl(?string $relayState = null): string
    {
        return $this->auth()->login($relayState, [], false, false, true);
    }

    /**
     * Validate an assertion posted to the ACS endpoint and return the identity.
     *
     * @throws SsoAuthenticationException when the assertion is missing,
     *                                    unsigned, signed by the wrong key,
     *                                    expired, replayed or addressed
     *                                    elsewhere
     */
    public function processResponse(?string $lastRequestId = null): object
    {
        $auth = $this->auth();

        try {
            // Second argument is the in-flight request id: supplying it makes
            // the toolkit reject an assertion that answers a request we never
            // sent, which is what stops a replayed or injected response.
            $auth->processResponse($lastRequestId);
        } catch (SamlError $e) {
            throw new SsoAuthenticationException('The SAML response could not be processed: '.$e->getMessage());
        }

        $errors = $auth->getErrors();

        if ($errors !== []) {
            // getLastErrorReason() carries the detail; it goes to the log, not
            // to the browser, because it describes our validation internals.
            logger()->warning('SAML assertion rejected', [
                'organization_id' => $this->setting->organization_id,
                'slug' => $this->setting->slug,
                'errors' => $errors,
                'reason' => $auth->getLastErrorReason(),
            ]);

            throw new SsoAuthenticationException('The SAML response failed validation.');
        }

        if (! $auth->isAuthenticated()) {
            throw new SsoAuthenticationException('The identity provider did not authenticate this user.');
        }

        return $this->mapAttributes($auth);
    }

    /**
     * SP metadata XML, for the client to hand to their IdP administrator.
     *
     * @throws SsoAuthenticationException when the generated metadata is invalid
     */
    public function metadata(): string
    {
        $settings = new SamlSettings($this->settingsArray(), true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);

        if ($errors !== []) {
            throw new SsoAuthenticationException('Generated SP metadata is invalid: '.implode(', ', $errors));
        }

        return $metadata;
    }

    /**
     * Shape the assertion into the same object the OIDC path produces, so
     * SsoProvisioningService does not care which protocol was used.
     */
    private function mapAttributes(SamlAuth $auth): object
    {
        $attributes = $auth->getAttributes();
        $nameId = $auth->getNameId();

        $emailAttribute = $this->setting->saml_email_attribute
            ?: $this->firstPresent($attributes, [
                'email',
                'mail',
                'urn:oid:0.9.2342.19200300.100.1.3',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            ]);

        $nameAttribute = $this->setting->saml_name_attribute
            ?: $this->firstPresent($attributes, [
                'displayName',
                'name',
                'cn',
                'http://schemas.microsoft.com/identity/claims/displayname',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
            ]);

        $groupsAttribute = $this->setting->groups_claim
            ?: $this->firstPresent($attributes, [
                'groups',
                'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
            ]);

        // NameID is the fallback for email because many IdPs are configured to
        // emit the address there and nowhere else.
        $email = $this->single($attributes, $emailAttribute) ?: $nameId;

        return (object) [
            'id' => $nameId,
            'email' => $email,
            'name' => $this->single($attributes, $nameAttribute) ?: null,
            'groups' => $groupsAttribute ? ($attributes[$groupsAttribute] ?? []) : [],
            // SAML has no email_verified claim. Left null rather than false so
            // the provisioning service treats it as "not asserted" instead of
            // refusing every SAML sign-in.
            'email_verified' => null,
            'user' => $attributes,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $attributes
     * @param  list<string>  $candidates
     */
    private function firstPresent(array $attributes, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $attributes)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $attributes
     */
    private function single(array $attributes, ?string $key): ?string
    {
        if ($key === null || ! isset($attributes[$key])) {
            return null;
        }

        $value = $attributes[$key];

        return is_array($value) ? ($value[0] ?? null) : (string) $value;
    }

    private function auth(): SamlAuth
    {
        return new SamlAuth($this->settingsArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsArray(): array
    {
        // The toolkit reads the current URL from the server globals to validate
        // Destination; behind a load balancer that must be the external scheme.
        if (request()->isSecure()) {
            SamlUtils::setSelfProtocol('https');
        }

        return [
            'strict' => true,
            'debug' => false,
            'baseurl' => url('/auth/sso/'.$this->setting->slug),

            'sp' => [
                'entityId' => $this->setting->spEntityId(),
                'assertionConsumerService' => [
                    'url' => $this->setting->acsUrl(),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => url('/auth/sso/'.$this->setting->slug.'/slo'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert' => (string) $this->setting->saml_sp_x509_cert,
                'privateKey' => (string) $this->setting->saml_sp_private_key,
            ],

            'idp' => [
                'entityId' => (string) $this->setting->saml_idp_entity_id,
                'singleSignOnService' => [
                    'url' => (string) $this->setting->saml_idp_sso_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => (string) $this->setting->saml_idp_slo_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $this->normaliseCertificate((string) $this->setting->saml_idp_x509_cert),
            ],

            'security' => [
                // Refuse anything that is not signed. An IdP that does not sign
                // its assertions offers no authentication guarantee at all.
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                'requestedAuthnContext' => false,
                // Reject IdP-initiated responses we did not ask for: without
                // this, a captured assertion can simply be posted back.
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                'authnRequestsSigned' => filled($this->setting->saml_sp_private_key),
                'logoutRequestSigned' => filled($this->setting->saml_sp_private_key),
                'logoutResponseSigned' => filled($this->setting->saml_sp_private_key),
            ],
        ];
    }

    /**
     * Accept a certificate pasted with or without PEM armour and line breaks —
     * IdP admin consoles hand it over in every combination.
     */
    private function normaliseCertificate(string $certificate): string
    {
        $certificate = trim($certificate);

        if ($certificate === '') {
            return '';
        }

        return preg_replace('/\s+/', '', str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
            '',
            $certificate
        )) ?? '';
    }
}
