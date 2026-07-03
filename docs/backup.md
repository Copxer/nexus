# Backup strategy

A restore drill you haven't rehearsed isn't a backup. This doc
covers what to back up, how, where to store copies, and — most
importantly — a quarterly restore rehearsal so the plan doesn't
rot silently.

## What to back up

Two things must be preserved:

1. **The database** — every piece of state the app persists.
2. **`.env`** — carries `APP_KEY`, which is the encryption key for
   `github_connections.access_token` and
   `github_connections.refresh_token`.

Everything else can be rebuilt from `git` + `composer install` +
`npm run build`.

## What NOT to back up

Skip these to keep dumps small + safe:

- `storage/framework/cache/` — regenerable
- `storage/framework/sessions/` — Redis-backed anyway
- `storage/framework/views/` — regenerable via `view:cache`
- `storage/logs/` — nice to keep somewhere for postmortems, but
  not part of the restore path
- `node_modules/`, `vendor/`, `public/build/` — all rebuildable
- Redis itself — cache/queue only, all recoverable from the DB
  and the code

## 1. Database dumps

### MySQL

```bash
mysqldump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --databases nexus \
    | gzip -c > /var/backups/nexus/db-$(date -u +%Y-%m-%d).sql.gz
```

`--single-transaction` gives a consistent snapshot on InnoDB
without locking writers. `--quick` streams rows instead of buffering
whole tables in memory.

### PostgreSQL

```bash
PGPASSWORD=$DB_PASSWORD pg_dump \
    --host=$DB_HOST \
    --username=$DB_USERNAME \
    --dbname=nexus \
    --format=custom \
    --serializable-deferrable \
    --file=/var/backups/nexus/db-$(date -u +%Y-%m-%d).dump
```

`--serializable-deferrable` gives an MVCC-consistent snapshot
without blocking writers. `custom` format supports parallel restore
via `pg_restore -j`.

### Nightly cron

Add to `/etc/cron.d/nexus-backup`:

```
0 3 * * * root /usr/local/bin/nexus-backup-db.sh
```

Where `/usr/local/bin/nexus-backup-db.sh` runs the dump command
above + rotates old files. A 30-day retention on-box is a common
default; adjust to your RPO.

```bash
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR=/var/backups/nexus
mkdir -p "$BACKUP_DIR"

# ...your dump command from above...

# Rotate: keep 30 days
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +30 -delete
find "$BACKUP_DIR" -name 'db-*.dump'   -mtime +30 -delete
```

## 2. `.env` handling

**The `.env` file needs a different strategy than the database.**

`APP_KEY` inside is what decrypts stored GitHub OAuth tokens
([spec 039](../specs/phase-9-polish/039-security-audit.md), see
also [operator-checklist §1](security/operator-checklist.md)).
Losing it means every user has to reconnect GitHub after restore —
recoverable, but the operational cost is real.

### Encrypt before shipping off-box

At minimum, don't ship `.env` in cleartext. Two workable shapes:

**Option A — encrypt with a passphrase before upload:**

```bash
gpg --symmetric --cipher-algo AES256 \
    --output /var/backups/nexus/env-$(date -u +%Y-%m-%d).gpg \
    .env
```

**Option B — store `.env` in an ops secrets manager** (1Password,
Bitwarden, HashiCorp Vault, AWS Secrets Manager). Update whenever
you rotate a secret.

### Never store the plain `.env` next to the DB backup

A single stolen backup archive would give an attacker both the
ciphertext (encrypted GitHub tokens in the DB dump) and the key
(`APP_KEY` from `.env`). Keep the two artifacts in different
locations, ideally different providers.

## 3. Off-site retention

**Recommend:** a second copy in a different cloud region or
provider than the app runs in. A backup that shares the failure
domain of the app isn't a disaster recovery plan.

The mechanism doesn't matter — S3 / R2 / GCS bucket with
lifecycle rules, a nightly rsync to a second box, whatever your
ops team is already comfortable with. What matters is that the
copies aren't reachable from the same credentials that would be
compromised in a breach.

## 4. Restore drill

The full sequence for restoring onto a fresh host. Order matters:
`.env` (and `APP_KEY`) must be on-box **before** the DB restore, so
the encrypted GitHub token columns can be read when the app boots.

```bash
# 1. Provision the host per docs/installation.md steps 1-3.

# 2. Fetch the code into the empty deploy directory.
sudo mkdir -p /var/www/nexus
sudo chown "$USER":"$USER" /var/www/nexus
cd /var/www/nexus
git clone https://github.com/Copxer/nexus.git .    # or your fork
git checkout <deployed-tag>

# 3. Restore .env into the deploy directory (BEFORE the DB restore,
#    so APP_KEY is available to decrypt the github_connections rows).
sudo cp /path/to/env-YYYY-MM-DD.decrypted /var/www/nexus/.env
sudo chown www-data:www-data /var/www/nexus/.env
sudo chmod 640 /var/www/nexus/.env

# 4. Install deps.
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 5. Restore the database.
#    The MySQL dump embeds `CREATE DATABASE`/`USE nexus` via
#    `--databases`, so the target DB name comes from the dump itself
#    -- restoring into a different DB name requires stripping those
#    lines from the .sql first.

# MySQL
gunzip -c /path/to/db-YYYY-MM-DD.sql.gz \
    | mysql --user=$DB_USERNAME --password

# PostgreSQL
pg_restore --username=$DB_USERNAME --dbname=nexus --clean \
    /path/to/db-YYYY-MM-DD.dump

# 6. Verify migrations match the deployed code.
php artisan migrate:status
# Every migration should say "Yes". If any say "No", run:
php artisan migrate --force

# 7. Rebuild caches.
php artisan optimize

# 8. Bring up the long-runners.
sudo supervisorctl start nexus-horizon nexus-schedule nexus-reverb
#   -- OR --
sudo systemctl start nexus-horizon nexus-schedule nexus-reverb
```

### Sanity checks after restore

```bash
php artisan about
php artisan migrate:status
php artisan queue:failed        # sane count (may have stragglers)
```

Then in the UI:

- Sign in as an existing user (password hashes survive restore).
- Open the Settings page — the GitHub Connection row should still
  show the linked account (proves `APP_KEY` decrypted the stored
  tokens).
- Trigger a manual repository sync — proves the token still
  authenticates against GitHub.
- Confirm the Overview loads without empty-state errors.

## 5. Quarterly restore rehearsal

**Do it every quarter.** A backup that hasn't been restored is
theoretical. Restore drills catch the categories of problem the
runbook won't:

- The dump command has always been running against the wrong DB.
- The retention policy silently truncated the file you needed.
- The passphrase for the encrypted `.env` was lost with the
  employee who left.
- A migration ran on prod that isn't reflected in the migrations
  folder anymore.
- The GitHub App has been revoked and no one noticed.

Suggested cadence: pick a scratch VM every quarter, run the full
restore sequence from step 1, and confirm the sanity checks pass.
Document what you fixed to make it work — that's the drift-detection
value.

## What's out of scope for Phase 9

- Automated point-in-time recovery (MySQL binlogs, Postgres WAL
  archiving) — worth adding if your RPO tightens below one day,
  but adds significant operational surface.
- Managed backup services (RDS automated backups, PlanetScale
  branch backups, etc.) — if the host DB is a managed service,
  use its built-in facility instead of `mysqldump`. Keep the
  `.env` handling above.
- Redis snapshots — Redis holds cache + queue only. No need to
  restore its state; workers repopulate from the DB.
- Backup encryption at rest via LUKS / EBS encryption — a good
  idea regardless of backup shape; outside the app's scope.

## Next steps

- [Security operator checklist](security/operator-checklist.md) —
  pre-deploy sign-off.
- [Installation](installation.md), [Deployment](deployment.md) —
  the other halves of the operator handbook.
