# Docker Setup — Empyrian

## Overview

```
docker/
├── docker-compose.yml          # base (dev + migration)
├── docker-compose.prod.yml     # production overlay (Nginx + SSL)
├── .env.example
└── config/
    ├── apache/000-default.conf
    ├── php/uploads.ini
    ├── mysql/memory.cnf
    └── nginx/default.conf
```

## Prerequisites

- Docker 24+ and Docker Compose v2

## Migrating the live site into Docker

Empyrian is hosted on Hostinger. Export via WordPress admin or cPanel.

### Step 1 — Export from Hostinger

**Option A — WordPress admin:**
1. Install "All-in-One WP Migration" plugin on empyrian.net
2. Export → download the `.wpress` file

**Option B — cPanel/SSH (if Hostinger grants it):**
```bash
# Database
mysqldump -u DB_USER -p DB_NAME > empyrian-$(date +%Y%m%d).sql

# wp-content (skip cache)
tar --exclude='wp-content/cache' -czf empyrian-wpcontent-$(date +%Y%m%d).tar.gz wp-content
```

### Step 2 — Configure environment

```bash
cd docker/
cp .env.example .env
# Edit .env — match DB credentials to your export
```

### Step 3 — Start containers

```bash
docker compose up -d
```

### Step 4 — Import database

```bash
docker compose exec -T db mariadb -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" < ../empyrian-YYYYMMDD.sql
```

### Step 5 — Restore wp-content

```bash
docker compose cp ../empyrian-wpcontent-YYYYMMDD.tar.gz wordpress:/tmp/
docker compose exec wordpress bash -c "tar -xzf /tmp/empyrian-wpcontent-YYYYMMDD.tar.gz -C /var/www/html"
docker compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content
```

### Step 6 — Update site URL

```bash
docker compose exec db mariadb -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -e "
  UPDATE wp_options SET option_value='https://NEW_DOMAIN' WHERE option_name IN ('siteurl','home');
"
```

### Step 7 — Verify

Visit `http://localhost:8081`

## Running in production

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm certbot
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

## Empyrian-specific notes

| Item | Detail |
|---|---|
| Theme | Musik (custom child theme in `musik-child-updated/`) |
| Key plugins | BuddyPress, daggerhart OIDC (Mynger SSO), All-In-One Security |
| SSO config | See `docs/MYNGER-SSO.md` — identity_key=preferred_username, OIDC client ID=empyrian-wp |
| No WP Super Cache | Not installed on empyrian; AIOS security blocks Code Snippets admin anyway |
| Registration | No email activation required (BuddyPress handles profiles) |
| AIOS | All-In-One Security plugin — blocks `/wp-admin/admin.php?page=snippet` for non-superadmin. This is expected behaviour, not a bug. |

## After migration: re-link Mynger SSO

The OIDC plugin settings are stored in the DB and will migrate automatically. After moving to a new domain, update these two values in WP admin → Settings → OpenID Connect:

- Redirect URI: `https://NEW_DOMAIN/wp-admin/admin-ajax.php?action=openid-connect-authorize`
- Then update the redirect URI on the Mynger OAuth client (`empyrian-wp`) at api.mynger.com
