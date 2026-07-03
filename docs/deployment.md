# Deployment playbook

This guide covers the four long-running processes Nexus needs
supervised, an Nginx reference config, and the post-deploy checklist.
It assumes you've already worked through
[`docs/installation.md`](installation.md).

## Process topology

Four processes run alongside each other on a single-host deploy:

| Process | Command | Owns |
|---------|---------|------|
| PHP-FPM | (apt-managed) | HTTP requests routed from Nginx |
| Horizon | `php artisan horizon` | All queued jobs |
| Scheduler | `php artisan schedule:work` | Cron-driven jobs (website probes, alert evaluators, offline detectors) |
| Reverb | `php artisan reverb:start` | Websocket connections |

PHP-FPM ships as a systemd unit in the `php8.4-fpm` package — no
custom config needed. Horizon / scheduler / Reverb are the three you
supervise yourself.

Pick **one** supervisor stack: Supervisor OR systemd. Don't mix.
Supervisor is simpler when your team is already used to it (it groups
the three processes under one status view); systemd is native on
modern Linux and integrates with `journalctl` for logs.

## Option A — Supervisor

Install:

```bash
sudo apt install supervisor
```

### `/etc/supervisor/conf.d/nexus-horizon.conf`

```ini
[program:nexus-horizon]
process_name=%(program_name)s
command=php /var/www/nexus/artisan horizon
directory=/var/www/nexus
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexus/horizon.log
stopwaitsecs=60
```

`stopwaitsecs=60` lets in-flight jobs finish before Horizon is killed
during a restart. Match it to your slowest job's expected wall time.

### `/etc/supervisor/conf.d/nexus-schedule.conf`

```ini
[program:nexus-schedule]
process_name=%(program_name)s
command=php /var/www/nexus/artisan schedule:work
directory=/var/www/nexus
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexus/schedule.log
```

`schedule:work` is Laravel's supervised-scheduler command — it loops
every 60s and dispatches whatever `routes/console.php` defines
(website probes, host-offline detection, alert-rule evaluator).
Traditional `cron * * * * * php artisan schedule:run` works too but
duplicates the responsibility with two supervisors.

### `/etc/supervisor/conf.d/nexus-reverb.conf`

```ini
[program:nexus-reverb]
process_name=%(program_name)s
command=php /var/www/nexus/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/nexus
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nexus/reverb.log
```

Reverb binds to `127.0.0.1:8080`; Nginx fronts it (see the WebSocket
upgrade block below).

### Load + start

```bash
sudo mkdir -p /var/log/nexus && sudo chown www-data:www-data /var/log/nexus
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
# nexus-horizon    RUNNING   pid ...
# nexus-schedule   RUNNING   pid ...
# nexus-reverb     RUNNING   pid ...
```

## Option B — systemd

Three unit files, all with `Restart=always` so a crash comes back up.

### `/etc/systemd/system/nexus-horizon.service`

```ini
[Unit]
Description=Nexus Horizon queue supervisor
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nexus
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5s
TimeoutStopSec=60
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

### `/etc/systemd/system/nexus-schedule.service`

```ini
[Unit]
Description=Nexus scheduler
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nexus
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

### `/etc/systemd/system/nexus-reverb.service`

```ini
[Unit]
Description=Nexus Reverb websocket server
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nexus
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

### Load + start

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nexus-horizon nexus-schedule nexus-reverb
sudo systemctl status nexus-horizon nexus-schedule nexus-reverb
journalctl -u nexus-horizon -f    # tail logs
```

## Nginx reference config

`/etc/nginx/sites-available/nexus`:

```nginx
server {
    listen 80;
    server_name nexus.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name nexus.example.com;

    ssl_certificate     /etc/letsencrypt/live/nexus.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/nexus.example.com/privkey.pem;

    root /var/www/nexus/public;
    index index.php;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/nexus.access.log;
    error_log  /var/log/nginx/nexus.error.log;
}

# Reverb websocket endpoint. Point VITE_REVERB_HOST at this hostname.
server {
    listen 443 ssl http2;
    server_name ws.nexus.example.com;

    ssl_certificate     /etc/letsencrypt/live/ws.nexus.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ws.nexus.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
    }
}
```

Enable + reload:

```bash
sudo ln -s /etc/nginx/sites-available/nexus /etc/nginx/sites-enabled/nexus
sudo nginx -t
sudo systemctl reload nginx
```

**Caddy operators.** Caddy's `php_fastcgi unix//run/php/php8.4-fpm.sock`
directive handles the FastCGI block, and its automatic HTTPS covers
the certificate setup. Follow the layout above — an app vhost + a
websocket vhost — and the mapping is straightforward.

## Post-deploy checklist

Run this after every deploy — a fresh install, a hotfix, or a routine
release.

```bash
cd /var/www/nexus

# 1. Pull the new code
git fetch --tags && git checkout <ref>

# 2. Rebuild dependencies (only if composer.lock / package-lock.json changed)
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. Migrate + refresh caches (optimize bundles config + route + event + view caches).
php artisan migrate --force
php artisan optimize

# 4. Restart the long-runners so they pick up the new code
sudo supervisorctl restart nexus-horizon nexus-schedule nexus-reverb
#   -- OR --
sudo systemctl restart nexus-horizon nexus-schedule nexus-reverb

# 5. Confirm horizon terminated cleanly and re-came-up
php artisan horizon:status
```

`php artisan horizon:terminate` (without `restart`) is Horizon's
own drain command — jobs get their `stopwaitsecs`/`TimeoutStopSec`
window, then the process exits and supervisor restarts it. Either
`supervisorctl restart` or explicit `horizon:terminate` works;
don't do both.

### Horizon allow-list gate

Before the `/horizon` dashboard is reachable, `HORIZON_ALLOW_LIST`
must be populated in `.env`. Empty list = zero access (fail closed).

See [`docs/security/operator-checklist.md`](security/operator-checklist.md)
§3 for the full sign-off.

### Zero-downtime deploys

Symlink-swap deployments (Envoyer / Deployer style) work out of the
box. The one caveat: `horizon:terminate` between shared-state
migrations. If a job class name or a serialized payload shape
changed, in-flight jobs on the old code will fail once the new code
lands. Drain Horizon before flipping the release symlink:

```bash
# In the release directory being retired:
php artisan horizon:terminate
# Wait for `queue:failed` to stabilize, then flip the symlink.
```

## Health checks + monitoring

Nexus exposes these endpoints for load-balancer + monitoring probes:

| Endpoint | Purpose | Recommended check |
|----------|---------|-------------------|
| `GET /up` | Laravel's built-in health check. Returns 200 unless the framework itself is broken. | External uptime monitor, 30-60s interval |
| `GET /horizon` | Horizon dashboard (auth-gated by `HORIZON_ALLOW_LIST`). | Manual — human operator check |
| `GET /alerts` | `AlertSource::System` alerts spec 038 fires when Nexus's own health degrades (queue backlog, GitHub rate limit, webhook failure rate, agent ingestion failure rate). | Include in your ops dashboard |
| Settings → System health card | Renders the same self-monitoring signals inline for a quick glance. | UI-only |

If you want a text-only endpoint for a load balancer, `GET /up` is
the one to point at.

## Rollback

Symlink-swap deploys make rollback trivial — flip the current
release symlink back to the previous directory and restart the
long-runners. Migrations that ran on the new release may need
manual rollback if they're not backwards-compatible; keep
schema-only migrations reversible for exactly this reason (add a
column with a default in one release, populate + backfill in the
next, drop the default only after the deploy is stable).

## Next steps

- [Backup strategy](backup.md) — DB dumps, `.env` handling,
  restore drill.
- [Security operator checklist](security/operator-checklist.md) —
  final sign-off before traffic.
