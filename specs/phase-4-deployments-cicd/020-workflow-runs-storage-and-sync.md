---
spec: workflow-runs-storage-and-sync
phase: 4-deployments-cicd
status: done
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/61
branch: spec/020-workflow-runs-storage-and-sync
---

# 020 — Workflow runs storage + sync

## Goal
Stand up durable storage for GitHub Actions workflow runs, the sync plumbing to populate it, and a per-repo Workflow Runs tab so the data is visible end-to-end. After this spec, a Nexus user who imports a repository immediately sees historical runs; new runs flow in via the existing spec-019 webhook handler (extended here to upsert into the table); and a manual "Run sync" button per tab refreshes on demand.

This is the **data layer** of phase 4. Spec 021 builds the cross-repo `/deployments` timeline + filters + drawer. Spec 022 surfaces a success-rate KPI on Overview.

Roadmap reference: §8.6 GitHub Actions / Workflow Runs (model fields, sync flow, conclusion enum), §19 Phase 4 (deliverables + acceptance criteria).

## Scope
**In scope:**

- **`workflow_runs` table** mirroring the relevant slice of GitHub's `actions/runs` payload:
    - `id` (PK), `repository_id` (FK → `repositories`, cascade on delete), `github_id` (string, GitHub's numeric run id), `run_number` (unsigned int), `name` (string, the workflow name), `event` (string, e.g. `push`/`pull_request`/`schedule`/`workflow_dispatch`), `status` (string-backed enum: `queued`/`in_progress`/`completed`), `conclusion` (string-backed enum nullable: `success`/`failure`/`cancelled`/`timed_out`/`action_required`/`stale`/`neutral`/`skipped`), `head_branch` (string nullable), `head_sha` (string), `actor_login` (string nullable), `html_url` (string), `run_started_at` (datetime nullable), `run_updated_at` (datetime nullable, the GitHub-side `updated_at`), `run_completed_at` (datetime nullable, derived from payload `updated_at` when status=completed), standard `created_at`/`updated_at`.
    - **Unique** on `(repository_id, github_id)` for upsert idempotency.
    - **Index** on `(repository_id, run_started_at desc)` for the per-repo tab listing and the cross-repo timeline query in spec 021.

- **`*_workflow_runs_sync_*` columns on `repositories`** mirroring the six-column pattern shipped for issues / PRs:
    - `workflow_runs_sync_status` (string, default `pending`, indexed), `workflow_runs_synced_at` (datetime nullable), `workflow_runs_sync_error` (text nullable), `workflow_runs_sync_failed_at` (datetime nullable).
    - Two additional columns added inline with the above pair (`*_sync_error` after status, `*_sync_failed_at` after synced_at) for symmetry with the metadata / issues / PRs flows.

- **`App\Models\WorkflowRun`** — Eloquent model.
    - Fillable + casts for the columns above. Status / conclusion cast to enums. Datetimes cast normally.
    - `repository()` belongsTo. `getRouteKeyName()` stays default (numeric id) — there's no nice slug shape to bind on.

- **`App\Enums\WorkflowRunStatus`** (`Queued`, `InProgress`, `Completed`) and **`App\Enums\WorkflowRunConclusion`** (`Success`, `Failure`, `Cancelled`, `TimedOut`, `ActionRequired`, `Stale`, `Neutral`, `Skipped`) with `badgeTone()` helpers consistent with `RepositorySyncStatus` + `ActivitySeverity`.

- **`GitHubClient::listWorkflowRuns(string $fullName, int $perPage = 100): array`** — new client method.
    - Hits `GET /repos/{owner}/{repo}/actions/runs?per_page={perPage}`.
    - Returns the unwrapped `workflow_runs` array (GitHub wraps the payload in `{ total_count, workflow_runs: [...] }`).
    - Throws `GitHubApiException` on non-2xx (existing pattern).
    - Phase-1 caps at `perPage = 100`; pagination is a follow-up if the timeline UI needs deeper history.

- **`App\Domain\GitHub\Actions\NormalizeGitHubWorkflowRunAction`** — pure transformation, payload → array shaped for `WorkflowRun::upsert()`. Mirrors `NormalizeGitHubIssueAction` / `NormalizeGitHubPullRequestAction`. Returns `null` for malformed payloads (missing `id` / `head_sha`) so the caller can skip cleanly.

- **`App\Domain\GitHub\Actions\SyncRepositoryWorkflowRunsAction`** — orchestrates the fetch + upsert.
    - Fetches via `GitHubClient::listWorkflowRuns`.
    - Normalizes each row, drops nulls, upserts on `(repository_id, github_id)`.
    - Returns the count of rows persisted (insert + update both count) for observability — same shape as the issues/PRs actions.

- **`App\Domain\GitHub\Jobs\SyncRepositoryWorkflowRunsJob`** — `ShouldQueue` job, parallel to `SyncRepositoryIssuesJob` and `SyncRepositoryPullRequestsJob`.
    - Constructor: `int $repositoryId`.
    - Same lifecycle: `pending → syncing (clears error) → synced (timestamps + clears error) | failed (sets error + failed_at)`.
    - Same 401 handling: clear `access_token` + zero `expires_at` so the Settings card surfaces Reconnect.
    - `tries = 1` (matches the rest).

- **`SyncGitHubRepositoryJob` chains the new job on success.** Currently it dispatches issues + PRs. Add workflow runs alongside, wrapped in the same `dispatchChildSync` try/catch so a queue blip in one child doesn't suppress the others.

- **`RepositorySyncController` clears the new error pair too.** When the user clicks the top-level "Run sync", it clears all *eight* error columns (metadata + issues + PRs + workflow runs).

- **`App\Http\Controllers\RepositoryWorkflowRunsSyncController`** — new single-action controller for the per-tab "Run sync" button. Mirrors `RepositoryPullRequestsSyncController` exactly. Authorizes via `ProjectPolicy::update`. Dispatches the job. Returns `back()->with('status', 'Workflow runs sync queued.')`.

- **Route**: `Route::post('/repositories/{repository}/workflow-runs/sync', RepositoryWorkflowRunsSyncController::class)->name('repositories.workflow-runs.sync')`.

- **`App\Domain\GitHub\Queries\WorkflowRunsForRepositoryQuery`** — query object returning the run rows shaped for the tab. Sorted `run_started_at desc, github_id desc` (recent first, deterministic tie-break). Caps at 100. Mirrors `IssuesForRepositoryQuery` / `PullRequestsForRepositoryQuery`.

- **`RepositoryController::show` payload extension**:
    - `workflowRuns` array (from the new query).
    - `workflowRunsSync` array shaped `{ status, synced_at, error, failed_at }` matching `issuesSync` + `pullRequestsSync`.

- **`Repositories/Show.vue` — new Workflow Runs tab.**
    - Tab nav button (icon: `Activity` from lucide; or `Workflow` if it lands clean) with a count badge like the existing Issues / PRs tabs.
    - Header strip mirrors the existing tabs: status badge, "Last sync" or "Failed Xm ago", per-tab "Run sync" button (gated to project owner via `canSync`), per-tab failure banner that surfaces `workflowRunsSync.error` when `status === 'failed'`.
    - List body: each row shows `name #run_number`, `event`, `head_branch`, conclusion badge (with the new enum's tone helper), actor, "Updated Xm ago", and an external-link icon → `html_url`.
    - Empty state mirrors the existing tabs: "Workflow runs haven't been synced yet" / "The last workflow runs sync failed." / "No workflow runs mirrored for this repository."

- **`WorkflowRunWebhookHandler` upserts into the new table.** Currently it only creates the activity event. Extend it to *also* normalize + upsert the run (using `NormalizeGitHubWorkflowRunAction` + `WorkflowRun::upsert`) before the activity-event creation. Repository resolution is already wired. Spec 019's existing tests stay green; new tests cover the upsert path.

- **Tests** (Pest/PHPUnit, mirrors existing GitHub specs):
    - `NormalizeGitHubWorkflowRunActionTest` — happy path + drops nulls + handles missing optional fields.
    - `SyncRepositoryWorkflowRunsActionTest` — Http::fake'd payload, asserts upsert idempotency.
    - `SyncRepositoryWorkflowRunsJobTest` — happy path, 401 → connection expiry, 500 → error/failed_at persisted, no-connection → error stored, success clears error/failed_at, syncing flip clears errors. Mirrors `SyncRepositoryIssuesJobTest` exactly.
    - `RepositoryWorkflowRunsSyncControllerTest` — owner can dispatch (Queue::fake'd), non-owner forbidden, unknown repo 404.
    - `WorkflowRunsForRepositoryQueryTest` — orders, caps, scoping.
    - `RepositoryControllerTest` — extend the existing show test to assert `workflowRuns` + `workflowRunsSync` props are present.
    - `WorkflowRunWebhookHandlerTest` — extend the existing tests to assert the upsert side-effect (existing activity-event assertions stay).
    - `SyncGitHubRepositoryJobTest` — assert `SyncRepositoryWorkflowRunsJob` is chained on success.

**Out of scope:**

- The `/deployments` cross-repo timeline page + filters + detail drawer → spec 021.
- Overview KPI card / success-rate chart → spec 022.
- Workflow run detail page or drawer (the Workflow Runs tab links out to GitHub for now; in-app drawer is spec 021's job).
- Pagination for the sync (cap at 100 most recent runs per fetch — phase-1 simplification, parallel to issues/PRs).
- "Is this a deployment?" classification — sync all runs; spec 021's UI can add an optional filter or tagging if it earns its keep.
- Re-running / cancelling workflow runs from Nexus (write-direction GitHub Apps work is phase 9 polish if at all).
- Backfill of historical runs older than the 100 most recent — chase a follow-up only if Phase-1 users complain.
- Activity-event modeling for *new* event types (e.g. `deployment_status`). Spec 019 already covered `workflow_run`.

## Plan

1. **Migration: `create_workflow_runs_table`**, plus a sibling migration `add_workflow_runs_sync_columns_to_repositories_table` (mirrors spec 015's schema-extension pattern).
2. **Enums + model + factory.** `WorkflowRunStatus`, `WorkflowRunConclusion`, `WorkflowRun`, `WorkflowRunFactory`. Update the `Repository` model fillable + casts for the four new columns.
3. **Normalize action + tests.** Drive the shape from a sample GitHub `actions/runs` payload (capture once, store as a fixture).
4. **GitHubClient::listWorkflowRuns + tests.** Http::fake'd happy path + 401 + 500.
5. **Sync action + job + tests.** Mirror `SyncRepositoryIssuesAction` / `SyncRepositoryIssuesJob` 1:1, swapping issues for workflow runs.
6. **Chain the new job from `SyncGitHubRepositoryJob`.** Update the existing test that asserts both child syncs dispatch — it'll now assert all three.
7. **`RepositoryWorkflowRunsSyncController` + route + tests.** Mirror PRs.
8. **`RepositorySyncController` clears the new error pair too.** Update the existing controller test that asserts the all-six clear so it now asserts all-eight.
9. **Query object + tests.**
10. **Show controller payload extension + test.**
11. **`Repositories/Show.vue` Workflow Runs tab.** TS interfaces, computed counts, syncing state, runWorkflowRunsSync handler. Reuse the existing failure-banner shape.
12. **Extend `WorkflowRunWebhookHandler`** to upsert + tests. Existing assertions stay; add upsert-side assertions.
13. **Self-review pass via `superpowers:code-reviewer`.**
14. **Open the PR.** CI must be green before merge. Wait for explicit "merge it" before squash-merging.

## Acceptance criteria
- [ ] `workflow_runs` table exists with the documented columns + indexes.
- [ ] `repositories` carries the four new sync columns; `RepositorySyncStatus` enum reused (no parallel enum).
- [ ] `WorkflowRun` model + factory exist; status/conclusion cast to typed enums.
- [ ] `GitHubClient::listWorkflowRuns` returns the unwrapped `workflow_runs` array on 2xx; throws `GitHubApiException` on non-2xx.
- [ ] `SyncRepositoryWorkflowRunsJob` runs `pending → syncing → synced` on happy path; clears `workflow_runs_sync_error` + `workflow_runs_sync_failed_at` on success; sets them on failure (capped at 500 chars).
- [ ] On 401 the connection's `access_token` is cleared so the Settings card surfaces Reconnect (parallel to issues/PRs).
- [ ] `SyncGitHubRepositoryJob` chains the new job on success — repository sync now triggers metadata + issues + PRs + workflow runs.
- [ ] Top-level "Run sync" controller clears all eight error columns (metadata + issues + PRs + workflow runs) so stale red banners don't outlive a manual re-trigger.
- [ ] Per-tab `POST /repositories/{r}/workflow-runs/sync` dispatches the job, gated to project owner; returns 403 for non-owners and 404 for unknown repos.
- [ ] Repository show page exposes a Workflow Runs tab with status badge, "Last sync Xm ago" / "Failed Xm ago", per-tab Run sync button (project owner only), per-tab failure banner, and a sortable list of runs.
- [ ] Workflow run rows link out to GitHub via `html_url`.
- [ ] `WorkflowRunWebhookHandler` upserts the run row into `workflow_runs` *in addition* to the existing activity-event creation. Existing spec 019 tests stay green.
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
List of created/modified files. Fill in as work progresses.

- `database/migrations/<ts>_create_workflow_runs_table.php` — new.
- `database/migrations/<ts>_add_workflow_runs_sync_columns_to_repositories_table.php` — new.
- `app/Enums/WorkflowRunStatus.php` — new.
- `app/Enums/WorkflowRunConclusion.php` — new.
- `app/Models/WorkflowRun.php` — new.
- `app/Models/Repository.php` — extend fillable + casts.
- `database/factories/WorkflowRunFactory.php` — new.
- `app/Domain/GitHub/Services/GitHubClient.php` — add `listWorkflowRuns`.
- `app/Domain/GitHub/Actions/NormalizeGitHubWorkflowRunAction.php` — new.
- `app/Domain/GitHub/Actions/SyncRepositoryWorkflowRunsAction.php` — new.
- `app/Domain/GitHub/Jobs/SyncRepositoryWorkflowRunsJob.php` — new.
- `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php` — chain the new job in the success path.
- `app/Domain/GitHub/Queries/WorkflowRunsForRepositoryQuery.php` — new.
- `app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php` — extend to upsert.
- `app/Http/Controllers/RepositoryWorkflowRunsSyncController.php` — new.
- `app/Http/Controllers/RepositoryController.php` — extend show payload.
- `app/Http/Controllers/RepositorySyncController.php` — clear all eight error columns on manual re-trigger.
- `routes/web.php` — add the workflow-runs sync route.
- `resources/js/Pages/Repositories/Show.vue` — new Workflow Runs tab.
- `tests/Feature/GitHub/NormalizeGitHubWorkflowRunActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryWorkflowRunsActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryWorkflowRunsJobTest.php` — new.
- `tests/Feature/GitHub/SyncGitHubRepositoryJobTest.php` — extend chained-dispatch assertion.
- `tests/Feature/GitHub/RepositoryWorkflowRunsSyncControllerTest.php` — new.
- `tests/Feature/GitHub/RepositorySyncControllerTest.php` — extend "clear stale errors" test from six → eight columns.
- `tests/Feature/GitHub/WorkflowRunsForRepositoryQueryTest.php` — new.
- `tests/Feature/GitHub/Webhooks/WorkflowRunWebhookHandlerTest.php` — extend with upsert assertions.
- `tests/Feature/Repositories/RepositoryControllerTest.php` — extend show-payload test.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- Opened issue [#61](https://github.com/Copxer/nexus/issues/61) and branch `spec/020-workflow-runs-storage-and-sync` off `main`.
- Implementation complete: 2 migrations + `WorkflowRun` + `WorkflowRunStatus` + `WorkflowRunConclusion` enums + factory, `NormalizeGitHubWorkflowRunAction` (pure transform), `GitHubClient::listWorkflowRuns`, `SyncRepositoryWorkflowRunsAction` + `SyncRepositoryWorkflowRunsJob` (mirrors issues/PRs lifecycle), chained off `SyncGitHubRepositoryJob`, `RepositoryWorkflowRunsSyncController` + route, `WorkflowRunsForRepositoryQuery`, `RepositorySyncController` extended to clear all 8 error columns, `RepositoryController::show` payload extended, `Repositories/Show.vue` Workflow Runs tab. `WorkflowRunWebhookHandler` extended to upsert into `workflow_runs` for ALL deliveries (in-flight + terminal); spec 019's existing assertions stay valid.
- 26 net new passing tests; full suite: 239 passed (was 213). Pint + build clean.
- Self-review pass via `superpowers:code-reviewer`; addressed 4 of 5 recommendations: added `workflowStatusTone()` helper for the run-status badge fallback, doc'd the `WebhookDeliveryStatus::Skipped` semantics shift, switched the Workflow Runs `v-else` to `v-else-if` for forward safety, and added a re-run comment on `run_completed_at`. Deferred: a stale-webhook overwrite guard (last-write-wins on the upsert) — pre-existing across all three sync flows; will harden in a follow-up if it bites.

## Decisions (locked 2026-04-30)
- **Naming: `WorkflowRun`, not `Deployment`.** The model + DB use GitHub's own vocabulary; the user-facing UI in spec 021 will say "deployments" where appropriate. The mismatch in the roadmap is a UX choice, not a data-model one.
- **Sync all runs, not just deploy-shaped ones.** GitHub has no clean "is deployment" flag. Phase-1 syncs everything; spec 021's UI can layer optional filtering / tagging later if the timeline gets noisy.
- **Reuse `RepositorySyncStatus` for the new sync columns.** Don't introduce a parallel enum. Same shape, same lifecycle.
- **Cap fetch at 100 most recent runs.** Mirrors issues/PRs phase-1 cap; pagination is a follow-up if the timeline UI needs deeper history.
- **Webhook handler upsert lives in the existing `WorkflowRunWebhookHandler`.** Don't introduce a parallel upsert handler — the existing handler already resolves the repository and reads the payload.

## Open questions / blockers
- **Lucide icon for the Workflow Runs tab.** `Workflow`, `Activity`, or `PlayCircle`? Pick during implementation; trivially swappable.
- **Conclusion badge tones.** Map `success → success`, `failure → danger`, `cancelled → warning`, `timed_out → warning`, others → muted. Confirm against `StatusBadge.vue` token set.
- **`run_started_at` vs GitHub's `created_at`.** GitHub returns both; `run_started_at` is the actual run start (post-queue). Use `run_started_at` for the listing sort and fall back to `created_at` only if it's null.
