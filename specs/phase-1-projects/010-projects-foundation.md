---
spec: projects-foundation
phase: 1-projects
status: in-progress
owner: yoany
created: 2026-04-28
updated: 2026-04-28
issue: https://github.com/Copxer/nexus/issues/24
branch: spec/010-projects-foundation
---

# 010 — Projects (model + CRUD pages + sidebar/palette activation)

## Goal
Stand up Projects as a first-class entity. Phase 1 starts here: a `projects` table, an Eloquent model, a factory, a policy, a CRUD controller, four Vue pages (Index / Create / Show / Edit), a seeder for sample data, and the sidebar/command-palette nav items get promoted from "Soon" to real links. After this spec, a logged-in user can create a project, see it on `/projects`, click into it, edit it, and delete it — all with the Nexus dark theme.

This spec does **not** wire the Overview Projects KPI to the new table — that's spec 012 ("Wire Overview to DB"). Repositories live in spec 011.

Roadmap reference: §8.2 Projects (the full field list, status/priority enums, UX requirements, the 7-tab project detail view).
Visual target: existing dashboard chrome + the same glass-card / token vocabulary used everywhere in Phase 0.

## Scope
**In scope:**

- **Database.** Migration creates `projects` table with the §8.2 fields:
    - `id` (auto-increment)
    - `team_id` (nullable foreign key — teams aren't a thing yet, leave the column for future single-team-per-user setup; nullable + indexed)
    - `name` (string, required)
    - `slug` (string, unique, generated from name)
    - `description` (text, nullable)
    - `status` (enum: `active|maintenance|paused|archived`, default `active`)
    - `priority` (enum: `low|medium|high|critical`, default `medium`)
    - `environment` (string, nullable — free-form: production/staging/internal)
    - `owner_user_id` (foreign key to users, required)
    - `color` (string, nullable — token shorthand: `cyan|blue|purple|magenta|success|warning`)
    - `icon` (string, nullable — lucide icon name)
    - `health_score` (integer 0..100, nullable — placeholder, populated by future jobs)
    - `last_activity_at` (timestamp, nullable)
    - timestamps

- **Model.** `App\Models\Project`:
    - `$fillable` for the columns above except `id`, `team_id`, `owner_user_id` (those are server-set).
    - Casts for the enums (PHP-native enum casts) and timestamps.
    - `belongsTo(User::class, 'owner_user_id')` (`owner` relationship).
    - `slug` auto-generated on `creating` via a model observer or boot method (use `Str::slug($name)` plus a 3-char uniqueness suffix on collision).

- **Enum classes.** `App\Enums\ProjectStatus` (active/maintenance/paused/archived) + `App\Enums\ProjectPriority` (low/medium/high/critical). String-backed PHP enums so they map cleanly to the DB column.

- **Factory.** `Database\Factories\ProjectFactory` — sensible randoms across all enum values, plus a couple of realistic-looking name patterns ("Customer Portal v3", "Billing API", "Edge Cache Pilot").

- **Policy.** `App\Policies\ProjectPolicy`:
    - `viewAny`: any authenticated user (single-tenant for phase 1; team scoping arrives later).
    - `view` / `update` / `delete`: owner only.
    - `create`: any authenticated, verified user.
    - `restore` / `forceDelete`: omit (no soft deletes this spec).
    - Register with `Gate::policy(...)` in `App\Providers\AppServiceProvider::boot()`.

- **Controller.** `App\Http\Controllers\ProjectController` — full RESTful resource, except `restore` (no soft deletes):
    - `index` — paginated list of all projects (ordered `last_activity_at desc, created_at desc`), with eager-loaded `owner` and a computed `repositories_count` placeholder (stays 0 until spec 011).
    - `create` — Inertia form page.
    - `store` — `FormRequest`-validated; sets `owner_user_id` to current user; redirects to `show`.
    - `show` — passes the project + owner + a placeholder `repositories: []` array (real repos in spec 011) and the 7 tab labels.
    - `edit` — Inertia form page.
    - `update` — `FormRequest`-validated; redirects back to `show`.
    - `destroy` — owner-only via policy; redirects to `index`.

- **Routes.** `routes/web.php` — `Route::resource('projects', ProjectController::class)->middleware(['auth', 'verified'])`.

- **Form Requests.** `App\Http\Requests\Projects\StoreProjectRequest` + `UpdateProjectRequest` — validate name, description, status (in enum), priority (in enum), environment (nullable string ≤ 64), color (nullable, must match the token list), icon (nullable lucide name string).

- **Vue Pages** under `resources/js/Pages/Projects/`:
    - `Index.vue` — responsive card grid (1/2/3 cols at mobile/sm/lg). Each card shows: icon (top-left, accent-tinted), name, description (truncated), status badge, priority pill, owner avatar, last-activity time. CTA "Create project" button anchored top-right. Empty state when no projects.
    - `Create.vue` — form with the §8.2 fields. Uses existing `TextInput`, `InputLabel`, `InputError`, `PrimaryButton` Breeze components. Status + priority + color render as visual pickers (radio-group style) so the user picks tokens, not free text. Icon is a small lucide picker (12 curated options — `FolderKanban`, `Rocket`, `GitBranch`, `Server`, `Globe`, `BarChart3`, `Bell`, `Activity`, `HeartPulse`, `Cpu`, `Database`, `Cloud`).
    - `Show.vue` — detail header (icon + name + status + priority + owner + last activity) + 7-tab nav (Overview / Repositories / Deployments / Hosts / Monitoring / Activity / Settings). Only **Overview** and **Settings** tabs render content; the others render a phase-pending placeholder ("Repositories arrives with spec 011", "Deployments arrives with phase 4", etc.). Tab switching is client-side via a local `activeTab` ref; no URL hash sync this spec.
    - `Edit.vue` — same form as Create, prefilled.

- **Sidebar nav activation.** `resources/js/Components/Sidebar/Sidebar.vue` — drop the `disabled: true` from the `Projects` entry, change `routeName: 'projects.index'`. Active state lights up when on any `/projects/*` route (`route().current('projects.*')`).

- **Command palette activation.** `resources/js/lib/commands.ts` — `go-projects` becomes real: drop the `disabled` flag, set `run: () => router.visit(route('projects.index'))`. Add a real `Create project` (`create-project`): drop the disabled flag, set `run: () => router.visit(route('projects.create'))`.

- **Seeder.** `Database\Seeders\ProjectSeeder` — creates 4 sample projects with realistic data (a mix of statuses + priorities) for the demo user. Wired into `DatabaseSeeder` so `php artisan migrate:fresh --seed` produces a populated dashboard.

- **Tests** (PHP feature tests):
    - `ProjectControllerTest` — CRUD happy paths (index renders, create form renders, store creates + redirects, show renders, edit renders, update updates, destroy destroys).
    - `ProjectPolicyTest` — non-owner cannot edit/delete; owner can.
    - `ProjectModelTest` — slug auto-generates on create + handles collisions.
    - All tests use the existing `RefreshDatabase` + `User::factory()` patterns from spec 005's smoke test.

- **Update phase trackers** as part of the same PR: flip 010 row to 🟡 in `specs/phase-1-projects/README.md`, then to 🟢 at the bookkeeping PR after merge (matches the Phase 0 workflow).

**Out of scope:**
- Repositories — spec 011.
- Wire `OverviewController` `dashboard.projects.active` to a `Project::count()` — spec 012.
- Team scoping / multi-tenant. The `team_id` column is reserved but unused this spec.
- Soft deletes / restore. Add when we have an undo UX story.
- Activity logging on project changes. Spec 007's mock activity feed stays unchanged.
- Avatar / image upload for projects.
- Drag-to-reorder on the index page.
- A Projects API for external clients.
- The 5 unbuilt project-detail tabs (Repositories / Deployments / Hosts / Monitoring / Activity) — they ship with their owning phases.

## Plan

1. **Migration + enum classes.** Write the migration, define `ProjectStatus` and `ProjectPriority` enum classes. Run `php artisan migrate:fresh` locally to confirm the schema.
2. **Model + factory.** Build `App\Models\Project` with relationships, casts, and the slug-on-creating boot method. Write `ProjectFactory` exercising every enum value.
3. **Policy + provider registration.** Write `ProjectPolicy`. Register in `AppServiceProvider::boot()`.
4. **Form Requests + Controller + Routes.** Write `StoreProjectRequest`, `UpdateProjectRequest`. Write `ProjectController` (7 actions, eager-loading `owner`). Add `Route::resource(...)`.
5. **Vue pages.** Build `Index.vue`, `Create.vue`, `Show.vue`, `Edit.vue` against the design tokens. Reuse Breeze form components.
6. **Sidebar + palette activation.** Drop `Projects` from "Soon" in `Sidebar.vue`. Drop `Soon` and wire `run` for `go-projects` + `create-project` in `commands.ts`.
7. **Seeder.** Write `ProjectSeeder`, register in `DatabaseSeeder`, run `php artisan migrate:fresh --seed`.
8. **Tests.** Feature + policy + model tests.
9. **Manual UX walk** (Playwright Chrome) at desktop (1440) + mobile (390): create a project, edit it, delete it. Confirm sidebar and palette behave.
10. **Pipeline** — Pint, vue-tsc, build, full PHP test run.
11. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] Migration creates `projects` table with all §8.2 fields. `php artisan migrate:fresh` succeeds.
- [ ] `App\Models\Project` model exists with `owner` relationship + slug auto-generation + enum casts.
- [ ] `App\Enums\ProjectStatus` + `App\Enums\ProjectPriority` are string-backed PHP enums and used as casts.
- [ ] `ProjectFactory` produces valid records across every enum value.
- [ ] `ProjectPolicy` enforces owner-only edit/delete; viewer role is `auth+verified`.
- [ ] `ProjectController` resourceful CRUD; routes named `projects.{index,create,store,show,edit,update,destroy}`.
- [ ] `StoreProjectRequest` + `UpdateProjectRequest` validate enum membership and length.
- [ ] `Pages/Projects/Index.vue` shows a responsive card grid with empty state.
- [ ] `Pages/Projects/Create.vue` + `Edit.vue` render the form with visual pickers for status/priority/color/icon.
- [ ] `Pages/Projects/Show.vue` renders the 7-tab nav (Overview + Settings have content; the others are phase-pending placeholders).
- [ ] Sidebar `Projects` is no longer "Soon"; clicking it navigates to `/projects` and the row highlights as active there.
- [ ] Command palette `Go to Projects` and `Create project` are real commands (no "Soon" pill); pressing Enter on each navigates correctly.
- [ ] `ProjectSeeder` creates 4 sample projects on `migrate:fresh --seed`.
- [ ] PHP test suite still passes; new tests cover CRUD, policy, model. SmokeTest unchanged.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes — design tokens only.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `database/migrations/<timestamp>_create_projects_table.php` — new.
- `app/Models/Project.php` — new.
- `app/Enums/ProjectStatus.php` — new.
- `app/Enums/ProjectPriority.php` — new.
- `database/factories/ProjectFactory.php` — new.
- `app/Policies/ProjectPolicy.php` — new.
- `app/Providers/AppServiceProvider.php` — register `ProjectPolicy`.
- `app/Http/Controllers/ProjectController.php` — new.
- `app/Http/Requests/Projects/StoreProjectRequest.php` — new.
- `app/Http/Requests/Projects/UpdateProjectRequest.php` — new.
- `routes/web.php` — `Route::resource('projects', ProjectController::class)->middleware([...])`.
- `resources/js/Pages/Projects/Index.vue` — new.
- `resources/js/Pages/Projects/Create.vue` — new.
- `resources/js/Pages/Projects/Show.vue` — new.
- `resources/js/Pages/Projects/Edit.vue` — new.
- `resources/js/Components/Sidebar/Sidebar.vue` — activate `Projects` nav.
- `resources/js/lib/commands.ts` — activate `go-projects` + `create-project`.
- `database/seeders/ProjectSeeder.php` — new.
- `database/seeders/DatabaseSeeder.php` — register `ProjectSeeder`.
- `tests/Feature/Projects/ProjectControllerTest.php` — new.
- `tests/Feature/Projects/ProjectPolicyTest.php` — new.
- `tests/Feature/Projects/ProjectModelTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-28
- Spec drafted; scope confirmed (6 decisions locked: 3-char alpha slug suffix, token-aligned colors, curated 12 icons, all 7 tabs, skip soft deletes, seed 4).
- Opened issue [#24](https://github.com/Copxer/nexus/issues/24) and branch `spec/010-projects-foundation` off `main`.
- Created `specs/phase-1-projects/README.md` and `specs/phase-1-projects/010-projects-foundation.md` to start Phase 1.
- Implemented:
    - **Migration + enums + model + factory.** `projects` table with all §8.2 fields. `App\Enums\ProjectStatus` and `ProjectPriority` (string-backed; both expose a `badgeTone()` for the dashboard). `App\Models\Project` with slug auto-gen on `creating` (3-char `Str::random` suffix on collision), `owner` belongsTo, enum casts. Factory exercises every enum value and uses a curated name pool.
    - **Policy + provider.** `App\Policies\ProjectPolicy` (owner-only `update`/`delete`; `viewAny`/`view`/`create` open to verified users). Registered via `Gate::policy()` in `AppServiceProvider::boot()`. The base `Controller` class gained `AuthorizesRequests` trait (Laravel 11+ no longer ships it by default).
    - **Form requests + controller + routes.** `Store/UpdateProjectRequest` validate enum membership + length + token-restricted color and icon. `ProjectController` resourceful 7 actions; `index` eager-loads `owner` + orders by `last_activity_at desc`; `transform()` shapes the JSON for all three pages (Index/Show/Edit). `routes/web.php` adds `Route::resource('projects')` under `auth+verified`.
    - **Vue pages.** `Index` (responsive 1/2/3-col card grid + empty state), `Create` and `Edit` share `Pages/Projects/Partials/ProjectForm.vue` (token-aligned color swatches, 12-icon picker, status/priority radio pills, name/description/environment text inputs), `Show` (header + 7-tab nav with Overview + Settings active, the other 5 rendering phase-pending placeholders pointing to their owning specs/phases).
    - **Sidebar + palette activation.** Projects nav item drops "Soon" + sets `routeName: 'projects.index'`. Sidebar's `isActive` now also matches `projects.*` so child routes (Show/Edit) keep the nav lit. Palette's `go-projects` and `create-project` lose their `disabled` flag and gain real `run` handlers.
    - **Icon registry.** New `resources/js/lib/projectIcons.ts` whitelists the 12 curated lucide icons + a `projectIcon(name)` resolver. Keeps the bundle tree-shakeable (vs `import * as LucideIcons`).
    - **Seeder.** `ProjectSeeder` drops 4 sample projects (Customer Portal v3 / Billing API / Edge Cache Pilot / Legacy Reporting Suite) with varied statuses + priorities, owned by the first existing user. Note: `DatabaseSeeder` uses `WithoutModelEvents` which disables the `creating` slug hook, so the seeder sets `slug` explicitly via `Str::slug` — robust regardless of how the seeder is invoked.
    - **PHP feature tests.** Three test files: `ProjectControllerTest` (7 cases — index, create, store, show, edit, update, destroy), `ProjectPolicyTest` (4 cases — non-owner blocked, owner allowed, verified can view, unverified blocked), `ProjectModelTest` (3 cases — slug auto-gen, collision suffix, fallback when name has no slug chars).
- Manual UX walk in Playwright Chrome (1440×900):
    - `/projects` index renders all 4 seeded cards with status + priority badges, owner avatars, last-activity stamps. Sidebar `Projects` nav lit cyan.
    - `/projects/create` renders the form with all 4 picker types working. Filled out a "Spec 010 Demo" project (Critical priority, magenta color, Rocket icon).
    - Submitted → redirected to `/projects/spec-010-demo` with the slug auto-generated.
    - Show page: header with magenta-glowing Rocket icon, ACTIVE/CRITICAL badges, owner + "0 seconds ago" activity, 7-tab nav. Overview tab body shows Environment / Health score / Slug. Repositories tab renders the "Coming up later — populates with real data when spec 011 ships." placeholder.
    - Cmd+K palette → "Create project" filters cleanly with cyan highlight, no "Soon" pill. Pressed Enter → navigated to `/projects/create`.
- Pipeline: Pint clean, vue-tsc clean, `npm run build` green. **20 tests pass with 134 assertions** (7 new controller + 4 policy + 3 model + 6 existing smoke/heartbeat/horizon-access).

## Decisions (locked 2026-04-28)
- **Slug strategy — 3-char alpha suffix.** `Str::slug($name)` plus a 3-char alpha suffix on collision (e.g. `customer-portal-v3-a4f`). Distinguishable, doesn't leak project count.
- **Color picker — token-aligned only.** 6 swatches: cyan / blue / purple / magenta / success / warning. No free-form hex — prevents palette drift.
- **Icon picker — curated 12.** `FolderKanban`, `Rocket`, `GitBranch`, `Server`, `Globe`, `BarChart3`, `Bell`, `Activity`, `HeartPulse`, `Cpu`, `Database`, `Cloud`. Cheap to extend later.
- **Show page tabs — all 7.** Overview + Settings active; the other 5 render phase-pending placeholders. Advertises the roadmap (consistent with sidebar / palette / Visualizations stub treatment from Phase 0).
- **Soft deletes — skip.** No undo UX yet; add when a "trash bin" arrives.
- **Seed count — 4 projects** across statuses + priorities. Enough to show the index card grid; sparse enough that "Create project" feels meaningful in the demo.

## Open questions / blockers
- The §8.2 roadmap mentions a `team_id`. We're single-tenant for now and the column stays nullable. No team scoping logic this spec — `viewAny` is open to all `auth+verified` users. Re-evaluate when a multi-team / multi-account spec is scheduled.
