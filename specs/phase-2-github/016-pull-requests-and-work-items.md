---
spec: pull-requests-and-work-items
phase: 2-github
status: in-progress
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/42
branch: spec/016-pull-requests-and-work-items
---

# 016 — Pull requests sync + unified Work Items page

## Goal
Mirror GitHub pull requests for every imported `Repository` into a local `github_pull_requests` table (parallel structure to spec 015's `github_issues`) and ship the unified `/work-items` page that joins issues + PRs into a single engineering work queue. After this spec Phase 2 is complete: a Nexus user can connect GitHub (013), import repos (014), watch issues sync (015), and now watch pull requests sync alongside while browsing the unified queue across all of their repositories.

This spec also adds a per-Repository "Pull Requests" tab (sibling of spec 015's Issues tab) so the Repository show page covers both sides of the issue-and-PR pair. Webhooks for near-real-time updates remain phase 3; review-detail sync (`mergeable`, `review_status`, `checks_status`) remains phase 9 polish.

Roadmap reference: §8.4 GitHub Issues & Pull Requests (`github_pull_requests` schema + UX requirements + `/work-items` page), §27 (`app/Domain/GitHub/Actions/SyncRepositoryPullRequestsAction.php`, `app/Domain/GitHub/Actions/NormalizeGitHubPullRequestAction.php`).

## Scope
**In scope:**

- **`github_pull_requests` table** with the §8.4 field set, MVP-trimmed:
    - `id` (PK), `repository_id` (FK → `repositories`, cascade delete), `github_id` (bigint), `number` (int)
    - `title` (string), `body_preview` (text, truncated at 280 chars — same firm-cap policy as issues)
    - `state` (enum: `open`/`closed`/`merged`)
    - `author_login` (string, nullable)
    - `base_branch` (string), `head_branch` (string)
    - `draft` (bool), `merged` (bool)
    - `additions` (uint), `deletions` (uint), `changed_files` (uint)
    - `comments_count` (uint), `review_comments_count` (uint)
    - `created_at_github`, `updated_at_github`, `closed_at_github`, `merged_at` (datetime, all nullable except created)
    - `synced_at` (datetime), `created_at` / `updated_at`
    - **Compound unique index** `(repository_id, github_id)` for upsert-on-replay.
    - **Skipped from §8.4 (phase 9)**: `mergeable`, `review_status`, `checks_status`, `review_comments_count` (the derived multi-call ones — `mergeable`/`review_status`/`checks_status` need extra REST calls per PR).

- **`App\Models\GithubPullRequest`** — Eloquent model.
    - Casts: `draft` + `merged` as `bool`; `state` as `App\Enums\GithubPullRequestState`; the GitHub timestamp columns as `datetime`; integer counts as `integer`.
    - `repository()` belongs-to.
    - `$fillable` shaped to the column list.

- **`App\Enums\GithubPullRequestState`** — `Open` / `Closed` / `Merged`. Derived from GitHub's `state` + `merged` boolean by the normalizer (spec 015's enum had only Open/Closed; PRs need the third).

- **Repositories table extension.** New nullable `prs_sync_status` enum column + `prs_synced_at` datetime, mirroring spec 015's issues columns. Lifecycle `pending → syncing → synced/failed`.

- **`App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction`** — pure mapper from a single GitHub `/pulls` payload to the array we persist. Trims body to 280 chars (no ellipsis suffix, same as issues), pulls `base.ref` / `head.ref` for branch names, derives `state` from `state + merged_at`. Does NOT need to drop anything (the `/pulls` endpoint only returns PRs).

- **`App\Domain\GitHub\Actions\SyncRepositoryPullRequestsAction`** — orchestrates one repository's PRs sync.
    - Constructor injects `NormalizeGitHubPullRequestAction`.
    - Takes a `Repository` model + an injected `GitHubClient`.
    - Calls `GET /repos/{full_name}/pulls?state=all&per_page=100&sort=updated&direction=desc`. (Note: `/pulls` is separate from `/issues` — cleaner than re-using `/issues` and filtering for `pull_request`.)
    - Same "ignore `since` when local mirror is empty" guard from spec 015's M4 fix. Actually, GitHub's `/pulls` does **not** support `?since=` — so for now we always full-fetch. Pagination is still the locked single-page cap. Document the difference from issues sync inline.
    - Upsert on `(repository_id, github_id)`.
    - Returns `int` count of PRs persisted.

- **`App\Domain\GitHub\Jobs\SyncRepositoryPullRequestsJob`** — `ShouldQueue` job, parallel structure to spec 015's `SyncRepositoryIssuesJob`.
    - Lifecycle `pending → syncing → synced/failed` on the new `prs_sync_status` column.
    - On 401 reuse the same `expireConnection` plumbing (clear `access_token`, set `expires_at = now()`).
    - `prs_synced_at` preserved on failure.
    - `tries = 1` (same lock decision).

- **Hook into `SyncGitHubRepositoryJob`.** After the `SyncRepositoryIssuesJob::dispatch()` we already added in spec 015, also dispatch `SyncRepositoryPullRequestsJob`. Both dispatches sit outside the metadata try/catch (spec 015's M2 fix) and run independently — a failure in one does not roll back the other.

- **`App\Http\Controllers\RepositoryPullRequestsSyncController`** — single-action controller for the manual "Run sync" button on the Repository PRs tab.
    - `POST /repositories/{repository}/pulls/sync` (route key constraint matches spec 011's two-segment slug).
    - `ProjectPolicy::update` gate.

- **`App\Domain\GitHub\Queries\PullRequestsForRepositoryQuery`** — parallel to spec 015's `IssuesForRepositoryQuery`. Returns the most-recent 50 by `updated_at_github desc`, shaped for the Repository show page's PRs tab.

- **`App\Domain\GitHub\Queries\WorkItemsForUserQuery`** — the new query backing the `/work-items` page. Joins both `github_issues` + `github_pull_requests` filtered by repos the user can see (phase-1: their own projects' repos), tagged with a `kind: 'issue' | 'pull_request'` discriminator so the page can render either. Returns the most-recent 100 by `updated_at_github desc`. Filterable by `state`, `repository_id`, and `kind` (driven by query string from the page).

- **`App\Http\Controllers\WorkItemController`** — single-action controller for `GET /work-items`.
    - Validates query string: `kind` ∈ `issues|pulls|all` (default `all`), `state` ∈ `open|closed|merged|all` (default `open`), `repository_id` (nullable int), `q` (nullable string for title/number search).
    - Calls the query class with those filters.
    - Returns the page payload + the list of repositories the user can see (for the repo dropdown).

- **Repository show page — Pull Requests tab.** Add a third tab on `Pages/Repositories/Show.vue` (sibling of Overview / Issues from spec 015). Sync status strip + "Run sync" button (POSTs to the new route) + per-row PR list:
    - Each row: `#{number}` + title + state badge (`Open` cyan / `Closed` muted / `Merged` purple) + `author_login` + `base_branch ← head_branch` + relative `updated_at_github` + a small external link.
    - Empty state: same pending/failed/empty branching as the Issues tab.

- **`Pages/WorkItems/Index.vue`** — new top-level page. Header with three filter widgets: **kind tabs** (Issues / Pull Requests / All), **state filter** (open/closed/merged/all), **repository dropdown** (all the user's repos), **search input** (title or `#number`). List rows mix issues and PRs visually distinct (icon + state-tone badge). Cap at 100 rows; "Load more" lands later.

- **Routes.** Add to `routes/web.php` under the existing `auth + verified` group:
    ```php
    Route::post('/repositories/{repository}/pulls/sync', RepositoryPullRequestsSyncController::class)
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.pulls.sync');

    Route::get('/work-items', WorkItemController::class)
        ->name('work-items.index');
    ```

- **Sidebar navigation.** Promote the existing `Issues & PRs (Phase 2)` placeholder in `AppLayout`'s primary nav to a real `<Link>` pointing to `/work-items`. (The placeholder is a `<generic>` block today — drop the Phase 2 chip on it and route it.)

- **`GithubPullRequestFactory`** for tests + seeded data parallel to `GithubIssueFactory`.

- **Tests** (PHP feature tests):
    - `NormalizeGitHubPullRequestActionTest` — happy path, missing optional fields, body trim at 280 chars, derived state matrix (`state=open + merged=false → open`, `state=closed + merged=false → closed`, `state=closed + merged=true → merged`, `state=open + merged=true` should not happen but defaults to `merged` for safety).
    - `SyncRepositoryPullRequestsActionTest` — `Http::fake()` on `/repos/{full}/pulls`; happy path inserts; second sync upserts; 401 surfaces `GitHubApiException::isUnauthorized() === true`.
    - `SyncRepositoryPullRequestsJobTest` — lifecycle (`pending → syncing → synced` and `→ failed`); 401 expires the connection; `prs_synced_at` preserved on failure.
    - `SyncGitHubRepositoryJobTest` — extension test: on the happy path BOTH `SyncRepositoryIssuesJob` AND `SyncRepositoryPullRequestsJob` are dispatched (assert via `Queue::fake()`).
    - `RepositoryPullRequestsSyncControllerTest` — manual sync dispatches the job for the project owner; non-owner is 403; 404 for unknown repo.
    - `RepositoryControllerTest` — extension: PRs tab payload shape; the "Run sync" CTA renders only for users with update permission.
    - `WorkItemControllerTest` — index renders the page for a verified user; query string filters narrow correctly; user only sees rows from their own projects' repos.

- **Update phase trackers** in the same PR (and the post-merge bookkeeping flips 016 to 🟢, completing Phase 2 → 4/4 🟢).

**Out of scope:**

- **Mergeable / review-status / checks-status sync.** All require extra GitHub REST calls per PR (`/pulls/{n}/reviews`, `/pulls/{n}/commits`, etc.). The roadmap §8.4 lists derived statuses (`needs_review`, `approved`, `changes_requested`, `checks_failed`, `merge_conflict`, `ready_to_merge`, `stale`) — those all live on those extra endpoints. Phase 9 polish.
- **Webhooks** (phase 3).
- **Multi-page fetch** for either issues or PRs.
- **Label / assignee / milestone filters** on `/work-items` — only state, repository, kind, and free-text search ship.
- **Priority calculation + `PriorityBadge`** — §8.4 has a multi-input priority logic (labels, repo importance, age, comments, manual override). That's a phase 9 surface.
- **Avatar URLs on rows** — spec 014 deliberately did not import GitHub avatars; same here.
- **Cross-team / multi-tenant scoping** — phase-1 ties everything to a single owner user.
- **Bulk re-sync** ("Sync all repos"). Phase 9.
- **Sort dropdown on `/work-items`.** Single locked sort: `updated_at_github desc`.
- **Issue/PR detail pages.** Both link out to GitHub directly. A later spec adds the local detail view if it earns its keep.

## Plan

1. **Migration + model + enum.** `php artisan make:migration create_github_pull_requests_table` → spec the schema + compound unique. Make the `GithubPullRequest` model with casts/relations. Add `App\Enums\GithubPullRequestState`. Add `prs_sync_status` + `prs_synced_at` columns to `repositories`.
2. **`NormalizeGitHubPullRequestAction`.** Pure mapper. Branch-derive state from `state` + `merged_at`. Reuse the body-trim + label-shape patterns from spec 015 where applicable.
3. **`SyncRepositoryPullRequestsAction`.** Wire to `GitHubClient::listPullRequests($fullName)`. Upsert.
4. **`GitHubClient::listPullRequests`** — new method. Same pinned headers + per-page cap as `listIssues`/`listRepositories`.
5. **`SyncRepositoryPullRequestsJob`.** Wraps the action; mirror spec 015's job structure exactly (lifecycle, 401, preserve-on-failure timestamp).
6. **Hook into `SyncGitHubRepositoryJob`.** Dispatch the PRs job alongside the issues job. Both dispatches outside the metadata try/catch.
7. **`PullRequestsForRepositoryQuery` + `WorkItemsForUserQuery`.** Two new query classes.
8. **Controllers + routes.** `RepositoryPullRequestsSyncController` (single-action) + `WorkItemController`.
9. **Repository show page — PRs tab.** Add the third tab + per-row UI matching the Issues tab.
10. **`Pages/WorkItems/Index.vue`.** New top-level page with kind tabs + state/repo filter dropdowns + search. Ship a focused MVP — table-style list with state badges + GitHub external links.
11. **Sidebar navigation.** Promote `Issues & PRs` placeholder to a real `<Link>`.
12. **Tests.** Seven test files per the list. All `Http::fake()` + `Queue::fake()`.
13. **Manual UX walk** — Playwright at 1440×900: import a stubbed repo (or seed), watch all three sync statuses (repo / issues / PRs) flip; visit Repository show → all three tabs; visit `/work-items` and exercise the filters.
14. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
15. **Self-review** with `superpowers:code-reviewer`. Address material findings.

## Acceptance criteria
- [ ] Migration creates `github_pull_requests` with the field set above and `(repository_id, github_id)` unique index.
- [ ] Migration extends `repositories` with `prs_sync_status` + `prs_synced_at`.
- [ ] `NormalizeGitHubPullRequestAction` correctly derives `state` (open / closed / merged) from GitHub's `state` + `merged` fields, trims body to 280 chars, handles missing optional fields gracefully.
- [ ] `SyncRepositoryPullRequestsAction` upserts on `(repository_id, github_id)`. A second sync against the same payload mutates one row in place.
- [ ] `SyncRepositoryPullRequestsAction` raises `GitHubApiException::isUnauthorized() === true` on 401; the job's catch path expires the connection (mirroring spec 014/015).
- [ ] `SyncGitHubRepositoryJob` dispatches BOTH `SyncRepositoryIssuesJob` AND `SyncRepositoryPullRequestsJob` after a successful repo metadata sync (verified via `Queue::fake()::assertPushed`).
- [ ] `RepositoryPullRequestsSyncController` 200s for the project owner and 403s for non-owners.
- [ ] Repository show page has a third "Pull Requests" tab; PRs tab renders the synced rows with state badges, branch names, author handles, relative GitHub timestamps, and external links; the "Run sync" button POSTs to the new route.
- [ ] `prs_synced_at` is preserved across a failed sync.
- [ ] `/work-items` page renders for a verified user; filters (kind, state, repository, search) narrow correctly; user only sees items from repos under their own projects.
- [ ] Sidebar `Issues & PRs` link routes to `/work-items` and the "Phase 2" chip is gone.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes.
- [ ] No real GitHub credentials in CI; `Http::fake()` + `Queue::fake()` only.
- [ ] Pint + vue-tsc + build clean. CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`.

## Files touched
- `database/migrations/<ts>_create_github_pull_requests_table.php` — new.
- `database/migrations/<ts>_add_prs_sync_columns_to_repositories_table.php` — new.
- `app/Models/GithubPullRequest.php` — new.
- `app/Enums/GithubPullRequestState.php` — new.
- `app/Domain/GitHub/Actions/NormalizeGitHubPullRequestAction.php` — new.
- `app/Domain/GitHub/Actions/SyncRepositoryPullRequestsAction.php` — new.
- `app/Domain/GitHub/Jobs/SyncRepositoryPullRequestsJob.php` — new.
- `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php` — extended to dispatch the PRs job alongside the issues job.
- `app/Domain/GitHub/Services/GitHubClient.php` — add `listPullRequests(string $fullName, int $perPage = 100): array`.
- `app/Domain/GitHub/Queries/PullRequestsForRepositoryQuery.php` — new.
- `app/Domain/GitHub/Queries/WorkItemsForUserQuery.php` — new.
- `app/Http/Controllers/RepositoryPullRequestsSyncController.php` — new.
- `app/Http/Controllers/RepositoryController.php` — extend `show()` to include the PRs payload + sync status.
- `app/Http/Controllers/WorkItemController.php` — new (single-action, GET).
- `app/Models/Repository.php` — add `pullRequests()` relation, fillable + casts for the new columns.
- `routes/web.php` — `repositories.pulls.sync` POST + `work-items.index` GET.
- `resources/js/Pages/Repositories/Show.vue` — add the PRs tab.
- `resources/js/Pages/WorkItems/Index.vue` — new.
- `resources/js/Components/Layouts/AppLayout.vue` (or wherever the primary nav lives) — promote `Issues & PRs` to a real `<Link>` pointing to `/work-items`.
- `database/factories/GithubPullRequestFactory.php` — new.
- `tests/Feature/GitHub/NormalizeGitHubPullRequestActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryPullRequestsActionTest.php` — new.
- `tests/Feature/GitHub/SyncRepositoryPullRequestsJobTest.php` — new.
- `tests/Feature/GitHub/SyncGitHubRepositoryJobTest.php` — extension for both-jobs-dispatched.
- `tests/Feature/GitHub/RepositoryPullRequestsSyncControllerTest.php` — new.
- `tests/Feature/Repositories/RepositoryControllerTest.php` — extension for PRs tab payload.
- `tests/Feature/GitHub/WorkItemControllerTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-29
- Spec drafted; scope confirmed (8 decisions locked: separate `github_pull_requests` table, three-state enum derived from `state + merged`, chain a third dispatch after spec 015's, single-page `/pulls` fetch with no `since=` param, three-tab Work Items page with kind/state/repo/search filters, single locked sort by `updated_at_github desc`, skip mergeable/review-status/checks-status, sidebar `Issues & PRs` promoted to real link).
- Opened issue [#42](https://github.com/Copxer/nexus/issues/42) and branch `spec/016-pull-requests-and-work-items` off `main`.
- Implementation complete: migrations + `GithubPullRequest` model + `GithubPullRequestState` enum + factory, `NormalizeGitHubPullRequestAction`, `GitHubClient::listPullRequests`, `SyncRepositoryPullRequestsAction`, `SyncRepositoryPullRequestsJob`, chain dispatch from `SyncGitHubRepositoryJob` (refactored to a small helper now that two child syncs fan out), `RepositoryPullRequestsSyncController` + route, `PullRequestsForRepositoryQuery`, Repository show page PRs tab, `WorkItemsForUserQuery`, `WorkItemController` + route, `Pages/WorkItems/Index.vue`, sidebar `Issues & PRs` promoted to a real link. 7 test files (5 new + 2 extensions): 171 tests / 773 assertions green. Pint/vue-tsc/build clean.
- Manual UX walk via Playwright: synced repo with 3 PRs (open / closed / merged) renders the new PRs tab cleanly with branch names + state badges + Run sync button. `/work-items` page renders the unified queue, kind filter narrows to PRs only, state=merged filter shows the 3 merged PRs across both repos. Sidebar `Issues & PRs` link is wired and the "Phase 2" chip is gone.

## Decisions (locked 2026-04-29)
- **Separate `github_pull_requests` table** parallel to `github_issues`. Cleaner than a unified work-items table; the cross-cutting `WorkItemsForUserQuery` joins them at the read layer.
- **Three-state enum: `open` / `closed` / `merged`.** Derived from GitHub's `state` + `merged` boolean by the normalizer. Skipping the richer derived states (`draft`, `needs_review`, etc.) — those need review/check sync which is phase 9.
- **Chain a third dispatch.** `SyncGitHubRepositoryJob` now fires `SyncRepositoryIssuesJob` AND `SyncRepositoryPullRequestsJob` after a successful metadata sync. Independent statuses on the repo row.
- **`/pulls` endpoint, no `since=` param.** GitHub doesn't support `since=` on `/pulls`; we always full-fetch (capped at 100). Single-page cap is the same locked decision as issues.
- **Three-tab Work Items page.** Issues / Pull Requests / All. Filters: kind (matches the tabs), state, repository dropdown, free-text title/number search. Single locked sort (most-recent-by-`updated_at_github`).
- **Skip `mergeable` / `review_status` / `checks_status`.** Each needs an extra REST call per PR. Worth its own phase 9 spec when we add review sync.
- **Sidebar `Issues & PRs` link.** Promote the existing placeholder to a real `<Link>` pointing to `/work-items`. Drop the "Phase 2" chip.
- **Mirror spec 015's failure plumbing.** 401 → blank token + `expires_at = now()`; failed sync does NOT bump `prs_synced_at`. Same firm 280-char body cap.

## Open questions / blockers

- **Performance on `WorkItemsForUserQuery`.** Joining issues + PRs at the read layer with limit 100 is fine for phase-1 single-user volume. If a real user has 50+ active repos and each has 100s of items, the 100-row cap may feel small. Pagination is a phase 9 follow-up.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue.** Pre-existing; same disclaimer.
