# Mynger SSO Integration — Empyrian

## Overview

Empyrian uses the [OpenID Connect Generic Client](https://wordpress.org/plugins/daggerhart-openid-connect-generic/) WordPress plugin (v3.11.3) to allow users to sign in with their Mynger account via OAuth 2.0 / OIDC. The Mynger backend at `api.mynger.com` acts as the identity provider.

---

## How it works

1. User clicks **"Sign in with Mynger"** on the Empyrian login page.
2. WordPress redirects to `https://api.mynger.com/oauth/authorize` with a `state` nonce.
3. If the user has an active Mynger SSO session (`mynger_sso` cookie) they see a consent screen; otherwise they log in with username/email + password.
4. Mynger redirects back to WordPress with an authorization code.
5. WordPress exchanges the code for tokens at `/oauth/token`.
6. WordPress fetches user claims from `/oauth/userinfo`.
7. WordPress links or creates a local WP user and logs them in.

---

## OAuth client registration

The Empyrian client is registered in the Mynger backend with:

| Field | Value |
|---|---|
| `client_id` | `empyrian-wp` |
| `redirect_uri` | `https://empyrian.net/wp-admin/admin-ajax.php?action=openid-connect-authorize` |
| `scope` | `identity` |

---

## WordPress plugin settings

Navigate to **Settings → OpenID Connect Client** in WP admin (`/wp-admin/options-general.php?page=openid-connect-generic-settings`).

| Setting | Value | Notes |
|---|---|---|
| Login Type | Button on login form | Shows "Sign in with Mynger" button |
| Login Button Text | `Sign in with Mynger` | |
| Client ID | `empyrian-wp` | |
| Client Secret | *(see WP admin)* | |
| Scope | `identity` | |
| Login Endpoint | `https://api.mynger.com/oauth/authorize` | |
| Userinfo Endpoint | `https://api.mynger.com/oauth/userinfo` | |
| Token Endpoint | `https://api.mynger.com/oauth/token` | |
| End Session Endpoint | `https://api.mynger.com/api/auth/logout` | |
| JWKS Endpoint | `https://api.mynger.com/oauth/jwks` | |
| Issuer | `https://mynger.com` | Must match JWT `iss` claim |
| Identity Key | `preferred_username` | Which userinfo claim is used as the stable user identity |
| Identify with Username | **checked** | Links existing WP accounts by login name |
| Link Existing Users | **checked** | Matches Mynger users to existing WP accounts |
| Create if does not exist | **checked** | Creates a new WP account for first-time Mynger users |
| Redirect user back | **checked** | Returns user to the page they came from after login |
| Email Format | `{email}` | |
| Display Name Format | `{username}` | |
| Nickname Key | `username` | |
| State Time Limit | `600` | Seconds; increased from default 180 to handle slow connections |

### How user linking works (2026-06-22 update)

With `identity_key = preferred_username` and `identify_with_username = true`:

1. Plugin gets `preferred_username` claim from Mynger userinfo (= Mynger account username)
2. Looks up WP user with `openid-connect-generic-subject-identity` user meta = that value
3. If not found: falls back to `get_user_by('login', preferred_username)`
4. On match: stores subject meta for fast lookup on future logins

This is consistent with how bonakude is configured and avoids the email-mismatch issues documented under "Key bugs fixed" below.

---

## Claude admin user

| Field | Value |
|---|---|
| WP user ID | 638 |
| WP login | `claude` |
| WP email | `claude.superai@gmail.com` |
| Mynger account email | `claude.superai+bonakude@gmail.com` |
| Mynger username | `claude` |
| WP subject meta | set automatically on first SSO login |

The Mynger `claude` account (same account used for bonakude) links to the empyrian WP user by username match. One Mynger account, two linked WP sites.

---

## Normal user auth flows

Empyrian uses the same `play-block` plugin REST endpoint as bonakude for all normal-user auth actions.

### Registration

```
POST /wp-json/play/auth
form-action: register
user_login:  <username>
user_email:  <email>
```

No email activation required — user can log in immediately after registration.

### Login

```
POST /wp-json/play/auth
form-action: login
log:         <username or email>
pwd:         <password>
nonce:       <nonce from /login/ page>
```

### Forgot Password (lostpwd)

```
POST /wp-json/play/auth
form-action: lostpwd
user_login:  <username or email>
nonce:       <nonce from /login/ page>
```

### Reset Password (resetpwd)

```
POST /wp-json/play/auth
form-action: resetpwd
rp_key:      <key from reset email>
rp_login:    <username>
pwd:         <new password>
nonce:       <nonce from /login/ page>
```

### E2E test results (2026-06-22)

| Flow | Result |
|---|---|
| SSO: `/login/` → Mynger → `/user/claude/` | ✓ |
| Register new user | ✓ |
| Login with correct credentials | ✓ |
| Login with wrong credentials (rejected) | ✓ |
| lostpwd → success email | ✓ |
| Password change → new password accepted, old rejected | ✓ |
| Duplicate username rejected at registration | ✓ |
| resetpwd with invalid key rejected | ✓ |

---

## Key bugs fixed and why

### 1. `incomplete-user-claim`

**Symptom:** WordPress showed `incomplete-user-claim` error.

**Cause:** The `/oauth/userinfo` endpoint was returning a JSON error body (`{"error":"invalid_token"}`) with a 401 status. WordPress parsed the body as user claims, found no `username` field, and rejected it.

**Fix (backend):** Rewrote `OAuthController.userinfo()` to:
- Return a bare 401 (no body) for invalid/missing JWT.
- If the JWT is valid but the user record is missing from DB, return a minimal claim set derived from the JWT itself rather than hitting the DB.

### 2. `invalid-state`

**Symptom:** WordPress showed `invalid-state` error after the OAuth redirect.

**Cause:** The browser had cached the Mynger consent/login HTML page. Reloading it resubmitted the old `state` value, which no longer matched the fresh transient WordPress had stored.

**Fix (backend):** Added `Cache-Control: no-store` and `Pragma: no-cache` response headers to both the consent and login HTML pages served by `/oauth/authorize`.

**Fix (WordPress):** Increased `state_time_limit` from 180 s to 600 s to give more headroom on slow connections.

### 3. `failed-user-creation`

**Symptom:** WordPress showed `failed-user-creation` error.

**Root cause (first occurrence):** `identity_key` was set to `username`. WordPress tried to create a new WP user with `user_login = "claude"` (the Mynger username), but a WP account with that login already existed.

**Root cause (second occurrence):** `identify_with_username` was **checked** while `identity_key = email`. The plugin called `get_user_by('login', email_address)` — email addresses are never stored as WP login names, so this always returned nothing. WordPress then tried `wp_insert_user`, which failed because the email was already registered.

**Fix (2026-06-22):** Switched to `identity_key = preferred_username` with `identify_with_username = true`. The plugin now correctly calls `get_user_by('login', mynger_username)`, which finds existing WP accounts by their login name. This matches bonakude's approach.

---

## Mynger backend changes

All changes are on the `feature/empyrian-oauth-fixes` branch of `mynger-backend`.

| File | Change |
|---|---|
| `OAuthController.java` | Rewrote `/oauth/userinfo` to separate JWT validation from DB lookup; added JWT-claim fallback; added `Cache-Control: no-store` to authorize HTML responses |
| `OAuthService.java` | Added `name` claim to `getUserinfo()` and `issueTokens()`; added `parseAccessTokenClaims()` helper |
