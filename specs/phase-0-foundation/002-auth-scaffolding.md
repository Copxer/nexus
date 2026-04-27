---
spec: auth-scaffolding
phase: 0-foundation
status: done
owner: yoany
created: 2026-04-27
updated: 2026-04-27
issue: https://github.com/Copxer/nexus/issues/1
branch: spec/002-auth-scaffolding
---

# 002 — Auth scaffolding (login / register / verified email)

## Goal
Confirm that authentication works end-to-end: a user can register, receive a verification email, log in, log out, request a password reset, and that protected routes require verified-email. Breeze already shipped controllers, views, and routes — this spec verifies the wiring and adapts the post-login redirect to `/overview` (the eventual landing page).

Roadmap reference: §16.1 Authentication, §21 Step 1.

## Scope
**In scope:**
- Verify each Breeze auth flow runs without errors (register, login, logout, forgot password, reset password, verify email, password confirmation)
- Set Breeze's `HOME` redirect to `/overview` (placeholder route for now — real Overview page lands in spec 006)
- Ensure protected routes require both `auth` and `verified` middleware
- Use the `log` mail driver for dev so the verification link appears in `storage/logs/laravel.log` without needing SMTP
- Run Breeze's bundled feature tests against MySQL — they must all pass
- Write a tiny `routes/web.php` placeholder route for `/overview` so dashboard redirects don't 404 yet

**Out of scope:**
- Visual restyling of auth pages — handled in spec 003 (design tokens) and spec 004 (layout). Breeze's default look is acceptable until then.
- Real SMTP / Mailgun / Resend setup — log driver is fine for dev.
- 2FA / SSO / GitHub-as-IdP — out of scope for Phase 0; GitHub connection is for *integration* (Phase 2), not auth.
- Team model / multi-tenant — added in spec ??? when Phase 1 starts (Projects depend on `team_id`).

## Plan
1. Inspect the Breeze-shipped routes (`routes/auth.php`, `routes/web.php`) and identify the `dashboard` route.
2. Add a placeholder `/overview` route that returns a simple Inertia page; redirect the post-login destination there.
3. Confirm `MAIL_MAILER=log` in `.env` (already the case from spec 001).
4. Run `php artisan test` — Breeze ships ~20 auth tests. They must all pass against MySQL.
5. Manually smoke-test: register a user via the UI, watch `storage/logs/laravel.log` for the verification email, click the link, log out, log back in.
6. Document the post-login URL change in this spec.
7. Commit.

## Acceptance criteria
- [x] `php artisan test` is fully green (Breeze auth suite + example tests) — 25/25 passed
- [x] Visiting `/login` and `/register` renders Breeze's default forms
- [x] Registering a user creates a row in `users` and dispatches a verification mail (delivered via Mailtrap SMTP — log driver replaced by user mid-flight)
- [x] Clicking the verification link sets `users.email_verified_at` (covered by `EmailVerificationTest`)
- [x] After login, the user lands on `/overview` (placeholder page renders inside `AuthenticatedLayout`)
- [x] Hitting `/overview` while unauthenticated redirects to `/login`
- [x] Hitting `/overview` while authenticated-but-unverified redirects to `/verify-email` (required adding `MustVerifyEmail` to `User`)
- [x] Logging out returns to `/`

## Files touched
- `routes/web.php` — renamed `/dashboard` route to `/overview`, route name `dashboard` → `overview`, render `Overview` component
- `app/Models/User.php` — implements `Illuminate\Contracts\Auth\MustVerifyEmail` so the `verified` middleware actually enforces verification
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — `route('dashboard')` → `route('overview')`
- `app/Http/Controllers/Auth/RegisteredUserController.php` — same
- `app/Http/Controllers/Auth/ConfirmablePasswordController.php` — same
- `app/Http/Controllers/Auth/VerifyEmailController.php` — same
- `app/Http/Controllers/Auth/EmailVerificationPromptController.php` — same
- `app/Http/Controllers/Auth/EmailVerificationNotificationController.php` — same
- `resources/js/Pages/Overview.vue` — renamed from `Dashboard.vue`, header text + placeholder copy updated
- `resources/js/Pages/Welcome.vue` — `route('dashboard')` → `route('overview')`, link text "Dashboard" → "Overview"
- `resources/js/Layouts/AuthenticatedLayout.vue` — same; both desktop nav and responsive nav updated
- `tests/Feature/Auth/AuthenticationTest.php` — `route('dashboard')` → `route('overview')`
- `tests/Feature/Auth/RegistrationTest.php` — same
- `tests/Feature/Auth/EmailVerificationTest.php` — same

## Work log

### 2026-04-27
- Spec drafted.
- Workflow skill `nexus-spec-workflow` codified; user requested no Co-Authored-By trailer (saved to feedback memory).
- Opened tracking issue #1 and created branch `spec/002-auth-scaffolding`.
- Renamed `/dashboard` → `/overview` (route URL + route name) so the rest of Phase 0 can build the real Overview page on this URL. Updated all 6 auth controllers, 3 Breeze tests, the Inertia layout, the Welcome page, and renamed `Dashboard.vue` → `Overview.vue`.
- Initial test run failed with 23/25 red — root cause was a stale route cache. `php artisan optimize:clear` fixed it.
- Manual smoke test (curl + cookie jar) showed the `verified` middleware was a no-op even when `email_verified_at` was NULL. Root cause: the `User` model had `MustVerifyEmail` import commented out and didn't implement the interface. Fixed by adding the interface and uncommenting the import.
- Re-ran tests: 25/25 green. Re-ran smoke test:
    - unauthenticated `/overview` → 302 → `/login` ✅
    - register → user row, `email_verified_at = NULL` ✅
    - authenticated-but-unverified `/overview` → 302 → `/verify-email` ✅
    - manually verified user → `/overview` returns 200 ✅
    - logout → 302 → `/` ✅
    - login again → 302 → `/overview` ✅
- User configured Mailtrap SMTP mid-flight (replaced `MAIL_MAILER=log`); registration email is now delivered to their Mailtrap inbox.

## Open questions / blockers
- None yet.
