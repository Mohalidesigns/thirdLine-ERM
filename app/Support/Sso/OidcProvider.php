<?php

namespace App\Support\Sso;

use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * A single OpenID Connect provider driven entirely by config/sso.php.
 *
 * Microsoft Entra ID, Google Workspace and Okta all speak standard OIDC, so
 * they differ only by endpoint — one audited code path is better than three
 * vendor SDKs.
 *
 * Identity is read from the userinfo endpoint using the access token, not by
 * decoding the id_token. That is deliberate: verifying a JWT means verifying
 * signatures, algorithms, issuer, audience and expiry correctly, and getting
 * any of it wrong is an authentication bypass. Calling userinfo over TLS with
 * a token we just obtained through the code exchange puts that burden on the
 * IdP, where it belongs.
 */
class OidcProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    /** @var array<string, mixed> */
    protected array $providerConfig = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function setProviderConfig(array $config): static
    {
        $this->providerConfig = $config;

        return $this;
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->providerConfig['auth_url'], $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->providerConfig['token_url'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->providerConfig['userinfo_url'], [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        $groupsClaim = $this->providerConfig['groups_claim'] ?? 'groups';

        return (new User)->setRaw($user)->map([
            // `sub` is the only claim OIDC guarantees is stable and unique for
            // the issuer; email can be reassigned to a different person.
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name') ?: Arr::get($user, 'preferred_username'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
            'groups' => Arr::wrap(Arr::get($user, $groupsClaim, [])),
            'email_verified' => Arr::get($user, 'email_verified'),
        ]);
    }
}
