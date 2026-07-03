# Installation

This guide walks through standing up Nexus Control Center on a fresh
Linux server. If you're setting up for local development, read the
[Local development](../README.md#local-development) section of the
README instead — it's a shorter path aimed at solo work on macOS or
Linux with SQLite.

Nexus is a standard Laravel application with three long-running side
processes (queue worker, scheduler, websocket server), so any
supervisor / systemd setup that can host Laravel will work. This guide
uses Debian/Ubuntu commands as the reference; adapt to your
distribution as needed.

## 1. System requirements

**Operating system.** Debian 12+ or Ubuntu 22.04+ recommended. Any
distribution with the components below works.

**PHP 8.4 or newer**, including these extensions:

- `bcmath`, `intl`, `mbstring`, `pcntl`, `curl`, `zip`
- Database driver: `mysql` or `pgsql`
- `redis` (the PECL extension) — cache / queue / session driver

Confirm with `php -m` after install.

**Database** — one of:

- MySQL 8.0+
- PostgreSQL 15+

SQLite is a dev convenience only. Production installs must run a
networked database engine so Horizon workers, the Reverb websocket
server, and the scheduler share state.

**Redis 7+** — required. Horizon queues, cache, session, and
throttling all key off Redis.

**Node.js 20+** on the deploy host — needed only to compile assets
with `npm run build` during deploys. Not needed at runtime.

**Web server** — Nginx (reference) or Caddy in front of PHP-FPM. The
deployment guide ships an Nginx config; Caddy users adapt.

**Process supervisor** — either Supervisor OR systemd. Both are
documented in `docs/deployment.md`. Pick one and stay consistent.

## 2. Provision the host

On Debian/Ubuntu:

```bash
sudo apt update
sudo apt install -y \
    php8.4-{cli,fpm,bcmath,intl,mbstring,pcntl,curl,zip,mysql,pgsql,redis} \
    mysql-server redis-server \
    nginx supervisor \
    git unzip

# Composer (system-wide)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Verify:

```bash
php --version         # PHP 8.4.x
composer --version    # Composer 2.x
node --version        # v20.x
mysql --version       # 8.0+   (or:  psql --version → 15+)
redis-cli ping        # PONG
```

## 3. Create the database

MySQL:

```sql
CREATE DATABASE nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nexus'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON nexus.* TO 'nexus'@'localhost';
FLUSH PRIVILEGES;
```

PostgreSQL:

```sql
CREATE DATABASE nexus;
CREATE USER nexus WITH ENCRYPTED PASSWORD 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT ALL PRIVILEGES ON DATABASE nexus TO nexus;
```

## 4. Fetch the code

Deploy directory convention: `/var/www/nexus`. Adapt if you have a
different layout.

```bash
sudo mkdir -p /var/www/nexus
sudo chown "$USER":"$USER" /var/www/nexus
cd /var/www/nexus
git clone https://github.com/Copxer/nexus.git .
git checkout main    # or the tagged release you're deploying
```

## 5. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build         # emits public/build/*
```

`--no-dev` skips PHPUnit + Pint + dev-only dependencies. `npm ci`
respects the committed `package-lock.json` — never use `npm install`
on a production host (it can silently upgrade dependencies).

## 6. Configure the environment

Copy the production template + edit:

```bash
cp docs/env.production.example .env
$EDITOR .env
```

At minimum, replace every `CHANGEME` value:

- `APP_URL` — the public origin, e.g. `https://nexus.example.com`
- `DB_HOST`, `DB_PASSWORD` — the DB credentials from step 3
- `REDIS_HOST`, `REDIS_PASSWORD` — Redis credentials
- `HORIZON_ALLOW_LIST` — comma-separated verified-user emails
  allowed at `/horizon` (see
  [`docs/security/operator-checklist.md`](security/operator-checklist.md)
  §3). Empty list = zero access.
- `REVERB_APP_KEY` and `REVERB_APP_SECRET` — random 32+ char
  strings; the websocket auth flow uses them.
- `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`,
  `GITHUB_WEBHOOK_SECRET` — from a GitHub App or OAuth App
  registered against your account/org. See the
  [GitHub App setup](../README.md#github-app-setup) section of
  the README for how to register the app.
- Mail settings — verification + password-reset emails route
  through here.

Generate the application key **once**:

```bash
php artisan key:generate
```

**`APP_KEY` matters beyond signing cookies.** It's the encryption key
for the `github_connections.access_token` +
`github_connections.refresh_token` columns (spec 039). If you lose
`APP_KEY`, every stored OAuth token becomes unreadable — operators
have to reconnect GitHub from each account. See
[`docs/backup.md`](backup.md) §2 for the backup strategy.

## 7. Run migrations + build caches

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

`optimize` bundles `config:cache`, `route:cache`, `event:cache`, and
`view:cache`. Rerun after any deploy that touches
`config/`, `routes/`, or `resources/views/`.

`--force` on migrate is required in production (Laravel refuses
non-interactive `migrate` without it).

## 8. Long-running processes

Nexus needs four processes supervised for full functionality:

| Process | Command | Purpose |
|---------|---------|---------|
| PHP-FPM | (managed by systemd + apt) | HTTP requests |
| Horizon | `php artisan horizon` | Queued jobs (GitHub sync, alerts, webhooks) |
| Scheduler | `php artisan schedule:work` | Cron — website probes, alert evaluators, offline-host detection |
| Reverb | `php artisan reverb:start` | Websocket broadcasts (activity feed, live deployments, alerts) |

Configuring these is [`docs/deployment.md`](deployment.md). Skip to
that guide next.

## 9. Verify

Before pointing traffic at the host:

```bash
php artisan about                                   # env / driver summary
php artisan migrate:status                          # every migration is Yes
php artisan queue:failed                            # empty
php artisan horizon:status 2>/dev/null || true      # requires Horizon running
```

Then browse to `APP_URL`, register a user, verify the email, and
confirm the Overview page loads.

## Next steps

- [Deployment playbook](deployment.md) — Supervisor + systemd units,
  Nginx config, post-deploy checklist.
- [Backup strategy](backup.md) — DB dumps, `.env` handling, restore
  drill.
- [Security operator checklist](security/operator-checklist.md) —
  Horizon allow-list, secrets audit, rate-limit coverage.
