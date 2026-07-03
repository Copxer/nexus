---
spec: alert-notification-service
phase: 10
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-02
updated: 2026-07-02
---

# 042 — `AlertNotificationService`: email + Slack + generic webhook delivery

## Goal

Phase 7 shipped the alerts engine: triggers, storage, lifecycle
(open → ack → resolved), UI, activity events. What it did NOT ship
is delivery to anywhere outside the app. Every deferred-Phase-7
note across the codebase points at the same missing piece — an
`AlertNotificationService` that fans a triggered alert out to the
operator's preferred channels.

Spec 042 ships that service. Three channels: email, Slack (incoming
webhook), generic HTTP webhook. Per-user routing preferences decide
which severities + sources fire which channel, and Nexus's own
observability + rate limits + dedupe surround delivery so a runaway
alert storm can't nuke your Slack channel or blow your SMTP quota.

This is the phase-10 keystone. Specs 044 (AI daily briefing), 045
(AI PR risk score), and 047 (status page subscribe) all lean on
its delivery layer — ship this first.

Roadmap refs: §Phase 7 acceptance criteria (`AlertNotification-
Service` deferred to Phase 10), §Phase 10 "Slack/Discord
notifications", §6.5 Strategy Pattern (per-channel delivery), §16
Security Requirements (webhook signatures for outbound), §18 Error
Handling (retry / backoff / dead-letter for delivery failures).

## Scope

**In scope:**

- **Migration: `alert_notification_channels`.** Per-user channel
  configuration. Columns:
  - `id`, `user_id` (FK → `users`, cascade delete)
  - `kind` (enum: `email` | `slack` | `webhook`)
  - `name` (user-facing label — "My Slack", "Ops on-call email")
  - `config` (JSON — per-kind: `email` → `to`; `slack` → `webhook_url`;
    `webhook` → `url` + optional `signing_secret` for HMAC)
  - `enabled` (boolean, default true — soft-disable without
    deleting)
  - `verified_at` (nullable timestamp — set after the "send test"
    round-trip succeeds. Un-verified channels are skipped by
    delivery)
  - Timestamps.

- **Migration: `alert_notification_preferences`.** Per-user routing
  rules. Columns:
  - `id`, `user_id` (FK, cascade delete)
  - `channel_id` (FK → `alert_notification_channels`, cascade
    delete)
  - `min_severity` (enum: `info` | `warning` | `critical` — send
    when alert.severity >= this)
  - `sources` (JSON array of `AlertSource` values; null / empty =
    all sources)
  - `enabled` (boolean, default true)
  - Timestamps.

- **Migration: `alert_deliveries`.** Delivery log per alert per
  channel. Columns:
  - `id`, `alert_id` (FK → `alerts`, cascade delete)
  - `channel_id` (FK → `alert_notification_channels`, cascade
    delete)
  - `status` (enum: `pending` | `sent` | `failed` | `skipped`)
  - `attempts` (integer, default 0)
  - `last_attempt_at`, `sent_at` (nullable)
  - `error_message` (nullable text — last failure reason)
  - `payload` (JSON — the outbound payload for forensic replay)
  - Timestamps.
  - Composite index on `(alert_id, channel_id)` to make retry
    lookups cheap.

- **`AlertNotificationService`.** Coordinator that answers "an
  alert just triggered — who gets notified?" Reads
  `alert_notification_preferences`, filters by severity + source,
  dispatches a `DispatchAlertNotificationJob` per matching
  channel. Idempotent: re-firing for the same
  `(alert_id, channel_id)` in the same lifecycle skips already-sent
  rows.

- **`DispatchAlertNotificationJob`** (implements `ShouldQueue`,
  `ShouldBeUnique` on `(alert_id, channel_id)`). Resolves the
  channel, delegates to the matching `NotificationChannelDriver`,
  writes the `alert_deliveries` row. Retries via §18 backoff on
  transient failures (429, 5xx, timeout). Dead-letters to
  `status: failed` after `tries = 3`.

- **`NotificationChannelDriver` contract + three implementations.**
  §6.5 Strategy pattern.
  - `EmailChannelDriver` — Laravel `Mail::to(...)->send(new
    AlertNotificationMail($alert))`. HTML + text templates. Uses
    the `MAIL_*` env vars documented in
    `docs/env.production.example`.
  - `SlackChannelDriver` — HTTP POST to the incoming-webhook URL
    with a Slack-message Block Kit payload. Timeouts at 5s.
    429-aware (respects `Retry-After`).
  - `GenericWebhookChannelDriver` — HTTP POST of the
    `AlertNotificationPayload` DTO as JSON. Optional
    `X-Nexus-Signature` HMAC-SHA-256 header when
    `config.signing_secret` is set (mirrors the inbound GitHub
    webhook verification shape).

- **`AlertNotificationPayload` DTO.** Immutable value object
  carrying the outbound shape: `alert_id`, `type`, `severity`,
  `source`, `title`, `message`, `link` (deep link back to
  `/alerts/{id}`), `triggered_at`, `metadata` (subset of the
  alert's metadata JSON, sanitized). Constructor accepts an
  `Alert` model; drivers receive the DTO, never the model.

- **Hook `AlertNotificationService::dispatchFor($alert)` into
  `TriggerAlertAction`.** Fire-and-forget: the notification
  dispatch is a queued side-effect, never blocking the trigger
  path. Wraps in try / catch + logs to Sentry / logs — trigger
  path succeeds even if notification dispatch throws.

- **Hook `dispatchResolutionFor($alert)` into
  `ResolveAlertAction`.** Optional per-preference: some operators
  want the "resolved" ping, some don't. Preference row gets a
  `notify_on_resolve` boolean (default false — critical alert
  resolutions ping, everything else stays quiet).

- **Rate limit + dedupe.**
  - Per-channel budget: 30 notifications / user / hour default,
    overridable per preference row via `rate_limit_per_hour`.
    Enforced via Laravel's `RateLimiter::attempt(...)` keyed on
    `(user_id, channel_id)`. Rejected sends land in
    `alert_deliveries` as `status: skipped` with a
    `error_message: rate_limited` marker so operators see the
    ceiling in the UI.
  - Dedupe: if an alert for the same `(source, source_id, type)`
    already fired within a 5-minute window, skip. Prevents flap
    storms from a website going up/down/up/down.

- **Settings UI: `/settings/notifications`.**
  - **Channels tab.** Add / edit / delete channels. Each row shows
    kind, name, verification status, enable toggle. "Send test"
    button dispatches a test notification (uses a stub Alert
    payload) and flips `verified_at` on success. Test failures
    render the driver's error message inline.
  - **Rules tab.** Per-channel routing preferences. Toggle
    `enabled`, pick `min_severity`, multi-select `sources`, set
    per-hour rate limit override, toggle `notify_on_resolve`.
  - **Deliveries tab.** Paginated log of recent deliveries —
    channel, alert, status, error, retry button on failed rows.
    Sits alongside spec 037's webhook-deliveries tab under
    Settings.

- **Tests.**
  - `TriggerAlertActionNotificationDispatchTest` — triggering an
    alert enqueues one `DispatchAlertNotificationJob` per matching
    preference; skipped severities / sources don't enqueue.
  - `DispatchAlertNotificationJobTest` — happy path per driver
    (email lands in `Mail::fake()`, Slack + webhook via
    `Http::fake()` assertions). Failure path — 5xx returns a job
    exception + row lands `status: failed` after retries.
  - `AlertNotificationRateLimitTest` — the 31st notification in
    an hour lands `status: skipped` with `rate_limited` marker.
  - `AlertNotificationDedupeTest` — same-fingerprint alert fired
    within 5 minutes → the second delivery is skipped
    (`error_message: deduped`).
  - `SendChannelTestNotificationTest` — the "send test" button
    flips `verified_at` on 2xx response.
  - `WebhookSignatureOutboundTest` — when `config.signing_secret`
    is set, outbound webhook carries a valid HMAC-SHA-256
    `X-Nexus-Signature` header.

**Out of scope:**

- **Discord / Microsoft Teams / PagerDuty native.** The
  `GenericWebhookChannelDriver` already handles Discord (Discord
  accepts inbound webhooks in a Slack-compatible-ish shape but
  needs its own driver for full parity). Teams / PagerDuty are
  their own drivers; add later on demand.
- **On-call rotations / escalation policies.** That's the
  deferred "incident management" phase-11 spec.
- **Digest / batching.** "Send at most one email per hour
  summarizing all alerts" — nice, but adds a batching queue on
  top of dispatch. Defer.
- **Per-project notification routing.** Preferences today are
  per-user; per-user × per-project is a Cartesian-product UI
  problem. If operators ask for it, a `project_id` nullable
  filter on the preference row + a UI select is a follow-up.
- **In-app notifications badge.** The alerts count in the sidebar
  already surfaces this. A separate bell-icon queue is a UX
  redesign, not this spec.
- **Two-factor delivery** (SMS, phone call). Different vendor
  surface; defer.
- **Retry policy tuning per channel.** All three drivers use the
  §18 default (3 retries, exponential backoff). Per-channel
  override lives in the preference row `rate_limit_per_hour`; a
  separate `max_attempts` knob is deferred until an operator
  actually asks.

## Plan

1. **Migrations.** Three tables, one migration file per table with
   `nexus_042_` prefix. Foreign keys with cascade delete. Add
   composite indexes for the retry / dedupe query paths.

2. **Enums.** `NotificationChannelKind` (`email` | `slack` |
   `webhook`), `AlertDeliveryStatus` (`pending` | `sent` |
   `failed` | `skipped`). Match the string-backed enum pattern
   from spec 030's `AlertStatus`.

3. **DTO + driver contract.** `AlertNotificationPayload` value
   object + `NotificationChannelDriver` interface with
   `send(AlertNotificationChannel $channel, AlertNotificationPayload
   $payload): void`. Drivers throw on failure so the caller
   ({Dispatch}Job) can wrap in the retry / dead-letter loop.

4. **Three driver implementations.** Email via Laravel Mail, Slack
   via `Http::post()` with 5s timeout, webhook via same +
   optional HMAC signature. Each driver is a thin adapter with a
   feature test.

5. **`AlertNotificationService`.** Coordinator. Query matching
   preferences → dispatch one job per channel → return silently.
   Dedupe check runs at the service level (fingerprint from
   `alert.{source, source_id, type}`); rate limit runs at the job
   level (per user × channel).

6. **`DispatchAlertNotificationJob`.** `ShouldBeUnique` keyed on
   `(alert_id, channel_id)`. Resolves the driver, catches driver
   exceptions, writes the `alert_deliveries` row. Failed jobs
   land `status: failed` after `tries = 3` (spec 037 pattern).

7. **Hook into `TriggerAlertAction` + `ResolveAlertAction`.**
   Fire-and-forget wrapped in try / catch. Trigger path never
   fails because notification dispatch failed — the alert is more
   important than the notification of it.

8. **Settings UI — three tabs.** Channels / Rules / Deliveries.
   Inertia pages + controllers under
   `app/Http/Controllers/Settings/Notifications/`. Follow the
   spec-037 webhook-deliveries page shape.

9. **`docs/env.production.example` update.** Add commented
   defaults for the new rate-limit / dedupe-window knobs so
   operators know they exist.

10. **Update spec 041's operator checklist §5.** Add the outbound
    Slack + webhook endpoints to the per-user throttle table.

11. **Pint clean, suite green, build clean. Self-review with
    `superpowers:code-reviewer`. PR. Watch CI. Pause for merge.**

## Acceptance criteria

- [ ] Three migrations shipped: `alert_notification_channels`,
      `alert_notification_preferences`, `alert_deliveries`.
- [ ] `NotificationChannelDriver` contract + three
      implementations (email, Slack, webhook).
- [ ] `AlertNotificationService::dispatchFor($alert)` wired into
      `TriggerAlertAction`; `dispatchResolutionFor($alert)` wired
      into `ResolveAlertAction`.
- [ ] `/settings/notifications` renders Channels + Rules +
      Deliveries tabs. "Send test" flips `verified_at`; failed
      test surfaces driver error.
- [ ] Rate limit enforced (30 / user / hour default, override per
      preference row).
- [ ] Dedupe suppresses same-fingerprint alerts within a 5-minute
      window.
- [ ] Failed deliveries surface in the Deliveries tab with a
      retry button (30 / min rate-limited, following spec 039's
      per-endpoint throttle table).
- [ ] Generic webhook carries `X-Nexus-Signature` when the
      channel row has a `signing_secret`.
- [ ] Every test from the §Tests block lands green.
- [ ] Pint clean, `php artisan test` green, `npm run build`
      clean.

## Files touched

- `database/migrations/2026_07_02_*_create_alert_notification_channels_table.php` — created
- `database/migrations/2026_07_02_*_create_alert_notification_preferences_table.php` — created
- `database/migrations/2026_07_02_*_create_alert_deliveries_table.php` — created
- `app/Enums/NotificationChannelKind.php` — created
- `app/Enums/AlertDeliveryStatus.php` — created
- `app/Models/AlertNotificationChannel.php` — created
- `app/Models/AlertNotificationPreference.php` — created
- `app/Models/AlertDelivery.php` — created
- `app/Domain/Notifications/Actions/DispatchAlertNotificationAction.php` — created (coordinator)
- `app/Domain/Notifications/DataTransferObjects/AlertNotificationPayload.php` — created
- `app/Domain/Notifications/Contracts/NotificationChannelDriver.php` — created
- `app/Domain/Notifications/Drivers/EmailChannelDriver.php` — created
- `app/Domain/Notifications/Drivers/SlackChannelDriver.php` — created
- `app/Domain/Notifications/Drivers/GenericWebhookChannelDriver.php` — created
- `app/Domain/Notifications/Jobs/DispatchAlertNotificationJob.php` — created
- `app/Domain/Notifications/Services/AlertNotificationService.php` — created
- `app/Mail/AlertNotificationMail.php` — created
- `resources/views/emails/alert-notification.blade.php` — created
- `app/Domain/Alerts/Actions/TriggerAlertAction.php` — hook into notification dispatch
- `app/Domain/Alerts/Actions/ResolveAlertAction.php` — hook into resolution dispatch
- `app/Http/Controllers/Settings/Notifications/ChannelsController.php` — created
- `app/Http/Controllers/Settings/Notifications/PreferencesController.php` — created
- `app/Http/Controllers/Settings/Notifications/DeliveriesController.php` — created
- `resources/js/Pages/Settings/Notifications/{Channels,Rules,Deliveries}.vue` — created
- `routes/web.php` — new notification settings routes (throttled)
- `tests/Feature/Notifications/*.php` — 6 feature tests per §Tests block
- `docs/security/operator-checklist.md` — extend §5 with outbound throttle rows
- `docs/env.production.example` — commented defaults for rate-limit / dedupe

## Work log

Dated notes as work progresses.

### 2026-07-02
- Drafted from `_template.md`. Keystone spec for Phase 10 —
  specs 044/045/047 rely on the delivery layer this ships.
- Three drivers via §6.5 Strategy pattern; queue-side dispatch
  keeps the alert trigger path never blocked on external calls.
- Rate limit + dedupe are in scope from the start (not a
  follow-up) because a runaway alert storm without them is
  worse than no notifications at all.
- Branch `spec/042-alert-notification-service` cut off main.
- Tracking issue #123.
- Phase 10 folder + README created in the same commit.
- Self-review caught two real bugs pre-push:
  - **Cross-tenant leak** — `matchingPreferences()` didn't scope
    by user. Fixed: project-scoped alerts now filter preferences
    by `project.owner_user_id`; system alerts (no project) still
    fan out to every configured preference. Added two regression
    tests (`project_scoped_alert_never_fires_a_stranger_users_preference`,
    `system_alert_with_no_project_fans_out_to_every_configured_preference`).
  - **Dead clause in `isDeduped`** — `->where('id', '<',
    PHP_INT_MAX)` was a no-op that looked load-bearing; the
    intent (exclude the current alert's own row) required
    `alert_id != $alert->id`. Fixed.
- Also fixed pre-push: atomic `attempts` increment via
  `DB::raw('attempts + 1')` to prevent concurrent retry drift,
  unique constraint on `(alert_id, channel_id)` to make the
  `firstOrCreate` invariant enforceable, and added the missing
  `docs/env.production.example` + `docs/security/operator-checklist.md`
  updates the spec plan called for.
- Added `ResolveAlertActionNotificationDispatchTest` (2 cases)
  pinning the `notify_on_resolve` gating that had no test.

## Open questions / blockers

- **Slack Block Kit vs. plain text.** Block Kit gives a richer
  card with severity color + button links; plain text is simpler
  and Discord-compatible. Ship Block Kit for the Slack driver
  (severity color + "View in Nexus" button); plain text fallback
  lives inside `text:` for the notification preview.
- **Email HTML template scope.** Ship a minimal
  branded HTML template (product name + severity badge + alert
  title + message + view button). Real email design polish is a
  separate spec if operators ask.
- **Test notification payload shape.** The "send test" button
  synthesizes an `Alert` with `type: 'test.notification'`,
  `severity: info`, and a fixed message. Doesn't hit the alerts
  table — pure in-memory model → payload → driver.
- **`notify_on_resolve` default.** False for `info` +
  `warning`, true for `critical` — operators generally want to
  know when a page came back up but don't need a
  Slack ping every time a webhook backlog cleared. Encoded as a
  per-severity default at preference creation; user can flip.
- **`payload` JSON size on `alert_deliveries`.** Keep it bounded
  (≤ 4 KB per row). Large `alert.metadata` blobs get truncated
  with a `[truncated]` marker so the deliveries table doesn't
  bloat.
