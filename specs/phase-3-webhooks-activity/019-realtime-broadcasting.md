---
spec: realtime-broadcasting
phase: 3-webhooks-activity
status: done
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/55
branch: spec/019-realtime-broadcasting
---

# 019 — Real-time broadcasting via Reverb

## Goal
Make the activity feed update **without page refresh** when a webhook lands. The page-load path from spec 018 stays as the cold start; new events stream in via a Laravel Reverb broadcast → Echo subscription → the user's right rail (and the `/activity` page) prepend the new row in place.

This is the last spec in Phase 3 — closing the loop between spec 017 (webhooks land in the DB) and spec 018 (UI reads them).

Roadmap reference: §4.3 Realtime, §8.5 GitHub Webhooks (full event matrix), §8.10 Activity Feed (`activity_events`), §13.2 WebSockets.

## Scope
**In scope — backend:**
- New broadcast event class `App\Events\ActivityEventCreated` (`ShouldBroadcastNow` so the Echo client sees it without queue-worker latency, with `broadcastOn()` returning a private per-user channel).
- `CreateActivityEventAction` dispatches `ActivityEventCreated` after the row insert. Single source of truth — every webhook handler funnels through it, so we hook broadcasting once and cover everything.
- Channel authorization in `routes/channels.php`: `users.{userId}.activity` returns true when `(int) $user->id === (int) $userId`. Mirrors the existing `App.Models.User.{id}` shape.
- Three new webhook handlers under `app/Domain/GitHub/WebhookHandlers/`:
   - `WorkflowRunWebhookHandler` — `workflow_run` events → `workflow.succeeded` / `workflow.failed` activity events. Severity from conclusion (`success` | `danger`).
   - `PushWebhookHandler` — `push` events update `repositories.last_pushed_at` (no activity event — pushes are too noisy and would drown the feed).
   - `ReleaseWebhookHandler` — `release.published` events → new `release.published` activity type (severity `info`).
- Add `release.published` to `ActivitySeverity` / event-type vocab where needed (PHP enum + TS type).
- Register the new handlers in `ProcessGitHubWebhookJob`'s router so deliveries get routed correctly.
- Tests: signed-fixture coverage for each new handler (idempotent + creates expected activity event) + a broadcast assertion using `Event::fake()` on `ActivityEventCreated`.

**In scope — frontend:**
- Install `laravel-echo` + `pusher-js` and wire an `Echo` singleton in `resources/js/bootstrap.ts` configured against `VITE_REVERB_*` env vars.
- New composable `resources/js/lib/useActivityFeed.ts`: returns a reactive `events: Ref<ActivityEvent[]>` that initializes from `usePage<PageProps>().props.activity?.recent`, subscribes the authenticated user to `users.{id}.activity`, and **prepends** broadcast events (capped at the existing 20-row rail / 100-row page rolling window). Re-syncs on Inertia `navigate` so a fresh page is the source of truth.
- `AppLayout.vue` swaps `resolvedActivityEvents` (computed) for the composable, preserving the explicit-prop-wins-when-undefined override path.
- `Pages/Activity/Index.vue` does the same on its 100-row list.
- Connection status indicator (small `wifi-off` Lucide pill) in the rail header when Reverb is not connected — optional but useful since cloudflared tunnels make websockets flaky.

**In scope — config / docs:**
- Flip `BROADCAST_CONNECTION` default to `reverb` in `.env.example` (still falls back to `null` for environments that haven't started the Reverb daemon).
- Update README's tunnel section with the **third tunnel** (`cloudflared tunnel --url http://localhost:8080` for Reverb) and the matching `VITE_REVERB_*` env updates. Include the named-tunnel recommendation since URL drift across three tunnels gets painful fast.
- Update phase-3 README to flip 019 to 🟢 + mark Phase 3 complete.

**Out of scope:**
- **Retiring the sync-status polling** on `/repositories/{repo}` (PR #51). That polling watches `repository.sync_status`, not activity events; it's a different broadcast. Worth a follow-up `feat/sync-status-broadcast` spec but not part of this one.
- **Presence channels / "X is viewing" indicators.** Single-user dev today; meaningful when multi-tenant lands.
- **Filtering / unsubscribe per repo.** Phase 7 (alerts) or beyond.
- **Push event activity** — pushes are noisy. Update `last_pushed_at` only.
- **Heatmap real data.** Overview's `MOCK_HEATMAP` stays mock; spec 018's same-spec note still applies.
- **Reconnect / offline queue replay.** Echo's default reconnection is enough for local dev; production-grade resilience can come later.

## Plan
1. Backend skeleton:
   1.1. Add `ActivityEventCreated` event class.
   1.2. Wire it into `CreateActivityEventAction` (dispatch after insert).
   1.3. Add the channel authorization in `routes/channels.php`.
2. New webhook handlers:
   2.1. `WorkflowRunWebhookHandler` (mirror `IssuesWebhookHandler` shape — fail-loud on signature/payload errors, idempotent on retries).
   2.2. `PushWebhookHandler` (touches `last_pushed_at`; no activity).
   2.3. `ReleaseWebhookHandler`.
   2.4. Extend `release.published` to the `ActivitySeverity` enum + TS event-type vocab + the relevant tests.
   2.5. Register all three in `ProcessGitHubWebhookJob`.
3. Frontend:
   3.1. `npm install laravel-echo pusher-js`.
   3.2. Wire Echo singleton in `bootstrap.ts`. Read `VITE_REVERB_*`.
   3.3. Compose `useActivityFeed.ts`. Subscribe / unsubscribe on mount/unmount + page navigation.
   3.4. Swap `AppLayout.vue` and `Activity/Index.vue` to the composable.
   3.5. Optional: connection-status pill in the rail header.
4. Config:
   4.1. `.env.example`: `BROADCAST_CONNECTION=reverb`.
   4.2. README tunnel section: add third tunnel + Reverb env updates + named-tunnel callout.
5. Tests:
   5.1. `WorkflowRunWebhookHandlerTest`, `PushWebhookHandlerTest`, `ReleaseWebhookHandlerTest`.
   5.2. `ActivityEventCreatedBroadcastTest` (uses `Event::fake([ActivityEventCreated::class])`, calls `CreateActivityEventAction`, asserts dispatched on the correct channel).
   5.3. `ActivityChannelTest` (auth allowed for own user, denied for someone else).
6. Pipeline + self-review + open PR.

## Acceptance criteria
- [x] `ActivityEventCreated` exists, implements `ShouldBroadcastNow`, broadcasts on `users.{userId}.activity` for the project owner of the event's repository (no-op when there's no repo).
- [x] Every `CreateActivityEventAction::execute()` dispatches the broadcast.
- [x] `routes/channels.php` authorizes `users.{userId}.activity` only for the matching user (`(int) $user->id === (int) $userId`).
- [x] Three new webhook handlers ship with feature tests; each is idempotent (no double-write when replayed).
- [x] `release.published` exists in the TS event-type union with a `Tag` icon in `ActivityFeedItem.vue`. PHP severity vocab unchanged (`info` already exists).
- [x] Echo singleton wired in `bootstrap.ts` against `VITE_REVERB_*`; `useActivityFeed` composable subscribes, prepends, dedupes, caps, unsubscribes.
- [x] `AppLayout.vue` and `Pages/Activity/Index.vue` both consume the composable; both surfaces show a "Live updates offline" pill when the websocket isn't connected.
- [x] `BROADCAST_CONNECTION=reverb` set in `.env.example`.
- [x] README documents the third Reverb tunnel with the dual-tunnel section already established by the cloudflared work.
- [x] Pint clean, `npm run build` green, **239/239** tests passing.

## Files touched
- `app/Domain/Activity/ActivityEventPresenter.php` — new. Single mapping from `ActivityEvent` model to the JSON shape the frontend expects. Used by the query, the broadcast, and the future Overview swap.
- `app/Domain/Activity/Queries/RecentActivityForUserQuery.php` — refactored to use the presenter.
- `app/Domain/Activity/Actions/CreateActivityEventAction.php` — dispatches `ActivityEventCreated` after the row insert.
- `app/Events/ActivityEventCreated.php` — new. `ShouldBroadcastNow` event, broadcasts on `PrivateChannel("users.{ownerId}.activity")`, payload via the presenter, fixed `broadcastAs()` name.
- `app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php` — new. Maps completed workflow runs to `workflow.succeeded` / `workflow.failed`.
- `app/Domain/GitHub/WebhookHandlers/PushWebhookHandler.php` — new. Updates `repositories.last_pushed_at` only.
- `app/Domain/GitHub/WebhookHandlers/ReleaseWebhookHandler.php` — new. Maps published releases to `release.published`.
- `app/Domain/GitHub/Jobs/ProcessGitHubWebhookJob.php` — registered the three new handlers in the router.
- `routes/channels.php` — registered `users.{userId}.activity` channel authorization.
- `bootstrap/app.php` — added `withBroadcasting(...)` so the `/broadcasting/auth` endpoint is available.
- `.env.example` — `BROADCAST_CONNECTION=reverb` with a `null`-fallback note.
- `resources/js/bootstrap.ts` — Echo singleton against Reverb (env-driven, graceful fallback when env vars are missing).
- `resources/js/lib/useActivityFeed.ts` — new composable. Reseed on mount + navigation, prepend on broadcast, dedupe by id, cap to limit, expose `connected` flag.
- `resources/js/Layouts/AppLayout.vue` — swap computed for the composable; pass `:realtime-connected` to both rail variants.
- `resources/js/Components/Activity/RightActivityRail.vue` — new `realtimeConnected` prop renders a small "Offline" pill when `false`.
- `resources/js/Pages/Activity/Index.vue` — consumes the composable too; shows "Live updates offline" pill on the page header.
- `resources/js/Components/Activity/ActivityFeedItem.vue` — extended icon map for the four event types added/used (`issue.reopened`, `issue.updated`, `pull_request.closed`, `release.published`).
- `resources/js/types/index.d.ts` — extended `ActivityEventType` union.
- `package.json` / `package-lock.json` — `laravel-echo`, `pusher-js`.
- `README.md` — Reverb-tunnel walkthrough + Phase 3 marked complete.
- `specs/README.md`, `specs/phase-3-webhooks-activity/README.md` — trackers flipped to 🟢.
- 5 new test files: `WorkflowRunWebhookHandlerTest`, `PushWebhookHandlerTest`, `ReleaseWebhookHandlerTest`, `ActivityEventCreatedBroadcastTest`, `ActivityChannelTest` (21 new tests, total 239/239).

## Work log

### 2026-04-29
- Spec drafted. Confirmed surface: Reverb already installed (env keys present, config/broadcasting.php has the driver), no Echo yet, channels.php only carries the default User model channel, two webhook handlers exist (issues + pull_request), three more needed for Phase 3 completeness.
- User confirmed the three scope decisions (push events stay silent; release.published is a new TS type; ship the connection-status pill).
- Implemented backend (presenter + broadcast event + dispatch from action + channel auth + `withBroadcasting` registration), three new handlers (workflow_run / push / release), Echo + composable on the frontend, AppLayout + activity-page consumers + connection-status pill, README + .env updates, 5 new test files (21 tests).
- Pipeline: Pint clean, `npm run build` green, `php artisan test` **239/239** (was 218 — +21 new). Phase 3 trackers flipped to 🟢.
- Ran `superpowers:code-reviewer`. Three materials, all addressed in commit (TBD):
    - **`withBroadcasting()` was registered twice.** `withRouting(channels: …)` already invokes `withBroadcasting` internally (verified in `vendor/.../ApplicationBuilder.php:182`), which made the explicit call register `/broadcasting/auth` twice in the route table. Dropped the redundant call.
    - **`ShouldBroadcastNow` could poison the webhook handler.** The synchronous broadcaster runs on the request thread; a Reverb outage would throw out of the action and trigger a job retry, which would re-insert the row (no idempotency key). Wrapped the dispatch in try/catch with `Log::warning` — broadcasts are best-effort, and the page-load read from spec 018 covers the gap until the next event.
    - **`watch(seed)` in `useActivityFeed` clobbered broadcast-prepended events.** Vue's reactive read of `props.activityEvents` / `page.props.activity?.recent` returns a fresh array each navigation, which fired the watch and re-seeded — wiping every realtime row that had streamed in. Removed the watch entirely; `router.on('navigate')` already handles reseeding at the actual lifecycle point that needs it.

## Open questions / blockers
- **Broadcast recipient set.** An `activity_event` row is tied to a `repository_id`, which belongs to a `project`, which has an `owner_user_id`. Today the broadcast goes to that single owner. Multi-tenant scoping (when teams arrive) will widen this to all team members — likely a separate spec.
- **Push activity events.** Roadmap §8.5 lists `push` as a supported event but §8.10 doesn't include `push.*` in the activity-event vocabulary. Going with: handler updates `repositories.last_pushed_at` only, no activity event. Confirm before implementing.
- **Echo reconnection behavior on tunnel flap.** Cloudflare quick-tunnels drop the WebSocket occasionally. Echo auto-reconnects, but we may want a UI reconnect indicator + manual "refresh" CTA. Optional — flagged in scope.
