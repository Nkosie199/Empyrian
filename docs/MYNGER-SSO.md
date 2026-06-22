# Mynger SSO Integration — Empyrian

## Overview

Empyrian uses the [OpenID Connect Generic Client](https://wordpress.org/plugins/daggerhart-openid-connect-generic/) WordPress plugin (v3.11.3) to allow users to sign in with their Mynger account via OAuth 2.0 / OIDC. The Mynger backend at `api.mynger.com` acts as the identity provider.

---

## How it works

1. User clicks **"Sign in with Mynger"** on the Empyrian login page.
2. WordPress redirects to `https://api.mynger.com/oauth/authorize` with a `state` nonce.
3. If the user has an active Mynger SSO session (`mynger_sso` cookie) they see a consent screen; otherwise they log in with email + password.
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
| Identity Key | `email` | Which userinfo claim is used as the stable user identity |
| Identify with Username | **unchecked** | Links existing WP accounts by email, not by login name |
| Link Existing Users | **checked** | Matches Mynger users to existing WP accounts by email |
| Create if does not exist | **checked** | Creates a new WP account for first-time Mynger users |
| Redirect user back | **checked** | Returns user to the page they came from after login |
| Email Format | `{email}` | |
| Display Name Format | `{username}` | |
| Nickname Key | `username` | |
| State Time Limit | `600` | Seconds; increased from default 180 to handle slow connections |

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

**Root cause (second occurrence, main fix):** `identify_with_username` was **checked**, so `link_existing_users` tried to find an existing WP account via `get_user_by('login', subject_identity)`. When `identity_key = email`, this looked up users by their email address as a login name — a query that always returns nothing, since WP login names are not email addresses. With no match, WordPress fell through to `wp_insert_user`, which failed because the email was already registered.

**Fix:** 
- Set `identity_key = email` — the user's Mynger email becomes the stable identity.
- **Uncheck** `identify_with_username` — the plugin now links existing WP accounts via `get_user_by('email', ...)` instead of by login name.
- This means: on first SSO login, if a WP account already exists with the same email as the Mynger account, they are automatically linked. New users get a fresh WP account with their email as the login.

---

## Mynger backend changes

All changes are on the `feature/empyrian-oauth-fixes` branch of `mynger-backend`.

| File | Change |
|---|---|
| `OAuthController.java` | Rewrote `/oauth/userinfo` to separate JWT validation from DB lookup; added JWT-claim fallback; added `Cache-Control: no-store` to authorize HTML responses |
| `OAuthService.java` | Added `name` claim to `getUserinfo()` and `issueTokens()`; added `parseAccessTokenClaims()` helper |
