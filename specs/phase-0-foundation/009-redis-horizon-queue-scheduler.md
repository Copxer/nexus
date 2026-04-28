---
spec: redis-horizon-queue-scheduler
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-28
updated: 2026-04-28
issue: https://github.com/Copxer/nexus/issues/21
branch: spec/009-redis-horizon-queue-scheduler
---

# 009 — Redis / Horizon / queue / scheduler wired up

## Goal
Wrap up Phase 0 by proving the runtime plumbing is alive: Redis is the cache/session/queue/broadcast backend, Laravel Horizon runs the queue, the scheduler runs heartbeat tasks, and a sample queueable job round-trips through Horizon. After this spec, the four roadmap-§26.2 commands — `php artisan horizon`, `queue:work`, `schedule:work`, `reverb:start` — all boot cleanly in dev with zero warnings, and a developer can dispatch a sample job and watch it complete on the Horizon dashboard.

This is a **wiring + verification** spec. The actual queueable jobs (GitHub sync, Docker polling, alert evaluation, etc.) belong to phases 2–7 — we're laying the rails, not the engines. The phase-level acceptance criterion in `specs/phase-0-foundation/README.md` is literal: "`php artisan horizon`, `queue:work`, `schedule:work` all start cleanly in dev." This spec satisfies that and a little more.

Roadmap reference: §4.4 Background Jobs (the queue use-cases), §4.5 Storage (Redis as cache + queue + locks), §26.2 Laravel Workers (the four canonical commands), §26.3 Environment Variables (the locked production config).

## Scope
**In scope:**

- **Verify the existing scaffolding boots cleanly.** Horizon, Predis, and the Reverb env block are already wired (specs 001 and the recent Reverb env work). Walk:
    1. `php artisan horizon --status` returns a clean state with no warnings
    2. `php artisan queue:work --once` exits 0 with no payload
    3. `php artisan schedule:work --verbose --once` lists the (empty or single-task) schedule and exits cleanly
    4. `php artisan reverb:start --port=8080 --hostname=127.0.0.1` (already works — covered by `dev:horizon` script)
    5. `redis-cli ping` returns `PONG` and Laravel's `Cache::store('redis')->put/get` round-trips a key

- **Plug a baseline heartbeat into the scheduler.** Add a single `Schedule::command('inspire')->everyMinute()` (or similar low-noise task) so `schedule:work` actually has something to run; otherwise it boots into a silent loop and the developer can't tell whether it's broken or just idle. A heartbeat is the simplest "is my scheduler alive" signal.

- **Add a sample queueable job and a manual dispatch route to prove the queue end-to-end.**
    - `app/Jobs/HeartbeatPing.php` — implements `ShouldQueue`, `handle()` writes a single info-level log line ("Heartbeat ping at <iso8601>"). Cheap, observable, idempotent.
    - `app/Console/Commands/HeartbeatPingCommand.php` (single-action artisan command, signature `app:heartbeat`) — dispatches the job. Lets a developer fire `php artisan app:heartbeat` and confirm the job lands in Horizon's "completed" list.
    - Wire that command into the scheduler too: `Schedule::command('app:heartbeat')->everyTenMinutes()` so `schedule:work` exercises the queue → Horizon → log path on its own.

- **Wire the Horizon dashboard gate.** Currently `App\Providers\HorizonServiceProvider::gate()` returns false for everyone in non-local environments. Update it to allow authenticated, email-verified users in the local environment without explicit allow-listing (matches the phase-0 single-user dev setup), and keep the empty allow-list for non-local (we'll populate it when production deploys land in phase 9). Also wrap the `/horizon` route in the `auth` + `verified` middleware via the gate (Horizon already does this if the gate exists — just confirm).

- **Update the dev composer script** to include `schedule:work` so a developer running `composer dev:horizon` gets the full local stack (server + horizon + reverb + scheduler + pail + vite). Currently scheduler is missing.

- **Add a feature test that asserts the dispatched job runs.** Use `Queue::fake()` for the dispatch unit (test the command enqueues `HeartbeatPing`) and use the real queue + `Bus::dispatchSync` for the end-to-end "the job runs without throwing" check.

- **Add an HTTP smoke test that `/horizon` returns 200 for an authenticated, verified user in the test environment** — this verifies the gate is wired correctly without relying on real Redis (Horizon's UI is server-rendered Blade, not Inertia, so it's fine to assert status alone).

- **Update `.env.example`** if anything is missing — confirm `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `BROADCAST_CONNECTION` are all present and match `§26.3`. Don't add Reverb credentials (those are gen-locally per-environment).

**Out of scope:**

- Production-grade Supervisor / systemd configs. Those ship with phase 9 deployment readiness.
- Real domain queueable jobs (GitHub sync, host polling, alert evaluation, etc.) — each lives in its own phase spec.
- Horizon notification routing (Slack / email). Roadmap §26.2 mentions it; can wait until alerts spec.
- Multi-tenant queue isolation, separate tenant queues, queue priority tuning. Defer.
- Queue retry/backoff tuning. Default Laravel + Horizon behavior is fine for phase 0.
- A Horizon dashboard brand re-skin into the Nexus dark theme. Horizon's UI is fine as-is for phase 0; the brand alignment is its own future polish.

## Plan

1. **Scaffold the heartbeat artifacts.**
    - `app/Jobs/HeartbeatPing.php` — `ShouldQueue` job with `handle()` writing to `Log::info()`.
    - `app/Console/Commands/HeartbeatPingCommand.php` — `app:heartbeat` command that dispatches the job.
    - `routes/console.php` — register both the heartbeat schedule (`app:heartbeat` every 10 min) and a low-noise canary (`inspire` every hour, just so `schedule:list` is never empty).

2. **Update `HorizonServiceProvider::gate()`** to allow any authenticated, email-verified user in the `local` environment. Keep the explicit-allowlist branch for non-local. Add a docblock noting the production allowlist gets populated in phase 9.

3. **Update `composer.json` `dev:horizon` script** to add `php artisan schedule:work` to the concurrently invocation.

4. **Update `.env.example`** if any §26.3 keys are missing. Cross-check `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `BROADCAST_CONNECTION`. (We expect them all to be present — `.env` was set up in spec 001.)

5. **Tests.**
    - `tests/Feature/Console/HeartbeatPingTest.php` — `Queue::fake()` + assert `Bus` dispatches `HeartbeatPing` when the command runs; then a separate test using `Bus::fake()` to verify the job's `handle()` runs without throwing.
    - `tests/Feature/Horizon/AccessTest.php` — `/horizon` returns 200 for a verified user in the testing environment.
    - The existing `SmokeTest` keeps passing untouched.

6. **Local boot smoke walk.**
    - Start Redis (`redis-cli ping`).
    - Start `php artisan horizon` — observe a clean "Horizon started successfully" line, no PHP warnings.
    - Run `php artisan app:heartbeat` — see "Heartbeat ping at …" in laravel.log within seconds.
    - Run `php artisan schedule:work --once` and verify it lists the new schedule entries.
    - Optionally hit `/horizon` in browser and confirm dashboard renders.
    - Capture findings in the Work log.

7. **Pipeline pass** — vue-tsc clean, Pint clean, build green, all tests pass.

8. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `php artisan horizon` boots cleanly with zero warnings (locally, with Redis up).
- [ ] `php artisan queue:work --once` exits 0 with no payload (queue is reachable, even if empty).
- [ ] `php artisan schedule:work --once` lists at least one scheduled task and exits cleanly.
- [ ] `php artisan app:heartbeat` dispatches `HeartbeatPing` to the queue; Horizon shows it in the completed list.
- [ ] `routes/console.php` registers `app:heartbeat` on a schedule (`->everyTenMinutes()`) so the queue → Horizon path runs autonomously.
- [ ] `App\Providers\HorizonServiceProvider::gate()` allows authenticated, verified users in `local`; non-local explicit allow-list stays empty (populated in phase 9).
- [ ] `composer dev:horizon` runs the full stack: server + horizon + reverb + scheduler + pail + vite.
- [ ] `.env.example` carries all §26.3 keys (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `BROADCAST_CONNECTION=reverb`, plus `REDIS_*`).
- [ ] New tests pass: `HeartbeatPingTest` (2 cases) + `Horizon\AccessTest` (1 case). Existing `SmokeTest` unchanged.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `app/Jobs/HeartbeatPing.php` — new queueable job.
- `app/Console/Commands/HeartbeatPingCommand.php` — new `app:heartbeat` command.
- `routes/console.php` — register heartbeat + inspire schedules.
- `app/Providers/HorizonServiceProvider.php` — allow verified users in `local`.
- `composer.json` — add `php artisan schedule:work` to `dev:horizon`.
- `.env.example` — confirm §26.3 keys present (likely no diff).
- `tests/Feature/Console/HeartbeatPingTest.php` — new.
- `tests/Feature/Horizon/AccessTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-28
- Spec drafted; scope confirmed (5 decisions locked: ship heartbeat sample, open Horizon gate in local, add `schedule:work` to dev script, keep `inspire` canary, keep two feature tests).
- Opened issue [#21](https://github.com/Copxer/nexus/issues/21) and branch `spec/009-redis-horizon-queue-scheduler` off `main`.
- Implemented the heartbeat path:
    - `app/Jobs/HeartbeatPing.php` — `ShouldQueue` job. `handle()` writes `Log::info('Heartbeat ping at <iso8601>')`.
    - `app/Console/Commands/HeartbeatPingCommand.php` — `app:heartbeat` artisan command, dispatches `HeartbeatPing`.
    - `routes/console.php` — `Schedule::command('app:heartbeat')->everyTenMinutes()` + `Schedule::command('inspire')->hourly()` canary.
- Opened the Horizon gate: `App\Providers\HorizonServiceProvider::gate()` allows any verified user when `app()->environment('local', 'testing')`. Non-local allow-list stays empty (phase 9 deploy spec populates it). Cleaned the unused `Horizon` import + the commented notification-routing examples that came from `horizon:install`.
- `composer.json` `dev:horizon` script gained `php artisan schedule:work` so the local stack now boots server + horizon + reverb + scheduler + pail + vite via one command.
- `.env.example` already carried all §26.3 keys (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `BROADCAST_CONNECTION=reverb`, `REDIS_*`) — no diff needed.
- Tests added:
    - `tests/Feature/Console/HeartbeatPingTest.php` — 2 cases: `Queue::fake()` confirms the command pushes `HeartbeatPing`; `Log::spy()` confirms `handle()` logs the expected ping line.
    - `tests/Feature/Horizon/AccessTest.php` — verified user can reach `/horizon` in the testing environment (gate's `local|testing` branch is hit).
- Local boot smoke walk:
    - `redis-cli ping` → `PONG` ✓
    - `php artisan horizon` → "Horizon started successfully." (3-sec timeout, exit 0) ✓
    - `php artisan queue:work --once` → exits 0 (queue reachable) ✓
    - `php artisan schedule:list` → shows both entries (`*/10` heartbeat, `0 *` inspire) ✓
    - `php artisan schedule:work` → "Running scheduled tasks." ✓
    - `php artisan app:heartbeat` → "Heartbeat ping dispatched." + log line confirmed in `storage/logs/laravel.log` ✓
- Pipeline: vue-tsc clean, Pint clean, `npm run build` green. **6/6 tests pass with 53 assertions** (2 new heartbeat + 1 new horizon access + 3 existing smoke).

## Decisions (locked 2026-04-28)
- **Heartbeat sample job — ship.** `HeartbeatPing` queueable job + `app:heartbeat` artisan command + 10-minute schedule. Proves the queue → Horizon → log path round-trips and doubles as a smoke target for future phases.
- **Horizon gate in local — open.** Authenticated, verified users get in without explicit allow-listing in dev. Production allow-list lands with phase 9.
- **`schedule:work` in `dev:horizon` script — add.** One-line change so `composer dev:horizon` boots the full stack with one command.
- **`inspire` canary — keep.** Hourly low-noise task so `schedule:list` is never empty and the scheduler exercises more than one entry.
- **Tests — keep.** Two tiny feature tests (`HeartbeatPingTest`, `Horizon\AccessTest`) — ~20 LOC each, protect against accidental regression of the gate or dispatch wiring.

## Open questions / blockers

- **Horizon dashboard styling.** Horizon ships with its own Tailwind UI that doesn't match the Nexus dark theme. Out of scope here — flagged for a future polish spec if it ever earns its own ticket.
- **Redis cluster vs single-node.** Phase 0 dev uses single-node `redis://127.0.0.1:6379`. Production cluster config ships with phase 9.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue** still present locally (specs 005–008). Not introduced by this spec; CI passes on PHP 8.4. Same disclaimer.
