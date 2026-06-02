# track-any-device/sso-client

OAuth2 SSO client for the Track Any Device platform — Socialite driver, callback controller, and route helper that integrate with `package-sso-server` (Passport-backed central auth).

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.3 |
| Laravel | ^13.7 |
| laravel/socialite | ^5.0 |
| track-any-device/core | ^0.0.2 |
| Stancl/Tenancy (host app) | ^3.x |

---

## Installation

```bash
composer require track-any-device/sso-client
```

Laravel's package auto-discovery registers `SsoClientServiceProvider` automatically.

Publish the config stub:

```bash
php artisan vendor:publish --tag=sso-client-config
```

---

## Environment variables

Add to the host app's `.env` (and `config/services.php`):

```dotenv
SSO_SERVER_URL=https://login.example.com   # base URL of the central SSO host
SSO_CLIENT_ID=                             # fallback for local dev (before DB is seeded)
SSO_CLIENT_SECRET=                         # fallback for local dev
SSO_REDIRECT_URI=https://tenant.example.com/sso/callback
APP_SURFACE=tenant                         # must match the `kind` column in oauth_clients
```

Map them in `config/services.php`:

```php
'sso' => [
    'server_url'    => env('SSO_SERVER_URL', ''),
    'client_id'     => env('SSO_CLIENT_ID', ''),
    'client_secret' => env('SSO_CLIENT_SECRET', ''),
    'redirect'      => env('SSO_REDIRECT_URI', ''),
],
```

And surface in `config/app.php` (or a custom config file — see issue #3):

```php
'surface' => env('APP_SURFACE', ''),
```

---

## Routes the host app must register

Call `SsoClient::routes()` inside the tenant route group so the callback is
scoped correctly. The route must be **exempt** from any `AuthorizeTenantAccess`
middleware that guards authenticated routes.

```php
// routes/tenant.php
use TrackAnyDevice\SsoClient\SsoClient;

Route::middleware(['web'])->group(function () {
    // Exempt from auth guard — guests hit this to complete the SSO handshake.
    SsoClient::routes();

    Route::middleware(['auth'])->group(function () {
        // ... authenticated tenant routes
    });
});
```

Registered route:

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/sso/callback` | `tenant.sso.callback` | `SsoCallbackController` |

---

## Phone verification on the authorize endpoint

On the login domain (where Passport is running), the service provider
automatically adds `phone.verified` middleware to Passport's
`/oauth/authorize` routes (GET, POST, DELETE). This prevents unverified
users from obtaining authorization codes and avoids a redirect loop between
app surfaces and the login domain.

The `phone.verified` middleware alias must be registered by the host
application (e.g. in `bootstrap/app.php`). The middleware is only applied
when the Passport routes exist — on tenant apps this is a safe no-op.

Phone setup routes (`phone.edit`, `phone.verify`, `phone.send`,
`phone.resend`, `logout`) are excluded inside the middleware itself and
remain accessible.

---

## Auth flow

```
Browser → GET /oauth/authorize (SSO server)
        ← 302 to /sso/callback?code=…&state=…  (tenant app)

Tenant  → POST /oauth/token  (SSO server — exchanges code for access token)
        ← { access_token, … }

Tenant  → GET /api/sso/user  (SSO server — fetches user payload)
        ← { id, name, email, … }

Tenant  → Auth::login($user)  +  session()->regenerate()
        → redirect()->intended('/dashboard')
```

1. The service provider registers a `sso` Socialite driver backed by `SsoProvider`.
2. On boot, it resolves OAuth2 client credentials from the `oauth_clients` table (column `kind` matches `APP_SURFACE`), falling back to `config/services.php` when the DB is unavailable (local dev).
3. `SsoCallbackController` handles the callback, logs the user in, and carries OTP freshness across the SSO boundary via the `sms_2fa_verified` session key.

### Session key written by this package

| Key | Type | Meaning |
|---|---|---|
| `sms_2fa_verified` | `bool` | Set to `true` when the user's `last_otp_validated_on` is within 15 minutes. Host-app 2FA middleware must read this key to skip re-challenge. |

### Flash key written on failure

| Key | Value |
|---|---|
| `errors_sso` | Human-readable error string |

Read in Blade: `@if(session('errors_sso')) … @endif`

---

## Release workflow convention

The release workflow (`.github/workflows/release.yml`) auto-tags and publishes a GitHub release on every push to `main`. It derives the version bump from conventional commit prefixes:

| Commit prefix | Bump |
|---|---|
| `feat!:` / `BREAKING CHANGE` | major |
| `feat:` | minor |
| `fix:`, `chore:`, `docs:`, `refactor:`, `perf:`, `style:`, `test:`, `ci:` | patch |

Manual dispatch lets you override the bump type (patch / minor / major) regardless of commit messages.

No `version` field is kept in `composer.json`; Packagist reads the git tag.
