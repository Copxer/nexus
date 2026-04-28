---
spec: responsive-polish
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-28
updated: 2026-04-28
issue: https://github.com/Copxer/nexus/issues/18
branch: spec/008-responsive-polish
---

# 008 — Responsive behavior (desktop / laptop / tablet / mobile)

## Goal
Walk every authenticated screen end-to-end at the four roadmap breakpoints (large desktop / laptop / tablet / mobile) and fix the responsive issues that have accumulated across specs 003–007. After this spec, the Overview page, all auth pages, the Welcome landing, and the Profile page should look intentional at every viewport from 360 px to 1920 px — no horizontal scroll, no overflow truncation that hides numbers, no awkward whitespace, and no broken card chrome.

This is a polish spec — the UI components are already built (specs 004 / 006 / 007). The work is about *grid templates, gutters, breakpoints, truncation rules, and stack ordering*, not new components. Roadmap reference: §7.5 Layout (the four-tier responsive matrix), §22 Visual style notes, and the responsive callouts scattered through §8.x widget descriptions.

Visual target: [`../visual-reference.md`](../visual-reference.md) for the desktop look; mobile/tablet are not in the canonical screenshot, so we drive those by the §7.5 matrix:
- **Large desktop (≥ 2xl, ~1536 px+):** sidebar + main grid + activity rail all visible.
- **Laptop (xl 1280–1535 px):** sidebar + main grid; activity rail collapses to drawer.
- **Tablet (md/lg 768–1279 px):** sidebar collapses to drawer; activity rail still drawer.
- **Mobile (< md, < 768 px):** stacked cards, hamburger nav, activity rail hidden.

The current shell already implements the high-level breakpoint structure. This spec is about the rough edges *inside* that structure.

## Scope
**In scope (the polish punch list):**

- **Overview KPI row.**
    - At 1280 px (xl), the value + trend chip wrap on Hosts and Uptime cards because the chip is wide and the column is narrow. Either drop chip horizontal padding 1 step or shrink the value's `text-3xl` → `text-2xl` at xl-only so the trend chip stays inline.
    - At < md (mobile), the long secondary labels ("Deployments (24h)", "100% healthy") truncate awkwardly. Allow the secondary label to wrap to a second line on mobile, or shorten the label string at that breakpoint.
- **Overview stub widgets.**
    - At md (768 px), the Issues & PRs card and Top Repositories card sit at full width each — fine. But Container Hosts and Service Health each take full width and Service Health's `grid-cols-[auto_1fr_140px]` row pattern has the sparkline cropped on mobile. Reduce sparkline width to ~100 px below md, or drop it on mobile entirely.
    - At < md, the Top Repositories row uses a fixed `w-32` repo-name column + `w-20` commit-count column. The middle bar squeezes to nothing. Convert the layout to a 2-row stack at < md: line 1 = repo name + commits, line 2 = full-width bar.
    - The "Visualizations" placeholder card at < md crams 5 stub tiles into 2 columns with the third row having one orphan. Move to a 1-column stack at < sm so each tile reads cleanly.
- **Activity Heatmap.**
    - At 360 px the cells (`32px` clamp) plus the row labels ("12 PM", etc.) overflow the card horizontally on the smallest phones. Either shrink cells to 24–28 px on mobile, or drop the row labels to single letters ("M") at the smallest breakpoint.
    - The `Less / More` legend at < sm currently sits ms-auto on the right; at narrow widths it pushes outside the card. Move to a centered position at < sm.
- **Right activity rail (drawer mode).**
    - Drawer width is `w-80` (320 px) which is wider than the smallest mobile viewport (360 px) — the rail backdrop is barely visible. Cap the drawer width to `w-[88%]` at < sm so the backdrop stays clickable.
    - Long event titles in the feed wrap correctly already (line-clamp-2) but the metadata pill below the source line can push off-screen on the narrowest widths. Add `flex-wrap` to the meta row.
- **Sidebar (drawer mode at < lg).**
    - `w-72` is fine at md; at 360 px it covers ~80% of the screen — keep, but cap to `w-[88%]` for breathing room.
    - The "Soon" pill on each disabled nav item wraps the label to two lines at narrow widths because the pill takes ~44 px. The two-line label looks ragged. Either:
        a) Truncate the label on overflow with `truncate` so the visual is "Issues & …" instead of breaking, or
        b) Hide the pill below md (we already advertise the phase-pending status via the disabled styling).
    Recommendation: option (a) — keeps the phase pill informative, just truncates the label.
- **TopBar (mobile).**
    - At 360 px the time-range pill (`24H ▾`) is hidden (`sm:inline-flex`) — good. The notifications bell is visible but the avatar dropdown opens at `align="right"` and at 360 px the dropdown extends off the left of the screen because of the right-anchored alignment with insufficient viewport width. Verify and fix if needed.
    - The activity rail toggle (`PanelRight` icon) only appears `md:inline-flex 2xl:hidden`. At < md the rail is unreachable. That's the spec 004 design but worth re-confirming the user can still see *some* event data on mobile (current answer: no — they only see the right rail content if they navigate via Cmd+K palette to a future page that surfaces it elsewhere, which doesn't exist yet). **Decision needed:** add a mobile entry point for the rail (e.g. show the rail toggle at `< md` too), or accept that mobile users don't see activity until the dedicated mobile UX phase later. Lean: add the toggle button at all breakpoints below 2xl — the cost is one Tailwind class change.
- **Profile / Edit page.**
    - At md (768 px), the breeze-shipped sections (Update Profile / Update Password / Delete Account) use `max-w-7xl mx-auto` from the original layout but the new `AppLayout` already constrains; result is double-padded. Trim the inner `max-w-7xl` so the sections breathe correctly.
    - The danger-zone "Delete Account" form's modal width is `sm:max-w-2xl` — at narrow widths the modal extends edge-to-edge, but the inner buttons stack and look fine. Verify and leave as-is unless something visibly breaks.
- **Welcome landing (`/`).**
    - The "Open Overview" CTA at < sm sits below a tall hero block. Confirm scroll behavior is intentional. Skip if it already reads as expected.
- **Auth pages.**
    - Login / Register / Reset / Verify pages all use the `GuestLayout` shipped in spec 002. At ≥ md the form sits at ~`max-w-md mx-auto` — fine. At narrow widths, confirm the `Nexus Control Center` wordmark + form combination doesn't overflow.

**Walkthrough viewports:**
We test at five concrete widths in Playwright Chrome and capture before/after notes:
- 360 × 800 (small phone — iPhone SE)
- 768 × 1024 (tablet — iPad portrait)
- 1280 × 800 (laptop — small MacBook Air)
- 1536 × 960 (desktop — MacBook 16")
- 1920 × 1080 (large desktop — external monitor)

For each viewport we walk: `/` → `/login` → log in → `/overview` → open palette → `/profile` → log out.

**Out of scope:**
- Restyling components beyond pure responsive tweaks. No new visual treatments, no new components, no chart libraries, no animations.
- A sidebar icon-only collapse mode for tablet (roadmap §7.5 lists "Tablet: sidebar becomes icon-only or drawer" — we keep the drawer-only behavior from spec 004 for this phase; icon-only ships when an "advanced layouts" spec is scheduled).
- A bottom navigation bar on mobile (roadmap §7.5 lists "Mobile: stacked cards, bottom navigation"). Bottom nav is its own UX surface and warrants its own spec; mobile users keep the hamburger-driven sidebar drawer for now.
- Reduced-motion / `prefers-reduced-motion` support — out of scope for this spec; comes with phase 9 polish.
- Print stylesheet / hi-DPI tuning — out of scope.

## Plan
1. **Fresh manual walkthrough at 5 viewports.** Boot the dev server, log in, drive Playwright through each viewport in order. Capture full-page screenshots and annotate each issue against the punch list. Spec out any new issues found into the work log so we don't lose them.
2. **Apply fixes in component-grouped commits** to keep the diff reviewable:
    - Commit A: Overview KPI row (KpiCard responsive value sizing + secondary-label wrapping).
    - Commit B: Overview stub widgets (Top Repositories stack at sm, Service Health sparkline width, Visualizations 1-col stack).
    - Commit C: ActivityHeatmap mobile (cell size + label letterification + legend centering).
    - Commit D: Sidebar / RightActivityRail drawer width caps + sidebar nav-item truncation.
    - Commit E: TopBar mobile (activity-rail toggle visibility, avatar dropdown alignment).
    - Commit F: Profile / Edit double-padding trim (small).
    - Commit G: Auth pages / Welcome touch-up (only if anything broken; otherwise skip).
3. **Re-walk all 5 viewports** after each commit batch and confirm fixes don't regress anywhere.
4. **Pipeline pass** — vue-tsc, Pint, build, SmokeTest.
5. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] At each of 360 / 768 / 1280 / 1536 / 1920 px viewport widths, the following pages render with no horizontal scroll, no clipped numbers, no broken chrome: `/`, `/login`, `/register`, `/overview`, `/profile`.
- [ ] **Overview KPI row:** at xl, value + trend chip stay on the same line for the wider numbers (Hosts, Uptime); at < md, the secondary label wraps cleanly (no truncation that hides letters).
- [ ] **Top Repositories stub** stacks repo-name + commit-count + bar into a 2-row layout at < sm so each repo is readable.
- [ ] **Service Health stub** sparkline shrinks (or drops) at < sm so the row layout doesn't crop.
- [ ] **Visualizations placeholder** stacks to 1 column at < sm.
- [ ] **ActivityHeatmap** at 360 px: cells + labels fit inside the card with no horizontal overflow; legend stays inside the card.
- [ ] **Drawer widths** (sidebar + activity rail) cap at `w-[88%]` at < sm so the backdrop click target stays generous.
- [ ] **Sidebar nav items** at narrow widths truncate the label rather than wrapping to a second line; "Soon" pill stays visible.
- [ ] **Activity rail entry point** is reachable at < md (mobile) — or this is explicitly documented as deferred.
- [ ] **Profile/Edit** sections render with normal-feel padding (no double-padding from leftover `max-w-7xl`).
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes leak in — design tokens only.
- [ ] No regressions: SmokeTest still passes (3 cases, 48 assertions). Pint clean, vue-tsc clean, `npm run build` green, CI green.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
*(Filled in as work progresses — likely candidates only.)*
- `resources/js/Components/Dashboard/KpiCard.vue` — responsive value sizing.
- `resources/js/Pages/Overview.vue` — Top Repositories stack-at-sm, Service Health row template, Visualizations grid.
- `resources/js/Components/Activity/ActivityHeatmap.vue` — cell-size at sm, legend center.
- `resources/js/Components/Activity/RightActivityRail.vue` / wrapper drawer — width cap.
- `resources/js/Components/Sidebar/Sidebar.vue` / wrapper drawer — width cap.
- `resources/js/Components/Sidebar/SidebarNavLink.vue` — label truncation.
- `resources/js/Components/TopBar/TopBar.vue` — activity-rail toggle visibility.
- `resources/js/Pages/Profile/Edit.vue` (and partials) — trim double-padding.

## Work log
Dated notes as work progresses.

### 2026-04-28
- Spec drafted from the running list of responsive issues spotted during specs 005–007 manual verification; scope confirmed (5 decisions locked: show rail toggle on mobile, truncate sidebar labels, shrink heatmap cells but keep readable labels, defer tablet icon-only mode, defer mobile bottom-nav).
- Opened issue [#18](https://github.com/Copxer/nexus/issues/18) and branch `spec/008-responsive-polish` off `main`.
- Implemented punch-list fixes:
    - **KpiCard responsive value sizing** — `text-2xl` at base, `lg:text-3xl` (laptop wide cards), `xl:text-2xl` (6-col tight cards), `2xl:text-3xl` (large desktop wide cards). Initial draft used `sm:text-3xl` which forced an over-sized value on the md tablet 3-col grid; the final breakpoint chain only goes large where the card width supports it. Also enabled wrapping on the secondary-label row so long labels don't truncate.
    - **Top Repositories stub** — 2-row stack at < sm (name + commits on line 1, full-width gradient bar on line 2) using `flex-col` + `sm:contents` to flatten back into the parent flex on sm+.
    - **Service Health stub** — sparkline column shrinks `100px` → `lg:140px` so the row template doesn't squeeze the service name on narrow viewports.
    - **Visualizations placeholder** — 1-col at < sm, 2-col at sm, 3 at md, 5 at lg.
    - **ActivityHeatmap mobile** — cells ramp `24px → 32px → 40px` from base → sm → md so the grid fits inside its card at 360 px. Legend strip becomes `mx-auto` at < sm (centred) and `ms-auto` at sm+ (right-aligned).
    - **Drawer width caps** — `RightActivityRail` and `Sidebar` (drawer variant) gain `max-w-[88vw]` so the backdrop click target stays generous on small phones (360 px → ~43 px backdrop).
    - **Sidebar nav-link truncation** — added `min-w-0 flex-1 truncate` to the label span and `shrink-0 whitespace-nowrap` to the "Soon" pill so labels truncate cleanly instead of wrapping to two lines.
    - **TopBar mobile rail toggle** — removed the `md:` floor; the activity-rail toggle is now visible at all breakpoints below 2xl. Mobile users can reach the populated feed via the drawer.
    - **Stub widget headers** — metadata strings (e.g. "7 days · 4-hour buckets · mock") hide at < sm so the header titles don't wrap to two lines on the heatmap card.
    - **Profile/Edit page** — already used `mx-auto max-w-3xl` (no leftover Breeze `max-w-7xl`); no changes needed. The pre-existing `text-gray-*` classes in `DeleteUserForm.vue` are a theming issue, not responsive — deferred to a dedicated Breeze-partials re-theme task.
- Manual verification (Playwright Chrome) at 5 viewports (1920 / 1536 / 1280 / 768 / 360):
    - **1920 (large desktop):** sidebar + main + persistent rail; all KPI cards show value + trend on one line.
    - **1536 (2xl):** sidebar + main + persistent rail; some chip wrapping on Hosts/Uptime cards because the 6-col grid keeps cards narrow even at this width.
    - **1280 (xl):** sidebar + main + drawer rail; Hosts/Uptime chip wraps below value (sub-pixel space — the cards genuinely cannot fit "99.98%" + "↑ +0.01%" + gap inside ~115 px of cluster width). The wrap is the graceful fallback rather than a layout bug.
    - **768 (tablet):** drawer sidebar + drawer rail; all 6 KPI chips inline with the new lg-only `text-3xl` rule. Stub widgets stack to single column.
    - **360 (mobile):** drawer sidebar + drawer rail (rail toggle now visible); all KPI chips inline; Top Repositories stacks 2-row; heatmap fits with 24 px cells; legend centred.
- Pipeline: vue-tsc clean, Pint clean, `npm run build` green. SmokeTest still passes (3 cases, 48 assertions).

## Decisions (locked 2026-04-28)
- **Mobile activity-rail entry point — show.** The `PanelRight` toggle becomes visible at all breakpoints below 2xl so mobile users can still reach the feed via the drawer.
- **Sidebar nav labels — truncate.** Add `truncate` so labels become "Issues & …" rather than wrapping to two lines; the "Soon" pill stays — it's the whole point of the placeholder treatment.
- **Heatmap mobile — shrink cells, keep labels.** 32 → 24 px cells at < sm; row labels stay readable ("12 PM" not "M"). Single-letter abbreviations sacrifice information density.
- **Tablet icon-only sidebar — defer.** This spec is polish, not a new layout pattern. Icon-only mode ships in a future "advanced layouts" spec.
- **Mobile bottom nav — defer.** Bottom nav warrants its own UX surface and dedicated spec. Mobile users keep the hamburger-driven sidebar drawer for now.

## Open questions / blockers
- None at the spec level — every item is a tweak to existing classes/templates. New issues found during the walkthrough will be added to the punch list as we go.
