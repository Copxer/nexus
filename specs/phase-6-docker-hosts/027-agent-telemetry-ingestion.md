---
spec: agent-telemetry-ingestion
phase: 6
status: done
owner: Yoany
created: 2026-05-01
updated: 2026-05-01
---

# 027 — Agent Telemetry Ingestion + Reference Agent

## Goal
Wire the bearer-authenticated `POST /agent/telemetry` endpoint so a Nexus agent on a Docker host can push host + container stats into the database. Builds directly on 026: tokens already exist + are hashed; this spec gives them something to authenticate against. Also ships a small Node reference agent script that documents the payload contract and lets a developer get telemetry flowing on their own laptop in five minutes.

Roadmap refs: §8.7 Docker Hosts, §16.5 Agent Security, §17 Observability for Nexus Itself.

## Scope

**In scope:**
- `app/Http/Middleware/AuthenticateAgent.php` — reads `Authorization: Bearer <plaintext>`, hashes with `AgentToken::hash()`, resolves the active token + host, stamps `last_used_at`, attaches the host to the request via `$request->attributes->set('agent_host', $host)`. 401 on missing / malformed / revoked / unknown.
- Route `POST /agent/telemetry` registered outside the auth/verified group (no CSRF — it's a JSON API). Middleware alias `'agent.auth'` registered in `bootstrap/app.php`.
- `app/Http/Controllers/Agent/HostTelemetryController.php` — pulls the host off the request, validates payload via a Form Request, dispatches to the action, returns `204 No Content`.
- `app/Http/Requests/Agent/IngestTelemetryRequest.php` — validates the payload shape (host metrics, optional facts, container array). `recorded_at` must be ISO 8601 and within a sane skew window (≤ 5 min in the future, ≤ 1 hour in the past — log + reject older).
- `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — single transaction:
  1. Updates host metadata (cpu_count, memory_total_mb, disk_total_gb, os, docker_version) when provided.
  2. Flips `status` to `online` and stamps `last_seen_at = recorded_at`.
  3. Inserts a `host_metric_snapshots` row.
  4. Hands containers off to `SyncContainerSnapshotsAction`.
- `app/Domain/Docker/Actions/SyncContainerSnapshotsAction.php` — for each container in the payload:
  - Upsert on `(host_id, container_id)` with the per-container fields (status, image, latest stats).
  - Insert one `container_metric_snapshots` row.
  - **Container removal is out of scope** (deferred — stopped/gone containers stay in the table until a future cleanup job; we just stop seeing fresh snapshots).
- Per-token rate limiting via `RateLimiter::for('agent-telemetry', ...)` keyed off the hashed token. Default: 60 req/min/token. Returns 429 with the canonical `Retry-After` header.
- A `dispatch.activity` placeholder is **not** added here — `host.online` activity events are a 029 concern.
- Reference agent at `agent/reference-agent.mjs` — single-file Node 20+ script. Reads `NEXUS_URL` + `NEXUS_AGENT_TOKEN` env vars, shells out to `docker info` / `docker stats --no-stream` / `docker ps -a --no-trunc --format '{{json .}}'`, builds the payload, posts every `NEXUS_AGENT_INTERVAL` seconds (default 30). Includes `agent/README.md` with the install command, env var reference, and a one-liner `node agent.mjs` to run it.
- Feature tests: middleware (valid / missing / malformed / revoked / unknown token), endpoint happy path, payload validation rejection, rate limit (assert 429 + `Retry-After`), idempotency (re-post same `recorded_at` lands two snapshot rows — that's fine; we don't dedupe at this layer).
- Unit tests for both actions: host upsert + first-online transition, container upsert preserves `(host_id, container_id)` uniqueness, second post for same container appends a snapshot without breaking the upsert.

**Out of scope:**
- Container removal / sweep job (future polish).
- Activity events for `host.online` / `host.recovered` / `container.unhealthy` (029).
- IP allowlist + host fingerprint binding (roadmap §16.5 "later").
- Production-ready Go agent (roadmap §8.7).
- Promoting host issues to `alerts` rows (Phase 7).
- Hosts UI rendering of metric history (028 — this spec persists the data; 028 displays it).

## Plan

1. **Middleware.** New `app/Http/Middleware/AuthenticateAgent.php`:
   ```php
   $header = $request->bearerToken();
   abort_unless(is_string($header) && $header !== '', 401);
   $token = AgentToken::query()
       ->where('hashed_token', AgentToken::hash($header))
       ->whereNull('revoked_at')
       ->with('host')
       ->first();
   abort_unless($token && $token->host && $token->host->archived_at === null, 401);
   $token->forceFill(['last_used_at' => now()])->save();
   $request->attributes->set('agent_host', $token->host);
   $request->attributes->set('agent_token', $token);
   ```
   No `Auth::login()` — this is a machine-to-machine path; the host (not a user) is the actor. Rate limiting reads `agent_token->id` for the bucket key.

2. **Middleware alias.** Register in `bootstrap/app.php`'s `withMiddleware()`:
   ```php
   $middleware->alias([
       'agent.auth' => AuthenticateAgent::class,
   ]);
   ```

3. **Route.** In `routes/web.php`, outside the auth group:
   ```php
   Route::post('/agent/telemetry', HostTelemetryController::class)
       ->middleware(['agent.auth', 'throttle:agent-telemetry'])
       ->name('agent.telemetry');
   ```
   CSRF is automatically skipped for this path via `bootstrap/app.php`'s `validateCsrfTokens(except: ['agent/telemetry', ...])` (mirrors the GitHub webhook entry).

4. **RateLimiter binding.** In `app/Providers/AppServiceProvider::boot()`:
   ```php
   RateLimiter::for('agent-telemetry', function (Request $request) {
       $token = $request->attributes->get('agent_token');
       return Limit::perMinute(60)->by('agent-token:' . ($token?->id ?? 'anon'));
   });
   ```

5. **Form Request.** `app/Http/Requests/Agent/IngestTelemetryRequest.php` — `authorize()` returns true (the middleware already gated it). Validation:
   ```php
   'recorded_at' => ['required', 'date'],
   'host' => ['required', 'array'],
   'host.metrics' => ['required', 'array'],
   'host.metrics.cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
   'host.metrics.memory_used_mb' => ['nullable', 'integer', 'min:0'],
   'host.metrics.memory_total_mb' => ['nullable', 'integer', 'min:0'],
   'host.metrics.disk_used_gb' => ['nullable', 'integer', 'min:0'],
   'host.metrics.disk_total_gb' => ['nullable', 'integer', 'min:0'],
   'host.metrics.load_average' => ['nullable', 'numeric', 'min:0'],
   'host.metrics.network_rx_bytes' => ['nullable', 'integer', 'min:0'],
   'host.metrics.network_tx_bytes' => ['nullable', 'integer', 'min:0'],
   'host.facts' => ['sometimes', 'array'],
   'host.facts.cpu_count' => ['nullable', 'integer', 'min:1', 'max:1024'],
   'host.facts.memory_total_mb' => ['nullable', 'integer', 'min:0'],
   'host.facts.disk_total_gb' => ['nullable', 'integer', 'min:0'],
   'host.facts.os' => ['nullable', 'string', 'max:80'],
   'host.facts.docker_version' => ['nullable', 'string', 'max:32'],
   'containers' => ['sometimes', 'array', 'max:500'],
   'containers.*.container_id' => ['required', 'string', 'max:80'],
   'containers.*.name' => ['required', 'string', 'max:255'],
   'containers.*.image' => ['required', 'string', 'max:255'],
   'containers.*.image_tag' => ['nullable', 'string', 'max:128'],
   'containers.*.status' => ['nullable', 'string', 'max:32'],
   'containers.*.state' => ['nullable', 'string', 'max:32'],
   'containers.*.health_status' => ['nullable', 'string', 'max:16'],
   'containers.*.ports' => ['sometimes', 'array'],
   'containers.*.labels' => ['sometimes', 'array'],
   'containers.*.metrics' => ['sometimes', 'array'],
   'containers.*.metrics.cpu_percent' => ['nullable', 'numeric'],
   'containers.*.metrics.memory_usage_mb' => ['nullable', 'integer', 'min:0'],
   'containers.*.metrics.memory_limit_mb' => ['nullable', 'integer', 'min:0'],
   'containers.*.metrics.network_rx_bytes' => ['nullable', 'integer', 'min:0'],
   'containers.*.metrics.network_tx_bytes' => ['nullable', 'integer', 'min:0'],
   'containers.*.metrics.block_read_bytes' => ['nullable', 'integer', 'min:0'],
   'containers.*.metrics.block_write_bytes' => ['nullable', 'integer', 'min:0'],
   'containers.*.started_at' => ['nullable', 'date'],
   'containers.*.finished_at' => ['nullable', 'date'],
   ```
   `withValidator()` adds the skew check on `recorded_at` (between `now()->subHour()` and `now()->addMinutes(5)`).

6. **Controller.** Thin — pull host off request, hand validated payload to the action, return 204.

7. **Actions.**
   - `IngestHostTelemetryAction` — wraps in `DB::transaction`; updates host metadata (`fillIfPresent`); inserts snapshot; recursively calls `SyncContainerSnapshotsAction` for the container array (if present).
   - `SyncContainerSnapshotsAction` — `Container::updateOrCreate(['host_id'=>..., 'container_id'=>...], $payload)` then `ContainerMetricSnapshot::create(...)`. Computes `memory_percent` server-side from `memory_usage_mb / memory_limit_mb` when both are present, else null.

8. **Reference agent.**
   - `agent/reference-agent.mjs` (~120 LoC). Single-file ESM Node 20 script. No deps — uses built-in `fetch` + `node:child_process`.
   - Loop: every `NEXUS_AGENT_INTERVAL` seconds, run `docker info --format '{{json .}}'`, `docker stats --no-stream --no-trunc --format '{{json .}}'`, `docker ps -a --no-trunc --format '{{json .}}'`, parse, build payload, POST.
   - On 401, log + exit non-zero (token revoked or wrong URL — operator should see + fix).
   - On 429, sleep `Retry-After` then continue.
   - On 5xx / network error, log + retry next interval (no backoff; the interval itself is the gate).
   - `agent/README.md` documents env vars: `NEXUS_URL`, `NEXUS_AGENT_TOKEN`, optional `NEXUS_AGENT_INTERVAL` (default 30). Includes a sample `systemd` unit for the eventually-shipped install path.

9. **Tests.**
   - `tests/Feature/Agent/AuthenticateAgentMiddlewareTest.php` — happy path, no header, malformed bearer, revoked token, archived host, unknown token. Asserts `last_used_at` stamped on success only.
   - `tests/Feature/Agent/HostTelemetryControllerTest.php` — happy path round-trip (status flips to online, snapshot row written, container snapshot written), validation rejection, skew window rejection, 429 after 60 calls (drive `RateLimiter::hit('agent-token:1', 60)` directly to avoid 60 actual requests in the test).
   - `tests/Unit/Domain/Docker/IngestHostTelemetryActionTest.php` — first telemetry transitions `pending → online`; second telemetry leaves status alone; metadata only updates when present.
   - `tests/Unit/Domain/Docker/SyncContainerSnapshotsActionTest.php` — first call inserts container + 1 snapshot; second call updates container + appends a 2nd snapshot; missing container in payload doesn't drop existing rows.

## Acceptance criteria
- [x] `POST /agent/telemetry` with a valid bearer + payload returns `204` and persists host metadata + 1 host snapshot + N container snapshots.
- [x] Endpoint rejects: missing bearer (401), wrong-format bearer (401), revoked token (401), token belonging to an archived host (401), unknown token (401).
- [x] Token's `last_used_at` is stamped on each successful request.
- [x] First successful telemetry from a `pending` host flips status to `online` and stamps `last_seen_at`.
- [x] Per-token rate limit: 61st request inside 60 s returns `429` with `Retry-After`.
- [x] Payload outside the skew window (older than 1 h or further than 5 min in the future) is rejected with a 422.
- [x] Reference agent script + README live under `agent/`. Manual smoke: pointing `NEXUS_URL` at `composer run dev` + a real token causes telemetry rows to appear within one interval.
- [x] Pint clean, tests green (26 new), `npm run build` clean.

## Files touched

- `app/Http/Middleware/AuthenticateAgent.php` — new (auth + per-token rate limit)
- `bootstrap/app.php` — register `agent.auth` alias + CSRF exclusion for `/agent/telemetry`
- `app/Http/Controllers/Agent/HostTelemetryController.php` — new
- `app/Http/Requests/Agent/IngestTelemetryRequest.php` — new
- `app/Domain/Docker/Actions/IngestHostTelemetryAction.php` — new
- `app/Domain/Docker/Actions/SyncContainerSnapshotsAction.php` — new
- `app/Providers/AppServiceProvider.php` — comment-only stub (rate limiting moved into middleware; see Work log)
- `routes/web.php` — `/agent/telemetry` route + `withoutMiddleware` to strip session/cookie/Inertia chain
- `agent/reference-agent.mjs` — new
- `agent/README.md` — new
- `tests/Feature/Agent/AuthenticateAgentMiddlewareTest.php` — new (7 cases)
- `tests/Feature/Agent/HostTelemetryControllerTest.php` — new (10 cases)
- `tests/Unit/Domain/Docker/IngestHostTelemetryActionTest.php` — new (5 cases)
- `tests/Unit/Domain/Docker/SyncContainerSnapshotsActionTest.php` — new (5 cases)

## Work log

### 2026-05-01
- Spec drafted.
- Issue [#80](https://github.com/Copxer/nexus/issues/80) opened, branch `spec/027-agent-telemetry-ingestion` cut off `main`.
- **Implementation deviation: rate limiting moved into `AuthenticateAgent` middleware** instead of the planned `throttle:agent-telemetry` named limiter. The first attempt followed the spec's Plan §3+§4 (named limiter via `RateLimiter::for(...)` in AppServiceProvider, `throttle:agent-telemetry` middleware on the route). Tests showed the limiter callback always saw `agent_token = null` even with the route defined as `middleware(['agent.auth', 'throttle:agent-telemetry'])`. Reason: Laravel's default `MiddlewarePriority` runs `ThrottleRequests` before any unlisted custom middleware, so the named-limiter callback fires before `AuthenticateAgent` has a chance to set the request attribute. Options were (a) inject `AuthenticateAgent` into the priority list (brittle — would need to maintain Laravel's full priority array), or (b) collapse auth + throttle into one middleware. Went with (b): cleaner, self-contained, the limit fires only after we've identified the token. The spec's Plan steps still describe the abandoned approach for historical context.
- **Implementation deviation: route uses `withoutMiddleware([...])`** to strip session / cookie / Inertia stack from the `web` group. Surfaced during self-review: agents are non-browser JSON clients posting every 30 seconds, so leaving `StartSession` etc. on the route would spawn ~144k orphan session rows per day at 50 hosts. The exclusion list also includes `PreventRequestForgery` because that middleware refreshes the XSRF cookie at response time (calls `$request->session()`) regardless of the path-except list, which fails when `StartSession` isn't running.
- Self-review pass via `superpowers:code-reviewer` flagged 5 should-fix items + several nice-to-haves. Addressed: (1) session-stack stripping on the agent route, (2) `recorded_at` non-string rejection test (locks behavior for `0`, `false`, `[]`), (3) host-isolation test (same `container_id` on two hosts → distinct rows), (4) "skip status write when already Online" optimisation in `IngestHostTelemetryAction` to avoid 144k pointless `UPDATE hosts SET status='online'` writes per day, plus a test, (5) spec status flipped to `done` and Plan deviations recorded here. Tests grew 22 → 26.
- Final: full suite 460 passing, Pint clean, build green.

## Open questions / blockers
- **Container removal:** I'm deferring it. Once a container is gone from `docker ps -a`, its row stays in `containers` until a future cleanup spec. Acceptable trade-off — the alternative (delete-not-in-payload) risks racing a partial-failure agent post and dropping live containers. We can revisit when 028 or 029 surfaces a stale-container UI need.
- **Skew window:** 1 hour past / 5 min future. If the agent's clock is wrong by more than that, the host stays offline until the operator notices. Wide enough for daylight-savings, NTP drift; tight enough that a re-played payload from yesterday can't resurrect a dead host. Open to tuning.
- **Reference agent language:** Node, not Bash or Go. Node ships on every dev laptop, has built-in `fetch`, and gives us a single-file ESM artifact. Production-grade Go binary is roadmap §8.7's "later".
