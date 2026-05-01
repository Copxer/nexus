---
spec: hosts-and-agent-tokens
phase: 6
status: done
owner: Yoany
created: 2026-05-01
updated: 2026-05-01
---

# 026 — Hosts + Agent Tokens Scaffolding

## Goal
Lay the data + auth foundation for Phase 6. Add the `hosts`, `agent_tokens`, `containers`, `host_metric_snapshots`, and `container_metric_snapshots` tables; build the host CRUD UX inside Settings; and let the user mint an agent token (shown once, hashed at rest) and rotate it. No telemetry ingestion in this spec — that lands in 027.

Roadmap refs: §8.7 Docker Hosts, §9.1 Core Tables, §16.5 Agent Security.

## Scope

**In scope:**
- Migrations + models for `hosts`, `agent_tokens`, `containers`, `host_metric_snapshots`, `container_metric_snapshots`.
- `Host` policy + factory; `AgentToken` policy + factory.
- Host CRUD action classes (`CreateHostAction`, `UpdateHostAction`, `ArchiveHostAction`).
- Token issuance (`IssueAgentTokenAction`) — generates a random 40-char token, stores `hashed_token`, returns plaintext **once** in the response payload only.
- Token rotation (`RotateAgentTokenAction`) — invalidates the previous token by replacing the hash; previous plaintext is unrecoverable.
- Settings UI under `/settings/integrations/hosts` (or analogous route consistent with how other integrations live):
  - Hosts table.
  - Create / edit form.
  - "Show install command" panel that displays the agent token **once** after creation/rotation.
- Project detail page gets a "Hosts" tab placeholder (real wiring lands in 028).
- Tests for: action classes, policy gating, token issuance flow (token shown once, hash persisted), rotation flow.

**Out of scope:**
- The `/agent/telemetry` endpoint and `agent.auth` middleware (027).
- Reference agent script (027).
- Hosts index/show pages and metric rendering (028).
- Offline detection job + activity events (029).
- Alert table integration (Phase 7).

## Plan

1. **Migrations.** New files under `database/migrations/` in this order:
   - `create_hosts_table` — fields per roadmap §8.7 (`id`, `project_id`, `name`, `slug`, `provider`, `endpoint_url` *nullable*, `connection_type`, `status` enum (`pending|online|offline|degraded|archived`), `last_seen_at` *nullable*, `cpu_count`, `memory_total_mb`, `disk_total_gb`, `os`, `docker_version`, `metadata` json, `archived_at` *nullable*, timestamps). Slug unique per project.
   - `create_agent_tokens_table` — `id`, `host_id`, `name` (label), `hashed_token` (string, indexed unique), `last_used_at` *nullable*, `revoked_at` *nullable*, `created_by_user_id`, timestamps.
   - `create_containers_table` — fields per roadmap §8.7 (`id`, `host_id`, `project_id` *nullable*, `container_id`, `name`, `image`, `image_tag`, `status`, `state`, `health_status`, `ports` json, `labels` json, plus stats fields). Index on `(host_id, container_id)`.
   - `create_host_metric_snapshots_table` — `id`, `host_id`, `cpu_percent`, `memory_used_mb`, `memory_total_mb`, `disk_used_gb`, `disk_total_gb`, `load_average` (float), `network_rx_bytes` bigint, `network_tx_bytes` bigint, `recorded_at`, timestamps. Index `(host_id, recorded_at)`.
   - `create_container_metric_snapshots_table` — same shape but per-container plus `cpu_percent`, `memory_usage_mb`, `memory_limit_mb`, `block_read_bytes`, `block_write_bytes`. Index `(container_id, recorded_at)`.

2. **Models.**
   - `app/Models/Host.php` — belongsTo Project, hasMany AgentToken, hasMany Container, hasMany HostMetricSnapshot. Casts: `metadata` array, `last_seen_at` datetime, `archived_at` datetime, `status` to enum. `slug()` accessor.
   - `app/Models/AgentToken.php` — belongsTo Host, belongsTo User (creator). Hidden: `hashed_token`. Static helper `findActiveByPlaintext(string $plaintext): ?self` that hashes + lookups; used later by middleware.
   - `app/Models/Container.php`, `app/Models/HostMetricSnapshot.php`, `app/Models/ContainerMetricSnapshot.php`.

3. **Enums.** (Repo convention: top-level `app/Enums/`, not nested under domain folders.)
   - `app/Enums/HostStatus.php` — string enum (`pending|online|offline|degraded|archived`). Includes `badgeTone()` matching `WebsiteStatus::badgeTone()`.
   - `app/Enums/HostConnectionType.php` — `agent | ssh | docker_api | manual`.

4. **Actions.**
   - `app/Domain/Docker/Actions/CreateHostAction.php` — input DTO, persists `Host` with `status: pending`, returns model.
   - `app/Domain/Docker/Actions/UpdateHostAction.php`.
   - `app/Domain/Docker/Actions/ArchiveHostAction.php` — soft-archive (sets `archived_at`, status `archived`, revokes any active tokens).
   - `app/Domain/Docker/Actions/IssueAgentTokenAction.php` — generates `Str::random(40)`, computes `hash('sha256', ...)`, persists `AgentToken`, returns object containing `{token: AgentToken, plaintext: string}`. Plaintext is never logged.
   - `app/Domain/Docker/Actions/RotateAgentTokenAction.php` — marks previous token revoked, issues a new one.
   - `app/Domain/Docker/Actions/RevokeAgentTokenAction.php` — sets `revoked_at`.

5. **Policies.**
   - `app/Policies/HostPolicy.php` — view/update/delete tied to project membership (mirrors how `WebsitePolicy` was wired in Phase 5).
   - `app/Policies/AgentTokenPolicy.php` — same.

6. **Controllers + routes.** (Repo convention: hosts sit under `/monitoring/*` next to websites, per the comment in `WebsiteController.php`. Tests + Form Requests follow the same `Monitoring/` namespace.)
   - `app/Http/Controllers/Monitoring/HostController.php` — index/create/store/show/edit/update/destroy. Uses Inertia. Mirrors `WebsiteController` (private `transform()` for response shape, `ownedProjects()` helper, `?project_id=N` preselect on `create`).
   - `app/Http/Controllers/Monitoring/AgentTokenController.php` — `store`, `rotate`, `destroy`. Plaintext is returned via session flash on `store`/`rotate` so the Vue layer can show it once.
   - `app/Http/Requests/Monitoring/StoreHostRequest.php` + `UpdateHostRequest.php`.
   - Routes append to `routes/web.php` next to the websites routes:
     ```php
     Route::resource('monitoring/hosts', HostController::class)
         ->parameters(['hosts' => 'host'])
         ->names('monitoring.hosts');
     Route::post('/monitoring/hosts/{host}/tokens', [AgentTokenController::class, 'store'])
         ->name('monitoring.hosts.tokens.store');
     Route::post('/monitoring/hosts/{host}/tokens/{token}/rotate', [AgentTokenController::class, 'rotate'])
         ->name('monitoring.hosts.tokens.rotate');
     Route::delete('/monitoring/hosts/{host}/tokens/{token}', [AgentTokenController::class, 'destroy'])
         ->name('monitoring.hosts.tokens.destroy');
     ```

7. **Frontend (Monitoring/Hosts pages + components).**
   - `resources/js/Pages/Monitoring/Hosts/Index.vue` — table with name, project, status badge, last seen, agent token state.
   - `resources/js/Pages/Monitoring/Hosts/Create.vue` + `Edit.vue` — forms (mirrors `Monitoring/Websites/{Create,Edit}.vue`).
   - `resources/js/Pages/Monitoring/Hosts/Show.vue` — detail page for the host card + token panel. Metric rendering is intentionally deferred to 028; this page exists so the post-create redirect target is real.
   - `resources/js/lib/hostStyles.ts` — `hostStatusTone()` helper consumed by the existing shared `Components/Dashboard/StatusBadge.vue`. Mirrors `websiteStyles.ts`; avoids spinning up a host-specific badge wrapper for one tone map.
   - `resources/js/Components/Hosts/AgentTokenPanel.vue` — handles "show once" plaintext reveal (driven by `flash('agentTokenPlaintext')`) + copy-to-clipboard + rotate confirmation.
   - The project detail page already has a Hosts tab placeholder (verified in `resources/js/Pages/Projects/Show.vue:170`). No change needed here in 026 — the placeholder already shows "Phase 6" pending state.
   - Sidebar: `Hosts` link **stays disabled** until 028 wires it to `monitoring.hosts.index`.

8. **Tests.**
   - Feature: Settings hosts CRUD (index, store, update, archive). Auth required, policy enforced.
   - Feature: token issue — plaintext returned in response, `hashed_token` matches `hash('sha256', plaintext)`.
   - Feature: token rotate — old hash gone, new hash present, `last_used_at` cleared.
   - Unit: `IssueAgentTokenAction` does not log plaintext (assert via Pail/log channel mock or simply by not depending on the logger).
   - Existing test suites still green (`php artisan test`, `npm run build`, Pint).

## Acceptance criteria
- [ ] All five migrations apply cleanly on a fresh DB and roll back cleanly.
- [ ] Models + factories exist; `php artisan tinker` can create a Host + AgentToken via factory.
- [ ] Settings → Hosts page lists hosts under the current team's projects.
- [ ] Creating a host then minting a token displays the plaintext **once**, then only ever shows the hash state.
- [ ] Rotating a token replaces the active token; old plaintext no longer validates against any hash in DB.
- [ ] Project detail page shows a `Hosts` tab with an empty state.
- [ ] Sidebar still shows `Hosts` as disabled or marked "coming soon" — routing to a real index lands in 028.
- [ ] Pint clean, tests green, `npm run build` succeeds.

## Files touched
Fill in as work progresses.

- `database/migrations/...` — new migrations (5)
- `app/Models/Host.php` — new
- `app/Models/AgentToken.php` — new
- `app/Models/Container.php` — new
- `app/Models/HostMetricSnapshot.php` — new
- `app/Models/ContainerMetricSnapshot.php` — new
- `app/Enums/HostStatus.php` — new
- `app/Enums/HostConnectionType.php` — new
- `app/Domain/Docker/Actions/*.php` — new (5 actions)
- `app/Policies/HostPolicy.php` — new
- `app/Policies/AgentTokenPolicy.php` — new
- `app/Http/Controllers/Monitoring/HostController.php` — new
- `app/Http/Controllers/Monitoring/AgentTokenController.php` — new
- `app/Http/Requests/Monitoring/{StoreHostRequest,UpdateHostRequest,StoreAgentTokenRequest}.php` — new
- `app/Http/Middleware/HandleInertiaRequests.php` — share `flash.agentTokenPlaintext`
- `app/Providers/AppServiceProvider.php` — register HostPolicy + AgentTokenPolicy
- `routes/web.php` — register new routes
- `resources/js/Pages/Monitoring/Hosts/{Index,Create,Edit,Show}.vue` — new
- `resources/js/Components/Hosts/AgentTokenPanel.vue` — new
- `resources/js/lib/hostStyles.ts` — new (replaces planned HostStatusBadge.vue)
- `resources/js/types/index.d.ts` — extend `flash` with `agentTokenPlaintext`
- `tests/Feature/Monitoring/HostControllerTest.php` — new
- `tests/Feature/Monitoring/HostPolicyTest.php` — new
- `tests/Feature/Monitoring/AgentTokenLifecycleTest.php` — new

## Work log

### 2026-05-01
- Spec drafted.
- Issue [#78](https://github.com/Copxer/nexus/issues/78) opened, branch `spec/026-hosts-and-agent-tokens` cut off `main`.
- Implementation landed in one commit: 5 migrations, 5 models, 2 enums, 7 actions (incl. DTO), 2 policies, 2 controllers, 3 form requests, 4 Vue pages + 1 component + 1 style helper, route registrations, Inertia flash plumbing.
- Self-review pass via `superpowers:code-reviewer` surfaced 4 should-fix items: (1) rotate mismatched-pair test missing, (2) sibling-isolation feature tests missing for show/edit/update/destroy, (3) token name length silently truncated instead of validated, (4) `ArchiveHostAction` not idempotent on `archived_at`. All four addressed in a follow-up commit.
- Tests grew 20 → 27 (added rotate-mismatch, store-overlong-name, archive-idempotent, sibling-blocked × 4). Full suite 434 passing, Pint clean, build green.

## Open questions / blockers
- Are agent tokens scoped per-host (current plan) or per-team? Per-host is more secure and matches §16.5; sticking with per-host unless we change our minds.
- Should `endpoint_url` be required for `connection_type=agent`? Plan: no — agent pushes to us, so the field is informational only and stays nullable.
