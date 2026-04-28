# Phase 0 — Foundation

Source: [roadmap §19 Phase 0](../../nexus_control_center_roadmap.md) and §21 Steps 1–3.
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png)

## Phase goal
Stand up a Laravel 12 + Vue 3 + Inertia + Tailwind project with authentication, the futuristic dark layout, and an Overview page rendered with mock/static data. No real integrations yet.

## Tasks

| # | Task | Status |
|---|------|--------|
| 001 | Laravel 13 + Inertia + Vue 3 + TS scaffold | 🟢 |
| 002 | Auth scaffolding (login / register / verified email) | 🟢 |
| 003 | Design tokens + Tailwind theme (dark, glassmorphism, neon accents) | 🟢 |
| 004 | AppLayout + Sidebar + TopBar + RightActivityRail | 🟢 |
| 005 | CommandPalette (Cmd+K) shell | ⬜ |
| 006 | Overview page with mock KPI cards, sparklines, status badges | ⬜ |
| 007 | ActivityFeed + ActivityHeatmap components (mock data) | ⬜ |
| 008 | Responsive behavior (desktop / laptop / tablet / mobile) | ⬜ |
| 009 | Redis / Horizon / queue / scheduler wired up | ⬜ |

## Acceptance criteria (phase-level)
- [ ] User can register, log in, log out
- [ ] After login, user lands on `/overview`
- [ ] Overview page visually matches the futuristic concept (dark, glass cards, neon accents)
- [ ] All cards are responsive (desktop → mobile)
- [ ] No real integrations yet — Overview is fed by static/mock data
- [ ] `php artisan horizon`, `queue:work`, `schedule:work` all start cleanly in dev
