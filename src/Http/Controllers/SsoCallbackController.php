<?php

namespace TrackAnyDevice\SsoClient\Http\Controllers;

use Illuminate\Routing\Controller;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use TrackAnyDevice\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Tenant-side OAuth2 callback — exchanges a Passport authorization code
 * for an access token via Socialite, then establishes a local session.
 *
 *   GET /sso/callback?code=AUTH_CODE&state=STATE
 *
 *   1. Resolve this tenant's OAuthClient to get client credentials.
 *   2. Socialite exchanges the code for a Passport access token via
 *      POST /oauth/token on the central (login.*) host.
 *   3. Socialite fetches the authenticated user from /api/sso/user.
 *   4. Auth::login establishes the tenant-scoped session.
 *
 * Uses stateless() so we don't need Socialite-managed state in session —
 * state validation was already handled by the authorize endpoint.
 *
 * AuthorizeTenantAccess must exempt /sso/callback so this route is
 * reachable by unauthenticated guests completing the SSO handshake.
 */
class SsoCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $client = $this->resolveTenantClient();

        if (! $client) {
            return redirect('/')->with('errors_sso', 'This tenant is not configured for SSO.');
        }

        try {
            // Configure Socialite with this tenant's OAuth2 client credentials
            // and exchange the authorization code for an access token + user.
            $socialiteUser = Socialite::driver('sso')
                ->withConfig([
                    'client_id' => $client->client_id,
                    'client_secret' => $client->attributes['client_secret_hash'], // raw hash used as secret
                    'redirect' => $client->permitsRedirectUri(url('/sso/callback'))
                        ? url('/sso/callback')
                        : collect($client->redirect_uris ?? [])->first(''),
                    'server_url' => config('services.sso.server_url'),
                ])
                ->stateless()
                ->user();
        } catch (\Throwable) {
            return redirect('/')->with('errors_sso', 'The sign-in link is invalid or has expired. Please try again.');
        }

        /** @var User|null $user */
        $user = User::find($socialiteUser->getId());

        if (! $user) {
            return redirect('/')->with('errors_sso', 'The sign-in link is invalid or has expired. Please try again.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Carry the OTP freshness across the SSO boundary so users who
        // already completed 2FA on the central host are not re-challenged.
        if ($this->userHasFreshOtp($user)) {
            $request->session()->put('sms_2fa_verified', true);
        }

        return redirect()->intended('/dashboard');
    }

    private function userHasFreshOtp(User $user): bool
    {
        return $user->last_otp_validated_on !== null
            && $user->last_otp_validated_on->isAfter(now()->subMinutes(15));
    }

    private function resolveTenantClient(): ?OAuthClient
    {
        if (! function_exists('tenancy') || ! tenancy()->tenant) {
            return null;
        }

        return OAuthClient::query()
            ->where('tenant_id', tenancy()->tenant->getKey())
            ->where('is_active', true)
            ->first();
    }
}
