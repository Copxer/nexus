---
spec: repositories
phase: 1-projects
status: in-progress
owner: yoany
created: 2026-04-28
updated: 2026-04-28
issue: https://github.com/Copxer/nexus/issues/27
branch: spec/011-repositories
---

# 011 — Repositories (model + manual link + index/show + sidebar/palette activation)

## Goal
Add Repositories as a first-class entity that belongs to a Project. Phase 1's middle spec: a `repositories` table, an Eloquent model, a factory, a policy, a CRUD-ish controller (no edit/update — repository metadata is normally set by GitHub sync, which lands in phase 2), three Vue pages (`/repositories` index, `/repositories/{slug}` show, plus a manual link form on the Project detail's Repositories tab), a seeder, and the sidebar/palette `Repositories` nav promoted from "Soon" to a real link. After this spec, a project owner can manually link a GitHub repo by URL or `owner/name`, see it on the project's Repositories tab, list every repository across projects on `/repositories`, and click into a repo's detail page.

This spec does **not** wire the Overview Top Repositories widget to the new table — that's spec 012. It does **not** auto-sync repository metadata from GitHub — that's the central deliverable of phase 2.

Roadmap reference: §8.3 Repositories (the field list, UX requirements, manual link).
Visual target: existing dashboard chrome + the same glass-card / token vocabulary used everywhere in Phase 0/1.

## Scope
**In scope:**

- **Database.** Migration creates `repositories` table with the §8.3 fields:
    - `id` (auto-increment)
    - `project_id` (foreign key to projects, cascadeOnDelete + indexed)
    - `provider` (string, default `github` — leaves the door open for GitLab/Bitbucket later)
    - `provider_id` (string, nullable — the GitHub repo numeric id, populated by phase 2 sync)
    - `owner` (string — the GitHub `owner` slug, e.g. `nexus-org`)
    - `name` (string — the repo `name`, e.g. `nexus-web`)
    - `full_name` (string, unique — `owner/name`, used as the route key)
    - `html_url` (string)
    - `default_branch` (string, default `main`)
    - `visibility` (string, default `public` — `public|private|internal`)
    - `language` (string, nullable)
    - `description` (text, nullable)
    - `stars_count` / `forks_count` / `open_issues_count` / `open_prs_count` (unsigned integers, default 0)
    - `last_pushed_at` (timestamp, nullable)
    - `last_synced_at` (timestamp, nullable)
    - `sync_status` (string, default `pending` — `pending|syncing|synced|failed`)
    - timestamps

- **Enum class.** `App\Enums\RepositorySyncStatus` (string-backed: `pending|syncing|synced|failed`) with a `badgeTone()` helper for the dashboard. Skip a `Provider` enum for now — phase 1 only has `github`; we'll formalize when a second provider arrives.

- **Model.** `App\Models\Repository`:
    - `$fillable` for the columns above except `id`, `project_id` (server-set on link).
    - `belongsTo(Project::class)`.
    - `getRouteKeyName()` returns `'full_name'` so URLs read `/repositories/owner/name` — Laravel's default route binding handles slashes in route keys via the `whereAlphaNumeric` / explicit constraint pattern. We'll add an explicit `where('repository', '[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+')` constraint on the route.
    - Cast for `sync_status` (enum) and the count columns (`integer`).
    - `Project::repositories()` hasMany relationship gets added on the existing model.

- **Factory.** `Database\Factories\RepositoryFactory` — sensible randoms for `owner`, `name`, `language` (`PHP|TypeScript|Go|Rust|Python|Vue`), star/fork/issue counts, `last_pushed_at` within the last week, `sync_status` weighted toward `synced`.

- **Policy.** `App\Policies\RepositoryPolicy`:
    - `viewAny`: any authenticated, verified user.
    - `view`: any authenticated, verified user.
    - `create`: verified user who can `update` the parent project (i.e. project owner can link repos to their project).
    - `delete`: verified user who can `update` the parent project.
    - No `update` action — repository metadata is GitHub-sourced.
    - Register with `Gate::policy(...)` in `AppServiceProvider::boot()`.

- **Controller.** `App\Http\Controllers\RepositoryController`:
    - `index` — paginated list of all repositories across projects (eager-loads `project:id,slug,name,color,icon`); transforms each row to a flat shape for the page.
    - `show` — repository detail (project link, default-branch chip, language pill, sync status badge, the four count metrics, a `last pushed` line). No tabs this spec; pure detail view.
    - `store` — accepts a parent `project_id` and either a `repository_url` (`https://github.com/owner/name`) or a `full_name` (`owner/name`); the `LinkRepositoryToProjectAction` parses + validates; if the `full_name` already exists scoped to a different project we 409 with a friendly error; otherwise persist with `sync_status = 'pending'` and `last_synced_at = null`. Redirects back to `projects.show` with the Repositories tab pre-selected.
    - `destroy` — guarded by policy; redirects to the project's Repositories tab.

- **Action class.** `App\Domain\Repositories\Actions\LinkRepositoryToProjectAction` — validates the input, parses the URL/full-name, normalizes case (GitHub treats `Owner/Name` as case-preserving but case-insensitive on uniqueness), prevents duplicate `full_name`s scoped to the same project, and returns the persisted Repository. Following the roadmap's `app/Domain/...` pattern is a deliberate first step toward the layered architecture from §27.

- **Form Request.** `App\Http\Requests\Repositories\LinkRepositoryRequest` — validates `project_id` exists + the user can link to it (via the policy gate), and that exactly one of `repository_url` or `full_name` is present (with regex constraint on each).

- **Routes.** Add to `routes/web.php`:
    ```php
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::resource('projects', ProjectController::class);
        Route::resource('repositories', RepositoryController::class)
            ->only(['index', 'show', 'store', 'destroy'])
            ->where(['repository' => '[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+']);
    });
    ```

- **Vue Pages** under `resources/js/Pages/Repositories/`:
    - `Index.vue` — responsive table-style list (1-col stack at mobile, table-like rows at md+). Each row: language pill (color-coded), owner / name (mono), linked-project chip with the project icon + color, default branch, sync status badge, open issues / PRs counts, last-pushed time. Empty state with a "Link a repository" CTA pointing to the first project's Repositories tab (or to `/projects/create` if none exist yet).
    - `Show.vue` — repository detail page with the header (icon = `GitBranch`, owner/name with mono, external GitHub link), counts grid, sync status block, "Linked project" callout that links back to the project, and a Delete button (visible only when the user can `delete`).

- **Project Show — Repositories tab content.** Currently the Repositories tab on `Pages/Projects/Show.vue` renders the phase-pending placeholder pointing at "spec 011". Replace that branch with:
    - A short "Manual link" form (one input that accepts URL or `owner/name`) + "Link repository" submit button. Visible only when the current user can `update` the project (policy-gated via the `canUpdate` prop already on the page).
    - A list of repositories already linked to this project — same row treatment as the global index, plus a per-row remove button (policy-gated).
    - Empty state: "No repositories linked yet — paste a GitHub URL or `owner/name` above to add one."

- **Sidebar nav activation.** Drop `disabled` from the `Repositories` entry in `Components/Sidebar/Sidebar.vue`. `routeName: 'repositories.index'`. The `isActive` family-match from spec 010 already handles `repositories.*`.

- **Command palette activation.** `lib/commands.ts` — `go-repositories` becomes real (drop `disabled`, set `run: () => router.visit(route('repositories.index'))`). `connect-github` and `run-sync` stay "Soon" (they ship in phase 2).

- **Seeder.** `Database\Seeders\RepositorySeeder` — seed 2-3 repositories per existing project (so the index / project tabs render with realistic data on `migrate:fresh --seed`). Wire into `DatabaseSeeder` after `ProjectSeeder`.

- **Tests** (PHP feature tests):
    - `RepositoryControllerTest` — index renders, store creates via URL + via full_name, store rejects malformed input, store rejects duplicate `full_name` per project, show renders, destroy destroys.
    - `RepositoryPolicyTest` — non-project-owner cannot link or delete; project owner can.
    - `RepositoryModelTest` — route key + `Project::repositories()` relationship works.
    - `LinkRepositoryToProjectActionTest` — URL parser handles `https://github.com/owner/name`, `https://github.com/owner/name.git`, `https://github.com/owner/name/`, and `owner/name`.

- **Update Phase 1 README + main tracker** as part of the same PR: nothing to flip mid-implementation. Bookkeeping happens in the post-merge chore PR (matches the workflow we've used for every prior spec).

**Out of scope:**
- Wire the Overview Top Repositories widget to the new table — that's spec 012.
- Auto-sync metadata from GitHub. The `provider`, `stars_count`, `forks_count`, `open_issues_count`, `open_prs_count`, `language`, `description`, `default_branch`, `visibility`, `last_pushed_at`, `last_synced_at`, and `sync_status` columns are populated only by the seeder + manual data this spec; phase 2's `SyncGitHubRepositoryJob` updates them later. The `provider_id` stays `null` until phase 2.
- Repository risk score / risk badge. Roadmap §8.3 lists the inputs; the score requires alert/workflow data that lands in phases 4/7. Show a `sync_status` badge instead.
- Repository edit page. Metadata is GitHub-sourced; the only mutable fields a user has any business changing are the parent project link (which is a "delete + re-link" today) and the description (which we leave for GitHub to mirror). Skip Edit entirely.
- A `GitHubClient` HTTP wrapper / `SyncGitHubRepositoryJob`. Both ship with phase 2.
- Webhook ingestion / activity-event creation on link. Phase 3 owns activity events.
- Bulk operations (link many at once, bulk delete).

## Plan

1. **Migration + enum + model + factory.** Schema, `RepositorySyncStatus` enum, `Repository` model with `belongsTo(Project)`, route key, casts. `Project::repositories()` hasMany. Factory exercising every sync status. Run `php artisan migrate:fresh` to confirm.
2. **Action class.** `LinkRepositoryToProjectAction` parses URL or `owner/name`, normalizes, persists. Unit-test the parser branches.
3. **Form request.** `LinkRepositoryRequest` enforces input shape + policy authorization.
4. **Policy + provider registration.** `RepositoryPolicy` (no `update`); register in `AppServiceProvider::boot()`.
5. **Controller + routes.** `RepositoryController::{index, show, store, destroy}`. `Route::resource('repositories')->only(...)->where(...)` so two-segment slugs work as route keys.
6. **Vue pages.** `Repositories/Index.vue`, `Repositories/Show.vue`, plus update `Projects/Show.vue` to render the manual link form + linked repositories list when on the Repositories tab.
7. **Sidebar + palette activation.** Drop `disabled` from the `Repositories` nav. Wire `go-repositories` palette command.
8. **Seeder.** `RepositorySeeder` drops 2-3 repos per existing seeded project. Wire into `DatabaseSeeder`. Run `migrate:fresh --seed`.
9. **Tests.** Controller, policy, model, action.
10. **Manual UX walk** (Playwright Chrome) at desktop (1440) + mobile (390): walk `/repositories`, link a repo from a Project's Repositories tab, click into the repo, delete it, confirm sidebar + palette behave.
11. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
12. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] Migration creates `repositories` table with all §8.3 fields. `php artisan migrate:fresh` succeeds.
- [ ] `App\Models\Repository` exists with `project` belongsTo + sync-status enum cast + two-segment route key.
- [ ] `Project::repositories()` hasMany relationship works (asserted in `RepositoryModelTest`).
- [ ] `App\Enums\RepositorySyncStatus` is a string-backed PHP enum used as the `sync_status` cast.
- [ ] `RepositoryFactory` produces valid records across every sync-status value.
- [ ] `LinkRepositoryToProjectAction` parses both `https://github.com/owner/name` (with optional `.git` / trailing `/`) and `owner/name`. Idempotent: linking the same `full_name` twice to the same project is a no-op + flash message rather than an error.
- [ ] `RepositoryPolicy` enforces project-owner-only `create` + `delete`; viewer is `auth+verified`.
- [ ] `RepositoryController` resourceful index/show/store/destroy. Routes named `repositories.{index,show,store,destroy}` with the two-segment `where` constraint.
- [ ] `Pages/Repositories/Index.vue` shows a responsive list with linked-project chip + sync status + counts; empty state present.
- [ ] `Pages/Repositories/Show.vue` shows the repository detail with the linked project, counts grid, sync-status block, external GitHub link, and Delete button (policy-gated).
- [ ] `Pages/Projects/Show.vue` Repositories tab now renders the manual link form (when `canUpdate`) + the linked repositories list. The phase-pending placeholder for that tab is gone.
- [ ] Sidebar `Repositories` is no longer "Soon"; clicking it navigates to `/repositories` and the row highlights as active there.
- [ ] Command palette `Go to Repositories` is real (no "Soon" pill); `Connect GitHub` and `Run sync` stay "Soon" (phase 2).
- [ ] `RepositorySeeder` creates 2–3 sample repositories per seeded project on `migrate:fresh --seed`.
- [ ] PHP test suite green; new tests cover controller, policy, model, action. SmokeTest unchanged.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes — design tokens only.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `database/migrations/<timestamp>_create_repositories_table.php` — new.
- `app/Models/Repository.php` — new.
- `app/Models/Project.php` — add `repositories()` hasMany.
- `app/Enums/RepositorySyncStatus.php` — new.
- `database/factories/RepositoryFactory.php` — new.
- `app/Policies/RepositoryPolicy.php` — new.
- `app/Providers/AppServiceProvider.php` — register `RepositoryPolicy`.
- `app/Http/Controllers/RepositoryController.php` — new.
- `app/Http/Requests/Repositories/LinkRepositoryRequest.php` — new.
- `app/Domain/Repositories/Actions/LinkRepositoryToProjectAction.php` — new.
- `routes/web.php` — `Route::resource('repositories')->only(...)`.
- `resources/js/Pages/Repositories/Index.vue` — new.
- `resources/js/Pages/Repositories/Show.vue` — new.
- `resources/js/Pages/Projects/Show.vue` — Repositories tab gets real content (manual link form + linked-repos list).
- `resources/js/Components/Sidebar/Sidebar.vue` — activate `Repositories` nav.
- `resources/js/lib/commands.ts` — activate `go-repositories`.
- `database/seeders/RepositorySeeder.php` — new.
- `database/seeders/DatabaseSeeder.php` — register `RepositorySeeder`.
- `tests/Feature/Repositories/RepositoryControllerTest.php` — new.
- `tests/Feature/Repositories/RepositoryPolicyTest.php` — new.
- `tests/Feature/Repositories/RepositoryModelTest.php` — new.
- `tests/Feature/Repositories/LinkRepositoryToProjectActionTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-28
- Spec drafted; scope confirmed (6 decisions locked: full_name route key, skip edit, single-input link UX, 2-3 seeded repos per project, inline tab content, skip risk badge).
- Opened issue [#27](https://github.com/Copxer/nexus/issues/27) and branch `spec/011-repositories` off `main`.
- Implemented:
    - **Migration + enum + model + factory.** `repositories` table with all §8.3 fields (`team_id`-equivalent here is the `project_id` FK, cascadeOnDelete). `App\Enums\RepositorySyncStatus` (string-backed; pending/syncing/synced/failed) with `badgeTone()` helper. `App\Models\Repository` with `belongsTo(Project)`, sync-status enum cast, `getRouteKeyName()` returning `full_name` for the two-segment URL. `Project::repositories()` hasMany added to existing model. Factory exercises every sync-status state, weighted toward `synced`.
    - **Domain layer's first inhabitant.** `App\Domain\Repositories\Actions\LinkRepositoryToProjectAction` parses URL or `owner/name`, normalizes case, idempotent on duplicate within the same project. The URL parser handles `https://github.com/owner/name`, `.git` suffix, and trailing `/`.
    - **Form request + policy + provider.** `LinkRepositoryRequest` validates `project_id` exists + the user can update that project (via policy gate) + the `repository` field shape via regex. `RepositoryPolicy` (project-owner-only `create`/`delete`; viewAny/view open to verified users); registered via `Gate::policy()` in `AppServiceProvider::boot()`.
    - **Controller + routes.** `RepositoryController` with `index`, `show`, `store`, `destroy`. Store catches `InvalidArgumentException` (parser failure) and `UniqueConstraintViolationException` (full_name already linked elsewhere) and surfaces friendly errors. `Route::resource('repositories')->only([...])->where(['repository' => '[\w.-]+/[\w.-]+'])` so two-segment route keys resolve.
    - **Vue pages.** `Repositories/Index.vue` (responsive list with linked-project chip + language pill + sync status + counts, empty state CTA pointing at projects), `Repositories/Show.vue` (header + counts grid + "View on GitHub" + Unlink + sync activity card). Updated `Projects/Show.vue` Repositories tab to render manual link form (when `canUpdate`) + linked-repos list with per-row external-link + unlink controls. `tabs[1].pendingPhase` flipped from `'spec 011'` → `null`.
    - **Sidebar + palette activation.** Sidebar `Repositories` drops `disabled` + uses `routeName: 'repositories.index'`. Palette `go-repositories` loses `disabled`/`soonLabel` and gains `router.visit(route('repositories.index'))`.
    - **Seeder.** `RepositorySeeder` drops 2-3 repositories per seeded project (9 total across the 4 sample projects). Sets `full_name` explicitly to dodge the `WithoutModelEvents` workaround pattern from spec 010. Wired into `DatabaseSeeder` after `ProjectSeeder`.
    - **PHP feature tests.** 4 new test files: `LinkRepositoryToProjectActionTest` (6 cases — URL/slug parser branches, idempotency), `RepositoryControllerTest` (7 cases — index/show/store with both formats, garbage rejection, full_name uniqueness, destroy), `RepositoryPolicyTest` (4 cases — owner gate, non-owner blocked, viewer open to verified, unverified blocked), `RepositoryModelTest` (3 cases — route key, project relation, hasMany count).
- Manual UX walk in Playwright Chrome (1440×900):
    - `/repositories` index renders 9 seeded repos with all the row chrome (mono full_name, project chip with icon+color, counts, language pill, sync badge, pushed-at).
    - Two-segment URL `/repositories/nexus-labs/edge-cache` resolves correctly via the `where()` constraint; Show page renders header + counts grid + sync activity.
    - Project Show Repositories tab: manual link form + linked-repos list. End-to-end link with bare `owner/name` slug worked — new repo `nexus-org/spec-011-demo` appears as the 4th row with the muted PENDING badge.
    - Sidebar `Repositories` highlighted active on `/repositories` and `/repositories/{repo}`. Palette `Go to Repositories` filters cleanly.
- Found and fixed during implementation:
    - **`project_id` not in `$fillable`.** Initial draft had only the GitHub-sourced fields fillable; `Repository::create(['project_id' => ...])` silently dropped the FK and the insert failed on the NOT NULL constraint. Added `project_id` to fillable. The security boundary is the FormRequest, not mass-assignment guards on the model.
    - **`loadMissing('project:id,...')` excluded `owner_user_id`.** The eager-load column subset omitted the project's owner FK; the policy's `update` check then read null and `canDelete` was always false. Added `owner_user_id` to the column list in both `index()` and `show()`.
    - **Index grid template overflow.** The `md:grid-cols-[3fr_2fr_1fr_1fr_auto]` template gave the language+sync-status column only 1fr, which at 1440 was tight enough that the badges visually merged with the pushed-at cell. Switched the last three columns to `auto auto auto` so they get content-sized space.
- Pipeline: Pint clean, vue-tsc clean, `npm run build` green. **40 tests pass with 196 assertions** (20 new repository tests + 14 project + 6 existing smoke/heartbeat/horizon-access).
- Self-review with `superpowers:code-reviewer`. **No blockers; 2 material findings + worthwhile nits addressed.** Final test count after the new regression test: **41 tests, 197 assertions.**
    - **[material, fixed]** Parser asymmetry on `.git` suffix. `parse('https://github.com/owner/name.git')` correctly stripped to `name`, but `parse('owner/name.git')` (bare slug) yielded `name = 'name.git'` because the bare-slug branch matched `[\w.-]+` which includes the dot. Same logical repo could persist as two distinct rows. Fix: extracted a `stripGitSuffix()` helper called from both branches; added a regression test (`test_parses_bare_slug_with_git_suffix`) so the asymmetry can't return.
    - **[material, fixed]** `LinkRepositoryRequest::resolvedProject()` docblock claimed it was cached when each call queried the database. Rewrote the docblock to be honest about the two database reads and noted the revisit threshold (when a third call site appears).
    - **[nit, fixed]** `RepositoryPolicy::create()` accepted `$project` untyped. Changed the signature to `?Project` and added the corresponding import.
    - **[nit, fixed]** Added a `TODO(multi-team)` comment on the action's check-then-create — same TOCTOU concern as spec 010's slug race; acceptable for phase-1 single-user dev, flagged for the multi-tenant pass.
    - **[nit, fixed]** `RepositoryFactory::definition()` used `fake()->unique()->randomElement(self::NAME_POOL)` against an 8-element pool — `OverflowException` would fire if a single test created >8 repos. Switched to a `Str::random(4)` suffix appended to the name; the factory can now safely emit any number of unique full_names.
    - Reviewer also confirmed: two-segment route key works under `route:cache` and Ziggy URL generation; no `<Link>`-wrapping-button HTML invalidity in the new tab content; double-`authorize` in `store()` is acceptable defense-in-depth; `loadMissing` column-subset trap should NOT be addressed via dropping subsets (keep them; only extract a `Project::DASHBOARD_COLUMNS` constant if a third loader appears).

## Decisions (locked 2026-04-28)
- **Route key — `full_name`.** Two-segment slug (`owner/name`) with a regex `where()` constraint. URLs mirror GitHub (`/repositories/nexus-org/nexus-web`).
- **Edit page — skip.** Metadata is GitHub-sourced; nothing meaningful for users to mutate yet. Add later if a real use case arises.
- **Manual link UX — single input.** One field that accepts URL or `owner/name`; the parser handles both.
- **Sample seed shape — 2–3 repos per project.** Guarantees every seeded project has at least one repo so the Repositories tab on `/projects/{slug}` has content.
- **Project Show Repositories tab — render inline.** Manual link form + linked-repos list show on the Repositories tab. The project-scoped variant is the more useful UX.
- **Risk badge — skip.** Show `sync_status` instead; risk inputs come from phase 4/7 data we don't have. Fake risk is worse than honest sync status.

## Open questions / blockers

- **Two-segment route keys.** Laravel's default route binding doesn't expect a `/` inside a single segment. The `where('repository', '...slash...')` constraint plus the `full_name` route key handle this, but I want to verify in the manual UX walk that link generation (`route('repositories.show', $repo)`) emits the correct URL and the binder resolves it.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue** still present locally (specs 005–010). Not introduced by this spec; CI passes on PHP 8.4. Same disclaimer.
