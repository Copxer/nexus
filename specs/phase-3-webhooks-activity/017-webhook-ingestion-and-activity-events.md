---
spec: webhook-ingestion-and-activity-events
phase: 3-webhooks-activity
status: in-progress
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/45
branch: spec/017-webhook-ingestion-and-activity-events
---

# 017 — Webhook ingestion + activity events

## Goal
Stand up the GitHub webhook ingestion stack end-to-end: a signed `POST /webhooks/github` endpoint, signature verification (rejecting bad signatures with 401 + zero-side-effects), idempotent storage in `github_webhook_deliveries`, and an async `ProcessGitHubWebhookJob` that routes to event-specific handlers. Initial handler set covers `issues` and `pull_request` — they update the spec 015/016 local mirrors and create `activity_events` rows so the user-visible activity stream has data to render in spec 018. No UI changes in this spec; everything is testable via signed fixture payloads and DB assertions.

This spec ships the **backend half** of phase 3. Spec 018 lights up the visible feed, spec 019 layers Reverb broadcasting + extends the handler set to `workflow_run`, `push`, `release`, etc.

Roadmap reference: §8.5 GitHub Webhooks (signature verification, `github_webhook_deliveries` schema, processing flow), §8.10 Activity Feed (`activity_events` schema + event types).

## Scope
**In scope:**

- **`github_webhook_deliveries` table** matching §8.5 exactly:
    - `id` (PK), `github_delivery_id` (string, unique — GitHub's `X-GitHub-Delivery` header), `event` (string, e.g. `issues`/`pull_request`), `action` (string nullable, e.g. `opened`/`closed` from the payload's `action` field), `repository_full_name` (string, nullable), `payload_json` (json), `signature` (string), `status` (enum: `received`/`processed`/`failed`/`skipped`), `error_message` (text, nullable), `received_at` (datetime), `processed_at` (datetime, nullable), standard `created_at`/`updated_at`.
    - **Compound** index on `(event, action)` for future filtering, plus the `github_delivery_id` unique index for idempotency.

- **`activity_events` table** matching §8.10's field set with phase-1 simplifications:
    - `id`, `repository_id` (nullable FK → `repositories`, set null on delete), `actor_login` (string, nullable — the GitHub user who triggered it), `source` (string, default `github`), `event_type` (string, e.g. `issue.created`/`pull_request.merged`), `severity` (enum: `info`/`success`/`warning`/`danger`, default `info`), `title` (string), `description` (text, nullable), `metadata` (json, default `{}`), `occurred_at` (datetime), standard timestamps.
    - **Skipped from §8.10 (multi-tenant deferral)**: `team_id`, `project_id`, `actor_user_id`. Phase-1 ties everything to a single owner via `repository_id → project → owner`. Multi-team scoping ships when teams ship.
    - Index on `occurred_at desc` for the recent-activity query.

- **`App\Models\WebhookDelivery`** — Eloquent model.
    - Casts: `payload` (alias for `payload_json`) as `array`, timestamps as `datetime`, `status` as a string-backed `WebhookDeliveryStatus` enum.
    - `repository()` belongs-to (resolved by `repository_full_name` on demand inside handlers — no FK because deliveries can arrive before the repo is imported).

- **`App\Models\ActivityEvent`** — Eloquent model with casts and `repository()` relation.

- **`App\Enums\WebhookDeliveryStatus`** (`Received`, `Processed`, `Failed`, `Skipped`) and **`App\Enums\ActivitySeverity`** (`Info`, `Success`, `Warning`, `Danger`) with `badgeTone()` helpers consistent with existing enums.

- **`App\Domain\GitHub\Actions\VerifyGitHubWebhookSignatureAction`**:
    - Reads the configured webhook secret from `config('services.github.webhook_secret')`.
    - Computes `'sha256=' . hash_hmac('sha256', $rawBody, $secret)` and compares to the `X-Hub-Signature-256` header using `hash_equals` (timing-safe).
    - Returns `bool`. Caller (controller) uses this to gate everything: bad signature → 401, no DB write.

- **`App\Http\Controllers\Webhooks\GitHubWebhookController`** — single-action controller.
    - `POST /webhooks/github` (no auth middleware — GitHub doesn't carry session cookies — but CSRF must be excluded for this route).
    - Reads raw request body via `$request->getContent()` (NOT `$request->all()`, because Laravel's JSON parsing rejects malformed bodies before signature verification).
    - Calls `VerifyGitHubWebhookSignatureAction`. If it returns false → return 401 with no body. **No row inserted on bad signature** — that's the spec acceptance criterion.
    - Idempotency: look up `github_webhook_deliveries` by `github_delivery_id`. If a row already exists, return 200 with no further work (GitHub retries on timeout — we must not double-process).
    - Otherwise insert a fresh row with `status = received`, dispatch `ProcessGitHubWebhookJob::dispatch($delivery->id)`, return 200.
    - Doesn't process synchronously — the controller must always return quickly per §8.5.

- **CSRF exclusion.** Add `webhooks/github` to the `VerifyCsrfToken` middleware's `$except` array (or use Laravel 11's modern `bootstrap/app.php` config).

- **`App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob`** — `ShouldQueue` job.
    - Constructor: `int $deliveryId`.
    - `handle()`: load the delivery row; route by `event` to the matching handler (`IssuesWebhookHandler` for `issues`, `PullRequestWebhookHandler` for `pull_request`); on success flip `status = processed` + stamp `processed_at`; on caught exception flip to `failed` and store `error_message`; for unhandled events flip to `skipped` (per §8.5 "Log unknown events. Do not fail silently").

- **`App\Domain\GitHub\WebhookHandlers\IssuesWebhookHandler`**:
    - Reads the payload's `repository.full_name`. If we don't have a local `Repository` row for it, mark the delivery `skipped` with a message — the user hasn't imported this repo into Nexus yet, so we don't have a place to attach the issue.
    - Maps `action` → `event_type`: `opened → issue.created`, `closed → issue.closed`, `reopened → issue.reopened`, `edited → issue.updated`. Other actions skip.
    - Re-uses spec 015's `NormalizeGitHubIssueAction` to map `payload.issue` → upsert into `github_issues`. Drops PRs (the issues webhook still fires for PRs as side-effect).
    - Calls `CreateActivityEventAction` with the appropriate severity (`info` for opened/reopened/updated, `success` for closed).

- **`App\Domain\GitHub\WebhookHandlers\PullRequestWebhookHandler`**:
    - Reads `repository.full_name`; same skip logic if not imported.
    - Maps `action` → `event_type`: `opened → pull_request.opened`, `closed + merged === true → pull_request.merged`, `closed + merged === false → pull_request.closed`, `reopened → pull_request.reopened`, `review_requested → pull_request.review_requested`. Other actions skip.
    - Re-uses spec 016's `NormalizeGitHubPullRequestAction` to upsert into `github_pull_requests`.
    - Severity: `info` for opened/reopened/review_requested, `success` for merged, `muted` semantic for closed-without-merge → use `info`. (Phase 9 polish can add a `closed-not-merged` severity tone if it earns its keep.)

- **`App\Domain\Activity\Actions\CreateActivityEventAction`**:
    - Single `execute(array $attrs): ActivityEvent`. Centralized so spec 019 can hook broadcasting in one place + so non-webhook origins (manual sync? import? — phase 9) can reuse it.
    - Validates `event_type` is non-empty + `occurred_at` is a Carbon. Otherwise just persists.

- **Routes.** `routes/web.php` adds:
    ```php
    Route::post('/webhooks/github', GitHubWebhookController::class)
        ->name('webhooks.github');
    ```
    No middleware group — webhook bypasses auth + verified.

- **Config.** `config/services.php` gains a `github.webhook_secret` key reading `env('GITHUB_WEBHOOK_SECRET')`. `.env.example` adds the variable with documentation.

- **No GitHub-side webhook subscription registration in this spec.** That's a separate concern (the GitHub App settings already let you point a webhook URL at the server; for local development you point ngrok at your dev machine). Phase 9 might automate the per-repo subscription via the Apps API.

- **Tests** (PHP feature tests):
    - `VerifyGitHubWebhookSignatureActionTest` — valid signature returns true; tampered body returns false; missing secret returns false; constant-time comparison verified by happy-path equality.
    - `GitHubWebhookControllerTest` — bad signature 401 + zero rows inserted; valid signature 200 + delivery row stored + job dispatched (`Queue::fake()`); duplicate `X-GitHub-Delivery` returns 200 with no second insert + no second dispatch.
    - `ProcessGitHubWebhookJobTest` — `issues.opened` payload runs the issues handler, upserts the row, creates an `issue.created` activity event; `pull_request.opened` analogous; unknown event flips delivery to `skipped` with no activity events; handler exception flips delivery to `failed` with the error_message captured.
    - `IssuesWebhookHandlerTest` — opened/closed/reopened/edited mapping correct; PR-shaped issues payload (the `pull_request` key set on issue) skipped; unknown action skipped; no local Repository → skipped with message.
    - `PullRequestWebhookHandlerTest` — opened/closed-not-merged/closed-merged/reopened/review_requested mapping correct; unknown action skipped.
    - `CreateActivityEventActionTest` — happy-path persists with normalized fields; missing required fields throw.

- **Update phase trackers** in the same PR (post-merge bookkeeping flips 017 to 🟢, Phase 3 1/3 🟡).

**Out of scope:**

- Activity Feed UI. Spec 018 — components + AppLayout right-rail wiring + dedicated activity page (if it earns its keep).
- Real-time broadcasting via Reverb. Spec 019 — `ActivityEventCreated` event + Echo client + frontend subscription.
- Handlers for `workflow_run`, `push`, `release`, `check_run`, `check_suite`, `deployment`, `deployment_status`, `repository`, `pull_request_review`. Spec 019 extends the handler set once the broadcast layer is in place so the now-live feed has more event types flowing through.
- Per-repo webhook subscription registration via the GitHub Apps API. Manual webhook URL config on the GitHub App is sufficient for phase 1.
- Webhook delivery retry / replay UI. The `failed` state captures the audit trail; surfacing it visually is a phase 9 polish.
- `team_id` / `project_id` / `actor_user_id` columns on `activity_events`. Phase-1 ties activity to a single owner via `repository_id → project → owner`; multi-team scoping ships later.
- Webhook delivery rate-limiting / DDoS protection. Phase-1 single-user dev doesn't need it.

## Plan

1. **Migrations.** `create_github_webhook_deliveries_table` + `create_activity_events_table`. Both with the §8.5 / §8.10 fields plus the indexes called out above.
2. **Models + enums.** `WebhookDelivery`, `ActivityEvent`, `WebhookDeliveryStatus`, `ActivitySeverity`. Factories for both models.
3. **`VerifyGitHubWebhookSignatureAction`.** Pure HMAC-SHA-256 + `hash_equals`. No DI other than the secret string.
4. **Config** + `.env.example` entry.
5. **`CreateActivityEventAction`.** One method, validated insert.
6. **`GitHubWebhookController` + route + CSRF exclusion.** Raw-body read, signature gate, idempotency check, dispatch.
7. **`ProcessGitHubWebhookJob`.** Routing switch by `event`. `tries = 1` consistent with spec 014/015/016 jobs. Status flips at the end of each branch.
8. **`IssuesWebhookHandler` + `PullRequestWebhookHandler`.** Reuse the spec 015/016 normalizers — they're already pure mappers from a single GitHub payload to an upsert array.
9. **Tests.** Six test files. Use Laravel's `$this->withoutMiddleware(VerifyCsrfToken::class)` is NOT needed — the route is excluded; we can post directly. Build a small `signedPayload($body, $secret)` test helper that returns `['Content-Type' => 'application/json', 'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret), 'X-GitHub-Delivery' => Str::uuid(), 'X-GitHub-Event' => $event]`.
10. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
11. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] Migration creates `github_webhook_deliveries` (with `github_delivery_id` unique + `(event, action)` index) and `activity_events` (with `occurred_at` index, nullable `repository_id` FK).
- [ ] `VerifyGitHubWebhookSignatureAction` returns `false` for tampered bodies and `true` for legitimate ones; uses `hash_equals` (timing-safe).
- [ ] `POST /webhooks/github` returns 401 + does NOT insert a delivery row when the signature is invalid.
- [ ] Valid signature → delivery row inserted with `status = received`, `ProcessGitHubWebhookJob` dispatched (verified via `Queue::fake()`), 200 returned.
- [ ] Duplicate `X-GitHub-Delivery` header → second request returns 200 with no second insert and no second dispatch.
- [ ] `ProcessGitHubWebhookJob` flips status to `processed` on success, `failed` on caught exception (with `error_message`), `skipped` on unhandled event.
- [ ] `issues.opened` payload upserts the local `github_issues` row AND creates an `issue.created` activity event.
- [ ] `pull_request.opened` upserts `github_pull_requests` AND creates a `pull_request.opened` activity event.
- [ ] `pull_request.closed` with `merged=true` creates a `pull_request.merged` activity event (severity `success`); `merged=false` creates `pull_request.closed` (severity `info`).
- [ ] PR-shaped `issues` webhook payloads (with `pull_request` key) are dropped at the handler level, not double-processed.
- [ ] Webhook for a repo not yet imported into Nexus marks the delivery `skipped` with a clear `error_message` (NOT `failed`).
- [ ] Pint + vue-tsc + build clean. CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`.

## Files touched
- `database/migrations/<ts>_create_github_webhook_deliveries_table.php` — new.
- `database/migrations/<ts>_create_activity_events_table.php` — new.
- `app/Models/WebhookDelivery.php` — new.
- `app/Models/ActivityEvent.php` — new.
- `app/Enums/WebhookDeliveryStatus.php` — new.
- `app/Enums/ActivitySeverity.php` — new.
- `app/Domain/GitHub/Actions/VerifyGitHubWebhookSignatureAction.php` — new.
- `app/Domain/GitHub/Jobs/ProcessGitHubWebhookJob.php` — new.
- `app/Domain/GitHub/WebhookHandlers/IssuesWebhookHandler.php` — new.
- `app/Domain/GitHub/WebhookHandlers/PullRequestWebhookHandler.php` — new.
- `app/Domain/Activity/Actions/CreateActivityEventAction.php` — new.
- `app/Http/Controllers/Webhooks/GitHubWebhookController.php` — new.
- `bootstrap/app.php` — extend the CSRF `$except` config to skip `webhooks/github`.
- `config/services.php` — add `github.webhook_secret`.
- `.env.example` — `GITHUB_WEBHOOK_SECRET`.
- `routes/web.php` — `Route::post('/webhooks/github', …)`.
- `database/factories/WebhookDeliveryFactory.php` — new.
- `database/factories/ActivityEventFactory.php` — new.
- `tests/Feature/GitHub/Webhooks/VerifyGitHubWebhookSignatureActionTest.php` — new.
- `tests/Feature/GitHub/Webhooks/GitHubWebhookControllerTest.php` — new.
- `tests/Feature/GitHub/Webhooks/ProcessGitHubWebhookJobTest.php` — new.
- `tests/Feature/GitHub/Webhooks/IssuesWebhookHandlerTest.php` — new.
- `tests/Feature/GitHub/Webhooks/PullRequestWebhookHandlerTest.php` — new.
- `tests/Feature/Activity/CreateActivityEventActionTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-29
- Spec drafted; scope confirmed (decisions locked: phase-1 simplifications on `activity_events` (skip team_id/project_id/actor_user_id), initial handler set issues + pull_request only, idempotency via X-GitHub-Delivery unique, bad signature → 401 + zero side effects, unhandled events → `skipped` not `failed`, no GitHub Apps API automation for webhook subscription, `tries = 1` for the processing job).
- Opened issue [#45](https://github.com/Copxer/nexus/issues/45) and branch `spec/017-webhook-ingestion-and-activity-events` off `main`.
- Implementation complete: migrations + `WebhookDelivery` + `ActivityEvent` models + `WebhookDeliveryStatus` + `ActivitySeverity` enums + factories, `VerifyGitHubWebhookSignatureAction`, `CreateActivityEventAction`, `GitHubWebhookController` + route + `bootstrap/app.php` CSRF exclusion (using Laravel 13's `preventRequestForgery`), `ProcessGitHubWebhookJob` with handler routing, `IssuesWebhookHandler` + `PullRequestWebhookHandler`. 6 new test files: 204 tests / 847 assertions green. Pint/build clean.
- No manual UX walk — this spec is backend-only by design (spec 018 lights up the visible feed).

## Decisions (locked 2026-04-29)
- **Activity-events phase-1 simplification.** Skip `team_id` / `project_id` / `actor_user_id`; phase-1 derives ownership via `repository_id → project → owner`. Multi-tenant lands later.
- **Initial handler set: `issues` + `pull_request` only.** Enough to satisfy phase 3's acceptance criteria. Spec 019 extends to `workflow_run`, `push`, etc. once Reverb is wired.
- **Idempotency via `X-GitHub-Delivery` unique.** GitHub retries on timeout; the unique index + lookup-before-insert path handles it.
- **Bad signature: 401 + zero side effects.** No row inserted for unauthenticated requests so an attacker can't fill the deliveries table.
- **Unhandled events → `skipped`.** Per §8.5 "Log unknown events. Do not fail silently." `failed` is reserved for handler exceptions.
- **No webhook-subscription automation.** The GitHub App admin manually points a webhook URL at the dev/prod server. Per-repo subscription via Apps API is phase 9 polish if it earns its keep.
- **`tries = 1` for `ProcessGitHubWebhookJob`.** Mirror the existing sync jobs; if processing fails it lands in the `failed` state on the delivery row and stays there. Replay UI is phase 9.

## Open questions / blockers

- **CSRF exclusion in Laravel 11/13.** Modern Laravel uses `bootstrap/app.php` `withMiddleware()` config. Need to confirm the syntax for adding to `$except` for the webhooks route.
- **Raw-body access in tests.** `$this->postJson()` runs the body through Laravel's JSON parser. Use `$this->call('POST', $url, [], [], [], $headers, $rawBody)` or `$this->postJson()` with the full payload — verify the controller reads the right thing in tests.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue.** Pre-existing; webhook route is CSRF-excluded so this should not bite.
