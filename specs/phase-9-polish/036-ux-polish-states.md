---
spec: ux-polish-states
phase: 9
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-10
updated: 2026-06-11
---

# 036 — UX polish: loading, empty, error states + reduced-motion + light mode

## Goal
Make the app feel stable on every page, not just the happy path.
Today most pages render straight to data — there's no skeleton
during the first paint, empty states are inconsistent (some pages
say nothing, others say "—"), and an Inertia 500 dumps the user on
Laravel's generic error page. Phase 9 starts here because polish
work shows up immediately and validates the bar the rest of the
phase has to clear.

Three concrete shifts in this spec:

1. **Skeleton loading**: Inertia's progress bar is the only signal
   today. Add per-region skeleton placeholders on the major pages
   (Overview, Projects index, Project show, Hosts, Websites,
   Alerts, Analytics) so the first paint looks intentional, not
   blank.
2. **Empty + error states audit**: every list/grid that can be
   empty gets a deliberate empty state (icon + headline + tip).
   Every Inertia error boundary catches unhandled exceptions and
   renders an actionable error UI, not a stack trace.
3. **Motion + theme prefs**: respect `prefers-reduced-motion: reduce`
   (kill every `animate-*` + `transition-*` Tailwind class we own
   when the user asks). Add a persistent light-mode toggle via a
   `theme` shared Inertia prop saved on the user model.

Roadmap refs: §Phase 9 deliverables ("Full responsive polish",
"Loading states", "Error states", "Empty states", "UI feels
premium"), spec 008's reduced-motion deferral, specs 004 & 005's
light-mode deferral.

## Scope

**In scope:**

- **Skeleton loading components.**
  - `resources/js/Components/Skeleton/Skeleton.vue` — base shimmer
    primitive. Props: `width`, `height`, `rounded?` (default `md`).
    Uses CSS `@keyframes` shimmer with a 2s linear loop.
  - `resources/js/Components/Skeleton/SkeletonCard.vue` — KpiCard-
    shaped skeleton (icon dot + label line + value line + sparkline
    strip).
  - `resources/js/Components/Skeleton/SkeletonRow.vue` — table-row
    skeleton (avatar + label + meta + chip).
  - Page-level wiring: every page using `<KpiCard>` in a grid wraps
    that grid in a `<Transition appear>` that shows skeletons
    during the Inertia visit's initial paint. Use Inertia's
    `usePage().component` and a small `useFirstPaint` composable to
    distinguish first paint from partial-reload paints (partials
    keep the existing data, no skeleton needed).

- **Empty-state audit.**
  - Inventory: pages that render an array → list. Walk:
    Overview's risky-projects (033/035 already has placeholder),
    `topRepositories`, `topWorkItems`; Projects index; Project Show
    tabs (repos, deployments, monitors, hosts); Hosts index +
    show; Websites index + show; Alerts index; Activity index;
    Analytics cards (034 already has placeholders).
  - Each gap gets a `<EmptyState>` component render:
    - `resources/js/Components/EmptyState.vue` — new component.
      Props: `icon: LucideIcon`, `title: string`, `description?:
      string`, `action?: { label, href }`. Renders centered glass
      card with the icon at low opacity + headline + optional CTA.
  - Inventory + render swaps are bundled into one batch — touch
    every page once.

- **Error boundaries.**
  - `resources/js/Pages/Errors/AppError.vue` — generic error page,
    rendered by a new Inertia exception handler when an unexpected
    exception escapes. Renders a friendly "Something broke" UI
    with a `Try again` button (re-issues the failed visit) + a
    `Report this` link to a future feedback URL. The actual stack
    trace stays in the logs; the user sees a stable card.
  - `app/Exceptions/Handler.php` — extend `Handler::render()` to
    return `Inertia::render('Errors/AppError', [...])` instead of
    Laravel's default error page when the request expects HTML and
    the exception is unhandled. Existing 403 / 404 already redirect
    cleanly via Inertia's built-in flow — this fills the gap for
    500-class errors.
  - **No `errors/AppError`-rendering on validation 422s** — those
    are an existing Inertia contract (`useForm`). Filter on
    `class_basename($e)`.

- **Reduced-motion handling.**
  - `resources/css/app.css` — add a top-level
    `@media (prefers-reduced-motion: reduce) { ... }` block that
    nulls out every animation we own: shimmer (skeleton), hover
    transitions, Inertia's progress bar, the Reverb-connect pulse
    dot. Test via Chrome DevTools's "Emulate CSS media feature".
  - The block is short — `* { animation-duration: 0.001s !important;
    transition-duration: 0.001s !important; }` covers ~95% of cases
    without per-class plumbing.

- **Light-mode toggle.**
  - `users` table — add `theme` column (`enum('dark', 'light',
    'system')`, default `'dark'`). Migration is small.
  - `User` model — fillable + cast.
  - `app/Http/Controllers/Settings/ThemeController.php` — single-
    action `update(Request)` that validates `theme in dark,light,
    system` and saves. Returns Inertia redirect to settings.
  - `HandleInertiaRequests::share()` — expose `auth.user.theme`.
  - `resources/js/Layouts/AppLayout.vue` — read `theme` prop on
    mount; if `system`, derive from
    `window.matchMedia('(prefers-color-scheme: light)')`. Apply
    via `<html class="light">` / `<html class="dark">` toggling.
  - `tailwind.config.js` — already supports `darkMode: 'class'`
    (or we switch to it). All existing color tokens use design-
    system semantic names so the light-mode palette is a
    `tailwind.config.js` token additions, not per-component
    rewriting.
  - **Light-mode palette is its own design pass.** This spec ships
    the toggle plumbing + a baseline light palette that's
    legible-but-not-polished. Visual refinement is a follow-up
    polish spec.
  - Settings page (existing) gets a "Theme" section with
    radio buttons for dark / light / system.

- **Tests.**
  - `tests/Feature/Settings/ThemeControllerTest.php` — happy path
    update + validation rejection + guest redirect.
  - `tests/Feature/Errors/AppErrorHandlerTest.php` — fake an
    uncaught exception, assert the response renders
    `Errors/AppError` instead of Laravel's debug page (in
    `APP_ENV=production` mode).
  - Existing tests cover validation 422 path; spot-check those
    still pass.
  - Manual QA: walk every page in DevTools with the
    `prefers-reduced-motion: reduce` flag on; verify nothing
    moves. Walk every page in light + dark; verify nothing
    becomes illegible.

**Out of scope:**

- **Skeleton on `/analytics`** — spec 034 already renders empty-
  state placeholders for cards with no data. Skeleton-during-load
  is a polish-of-polish; defer if 034's KpiCards already feel OK
  during a 200ms first-paint.
- **Polished light-mode palette.** This spec ships a baseline that
  works; a follow-up design-pass spec tightens the contrast +
  glow story.
- **Per-page custom error pages.** Generic `AppError` for now;
  per-domain custom errors (e.g. specific "GitHub sync failed"
  page) are deferred.
- **Internationalization.** Strings stay English; i18n is its own
  spec.
- **Animation library swap.** Tailwind's built-in `transition-*`
  classes are enough; no `vue-transition` / `motion-one`
  introduction.
- **Per-route preload hints.** Vite's `@inertiajs/vue3` already
  prefetches via `Link`; we don't tune this.

## Plan

1. **Skeleton primitives.** Build `Skeleton.vue`, `SkeletonCard.vue`,
   `SkeletonRow.vue`. Add to a tiny `useFirstPaint` composable so
   pages can `v-if="!firstPaint || data"` on their data wrapper.

2. **Empty-state component.** Build `EmptyState.vue`. Add to the
   audit-list pages one PR pass at a time.

3. **`AppError` page + handler.** Add `Pages/Errors/AppError.vue`.
   Extend `Handler::render()`.

4. **Reduced-motion CSS block.** One-block change in `app.css`.

5. **Light-mode plumbing.**
   - Migration adds `theme` to `users`.
   - `User` model fillable + cast.
   - `ThemeController` + route under `settings/theme`.
   - Share `auth.user.theme` from `HandleInertiaRequests`.
   - `AppLayout.vue` toggles `<html>` class on mount + on prop
     change.
   - Add token entries to `tailwind.config.js` for the light
     palette.

6. **Tests.** Per the list above.

7. **Pint clean. `php artisan test` green. `npm run build` clean.
   Self-review with `superpowers:code-reviewer`. PR. Watch CI.
   Pause for merge.**

## Acceptance criteria
- [ ] Every major page (Overview, Projects, Hosts, Websites,
      Alerts, Analytics) renders skeleton placeholders during the
      initial paint.
- [ ] Every list/grid that can be empty renders an `<EmptyState>`
      with icon + headline + tip.
- [ ] An uncaught 500-class exception renders `Errors/AppError`
      with a `Try again` button — not Laravel's default page.
      Validation 422s + 403 + 404 behave as before.
- [ ] `prefers-reduced-motion: reduce` kills every shimmer +
      hover + transition we own.
- [ ] User can switch theme between dark / light / system via the
      settings page; the choice persists across sessions.
- [ ] Pint clean. `php artisan test` green. `npm run build` clean.

## Files touched
List of created/modified files. Fill in as work progresses.

- `database/migrations/2026_06_*_add_theme_to_users.php` — created
- `app/Models/User.php` — fillable + cast
- `app/Http/Controllers/Settings/ThemeController.php` — created
- `routes/web.php` — `settings.theme.update` route
- `app/Http/Middleware/HandleInertiaRequests.php` — share `theme`
- `app/Exceptions/Handler.php` — `render()` extension
- `resources/js/Pages/Errors/AppError.vue` — created
- `resources/js/Components/Skeleton/Skeleton.vue` — created
- `resources/js/Components/Skeleton/SkeletonCard.vue` — created
- `resources/js/Components/Skeleton/SkeletonRow.vue` — created
- `resources/js/Components/EmptyState.vue` — created
- `resources/js/Layouts/AppLayout.vue` — theme toggle
- `resources/js/Pages/Settings/Index.vue` — theme radios
- `resources/js/lib/useFirstPaint.ts` — composable
- `resources/css/app.css` — reduced-motion block
- `tailwind.config.js` — light palette tokens
- Empty-state render swaps across: `Pages/Overview.vue`,
  `Pages/Projects/Index.vue`, `Pages/Projects/Show.vue`,
  `Pages/Monitoring/Hosts/Index.vue`,
  `Pages/Monitoring/Hosts/Show.vue`,
  `Pages/Monitoring/Websites/Index.vue`,
  `Pages/Monitoring/Websites/Show.vue`,
  `Pages/Alerts/Index.vue`, `Pages/Activity/Index.vue`
- `tests/Feature/Settings/ThemeControllerTest.php` — created
- `tests/Feature/Errors/AppErrorHandlerTest.php` — created

## Work log
Dated notes as work progresses.

### 2026-06-10
- Drafted from `_template.md`. First spec in Phase 9. Scope-pruned
  vs. the deliverables list: focused on visible-polish slices
  (skeleton / empty / error / motion / theme), defers Horizon
  theming + animation library swaps + i18n.

### 2026-06-11
- Branch `spec/036-ux-polish-states` cut off main.
- Tracking issue #106.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **Skeleton-vs-spinner trade-off.** Inertia's progress bar is fine
  for sub-200ms navigations. Skeletons help when data fetches drag
  past 300ms — Phase 0–8 pages stay snappy in dev, but production
  page-loads (with cold DB) sometimes brush 400ms. Worth measuring
  during impl; if every page is sub-200ms we may scope the
  skeleton work down to just the analytics + overview pages.
- **Light-mode palette baseline.** This spec ships a "legible-but-
  not-polished" light palette. The design pass that tightens it
  could be 036.5 or a polish spec.
- **`Errors/AppError` behaviour in `APP_DEBUG=true`.** Laravel's
  Ignition page is genuinely useful in dev; the new handler
  should bypass Inertia when `APP_DEBUG=true` so devs keep
  Ignition. Easy guard via `if (config('app.debug'))`.
