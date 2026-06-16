---
spec: reliability-hardening
phase: 9
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-16
updated: 2026-06-16
---

# 037 — Reliability hardening: retries, rate-limit handling, webhook retry UI

## Goal
Today every `ShouldQueue` job in the codebase has `$tries = 1`. A
transient DB hiccup or a GitHub rate-limit response silently
fails the job; the user sees a stale-looking sync card with a
`failed` badge and no way to retry. Spec 037 wires up the §18
retry matrix across the queued path: GitHub sync jobs get
exponential backoff + rate-limit-aware release, the webhook
processor retries 3× then surfaces, and the existing
`RepositorySyncStatus` enum gains the missing `rate_limited` +
`unauthorized` cases so the UI can decompose the failure mode.

Three concrete shifts:

1. **Retry policy on every queued job.** Sync jobs get
   `$tries = 3` + exponential `$backoff()`. Rate-limit responses
   `release()` until GitHub's `X-RateLimit-Reset`. Webhook job
   gets `$tries = 3`. Intentional `$tries = 1` stays for
   scheduler-bound jobs (website check, host offline detector,
   the analytics sweep) where the next tick is the retry path.
2. **§18.2 sync-status decomposition.** `RepositorySyncStatus`
   gains `RateLimited` + `Unauthorized`. Sync jobs map exception
   shapes onto the right state so a 401 doesn't read as a
   generic "Failed".
3. **Webhook delivery retry UI.** New `/settings/webhook-deliveries`
   page lists every row from `github_webhook_deliveries` with
   filters (status, event, repository), and exposes a "Retry"
   button on failed rows that re-dispatches
   `ProcessGitHubWebhookJob` against the stored payload. The
   payload + signature are already persisted for forensic audit
   (spec 017); retry just reuses them.

Roadmap refs: §18 Error Handling (retry matrix + sync statuses +
external API errors), §16 Security Requirements (rate limit
enforcement), spec 017 (webhook delivery store), spec 014
(repository sync status fields).

## Scope

**In scope:**

- **`RepositorySyncStatus` enum (`app/Enums/`).**
  - Add `case RateLimited = 'rate_limited';`
  - Add `case Unauthorized = 'unauthorized';`
  - Update `badgeTone()` mapping: rate-limited → `warning`,
    unauthorized → `danger`.
  - Existing `pending` / `syncing` / `synced` / `failed` cases
    keep current tones.
  - Stored as `string(16)` in the DB — no migration needed
    (column accepts any 16-char string).

- **Job retry + backoff matrix (`app/Domain/`).**
  - **`SyncGitHubRepositoryJob`, `SyncRepositoryIssuesJob`,
    `SyncRepositoryPullRequestsJob`, `SyncRepositoryWorkflowRunsJob`:**
    - `$tries = 3`.
    - `public function backoff(): array { return [60, 300, 900]; }`
      — 1 min / 5 min / 15 min exponential.
    - `public function failed(\Throwable $e): void` —
      persists the appropriate sync status (`RateLimited` /
      `Unauthorized` / `Failed`) and the error message
      (capped 500 chars). Mirrors the in-job catch block
      that already exists.
    - In the job body's `try / catch`, when the caught
      `GitHubApiException::wasRateLimited()` returns true,
      call `$this->release($resetSeconds)` instead of letting
      the catch persist `Failed`. The reset seconds come from
      a new `secondsUntilReset()` method on the exception
      class (computed from the rate-limit reset header
      already plumbed by `GitHubApiClient`).
  - **`ProcessGitHubWebhookJob`:** flip from `$tries = 1` →
    `$tries = 3`. Add `backoff(): array { return [10, 60]; }` —
    quick retry then longer. Add `failed()` handler that marks
    the delivery row `WebhookDeliveryStatus::Failed` with the
    final exception's message (capped 500 chars). Existing
    in-job catch stays — `failed()` is the catch's last-ditch
    record for cases the catch itself misses (eg. timeout).
  - **Intentional `$tries = 1` (unchanged, documented in
    docblocks):** `RunWebsiteCheckJob`, `DispatchDueWebsiteChecksJob`,
    `DetectOfflineHostsJob`, `RecomputeProjectHealthScoreJob`,
    `RecomputeAllProjectHealthScoresJob`. Their next-tick
    cadence is the retry path; per-job retry would double-write
    or mask transient failures the user should see.

- **`GitHubApiException` (`app/Domain/GitHub/Exceptions/`).**
  - Add `?int $rateLimitResetAt = null` constructor param +
    property.
  - Add `public function secondsUntilReset(): int { return max(0, ($this->rateLimitResetAt ?? 0) - time()); }`.
  - Update `wasRateLimited()` to ALSO return true when
    `rateLimitResetAt !== null` (header-driven path, not just
    the heuristic message check).

- **`GitHubApiClient` (or whatever wraps the HTTP calls).**
  - On a 429 (or 403 with rate-limit headers), parse
    `X-RateLimit-Reset` and throw `GitHubApiException` with
    `rateLimitResetAt: (int) $resetUnixTs`. This puts the right
    information on the exception for the job's catch to
    decide between retry / release.
  - If the existing client doesn't centralize HTTP calls,
    extend the per-caller paths instead. Verify during impl.

- **Webhook delivery retry UI.**
  - `app/Http/Controllers/Settings/WebhookDeliveryController.php`
    — single-action invokable `__invoke` for the index page,
    plus an action method `retry(WebhookDelivery $delivery)`.
  - Routes: `GET /settings/webhook-deliveries` →
    `webhook-deliveries.index`, `POST
    /settings/webhook-deliveries/{delivery}/retry` →
    `webhook-deliveries.retry`. Both inside the existing
    `auth + verified` group.
  - `Pages/Settings/WebhookDeliveries.vue`:
    - Lists rows from `github_webhook_deliveries` (paginated,
      30/page). Each row: status badge (mapping `received` →
      info, `processed` → success, `failed` → danger,
      `skipped` → muted), event name + action, repository
      `full_name`, received-at + processed-at humanized,
      error message excerpt for failed rows.
    - Filter strip: status dropdown (all / received /
      processed / failed / skipped), event dropdown, free-
      text repository search. URL-backed like the Alerts
      filter pattern.
    - Retry button on `failed` rows only — POSTs to the
      retry route, flashes "Webhook re-queued." on success.
  - `retry()` action:
    - Authorizes via the existing settings access
      (any verified user — single-tenant phase-1).
    - Re-dispatches `ProcessGitHubWebhookJob` with the
      same `delivery_id`. The job re-runs its handler chain
      against the persisted payload.
    - Resets the delivery row to
      `WebhookDeliveryStatus::Received` so a successful retry
      ends as `Processed`.
  - Sidebar entry: a new "Webhook Deliveries" link under
    Settings (or as a section in `/settings` itself —
    cheaper). Pick the in-settings tab on impl.

- **Sync job catch-paths emit the new statuses.**
  - In each sync job's `catch (GitHubApiException $e)`, branch:
    - `$e->wasRateLimited()` → persist
      `RepositorySyncStatus::RateLimited`.
    - `$e->getCode() === 401` → persist
      `RepositorySyncStatus::Unauthorized`.
    - otherwise → existing `Failed` path.
  - Generic `catch (Throwable $e)` continues to map to
    `Failed`.

- **Tests.**
  - `tests/Unit/Domain/GitHub/Exceptions/GitHubApiExceptionTest.php`
    — `wasRateLimited()` true when `rateLimitResetAt` is set;
    `secondsUntilReset()` returns the right delta;
    forward-only (won't return negative).
  - `tests/Unit/Domain/GitHub/Jobs/SyncRepositoryIssuesJobTest.php`
    — extend with: rate-limit exception triggers
    `$this->release(N)` instead of persisting `Failed`;
    401 persists `Unauthorized`; transient error retries (the
    job's `attempts() < $tries` path); third failure persists
    `Failed` via the `failed()` handler.
  - `tests/Unit/Domain/GitHub/Jobs/ProcessGitHubWebhookJobTest.php`
    — `failed()` handler marks the delivery `Failed` with the
    exception message.
  - `tests/Feature/Settings/WebhookDeliveriesIndexTest.php`
    — verified user can list; status filter narrows; payload
    shape carries the expected fields.
  - `tests/Feature/Settings/WebhookDeliveryRetryTest.php`
    — failed delivery → POST retry → status flips to
    `Received`, job dispatched (via `Bus::fake()`),
    success flash present.
  - Existing `RepositorySyncStatusTest` (or wherever the
    enum is tested today) — add cases for the two new
    statuses.

**Out of scope:**

- **Bulk retry of failed deliveries** — single-row retry
  ships here; "retry all failed since X" is a polish
  follow-up.
- **Activity-feed entry on retry** — surfacing the retry on
  the right-rail is small but pushes Activity surface area
  that isn't load-bearing for the spec's goal. Defer.
- **Per-job admin dashboard** — the existing Horizon UI
  already shows queue health (spec 009). A Nexus-styled job
  list isn't needed.
- **§17 self-monitoring alerts** (queue backlog,
  webhook-failure-rate, agent-ingestion-failure-rate) —
  those land in **spec 038** (self-monitoring), not here.
  This spec sets the groundwork by ensuring failures are
  surfaced + actionable.
- **GitHub App webhook auto-subscription** — manual setup
  stays manual (deferred per spec 017's own polish notes).
- **`Activity event title i18n`** + per-locale error messages
  — out of scope; English-only.

## Plan

1. **Enum.** Add the two cases to `RepositorySyncStatus`. Update
   `badgeTone()`. One enum test addition.

2. **`GitHubApiException`.** Constructor + property + two new
   methods. One unit test file.

3. **GitHub HTTP client.** Find the call site(s) that wrap 4xx/5xx
   responses; on 429 (or 403 + `X-RateLimit-Remaining: 0` per
   GitHub docs), parse `X-RateLimit-Reset` and pass to the
   exception. The client may not be centralized — verify during
   impl and either centralize or fan out the change.

4. **Sync jobs.** Add `$tries`, `backoff()`, `failed()` to each of
   the four sync jobs. Branch the catch to emit `RateLimited` /
   `Unauthorized` / `Failed`. The `failed()` handler is the safety
   net for cases the catch doesn't fire (Laravel-level timeout,
   serialization error, etc.).

5. **Webhook job.** Flip to `$tries = 3` + `backoff([10, 60])` +
   `failed()` that updates the delivery row.

6. **Controller + page.** `WebhookDeliveryController` (index +
   retry actions), route registrations, Vue page with filter
   strip + retry CTA. Mirror the Alerts page filter shape (URL-
   backed `status`, `event`, `repository_full_name` params).

7. **Sidebar + Settings entry.** Add a "Webhook Deliveries" link.
   Decide on placement during impl — inline tab vs. dedicated
   page.

8. **Tests.** Per the test list. Use `Bus::fake()` for retry
   dispatch verification, `Queue::fake()` for backoff-array
   assertions, `Carbon::setTestNow()` for `secondsUntilReset`
   determinism.

9. **Pint clean, full suite + build green, self-review with
   `superpowers:code-reviewer`, PR, watch CI, pause for merge.**

## Acceptance criteria
- [ ] Every queued sync job retries up to 3× with exponential
      backoff `[60, 300, 900]` before its `failed()` handler
      persists the terminal status.
- [ ] A GitHub 429 response causes the job to `release()` until
      `X-RateLimit-Reset` instead of immediately failing; the
      sync row reports `rate_limited` while the lock holds.
- [ ] A GitHub 401 response causes the job to persist
      `unauthorized` on the repository's sync status (matches
      §18.2 vocabulary).
- [ ] `ProcessGitHubWebhookJob` retries 3× then `failed()` marks
      the delivery `Failed` with the exception message.
- [ ] User can navigate to `/settings/webhook-deliveries`, filter
      by status / event / repository, and click "Retry" on a
      failed row. The row flips back to `Received` and the job
      re-runs against the stored payload.
- [ ] No regression on the intentional `$tries = 1` jobs
      (`RunWebsiteCheckJob`, `DispatchDueWebsiteChecksJob`,
      `DetectOfflineHostsJob`, the analytics recompute pair).
- [ ] Pint clean. `php artisan test` green. `npm run build` clean.

## Files touched

- `app/Enums/RepositorySyncStatus.php` — two cases + tones
- `app/Domain/GitHub/Exceptions/GitHubApiException.php` —
  rateLimitResetAt + secondsUntilReset
- GitHub HTTP client surface (TBD during impl) — 429 plumbing
- `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php` —
  retry matrix + failed() + status branch
- `app/Domain/GitHub/Jobs/SyncRepositoryIssuesJob.php` — same
- `app/Domain/GitHub/Jobs/SyncRepositoryPullRequestsJob.php` —
  same
- `app/Domain/GitHub/Jobs/SyncRepositoryWorkflowRunsJob.php` —
  same
- `app/Domain/GitHub/Jobs/ProcessGitHubWebhookJob.php` —
  `$tries = 3`, backoff, failed()
- `app/Http/Controllers/Settings/WebhookDeliveryController.php`
  — created
- `routes/web.php` — index + retry routes
- `resources/js/Pages/Settings/WebhookDeliveries.vue` — created
- `resources/js/Components/Sidebar/Sidebar.vue` — entry
- `tests/Unit/Domain/GitHub/Exceptions/GitHubApiExceptionTest.php`
  — created (or extended)
- `tests/Unit/Domain/GitHub/Jobs/SyncRepositoryIssuesJobTest.php`
  — extended with retry + rate-limit + auth + failed() cases
- `tests/Unit/Domain/GitHub/Jobs/ProcessGitHubWebhookJobTest.php`
  — failed() case
- `tests/Feature/Settings/WebhookDeliveriesIndexTest.php` —
  created
- `tests/Feature/Settings/WebhookDeliveryRetryTest.php` —
  created

## Work log
Dated notes as work progresses.

### 2026-06-16
- Drafted from `_template.md`. Spec 036's reduced-motion + theme
  primitive surface settled; 037 picks up the §18 retry matrix
  + the webhook retry UI that 036's error states implied.
- Branch `spec/037-reliability-hardening` cut off main.
- Tracking issue #109.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **GitHub HTTP client centralization.** Need to verify during
  impl whether 429 handling can land in one client class or
  needs fanning out across per-caller `Http::get()` paths. If
  decentralized, this spec either centralizes (~+2h) or
  fans out the patch.
- **`failed()` vs in-job `catch`.** Both paths persist the sync
  status. The in-job catch fires synchronously and can branch
  on exception type cleanly; `failed()` fires after Laravel
  gives up on retries and runs in a fresh container, so it
  doesn't have the original exception's full shape (only the
  serialized form). Picking the right division: catch handles
  the *known* error modes (rate-limit, 401, timeout), `failed()`
  handles the *unknown* / Laravel-level catastrophes (worker
  killed, queue-driver hiccup). Verified during impl.
- **Webhook deliveries page placement.** Inline tab in
  `/settings` vs. dedicated `/settings/webhook-deliveries`.
  Inline keeps the surface count low but loses the URL-
  backed filter contract. Dedicated wins on bookmarkability.
  Decision deferred to impl — defaulting to dedicated.
