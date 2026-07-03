---
spec: production-docs
phase: 9
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-01
updated: 2026-07-02
---

# 041 — Production docs: installation, deployment playbook, backup strategy, `.env.production.example`

## Goal
Phase 9 closes when a fresh operator can stand Nexus up in
production from the docs alone. Today the repo has a README aimed
at solo-developer local setup, spec 039's
`docs/security/operator-checklist.md`, and nothing else. There's
no installation guide, no supervisor / systemd unit examples for
the four long-running processes (php-fpm, Horizon, scheduler,
Reverb), no backup drill, and no production-shaped env template.

Roadmap §Phase 9 lists installation docs, deployment docs, and
backup plan as explicit deliverables. Spec 041 ships all three
plus a `.env.production.example` and README breadcrumbs.

This is a docs-only spec. No new code, no schema changes, no
tests beyond a smoke check that the doc files exist. Value is in
the operator's ability to deploy without spelunking.

Roadmap refs: §Phase 9 acceptance criteria ("installation docs,
deployment docs, backup plan"), §26 Production Deployment Notes
(required services + workers + env vars), §17 Observability (what
to monitor once deployed).

## Scope

**In scope:**

- **`docs/installation.md`** — operator OS setup + prerequisites:
  - System requirements: PHP 8.4+ with the extensions the app
    needs (`bcmath`, `intl`, `pcntl`, `redis`), MySQL 8+ or
    PostgreSQL 15+, Redis 7+, Node.js 20+ (only for `npm run
    build` on the deploy host — not needed at runtime), Nginx
    or Caddy in front of PHP-FPM.
  - Process supervisor choice: Supervisor (traditional, apt
    package on Debian/Ubuntu) OR systemd (native on modern
    Linux). Spec 041 ships examples for **both** so operators
    aren't forced into a stack.
  - First-time setup commands: `git clone`, `composer install
    --no-dev --optimize-autoloader`, `npm ci && npm run build`,
    copy + edit `.env.production.example` → `.env`, `php artisan
    key:generate`, `php artisan migrate --force`, `php artisan
    optimize`.

- **`docs/deployment.md`** — production playbook:
  - Web server: Nginx + PHP-FPM reference config (fastcgi_pass,
    `try_files`, HTTPS termination) with a note that Caddy
    users can adapt.
  - **Supervisor unit files** for the three PHP long-runners:
    - `nexus-horizon.conf` — `php artisan horizon`, autorestart,
      `stopwaitsecs 60` so in-flight jobs finish.
    - `nexus-schedule.conf` — `php artisan schedule:work` (spec
      038's every-minute evaluator + spec 037's every-10-min
      poll depend on this).
    - `nexus-reverb.conf` — `php artisan reverb:start --host=
      127.0.0.1 --port=8080` behind the same Nginx that fronts
      PHP-FPM (WebSocket upgrade block).
  - **systemd unit files** for the same three processes (each
    with `Restart=always`, `RestartSec=5s`, `Type=simple`).
    Operators pick one supervisor OR one systemd, not both.
  - Post-deploy checklist:
    - `php artisan migrate --force`
    - `php artisan config:cache && php artisan route:cache &&
      php artisan event:cache`
    - `php artisan horizon:terminate` so the queue reloads with
      the new code.
    - Reference `docs/security/operator-checklist.md` §3 (Horizon
      allow-list) — deploy MUST populate `HORIZON_ALLOW_LIST`
      before the dashboard is reachable.
  - **Zero-downtime deploy note.** Symlink-swap deployments
    (Envoyer / Deployer style) work; the caveat is
    `horizon:terminate` between shared-state migrations.
  - **Health checks + monitoring.** Point at the existing
    `/up` health endpoint (Laravel's default), `/horizon` (queue),
    `/alerts` (`AlertSource::System` alerts spec 038 fires), and
    the Settings system-health card.

- **`docs/backup.md`** — backup + recovery playbook:
  - **Database dump** — `mysqldump` / `pg_dump` snippets with
    `--single-transaction` (MySQL) / `--serializable-deferrable`
    (Postgres). Nightly cron at 03:00 UTC to `/var/backups/
    nexus/db-YYYY-MM-DD.sql.gz`, 30-day rotation.
  - **`.env` handling** — the `APP_KEY` inside is what
    `github_connections.access_token` and `github_refresh_token`
    are encrypted against (spec 039). Losing `.env` = losing all
    the OAuth tokens forever. Recommended: encrypt the `.env`
    file with a passphrase before shipping it off-box, or store
    in an ops secrets manager (1Password / Bitwarden / Vault).
    **Never store the plain `.env` alongside the database
    backup** — a single stolen backup would give the attacker
    both the ciphertext and the key.
  - **Restore drill** — sequence: restore `.env` first, `php
    artisan migrate:fresh --force` on the new box + restore the
    dump on top, `php artisan horizon:terminate`.
  - **What NOT to back up** — `storage/framework/cache/`,
    `storage/framework/sessions/` (Redis-backed anyway),
    `storage/logs/` (nice-to-have, not critical), `node_modules/`,
    `vendor/`, `public/build/` (re-buildable).
  - **Off-site retention** — recommend a second copy in a
    different provider / region than the app runs in.
  - **Quarterly restore drill** — a backup you haven't restored
    isn't a backup. Suggested cadence: quarterly drill on a
    scratch VM.

- **`docs/env.production.example`** — production-shaped template.
  Lives under `docs/` (not repo root) because the environment
  sandbox blocks writing any `.env*` filename. Operators copy
  the file to `.env` on the deploy host. Difference from
  `.env.example`:
  - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
  - Placeholders for `DB_HOST` / `DB_PORT` / `DB_PASSWORD`,
    `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD`.
  - `HORIZON_ALLOW_LIST=` — empty, with a comment reminding to
    populate before deploy (spec 039 cross-ref).
  - `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` — explicit
    for multi-host prod setups.
  - `MAIL_MAILER` + `MAIL_FROM_ADDRESS` — even if the app doesn't
    send email today, having the knob templated saves a future
    debug session.
  - `TELESCOPE_ENABLED=false`.
  - Dev-only knobs (tunnel URLs, `HOSTS_HEARTBEAT_TIMEOUT_SECONDS`
    overrides) commented out with a note.

- **`README.md` production section.** New "Deploying to
  production" block near the top that points at
  `docs/installation.md` → `docs/deployment.md` → `docs/backup.md`
  → `docs/security/operator-checklist.md`. One-line each, no
  detail — the docs carry the weight.

- **Smoke test.** `tests/Feature/Docs/ProductionDocsExistTest.php`
  asserts the four doc files exist + are non-empty + contain
  their required headings. Catches an accidental `git rm docs/*`
  or a truncated file; doesn't assert content quality.

**Out of scope:**

- **Ansible / Terraform / Docker Compose examples.** One specific
  deploy shape ignores the others; docs describe requirements +
  reference examples, not turnkey playbooks.
- **CI/CD pipeline docs** (GitHub Actions deploy workflow) —
  separate polish spec; every team's pipeline shape differs.
- **Monitoring-stack setup** (Grafana / Prometheus wiring for
  the metrics Nexus emits) — outside Phase 9's polish scope;
  candidate for a Phase 10 observability polish.
- **Multi-region / HA topologies** — Phase 9 targets "one
  operator can deploy a single instance." Read-replicas,
  failover, and geo-distributed writes come with the
  multi-tenant migration.
- **Zero-downtime migrations recipe** — the deployment playbook
  points at `horizon:terminate` but doesn't cover the
  advanced-Laravel-deploy tools (Envoyer, Deployer, Vapor).
- **Kubernetes manifests / Helm chart** — the roadmap doesn't
  ask for it; if it's ever wanted it's its own spec.

## Plan

1. **Draft the four doc files.** Write each one as a standalone
   operator handbook — copy-paste-ready commands, no forward
   references except into other Nexus docs. Sanity-check by
   reading top-to-bottom as if I were the operator with zero
   context.

2. **`.env.production.example`.** Diff from `.env.example`;
   strip dev knobs, add production placeholders, add comments
   for each block referencing which spec / section motivates it.

3. **README breadcrumbs.** Add a "Deploying to production"
   section pointing at the four docs. Don't inline content —
   keep the README short.

4. **Smoke test.** `ProductionDocsExistTest` — 4 assertions,
   each `assertFileExists` + `assertStringContainsString('#
   Heading')`. Runs in <100ms.

5. **Pint clean, suite green, build clean. Self-review with
   `superpowers:code-reviewer`. PR. Watch CI. Pause for
   merge.**

## Acceptance criteria
- [ ] `docs/installation.md` exists and covers PHP + DB + Redis
      + supervisor prerequisites + first-time setup commands.
- [ ] `docs/deployment.md` exists and includes Supervisor AND
      systemd unit-file examples for the three PHP long-runners
      (Horizon, scheduler, Reverb) plus an Nginx reference
      config and a post-deploy checklist.
- [ ] `docs/backup.md` exists and covers DB dump, `.env`
      handling, restore drill, off-site retention, quarterly
      drill cadence.
- [ ] `docs/env.production.example` exists and diverges from
      `.env.example` on `APP_ENV`, `APP_DEBUG`, `LOG_LEVEL`,
      DB/Redis auth placeholders, `HORIZON_ALLOW_LIST`,
      session/sanctum domains, Telescope disable, mail
      from-address. (Filename lives under `docs/` because the
      environment sandbox blocks writing `.env*` files.)
- [ ] README has a "Deploying to production" block linking to
      all four docs + the operator checklist.
- [ ] `ProductionDocsExistTest` passes.
- [ ] Pint clean. `php artisan test` green. `npm run build`
      clean.

## Files touched

- `docs/installation.md` — created
- `docs/deployment.md` — created
- `docs/backup.md` — created
- `docs/env.production.example` — created (path under `docs/`
  because sandbox blocks `.env*` writes; operators copy to `.env`)
- `README.md` — new production section
- `tests/Feature/Docs/ProductionDocsExistTest.php` — created

## Work log
Dated notes as work progresses.

### 2026-07-01
- Drafted from `_template.md`. Docs-only spec — no schema, no
  code paths, no fixtures beyond the smoke test. Value is
  operator-facing; the smoke test only catches
  accidental deletion, not content quality.

### 2026-07-02
- Branch `spec/041-production-docs` cut off main.
- Tracking issue #121.
- Scope shipped as drafted (no late edits requested).
- Env template path moved from repo root
  (`.env.production.example`) to `docs/env.production.example`
  because the environment sandbox denies writes to any `.env*`
  filename. Functionally equivalent — operators copy to `.env`
  on deploy host either way. README + `docs/installation.md`
  point at the docs path.
- Shipped the four docs + env template + README production
  breadcrumbs + `ProductionDocsExistTest` smoke test.
  Supervisor and systemd examples ship inline; Nginx is the
  reference config with a WebSocket upgrade block for Reverb.
  Restore drill sequence lands `.env` before the DB dump so
  `APP_KEY` is available to decrypt the GitHub tokens in the
  restored rows.

## Open questions / blockers

- **Supervisor vs systemd — both.** Some operators standardize
  on one, and a "pick one" spec risks alienating half the
  audience. Both examples fit inline; the deployment doc
  frames them as alternatives, not layers.
- **Nginx vs Caddy.** Nginx is the reference config because
  the entire Laravel ecosystem documents it. Caddy operators
  can adapt — a one-paragraph pointer to Caddy's automatic
  HTTPS + `php_fastcgi` directive is enough.
- **Database backup command per engine.** MySQL + Postgres
  each get their own snippet. SQLite is dev-only (see spec
  005's rationale) and doesn't get a production restore
  path — operators running SQLite in prod are outside the
  supported deploy shape.
- **`.env.production.example` shipping in git.** The file is
  a template with placeholders + comments, not real secrets.
  Same pattern as the existing `.env.example`; safe.
