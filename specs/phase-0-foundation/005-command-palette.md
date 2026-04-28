---
spec: command-palette
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/9
branch: spec/005-command-palette
---

# 005 — CommandPalette (Cmd+K) shell

## Goal
Stand up the global command palette — a centered, glass-styled modal that opens on `Cmd+K` / `Ctrl+K` and lets the user fuzzy-filter and run navigation/actions from anywhere in the authenticated app. After this spec, the global search input in the top bar (placeholder since spec 004) becomes a real trigger, and the keyboard shortcut works on every page.

This is the **shell** spec — wiring + UX + accessibility + a small set of real commands. The full command catalog (project search, repo search, host opener, "Run sync", etc.) gets fleshed out later as those sections come online; for now those entries render with a "Soon" pill and are inert. This mirrors how the sidebar's nav was treated in spec 004.

Roadmap reference: §7.10 UX Pattern: Command Palette. Sidebar-nav treatment of "Soon" items mirrors §7.6 / spec 004.
Visual target: [`../visual-reference.md`](../visual-reference.md) → [`../../nexus-dashboard.png`](../../nexus-dashboard.png).

## Scope
**In scope:**
- A new `CommandPalette.vue` modal:
  - Centered, max-width ~640px, glass-card styling consistent with the rest of the shell.
  - Search input with a Lucide search icon and `Esc` hint pill on the right.
  - Filtered command list grouped by category (`Navigation`, `Actions`, `System`).
  - Each row: icon + label + optional keyboard-shortcut hint or "Soon" pill on the right.
  - Keyboard nav: `↑` / `↓` cycles items, `Enter` runs the highlighted command, `Esc` closes.
  - Mouse: hover sets the highlighted index; click runs the command.
  - Empty state: "No matching commands" when the filter returns nothing.
  - Backdrop click closes; `aria-modal="true"`, `role="dialog"`, focus trap, focus restored to the prior element on close.
- Global trigger:
  - `Cmd+K` (macOS) / `Ctrl+K` (other platforms) opens the palette from anywhere inside `AppLayout`.
  - Clicking the existing top-bar search input also opens the palette (replacing its current `readonly` placeholder behavior).
  - Listener attached at `AppLayout` level; ignored when the user is typing inside another `<input>` / `<textarea>` / `[contenteditable]` so it doesn't hijack form input — except for the top-bar search field itself, which deliberately triggers it.
- Command registry in TypeScript (`resources/js/lib/commands.ts`):
  - Strongly-typed `Command` shape (id, label, group, icon, keywords, run, disabled?, soon?).
  - Initial entries:
    - **Navigation:** Go to Overview *(real)*, Go to Profile *(real)*. The 9 placeholder sections from §7.6 (Projects, Repositories, Issues & PRs, Pipelines, Deployments, Hosts, Monitoring, Analytics, Alerts, Settings) appear with a "Soon" pill and are inert — same treatment as the sidebar.
    - **Actions:** Log out *(real, posts to `/logout`)*. Roadmap-listed actions that don't have backends yet (Create project, Connect GitHub, Run sync, View failed deployments, View slow websites) appear with "Soon" pills.
    - **System:** Toggle theme — listed as "Soon" (light mode is deferred to phase 9).
- Update `TopBar/TopBarSearch.vue` to act as a palette **trigger**, not a real input:
  - Stays visually a search field; clicking or focusing it opens the palette.
  - Right-side hint pill shows `⌘K` / `Ctrl K` based on platform detection (`navigator.platform` or `userAgent`).
  - `aria-keyshortcuts="Meta+K Control+K"`, `role="button"` since it now triggers a dialog.
- Fuzzy filter: substring + acronym match against `label` and `keywords` (no extra dependency — ~30 lines of TS). Sorted by match quality, with disabled/"Soon" items always sinking below the active matches.
- Tests:
  - Manual verification of the palette UX in the dev server (open/close, keyboard nav, click, disabled items inert, focus trap, focus restore). Notes captured in the Work log.
  - One Inertia feature test confirming `/overview` still 200s for an authenticated user (smoke check that nothing server-side regressed).
  - JS-level unit tests are **deferred** — Vitest isn't wired up in this repo yet. Adding it ships in a separate small chore PR after this spec, then the palette + fuzzy-match get retroactive unit tests.

**Out of scope:**
- Real backend-powered search (project / repo / host / alert lookups against the database). Comes in later phases when those sections ship.
- Recents / pinned / history. Add if it earns its keep later.
- A telemetry hook for "command run" events. Defer until we have an analytics story.
- Adding a third-party command-palette library — we roll a small in-house one to stay token-consistent and keep the bundle small. Re-evaluate if requirements expand.
- A secondary `/` shortcut to open the palette. Cmd+K only for now; revisit if it's missed.
- Theme toggle implementation — listed as a "Soon" entry only.
- Mobile-specific palette UX polish (we ensure it doesn't break on mobile; full responsive pass is spec 008).

## Plan
1. Create `resources/js/lib/commands.ts` with the `Command` type and a `getCommands(router)` factory that returns the initial registry. Keep it pure / framework-agnostic so it's easy to test.
2. Add a tiny fuzzy-match helper (`resources/js/lib/fuzzyMatch.ts`) — substring + acronym scoring. Unit-test it in isolation.
3. Build `CommandPalette.vue`:
    - Props: `open: boolean`. Emits: `close`.
    - Internal state: `query`, `highlightedIndex`.
    - Computed `filtered` — runs `fuzzyMatch`, partitions by group, keeps disabled items at the bottom of each group.
    - Keydown handlers wired to the input (`↑`/`↓`/`Enter`/`Esc`).
    - Focus trap + return-focus via a `ref` captured before opening.
    - Backdrop click + Esc → `emit('close')`.
4. Wire it into `AppLayout.vue`:
    - Add `paletteOpen` ref.
    - Window-level `keydown` listener (existing Escape listener pattern from spec 004 already lives here): if `(e.metaKey || e.ctrlKey) && e.key === 'k'`, prevent default, open the palette. Skip when target is a form field other than the top-bar trigger.
    - Render `<CommandPalette :open="paletteOpen" @close="paletteOpen = false" />` near the existing drawer markup.
5. Convert `TopBar/TopBarSearch.vue` from a `readonly` input into a button-shaped trigger:
    - Keeps the search-icon prefix and overall pill shape.
    - Right side: `⌘K` / `Ctrl K` hint pill (platform-detected once on mount).
    - `@click` and `@keydown.enter`/`@keydown.space` emit `open-palette` up to `TopBar` → `AppLayout`.
6. Run the dev server, manually verify:
    - `Cmd+K` opens; `Esc` closes.
    - Typing filters; `↑/↓/Enter` works; click runs.
    - Clicking the top-bar search opens the same palette.
    - Tabbing inside the open palette stays trapped; closing returns focus to the trigger.
    - Disabled "Soon" items don't fire on Enter or click.
7. Tests:
    - `tests/Feature/SmokeTest.php` — one assertion that `/overview` still 200s for an authenticated user (a tiny safety net; no new server code).
    - JS-level unit tests deferred to a follow-up "wire Vitest" chore PR.
8. Pipeline pass: Pint, `vue-tsc`, `npm run build`, `php artisan test`.
9. Self-review with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `Cmd+K` (or `Ctrl+K` on non-mac) opens the palette from any authenticated page.
- [ ] `Esc` closes; backdrop click closes; focus is restored to the prior element on close.
- [ ] Clicking the top-bar search field opens the same palette; the field shows a `⌘K` / `Ctrl K` hint pill.
- [ ] Filtering with the input narrows the list; matches highlight the matched substring (or are simply ranked higher — no requirement to render markup-level highlighting in this spec).
- [ ] `↑` / `↓` / `Enter` keyboard navigation works; mouse hover sets the highlighted index.
- [ ] **Real commands** that work end-to-end: Go to Overview, Go to Profile, Log out.
- [ ] **"Soon" commands** are listed but inert: Projects, Repositories, Issues & PRs, Pipelines, Deployments, Hosts, Monitoring, Analytics, Alerts, Settings, Create project, Connect GitHub, Run sync, View failed deployments, View slow websites, Toggle theme.
- [ ] Disabled commands cannot be triggered by Enter or click and have `aria-disabled="true"`.
- [ ] Palette has `role="dialog"`, `aria-modal="true"`, `aria-label="Command palette"`. Internal list has `role="listbox"`; rows have `role="option"` and `aria-selected` reflects the highlighted index.
- [ ] Focus is trapped inside the palette while open; Tab cycles input ↔ list (or stays on input — design choice noted in the work log).
- [ ] No `gray-*` / `indigo-*` / `red-*` / `green-*` Tailwind classes leak into the new components — design tokens only.
- [ ] No regressions: 25/25 tests still pass, Pint clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `resources/js/Components/CommandPalette/CommandPalette.vue` — new modal component.
- `resources/js/Components/CommandPalette/CommandPaletteRow.vue` — single command row (icon + label + right-side pill).
- `resources/js/lib/commands.ts` — typed registry + `getCommands(router)` factory.
- `resources/js/lib/fuzzyMatch.ts` — small substring + acronym scorer.
- `resources/js/Layouts/AppLayout.vue` — global keydown listener for `Cmd+K`, palette state, render `<CommandPalette />`.
- `resources/js/Components/TopBar/TopBarSearch.vue` — converted from `readonly` input to palette-trigger button.
- `resources/js/Components/TopBar/TopBar.vue` — bubble `open-palette` event from TopBarSearch up to AppLayout.
- `tests/Feature/SmokeTest.php` — light smoke test that `/overview` still renders.

## Work log
Dated notes as work progresses.

### 2026-04-27
- Spec drafted; scope confirmed with the user (4 decisions locked: defer JS tests, skip `/`, list "Soon" items, roll our own).
- Opened issue [#9](https://github.com/Copxer/nexus/issues/9) and branch `spec/005-command-palette` off `main`.

## Decisions (locked 2026-04-27)
- **JS test runner — defer.** Vitest is not wired up; adding it ships in a separate small chore PR right after this spec. This spec relies on manual verification + a PHP smoke test.
- **`/` as secondary trigger — skip.** Cmd+K only for now; revisit if missed.
- **"Soon" treatment — list, don't hide.** Unbuilt commands appear with a "Soon" pill, same as the sidebar nav from spec 004.
- **Library choice — roll our own.** No third-party command palette dep; ~150 lines of Vue with full token control. Re-evaluate only if requirements expand.

## Open questions / blockers
- **Trigger from input fields.** Standard pattern is to ignore `Cmd+K` while typing in another input. Profile-edit forms have inputs. Confirming that's fine — Cmd+K won't open while editing your profile, you'd Tab/Esc out first. (Matches GitHub / Linear / Vercel.)
