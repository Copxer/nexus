---
spec: app-shell
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/7
branch: spec/004-app-shell
---

# 004 — AppLayout + Sidebar + TopBar + RightActivityRail

## Goal
Build the futuristic three-column shell — left sidebar, main content area, right activity rail — that every authenticated page renders inside. After this spec, the Overview placeholder should sit inside chrome that visually matches `nexus-dashboard.png`: glass sidebar with a vertical nav, glass top bar with a search field + actions row, glass right rail ready for the activity feed.

Roadmap reference: §7.5 Layout, §7.6 Navigation Structure, §11.3 App Layout, §22.3 Sidebar Style, §22.4 Top Bar Style.
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png).

## Scope
**In scope:**
- A new `AppLayout.vue` that composes `Sidebar`, `TopBar`, an `AppMain` slot, and `RightActivityRail`. Replaces `AuthenticatedLayout.vue` (which was the Breeze top-nav default, now obsolete).
- `Sidebar.vue` (~240 px on desktop):
  - NEXUS wordmark + cyan-glow logo at the top.
  - Vertical nav with **all 11 sections** from §7.6 (Overview, Projects, Repositories, Issues & PRs, Pipelines, Deployments, Hosts, Monitoring, Analytics, Alerts, Settings) using Lucide icons. Only `Overview` is a real link this spec; the rest get a small "Soon" pill and `aria-disabled` so they're visually present but inert.
  - User card pinned to the bottom (avatar initials, name, email, log-out / profile dropdown).
  - "System status" indicator above the user card — flat green dot + "All systems online" placeholder text. Real data lands later.
- `TopBar.vue`:
  - Page title (slot-driven so each page can fill it).
  - Global search input (visually present, no behavior — wired in spec 005 via the command palette).
  - Time-range selector pill (visual only — `24h` default, no behavior).
  - Notifications bell with a mock count badge — opens nothing in this spec.
  - User avatar (mirrors the sidebar user card; clickable opens the same dropdown). Reuse the existing `Dropdown` component.
- `RightActivityRail.vue` (~320 px on desktop):
  - Header "Activity" + a filter pill placeholder.
  - Empty state: "Once integrations are connected, recent events will stream here." (the real feed comes in spec 007).
- Responsive behavior (full polish lands in spec 008, but shell must not break on smaller screens):
  - Desktop ≥ `lg`: sidebar + main + right rail visible.
  - Tablet (`md`–`lg`): sidebar shown, right rail collapses into a button + slide-over drawer triggered from the top bar.
  - Mobile (< `md`): sidebar collapses behind a hamburger, right rail hidden.
- Install **`lucide-vue-next`** for icons. Use it on the sidebar nav, top bar action buttons, and the activity rail header. We'll keep using it through phase 0 and beyond.
- Update `Overview.vue` to render its content inside the new `AppLayout`.
- Update the Breeze profile pages (`Pages/Profile/Edit.vue`, partials) to use `AppLayout` so they don't reference the deleted `AuthenticatedLayout`.

**Out of scope:**
- Real data anywhere. Activity rail is empty, system status is placeholder, notifications count is mock or zero, search does nothing.
- The command palette (`Cmd + K`) — spec 005.
- Real KPI cards / charts / map / heatmap on `Overview` — spec 006.
- Theme toggle (light mode is deferred to phase 9).
- Drag-resize / collapse-to-icon-only sidebar polish (basic responsive collapse only).
- Repainting `Pages/Profile/**` body content — only the layout wrapper is updated; the form internals stay as-is until they get their own pass.

## Plan
1. Install `lucide-vue-next`. Verify a sample import builds (`vue-tsc` happy, Vite happy).
2. Build the smallest pieces first:
    - `Sidebar/SidebarNavLink.vue` — supports `active`, `disabled` (with a soft "Soon" pill), an icon slot.
    - `Sidebar/SidebarSystemStatus.vue` — placeholder dot + label + microcopy.
    - `Sidebar/SidebarUserCard.vue` — avatar circle (initials), name + email, kebab menu trigger that reuses `Dropdown` for Profile / Log out.
3. Compose `Sidebar.vue`.
4. Build `TopBar/TopBarSearch.vue` and `TopBar/TopBarActions.vue`. Compose `TopBar.vue`.
5. Build `RightActivityRail.vue` with its empty state.
6. Build `AppLayout.vue` that arranges them in a CSS Grid: `[sidebar] [main] [rail]` on desktop, with breakpoints for tablet/mobile.
7. Migrate consumers:
    - `Overview.vue` → `AppLayout`.
    - `Pages/Profile/Edit.vue` → `AppLayout`.
    - Delete `AuthenticatedLayout.vue` (no remaining consumers).
8. Apply z-index ladder per the stacking-context memory (sidebar `z-30`, top bar `z-30`, drawers `z-40`).
9. Smoke-test responsive: resize from desktop → tablet → mobile in the dev server and verify the rail/drawer transitions.
10. Verify pipeline (Pint, build, tests).
11. Self-review with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `AppLayout.vue` exists and renders sidebar + main + activity rail in a 12-column desktop grid that matches the visual reference's three-column composition.
- [ ] Sidebar shows the **11 nav items** from §7.6 in order, each with a Lucide icon. `Overview` is the only active link; the rest carry a "Soon" pill and are `aria-disabled`.
- [ ] Sidebar's bottom region shows the system status indicator + the user card; clicking the user-card menu opens a dark-glass dropdown with Profile / Log out (re-uses the existing `Dropdown` component).
- [ ] Top bar shows the page title, a global search input, a `24h` time-range pill, a notifications bell, and a user avatar. None of these are wired to backend functionality yet.
- [ ] Right activity rail renders with an empty-state message; on tablet it collapses into a button + slide-over drawer; on mobile it's hidden.
- [ ] Sidebar collapses behind a hamburger on mobile.
- [ ] `Overview.vue` and `Pages/Profile/Edit.vue` render inside `AppLayout`. `AuthenticatedLayout.vue` is deleted.
- [ ] `lucide-vue-next` is installed; tree-shaken icons end up in the build.
- [ ] No regressions: 25/25 tests still pass, Pint clean, `npm run build` green, CI green on the PR.
- [ ] No `gray-*` / `indigo-*` / `red-*` / `green-*` Tailwind classes leak into the new components — tokens only.

## Files touched
- (filled in as work progresses)

## Work log

### 2026-04-27
- Spec drafted. Verified `main` includes spec 003 tokens, the welcome-landing PR (#6) is merged, and `AuthenticatedLayout.vue` is the only Breeze-shipped layout still in use.

## Open questions / blockers
- **Where do disabled nav items go on click?** Initially I'll prevent navigation entirely (`aria-disabled` + `pointer-events-none` on the link). If the user prefers a "coming soon" splash page route, we wire that in a later spec.
- **Profile/Log Out dropdown placement.** The visual reference shows the user card at the bottom of the sidebar AND a user avatar in the top right. Both will be clickable. The sidebar version is the primary; the top-right is a convenience shortcut. Both open the same dropdown.
- **Theme toggle.** Roadmap §22.4 lists a theme toggle in the top bar. We're dark-first, so no toggle this spec — it would imply a working light mode.
