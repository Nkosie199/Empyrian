# Cloudflare Setup — Empyrian

## Why Cloudflare

Same reasons as bonakude — edge caching, DDoS protection, free SSL, hides origin IP. Since empyrian.net is on Hostinger shared hosting, Cloudflare is even more valuable because shared hosting has strict CPU/bandwidth limits.

## Setup

Follow the same steps as [`bonakude/docs/CLOUDFLARE.md`](../../Bonakude/docs/CLOUDFLARE.md) with these differences:

### DNS records

| Type | Name | Value | Proxied |
|---|---|---|---|
| A | `@` | *(Hostinger IP — check cPanel → General Information)* | ✅ Yes |
| A | `www` | *(same Hostinger IP)* | ✅ Yes |

### Registrar

Find empyrian.net's registrar and update nameservers there. Check current nameservers:
```bash
nslookup -type=NS empyrian.net
```

### SSL/TLS mode

Set to **Full** (not Full Strict) unless Hostinger provides a valid cert. Hostinger typically provides Let's Encrypt certs via cPanel, in which case **Full (strict)** is correct.

### Page rules — bypass cache for BuddyPress

BuddyPress member pages are dynamic (activity streams, profiles). Add bypass rules:

```
empyrian.net/members/*    → Cache Level: Bypass
empyrian.net/wp-admin/*   → Cache Level: Bypass
empyrian.net/wp-login.php → Cache Level: Bypass
empyrian.net/login/*      → Cache Level: Bypass
```

### Bypass cache for OIDC/SSO flow

The Mynger SSO redirect flow must never be cached:

```
empyrian.net/?openid-connect-authorize* → Cache Level: Bypass
```

### Trust Cloudflare IPs in WordPress

Add to `wp-config.php` via Hostinger File Manager or cPanel:

```php
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}
```

## Expected result

| Metric | Before | After |
|---|---|---|
| Anonymous page load | 3–8s (Hostinger shared) | <100ms (Cloudflare edge) |
| Logged-in / member pages | 3–8s | 3–8s (bypassed, as expected) |
| Upload bandwidth | Counts against Hostinger quota | Served from Cloudflare |
