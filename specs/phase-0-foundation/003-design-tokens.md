---
spec: design-tokens
phase: 0-foundation
status: done
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/4
branch: spec/003-design-tokens
---

# 003 — Design tokens + Tailwind theme (dark, glassmorphism, neon accents)

## Goal
Translate the locked visual reference (`specs/visual-reference.md`, sourced from `nexus-dashboard.png`) into concrete Tailwind theme extensions and base CSS. After this spec, **any new component should be able to drop in `bg-panel`, `text-accent-cyan`, `font-mono`, `shadow-glow-cyan`, etc., and look correct without further design decisions.**

Roadmap reference: §7 UX Direction, §22 Visual Design Specification.

## Scope
**In scope:**
- Extend `tailwind.config.js` with the locked color tokens, font family stack, and a small set of utilities for glow/blur/shadow.
- Replace `resources/css/app.css` with the project's base styles: dark background, custom scrollbar, font-face declarations, default selection color.
- Add **Inter** (sans) and **JetBrains Mono** (mono) via the `@fontsource/*` npm packages so the fonts ship with the build, not via CDN (faster, offline-friendly, no FOUT).
- Add a small reusable component CSS layer for `.glass-card` and the typographic helpers (`.tabular-nums`, `.font-display`).
- Apply the tokens to **Login**, **Register**, and **AuthenticatedLayout** as a smoke test. The visual feel of those pages should change visibly: navy background, cyan-accented buttons, glass card chrome.
- Add `darkMode: 'class'` and set `<html class="dark">` by default in `app.blade.php` (the dashboard target is dark-first, light mode is a phase-9 nice-to-have).
- Update Pint + tests still pass; add a visual smoke screenshot if practical.

**Out of scope:**
- Building the actual sidebar, top bar, command palette, or overview page (spec 004+).
- Light-mode parity. Dark-first; light mode is deferred.
- Tailwind v4 upgrade. Breeze installed v3.2; we stay on v3 to minimize risk in this spec. v4 can be a separate `chore/upgrade-tailwind` PR later.
- Animations/motion. Spec 003 is static tokens only; animation utilities land in spec 004 alongside the layout that uses them.

## Plan
1. Draft the token list against `visual-reference.md`. Map every color, font, blur, and shadow to a Tailwind extension key.
2. Install fonts: `npm i @fontsource/inter @fontsource/jetbrains-mono`. Import the weights we'll actually use (400/500/600/700 for Inter, 400/500 for JetBrains Mono).
3. Update `tailwind.config.js` with `extend.colors`, `extend.fontFamily`, `extend.boxShadow`, `extend.backdropBlur`, `extend.backgroundImage` (for the navy gradient).
4. Rewrite `resources/css/app.css`:
    - `@import` the font CSS files
    - `@tailwind base; @tailwind components; @tailwind utilities;` (preserve current order)
    - `@layer base` block: `:root`, `html.dark`, `body`, `::selection`, scrollbar
    - `@layer components` block: `.glass-card`, optional `.glow-*`
5. Force dark mode at the layout level (`<html class="dark">` in `resources/views/app.blade.php`).
6. Repaint:
    - `Login.vue` background + button + input
    - `Register.vue` (same components — Breeze uses `GuestLayout`)
    - `GuestLayout.vue` if needed
    - `AuthenticatedLayout.vue` top bar + page background
7. Run `php artisan test` (must stay green), `npm run build` (must compile), `vendor/bin/pint --test` (must pass).
8. Run `composer run dev`, eyeball each page, capture a smoke screenshot for the work log.
9. Open PR.

## Acceptance criteria
- [x] Tailwind config exposes named color tokens matching `visual-reference.md` (background.{base,panel,panel-hover}, border.{subtle,active}, text.{primary,secondary,muted}, accent.{blue,cyan,purple,magenta}, status.{success,warning,danger,info}).
- [x] Tailwind config exposes `fontFamily.sans = Inter`, `fontFamily.mono = JetBrains Mono`, with system fallbacks from `defaultTheme`.
- [x] `npm run build` produces a CSS bundle bundling Inter + JetBrains Mono webfonts (verified — passes locally).
- [x] `app.blade.php` renders with `<html class="dark">` and a navy gradient background (`bg-app-gradient`).
- [x] `.glass-card` utility added under `@layer components`: `rounded-2xl`, semi-transparent slate panel, subtle border, backdrop-blur, hover-glow border.
- [x] Login + Register repainted: dark gradient background with cyan/purple ambient blobs, glass card chrome, neon-cyan primary button, monospace-friendly inputs, "Nexus Control Center" wordmark.
- [x] AuthenticatedLayout repainted: glass top bar with the cyan-glow logo, dark dropdown trigger, dark mobile menu, navy gradient page background, dark page-heading bar.
- [x] All Breeze components (TextInput, InputLabel, PrimaryButton, SecondaryButton, DangerButton, InputError, Checkbox, NavLink, ResponsiveNavLink) use the new tokens — no leftover `gray-*` / `indigo-*` classes.
- [x] All existing tests still pass (25/25).
- [x] Pint clean.
- [ ] CI green on the PR (will verify after push).

## Files touched
- `tailwind.config.js` — added `darkMode: 'class'`, locked color tokens, font stacks (Inter / JetBrains Mono with fallbacks), `app-gradient` backgroundImage, glow box-shadows, `panel` shadow, `xs` backdropBlur.
- `resources/css/app.css` — webfont imports (`@fontsource/inter` 400/500/600/700, `@fontsource/jetbrains-mono` 400/500), `@layer base` with html/body/selection/scrollbar styling, `@layer components` with `.glass-card` and `.font-display`.
- `resources/views/app.blade.php` — `<html class="dark">`, removed Bunny Fonts CDN link (replaced by bundled webfonts), body uses `bg-background-base font-sans text-text-primary`.
- `resources/js/Layouts/GuestLayout.vue` — dark gradient backdrop, ambient cyan/purple glow blobs, cyan-glow logo, "Nexus Control Center" wordmark, `glass-card` form container.
- `resources/js/Layouts/AuthenticatedLayout.vue` — `bg-app-gradient` page background, glass top bar with backdrop blur, cyan-glow logo + "Nexus" wordmark, dark dropdown trigger, dark mobile menu, dark page-heading bar.
- `resources/js/Pages/Auth/Login.vue` — heading + subhead, status pill, spaced fields, full-width primary button, "Create an account" link, neon-cyan accents.
- `resources/js/Pages/Auth/Register.vue` — same treatment.
- `resources/js/Pages/Overview.vue` — placeholder card now uses `glass-card`.
- `resources/js/Components/TextInput.vue` — dark input chrome, cyan focus ring.
- `resources/js/Components/InputLabel.vue` — uppercase tracked label in `text-secondary`.
- `resources/js/Components/PrimaryButton.vue` — neon-cyan filled, glow shadow.
- `resources/js/Components/SecondaryButton.vue` — dark slate, cyan hover.
- `resources/js/Components/DangerButton.vue` — neon-danger filled, danger glow.
- `resources/js/Components/InputError.vue` — `text-status-danger`.
- `resources/js/Components/Checkbox.vue` — dark + cyan accent.
- `resources/js/Components/NavLink.vue` — cyan underline when active.
- `resources/js/Components/ResponsiveNavLink.vue` — cyan left border + tinted bg when active.
- `package.json` / `package-lock.json` — added `@fontsource/inter`, `@fontsource/jetbrains-mono`.

## Work log

### 2026-04-27
- Spec drafted. Verified current Tailwind setup is v3.2 with Breeze defaults.
- Opened tracking issue #4 and created branch `spec/003-design-tokens`.
- Installed `@fontsource/inter` (400/500/600/700) and `@fontsource/jetbrains-mono` (400/500). 50 packages added.
- Extended `tailwind.config.js` per the locked tokens. Reserved glow shadows for active/critical/online states only.
- Rewrote `app.css` with webfont imports, base layer (scrollbar + selection + body/html), and components layer (`.glass-card`, `.font-display`).
- Forced `<html class="dark">` in `app.blade.php`. Removed the Figtree CDN link.
- Repainted the entire Breeze component library (8 components) so any new page automatically gets the right look.
- Repainted Login, Register, GuestLayout, AuthenticatedLayout, and the Overview placeholder.
- `vendor/bin/pint --test` ✅, `npm run build` ✅, `php artisan test` 25/25 ✅.
- Visual smoke test deferred to PR review (the user will verify via `composer run dev`).

## Open questions / blockers
- Whether to alias both `accent-cyan` and `accent-cyan-500`-style scales. Decision: just the named flat tokens for now (`accent-cyan`, `accent-purple`, etc.). Scales can be added later if a component genuinely needs them.
- Whether to use `@apply` inside `.vue` `<style>` blocks or keep all style in Tailwind class strings. Decision: prefer Tailwind class strings; use `@layer components` for actual reuse (cards, buttons), not page-specific styling.
