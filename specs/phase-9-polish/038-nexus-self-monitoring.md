---
spec: nexus-self-monitoring
phase: 9
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-18
updated: 2026-06-18
---

# 038 — Nexus self-monitoring: internal alerts + health card

## Goal
Phase 9 is about preparing Nexus for daily use. Specs 036 / 037
hardened the front of house (loading states, error boundaries,
retry logic). 038 turns the lens around: Nexus monitors itself.

Today queue backlog, webhook failure rate, GitHub rate-limit
remaining, and agent ingestion failures are all invisible until
something breaks visibly downstream. Horizon at `/horizon` shows
queue health, but it lives outside the Nexus UI and isn't linked
from anywhere. The roadmap §17 list of "internal system alerts"
(queue backlog too high, GitHub rate limit almost exhausted,
webhook failures increasing, agent token invalid attempts) doesn't
fire anywhere today.

Three concrete shifts:

1. **`EvaluateSystemHealthJob`** — scheduled every minute. Queries
   four sources (queue / webhook deliveries / GitHub rate / agent
   auth failures) against threshold tables. On a breach, calls
   `TriggerAlertAction` with the existing `AlertSource::System`
   vocabulary so the alert lands on `/alerts` next to user-level
   alerts. Auto-resolves when the source recovers (uses the same
   idempotency contract spec 030 ships).
2. **System health card on `/settings`** — read-side
   `GetSystemHealthQuery` exposes the same four signals as live
   numbers. The card renders a `KpiCard`-style grid with status
   tones + a "View details" link into Horizon for queue + Nexus's
   own `/alerts` for the rest. No realtime — page-load reads only.
3. **Agent auth-failure capture** — `AuthenticateAgent` middleware
   already tracks rate-limit attempts via Laravel's `RateLimiter`.
   Spec 038 adds an `agent.auth.failure` activity event on every
   rejected token so the evaluator can count "invalid attempts in
   last 5 min" without a new table.

Roadmap refs: §17 Observability for Nexus Itself, §18 Error
Handling (retry matrix consumes the new vocabulary), spec 030
Alerts engine (reuses `TriggerAlertAction`).

## Scope

**In scope:**

- **`AlertSource::System` adoption.** The enum case already exists
  but no emitter uses it today. Spec 038 makes it the canonical
  source for "Nexus is unhappy" alerts.

- **`GetSystemHealthQuery` (`app/Domain/Observability/Queries/`).**
  Read-only aggregate. Returns:
  ```php
  [
      'queue' => [
          'pending' => int,       // SELECT count from jobs
          'failed_5m' => int,     // SELECT count from failed_jobs WHERE failed_at > now-5m
          'status' => 'success'|'warning'|'danger'|'muted',
      ],
      'webhooks' => [
          'deliveries_5m' => int,
          'failures_5m' => int,
          'failure_rate_percent' => float|null,
          'status' => '...',
      ],
      'github_rate_limit' => [
          'remaining' => int|null, // null when no recent snapshot
          'reset_at_iso' => string|null,
          'status' => '...',
      ],
      'agent_auth' => [
          'failures_5m' => int,    // count of `agent.auth.failure` activity events
          'status' => '...',
      ],
  ]
  ```
  No persistence — every value computes from the live tables.

- **`CheckGitHubRateLimitJob` (`app/Domain/Observability/Jobs/`).**
  Hits GitHub's `/rate_limit` endpoint once every 10 minutes per
  *connected user* (queries `GithubConnection` for valid tokens).
  Persists the response to a tiny `github_rate_limit_snapshots`
  table (`user_id`, `remaining`, `limit`, `reset_at`,
  `recorded_at`). The query above reads the latest snapshot. This
  is the only new table — keeps the dashboard's rate-limit reading
  honest without overloading every sync job's response path with
  side-effects.

- **`EvaluateSystemHealthJob`
  (`app/Domain/Observability/Jobs/`).** Scheduled `everyMinute()`,
  `withoutOverlapping()`. Runs `GetSystemHealthQuery` then, for
  each signal whose status is `warning`/`danger`, calls
  `TriggerAlertAction` with `AlertSource::System` +:
  - `type: 'queue.backlog_high'` (severity = warning/critical by
    threshold)
  - `type: 'queue.failures_high'`
  - `type: 'webhook.failure_rate_high'`
  - `type: 'github.rate_limit_low'`
  - `type: 'agent.auth_failures_high'`

  On signals that recover (status flips back to `success`),
  calls `ResolveAlertAction` against the same source + type.
  Idempotency is automatic — `TriggerAlertAction` already returns
  the existing open row if one matches.

- **Thresholds (named constants on `EvaluateSystemHealthJob`).**
  ```
  QUEUE_BACKLOG_WARNING    = 100
  QUEUE_BACKLOG_CRITICAL   = 500
  QUEUE_FAILURES_5M_WARN   = 5
  QUEUE_FAILURES_5M_CRIT   = 20
  WEBHOOK_FAILRATE_WARN_PCT = 20
  WEBHOOK_FAILRATE_CRIT_PCT = 50
  WEBHOOK_MIN_SAMPLE       = 5    // don't fire on a quiet account
  GITHUB_RATE_REMAINING_WARN = 100
  GITHUB_RATE_REMAINING_CRIT = 20
  AGENT_AUTH_FAILURES_5M_WARN = 10
  AGENT_AUTH_FAILURES_5M_CRIT = 50
  ```

- **Agent auth-failure capture.** `AuthenticateAgent` middleware
  on a rejected token (invalid hash, missing header, rate-limit
  hit) dispatches an activity event:
  ```php
  CreateActivityEventAction::execute([
      'event_type' => 'agent.auth.failure',
      'severity' => ActivitySeverity::Warning,
      'source' => 'agent',
      'title' => 'Agent token rejected',
      'metadata' => ['ip' => $request->ip(), 'reason' => $reason],
  ]);
  ```
  The evaluator counts these for the `agent.auth_failures_high`
  signal. No new table — activity events are the persistence.

- **Settings page card.**
  - `SettingsController` enriches the Inertia payload with the
    `GetSystemHealthQuery` result.
  - `Pages/Settings/Index.vue` renders a new "System health"
    section above the existing Integrations block. Four small
    `KpiCard`s in a 2x2 grid: Queue / Webhooks / GitHub rate /
    Agent auth. Each carries a status badge + a "View details"
    link (Horizon for queue, `/settings/webhook-deliveries` for
    webhooks, `/alerts` for the rest).

- **Tests.**
  - `tests/Unit/Domain/Observability/Queries/GetSystemHealthQueryTest.php`
    — happy path (all-green); each signal flips correctly per
    threshold; empty-state (no deliveries / no agent traffic / no
    snapshot) returns `muted`.
  - `tests/Unit/Domain/Observability/Jobs/EvaluateSystemHealthJobTest.php`
    — triggers an alert on threshold breach, doesn't re-trigger
    when one already open (idempotency), auto-resolves on
    recovery, doesn't fire when sample size is below
    `WEBHOOK_MIN_SAMPLE`.
  - `tests/Unit/Domain/Observability/Jobs/CheckGitHubRateLimitJobTest.php`
    — happy path persists a snapshot; 401 marks the connection
    expired (reuses spec 037's path); no GitHub connections → no
    HTTP calls.
  - `tests/Feature/Http/Middleware/AuthenticateAgentTest.php` —
    extend with: invalid token dispatches `agent.auth.failure`
    activity event; valid token does not.
  - `tests/Feature/Settings/SystemHealthCardTest.php` — settings
    payload carries the health shape; card renders four KPIs
    with the expected statuses.

**Out of scope:**

- **Per-failure agent log table.** The activity event stream is
  sufficient for the count signal; an indexed
  `agent_ingestion_failures` table is a polish follow-up.
- **Time-series graphs / sparklines.** The card shows current
  values + status. A "queue depth over time" chart is its own
  spec (would need a snapshots table + retention policy).
- **Realtime updates.** Self-monitoring is intentionally a
  page-load read — the worst-case 1-minute staleness matches the
  evaluator's tick interval.
- **Email / Slack / webhook notifications** when internal alerts
  fire — `AlertNotificationService` stays deferred.
- **Horizon dashboard theming.** The link from the queue card
  points at Horizon's own UI; matching the dark theme is its
  own polish spec.
- **Database performance metrics** (slow-query log, connection
  count). Roadmap §17 lists it but tooling is heavy; defer to
  a perf-pass follow-up.
- **`/admin` route.** Self-monitoring lives on `/settings` next
  to the existing operational surfaces. A dedicated `/admin`
  + role-based access lands with the multi-tenant migration.

## Plan

1. **`github_rate_limit_snapshots` table.** Migration:
   ```php
   $table->id();
   $table->foreignId('user_id')->constrained()->cascadeOnDelete();
   $table->unsignedInteger('remaining');
   $table->unsignedInteger('limit');
   $table->timestamp('reset_at');
   $table->timestamp('recorded_at')->index();
   $table->timestamps();
   ```
   Model is bare — read access only.

2. **`GetSystemHealthQuery`.** Pure read. Four private methods,
   one per signal. Status mapper uses a small `statusFor()`
   helper that takes the value + thresholds and returns the tone
   string.

3. **`EvaluateSystemHealthJob`.** `everyMinute()` schedule.
   Inside: pull the query result, loop through the four signal
   blocks, branch on status. For each warning/critical: trigger.
   For each success that has a matching open alert: resolve.

4. **`CheckGitHubRateLimitJob`.** Iterates connected users with
   non-expired tokens, hits `GET /rate_limit`, upserts the
   snapshot. Reuses spec 037's 401 expire-connection path.
   `every10Minutes()` schedule.

5. **Agent auth failure activity dispatch.** Patch
   `AuthenticateAgent::handle()` to call
   `CreateActivityEventAction` before returning 401/429/403.

6. **Settings page integration.** Controller enriches payload;
   Vue renders 2x2 KPI grid above Integrations.

7. **Tests** per the list. Use `Queue::fake()` for trigger
   verification, `Http::fake()` for GitHub's `/rate_limit`,
   `Carbon::setTestNow()` for the windowed counts.

8. **Pint clean, full suite + build green, self-review with
   `superpowers:code-reviewer`, PR, watch CI, pause for merge.**

## Acceptance criteria
- [ ] Settings page renders a "System health" card with four
      KPIs (Queue / Webhooks / GitHub rate / Agent auth), each
      with a status tone and a context link.
- [ ] Queue backlog > 100 → warning alert; > 500 → critical.
      Backlog dropping back below threshold auto-resolves.
- [ ] Webhook failure rate > 20% over the last 5 minutes (with
      at least 5 deliveries in window) → warning alert. > 50% →
      critical.
- [ ] GitHub rate remaining < 100 (across the most-recent
      snapshot for any connected user) → warning. < 20 →
      critical.
- [ ] Agent auth failures > 10 in 5 minutes → warning. > 50 →
      critical.
- [ ] Every rejected agent request dispatches an
      `agent.auth.failure` activity event with the IP + reason
      in metadata.
- [ ] `EvaluateSystemHealthJob` runs every minute under the
      scheduler; `CheckGitHubRateLimitJob` runs every 10 minutes.
- [ ] Pint clean. `php artisan test` green. `npm run build`
      clean.

## Files touched
- `database/migrations/2026_06_*_create_github_rate_limit_snapshots_table.php`
- `app/Models/GithubRateLimitSnapshot.php` — created
- `app/Domain/Observability/Queries/GetSystemHealthQuery.php` — created
- `app/Domain/Observability/Jobs/EvaluateSystemHealthJob.php` — created
- `app/Domain/Observability/Jobs/CheckGitHubRateLimitJob.php` — created
- `routes/console.php` — schedule entries
- `app/Http/Middleware/AuthenticateAgent.php` — activity dispatch on reject
- `app/Http/Controllers/SettingsController.php` — pass health into payload
- `resources/js/Pages/Settings/Index.vue` — System health card
- `tests/Unit/Domain/Observability/Queries/GetSystemHealthQueryTest.php` — created
- `tests/Unit/Domain/Observability/Jobs/EvaluateSystemHealthJobTest.php` — created
- `tests/Unit/Domain/Observability/Jobs/CheckGitHubRateLimitJobTest.php` — created
- `tests/Feature/Http/Middleware/AuthenticateAgentTest.php` — extended
- `tests/Feature/Settings/SystemHealthCardTest.php` — created

## Work log
Dated notes as work progresses.

### 2026-06-18
- Drafted from `_template.md`. Builds on 030's `TriggerAlertAction`
  for the internal-alert vocabulary and 037's
  `GitHubApiException` plumbing for rate-limit awareness.
- Branch `spec/038-nexus-self-monitoring` cut off main.
- Tracking issue #112.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **Settings page vs. dedicated `/admin`.** Picked Settings to
  keep the operational surfaces unified. If multi-tenant lands
  and admin-only views become a thing, a `/admin/system-health`
  page can wrap `GetSystemHealthQuery` without rewriting the
  read path.
- **GitHub rate-limit polling cadence.** Every 10 minutes per
  connected user. With ~5 users that's ~30 API calls per hour
  against the user's per-hour quota of 5000 — well under any
  reasonable threshold. Could drop to every 30 min if users
  complain.
- **`agent.auth.failure` event volume.** A misbehaving agent in
  a tight retry loop could spam the activity rail with one
  event per second. The evaluator only counts the last 5 min,
  but the rail / `/activity` page could surface noise.
  Counter-measure: rate-limit the event dispatch in the
  middleware (one event per IP per minute) — defer if it
  becomes a problem.
- **`Horizon::stats()` integration.** Could replace some of the
  queue queries with live Horizon metrics if available, but the
  raw `jobs` + `failed_jobs` count queries are cheap and
  driver-agnostic — keep them for now.
