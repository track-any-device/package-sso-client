<?php

namespace TrackAnyDevice\SsoClient;

use Illuminate\Support\Facades\Route;
use TrackAnyDevice\SsoClient\Http\Controllers\SsoCallbackController;

/**
 * Register the SSO client callback route within the calling route group.
 *
 * Usage in routes/tenant.php (inside your tenant middleware group):
 *
 *   SsoClient::routes();
 *
 * This registers:
 *   GET  sso/callback  → SsoCallbackController  (tenant.sso.callback)
 *
 * AuthorizeTenantAccess must exempt /sso/callback so the callback can
 * be reached by unauthenticated guests completing the SSO handshake.
 */
class SsoClient
{
    public static function routes(): void
    {
        Route::get('sso/callback', SsoCallbackController::class)
            ->name('tenant.sso.callback');
    }
}
