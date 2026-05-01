# Nexus Control Center

A futuristic engineering operations dashboard. Pulls projects, GitHub repositories, issues, pull requests, deployments, monitoring, alerts, and infrastructure health into one place.

Source of truth for product scope: [`nexus_control_center_roadmap.md`](nexus_control_center_roadmap.md).
Visual target: [`specs/visual-reference.md`](specs/visual-reference.md).

## Stack

- **Backend** — Laravel 13 (PHP 8.4+), Eloquent, Horizon, Reverb (websockets), domain-driven layout under `app/Domain/{BoundedContext}/{Actions|Jobs|Queries|Services|...}`.
- **Frontend** — Vue 3 + Inertia v2 + TypeScript + Tailwind CSS. Single-page navigation; no API client layer (Inertia handles it).
- **Tests** — PHPUnit feature tests with `RefreshDatabase`, `Http::fake()`, `Queue::fake()`. CI runs Pint + `php artisan test` + `npm run build`.
- **Infrastructure** — SQLite for dev, MySQL/PostgreSQL for prod. Redis for queues + cache. GitHub App for OAuth + webhooks.

## Phase status

Status legend: ⬜ not started · 🟡 in progress · 🟢 done · 🔴 blocked

| # | Phase | Status | Notes |
|---|-------|--------|-------|
| 0 | Foundation (auth, layout, static overview) | 🟢 | 9/9 specs done. |
| 1 | Projects & Repositories | 🟢 | 3/3 specs done. |
| 2 | GitHub Integration MVP | 🟢 | 4/4 specs done — connection, repository import + sync, issues sync, PRs + unified Work Items page. |
| 3 | GitHub Webhooks & Activity Feed | 🟢 | 3/3 specs done (017–019). Phase complete. |
| 4 | Deployments & CI/CD | 🟢 | 3/3 specs done (020–022) — workflow runs storage + sync, cross-repo timeline UI with realtime, Overview success-rate widget. |
| 5 | Website Monitoring | 🟢 | 3/3 specs done (023–025) — monitor MVP + manual probe, scheduled checks + uptime calc + activity events, Overview uptime KPI + Reverb live updates + perf chart. |
| 6 | Docker Host Agent MVP | ⬜ | — |
| 7 | Alerts Engine | ⬜ | — |
| 8 | Analytics & Health Scores | ⬜ | — |
| 9 | Polish & Production Readiness | ⬜ | — |
| 10 | Future Innovation | ⬜ | — |

Detailed per-spec status lives in [`specs/README.md`](specs/README.md). Each spec is one GitHub issue + one branch + one PR — see [`.claude/skills/nexus-spec-workflow`](.claude/skills/nexus-spec-workflow) for the workflow.

## What works today

After Phase 2:
- Sign in, register, verify email.
- Create projects with color/icon. Manually link a GitHub repository or import via the connected GitHub account.
- Imported repos auto-sync metadata (description, branches, language, stars/forks, last push) and mirror their issues + pull requests into local tables.
- Per-Repository tabs for Overview / Issues / Pull Requests with state badges, branch names, comment counts, and external links to GitHub.
- A unified `/work-items` queue across all your imported repos, filterable by kind / state / repository / free-text search.
- Settings page surfaces the GitHub connection (encrypted token storage, scope display, Reconnect CTA when expired) and a per-user "N repositories linked, last sync …" indicator.
- Manual "Run sync" buttons everywhere a sync job exists.
- Controller flash messages (`->with('status'|'error', …)`) render as a dismissable top banner in `AppLayout`, so failed actions (OAuth callbacks, sync triggers) surface to the user instead of failing silently. Silent OAuth callback branches also `Log::warning` for postmortem.

After Phase 3 (complete):
- Spec 017 (done) — GitHub webhook ingestion endpoint at `POST /webhooks/github`. Verifies `X-Hub-Signature-256` (HMAC-SHA-256, timing-safe), stores deliveries idempotently, dispatches an async job, routes to per-event handlers. `issues` and `pull_request` events update the local mirrors and create `activity_events` rows.
- Spec 018 (done) — Activity Feed UI. `RecentActivityForUserQuery` powers a shared `activity.recent` Inertia prop registered in `HandleInertiaRequests::share()`, so every authenticated page lights up the right rail with the latest events without per-controller plumbing. New `/activity` page (linked from the sidebar between Alerts and Settings) shows up to 100 events.
- Spec 019 (done) — Real-time broadcasting via Reverb. `CreateActivityEventAction` dispatches `ActivityEventCreated` (a `ShouldBroadcastNow` event on a private `users.{id}.activity` channel) every time a row lands. Echo + Pusher are wired in `bootstrap.ts`; the `useActivityFeed` composable (`resources/js/lib/`) seeds from the shared prop and prepends broadcast events into the rail and the `/activity` page in real time. Three new webhook handlers (`workflow_run`, `push`, `release`) extend the spec-017 ingestion: workflow runs surface as `workflow.{succeeded,failed}`, releases as `release.published`, and pushes silently update `repositories.last_pushed_at` (no activity row — too noisy). When the websocket isn't connected the rail and the activity page show a small "Live updates offline" pill; page-load reads still surface the latest data.

After Phase 4 (complete):
- Spec 020 (done) — Workflow runs storage + sync. `workflow_runs` table with FK to `repositories` and the same six-column sync pattern as issues / PRs. `SyncRepositoryWorkflowRunsAction` upserts on `(repository_id, github_id)`; the matching `Job` chains off `SyncGitHubRepositoryJob` so importing a repo backfills its run history. Per-repo Workflow Runs tab on the show page lists status, conclusion, branch, run number, actor, started-at; rows link out to the GitHub Actions run. Spec 019's `WorkflowRunWebhookHandler` now upserts into the new table for every `workflow_run` delivery (queued / in_progress / completed) so the timeline reflects in-flight states.
- Spec 021 (done) — Deployment timeline UI. New `/deployments` page renders the cross-repo workflow runs as a chronological timeline. URL-bound filters (project / repository / status / conclusion / branch) survive reload; the repository dropdown narrows client-side based on the selected project. Per-run detail drawer (`Teleport` overlay, slide-from-right, Esc / backdrop / close-button dismiss with focus restoration) shows head SHA, duration, actor, conclusion, and a CTA out to GitHub. Real-time updates via a new `WorkflowRunUpserted` event broadcast on a private `users.{id}.deployments` channel — fires from the webhook handler upsert path (not from bulk REST sync, which would flood the channel). Sidebar `Deployments` entry replaces the Phase 4 placeholder; `Pipelines` stays disabled as a future filter view.
- Spec 022 (done) — Overview success-rate widget. `GetOverviewDashboardQuery::deploymentsKpi()` aggregates `workflow_runs` over the last 24h (vs the prior 24h) for the headline numbers plus a 12-day daily completed-run sparkline. Returns `successful_24h` (primary value), `success_rate_24h` (integer percent or null when no completed runs landed), `change_percent` (capped `[-100, +999]`), `sparkline`, and a status enum. Window keys on `run_completed_at` (not `run_started_at`) so long-running jobs land in the bucket where they actually completed. The Overview's Deployments KPI card secondary line now reads `92% success` (or `—% success` for empty windows) instead of the static "Successful" placeholder.

After Phase 5 (complete):
- Spec 023 (done) — Website monitor MVP. `websites` + `website_checks` tables with `WebsiteStatus` (`pending|up|down|slow|error`) and `WebsiteCheckStatus` enums. `RunWebsiteProbeAction` is a pure HTTP probe (no DB writes) classifying the response into up / slow (>3000ms hard threshold) / down / error; `RecordWebsiteCheckAction` persists the check + updates `Website.{status,last_checked_at,last_success_at,last_failure_at}`. CRUD pages live under `/monitoring/websites/*`; per-site show page hosts a manual "Probe now" button (sync request → instant feedback ≤ `timeout_ms`). Sidebar `Monitoring` entry replaces the Phase 5 placeholder.
- Spec 024 (done) — Scheduled checks + uptime calc + activity events. `DispatchDueWebsiteChecksJob` runs every minute (`Schedule::job(...)->everyMinute()->withoutOverlapping()` in `routes/console.php`), filters due websites in PHP (cross-DB compat), and dispatches `RunWebsiteCheckJob` per row. The per-website job reuses the spec-023 actions so manual probes and scheduled probes never drift. `RecordWebsiteCheckAction` detects healthy↔failed category transitions and emits `website.down` / `website.up` activity events on swings (steady-state runs stay silent). Spec 019's `ActivityEventCreated::broadcastOn()` was extended to resolve recipient channels for `source: monitoring` rows via `metadata.website_id → website → project → owner_user_id` so monitoring incidents broadcast in realtime. `GetWebsitePerformanceSummaryQuery` returns count-based uptime % over 24h / 7d / 30d windows + last-incident timestamp; the show page renders a 4-tile uptime stats strip. `RecentActivityForUserQuery` extended to surface monitoring events alongside repo events on the right rail.
- Spec 025 (done) — Overview uptime KPI + Reverb live updates + perf chart. `GetMonitoringUptimeKpiQuery` aggregates `website_checks` volume-weighted across **all** the user's monitors over 24h (vs prior 24h) plus a 12-day daily sparkline; replaces the long-standing `MOCK_KPIS['uptime']` on Overview. Empty 24h window → null overall + muted status; status thresholds at 99 / 95. New `WebsiteCheckRecorded` event (`ShouldBroadcastNow`, pre-resolved owner id, `users.{id}.monitoring` channel, light-weight `{check_id, website_id}` pulse) fires from `RecordWebsiteCheckAction` on every persisted check (steady-state runs included; transition events stay separate). The website show page subscribes via Echo, filters client-side by `website_id`, and partial-reloads `website + summary + checks` on each pulse. Response-time `Sparkline` of the last 50 `response_time_ms` values renders in the recent-checks card with leading-null skip + carry-forward fill; `<2 data points` renders a "not enough data" placeholder.

## Local development

```bash
# 1. Install backend + frontend deps
composer install
npm install

# 2. Set up the env + key + db + storage
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link

# 3. Run the dev stack (Laravel + Vite + queue worker + scheduler)
composer dev
```

`composer dev` runs Laravel, Vite, the queue worker, and the scheduler in parallel via concurrently. For real-time/websocket testing, use `composer dev:horizon` (also runs Reverb + Horizon). The scheduler tick is what drives spec 024's website-monitor probes — without `composer dev` (or a real cron in prod), `DispatchDueWebsiteChecksJob` never fires and monitors stay on whatever state the last manual "Probe now" left them in.

### GitHub App setup

Phase 2's repository sync and Phase 3's webhooks both need a GitHub-side app registered against your account/org. Without `GITHUB_CLIENT_ID` set, the "Connect GitHub" button on `/settings` redirects to GitHub with an empty `client_id` and **GitHub itself returns a 404** — that's the canonical "I tried to connect and got a 404" symptom.

#### 1. Register the app on GitHub

Two options — either works for spec 013's OAuth flow:

- **GitHub OAuth App** (simpler — fine until you start spec 017's webhooks): https://github.com/settings/developers → "New OAuth App".
- **GitHub App** (use this if you also want Phase 3 webhooks; one app covers both flows): https://github.com/settings/apps → "New GitHub App".

Settings either way:

| Field | Value |
|-------|-------|
| Application / GitHub App name | `Nexus Control Center (dev)` (any name; per-developer) |
| Homepage URL | `http://localhost:8000` |
| Authorization callback URL | `http://localhost:8000/integrations/github/callback` |
| Webhook URL *(GitHub App only, optional pre-Phase-3)* | `https://<ngrok>.ngrok.io/webhooks/github` |
| Webhook secret *(GitHub App only)* | a random string — must match `GITHUB_WEBHOOK_SECRET` in `.env` |
| Permissions *(GitHub App only)* | Repository: read for `Metadata`, `Issues`, `Pull requests`. Account: read for `Email addresses` (optional). |

#### 2. Copy credentials into `.env`

```
GITHUB_CLIENT_ID=Iv1.xxxxxxxxxxxxxxxx
GITHUB_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_OAUTH_REDIRECT_URI=http://localhost:8000/integrations/github/callback
GITHUB_WEBHOOK_SECRET=                # set this when you start spec 017
```

If you previously had Laravel running, restart it so config picks up the new env:

```bash
php artisan config:clear
php artisan serve
```

#### 3. Use `localhost`, not `127.0.0.1`

GitHub validates the OAuth callback host **exactly**. If your `GITHUB_OAUTH_REDIRECT_URI` says `localhost` but you browse via `http://127.0.0.1:8000`, the OAuth handshake will fail. Pick one and stick with it across the env value, the GitHub App's callback URL, and the URL you use in your browser.

#### Phase 3 webhook tunneling

Spec 017's webhook ingestion needs GitHub to be able to reach your dev server. Point an ngrok (or Cloudflare Tunnel) tunnel at port 8000 and set the GitHub App's Webhook URL to `https://<your-tunnel>/webhooks/github`. The `X-Hub-Signature-256` header is verified against `GITHUB_WEBHOOK_SECRET` — if that doesn't match the value configured on the App, every delivery 401's and never lands in the database.

### Browsing the dev UI through a Cloudflare tunnel

Sometimes you want to browse the running app from a public URL — to demo the dashboard, hit it from another device, or run an OAuth callback that GitHub can reach. `composer run dev` boots two long-running HTTP servers — Laravel on `:8000` and Vite on `:5173` — and the browser needs to talk to **both**. Tunneling only port 8000 will load the HTML but every Vite asset (`@vite/client`, `app.ts`, …) will 404 / CORS-fail because the page tries to fetch them from `localhost:5173`.

Set up two cloudflared tunnels and point Vite at its public URL:

```bash
# Terminal 1 — Laravel
cloudflared tunnel --url http://localhost:8000
# → https://<random>.trycloudflare.com   (call this URL_A)

# Terminal 2 — Vite
cloudflared tunnel --url http://localhost:5173
# → https://<random>.trycloudflare.com   (call this URL_B)
```

Then update `.env`:

```env
APP_URL=https://URL_A.trycloudflare.com
VITE_DEV_SERVER_URL=https://URL_B.trycloudflare.com
```

…and **restart `composer run dev`**. Vite reads `VITE_DEV_SERVER_URL` once at boot; `vite.config.js` flips into tunnel mode when it's set — binds `0.0.0.0`, locks port 5173, allows cross-origin requests, and emits asset URLs at the public host so the browser can fetch them through the tunnel. You'll see a `[vite] tunnel mode active — origin=…` line in the `composer run dev` output (prefixed `vite:`); if it's missing, Vite didn't pick up `VITE_DEV_SERVER_URL` and you'll see CORS / 403 errors in the browser.

Caveats with quick tunnels (`cloudflared tunnel --url ...`):

- The tunnel URL changes on every run. Re-edit `.env` and restart `composer run dev` each time.
- The OAuth callback URL configured in your GitHub App **must** match the new `APP_URL`. Update the GitHub App settings every time the Laravel tunnel URL changes — or use a [named tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps) bound to a stable hostname so the URL stays put.
- HMR over `wss://*.trycloudflare.com` can drop intermittently — when it does, asset URLs are absolute so a manual refresh still serves the latest code. Named tunnels are more stable.
- If port 5173 is already in use, set `VITE_DEV_SERVER_PORT=5174` (or any free port) in `.env` and point your second `cloudflared` at the same port.
- The default Laravel `APP_URL`-derived `SESSION_DOMAIN`/`SANCTUM_STATEFUL_DOMAINS` won't include your tunnel hostname. If session-based requests start 419'ing through the tunnel, set `SESSION_DOMAIN=` (blank) and add the tunnel hostname to `SANCTUM_STATEFUL_DOMAINS`.
- TLS terminates at the tunnel — `php artisan serve` only sees plain HTTP locally. Two things tame this:
    - `AppServiceProvider::boot()` calls `URL::forceScheme('https')` whenever `APP_URL` starts with `https://` so generated links don't trigger Mixed Content blocks. If you ever swap to a non-HTTPS tunnel, change `APP_URL` to `http://...` to disable the override.
    - `bootstrap/app.php` trusts loopback proxies (`127.0.0.1`, `::1`), so cloudflared's `X-Forwarded-Proto: https` is honored. Without this, **signed URLs** (email verification, password reset) verify the signature against an `http://` URL while it was signed against `https://`, and every click 403's "Invalid signature." Loopback-only trust is safe in prod too — anything that can connect from loopback already has direct app access.
- Reverb (websockets, port 8080) is wired by spec 019 — set up a **third tunnel** when you want the activity feed to update without page refresh:
    ```bash
    cloudflared tunnel --url http://localhost:8080
    # → https://<random>.trycloudflare.com   (call this URL_C)
    ```
    Then in `.env` — **only the `VITE_REVERB_*` (browser-side) keys change**; leave the server-side `REVERB_*` keys on their localhost defaults because the Laravel process still talks to Reverb on the same machine:
    ```env
    # Browser → Reverb (over the tunnel)
    VITE_REVERB_HOST=URL_C.trycloudflare.com
    VITE_REVERB_SCHEME=https
    VITE_REVERB_PORT=443
    ```
    Restart `composer run dev` and make sure `php artisan reverb:start` is running too — `composer dev` doesn't start it, so use `composer dev:horizon` or run it in another terminal. The `[vite] tunnel mode active` banner prints the resolved env at boot. To verify it's wired end-to-end, open `URL_A/activity` and trigger a webhook event (or push a commit to a synced repo) — the row should prepend instantly. If it doesn't, the rail and `/activity` page show a "Live updates offline" pill and page-load reads still surface the latest events. Named tunnels make this triple substantially less painful.

Local-only dev (no tunnel) is unaffected — leave `VITE_DEV_SERVER_URL` empty and Vite behaves exactly as before.

## Tests + linting

```bash
php artisan test                  # PHP feature + unit tests
./vendor/bin/pint                 # PHP formatter (CI gate)
npm run build                     # vue-tsc + Vite production build (CI gate)
```

CI workflow: [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Workflow conventions

- Branches: `spec/NNN-<slug>` for spec branches, `chore/<slug>` / `fix/<slug>` for everything else. Direct push to `main` is blocked by branch protection.
- One spec → one issue → one branch → one PR. Tasks within a spec live as checklists in the spec markdown, not as separate issues.
- Squash-merge into `main`. Each spec lands as one commit.
- Bookkeeping (flipping spec status to `done`, updating phase trackers) ships as a small follow-up `chore:` PR after the feature merges.

Full workflow: [`.claude/skills/nexus-spec-workflow/SKILL.md`](.claude/skills/nexus-spec-workflow/SKILL.md).

## Repository layout

```
app/
    Domain/                 — bounded contexts (Activity, Dashboard, GitHub, Monitoring, Repositories, …)
        {Context}/
            Actions/        — invokable use-cases
            Jobs/           — ShouldQueue background work
            Services/       — external API wrappers
            WebhookHandlers/— per-event handlers (spec 017+)
            Probes/         — value objects for probe results (Monitoring)
            Queries/        — read-side query classes
            Exceptions/
    Enums/                  — string-backed PHP enums (status, severity, …)
    Events/                 — broadcast events (ActivityEventCreated, WorkflowRunUpserted, WebsiteCheckRecorded)
    Http/Controllers/
    Http/Controllers/Monitoring/
    Http/Controllers/Webhooks/
    Models/
    Policies/               — Gate policies (Project, Repository, Website)
resources/js/
    Pages/                  — Inertia page components
        Activity/
        Deployments/        — cross-repo timeline + drawer (spec 021)
        Monitoring/Websites/— monitor CRUD + show (specs 023–025)
        Projects/
        Repositories/
        WorkItems/
        Overview.vue · Settings/Index.vue · Welcome.vue
    Components/
        Activity/           — ActivityFeed, ActivityFeedItem, ActivityHeatmap
        Dashboard/          — KpiCard, Sparkline, StatusBadge
        Sidebar/
    lib/
        useActivityFeed.ts  — Echo composable for the right rail (spec 019)
        workflowRunStyles.ts — shared tone/label maps for workflow runs
        websiteStyles.ts    — shared tone maps for website status
    Layouts/AppLayout.vue   — primary chrome (sidebar + topbar + right rail)
specs/
    README.md               — phase tracker + per-spec links
    phase-N-<slug>/         — one folder per phase
        README.md           — phase summary + task list
        NNN-<slug>.md       — individual spec
```
