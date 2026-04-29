---
spec: github-app-connection
phase: 2-github
status: in-progress
owner: yoany
created: 2026-04-29
updated: 2026-04-29
issue: https://github.com/Copxer/nexus/issues/33
branch: spec/013-github-app-connection
---

# 013 — GitHub App connection (OAuth flow, encrypted token storage, Settings/Integrations panel)

## Goal
Open the door to GitHub. Phase 2 starts here: a logged-in Nexus user can click "Connect GitHub" and complete a real OAuth-style flow, returning to Nexus with a securely-stored access token that future syncs use. After this spec the user can see their connection status (connected as `@username` since `<datetime>`), disconnect, and re-connect; the CommandPalette `Connect GitHub` action becomes real (was "Soon"); and the Settings sidebar nav item activates with an Integrations panel that lists GitHub's connection state.

This spec does **not** list or import any repositories — that's spec 014. It does not call any GitHub API beyond the OAuth handshake + a single `GET /user` call to fetch the connected username for display. Webhooks ship in phase 3.

Roadmap reference: §27 (`app/Domain/GitHub`), §26.3 (`GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` env vars), §8.4 (Connect button copy: "Connect GitHub", placeholder we shipped in the empty-state of the Phase 0 sidebar).

## Scope
**In scope:**

- **GitHub App vs OAuth App — pick GitHub App.** Modern recommendation, fine-grained per-installation permissions, longer-lived tokens. We'll use the **OAuth user-to-server flow** that GitHub Apps support — i.e. the user authorizes the GitHub App on their account, GitHub redirects back to Nexus with a `code`, we exchange that code for a user-access-token. This spec covers only the user-token flow (per-user auth); installation tokens for org repos can come later when multi-tenant lands.

- **`github_connections` table.** New migration:
    - `id`
    - `user_id` foreign-key to `users` (cascadeOnDelete, unique — one connection per Nexus user for now)
    - `github_user_id` (string — GitHub's numeric user id)
    - `github_username` (string — `@octocat`)
    - `access_token` (text, encrypted via `'encrypted'` cast)
    - `refresh_token` (text, nullable, encrypted) — GitHub User-to-Server access tokens expire after 8 hours and are refreshed via the refresh token
    - `expires_at` (timestamp, nullable)
    - `refresh_token_expires_at` (timestamp, nullable)
    - `scopes` (json, nullable — array of granted scopes)
    - `connected_at` (timestamp)
    - timestamps (`created_at`, `updated_at`)

- **`App\Models\GithubConnection`** — `belongsTo(User)`, encrypted casts on the two token columns + array cast on scopes. `User::githubConnection()` hasOne added.

- **`App\Domain\GitHub\Services\GitHubOAuthService`** — handles the OAuth handshake. Methods:
    - `redirectUrl(string $state): string` — builds the `https://github.com/login/oauth/authorize?client_id=…&state=…` URL.
    - `exchangeCode(string $code): array` — POST to `https://github.com/login/oauth/access_token`, returns the token payload.
    - `fetchUser(string $accessToken): array` — GET `https://api.github.com/user`, returns `{ id, login, ... }`.
    - HTTP-client-injectable so tests can mock without real GitHub credentials.

- **`App\Domain\GitHub\Actions\PersistGithubConnectionAction`** — given the token payload + user payload, creates or updates the `github_connections` row scoped to the current Nexus user. Encrypts tokens via the model cast.

- **`App\Http\Controllers\GithubConnectionController`** — three actions:
    - `redirect()` — generates a CSRF-safe `state` (stored in the session), returns a redirect to GitHub's authorize URL.
    - `callback(Request $request)` — verifies `state` against the session, exchanges the `code`, calls `fetchUser`, persists via the action, redirects to Settings/Integrations with a flash message.
    - `destroy()` — disconnects (deletes the `github_connections` row).

- **Routes.** `routes/web.php` adds:
    ```php
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('/integrations/github/connect', [GithubConnectionController::class, 'redirect'])
            ->name('integrations.github.connect');
        Route::get('/integrations/github/callback', [GithubConnectionController::class, 'callback'])
            ->name('integrations.github.callback');
        Route::delete('/integrations/github', [GithubConnectionController::class, 'destroy'])
            ->name('integrations.github.disconnect');
    });
    ```

- **Settings sidebar activation.** Drop `disabled` from the `Settings` entry in `Sidebar.vue`; route to `/settings`. Add a `SettingsController` with a single `index` action rendering `Pages/Settings/Index.vue`. The page renders an Integrations panel listing GitHub's connection state. (Settings is otherwise empty — only the GitHub Integrations card lives here this spec; future settings sections — profile shortcuts, notifications, etc. — slot in later.)

- **Settings page (`Pages/Settings/Index.vue`)** — single Integrations card showing:
    - **When disconnected:** GitHub icon + "Connect GitHub" CTA + brief copy ("Bring repositories, issues, and pull requests into Nexus."). The CTA links to `route('integrations.github.connect')`.
    - **When connected:** GitHub icon + `@username` + "Connected <relative time>" + "Disconnect" button (DELETE form).
    - **When token expired:** "Reconnect" CTA (since the user-token can't refresh once expired).

- **Command palette activation.** `lib/commands.ts` — `connect-github` becomes real (`disabled: false`, `run: () => router.visit(route('integrations.github.connect'))`). Goes through Inertia visit; the controller action returns a 302 to GitHub.

- **`.env.example` already has `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_WEBHOOK_SECRET`** (verified in spec 001/009). Add `GITHUB_OAUTH_REDIRECT_URI` if missing.

- **`config/services.php`** — add a `github` block reading from those env vars so the service can `config('services.github.client_id')` etc.

- **No real GitHub credentials in CI.** Tests use mocked HTTP responses via Laravel's `Http::fake()` facade — exchange-code returns a stub token payload, fetch-user returns `@nexus-test`. The OAuth handshake is verified end-to-end at the controller level but never hits api.github.com.

- **Tests.**
    - `GithubOAuthServiceTest` — `redirectUrl()` includes the configured `client_id` + the right scopes + the right redirect URI; `exchangeCode()` makes the right POST and unmarshals the response; `fetchUser()` parses GitHub's user payload.
    - `GithubConnectionControllerTest` — `redirect` returns 302 to GitHub with a state in the session; `callback` rejects mismatched state; `callback` happy-path persists the connection and redirects to settings; `destroy` removes the row.
    - `PersistGithubConnectionActionTest` — first-time create; subsequent re-connect updates the existing row in place (one connection per user invariant).
    - `SettingsControllerTest` — page renders for verified user; shows connected state if a connection exists, disconnected state otherwise.

**Out of scope:**

- Listing or importing repositories. That's spec 014. Settings page does NOT show "X repositories synced" yet.
- Any GitHub API call beyond `GET /user`. No issue/PR sync, no repo metadata refresh.
- Webhook secret usage / signature verification. Phase 3.
- Multi-tenant / org-wide connections. Per-user only this spec; revisit when teams arrive.
- A "test connection" button. Adding it later if it earns its keep.
- Token refresh background job. We refresh on demand at the next API call (in spec 014's sync job); a proactive refresh job is phase 9 polish.
- A real GitHub App registration. The app's `client_id`/`client_secret` come from `.env`; the user (yoany) registers the GitHub App against their own account when they're ready to test for real. CI never needs them.
- A "View on GitHub" deep-link from the Settings panel — pure cosmetic.

## Plan

1. **Migration + model.** `github_connections` table; `App\Models\GithubConnection` with encrypted casts + `belongsTo(User)`. `User::githubConnection()` hasOne.
2. **Service + action.** `GitHubOAuthService` (HTTP-client-injectable); `PersistGithubConnectionAction` calls the service, persists the encrypted token, returns the model. Pure read/write — no controller dependency.
3. **Controller + routes.** `GithubConnectionController::{redirect, callback, destroy}`; route names + middleware.
4. **`config/services.php`** — `github` block reading the env vars.
5. **Settings sidebar.** `SettingsController::index`, `Pages/Settings/Index.vue` rendering the Integrations card. Drop `disabled` from `Sidebar.vue` Settings entry.
6. **Command palette.** Activate `connect-github` (route to `integrations.github.connect`).
7. **Tests.** Service, action, controller, settings page. All HTTP traffic mocked via `Http::fake()`.
8. **Manual UX walk** in Playwright Chrome: log in → CommandPalette → "Connect GitHub" → see redirect to GitHub (we'll mock the actual GitHub roundtrip in the test, but the manual walk can stop at the GitHub redirect and confirm the state cookie is set). Re-walk after stubbing a connection in tinker to verify the connected-state UI.
9. **Pipeline pass** — Pint, vue-tsc, build, full PHP test run.
10. **Self-review** with `superpowers:code-reviewer`.

## Acceptance criteria
- [ ] `github_connections` table exists with all listed columns; tokens are encrypted at rest.
- [ ] `App\Models\GithubConnection` + `User::githubConnection()` hasOne work end-to-end.
- [ ] `GitHubOAuthService` exposes `redirectUrl()`, `exchangeCode()`, `fetchUser()`. HTTP traffic is mockable via `Http::fake()`.
- [ ] `PersistGithubConnectionAction` persists or updates the per-user connection row; tokens encrypted via model cast.
- [ ] `GithubConnectionController::redirect` 302's to GitHub with a CSRF-safe `state` parameter stored in session.
- [ ] `GithubConnectionController::callback` rejects mismatched `state` (403/redirect with error flash); on success persists the connection and redirects to `/settings`.
- [ ] `GithubConnectionController::destroy` removes the row + redirects to `/settings`.
- [ ] `/settings` renders an Integrations card showing connected state (`@username` + `connected_at`) or disconnected CTA.
- [ ] Sidebar `Settings` is no longer "Soon"; clicking it navigates to `/settings`. Active state when on `/settings`.
- [ ] Command palette `Connect GitHub` is real (no "Soon" pill); pressing Enter navigates the user through the connect flow.
- [ ] No real GitHub credentials in CI; tests pass against `Http::fake()` only.
- [ ] No `gray-*` / `red-*` / `green-*` / `indigo-*` Tailwind classes — design tokens only.
- [ ] Pint clean, vue-tsc clean, `npm run build` green, CI green on the PR.
- [ ] Self-review pass with `superpowers:code-reviewer`; material findings addressed before PR.

## Files touched
- `database/migrations/<timestamp>_create_github_connections_table.php` — new.
- `app/Models/GithubConnection.php` — new.
- `app/Models/User.php` — add `githubConnection()` hasOne.
- `app/Domain/GitHub/Services/GitHubOAuthService.php` — new.
- `app/Domain/GitHub/Actions/PersistGithubConnectionAction.php` — new.
- `app/Http/Controllers/GithubConnectionController.php` — new.
- `app/Http/Controllers/SettingsController.php` — new (single-action `index`).
- `routes/web.php` — add the GitHub connect/callback/disconnect routes + `/settings`.
- `config/services.php` — add `github` block.
- `resources/js/Pages/Settings/Index.vue` — new.
- `resources/js/Components/Sidebar/Sidebar.vue` — activate `Settings` nav.
- `resources/js/lib/commands.ts` — activate `connect-github`.
- `tests/Feature/GitHub/GitHubOAuthServiceTest.php` — new.
- `tests/Feature/GitHub/GithubConnectionControllerTest.php` — new.
- `tests/Feature/GitHub/PersistGithubConnectionActionTest.php` — new.
- `tests/Feature/Settings/SettingsControllerTest.php` — new.

## Work log
Dated notes as work progresses.

### 2026-04-29
- Spec drafted; scope confirmed (6 decisions locked: GitHub App + user-to-server OAuth, per-user connection, encrypted token cast, Settings + palette entry points, explicit Reconnect on expiry, Http::fake() only in CI).
- Opened issue [#33](https://github.com/Copxer/nexus/issues/33) and branch `spec/013-github-app-connection` off `main`.
- Created `specs/phase-2-github/README.md` and `specs/phase-2-github/013-github-app-connection.md` to start Phase 2.
- Implemented:
    - **Migration + model.** `github_connections` table with `user_id` unique FK + GitHub user id/login + encrypted `access_token`/`refresh_token` + expiries + scopes + `connected_at`. `App\Models\GithubConnection` uses Laravel's `'encrypted'` cast on the two token columns plus `$hidden` on them as a defense-in-depth so they don't sneak into Inertia props or `toArray()` output. `User::githubConnection()` hasOne added.
    - **Service + action.** `App\Domain\GitHub\Services\GitHubOAuthService` exposes `redirectUrl(state)`, `exchangeCode(code)`, `fetchUser(token)` — all HTTP traffic flows through Laravel's `Http` facade so tests use `Http::fake()`. `PersistGithubConnectionAction::execute(user, tokenPayload, userPayload)` is idempotent on re-connect (`updateOrCreate` keyed on `user_id`); converts GitHub's `expires_in` seconds to absolute timestamps; parses the CSV `scope` field into a JSON array.
    - **Controller + routes.** `GithubConnectionController::{redirect, callback, destroy}` — `redirect` mints a 40-char `Str::random` state and stashes it in the session; `callback` `pull`s the state, hash-equals checks it (constant-time), exchanges the code via the service, fetches the user, persists via the action, redirects to `/settings` with a flash. `destroy` deletes the row. The callback also handles the `?error=…&error_description=…` shape GitHub returns when the user denies.
    - **Settings.** New `SettingsController::__invoke` returns `Inertia::render('Settings/Index', ['github' => …])` with the connection trimmed to a UI-safe shape (never includes the token). `Pages/Settings/Index.vue` renders an Integrations card with three states (disconnected → "Connect GitHub" CTA, connected → metadata strip with `@username` / connected_at / token expiry / scopes pills + Disconnect, expired → "Reconnect" CTA).
    - **Sidebar + palette.** Sidebar `Settings` drops `disabled` + uses `routeName: 'settings.index'`. Palette `connect-github` loses `disabled`/`soonLabel` and gains `router.visit(route('integrations.github.connect'))`.
    - **`config/services.php`** gains a `github` block reading `client_id`/`client_secret`/`redirect`/`scopes` from env. `.env.example` adds `GITHUB_OAUTH_REDIRECT_URI` (the existing `GITHUB_CLIENT_ID`/`GITHUB_CLIENT_SECRET`/`GITHUB_WEBHOOK_SECRET` were already there).
    - **PHP feature tests** — 4 new files, 16 new cases:
        - `GitHubOAuthServiceTest` (5) — redirect URL contains client_id/state/scopes/redirect_uri; `exchangeCode` returns the decoded payload from `Http::fake`; rejects GitHub error payloads; `fetchUser` parses the profile; throws on 401.
        - `GithubConnectionControllerTest` (5) — `redirect` 302's to GitHub with state in session; `callback` rejects mismatched state; happy-path persists; `?error=` query string surfaces as a flash; `destroy` removes the row.
        - `PersistGithubConnectionActionTest` (3) — first-time create; re-running updates the existing row in place (tokens encrypted at rest, verified via raw `DB::table()` read); copes with payloads missing optional fields.
        - `SettingsControllerTest` (3) — disconnected state, connected state, response body never contains plaintext tokens.
- Manual UX walk in Playwright Chrome (1440×900):
    - `/settings` disconnected: "GitHub" card, muted "DISCONNECTED" badge, "Connect GitHub" CTA, helper text pointing at specs 014–016. Sidebar `Settings` lit (no longer "Soon").
    - After stubbing a fake `GithubConnection` in tinker: card shows green "CONNECTED" badge, `@octocat` with external-link icon, "6 seconds ago" / "7 hours from now" / scopes pills (read:user / repo). "Disconnect" red button visible.
    - Disconnect → confirmation dialog → page reverts to disconnected state.
    - The Connect-GitHub redirect route is verified auth-guarded by curl (302 → /login when unauthenticated). The actual redirect to `https://github.com/login/oauth/authorize?...` is covered by `GithubConnectionControllerTest::test_redirect_sends_user_to_github_with_state_in_session`.
- Pipeline: Pint clean, vue-tsc clean, `npm run build` green. **67 tests pass with 320 assertions** (16 new GitHub + Settings + 51 pre-existing).

## Decisions (locked 2026-04-29)
- **GitHub App with user-to-server OAuth.** Modern, fine-grained permissions, unblocks future org installations. (vs classic OAuth App.)
- **Per-user connection.** `github_connections` row scoped to `user_id`. Org-wide connections come with the multi-team spec.
- **Encrypted token storage.** Laravel `'encrypted'` model cast on `access_token` + `refresh_token`. Never logged, never exposed in Inertia props.
- **Connect button — Settings + Palette.** Settings → Integrations panel and the existing CommandPalette `Connect GitHub` command. No top-bar CTA.
- **Token-expired UX — explicit Reconnect.** Silent refresh attempts hide problems; explicit "Reconnect" CTA when expired.
- **Tests — `Http::fake()` only.** No real GitHub credentials in CI. Real-credential testing is out-of-band when the GitHub App gets registered.

## Open questions / blockers

- **GitHub App registration.** The user (yoany) needs to register a GitHub App at https://github.com/settings/apps when ready to test against real GitHub. The `client_id` / `client_secret` go into `.env`; the redirect URL is `http(s)://<nexus-host>/integrations/github/callback`. None of this blocks the spec — the controller and tests work against mocked HTTP.
- **PHP 8.5 + Laravel 13.6 CSRF-in-tests issue** still present locally. Not introduced by this spec. CI passes on PHP 8.4. Same disclaimer.
