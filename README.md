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
| 3 | GitHub Webhooks & Activity Feed | 🟡 | 1/3 specs done (017). Next: 018 Activity Feed UI. |
| 4 | Deployments & CI/CD | ⬜ | — |
| 5 | Website Monitoring | ⬜ | — |
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

After Phase 3 (in progress):
- Spec 017 (done) — GitHub webhook ingestion endpoint at `POST /webhooks/github`. Verifies `X-Hub-Signature-256` (HMAC-SHA-256, timing-safe), stores deliveries idempotently, dispatches an async job, routes to per-event handlers. `issues` and `pull_request` events update the local mirrors and create `activity_events` rows. Backend only — UI for the activity feed lands in spec 018.

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

`composer dev` runs Laravel, Vite, the queue worker, and the scheduler in parallel via concurrently. For real-time/websocket testing, use `composer dev:horizon` (also runs Reverb + Horizon).

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

…and **restart `composer run dev`**. Vite reads `VITE_DEV_SERVER_URL` once at boot; `vite.config.js` flips into tunnel mode when it's set — binds `0.0.0.0`, locks port 5173, allows cross-origin requests, and emits asset URLs at the public host so the browser can fetch them through the tunnel.

Caveats with quick tunnels (`cloudflared tunnel --url ...`):

- The tunnel URL changes on every run. Re-edit `.env` and restart `composer run dev` each time.
- The OAuth callback URL configured in your GitHub App **must** match the new `APP_URL`. Update the GitHub App settings every time the Laravel tunnel URL changes — or use a [named tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps) bound to a stable hostname so the URL stays put.
- Reverb (websockets, port 8080) isn't covered by this setup. When real-time features become essential, expose Reverb via a third tunnel and update the `VITE_REVERB_*` block in `.env` to match.

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
    Domain/                 — bounded contexts (GitHub, Repositories, Activity, …)
        {Context}/
            Actions/        — invokable use-cases
            Jobs/           — ShouldQueue background work
            Services/       — external API wrappers
            WebhookHandlers/— per-event handlers (spec 017+)
            Queries/        — read-side query classes
            Exceptions/
    Enums/                  — string-backed PHP enums (status, severity, …)
    Http/Controllers/
    Http/Controllers/Webhooks/
    Models/
resources/js/
    Pages/                  — Inertia page components (Overview, Projects, Repositories, WorkItems, Settings)
    Components/             — shared UI (Sidebar, StatusBadge, ActivityFeed once spec 018 lands)
    Layouts/AppLayout.vue   — primary chrome (sidebar + topbar + right rail)
specs/
    README.md               — phase tracker + per-spec links
    phase-N-<slug>/         — one folder per phase
        README.md           — phase summary + task list
        NNN-<slug>.md       — individual spec
```
