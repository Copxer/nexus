---
spec: auth-scaffolding
phase: 0-foundation
status: not-started
owner: yoany
created: 2026-04-27
updated: 2026-04-27
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
- [ ] `php artisan test` is fully green (Breeze auth suite + example tests)
- [ ] Visiting `/login` and `/register` renders Breeze's default forms
- [ ] Registering a user creates a row in `users` and writes a verification mail to `storage/logs/laravel.log`
- [ ] Clicking the verification link sets `users.email_verified_at`
- [ ] After login, the user lands on `/overview` (placeholder page is OK for now)
- [ ] Hitting `/overview` while unauthenticated redirects to `/login`
- [ ] Hitting `/overview` while authenticated-but-unverified redirects to `/verify-email`
- [ ] Logging out returns to `/`

## Files touched
- (filled in as work progresses)

## Work log

### 2026-04-27
- Spec drafted.

## Open questions / blockers
- None yet.
