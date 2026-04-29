---
spec: activity-feed-ui
phase: 3-webhooks-activity
status: done
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/53
branch: spec/018-activity-feed-ui
---

# 018 — Activity Feed UI

## Goal
Surface the `activity_events` rows that spec 017's webhook handlers have been writing to the database. Right now those events sit silently — only the Overview page reads them, and every other authenticated page (Projects, Repositories, Settings, Profile, Work Items) shows the right-rail empty state. After this spec the **right rail is populated on every authenticated page via a shared Inertia prop**, and there's a dedicated `/activity` page for the full chronological list.

Roadmap reference: §8.10 Activity Feed, §11.3 App Layout, §22.6 Sidebar (the dormant **Alerts** entry is unrelated; the new entry is just an active-state for the existing **Activity** nav slot — we'll create that slot in this spec).

## Scope
**In scope:**
- `RecentActivityForUserQuery` — returns the **last 20** `activity_events` (newest first) scoped to repositories owned by the authenticated user's projects, mapped to the existing TS `ActivityEvent` shape (id / type / severity / title / source / occurred_at / metadata). Mapping logic is shared with the per-page reads (Overview, future contexts).
- `HandleInertiaRequests::share()` adds a top-level `activity.recent` key returning the same payload for every authenticated page. Anonymous / guest pages get an empty array.
- `AppLayout.vue` reads the shared prop as the **fallback** when a page doesn't pass `activityEvents` explicitly (Overview keeps its explicit pass-through unchanged for now — same data source, just kept explicit because Overview also needs the heatmap query off the same fetch). The right rail (column + drawer) auto-populates on every other authenticated page.
- `Pages/Activity/Index.vue` — dedicated activity page rendering the full feed (last **100** events) inside `AppLayout` plus a footer note explaining real-time arrives in spec 019.
- `ActivityController@index` returning `Inertia::render('Activity/Index', ['events' => ...])`. Route: `GET /activity` → `activity.index`. Auth + verified middleware (matches the rest of the app shell).
- `Sidebar.vue` activates the **Activity** nav item (currently "Pipelines" sits between Issues & PRs and Deployments — Activity isn't in §7.6's exact 11-item list, so we'll add it as a 12th, slotted between **Alerts** and **Settings**, with a `LayoutGrid`-or-similar Lucide icon). Mark it active for `/activity`.
- Tests:
   - Query: respects the user-scoping (events for repos in OTHER users' projects don't leak in).
   - Share middleware: anonymous → no `activity` key (or empty); authenticated → 20 newest.
   - `ActivityController@index` returns 100-cap, ordered, scoped.
   - Sidebar nav: Activity link is visible, points to `/activity`, gets `active` styling on that route.

**Out of scope:**
- **Real-time updates** — spec 019 adds Reverb broadcast on `ActivityEventCreated` and Echo wiring on the page. Spec 018 ships page-load fresh; the page poll is whatever Inertia partial reload mechanism the user already triggers (no auto-poll added here).
- **Activity filters** (by event type / repo / date range). Visible-only "Recent / All" tabs already exist on `ActivityFeed.vue`; real filtering arrives later (probably alongside the analytics phase).
- **Pagination** (cursor / "Load more"). Cap at 100 for now — fine for phase 3 dev. Pagination lands when activity volume grows, almost certainly with the alerts / monitoring phases.
- **Read/unread state** — out of scope (no notifications yet; that's phase 7's alerts engine).
- **Per-project activity tabs** — the Project Show page already has an Activity tab placeholder; populating it is a separate spec.

## Plan
1. Read the existing reads on Overview to understand the mapping currently in flight (the TS-shape transformation). Extract it to a single helper if needed so the new query + the existing Overview query don't drift.
2. Build `app/Domain/Activity/Queries/RecentActivityForUserQuery.php`. Accept a `User`, return `Collection<int, array>` (or DTO). Limit + scoping live here.
3. Wire the share in `HandleInertiaRequests`. Conditional on `$request->user()` so guests don't pay the query cost.
4. Touch `AppLayout.vue`: when `props.activityEvents` is empty AND `usePage().props.activity?.recent` exists, use the shared one. Compute that decision once via a `computed`. Keep `<RightActivityRail :events="…" />` callsite untouched.
5. Build `Pages/Activity/Index.vue`. Reuse `<ActivityFeed>` so we don't fork the styling. Add a small empty-state for "no events yet — connect a GitHub repository and create an issue."
6. Add the controller + route. Inertia render.
7. Activate the Sidebar "Activity" item — add a new `NavItem` with `routeName: 'activity.index'`, choose a Lucide icon (`Activity` is already used elsewhere — pick `Inbox` or `History`), and remove the `disabled: true` flag.
8. Tests: query scoping, controller render, share middleware, sidebar active state.
9. Pipeline (Pint, build, tests). Self-review. Open PR.

## Acceptance criteria
- [x] `RecentActivityForUserQuery` exists; results ordered by `occurred_at desc, id desc`; capped via a `limit` argument (`RAIL_LIMIT = 20`, `PAGE_LIMIT = 100`).
- [x] Events are scoped: user A's webhook deliveries don't appear in user B's right rail (proved by `test_users_only_see_their_own_events_via_shared_prop`).
- [x] `HandleInertiaRequests::share()` adds `activity.recent` for authenticated requests via a lazy closure; guests skip the query entirely.
- [x] `AppLayout.vue` populates the right rail on every authenticated page automatically — `resolvedActivityEvents` falls back to `usePage().props.activity?.recent` when the page doesn't pass `activityEvents` explicitly.
- [x] `GET /activity` renders `Pages/Activity/Index.vue` with up to 100 events scoped to the user.
- [x] Sidebar shows the **Activity** entry between Alerts and Settings, links to `/activity`, with the existing active-state treatment via `route().current('activity.index')`. Lucide icon: `History` (the `Activity` icon was already in use by Pipelines).
- [x] All existing tests still pass; new tests cover query scoping (3 cases), controller render (3 cases), and shared prop behaviour (2 cases).
- [x] Pint clean, `npm run build` green, `php artisan test` 216/216.

## Files touched
- `app/Domain/Activity/Queries/RecentActivityForUserQuery.php` — new. Single-source-of-truth read for both the rail and the page; includes the model→TS mapping (id prefix, repo full_name as `source`, `metadata` pill from the first known key).
- `app/Http/Middleware/HandleInertiaRequests.php` — added `activity.recent` shared prop, lazy closure so guests pay nothing.
- `app/Http/Controllers/ActivityController.php` — new. Single `index()` invocation for the dedicated page.
- `routes/web.php` — added `GET /activity` → `activity.index`. `ActivityController` import.
- `resources/js/Layouts/AppLayout.vue` — `resolvedActivityEvents` computed; both rail callsites switched.
- `resources/js/Components/Sidebar/Sidebar.vue` — added `Activity` nav item with `History` icon, between Alerts and Settings. Refreshed the order comment.
- `resources/js/Pages/Activity/Index.vue` — new dedicated page. Glass-card wrapper around `ActivityFeed`; empty state for first-time users.
- `resources/js/types/index.d.ts` — extended `PageProps` with `flash?` (was already shared but never typed) and `activity?: { recent: ActivityEvent[] }`.
- `tests/Feature/Activity/RecentActivityForUserQueryTest.php` — new (3 tests).
- `tests/Feature/Activity/ActivityControllerTest.php` — new (3 tests).
- `tests/Feature/Activity/SharedActivityPropTest.php` — new (2 tests, asserts via `/overview`).

## Work log

### 2026-04-29
- Spec drafted. Confirmed existing surface: `ActivityEvent` model + table, `CreateActivityEventAction`, `ActivityFeed.vue` + `ActivityFeedItem.vue`, `RightActivityRail.vue` already accept events, `AppLayout.vue` already forwards `activityEvents`. Discovered Overview's `recentActivity` is still **mock** (MOCK_ACTIVITY in `GetOverviewDashboardQuery`); the real-vs-mock split happens in the rail where the shared Inertia prop now serves real events.
- Built the query, wired the shared prop with a lazy closure (guests pay nothing), updated AppLayout's resolution, added the `/activity` route + page, activated the Sidebar entry. Tests cover scoping, ordering, mapping, controller render, 100-cap, auth gate, and shared-prop visibility from a third-party route (Overview).
- Pipeline: Pint clean, `npm run build` green, `php artisan test` 216/216 (was 208).

## Open questions / blockers
- **Sidebar slot ordering.** Roadmap §7.6 lists 11 items, no "Activity" entry — the activity feed lives in the right rail. But a dedicated `/activity` page deserves a sidebar entry. Plan: add it as a 12th item between **Alerts** and **Settings**. If the user prefers a different slot, adjust before implementing.
- **Activity icon.** `Activity` icon is already used by **Pipelines** in the sidebar. Will use `Inbox` or `History` to keep distinct visual identity. Settle during implementation.
