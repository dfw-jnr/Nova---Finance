# NOVA Finance

Premium personal finance PWA — PHP 8+, MySQL/MariaDB, vanilla JS.

## Requirements

- PHP 8.1+ with PDO MySQL extension
- MySQL 8+ or MariaDB 10.5+
- HTTPS in production (required for iPhone PWA install)

## Project structure

```
nova-finance/
├── public/           # Web root (point your vhost here)
│   ├── index.php     # App shell (auth-gated)
│   ├── login.php / register.php
│   ├── api/          # REST endpoints
│   ├── assets/       # CSS, JS, icons
│   ├── manifest.json
│   ├── service-worker.js
│   └── offline.html
├── app/              # Private PHP (not web-accessible)
│   ├── config/       # env.php credentials
│   ├── helpers/
│   ├── services/
│   ├── repositories/
│   └── bootstrap.php
├── database/
│   ├── schema.sql
│   └── seed.sql
└── storage/          # Rate-limit data, etc.
```

## Local development (quick start)

PHP PDO drivers enabled, then from `public`:

```bash
cd public
php -S localhost:8080 router.php
```

Default local config uses **SQLite** (`storage/nova.sqlite`) so you can develop without MySQL.
For production, set `db.driver` to `mysql` in `app/config/env.php` and import `database/schema.sql`.

## Database setup (MySQL / MariaDB)

1. Create DB and tables:

```bash
mysql -u root -p < database/schema.sql
```

2. In `app/config/env.php`:

```php
'driver' => 'mysql',
'user' => '…',
'pass' => '…',
```

## First account

1. Open `/register.php`
2. Enter name, email, password (8+ chars), currency (EUR default)
3. You’re signed in with a default “Main” bank account

## Deploy (access from anywhere)

1. Push this repo to GitHub
2. Sign up at [render.com](https://render.com) → **New** → **Web Service** → connect the repo
3. Runtime: **Docker** (uses the included `Dockerfile`)
4. Set `APP_URL` to your HTTPS URL

### Keep your data (required)

Render wipes the container disk on every deploy, so **SQLite will lose all accounts**. Use a free MySQL database:

1. Create a free MySQL at [Aiven](https://console.aiven.io/signup) (no credit card)
2. Copy the service URI (`mysql://…`)
3. In Render → Environment, set:
   - `DB_DRIVER` = `mysql`
   - `DATABASE_URL` = your `mysql://…` URI
   - `DB_SSL` = `1`
4. Redeploy once — tables are created automatically; data survives future deploys

Free Render instances still sleep after idle (first open can take ~30s).

## Install on iPhone (like a real app)

1. Open your **HTTPS** URL in **Safari** (required)
2. Tap **Share** (square with arrow)
3. Tap **Add to Home Screen** → **Add**
4. Open **NOVA** from the home screen — no Safari chrome, fullscreen app mode

## Security considerations

- Passwords hashed with `password_hash()` / `password_verify()`
- CSRF tokens on mutating requests
- Session regeneration on login
- HttpOnly session cookies; set `secure => true` behind HTTPS
- Prepared statements (PDO) everywhere
- Row-level authorization (`user_id` checks) on all resources
- Idempotent offline sync via unique `client_id`
- Rate limiting on login/register
- No credentials under `public/`

## Offline sync

- App shell cached by the service worker
- Transactions created offline are stored in `localStorage` with a UUID `client_id`
- On reconnect, `/api/sync` upserts by `client_id` (no duplicates)

## Remaining production tasks

- [ ] Force HTTPS + HSTS
- [ ] Set strong DB password and restricted DB user
- [ ] Cron job to materialize `recurring_transactions`
- [ ] Email verification / password reset
- [ ] Backups and point-in-time recovery
- [ ] Structured logging (no sensitive payloads)
- [ ] Content-Security-Policy headers
- [ ] Penetration test & dependency audit
- [ ] App Store wrapper (Capacitor/WKWebView) if shipping native

## API shape

```json
{ "success": true, "data": {} }
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…" } }
```
