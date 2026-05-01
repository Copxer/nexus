# Phase 4 — Deployments & CI/CD

Source: [roadmap §19 Phase 4](../../nexus_control_center_roadmap.md), §8.6 GitHub Actions workflow runs, §8.13 Deployment timeline.

## Phase goal
Surface GitHub Actions workflow runs as a "deployment timeline" inside Nexus. Phase 3 already wired a `WorkflowRunWebhookHandler` that creates `activity_events` for completed runs — but the run data itself is transient (only payload + activity metadata). Phase 4 adds durable storage, a backfill sync (so already-imported repos get historical runs), a per-repo Workflow Runs tab, a dedicated `/deployments` timeline page with filters + drawer, and an Overview success-rate widget.

## Tasks

| # | Task | Status |
|---|------|--------|
| 020 | Workflow runs storage + sync (table, model, sync job chained off repo-import + manual sync, webhook-handler upsert, per-repo Workflow Runs tab) | 🟢 |
| 021 | Deployment timeline UI (`/deployments` page, status timeline, detail drawer, filters by project / repository / status / branch, real-time refresh via Reverb) | 🟢 |
| 022 | Overview success-rate widget (KPI card on Overview powered by an aggregate query over `workflow_runs`) | 🟢 |

## Acceptance criteria (phase-level)
- [ ] Importing a repository backfills its recent workflow runs into the local `workflow_runs` table.
- [ ] `workflow_run` webhook deliveries upsert into `workflow_runs` (in addition to spec 019's existing activity-event creation).
- [ ] Repository show page exposes a Workflow Runs tab with status badges, conclusion, branch, run number, and a link out to GitHub.
- [ ] `/deployments` page renders a chronological timeline with success/failure markers and supports filtering by project, repository, status, and branch.
- [ ] Per-run drawer surfaces head SHA, duration, actor, conclusion, and links to the GitHub run.
- [ ] Overview shows a "Last 24h workflow runs" KPI card with success rate, powered by a real DB aggregate (no mocks).
- [ ] Failed workflow runs continue to surface as activity events on the right-rail / activity page (already covered by spec 019's handler — verified, not re-implemented).
- [ ] Pint + tests + build clean. CI green for every spec PR.
