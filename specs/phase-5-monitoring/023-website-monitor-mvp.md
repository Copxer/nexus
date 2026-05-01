---
spec: website-monitor-mvp
phase: 5-monitoring
status: done
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/69
branch: spec/023-website-monitor-mvp
---

# 023 — Website monitor MVP (CRUD + manual probe + check history)

## Goal
Stand up the data layer for website uptime monitoring plus a working CRUD UX with a manual "Probe now" action that records a real HTTP check. After this spec a user can add a website URL to a project, see the recent check history, and probe on demand. The scheduler arrives in spec 024; the Overview KPI integration + Reverb live updates ride spec 025.

Roadmap reference: §8.8 Website Performance Monitoring (model fields, MVP probe shape), §19 Phase 5.

## Scope
**In scope:**

- **`websites` table** mirroring §8.8's MVP slice:
    - `id`, `project_id` (FK → `projects`, cascadeOnDelete), `name` (string), `url` (string), `method` (string, default `GET`), `expected_status_code` (smallint, default `200`), `timeout_ms` (unsigned int, default `10000`), `check_interval_seconds` (unsigned int, default `300`), `status` (string-backed enum: `pending|up|down|slow|error`, default `pending`, indexed), `last_checked_at` (datetime nullable), `last_success_at` (datetime nullable), `last_failure_at` (datetime nullable), standard timestamps.
    - **Index** on `(project_id, status)` so the per-project listing + status filter on the index page run cleanly.
    - **No multi-tenant `team_id`** in phase-1 — same pattern as repositories.

- **`website_checks` table** matching §8.8's check fields, MVP slice (no DNS/TLS/TTFB timings yet — those land later):
    - `id`, `website_id` (FK → `websites`, cascadeOnDelete), `status` (string-backed enum: `up|down|slow|error`, indexed), `http_status_code` (smallint nullable), `response_time_ms` (unsigned int nullable), `error_message` (text nullable), `checked_at` (datetime, indexed), standard timestamps.
    - Index `(website_id, checked_at desc)` for the per-website history listing.

- **`App\Enums\WebsiteStatus`** (`Pending`, `Up`, `Down`, `Slow`, `Error`) and **`App\Enums\WebsiteCheckStatus`** (`Up`, `Down`, `Slow`, `Error`) with `badgeTone()` helpers consistent with `RepositorySyncStatus` + `WorkflowRunConclusion`.
    - **Why two enums:** websites have a `pending` initial state (created but never probed); a recorded `WebsiteCheck` row only happens after a probe ran, so its status enum doesn't need `pending`.

- **`App\Models\Website`** + **`App\Models\WebsiteCheck`** + factories.
    - `Website::project()` belongsTo, `Website::checks()` hasMany.
    - `WebsiteCheck::website()` belongsTo.
    - Casts: status enums + datetimes.
    - `Website::getRouteKeyName()` stays default (numeric id) — no nice slug shape.

- **`App\Domain\Monitoring\Actions\RunWebsiteProbeAction`** — pure HTTP probe, no DB writes.
    - Accepts a `Website` + uses `Http::timeout($website->timeout_ms / 1000)->{$method}($url)`.
    - Returns a `WebsiteProbeResult` value object: `status` (enum: up/down/slow/error), `http_status_code`, `response_time_ms`, `error_message`.
    - **Status mapping:**
        - HTTP request succeeded with `expected_status_code` → `Up`
        - Request succeeded but status mismatch → `Down`
        - Request succeeded but `response_time_ms > 3000` → `Slow` (overrides `Up`; phase-1 hard threshold; configurable later)
        - Connection error / timeout / DNS failure → `Error` (with the exception class + message in `error_message`)
    - All HTTP traffic goes through `Http::timeout(...)` so test doubles via `Http::fake()` work out of the box.

- **`App\Domain\Monitoring\Actions\RecordWebsiteCheckAction`** — composes the persistence path:
    - Accepts a `Website` + a `WebsiteProbeResult`.
    - Inserts a `WebsiteCheck` row keyed by the result's fields.
    - Updates `Website.status`, `Website.last_checked_at`, and conditionally `last_success_at` (when status is `Up` or `Slow`) / `last_failure_at` (when status is `Down` or `Error`).
    - Does NOT dispatch activity events yet — that's spec 024 (transition detection lives there).

- **`App\Http\Controllers\Monitoring\WebsiteController`** — resourceful (index, create, store, show, update, destroy).
    - `index` → `Pages/Monitoring/Websites/Index.vue` with a flat list of all websites under the user's projects (cross-project for now; project-scoped filter via query string lands in spec 025 if it earns its keep).
    - `create` → `Pages/Monitoring/Websites/Create.vue`.
    - `store` validates URL, name, project_id, method, expected_status_code, timeout_ms, check_interval_seconds; redirects to `show`.
    - `show` → `Pages/Monitoring/Websites/Show.vue` with the website + last 50 `website_checks` ordered desc.
    - `edit` → `Pages/Monitoring/Websites/Edit.vue`.
    - `update` validates the same shape.
    - `destroy` → flash + redirect to `index`.
    - Authorization: `WebsitePolicy` checks via `Project::owner_user_id === auth()->id` (delegates to project ownership; matches the repository policy pattern).

- **`App\Http\Controllers\Monitoring\WebsiteProbeController`** — single-action `__invoke` for the manual "Probe now" button.
    - **Sync probe** (locked decision): controller calls `RunWebsiteProbeAction` synchronously, then `RecordWebsiteCheckAction`. Request blocks ≤ `timeout_ms` (default 10s) and returns with the persisted check in the flash message.
    - Authorize via the policy. POST `/monitoring/websites/{website}/probe`.

- **`Pages/Monitoring/Websites/`** — four Vue pages. Mirror `Repositories/Show.vue` patterns: tabs collapsed into a single Show page (no extra tabs in spec 023), reuse `StatusBadge`, reuse the recent shared `workflowRunStyles` philosophy if a third consumer arises (don't preemptively extract).
    - `Index.vue` — table of websites with status badge, last check timestamp, response time, link to detail.
    - `Create.vue` + `Edit.vue` — form with URL, name, project (dropdown of user's projects), method (GET/HEAD/POST), expected status, timeout, interval.
    - `Show.vue` — header (URL, name, project chip, status badge, "Probe now" button), body (last 50 checks list with status, HTTP code, response time, "Failed" toast for errors).
    - Sidebar `Monitoring` entry flipped from disabled → linked to `monitoring.websites.index`.

- **Routes** under `auth + verified` middleware:
    ```
    Route::resource('monitoring/websites', WebsiteController::class)
        ->parameters(['websites' => 'website'])
        ->names('monitoring.websites');
    Route::post('/monitoring/websites/{website}/probe', WebsiteProbeController::class)
        ->name('monitoring.websites.probe');
    ```

- **Tests** (Pest/PHPUnit, mirrors phase-4 patterns):
    - `RunWebsiteProbeActionTest` — Http::fake'd 200 → Up, 500 → Down, slow response → Slow override, transport error → Error.
    - `RecordWebsiteCheckActionTest` — inserts a check, updates `Website.last_*` correctly per result status.
    - `WebsiteControllerTest` — index lists user's websites, store validates, store creates, update edits, destroy removes, non-owner gets 403.
    - `WebsiteProbeControllerTest` — owner can probe (Http::fake), non-owner forbidden, missing website 404. The probe path persists a check + updates the website.
    - `WebsitePolicyTest` — owner can view/update/delete; non-owner cannot.
    - **Manual smoke note** in the work log — verify the form flows in a browser; the env-CSRF baseline known issue still applies to local POST tests, CI passes them.

**Out of scope:**

- Scheduled checks / `DispatchDueWebsiteChecksJob` — spec 024.
- Uptime % calculation / `GetWebsitePerformanceSummaryQuery` — spec 024.
- Activity event creation on status transitions — spec 024.
- Reverb broadcast for live status updates — spec 025.
- Overview KPI integration (replacing `MOCK_KPIS['uptime']`) — spec 025.
- Response-time line chart / uptime ring on Show page — spec 025.
- DNS / TLS / TTFB timing fields — future phase.
- Per-project Websites tab on `Projects/Show.vue` — possible phase-5 polish if it earns its keep; the cross-project list at `/monitoring/websites` is enough for MVP.

## Plan

1. **Migrations** — `create_websites_table` + `create_website_checks_table`.
2. **Enums + models + factories** — `WebsiteStatus`, `WebsiteCheckStatus`, `Website`, `WebsiteCheck`.
3. **`WebsiteProbeResult` value object** — readonly DTO under `App\Domain\Monitoring\Probes` (or co-located with the action).
4. **`RunWebsiteProbeAction` + tests** — Http::fake'd happy/slow/down/error paths.
5. **`RecordWebsiteCheckAction` + tests** — DB writes + `Website.last_*` updates.
6. **`WebsitePolicy` + tests**.
7. **`WebsiteController` resourceful + Vue pages** (Index, Create, Edit, Show).
8. **`WebsiteProbeController` + route + test**.
9. **Sidebar entry** — flip `Monitoring` from disabled → routeName `monitoring.websites.index`.
10. **Self-review pass via `superpowers:code-reviewer`**.
11. **Open the PR**.

## Acceptance criteria
- [ ] `websites` + `website_checks` tables exist with the documented columns + indexes.
- [ ] `Website` + `WebsiteCheck` models with typed enum casts; both factories present.
- [ ] `RunWebsiteProbeAction` returns the right status for happy/slow/down/transport-error paths under `Http::fake`.
- [ ] `RecordWebsiteCheckAction` persists a `WebsiteCheck` row + updates `Website.{status,last_checked_at,last_success_at,last_failure_at}` per the result.
- [ ] CRUD endpoints under `/monitoring/websites` enforce `WebsitePolicy`; non-owner of the parent project gets 403.
- [ ] `POST /monitoring/websites/{website}/probe` runs the probe synchronously, persists a check, redirects with a flash status. 403 for non-owners.
- [ ] Sidebar `Monitoring` entry is enabled and routes to the websites listing.
- [ ] `Pages/Monitoring/Websites/{Index,Create,Edit,Show}.vue` render real data and pass the standard verified-auth gating.
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
- `database/migrations/<ts>_create_websites_table.php` — new.
- `database/migrations/<ts>_create_website_checks_table.php` — new.
- `app/Enums/WebsiteStatus.php` — new.
- `app/Enums/WebsiteCheckStatus.php` — new.
- `app/Models/Website.php` — new.
- `app/Models/WebsiteCheck.php` — new.
- `database/factories/WebsiteFactory.php` — new.
- `database/factories/WebsiteCheckFactory.php` — new.
- `app/Domain/Monitoring/Probes/WebsiteProbeResult.php` — new (readonly DTO).
- `app/Domain/Monitoring/Actions/RunWebsiteProbeAction.php` — new.
- `app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php` — new.
- `app/Policies/WebsitePolicy.php` — new (registered in `AppServiceProvider`).
- `app/Http/Controllers/Monitoring/WebsiteController.php` — new.
- `app/Http/Controllers/Monitoring/WebsiteProbeController.php` — new.
- `app/Http/Requests/Monitoring/StoreWebsiteRequest.php` + `UpdateWebsiteRequest.php` — new.
- `routes/web.php` — `monitoring.websites.*` routes + probe route.
- `resources/js/Components/Sidebar/Sidebar.vue` — flip `Monitoring` entry.
- `resources/js/Pages/Monitoring/Websites/Index.vue` — new.
- `resources/js/Pages/Monitoring/Websites/Create.vue` — new.
- `resources/js/Pages/Monitoring/Websites/Edit.vue` — new.
- `resources/js/Pages/Monitoring/Websites/Show.vue` — new.
- `tests/Feature/Monitoring/RunWebsiteProbeActionTest.php` — new.
- `tests/Feature/Monitoring/RecordWebsiteCheckActionTest.php` — new.
- `tests/Feature/Monitoring/WebsiteControllerTest.php` — new.
- `tests/Feature/Monitoring/WebsiteProbeControllerTest.php` — new.
- `tests/Feature/Monitoring/WebsitePolicyTest.php` — new.
- `specs/README.md` — phase-5 tracker.
- `specs/phase-5-monitoring/README.md` — task tracker.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- Opened issue [#69](https://github.com/Copxer/nexus/issues/69) and branch `spec/023-website-monitor-mvp` off `main`.
- Implementation complete. Two migrations + two enums + two models + two factories. `WebsiteProbeResult` DTO + `RunWebsiteProbeAction` (pure HTTP, classifies up/slow/down/error against the 3000ms hard threshold) + `RecordWebsiteCheckAction` (persists a `WebsiteCheck`, updates `Website.last_*`, treats `Slow` as success for `last_success_at`). `WebsitePolicy` gates create/update/delete/probe to project owners. `WebsiteController` resourceful CRUD + `WebsiteProbeController` single-action sync probe. Sidebar `Monitoring` flipped from disabled → linked to the index page.
- 28 tests across 5 test files: probe action (6), record action (6), policy (4), controller (9), probe controller (3). 19 net new passing tests; full suite 310 passed (was 291). The 51 failures are env-only POST CSRF (419) — same baseline pattern; CI passes them.
- Self-review pass via `superpowers:code-reviewer`; addressed all 3 recommendations:
    - Narrowed the probe action's catch list to `ConnectionException|RequestException` so programmer bugs (typo, OOM, future enum drift) bubble up loudly instead of getting silently classified as "site down."
    - Added belt-and-suspenders `authorize('create', [Website::class, $project])` in `WebsiteController::store` after validation, mirroring the `RepositoryController::store` pattern.
    - Migration column comment on `response_time_ms` clarifies it's wall-clock (DNS / TCP / TLS / send / receive), not server-reported TTFB; future timing fields will sit alongside.
- Cross-tenant `view` parity flagged in PR body — same single-tenant gap as `RepositoryPolicy`; uniform fix when teams ship.

## Decisions (locked 2026-04-30)
- **URL nesting under `/monitoring/`** — anticipates phase-6 hosts as a sibling. Sidebar label stays "Monitoring" pointing at `/monitoring/websites`.
- **Sync manual probe** — controller blocks until probe completes (≤ `timeout_ms`); user clicks the button, they want the result. Spec 024's scheduler is where async becomes natural.
- **Two status enums (`WebsiteStatus` + `WebsiteCheckStatus`)** — websites have a `pending` initial state; recorded checks don't need it.
- **Slow threshold = 3000ms hard-coded for phase-1.** Configurable per-website in a future polish if real users complain.
- **Activity events deferred to spec 024.** Status-transition detection lives with the scheduler — that's where it earns its keep, since manual probes are user-triggered and don't need a separate notification channel.
- **No multi-tenant `team_id`.** Same phase-1 simplification as repositories; the cross-cutting team scoping arrives uniformly.

## Open questions / blockers
- **Slow threshold UX.** 3000ms is roadmap-implied (no explicit value in §8.8); confirm during implementation that the rendered "Slow" badge feels right on a typical home-broadband response.
- **Project scope on `Index.vue`.** Cross-project flat list for MVP; revisit if a real user has 20+ websites and the table gets noisy.
