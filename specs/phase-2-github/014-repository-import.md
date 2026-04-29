---
spec: repository-import
phase: 2-github
status: in-progress
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/36
branch: spec/014-repository-import
---

# 014 — Repository import (list, pick, sync metadata)

## Goal
Turn the GitHub connection from spec 013 into something useful: a logged-in Nexus user can list the repositories their connected GitHub account can see, pick which to import into a Nexus project, and watch the existing `repositories` table fill with real metadata (description, default branch, language, stars/forks counts, last push, sync status). After this spec, the `Repositories` index, the per-project Repositories tab, the Top Repositories widget, and the Hosts KPI proxy on Overview all start showing live GitHub numbers automatically — that's the payoff of spec 012's Domain query layer.

This spec adds the **import** + **first sync** flows. Per-repo refresh ("Run sync" button) and bulk re-sync ride with spec 015/016 (alongside issues + PRs sync). Webhooks remain phase 3.

Roadmap reference: §8.3 Repositories (`SyncGitHubRepositoryJob` is the canonical filename), §27 (`app/Domain/GitHub/Services/GitHubClient.php`, `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php`).

## Scope
**In scope:**

- **`App\Domain\GitHub\Services\GitHubClient`** — a thin authenticated wrapper over GitHub's REST API. Gets a connected `User` (or `GithubConnection`) and exposes:
    - `listRepositories(int $perPage = 100): array` — calls `GET /user/repos?type=owner,collaborator&sort=pushed&per_page=...&page=...` (multi-page if needed) and returns the parsed list. Used by the import picker.
    - `fetchRepository(string $fullName): array` — calls `GET /repos/{owner}/{name}` for the metadata sync.
    - HTTP-client-injectable so tests use `Http::fake()`. All calls send `User-Agent: Nexus-Control-Center` (consistent with the `GitHubOAuthService::defaultHeaders()` helper from spec 013) and `Accept: application/vnd.github+json` + `X-GitHub-Api-Version: 2022-11-28` — pinned now since we lean on specific response shapes.
    - Throws a typed `GitHubApiException` on transport failures + GitHub error payloads. Token-expired (401) bubbles up so the caller can mark the connection expired in spec 013's UI.
    - **No retry / backoff yet.** Phase 9 polish layer.

- **`App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob`** — `ShouldQueue` job that takes a `Repository` model id, calls `GitHubClient::fetchRepository($repo->full_name)`, updates the local row in place (description, default_branch, language, visibility, stars_count, forks_count, open_issues_count, last_pushed_at, last_synced_at, sync_status). Sets `sync_status = 'syncing'` while running, `'synced'` on success, `'failed'` on caught exception. Idempotent (replays land the same row in the same final state).

- **`App\Domain\GitHub\Actions\ImportRepositoryAction`** — given a Nexus user, a target Project, and a GitHub `full_name`, creates the local `Repository` row in `pending` state and dispatches `SyncGitHubRepositoryJob`. The action is idempotent — re-importing an already-linked repo refreshes its metadata via a fresh sync without duplicating the row. Reuses spec 011's existing `LinkRepositoryToProjectAction` parser for `full_name` validation.

- **`App\Http\Controllers\GithubRepositoryImportController`** — two actions:
    - `index(Project $project)` — calls `GitHubClient::listRepositories()` for the current user's connection, returns the list as an Inertia response so the picker can render. The page is project-scoped (`/projects/{slug}/repositories/import`) so the import flow always has a target project.
    - `store(Project $project, Request $request)` — accepts a `full_name` (validated via the same regex from spec 011), calls `ImportRepositoryAction`, redirects to the project's Repositories tab with a flash.
    - Policy-gated via `ProjectPolicy::update` — only the project owner can import repos into their project.

- **Routes.** `routes/web.php` adds:
    ```php
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('/projects/{project}/repositories/import', [GithubRepositoryImportController::class, 'index'])
            ->name('projects.repositories.import.index');
        Route::post('/projects/{project}/repositories/import', [GithubRepositoryImportController::class, 'store'])
            ->name('projects.repositories.import.store');
    });
    ```

- **`Pages/Repositories/Import.vue`** — the picker. Pre-loaded list of repos (from the controller), plus a search input that filters client-side. Each row: avatar/icon + `owner/name` + description (truncated) + language + stars + last-push relative time + an "Import" button. Already-imported repos show as "Already linked" (disabled). Bulk-import is out of scope; one click per row.

- **Update Project Show — Repositories tab.** Add a small "Import from GitHub" CTA next to the existing manual link form, only visible when the user has a GitHub connection. The button links to `/projects/{slug}/repositories/import`. The manual link form from spec 011 stays as the fallback for users without a connection.

- **Settings page sync indicator.** When a sync is in flight or failed, the Integrations card surfaces a small "Last sync N min ago" + retry CTA on the GitHub row. The status text reads from a new computed prop (`recent_repositories: { count, last_synced_at }`) so the Settings controller can show high-level health without dragging in the full repo list.

- **`SyncGitHubRepositoryJob` queue dispatch.** Default queue. We rely on Horizon (spec 009 wired it). The action dispatches synchronously in tests via `Bus::fake()` + `Queue::assertPushed()` — no real worker needed in CI.

- **Tests** (PHP feature tests):
    - `GitHubClientTest` — `listRepositories` paginates correctly via `Http::fake()`; `fetchRepository` returns the parsed repo; 401 surfaces a typed exception.
    - `SyncGitHubRepositoryJobTest` — happy path updates the Repository row; failure flips `sync_status` to `failed` and re-throws; running on a row with `sync_status = pending` flips through `syncing → synced`.
    - `ImportRepositoryActionTest` — first-time import creates row + dispatches the job; idempotent on re-import (same row, fresh job).
    - `GithubRepositoryImportControllerTest` — index renders with the picker payload; store dispatches the job; non-owner is 403.

- **Update phase trackers** as part of the same PR (and the post-merge bookkeeping flips 014 to 🟢, matches the established workflow).

**Out of scope:**

- Issues sync (spec 015) and PRs sync + Work Items page (spec 016).
- Webhook ingestion + near-real-time updates (phase 3).
- Bulk import (multi-select + batch dispatch). Add later if the one-at-a-time flow feels slow.
- Per-repo "Run sync" button on the Repository show page. That ships with spec 015 once the sync surface area grows.
- Token-refresh handling. Spec 013 explicitly defers it; if the GitHub call returns 401, we mark the connection expired and surface a Reconnect CTA. The job records `failed` so the user can re-import after re-authenticating.
- Sync status broadcasting via Reverb. Phase 9.
- A `GitHubAvatar` component. The picker uses GitHub's `avatar_url` directly via `<img>`; if the image fails it falls back to the existing `GitBranch` lucide icon.
- Rate-limit handling beyond surfacing the GitHub error message. Real backoff + retry comes with phase 9.

## Plan

1. **`GitHubClient` service.** Constructor takes a `GithubConnection` (so tests can pass a fake one). Reads the (decrypted) token from the model and uses Laravel's `Http::withToken()`. Pin Accept + version headers. Multi-page `listRepositories` via Link-header-driven pagination or a fixed `?per_page=100` cap if a single page suffices for MVP (we'll cap at 100 for now — if a user has 100+ repos they probably want a search).
2. **`GitHubApiException`.** Typed exception with helpers (`isUnauthorized()`, `wasRateLimited()`) used by callers to map error → UI state.
3. **`SyncGitHubRepositoryJob`.** `ShouldQueue` + `Queueable`. `handle(GitHubClient $client)` updates the local row.
4. **`ImportRepositoryAction`.** `execute(User $user, Project $project, string $fullName): Repository`. Reuses spec 011's parser, calls `LinkRepositoryToProjectAction::execute($project, $fullName)`, then dispatches the sync job.
5. **`GithubRepositoryImportController` + routes.** Index loads the connection-scoped repo list; store validates + dispatches.
6. **`Pages/Repositories/Import.vue`.** Loads the list, client-side filter, per-row Import button, redirect-on-success.
7. **`Pages/Projects/Show.vue` Repositories tab tweak.** Add the "Import from GitHub" CTA; show only when `auth().user.has_github_connection` (a new prop on the page).
8. **Settings card extension.** Add `last_synced_at` + `recently_imported_count` to the Integrations card metadata strip. Tiny visual touch.
9. **Tests.** Service, job, action, controller — all `Http::fake()` + `Bus::fake()`.
10. **Manual UX walk** — stub a connection in tinker, mock GitHub's `/user/repos` response via a localdebug script (or just stub the controller's `listRepositories` for the manual walk), exercise the picker, click Import on one row, watch the Repositories tab update.
11. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
12. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `GitHubClient::listRepositories()` returns parsed GitHub repo objects via authenticated GET (mocked via `Http::fake()` in tests).
- [ ] `GitHubClient::fetchRepository($fullName)` returns one repo's metadata. 401 throws `GitHubApiException::isUnauthorized() === true`.
- [ ] `SyncGitHubRepositoryJob`: handles a Repository id; updates `description, default_branch, language, visibility, stars_count, forks_count, open_issues_count, last_pushed_at, last_synced_at, sync_status` on success; marks `failed` on caught exception.
- [ ] `ImportRepositoryAction` creates the local row (or refreshes an existing one) and dispatches the sync job. Idempotent.
- [ ] `GithubRepositoryImportController`: index renders `Pages/Repositories/Import.vue` with the user's GitHub repo list; store validates + dispatches; non-owner is 403.
- [ ] `Pages/Repositories/Import.vue` shows a searchable list with per-row Import buttons; already-imported repos are disabled with "Already linked".
- [ ] `Pages/Projects/Show.vue` Repositories tab shows an "Import from GitHub" CTA when the user has a connection (alongside the existing manual link form).
- [ ] After importing, the `repositories` row's `sync_status` cycles `pending → syncing → synced` (or `failed`) via the job.
- [ ] Overview Top Repositories widget reflects newly-synced star counts without code changes (spec 012's wiring already reads from the `repositories` table).
- [ ] No real GitHub credentials in CI; `Http::fake()` + `Bus::fake()` only.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes.
- [ ] Pint + vue-tsc + build clean. CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`.

## Files touched
- `app/Domain/GitHub/Services/GitHubClient.php` — new.
- `app/Domain/GitHub/Exceptions/GitHubApiException.php` — new.
- `app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php` — new.
- `app/Domain/GitHub/Actions/ImportRepositoryAction.php` — new.
- `app/Http/Controllers/GithubRepositoryImportController.php` — new.
- `routes/web.php` — add `projects.repositories.import.{index,store}` routes.
- `resources/js/Pages/Repositories/Import.vue` — new.
- `resources/js/Pages/Projects/Show.vue` — add "Import from GitHub" CTA in the Repositories tab.
- `resources/js/Pages/Settings/Index.vue` — show `last_synced_at` + `recently_imported_count` on the Integrations card (when connected).
- `app/Http/Controllers/SettingsController.php` — extend the serialized GitHub shape.
- `tests/Feature/GitHub/GitHubClientTest.php` — new.
- `tests/Feature/GitHub/SyncGitHubRepositoryJobTest.php` — new.
- `tests/Feature/GitHub/ImportRepositoryActionTest.php` — new.
- `tests/Feature/GitHub/GithubRepositoryImportControllerTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-29
- Spec drafted; scope confirmed (7 decisions locked: GitHubClient takes GithubConnection, single-page pagination cap, per-row Import, full sync-status lifecycle, Queue::fake + direct handler test, single typed exception, on-401 clear token + flag expired).
- Opened issue [#36](https://github.com/Copxer/nexus/issues/36) and branch `spec/014-repository-import` off `main`.

## Decisions (locked 2026-04-29)
- **GitHubClient takes a `GithubConnection`.** Explicit, easy to mock, fail-fast.
- **Single-page `?per_page=100`.** 100 repos is plenty for MVP. Multi-page follow-up if it earns its keep.
- **Per-row Import buttons.** No bulk select. Batch import becomes a phase-9 polish.
- **Full sync-status lifecycle:** `pending → syncing → synced/failed`. Free progress signal.
- **`Queue::fake()` for dispatch assertions** + one direct `handle()` test for the job's sync logic.
- **Single `GitHubApiException`** with status helpers. Subclass only if caller code starts switching on type.
- **On 401, clear `access_token` + flag connection expired.** Explicit Reconnect path; no silent failures on subsequent API calls.

## Open questions / blockers

- **`/user/repos` includes private repos when scope `repo` is granted.** Spec 013's default scopes are `read:user` + `repo`. Confirm at manual-walk time that the picker shows both public + private repos correctly.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue** still present locally. Same disclaimer.
