---
spec: command-palette
phase: 10
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-02
updated: 2026-07-02
---

# 043 — Global command palette: live entity search + recent commands

## Goal

Phase 0 shipped a solid `Cmd+K` command palette scaffold — fuzzy
matcher, keyboard navigation, ARIA, focus management, Teleport,
transitions, disabled "Soon" rows. But it only knows about **static
commands**: "Go to Overview", "Create project", "Log out." Type the
name of a real project, repository, alert, host, or website and it
returns "No matching commands." That's the gap spec 043 closes.

Roadmap §Phase 10 lists "Global command palette" as one of the
innovation deliverables. Solid palette UX is table stakes for a
platform ("Superhuman for GitHub" — the roadmap's own §1.4 aspiration).
The goal: a fresh operator hitting `Cmd+K` and typing three letters
of a project name lands on that project's page.

Roadmap refs: §Phase 10 Future Features ("Global command palette"),
§7.10 UX Pattern Command Palette (dropdown look + acronym matching +
recency), §3.2 Signal Over Noise (recent commands stay above
disabled/planned entries).

## Scope

**In scope:**

- **Live entity search — client-side, pre-loaded.** Extend the
  existing `getCommands()` registry so it can accept an entity
  bundle passed in from the shared Inertia prop. Entity kinds:
  - **Projects** — every project the user owns. `Command` shape:
    `label: 'Project · {name}'`, `keywords: [slug, description]`,
    `run: () => visit(project.show)`. Icon: `FolderKanban`.
  - **Repositories** — `label: 'Repo · owner/name'`, keywords
    include primary language + description snippet. Icon:
    `GitBranch`.
  - **Hosts** — `label: 'Host · {name}'`, keywords include host
    address + tag list. Icon: `Server`.
  - **Websites** — `label: 'Website · {url}'`, keywords include
    project name + status. Icon: `Globe`.
  - Each row's `run()` navigates to the entity's show page.

  These four kinds are pre-loaded via a shared Inertia prop
  because their combined row count is bounded (solo operator ≤ few
  hundred rows). Ship via
  `HandleInertiaRequests::share('palette.entities')` scoped
  per-user; the AppLayout is where the palette lives, so every
  page already carries the prop.

- **Live entity search — server-side, async, debounced.**
  Repositories issues/PRs (`work_items`) + Alerts scale past
  what a shared prop can carry (a single busy user can have
  hundreds of open work items + years of resolved alerts). Ship
  an async endpoint:
  - **`GET /palette/search?q=...`** returns up to 20 rows
    matching against titles / types. Response shape:
    `{ workItems: [...], alerts: [...] }`, each item a
    `{ id, title, subtitle, url, badge }` DTO.
  - **Client-side debounce** — 200ms after the last keystroke.
    Palette shows a subtle loading indicator while the request
    is in flight; results merge into the "Work items" and
    "Alerts" groups. Aborts the in-flight XHR if a new keystroke
    arrives (`AbortController`).
  - **Rate limit** — `throttle:30,1` on the endpoint per §5 of
    the operator checklist.

- **Recent commands surface.** LRU-track the last 5 successfully
  run **static** commands (not entity rows — entities are already
  bookmarks) in `localStorage['nexus:palette:recent']`. When the
  palette opens with an empty query, a "Recent" group appears
  above "Navigation." Clicking one runs the command + bumps it to
  MRU. Cap at 5 rows; older entries drop.

- **Group ordering.** New group order (when non-empty):
  `recent → navigation → actions → projects → repositories → hosts → websites → workItems → alerts → system`.
  Add the new groups to `CommandGroup` union + `commandGroupLabels`
  + `groupOrder` in `resources/js/lib/commands.ts`.

- **Result cap per group.** Cap at 8 rows per entity group so a
  single project's 40 repos can't push everything else off-screen.
  Overflow row: "Show all N repositories in {project}" → visits
  the filtered repositories index.

- **Empty-query default view.** When the query is blank:
  - **Recent** (up to 5), then
  - **Navigation** + **Actions** (full static list), then
  - **Skip entity groups.** Rendering hundreds of pre-loaded
    entities in the blank state would be noise. Only render
    entity groups when the query is non-empty.

- **Contrast + accessibility.**
  - Every new row keeps the same ARIA + keyboard-navigation
    contract as the existing scaffold. The palette already
    handles `role="listbox"` + `aria-activedescendant`.
  - Entity rows show a small subtitle line (e.g. "5 open issues"
    on a project row, "critical since 3m ago" on an alert row).
    Subtitle is `text-text-muted` at 11px.
  - Loading indicator for async results is `role="status"` +
    `aria-live="polite"` so screen readers announce it.
  - `prefers-reduced-motion: reduce` — palette already respects
    Tailwind transitions; add no new animations that would need
    a guard.

- **Server-side controller + query class.**
  - `App\Http\Controllers\PaletteSearchController` — one method,
    validates `q`, delegates to a query.
  - `App\Domain\Palette\Queries\SearchPaletteEntitiesQuery` —
    runs two scoped SQL queries (work items scoped to the user's
    projects, alerts scoped to the user's projects OR
    `AlertSource::System`), returns the merged DTO. Fuzzy
    matching happens PHP-side against `LIKE %q%` on the title
    columns — good enough at spec-1 scale; a `MATCH AGAINST`
    fulltext index is a follow-up if perf shows.
  - Route registered under `auth + verified` middleware, throttle
    30/min.

- **Shared Inertia prop.** Extend
  `HandleInertiaRequests::share()` to include a lightweight
  `palette.entities` bundle: `{ projects: [...], repositories:
  [...], hosts: [...], websites: [...] }`. Each row is
  `{ id, label, subtitle, keywords, url }`. Load-shed guard:
  only serialize when the user is authenticated (guests never
  see the palette).

- **Tests.**
  - `PaletteSearchControllerTest` — happy path returns
    matching work items + alerts, non-owner rows excluded, empty
    `q` returns empty payload (not a 500), throttling engaged
    after 30/min.
  - `SearchPaletteEntitiesQueryTest` — repository ownership +
    system alerts inclusion; LIKE-escape for `%`/`_` chars.
  - `SharedPalettePropTest` — shared prop present + correctly
    scoped to authenticated user; guest gets null.
  - `RecentCommandsTest` (Vitest) — new lightweight JS test
    file (if the repo hasn't set up Vitest yet, skip — the
    feature tests above cover the server-side contract, and a
    lightweight `resources/js/lib/paletteRecent.spec.ts` behind
    a Vitest config lands as a follow-up).

**Out of scope:**

- **Server-side project / repo / host / website search.** These
  four kinds ship via the pre-loaded prop. If a single operator
  ever exceeds several hundred combined rows we revisit — that's
  a scale problem, not a Phase 10 problem.
- **Command palette telemetry.** "Most-run commands globally" is
  a nice growth metric but adds analytics infra. Local LRU is
  enough for the Phase 10 UX.
- **Custom user-defined commands.** "Bind Cmd+K then G I to jump
  to Issues" is a power-user feature. Defer.
- **Cross-project keyboard nav** (`gp` = go to projects, etc.).
  The palette itself already handles fuzzy-matching "projects" or
  "go proj" — real gp/gi shortcuts add a second keyboard layer
  that fights with users typing in inputs.
- **Global markdown / doc search.** "Where did we document
  X?" is a knowledge-base problem, not a command palette.
- **Command groups with disclosure UI** (collapse/expand).
  Static ordering + `max-h-[60vh]` scroll is enough at spec-1
  scale.
- **AI-summarized results** ("AI thinks you might want to X").
  Palette should feel deterministic — LLM in the palette is
  the wrong place for surprise.
- **Server-side Vitest test.** No Vitest config today. Repo runs
  `vue-tsc` for typecheck + `npm run build`; the JS unit-test
  layer is a separate chore PR (roadmap §Phase 9 scope note).
  The recent-commands helper lands with types + PHP feature
  tests covering the server surface; the JS smoke lands with
  Vitest when it's set up.

## Plan

1. **Extend `CommandGroup` + `groupOrder` + `commandGroupLabels`**
   in `resources/js/lib/commands.ts` to include the new entity
   groups (`projects` / `repositories` / `hosts` / `websites` /
   `workItems` / `alerts` / `recent`).

2. **Refactor `getCommands()`** to accept an entity bundle. Signature
   change: `getCommands(entities?: PaletteEntities): Command[]`.
   Existing static commands stay identical; entity kinds append
   after the static groups.

3. **`palette.entities` shared prop.**
   `App\Http\Middleware\HandleInertiaRequests::share()` extension:
   `'palette' => fn () => $request->user() === null ? null :
   $paletteEntities->execute($request->user())`.
   New `App\Domain\Palette\Queries\GetPaletteEntitiesQuery` returns
   the four bundled kinds.

4. **`PaletteSearchController` + query class.**
   Validates `q`, calls `SearchPaletteEntitiesQuery`, returns JSON
   response. Throttled per §5 of the operator checklist.

5. **Client-side async fetch + debounce.**
   `CommandPalette.vue` — add a `watch(query)` with 200ms debounce
   that hits `/palette/search?q=...` via `fetch` + `AbortController`.
   Merge results into the fuzzy-scored static command list; entity
   rows get score 900 (below exact static match, above substring).

6. **Recent commands helper.**
   `resources/js/lib/paletteRecent.ts` — `pushRecent(commandId)`,
   `getRecent(): string[]`. Reads/writes `localStorage`. Guards
   against SSR / no-storage envs. Called from
   `CommandPalette.vue::runCommand()` for static commands only.

7. **Result cap + overflow row.**
   Per-group cap = 8. If a group has more than 8 matching rows,
   render an "Show all N in {group}" overflow row that navigates
   to the entity's index page with the query preserved as a filter.

8. **Feature tests + Pint clean + suite green + build clean +
   self-review via `superpowers:code-reviewer` + PR.**

## Acceptance criteria

- [ ] `Cmd+K` still opens the palette (regression).
- [ ] Empty query shows Recent (if any) + Navigation + Actions —
      no entity rows.
- [ ] Typing project / repo / host / website name matches from
      the pre-loaded shared prop, ranked by fuzzy score.
- [ ] Typing an alert / work-item title triggers a debounced
      async fetch to `/palette/search` and renders results
      inline.
- [ ] Result cap of 8 per group with an "Show all N" overflow
      row.
- [ ] Running a static command bumps it to the top of the Recent
      group on next open.
- [ ] `/palette/search` rate-limited at 30/min per §5 of the
      operator checklist; unauthorized (guest) → 401.
- [ ] Search results are per-user scoped — never leak work items
      / alerts from another operator's projects.
- [ ] Screen-reader announces the debounced load state via
      `role="status" aria-live="polite"`.
- [ ] `PaletteSearchControllerTest` + `SearchPaletteEntitiesQueryTest`
      + `SharedPalettePropTest` all green.
- [ ] Pint clean, `php artisan test` green, `npm run build`
      clean.

## Files touched

- `resources/js/lib/commands.ts` — extend types + accept entity
  bundle
- `resources/js/lib/paletteRecent.ts` — created (LRU helper)
- `resources/js/lib/paletteSearch.ts` — created (fetch +
  debounce)
- `resources/js/Components/CommandPalette/CommandPalette.vue` —
  wire entity groups + async + recent + overflow row
- `resources/js/Components/CommandPalette/CommandPaletteRow.vue`
  — add subtitle line rendering
- `app/Domain/Palette/Queries/GetPaletteEntitiesQuery.php` —
  created
- `app/Domain/Palette/Queries/SearchPaletteEntitiesQuery.php` —
  created
- `app/Http/Controllers/PaletteSearchController.php` — created
- `app/Http/Middleware/HandleInertiaRequests.php` — share
  `palette` prop
- `routes/web.php` — register `/palette/search` (throttled)
- `resources/js/types/index.d.ts` — extend `PageProps` with
  `palette` shape
- `docs/security/operator-checklist.md` — extend §5 with new
  endpoint
- `tests/Feature/Palette/PaletteSearchControllerTest.php` —
  created
- `tests/Feature/Palette/SearchPaletteEntitiesQueryTest.php` —
  created
- `tests/Feature/Middleware/SharedPalettePropTest.php` — created

## Work log

Dated notes as work progresses.

### 2026-07-02
- Drafted from `_template.md`. The Phase 0 scaffold already
  ships a full Cmd+K palette with fuzzy matching, keyboard nav,
  ARIA, focus management, and disabled "Soon" rows. This spec is
  a **delta**: live entity search + recent commands, not a
  rewrite.
- Split entity kinds by scale: projects/repos/hosts/websites
  ship pre-loaded (bounded row count per operator); alerts +
  work items ship async because they scale into the hundreds
  quickly on a busy user.
- Recent commands persist client-side (`localStorage`), not
  server-side, because the round-trip on every keystroke would
  ruin the palette's "instant" feel. Losing recent history on
  browser reset is fine — it's a UX affordance, not a data
  contract.
- Branch `spec/043-command-palette` cut off main.
- Tracking issue #125.

## Open questions / blockers

- **Entity ranking vs. static ranking.** Should `project · Nexus`
  outrank `Go to Projects` when the query is "nexus"? Yes —
  specific > generic. Entity exact-match scores 950; static
  substring scores 300–500. Static "Go to X" acronym-match at
  800 still wins over an entity substring (250–350), which
  matches user intent: `gp` = go projects, `nex` = the Nexus
  entity.
- **Async loading state placement.** Options: (a) inline spinner
  next to the search input, (b) skeleton rows in each affected
  entity group. Ship (a) — the palette is a fast surface, a
  spinner reads as "still working" without shifting layout.
- **Empty async response after successful fetch.** If the debounce
  fires + `/palette/search` returns 0 rows, do we render an empty
  Alerts/Work Items group or omit it? Omit — matches the current
  scaffold's behavior of not rendering groups with zero rows.
- **`paletteRecent.ts` schema versioning.** localStorage entries
  live forever unless the app clears them. Bump the storage key
  to `nexus:palette:recent:v1` and future breaking-shape changes
  become a rename, not a migration.
- **Prop leak.** `palette` shared prop shipping every project +
  every repo could be substantial for a heavy user. Cap the
  bundle: 50 projects, 100 repos, 50 hosts, 50 websites. If a
  user exceeds those, the async endpoint still returns matches
  for the overflow via a fallback search path (not shipped in
  spec 043 — captured for spec 043-follow-up if operators hit
  it).
