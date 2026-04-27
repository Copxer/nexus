---
spec: laravel-scaffold
phase: 0-foundation
status: done
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
- `composer.json`, `composer.lock` — Laravel 13.6 + Breeze + Horizon + Reverb + Spatie Permission + Predis
- `.env`, `.env.example` — MySQL `nexus`, Redis for cache/queue/session/broadcast, Reverb credentials, integration env placeholders
- `.gitignore` — added `/.claude/settings.local.json`
- `package.json`, `package-lock.json` — Vue 3, Inertia, TypeScript, Vite, axios
- `resources/js/` — Breeze stubs (`app.ts`, `bootstrap.ts`, `Pages/`, `Components/`, `Layouts/`, `types/`)
- `resources/css/app.css` — Tailwind entry
- `routes/auth.php`, `routes/web.php`, `routes/console.php` — Breeze auth + welcome routes
- `app/Http/Controllers/Auth/*` — Breeze auth controllers
- `app/Http/Controllers/ProfileController.php`, `app/Http/Controllers/Controller.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Requests/ProfileUpdateRequest.php`
- `app/Models/User.php` — default User model
- `app/Providers/HorizonServiceProvider.php` — published by `horizon:install`
- `config/permission.php`, `config/horizon.php`, `config/reverb.php`
- `database/migrations/*` — users, cache, jobs, permission_tables
- `bootstrap/providers.php` — registered HorizonServiceProvider
- `tests/Feature/Auth/*` — Breeze test suite

## Work log

### 2026-04-27
- Spec drafted. Awaiting user confirmation before scaffolding (since this creates many files at the project root).
- User locked all decisions: Breeze + MySQL `nexus` + Redis + native (no Sail) + pause-after-each-task.
- Verified tooling: PHP 8.4, Composer 2.8, Node 25.9, Laravel installer present, Redis `PONG`, MySQL 8.3.
- Ran `composer create-project laravel/laravel _scaffold` → Laravel **13.6.0** (newer than the roadmap's "Laravel 12" — patterns identical, going with current stable).
- `rsync -a _scaffold/ . && rm -rf _scaffold` to relocate files alongside roadmap/specs/screenshot.
- Configured `.env` for MySQL + Redis + Reverb. User filled in DB credentials.
- Confirmed MySQL connectivity, ensured database `nexus` exists.
- `composer require laravel/breeze --dev` then `php artisan breeze:install vue --typescript --dark`.
- Hit known Breeze bug: `app.ts` imports `./bootstrap` but the stub is missing. Created `resources/js/bootstrap.ts` with axios setup; installed `axios`.
- `npm run build` ✅ green.
- `php artisan migrate --force` ✅ users/cache/jobs tables created in MySQL.
- `composer require laravel/horizon laravel/reverb spatie/laravel-permission predis/predis`.
- `php artisan horizon:install` ✅; `printf 'yes\n' | php artisan reverb:install` ✅; published Spatie Permission migration.
- Generated Reverb app credentials with `random_bytes` and wrote them into `.env`.
- `php artisan migrate --force` ✅ permission tables created.
- Smoke test: `php artisan horizon:list` works; Redis read/write round-trip via `app("redis")` succeeds.
- Confirmed `.env` is gitignored (DB password not staged).
- `git init -b main`, staged 127 files, committed as `9f04dfb`.
- User added GitHub remote: `https://github.com/Copxer/nexus.git`. Added locally; **not pushed** (waiting for explicit go-ahead per system rules).

## Notable deviations from roadmap
- **Laravel 13** instead of 12 — current stable. Patterns from §6 (actions, services, DTOs, etc.) all still apply.
- Added `predis/predis` (the roadmap doesn't pin a Redis client; Predis is the default in `config/database.php` for Laravel 13 if no extension is preferred). The `.env` keeps `REDIS_CLIENT=phpredis` — switch to `predis` if the phpredis extension isn't available locally.

## Decisions (locked 2026-04-27)
- **Auth starter:** Breeze with `vue --typescript`. `Team` model added by hand later.
- **Database:** MySQL 8 (already running locally via Homebrew). Database name: `nexus`. User will fill in `DB_USERNAME` / `DB_PASSWORD` in `.env`.
- **Redis:** local Redis running (verified via `redis-cli ping → PONG`). Used for cache, queue, session, and broadcast.
- **Local services:** native (not Sail). Docker is installed and used for unrelated work.
- **Pause cadence:** review after each Phase 0 task.

## Open questions / blockers
- (none — all decisions locked)
