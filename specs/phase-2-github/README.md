# Phase 2 — GitHub Integration MVP

Source: [roadmap §19 Phase 2](../../nexus_control_center_roadmap.md), §8.3 Repositories, §8.4 Issues & Pull Requests, §27 Layered architecture (`app/Domain/GitHub`).

## Phase goal
Connect Nexus to GitHub for real. After this phase a logged-in user can authorize Nexus against their GitHub account, pick which repositories to import, and watch the existing `repositories` table fill with live metadata (stars, language, default branch, last push, sync status). Issues and pull requests sync into local tables and surface in a unified `/work-items` page. A "Run sync" button on each repo (and a project-level button) lets the user kick a sync on demand. Dashboard widgets reading repo metadata (Top Repositories, Hosts KPI proxy) start displaying real GitHub numbers without code changes — that's the payoff of the spec-012 wiring.

This phase is **read-only against GitHub**. Webhooks and near-real-time updates are phase 3.

## Tasks

| # | Task | Status |
|---|------|--------|
| 013 | GitHub App connection (OAuth flow, encrypted token storage, Settings/Integrations panel) | 🟢 |
| 014 | Repository import (list available repos, user picks which to import, `SyncGitHubRepositoryJob` populates metadata) | 🟢 |
| 015 | Issues sync (database + sync job + Repository Issues tab) | 🟢 |
| 016 | Pull requests sync + unified Work Items page (both filters + "Run sync" buttons) | 🟢 |

## Acceptance criteria (phase-level)
- [ ] User can connect a GitHub account and see their connection status.
- [ ] User can pick which of their accessible GitHub repositories to import into Nexus.
- [ ] `repositories` table populates with real metadata (stars, language, default branch, push timestamps, sync status).
- [ ] Issues sync into a `github_issues` table; PRs sync into a `github_pull_requests` table.
- [ ] `/work-items` page shows a unified issues + PRs view with filters (severity, repository, type).
- [ ] Per-repo "Run sync" button kicks a fresh sync; sync errors surface in the Sync Status column on the repo detail page.
- [ ] GitHub links from any list/detail open the correct GitHub URL.
- [ ] Top Repositories widget on the Overview reflects real GitHub stars (no spec changes needed — spec 012 wired the read query).
- [ ] No real GitHub credentials in CI; tests use mocked HTTP clients.
