---
spec: deployment-timeline-ui
phase: 4-deployments-cicd
status: in-progress
owner: yoany
created: 2026-04-30
updated: 2026-04-30
issue: https://github.com/Copxer/nexus/issues/63
branch: spec/021-deployment-timeline-ui
---

# 021 — Deployment timeline UI

## Goal
Light up the cross-repo `/deployments` page that consumes the `workflow_runs` table shipped in spec 020. Renders the user's workflow runs as a chronological deployment timeline with success / failure / in-flight markers, filters by project / repository / status / conclusion / branch (URL-bound so views are shareable), a per-run detail drawer with head SHA, duration, actor, conclusion, and a link out to GitHub, and **real-time refresh via Reverb** — when a webhook delivery upserts a workflow run, the timeline updates without a manual reload.

This is the **UI half** of phase 4. Spec 020 shipped the data; spec 022 surfaces a success-rate KPI on Overview.

Roadmap reference: §8.13 Deployment timeline, §19 Phase 4 (acceptance: dashboard shows latest deployments, timeline shows success/failure markers, user can filter, UI updates in near-real-time).

## Scope
**In scope:**

- **Sidebar nav.** Flip the existing `Deployments` entry in `Components/Sidebar/Sidebar.vue` from `disabled: true, soonLabel: 'Phase 4'` to `routeName: 'deployments.index'`. Keep the `Rocket` icon. The `Pipelines` entry stays disabled — a CI-only filter view can grow later.

- **Route + controller.** `Route::get('/deployments', [DeploymentController::class, 'index'])->name('deployments.index')`. New `App\Http\Controllers\DeploymentController` (single `index` action for now; the drawer hydrates from the same payload, no separate `show` route needed).

- **`App\Domain\GitHub\Queries\DeploymentTimelineQuery`** — cross-repo query scoped to the authenticated user.
    - Resolves "user's repositories" via `Repository::whereHas('project', fn ($p) => $p->where('owner_user_id', $user->id))` — same scoping pattern as `WorkItemsForUserQuery` (spec 016).
    - Filters supported: `project_id`, `repository_id`, `status` (`queued|in_progress|completed`), `conclusion` (`success|failure|cancelled|timed_out|action_required|stale|neutral|skipped`), `branch` (string match on `head_branch`).
    - Sort: `run_started_at desc, github_id desc` (matches `WorkflowRunsForRepositoryQuery`).
    - Cap: 100 rows. Cursor / pagination is a follow-up if a real user blows past it.
    - Eager-loads `repository:id,full_name,html_url,project_id` + `repository.project:id,slug,name,color,icon` so the timeline can render project chips without N+1.
    - Returns plain arrays, not Eloquent models — same convention as the issues / PRs / workflow runs queries.

- **Filter options payload.** The page needs the *available* projects + repositories so the filter UI can populate dropdowns. Resolve user's projects (id + name + color) and repositories (id + full_name + project_id) into two lightweight arrays passed alongside the timeline rows. Don't expose full enum lists for status / conclusion — those are static so the Vue page can hard-code them (safer than serializing from PHP enums and reflecting type drift).

- **Inertia payload shape:**
    ```
    {
        deployments: [...],          // array of run rows
        filters: {                   // current filter state, echoed back from the request
            project_id: number|null,
            repository_id: number|null,
            status: string|null,
            conclusion: string|null,
            branch: string|null,
        },
        filterOptions: {
            projects: [{ id, name, color }],
            repositories: [{ id, full_name, project_id }],
        },
    }
    ```

- **`Pages/Deployments/Index.vue`** (the page itself):
    - Top header: page title `Deployments`, secondary line `Cross-repo workflow runs`, manual "Refresh" button (router.reload, no params change). Stays consistent with `Activity/Index.vue`'s header treatment.
    - Filter strip directly under the header — a horizontal row of compact `<select>` dropdowns (Project, Repository, Status, Conclusion, Branch). All five are URL-bound: changing a value posts to `deployments.index` with the new query string via `router.get(route('deployments.index'), filters, { preserveScroll: true, preserveState: true })`. A clear-filters chip resets to baseline.
    - Repository dropdown is **dependent on Project** — when a project is selected, the repository dropdown narrows to that project's repos. When the project is cleared, the repo dropdown shows all user repos. Implemented client-side off the `filterOptions.repositories` array; no extra payload calls.
    - Timeline body: a chronological list. Each row is a `glass-card`-style entry with:
        - Left: a colored status dot (success / danger / warning / muted / accent-cyan-when-in-progress, mirrors spec 020's badge rules).
        - Middle: workflow name + run number, repository chip (uses project color), branch (mono), event (mono small), actor `@login`.
        - Right: relative time (`Started 3h ago`), conclusion badge or status badge, an `ExternalLink` icon → `html_url` on GitHub.
    - Group-by-day header — light visual separators on days. Computed client-side off `run_started_at` (already a humanized string in the payload; we'll add the raw ISO timestamp to the payload for grouping, or use a reverse-engineered comparison. Simpler: include `run_started_at_iso` in the payload alongside the human form).
    - Empty states: 1) no deployments at all → "No workflow runs yet. Import a repository or trigger an Action."; 2) filtered to nothing → "No deployments match these filters." with a clear-filters CTA.

- **Detail drawer** (`Pages/Deployments/DeploymentDrawer.vue` — co-located component):
    - Triggered by clicking a row. Slides in from the right (Tailwind `translate-x` transition, `aria-modal="true"`, focus-trapped).
    - Body content: workflow name + run number (heading), repository chip, status + conclusion badges, branch + head SHA (mono, `git log`-style first 7 chars + tooltip with full SHA), actor, event, "Started Xm ago" + `Updated Xm ago` + (if completed) `Completed Xm ago` + computed duration (`run_completed_at - run_started_at` rendered as `4m 12s`), a primary CTA "Open on GitHub" linking to `html_url`.
    - Closes on Escape, on backdrop click, on a top-right close button. Restores focus to the timeline row that opened it.
    - Hydrates from the row data already in the timeline payload — no separate API call. If a future spec needs richer per-run data (logs, jobs), introduce a `deployments.show` route; not needed for MVP.

- **TypeScript interfaces.** Mirror the existing pattern (`WorkflowRunRow` from `Repositories/Show.vue` — copy the shape; consider hoisting to `resources/js/types/` if a third consumer arrives, but don't preemptively centralize for two).

- **Authorization.** `auth` + `verified` middleware on the route group, like the rest of the user-scoped pages. The query already scopes to the authenticated user's repositories — no separate policy needed for MVP. (No multi-tenant team scoping yet — same phase-1 simplification all of phase 2/3 carries.)

- **Real-time refresh via Reverb.**
    - New event `App\Events\WorkflowRunUpserted` implementing `ShouldBroadcastNow` (mirrors spec 019's `ActivityEventCreated` shape).
    - Broadcasts on `PrivateChannel("users.{ownerUserId}.deployments")` — the project owner is the source of truth in phase-1 (multi-team scoping arrives with teams). The owner id is resolved from `Repository::project->owner_user_id` at dispatch time, NOT inside the event class — keeps the event self-contained for serialization.
    - `broadcastWith()` returns just `{ run_id, repository_id }` — a light-weight pulse, NOT the full row. The client uses this as a trigger to reload its timeline; trusting client-side merging would mean replicating the entire filter logic in JS, which we explicitly want to avoid.
    - Dispatched by `WorkflowRunWebhookHandler::upsertWorkflowRun()` after the upsert lands. **Not** dispatched by `SyncRepositoryWorkflowRunsAction` — bulk REST backfills can land 100 rows in a tick and would flood the channel; live deliveries are the only thing the user expects to see "appear."
    - `routes/channels.php` adds `Broadcast::channel('users.{userId}.deployments', fn (User $auth, int $userId) => $auth->id === $userId)` — same per-user gate as spec 019's `users.{userId}.activity`.
    - Vue side: `Pages/Deployments/Index.vue` subscribes via `window.Echo.private("users.{$page.props.auth.user.id}.deployments")` on mount, listens for `.WorkflowRunUpserted`, and on any incoming pulse calls `router.reload({ only: ['deployments'], preserveScroll: true, preserveState: true })`. The server re-applies the current filter state from the URL — runs that don't match the active filter naturally don't appear, no client-side filter replication needed.
    - `onBeforeUnmount` leaves the channel; reuse the cleanup pattern from `useActivityFeed`.
    - The "Refresh" button stays — it covers offline reconnect / WebSocket-loss scenarios and gives users a tactile fallback.

- **Tests:**
    - `DeploymentTimelineQueryTest` — scoping (only own repos), each filter applies independently, filters compose, ordering, cap at 100.
    - `DeploymentControllerTest` — Inertia component name + payload presence (`deployments`, `filters`, `filterOptions`); requires auth + verified; filters echoed from query string.
    - `WorkflowRunUpsertedTest` — `ShouldBroadcastNow`, broadcasts on the right channel for the run's owner, broadcastWith payload contains `run_id` + `repository_id`.
    - Extend `WorkflowRunWebhookHandlerTest` with a `Event::fake([WorkflowRunUpserted::class])` assertion that the event is dispatched on upsert, and *not* dispatched when the repository isn't imported.
    - `tests/Feature/Broadcasting/DeploymentsChannelAuthTest.php` — owner is authorized for `users.{ownId}.deployments`; another user is rejected.
    - Manual smoke note in the work log — drawer keyboard nav (Esc / focus trap) + a real webhook delivery actually nudging the timeline, verified by hand since browser-side WebSocket subscription isn't trivially asserted.

**Out of scope:**

- The Overview success-rate KPI card → spec 022.
- Broadcasting from `SyncRepositoryWorkflowRunsAction` — bulk REST backfills would flood the channel; live webhook deliveries are the only "appear" surface the user expects.
- Deeper drawer content (logs, jobs, step-by-step duration) — link out to GitHub for now.
- "Re-run" / "Cancel" buttons — write-direction GitHub Apps work, phase 9 polish at earliest.
- Cursor pagination beyond 100 rows.
- Saved filter presets.
- A separate `pipelines.index` route — the existing sidebar entry stays disabled.
- Switching the existing per-repo "Workflow Runs" tab on `Repositories/Show.vue` to share the new drawer — that tab links out to GitHub already; consolidating UX is a polish pass.

## Plan

1. **Query**: `DeploymentTimelineQuery` + tests (scoping, each filter, ordering, cap).
2. **Controller**: `DeploymentController::index`. Validate filter inputs (whitelist enum values, integer bounds). Resolve filter options (user's projects + repos, lightweight). Render Inertia.
3. **Route**: `routes/web.php` adds `deployments.index` inside the existing `auth + verified` middleware group.
4. **Sidebar**: flip `Deployments` from disabled → linked to `deployments.index`.
5. **Broadcast event**: `WorkflowRunUpserted` (`ShouldBroadcastNow`, private user channel, `{ run_id, repository_id }` payload) + `routes/channels.php` per-user authorization.
6. **Wire the event** to fire from `WorkflowRunWebhookHandler::upsertWorkflowRun()` (not from the sync action).
7. **Vue page** (`Pages/Deployments/Index.vue`): header, filter strip, timeline list, day grouping, empty states, Echo subscription that triggers a partial reload on incoming events.
8. **Drawer** (`Pages/Deployments/DeploymentDrawer.vue`): co-located component, prop-driven, keyboard accessible.
9. **Manual smoke** in browser (drawer focus trap, filter composition, refresh button, group-by-day visuals, real webhook delivery actually nudging the timeline).
10. **Self-review pass via `superpowers:code-reviewer`**.
11. **Open the PR** with the standard body shape.

## Acceptance criteria
- [ ] Sidebar `Deployments` entry is enabled and routes to `/deployments`. `Pipelines` entry stays disabled.
- [ ] `/deployments` is gated to `auth` + `verified`.
- [ ] Timeline lists workflow runs from the authenticated user's repositories only.
- [ ] Sort is `run_started_at desc` (with `github_id desc` tie-break) — matches the per-repo tab.
- [ ] Filters: project, repository, status, conclusion, branch all apply independently and compose.
- [ ] Filter state is reflected in the URL query string and survives reload.
- [ ] Repository dropdown narrows to the selected project's repos client-side.
- [ ] Timeline rows show: status dot, workflow name + run number, repository chip (project color), branch, event, actor, relative time, conclusion / status badge, link out to GitHub.
- [ ] Day separator headers segment the timeline.
- [ ] Empty states cover "no deployments at all" + "no matches for current filters" (with a clear-filters CTA).
- [ ] Clicking a row opens the detail drawer; Esc / backdrop / close button dismisses it; focus restores to the trigger row.
- [ ] Drawer body shows: workflow + run number, repository chip, status + conclusion, branch + head SHA (short + tooltip), actor, event, started / updated / completed timestamps + computed duration (e.g. `4m 12s`), "Open on GitHub" CTA.
- [ ] Refresh button performs a partial Inertia reload of just the timeline + filterOptions (preserves filter state).
- [ ] `WorkflowRunUpserted` event implements `ShouldBroadcastNow` and broadcasts on `users.{ownerUserId}.deployments` with payload `{ run_id, repository_id }`.
- [ ] Channel authorization in `routes/channels.php` rejects users other than the project owner.
- [ ] `WorkflowRunWebhookHandler::upsertWorkflowRun()` dispatches the event after the upsert; `SyncRepositoryWorkflowRunsAction` does NOT.
- [ ] Vue page subscribes via Echo on mount, leaves on unmount, and partial-reloads the timeline on each incoming event (server-side filter logic re-applies naturally — no client-side merge).
- [ ] Pint + `php artisan test` (full suite) + `npm run build` clean. CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before opening the PR.

## Files touched
- `app/Domain/GitHub/Queries/DeploymentTimelineQuery.php` — new.
- `app/Http/Controllers/DeploymentController.php` — new.
- `app/Events/WorkflowRunUpserted.php` — new (broadcast event).
- `app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php` — dispatch the broadcast event from `upsertWorkflowRun()`.
- `routes/web.php` — `deployments.index` route + `DeploymentController` import.
- `routes/channels.php` — `users.{userId}.deployments` per-user authorization.
- `resources/js/Components/Sidebar/Sidebar.vue` — flip `Deployments` entry to linked.
- `resources/js/Pages/Deployments/Index.vue` — new (with Echo subscription).
- `resources/js/Pages/Deployments/DeploymentDrawer.vue` — new.
- `tests/Feature/Deployments/DeploymentTimelineQueryTest.php` — new.
- `tests/Feature/Deployments/DeploymentControllerTest.php` — new.
- `tests/Feature/Events/WorkflowRunUpsertedTest.php` — new (broadcast contract).
- `tests/Feature/Broadcasting/DeploymentsChannelAuthTest.php` — new (channel authorization).
- `tests/Feature/GitHub/Webhooks/WorkflowRunWebhookHandlerTest.php` — extend with `Event::fake` assertion that `WorkflowRunUpserted` dispatches on upsert and not when repo isn't imported.
- `specs/README.md` — phase 4 tracker.
- `specs/phase-4-deployments-cicd/README.md` — task tracker.

## Work log
Dated notes as work progresses.

### 2026-04-30
- Spec drafted.
- User asked to add real-time refresh — added Reverb broadcast (`WorkflowRunUpserted`) dispatched from the webhook handler upsert path; bulk REST sync deliberately does NOT broadcast to avoid channel flooding on backfill. Vue listens via Echo and triggers a partial Inertia reload, letting server-side filter logic re-apply.
- Opened issue [#63](https://github.com/Copxer/nexus/issues/63) and branch `spec/021-deployment-timeline-ui` off `main`.

## Decisions (locked 2026-04-30)
- **`Deployments` sidebar entry, not `Pipelines`.** The sidebar already reserves both. Pipelines stays disabled as a CI-only future filter view.
- **Drawer overlay, not a dedicated route.** Hydrates from row data; no `deployments.show` until per-run logs / jobs need it.
- **URL-bound filters.** Shareable + reload-stable. Client-side dropdown dependency between project + repository.
- **No environment filter.** `workflow_runs` doesn't carry an environment column; `event` is the closest proxy and good enough for phase-1.
- **Real-time refresh via Reverb.** The webhook handler dispatches a per-user `WorkflowRunUpserted` event on every upsert; the page partial-reloads on receipt. Server-side filter re-applies naturally — no client-side merge logic to maintain. Bulk REST sync does NOT broadcast (would flood the channel on backfill).
- **Failed-deployment activity events** are already wired by spec 019's `WorkflowRunWebhookHandler`. Verify in this spec; do not re-implement.
- **Cap at 100 rows.** Mirrors the per-repo tab. Cursor / "load more" lands when a real user complains.

## Open questions / blockers
- **`run_started_at_iso`** in the payload for client-side day grouping — confirm during implementation that it's clean to add alongside the human-form, or whether we render the timeline group headers server-side.
- **Project chip color tokens.** Existing `projectAccentClass` map in `Repositories/Show.vue` — extract to `resources/js/lib/projectColors.ts` if a third consumer (this page) makes it earn its keep, otherwise duplicate the small map.
