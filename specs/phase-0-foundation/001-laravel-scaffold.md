---
spec: laravel-scaffold
phase: 0-foundation
status: in-progress
owner: yoany
created: 2026-04-27
updated: 2026-04-27
---

# 001 — Laravel 12 + Inertia + Vue 3 + TypeScript scaffold

## Goal
Bootstrap the base Laravel 12 application with Vue 3, Inertia, TypeScript, and TailwindCSS. This is the substrate every later phase builds on.

Roadmap reference: §4 Tech Stack, §21 Step 1.

## Scope
**In scope:**
- Fresh Laravel 12 install in `/Users/yoany/source/nexus`
- Vue 3 + TypeScript + Inertia.js wired through Vite
- TailwindCSS v4 configured
- Redis configured for cache, session, queue, broadcast
- Laravel Horizon installed (queue dashboard)
- Laravel Reverb installed (websockets — not yet used)
- Sanctum installed
- Spatie Laravel Permission installed
- `.env.example` reflects all required env vars from §26.3

**Out of scope:**
- Auth UI (handled in `002-auth.md`)
- Custom layout / theme (handled in `003-tailwind-theme.md` and `004-app-layout.md`)
- Any real integration code

## Plan
1. Decide between Laravel Breeze (Vue+Inertia+TS) starter vs. manual scaffold + Inertia. Breeze is the fastest path and matches the stack.
2. Run `composer create-project laravel/laravel .` (or use the Laravel installer).
3. Install Breeze: `composer require laravel/breeze --dev` then `php artisan breeze:install vue --typescript`.
4. Install supporting packages:
    - `laravel/horizon`
    - `laravel/reverb`
    - `laravel/sanctum` (typically already present)
    - `spatie/laravel-permission`
5. Configure `.env` for Redis (`CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `BROADCAST_CONNECTION`).
6. Publish Horizon + Permission configs and run their migrations.
7. Verify dev loop: `npm install && npm run dev`, `php artisan serve`, `php artisan horizon`.
8. Initialize git, commit the scaffold as the first commit.

## Acceptance criteria
- [ ] `php artisan --version` reports Laravel 12.x
- [ ] `npm run dev` boots Vite without errors
- [ ] Visiting `/` returns a working Inertia welcome page
- [ ] `php artisan horizon` starts cleanly
- [ ] `php artisan queue:work redis` consumes jobs
- [ ] `git log` has at least one commit (the scaffold)

## Files touched
- (to be filled in as work progresses)

## Work log

### 2026-04-27
- Spec drafted. Awaiting user confirmation before scaffolding (since this creates many files at the project root).

## Decisions (locked 2026-04-27)
- **Auth starter:** Breeze with `vue --typescript`. `Team` model added by hand later.
- **Database:** MySQL 8 (already running locally via Homebrew). Database name: `nexus`. User will fill in `DB_USERNAME` / `DB_PASSWORD` in `.env`.
- **Redis:** local Redis running (verified via `redis-cli ping → PONG`). Used for cache, queue, session, and broadcast.
- **Local services:** native (not Sail). Docker is installed and used for unrelated work.
- **Pause cadence:** review after each Phase 0 task.

## Open questions / blockers
- (none — all decisions locked)
