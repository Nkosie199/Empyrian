# Mynger SSO Integration — Empyrian WordPress

**Set up:** 12 June 2026  
**Plugin used:** OpenID Connect Generic Client by daggerhart (v3.x, WordPress.org)  
**Status:** 🔧 WP side complete. Mynger frontend needs an OAuth consent page before the flow works end-to-end.

---

## What was done

1. Installed **OpenID Connect Generic Client** from WordPress.org (safe, no custom code)
2. Registered **Empyrian as an OAuth client** on the Mynger API via `POST /oauth/clients` (unauthenticated registration endpoint — RFC 7591)
3. Configured all OIDC endpoints in WP Admin → Settings → OpenID Connect

---

## OAuth Client Credentials

| Field | Value |
|-------|-------|
| Client ID | `empyrian-wp` |
| Client Secret | `EmpyrianWP_yrijj6i8dczkuwgg` |
| Registered on Mynger | 2026-06-12T21:45:45 |

---

## OIDC Endpoint Configuration

| Setting | Value |
|---------|-------|
| Login Endpoint | `https://mynger.com/oauth/authorize` |
| Token Endpoint | `https://mynger.com/oauth/token` |
| Userinfo Endpoint | `https://api.mynger.com/api/auth/me` |
| End Session Endpoint | `https://mynger.com/oauth/revoke` |
| JWKS URI | `https://api.mynger.com/.well-known/jwks.json` |
| Issuer | `https://mynger.com` |
| Scope | `identity` |
| Identity Key | `username` |
| Nickname Key | `username` |
| Login Button Text | `Sign in with Mynger` |

---

## WordPress User Settings

- Link existing users: **enabled** (matches by identity key)
- Create user if not exists: **enabled** (JIT provisioning)
- Redirect back to origin: **enabled**
- Redirect on logout: **enabled**
- Identify with username: **enabled**

---

## Redirect URI (register this on Mynger if not already done)

```
https://empyrian.net/wp-admin/admin-ajax.php?action=openid-connect-authorize
```

This URI was used during client registration above.

---

## How to test

1. Open a private/incognito window
2. Go to `https://empyrian.net/wp-login.php` (button confirmed here ✅)
3. You will see a **"Sign in with Mynger"** button above the standard login form
4. Click it — you'll be redirected to `https://mynger.com/oauth/authorize`
5. Log in with your Mynger credentials
6. On success, you'll be redirected back to Empyrian and auto-logged in

> Note: The front-end custom login page at `/login/` uses a theme template that doesn't fire
> the `login_form` hook. To add the button there too, add `[openid_connect_generic_login_button]`
> shortcode to the Sign In page content in WP Admin → Pages → Sign In.

---

## Discovery document

`https://api.mynger.com/.well-known/openid-configuration`

Confirmed live. Issuer: `https://mynger.com`, Algorithm: RS256, Key ID: `mynger-2026-01`

---

## Current status of the OAuth flow (as of 13 June 2026)

The WordPress side is fully configured. The flow breaks at step 2 because the Mynger frontend
doesn't have an OAuth consent/authorization page.

**What happens now:**
1. User clicks "Sign in with Mynger" → redirected to `https://mynger.com/oauth/authorize?...`
2. React app shows "page not found" (route not implemented) — user stuck on Mynger

**What needs to be built — Mynger frontend `/oauth/authorize` page:**

```
Route: /oauth/authorize
Reads from URL params: client_id, redirect_uri, scope, state, response_type
```

Flow:
1. If user not logged in → show Mynger login form → on success, continue
2. Look up client details: `GET /oauth/clients` to find `client_id` name/logo
3. Show consent UI: "Empyrian WordPress wants access to: identity"
4. "Allow" button → POST to `https://api.mynger.com/oauth/authorize/approve` with:
   - Authorization: Bearer {token}
   - Body: `{ clientId, redirectUri, scope, state, responseType: 'code' }`
5. Backend returns `{ redirectUri: "https://empyrian.net/...?code=ABC&state=test123" }`
6. Frontend does: `window.location.href = response.redirectUri`
7. Empyrian receives the code, exchanges it for tokens, logs the user in

**Backend endpoints already built (confirmed via Swagger):**
- `GET /oauth/clients` — list clients (public, no auth) — used to show client name/logo
- `POST /oauth/authorize/approve` — issues auth code (requires Bearer token)
- `POST /oauth/token` — exchanges code for access token (used by WP plugin)
- `GET /oauth/userinfo` → `GET /api/auth/me` — returns user profile

**Test the backend directly:**
```bash
# After implementing, test the full flow:
curl -X POST https://api.mynger.com/oauth/authorize/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"clientId":"empyrian-wp","redirectUri":"https://empyrian.net/wp-admin/admin-ajax.php?action=openid-connect-authorize","scope":"identity","state":"test","responseType":"code"}'
```

## Notes

- The Mynger `/oauth/clients` endpoint allows unauthenticated client registration (public)
- Scopes supported: `identity`, `wallet`, `leaderboard` — only `identity` needed for SSO
- The `username` claim in the Mynger userinfo response is the canonical user identifier
- WP admin at `https://empyrian.net/wp-admin/options-general.php?page=openid-connect-generic-settings`
- Registered client: `empyrian-wp` / secret: `EmpyrianWP_yrijj6i8dczkuwgg` (stored in WP options)
