---
spec: ai-daily-briefing
phase: 10
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-21
updated: 2026-07-21
---

# 044 — AI daily briefing: scheduled operator digest

## Goal

Phase 10 adds the first proactive AI surface to Nexus: a morning
briefing that tells an operator what changed yesterday, what needs
attention today, and what looks unusual across their projects.

The roadmap only names this as "AI daily briefing." The Phase 10 README
narrows the slice: "yesterday: X new issues, Y merged PRs, Z alerts, N
things that look off," delivered through spec 042, with per-user opt-in
and delivery time. This spec turns that into a shippable feature without
building a general AI assistant or report builder.

Roadmap refs: §Phase 10 Future Features ("AI daily briefing"), §4.4
Background Jobs (daily summaries), §3.2 Signal Over Noise, Phase 10
README LLM dependencies, spec 042 delivery layer.

## Scope

**In scope:**

- **Environment gate + LLM config.** The feature is disabled unless
  `AI_FEATURES_ENABLED=true`. LLM settings live under
  `config('services.llm.*')` with Anthropic as the reference provider,
  matching the Phase 10 README. The implementation should keep the
  provider swappable behind a small client contract.

- **Migration: `daily_briefing_preferences`.** One row per user.
  Columns:
  - `id`, `user_id` (FK -> `users`, cascade delete)
  - `enabled` (boolean, default false)
  - `delivery_time` (time, default `08:00:00`)
  - `timezone` (string, default from the user's profile/app default)
  - `channel_id` (nullable FK -> `alert_notification_channels`)
  - `include_projects` (JSON array of project IDs; null/empty = all
    user projects)
  - `last_sent_for_date` (nullable date, idempotency marker)
  - Timestamps.

- **Migration: `daily_briefings`.** Persist each generated briefing so
  operators can inspect what was sent and so retries do not regenerate
  different prose. Columns:
  - `id`, `user_id` (FK -> users, cascade delete)
  - `briefing_date` (date in the user's timezone; represents
    "yesterday")
  - `status` (`pending` | `generated` | `delivered` | `failed` |
    `skipped`)
  - `input_snapshot` (JSON, bounded summary counts + sampled entities)
  - `summary` (text, final operator-facing LLM output)
  - `highlights` (JSON array of short bullets)
  - `risks` (JSON array of "things that look off")
  - `prompt_version` (string, e.g. `daily-briefing-v1`)
  - `generated_at`, `delivered_at` (nullable timestamps)
  - `error_message` (nullable text)
  - Timestamps.
  - Unique index on `(user_id, briefing_date)`.

- **`DailyBriefingPreference` + `DailyBriefing` models.** Cast JSON
  columns to arrays, status to an enum, and date/timestamp columns to
  immutable date objects where the repo pattern supports it.

- **`GetDailyBriefingInputQuery`.** Builds a bounded, deterministic
  snapshot for one user + date window. The window is midnight-to-midnight
  in the user's configured timezone, converted to UTC for database
  queries. Include:
  - Issues opened/closed and PRs opened/merged/closed.
  - Failed workflows/deployments and successful deploy count.
  - Alerts triggered/resolved, grouped by severity/source/project.
  - Website/host/container health changes from yesterday.
  - Project health-score deltas and the worst-scoring projects.
  - Activity volume by project, with top changed entities.

- **Bounded input shaping.** The query returns counts plus capped sample
  lists, not raw event dumps. Default caps: top 5 projects, top 10 work
  items, top 10 alerts, top 10 activity events. This keeps cost and
  prompt size predictable.

- **`LlmClient` contract + Anthropic implementation.** A minimal
  interface for `complete(LlmPrompt $prompt): LlmResponse`. The client
  reads API key/model/timeout from `services.llm`; request timeout 20s;
  retry once on transient 429/5xx; fail closed with a stored error.

- **`GenerateDailyBriefingAction`.** Takes the input snapshot, builds a
  versioned prompt, calls the LLM client, validates/sanitizes the
  response, and persists a `daily_briefings` row. Output format:
  - `summary` — 2-4 concise paragraphs.
  - `highlights` — 3-6 bullets.
  - `risks` — 0-5 bullets, each tied to a concrete source entity.
  - `next_steps` — optional short list when the data supports it.

- **Fallback when AI is unavailable.** If `AI_FEATURES_ENABLED=false`,
  no jobs are dispatched. If the LLM call fails for an opted-in user,
  persist `status: failed` with the error and do not send a fabricated
  briefing. A later retry can use the same input window.

- **Scheduler + jobs.**
  - `DispatchDueDailyBriefingsJob` runs every 15 minutes.
  - It finds enabled preferences whose local `delivery_time` has passed
    and whose `(user_id, briefing_date)` has not been sent.
  - It dispatches `GenerateDailyBriefingJob` per due user.
  - `GenerateDailyBriefingJob` is unique by `(user_id, briefing_date)`
    and creates or reuses the `daily_briefings` row.
  - `SendDailyBriefingJob` sends the generated briefing via the selected
    spec 042 channel, then marks `daily_briefings.status=delivered` and
    updates `daily_briefing_preferences.last_sent_for_date`.

- **Delivery through spec 042.** Reuse the notification channel settings
  and drivers shipped in spec 042. The selected channel is the
  preference's `channel_id`; if null, use the user's first verified email
  channel. If the current spec 042 implementation is alert-payload-only,
  add a narrow `DailyBriefingPayload` adapter rather than refactoring the
  whole alert notification service.

- **Settings UI: `/settings/daily-briefing`.**
  - Enable/disable toggle.
  - Delivery time + timezone fields.
  - Channel selector showing verified spec 042 channels.
  - Project filter multi-select, defaulting to all projects.
  - "Send test briefing" button that generates from yesterday's current
    snapshot and delivers immediately to the selected channel.
  - Last generated / last delivered status with error text when failed.
  - Endpoint throttle: update 20/min, test send 5/min.

- **Operator-facing briefing page.** Add a simple authenticated history
  view at `/daily-briefings` listing prior generated briefings with
  status, date, channel, summary preview, and a detail drawer/page for
  the full content. This gives users a durable record even if email or
  Slack delivery fails.

- **Prompt safety + privacy.** Do not send secrets, webhook URLs, access
  tokens, raw logs, or full issue/PR bodies to the LLM. Entity titles,
  statuses, counts, timestamps, labels, and short sanitized snippets are
  allowed. Store `input_snapshot`, not the full prompt with secrets.

- **Palette command.** Add "Open daily briefings" and "Daily briefing
  settings" to the spec 043 command palette action/navigation groups.

- **Docs.** Update `docs/env.production.example` with commented
  `AI_FEATURES_ENABLED` and `LLM_*` values if those keys do not already
  exist. Update `docs/security/operator-checklist.md` §5 with the new
  settings/test-send endpoints and the scheduler behavior.

- **Tests.**
  - `DailyBriefingPreferenceControllerTest` — update settings, guest
    rejection, throttle, channel ownership/verification guard.
  - `DispatchDueDailyBriefingsJobTest` — respects user timezone,
    delivery time, opt-in, `last_sent_for_date`, and disabled AI gate.
  - `GenerateDailyBriefingActionTest` — builds bounded input, calls the
    LLM client, persists generated output, stores failed status on client
    error.
  - `GenerateDailyBriefingJobTest` — unique/idempotent by user + date.
  - `SendDailyBriefingJobTest` — sends through the selected spec 042
    channel, falls back to verified email, marks delivered, records
    failures.
  - `GetDailyBriefingInputQueryTest` — scopes data to the user's projects
    and date window, includes counts, caps sample lists, excludes other
    users' data.
  - `DailyBriefingHistoryControllerTest` — authenticated users only see
    their own generated briefings.

**Out of scope:**

- **Chat UI / AI assistant.** This is a scheduled digest, not an
  interactive assistant.
- **AI incident postmortems.** Roadmap lists AI incident summary as a
  separate item. Spec 044 may mention incidents in the daily digest, but
  it does not generate long-form incident reports.
- **AI PR risk scoring.** Spec 045 owns per-PR risk tags and health-score
  explanations. Spec 044 can count risky signals already present in data,
  but it does not score individual PRs.
- **Weekly/team reports.** Team reports are explicitly deferred in the
  Phase 10 README. This spec ships one daily per-user briefing.
- **Natural-language querying.** No "ask Nexus" search or Q&A over the
  database.
- **Digest batching for arbitrary alerts.** Spec 042 explicitly deferred
  general alert batching. This spec sends one scheduled daily briefing;
  it does not change alert notification cadence.
- **Per-team routing.** The current product shape is per-user/single-
  tenant. Preferences stay per-user.
- **Model fine-tuning or embeddings.** A deterministic summarized prompt
  is enough for v1.

## Plan

1. **Config + gate.** Add `AI_FEATURES_ENABLED` and `services.llm.*`
   config. Keep the feature disabled by default unless the operator opts
   in and config is present.

2. **Migrations + models.** Create `daily_briefing_preferences` and
   `daily_briefings`, enum status, model casts, factories.

3. **Input query.** Build `GetDailyBriefingInputQuery` with strict user
   scoping, timezone windowing, and sample caps.

4. **LLM client.** Add `LlmClient` contract, Anthropic implementation,
   prompt/response DTOs, and fake client for tests.

5. **Generation action.** Implement prompt v1, response validation,
   output sanitization, persistence, and failed-state handling.

6. **Scheduler jobs.** Add `DispatchDueDailyBriefingsJob`,
   `GenerateDailyBriefingJob`, and `SendDailyBriefingJob`; register the
   dispatcher on a 15-minute schedule.

7. **Delivery adapter.** Reuse spec 042 channels/drivers. If needed,
   add a `DailyBriefingPayload` DTO that existing channel drivers can
   render to email/Slack/webhook without pretending the briefing is an
   alert.

8. **Settings UI.** Ship `/settings/daily-briefing` with opt-in,
   schedule, timezone, channel, project filter, status, and test-send.

9. **History UI.** Ship `/daily-briefings` index + detail so generated
   briefings are visible inside Nexus.

10. **Palette + docs.** Add command palette entries and update env / operator
    docs for AI config, endpoint throttles, and scheduler expectations.

11. **Tests + verification.** Cover the tests listed above, run Pint,
    `php artisan test`, `npm run build`, self-review, PR.

## Acceptance criteria

- [ ] Feature stays inert unless `AI_FEATURES_ENABLED=true`, LLM config is
      present, and the user has enabled daily briefings.
- [ ] Users can configure daily briefing opt-in, delivery time, timezone,
      channel, and included projects at `/settings/daily-briefing`.
- [ ] Scheduler dispatches at most one briefing per user per local
      briefing date, respecting timezone and `last_sent_for_date`.
- [ ] Generated input snapshot is scoped to the user's projects and
      contains bounded counts/samples for issues, PRs, deployments,
      alerts, monitoring/host health, health-score deltas, and activity.
- [ ] LLM output persists in `daily_briefings` with summary, highlights,
      risks, prompt version, status, and timestamps.
- [ ] Failed LLM generation records `status: failed` and does not send a
      fabricated briefing.
- [ ] Delivery uses a verified spec 042 channel; selected channel wins,
      otherwise first verified email channel is used when available.
- [ ] Test-send endpoint generates and delivers a briefing immediately,
      throttled at 5/min.
- [ ] `/daily-briefings` shows only the authenticated user's generated
      briefings and their delivery status.
- [ ] No secrets, tokens, webhook URLs, raw logs, or full bodies are sent
      to the LLM provider.
- [ ] Palette includes "Open daily briefings" and "Daily briefing
      settings" commands.
- [ ] Every test in the §Tests block is green.
- [ ] Pint clean, `php artisan test` green, `npm run build` clean.

## Files touched

Track actual files as implementation progresses. Expected touchpoints:

- `config/services.php` — added disabled-by-default `services.llm.*` config.
- `database/migrations/2026_07_21_140000_create_daily_briefing_preferences_table.php`
- `database/migrations/2026_07_21_140001_create_daily_briefings_table.php`
- `app/Enums/DailyBriefingStatus.php`
- `app/Models/DailyBriefingPreference.php`
- `app/Models/DailyBriefing.php`
- `app/Models/User.php` — added daily briefing relationships.
- `app/Models/AlertNotificationChannel.php` — added daily briefing preference relationship.
- `database/factories/DailyBriefingPreferenceFactory.php`
- `database/factories/DailyBriefingFactory.php`
- `tests/Feature/DailyBriefings/DailyBriefingPersistenceTest.php`
- `app/Domain/DailyBriefings/Queries/GetDailyBriefingInputQuery.php` — added scoped, timezone-aware bounded input snapshot query.
- `tests/Feature/DailyBriefings/GetDailyBriefingInputQueryTest.php` — covered user/project scoping, timezone conversion, counts, and sample caps.
- `app/Domain/AI/Contracts/LlmClient.php` — added the provider-swappable LLM completion contract.
- `app/Domain/AI/DataTransferObjects/LlmPrompt.php` — added the versioned prompt DTO.
- `app/Domain/AI/DataTransferObjects/LlmResponse.php` — added the structured client response DTO.
- `app/Domain/AI/Services/AnthropicLlmClient.php` — added the Anthropic Messages API implementation with configured model, timeout, API key, and one transient retry.
- `app/Domain/DailyBriefings/Actions/GenerateDailyBriefingAction.php` — added prompt v1 construction, LLM invocation, structured output validation/sanitization, generated persistence, and failed-state persistence.
- `app/Providers/AppServiceProvider.php` — bound `LlmClient` to the configured Anthropic implementation.
- `tests/Feature/DailyBriefings/AnthropicLlmClientTest.php` — covered the LLM binding and Anthropic HTTP request/response mapping with `Http::fake`.
- `tests/Feature/DailyBriefings/GenerateDailyBriefingActionTest.php` — covered prompt construction, fake-client generation, output sanitization, failed LLM calls, disabled AI fail-closed behavior, invalid output, and same-day row reuse.

Expected future touchpoints:

- `app/Domain/DailyBriefings/DataTransferObjects/DailyBriefingPayload.php`
- `app/Domain/DailyBriefings/Jobs/DispatchDueDailyBriefingsJob.php`
- `app/Domain/DailyBriefings/Jobs/GenerateDailyBriefingJob.php`
- `app/Domain/DailyBriefings/Jobs/SendDailyBriefingJob.php`
- `app/Http/Controllers/Settings/DailyBriefingPreferenceController.php`
- `app/Http/Controllers/DailyBriefingController.php`
- `resources/js/Pages/Settings/DailyBriefing.vue`
- `resources/js/Pages/DailyBriefings/Index.vue`
- `resources/js/Pages/DailyBriefings/Show.vue`
- `resources/js/lib/commands.ts`
- `routes/web.php`
- `routes/console.php`
- `docs/env.production.example`
- `docs/security/operator-checklist.md`
- `tests/Feature/DailyBriefings/DailyBriefingPreferenceControllerTest.php`
- `tests/Feature/DailyBriefings/DispatchDueDailyBriefingsJobTest.php`
- `tests/Feature/DailyBriefings/GenerateDailyBriefingActionTest.php`
- `tests/Feature/DailyBriefings/GenerateDailyBriefingJobTest.php`
- `tests/Feature/DailyBriefings/SendDailyBriefingJobTest.php`
- `tests/Feature/DailyBriefings/GetDailyBriefingInputQueryTest.php`
- `tests/Feature/DailyBriefings/DailyBriefingHistoryControllerTest.php`

## Work log

Dated notes as work progresses.

### 2026-07-21

- Opened GitHub issue #131 and started branch
  `spec/044-ai-daily-briefing` after user approval of the draft scope.
- Drafted from `_template.md`; scope was approved, so the spec moved to
  `in-progress` with issue and branch tracking in place.
- Roadmap source is intentionally thin (one bullet). Scope is based on
  the Phase 10 README's narrower acceptance note: morning digest,
  yesterday's activity, delivery through spec 042, per-user opt-in,
  configurable delivery time + timezone.
- Assumption: spec 042's channel configuration and drivers can be reused
  for non-alert payload delivery. If the implementation is tightly bound
  to alert payloads, add a narrow `DailyBriefingPayload` adapter instead
  of broadening the alert lifecycle.
- Assumption: Anthropic is the reference provider because the Phase 10
  README says the surrounding codebase's AI usage points there; the
  implementation still hides this behind `LlmClient` so the provider can
  be swapped.
- Kept AI incident summaries and PR risk scoring out of scope because
  the roadmap lists them separately and spec 045 owns PR risk + health
  explanations.
- Implemented the first backend-foundation work unit: disabled-by-default
  AI/LLM config, daily briefing persistence migrations, enum, models,
  factories, relationships, and persistence tests. Deliberately deferred
  input query, LLM client, jobs, delivery, UI, palette, and docs to keep
  the commit independently reviewable.
- Implemented the input-query work unit: `GetDailyBriefingInputQuery`
  builds a deterministic, bounded snapshot for one user and local briefing
  date, converts the local midnight-to-midnight window to UTC for database
  reads, scopes all data through the user's owned projects plus any
  included-project filter, and caps project/work-item/alert/activity
  samples. Added focused tests for scoping, timezone conversion, counts,
  and caps. Deliberately deferred LLM client/action, scheduler jobs,
  delivery, UI/history, palette, and docs.
- Discovery: current health-score storage only keeps `projects.health_score`
  as the latest value; there is no historical score table to compute true
  score deltas. The input snapshot therefore returns current
  `worst_projects` and an empty `health.deltas` array until a future slice
  adds score history or defines a persisted delta source.
- Implemented the generation/client work unit: `LlmClient`, prompt/response
  DTOs, Anthropic client binding, and `GenerateDailyBriefingAction`. The
  action receives the already-built input snapshot, builds prompt version
  `daily-briefing-v1`, calls the LLM client only when AI features are
  enabled, validates JSON output, strips tags/control characters from
  summary/highlights/risks, persists generated rows, and persists failed
  rows for disabled AI, provider exceptions, or invalid output. Added
  `Http::fake` coverage for the Anthropic request/response shape.
  Deliberately deferred scheduler jobs, delivery, UI/history, palette, and
  docs.
- Discovery: `daily_briefings.briefing_date` is cast as an immutable date;
  when reusing a row for the unique `(user_id, briefing_date)` key, an
  explicit `whereDate` lookup avoids date-cast mismatches before save.

## Open questions / blockers

- **Delivery adapter shape.** Confirm during implementation whether spec
  042 exposes reusable notification drivers for non-alert payloads. If
  not, ship a small briefing-specific payload adapter without changing
  alert semantics.
- **Default channel behavior.** Draft assumes "selected verified channel,
  else first verified email channel." If operators should receive Slack
  by default when available, adjust before implementation.
- **Briefing history retention.** Draft persists every generated briefing
  indefinitely. If storage becomes a concern, add a retention policy in a
  follow-up rather than deleting operator-visible history in v1.
