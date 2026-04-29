# Phase 3 — GitHub Webhooks & Activity Feed

Source: [roadmap §19 Phase 3](../../nexus_control_center_roadmap.md), §8.5 Webhooks, §8.10 Activity Feed.

## Phase goal
Make GitHub updates near real-time. After this phase, importing a repo wires a GitHub App webhook subscription so issue/PR/workflow events flow into Nexus without polling. Each event lands as a row in `github_webhook_deliveries` (raw audit trail) and creates one or more `activity_events` rows (the Nexus-side semantic event stream). The AppLayout right-rail and a dedicated activity page render those events; the same events broadcast over Reverb so the UI reflects them without reload.

## Tasks

| # | Task | Status |
|---|------|--------|
| 017 | GitHub webhook ingestion + activity events (receiver, signature verification, delivery storage, `issues` + `pull_request` handlers, `activity_events` table + `CreateActivityEventAction`) | 🟢 |
| 018 | Activity Feed UI (replace AppLayout right-rail mock with real activity, `ActivityFeed.vue` + `ActivityFeedItem.vue`, page-load fresh) | 🟢 |
| 019 | Real-time broadcasting via Reverb (Echo wiring, `ActivityEventCreated` broadcast, extend handler set to `workflow_run`/`push`/`release`) | 🟢 |

## Acceptance criteria (phase-level)
- [x] GitHub webhook endpoint at `POST /webhooks/github` accepts signed payloads.
- [x] Invalid `X-Hub-Signature-256` signatures are rejected with 401 and never stored.
- [x] Duplicate deliveries (same `X-GitHub-Delivery` header) are detected and not double-processed.
- [x] Issue and PR webhook events update the local `github_issues` / `github_pull_requests` rows and create matching `activity_events`.
- [x] Activity Feed UI surfaces real events on the AppLayout right-rail and on the dedicated `/activity` page.
- [x] UI updates in real time when new events land — no manual refresh needed (`useActivityFeed` composable + `ActivityEventCreated` broadcast).
- [x] No real GitHub credentials in CI; tests use signed fixture payloads + `Queue::fake()` / `Event::fake()`.
