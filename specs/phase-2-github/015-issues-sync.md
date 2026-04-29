---
spec: issues-sync
phase: 2-github
status: in-progress
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/39
branch: spec/015-issues-sync
---

# 015 — Issues sync (database + sync job + Repository Issues tab)

## Goal
Pull GitHub issues for every imported `Repository` into a local `github_issues` table, hooked into spec 014's `SyncGitHubRepositoryJob` so the issues sync runs automatically after a repo's metadata sync. Surface the issues on a new "Issues" tab on the existing Repository show page (sibling of Overview), with a "Run sync" button that re-dispatches the issues job. After this spec, every imported repo carries a live local issues mirror that the user can browse without leaving Nexus — the foundation for spec 016's unified Work Items page.

This is the **issues** half of phase-2's "issues + PRs" pair. PRs ship in spec 016 in the same domain (with the unified `/work-items` page that joins both tables behind filters, sorts, and badges). Webhooks for near-real-time issue updates remain phase 3.

Roadmap reference: §8.4 GitHub Issues & Pull Requests (`github_issues` schema + UX requirements), §27 (`app/Domain/GitHub/Actions/SyncRepositoryIssuesAction.php`).

## Scope
**In scope:**

- **`github_issues` table** with the field set from §8.4 (minus `priority`, which is a derived/UI concern that lands with 016's badge logic):
    - `id` (PK), `repository_id` (FK → `repositories`, cascade delete), `github_id` (bigint, GitHub's numeric `id`), `number` (int, repo-scoped issue number)
    - `title` (string), `body_preview` (text, truncated at 280 chars)
    - `state` (enum: `open`/`closed`)
    - `author_login` (string, nullable)
    - `labels` (json, list of `{name, color}`), `assignees` (json, list of usernames), `milestone` (json, `{title, due_on}` or null)
    - `comments_count` (unsigned int)
    - `is_locked` (bool)
    - `created_at_github`, `updated_at_github`, `closed_at_github` (datetime, nullable for closed)
    - `synced_at` (datetime)
    - Standard `created_at` / `updated_at`
    - **Compound unique index** `(repository_id, github_id)` so re-sync upserts in place.

- **`App\Models\GithubIssue`** — Eloquent model.
    - Casts: `labels`, `assignees`, `milestone` as `array`; `is_locked` as `bool`; the GitHub timestamp columns as `datetime`; `state` as a string-backed enum (`App\Enums\GithubIssueState`).
    - `repository()` belongs-to.
    - `$fillable` shaped to the column list.

- **`App\Enums\GithubIssueState`** — `Open` / `Closed`. Tiny enum to keep state strings centralized + add `badgeTone()` for UI.

- **Repositories table extension.** New nullable `issues_sync_status` enum column + `issues_synced_at` datetime, mirroring spec 014's repo-metadata-sync columns. Lifecycle `pending → syncing → synced/failed`. The repo's overall row stays untouched (spec 014's `sync_status`/`last_synced_at` cover repo metadata; new columns cover issues sync independently so the user can see them diverge if one succeeds and the other fails).

- **`App\Domain\GitHub\Actions\NormalizeGitHubIssueAction`** — pure mapper from a single GitHub `/issues` payload to the array we persist. Drops PRs (`pull_request` key set), trims body to 280 chars, picks the curated label/assignee/milestone fields. Used by both the sync job and (later) by spec 3's webhook handler so we have one normalization path.

- **`App\Domain\GitHub\Actions\SyncRepositoryIssuesAction`** — orchestrates one repository's issues sync.
    - Takes a `Repository` model + an injected `GitHubClient` (constructed by the caller around the owner's `GithubConnection`, same pattern as spec 014).
    - Calls `GET /repos/{full_name}/issues?state=all&per_page=100&since={last_synced_iso8601}` (omits `since` on first sync). Single page only — multi-page is phase-9 polish (consistent with spec 014's `?per_page=100` decision).
    - For each entry: skip PRs (the GitHub endpoint returns them too — they have a `pull_request` object), normalize via `NormalizeGitHubIssueAction`, upsert into `github_issues` keyed on `(repository_id, github_id)`.
    - Returns `int` count of issues synced (for log + UI flash).

- **`App\Domain\GitHub\Jobs\SyncRepositoryIssuesJob`** — `ShouldQueue` job that wraps the action.
    - Constructor: `int $repositoryId`.
    - `handle()`: load repo + connection (same `resolveConnection` shape as spec 014's job — owner project → owner user → `githubConnection`); flip `issues_sync_status = syncing`; call `SyncRepositoryIssuesAction`; on success flip to `synced` + stamp `issues_synced_at = now()`; on `GitHubApiException::isUnauthorized()` reuse spec 014's `expireConnection` mechanic (clear token + flag expired); on any caught failure flip to `failed` (preserve `issues_synced_at` per the M1 fix landed in spec 014).
    - `tries = 1`. Same lifecycle decision as spec 014's job.

- **Hook into `SyncGitHubRepositoryJob`.** After the repo metadata `synced` save, dispatch `SyncRepositoryIssuesJob::dispatch($repository->id)`. So importing a repo via spec 014's flow → repo metadata syncs → issues sync fires automatically. Failure on the issues side does NOT mark the repo metadata sync as failed; they're independent statuses.

- **`App\Http\Controllers\RepositoryIssuesSyncController`** — single-action controller for the manual "Run sync" button.
    - `POST /repositories/{repository}/issues/sync` (route key constraint matches spec 011's two-segment slug).
    - `ProjectPolicy::update($repository->project)` gate.
    - Dispatches `SyncRepositoryIssuesJob::dispatch($repository->id)` and redirects back with a flash.

- **`App\Domain\GitHub\Queries\IssuesForRepositoryQuery`** — small query class that returns the issues list shape the page needs (paginate later if it earns its keep — for MVP we return the most-recent 50 ordered by `updated_at_github desc`). Returns plain arrays so the controller / Inertia don't leak Eloquent magic into the front-end.

- **Repository show page — Issues tab.** Add an "Issues" tab next to the existing "Overview" tab on `Pages/Repositories/Show.vue` (the page already exists from earlier specs). Renders:
    - Sync status strip: `issues_sync_status` badge + relative `issues_synced_at` + "Run sync" button (POSTs to the new route).
    - List: each row is `#{number}` + title + state badge (`Open` cyan / `Closed` muted) + `author_login` + `comments_count` + relative `updated_at_github` + a small external link to `html_url`. Empty state: "No issues synced yet — click Run sync to fetch."

- **Routes.** Add to `routes/web.php` under the existing `auth + verified` group:
    ```php
    Route::post('/repositories/{repository}/issues/sync', RepositoryIssuesSyncController::class)
        ->name('repositories.issues.sync');
    ```
    Reuse the existing `{repository}` two-segment route key constraint.

- **Backfill in spec 014's import flow.** `ImportRepositoryAction` already dispatches `SyncGitHubRepositoryJob`; once that job auto-dispatches the issues job, no controller-level change is needed. The first user-visible signal that issues are wired is: import → repo show page → Issues tab populated.

- **Tests** (PHP feature tests):
    - `NormalizeGitHubIssueActionTest` — happy path, missing optional fields, label/assignee/milestone shape, body trim at 280 chars.
    - `SyncRepositoryIssuesActionTest` — `Http::fake()` on `/repos/{full}/issues`; happy path inserts; PR rows are dropped; second sync upserts (no duplicate rows); 401 surfaces `GitHubApiException::isUnauthorized() === true`; `since` query param sent on follow-up syncs.
    - `SyncRepositoryIssuesJobTest` — lifecycle (`pending → syncing → synced` and `→ failed`); 401 path expires the connection (asserting the same access-token-blanking + `expires_at = now()` as spec 014); `issues_synced_at` is preserved on failure (the M1 fix landed in spec 014, mirrored here).
    - `SyncGitHubRepositoryJobTest` — extension test: on the happy path, `SyncRepositoryIssuesJob` is dispatched (assert via `Queue::fake()`).
    - `RepositoryIssuesSyncControllerTest` — manual sync dispatches the job for the project owner; non-owner is 403; 404 for repo not found.
    - `RepositoryShowTest` — Issues tab payload shape; the "Run sync" CTA renders only for users with update permission.

- **Update phase trackers** in the same PR (and the post-merge bookkeeping flips 015 to 🟢).

**Out of scope:**

- Pull requests (spec 016) — the entire `github_pull_requests` table, normalization, and PR sync action ship there. Spec 015's GitHub call already hides them via the `pull_request` filter.
- Unified `/work-items` page (spec 016) — issues live on the per-Repository Issues tab for now. The cross-repo work queue lands when there's something to filter together.
- `PriorityBadge` / `PullRequestStatusBadge` / `WorkItemFilters` / `WorkItemTable` components from §8.4 — those are 016's UI surface.
- Webhooks (phase 3). The job is the only ingestion path.
- Multi-page issues fetching (`?page=2…`). 100 issues is plenty for MVP; if a real user has more, we add pagination then.
- Bulk "re-sync all repos" button. The per-repo "Run sync" is enough surface for spec 015.
- Per-issue Nexus notes / triage state. Phase 9.
- Filtering / sorting / search on the Issues tab. Comes with spec 016 when the table grows tabs + filters.
- Body markdown rendering. We persist a 280-char preview; a future spec adds a detail view with markdown rendering if it earns its keep.

## Plan

1. **Migration + model + enum.** `php artisan make:migration create_github_issues_table` → spec the schema + compound unique. Make the `GithubIssue` model with the casts/relations. Add the enum class. Add the `issues_sync_status` + `issues_synced_at` columns to `repositories` via a separate migration.
2. **`NormalizeGitHubIssueAction`.** Pure function: takes a single GitHub issue payload, returns a `?array` (null if it's a PR). Trim body. Map labels/assignees/milestone shapes.
3. **`SyncRepositoryIssuesAction`.** Wire to `GitHubClient` (likely add a `listIssues(Repository, ?Carbon $since): array` helper to `GitHubClient` so the URL building stays in one place). Loop, normalize, upsert.
4. **`SyncRepositoryIssuesJob`.** Wraps the action with the lifecycle/401/expire-connection plumbing. Mirror spec 014's `markFailed` (no `issues_synced_at` bump on failure).
5. **Hook into `SyncGitHubRepositoryJob`.** Dispatch the issues job after the repo metadata `synced` save.
6. **Controller + route + policy.** Single-action controller; `ProjectPolicy::update`.
7. **Query class + Inertia prop.** `IssuesForRepositoryQuery` — lean rows for the tab. Add to Repository show controller's render payload.
8. **Repository show — Issues tab.** Tab UI matches the Overview/Repositories pattern from earlier specs. Sync status strip + list + Run sync button + empty state. State badge uses `accent-cyan` (open) and `text-muted` (closed) — no raw `gray-*` / `red-*` / `green-*` / `indigo-*`.
9. **Tests.** Six test files per the list above. All `Http::fake()` + `Queue::fake()`.
10. **Manual UX walk** — Playwright at 1440×900: import a stubbed repo (or seed one), watch the Issues tab go from `pending → syncing → synced`, click Run sync to verify the manual path, screenshot the populated list.
11. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
12. **Self-review** with `superpowers:code-reviewer`. Address material findings.

## Acceptance criteria
- [ ] Migration creates `github_issues` with the field set above and `(repository_id, github_id)` unique index.
- [ ] Migration extends `repositories` with `issues_sync_status` + `issues_synced_at` (independent of the existing `sync_status` / `last_synced_at`).
- [ ] `NormalizeGitHubIssueAction` drops PR payloads, trims body to 280 chars, and gracefully handles missing optional fields (returns sane defaults, not null/missing keys).
- [ ] `SyncRepositoryIssuesAction` upserts on `(repository_id, github_id)`. A second sync against the same payload mutates one row in place (no dupes). Sends `since` param when `issues_synced_at` is non-null.
- [ ] `SyncRepositoryIssuesAction` raises `GitHubApiException::isUnauthorized() === true` on 401; the job's catch path clears the connection's `access_token` + sets `expires_at = now()` (mirroring spec 014).
- [ ] `SyncGitHubRepositoryJob` dispatches `SyncRepositoryIssuesJob` after a successful repo metadata sync (verified via `Queue::fake()::assertPushed`).
- [ ] `RepositoryIssuesSyncController` 200s for the project owner and 403s for non-owners.
- [ ] Repository show page has an "Issues" tab; Issues tab renders the synced rows with state badges, comment counts, author handles, relative GitHub timestamps, and external links; the "Run sync" button POSTs to the new route.
- [ ] `issues_synced_at` is preserved across a failed sync (M1 mirrored from spec 014).
- [ ] Closed issues stay in the table (not pruned) and render with a `Closed` badge.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes in the new Vue.
- [ ] No real GitHub credentials in CI; `Http::fake()` + `Queue::fake()` only.
- [ ] Pint + vue-tsc + build clean. CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`.

## Files touched
- `database/migrations/<ts>_create_github_issues_table.php` — new.
- `database/migrations/<ts>_add_issues_sync_columns_to_repositories_table.php` — new.
- `app/Models/GithubIssue.php` — new.
- `app/Enums/GithubIssueState.php` — new.
- `app/Domain/GitHub/Actions/NormalizeGitHubIssueAction.php` — new.
- `app/Domain/GitHub/Actions/SyncRepositoryIssuesAction.php` — new.
- `app/Domain/GitHub/Jobs/SyncRepositoryIssuesJob.php` — new.
- `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php` — extended to dispatch the issues job after repo metadata sync.
- `app/Domain/GitHub/Services/GitHubClient.php` — add `listIssues(Repository $repo, ?Carbon $since): array`.
- `app/Domain/GitHub/Queries/IssuesForRepositoryQuery.php` — new.
- `app/Http/Controllers/RepositoryIssuesSyncController.php` — new.
- `app/Http/Controllers/RepositoryController.php` — extend `show()` to include the issues + sync-status payload.
- `routes/web.php` — add the `repositories.issues.sync` POST route.
- `resources/js/Pages/Repositories/Show.vue` — add the "Issues" tab + sync status strip + list.
- `tests/Feature/GitHub/NormalizeGitHubIssueActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryIssuesActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryIssuesJobTest.php` — new.
- `tests/Feature/GitHub/SyncGitHubRepositoryJobTest.php` — extension test for the issues-job dispatch.
- `tests/Feature/GitHub/RepositoryIssuesSyncControllerTest.php` — new.
- `tests/Feature/Repositories/RepositoryShowTest.php` — extend (or add new tests) for the Issues tab payload.

## Work log
Dated notes as work progresses.

### 2026-04-29
- Spec drafted; scope confirmed (7 decisions locked: separate `github_issues` table, extend repo sync job to chain issues sync, single-page `?per_page=100&state=all&since=`, `(repository_id, github_id)` upsert, per-Repository Issues tab as the only UI surface, manual "Run sync" button + auto-chain on import, mirror spec 014's 401 → expire-connection + last-synced-preserved-on-failure plumbing).
- Opened issue [#39](https://github.com/Copxer/nexus/issues/39) and branch `spec/015-issues-sync` off `main`.
- Implementation complete: migrations + `GithubIssue` model + `GithubIssueState` enum, `NormalizeGitHubIssueAction`, `GitHubClient::listIssues`, `SyncRepositoryIssuesAction`, `SyncRepositoryIssuesJob`, chain from `SyncGitHubRepositoryJob`, `RepositoryIssuesSyncController` + route, `IssuesForRepositoryQuery`, Repository show page Issues tab. 6 test files (5 new + 1 extension): 138 tests / 559 assertions green. Pint/vue-tsc/build clean.
- Manual UX walk via Playwright: synced repo with 6 mixed open/closed issues renders the Issues tab with sync status badge, "Run sync" button, per-row state badges + author + relative timestamps + GitHub external links. Failed-sync repo shows the failure copy + Run sync CTA in the empty state. Tab navigation between Overview and Issues works.
- Adjustment from spec draft: the Repository show page didn't have tabs to begin with, so Overview/Issues was added as a new tab nav scaffold. Spec 016's PRs tab can drop in alongside.

## Decisions (locked 2026-04-29)
- **Separate `github_issues` table** (per roadmap §8.4). Cleaner than a unified work-items table; spec 016 can join in a query layer if needed.
- **Extend `SyncGitHubRepositoryJob` to chain the issues job** after a successful metadata sync. Independent statuses on the `repositories` row so failures stay isolated.
- **Single-page fetch (`per_page=100&state=all&since=`).** Same shape as spec 014's repo list. Pagination is a future-spec concern.
- **Upsert keyed by `(repository_id, github_id)`.** Compound unique index prevents dupes; second sync mutates in place.
- **Per-Repository Issues tab is the only UI surface.** The unified `/work-items` page lives in spec 016 once PRs join.
- **Manual "Run sync" + auto-chain.** Two paths to populate the table: spec 014's import flow, and the explicit button.
- **Mirror spec 014's failure plumbing.** 401 → blank token + `expires_at = now()`; a failed sync does NOT bump `issues_synced_at`. The Settings indicator and any future "Last issues sync" UI must mean "last successful sync."

## Open questions / blockers

- **GitHub `/issues` endpoint pagination.** If a popular repo has 200+ open issues we'll only see 100. Acceptable for MVP per the locked single-page decision. Flag at manual-walk time if a seeded repo trips it.
- **Issue body privacy / PII.** We persist a 280-char preview. If a real user notices issue bodies leaking in a way that surprises them, swap to a "fetch on demand" flow in a follow-up spec rather than persisting.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue.** Pre-existing; same disclaimer as 013/014.
