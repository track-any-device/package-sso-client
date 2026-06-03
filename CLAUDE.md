# package-sso-client — AI Instructions

This is the **OAuth2 SSO consumer package** for the Track Any Device platform.
Packagist: `track-any-device/sso-client` | Namespace: `TrackAnyDevice\SsoClient\`

This package is installed in every server app that authenticates users via SSO:
`server-tenant`, `server-admin`, `server-graphql`. It handles the OAuth2 callback,
exchanges the code for a Passport access token, and establishes a local session.

Read this file before making any change.

---

## Platform-Wide Rules

These three rules apply in every repository under the `track-any-device` organisation.

**Cross-repo changes: file a GitHub issue first.**
If a task in this repository requires a change in another package or server app — stop. Open a
GitHub issue in the target repository describing exactly what is needed and why. Reference that
issue number in your commit message (`ref track-any-device/{repo}#{n}`). Do not directly edit
files in another repository. When picking up a cross-repo issue, run Claude locally inside that
repository's working directory and work only within its scope.

**Release order: packages before server apps.**
This package depends on `package-core`. Release order: `package-core → package-sso-client →
server-tenant, server-admin, server-graphql (parallel)`.
Do not depend on `package-sso-server` — clients must not know about the server internals.

**Database layer lives in `package-core` only.**
No migrations or model classes here. The `SsoToken` audit table is in `package-core`.

---

## Rule 1 — Plan before implementing

Before writing any code, ask clarifying questions. Present a plan and get explicit agreement.
Only begin once the approach is confirmed.

---

## What lives in this package

| Class/File | Purpose |
|---|---|
| `SsoClientServiceProvider` | Registers Socialite `sso` driver, reads OAuth client from DB by `APP_SURFACE` |
| `SsoCallbackController` | Exchanges code → access token → fetches `/api/sso/user` → `Auth::login()` |
| `SsoRedirectController` | Initiates `Socialite::driver('sso')->redirect()` |
| `SsoClient::routes()` | Mounts `/sso/redirect` and `/sso/callback` |

---

## Rule 2 — `APP_SURFACE` drives which OAuth client is loaded

`SsoClientServiceProvider` reads `APP_SURFACE` from the environment and looks up the
matching `oauth_clients` row by kind. Valid values:

| `APP_SURFACE` | OAuth client kind | Consumer app |
|---|---|---|
| `tenant` | `OAuthClientKind::Tenant` | `server-tenant` |
| `admin` | `OAuthClientKind::Admin` | `server-admin` |
| `graphql` | `OAuthClientKind::GraphQl` | `server-graphql` |

Never hardcode client credentials — they are read from the database at boot time.

---

## Rule 3 — 2FA freshness is carried across the SSO boundary

`SsoCallbackController` reads `sms_2fa_verified` from the SSO user payload and writes it
into the local session after login. This prevents re-prompting for SMS 2FA on every surface.
Do not remove this session key assignment.

---

## SSO Flow (consumer side)

```
GET /sso/redirect
  → Socialite::driver('sso')->redirect()
  → login.*/oauth/authorize
  → user authenticates
  → redirect to /sso/callback?code=...

GET /sso/callback
  → Socialite::driver('sso')->stateless()->user()
  → exchanges code for Passport token
  → GET login.*/api/sso/user (Bearer token)
  → Auth::login($user)
  → redirect to intended URL
```

---

## Dependencies

```
track-any-device/core
laravel/socialite ^5
```

---

## Versioning

Tags are created automatically on merge to `main`. Default bump is `patch`.
