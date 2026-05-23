<?php

namespace TrackAnyDevice\SsoClient\Socialite;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * Socialite driver for the Track Any Device SSO server (Passport-backed).
 *
 * Implements the OAuth2 authorization-code flow against the central
 * login host:
 *
 *   1. redirect() → sends the browser to login.{APP_DOMAIN}/oauth/authorize
 *   2. user()     → in the callback, exchanges the code for an access
 *                   token via POST login.{APP_DOMAIN}/oauth/token, then fetches
 *                   the authenticated user from login.{APP_DOMAIN}/api/sso/user.
 *
 * Configuration (config/services.php or dynamic per-tenant via withConfig):
 *
 *   'sso' => [
 *       'client_id'     => env('SSO_CLIENT_ID'),
 *       'client_secret' => env('SSO_CLIENT_SECRET'),
 *       'redirect'      => env('SSO_REDIRECT_URI'),
 *       'server_url'    => env('SSO_SERVER_URL'),  // e.g. https://login.example.com
 *   ],
 *
 * For per-tenant clients the controller resolves credentials dynamically
 * and passes them via withConfig() before calling user().
 */
class SsoProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes to request. An empty scope list is valid for the
     * authorization-code grant when the server issues opaque tokens —
     * the /api/sso/user endpoint enforces its own access control.
     *
     * @var string[]
     */
    protected $scopes = [];

    protected $scopeSeparator = ' ';

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    private function serverUrl(): string
    {
        return rtrim((string) ($this->config['server_url'] ?? config('services.sso.server_url', '')), '/');
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->serverUrl().'/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->serverUrl().'/oauth/token';
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * Passport's token endpoint requires `grant_type`, `client_id`,
     * `client_secret`, `redirect_uri`, and `code`.
     *
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * Fetch the authenticated user from the SSO server's userinfo endpoint.
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(
            $this->serverUrl().'/api/sso/user',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
            ],
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Map the raw user array (returned by /api/sso/user) to a Socialite User.
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }
}
