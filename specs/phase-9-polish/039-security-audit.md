---
spec: security-audit
phase: 9
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-24
updated: 2026-06-24
---

# 039 — Security audit: encryption pass, Horizon allow-list, agent fingerprinting, rate-limit coverage

## Goal
The good news from the audit pass: there are no plaintext secrets in
the database. GitHub tokens are `encrypted` cast, passwords are
bcrypt-hashed, agent tokens are SHA-256 hashed, webhook signatures
are stored verbatim for forensic replay (intentional). The bad news:
that contract is invisible to anyone reading the codebase a year
from now. There are no tests pinning "GitHub token never leaks
through `toArray()`" or "agent token hash isn't reversible." If a
future refactor accidentally drops `$hidden` on a model, nothing
catches it.

Three concrete shifts:

1. **Lock the secret-handling invariants with tests.** One test per
   secret type asserting (a) the stored shape is what we expect
   (encrypted ciphertext != plaintext, bcrypt hash != plaintext,
   SHA-256 hash != plaintext), (b) the model's `$hidden` / Inertia
   serialization never emits the plaintext or the stored shape, (c)
   `forceFill + save` round-trips correctly. Also pin revoked /
   rotated token rejection paths.
2. **Externalize Horizon's allow-list.** Today the production gate
   is a hard-coded empty array with a `TODO(phase-9)` comment. Add
   a `HORIZON_ALLOW_LIST` env var (comma-separated emails) that the
   gate reads, document the deploy flow.
3. **Agent fingerprinting (opt-in).** New `fingerprint_enabled` +
   `fingerprint_hash` columns on `agent_tokens`. When opt-in is
   set, `AuthenticateAgent` middleware computes a per-request
   fingerprint (IP + User-Agent, hashed) and rejects on mismatch.
   Default is **off** for backward compatibility; tokens issued
   pre-039 stay opt-out unless rotated. Token issuance UI gets a
   checkbox.
4. **Rate-limit coverage audit.** Manual sync endpoints (POST
   `/repositories/{repo}/sync`, `/issues/sync`, etc.), webhook
   retry, website probe — none are throttled today. Add
   `throttle:N,1` per-user limits.
5. **Written security audit checklist** at `docs/security.md` —
   one row per secret + handling + test reference. Operator signs
   off before deploy.

Roadmap refs: §16 Security Requirements (authentication / authz /
secrets / webhooks / agents / rate limiting), §17 Observability,
§26 Production Deployment Notes.

## Scope

**In scope:**

- **Secret invariant tests** (`tests/Feature/Security/`):
  - `GithubConnectionSecretTest.php` — `access_token` + `refresh_token`
    are encrypted at rest (ciphertext != plaintext); `toArray()` /
    Inertia share / API serialization never expose either; `$hidden`
    list is enforced.
  - `UserPasswordSecretTest.php` — bcrypt hashed at rest; `toArray()`
    + `auth.user` Inertia share don't expose `password` or
    `remember_token`.
  - `AgentTokenSecretTest.php` — plaintext token never persisted
    (only `hashed_token`); SHA-256 hash isn't reversible (synthetic
    plaintext + hash mismatch); `toArray()` doesn't expose the
    hash; revoked token returns 401 + dispatches `agent.auth.failure`
    (intersects spec 038's path).
  - `WebhookSignatureAuditTest.php` — stored `signature` column is
    the raw header; signature verification is timing-safe
    (`hash_equals` round-trip); missing secret on config fails
    closed.

- **Horizon allow-list externalization.**
  - `config/horizon.php` — new key `'allow_list' => array_filter(
    array_map('trim', explode(',', env('HORIZON_ALLOW_LIST', ''))))`.
  - `HorizonServiceProvider::gate()` — read `config('horizon.allow_list')`
    instead of the hard-coded `[]`. Local + testing path unchanged.
  - `.env.example` — add `HORIZON_ALLOW_LIST=` (empty) with a
    comment explaining production deploy populates it.
  - `tests/Feature/Security/HorizonGateTest.php` — verified user
    in local env can view; verified user not in `allow_list` in
    `APP_ENV=production` cannot; user in `allow_list` can.

- **Agent fingerprinting opt-in.**
  - Migration `add_fingerprint_to_agent_tokens_table`:
    ```php
    $table->boolean('fingerprint_enabled')->default(false);
    $table->string('fingerprint_hash', 64)->nullable();
    ```
    Default `false` keeps existing tokens working.
  - `AgentToken` model — add both to `$fillable`.
  - `IssueAgentTokenAction::execute(Host $host, bool $fingerprintEnabled = false)`
    — accept the opt-in flag, persist `fingerprint_enabled`. Don't
    compute the hash yet — it's set on the first successful request
    (one-time binding to the first observed fingerprint).
  - `AuthenticateAgent::handle()` — after the existing token-lookup
    pass, when `$token->fingerprint_enabled` is true:
    1. Compute `hash('sha256', $request->ip().'|'.$request->userAgent())`.
    2. If `$token->fingerprint_hash` is `null` → persist + continue
       (first-binding).
    3. If `$token->fingerprint_hash !== $computed` → 401 +
       `agent.auth.failure` activity event with
       `reason: fingerprint_mismatch`.
  - Token rotation (`RotateAgentTokenAction`) — preserves the
    `fingerprint_enabled` flag from the previous token but resets
    the `fingerprint_hash` to null (new token re-binds on next
    request).
  - UI: token-issue panel (existing `AgentTokenPanel.vue`) gets a
    checkbox "Bind to first observed IP + browser" with a tooltip
    explaining the trade-off (tighter security, but agent
    migrations need a re-issue).

- **Rate-limit coverage on manual sync + retry + probe.**
  Add `'throttle:<limit>,1'` middleware to:
  - `POST /repositories/{repo}/sync` — 10/min/user
  - `POST /repositories/{repo}/issues/sync` — 10/min/user
  - `POST /repositories/{repo}/pulls/sync` — 10/min/user
  - `POST /repositories/{repo}/workflow-runs/sync` — 10/min/user
  - `POST /repositories/sync-all` — 2/min/user (bigger fan-out)
  - `POST /monitoring/websites/{website}/probe` — 20/min/user
  - `POST /settings/webhook-deliveries/{delivery}/retry` — 30/min/user
  - 429 response is the standard Laravel JSON shape; the UI's
    existing `usePage().props.errors` path surfaces it.
  - Tests: one per route asserting 429 after the limit + the
    `Retry-After` header is set.

- **Security audit document.**
  `docs/security.md` — markdown table of every secret + its
  handling + test reference + sign-off checkbox. Format:
  ```
  | Secret | Storage | Cast | Hidden | Test | Status |
  | GitHub access_token | github_connections.access_token | encrypted | yes | GithubConnectionSecretTest | ☐ |
  | ... |
  ```
  Document is the operator's pre-deploy checklist, not a runtime
  artifact.

**Out of scope:**

- **Multi-tenant team permissions (§16.2).** The roadmap lists
  roles + permissions but phase-1 is single-tenant. Owners /
  admins / developers / viewers is its own phase-10 spec.
- **IP allowlist for agents (§16.5 — "later").** The roadmap
  explicitly defers this. Fingerprinting is the lighter-weight
  step that doesn't require an admin to maintain per-host IP
  CIDR ranges.
- **Audit log table for security events.** Activity events
  already cover `agent.auth.failure`. A dedicated `audit_log`
  table for "user logged in / token rotated / connection
  disconnected" is its own polish spec.
- **`AlertNotificationService`** (email / Slack / webhook
  notifications) — still deferred from Phase 7.
- **Two-factor auth.** Roadmap §16.1 doesn't list it; phase-2
  feature, not phase-9 polish.
- **Webhook signing rotation.** The current
  `GITHUB_WEBHOOK_SECRET` is single-value; rotating it without
  downtime is its own spec (the cleanest approach uses a list of
  acceptable secrets during the rotation window).
- **Webhook endpoint IP rate-limiting.** GitHub's webhook
  delivery IPs are wide and change; the signature verification
  is the actual gate. Adding IP throttling would either be
  vacuous (allow GitHub's whole range) or fragile (chase their
  range list). Defer.

## Plan

1. **Secret invariant tests.** Four files under
   `tests/Feature/Security/`. Each one fails fast if `$hidden`
   drops a column or a cast changes. Use `assertSame` on raw
   `toArray()` keys + `assertStringNotContainsString` on Inertia
   render output.

2. **Horizon allow-list.** Edit `config/horizon.php` to expose
   `allow_list`, update `HorizonServiceProvider::gate()`, add
   `HORIZON_ALLOW_LIST=` to `.env.example`. One test file.

3. **Agent fingerprinting.** Migration + model + middleware patch
   + action signature + UI checkbox. Token rotation preserves the
   flag. Tests cover: enabled-with-no-hash binds; enabled-with-
   match passes; enabled-with-mismatch 401s + dispatches event;
   disabled bypasses entirely.

4. **Rate-limit middleware.** Add `'throttle:10,1'` etc. to the
   listed routes. Tests confirm 429 after limit + `Retry-After`.

5. **Security audit doc.** `docs/security.md` written last (after
   all the above land + tests pin them), so the test references
   are accurate.

6. **Pint clean, suite green, build clean. Self-review with
   `superpowers:code-reviewer`. PR. Watch CI. Pause for merge.**

## Acceptance criteria
- [ ] Every secret has an invariant test pinning its storage
      shape + Inertia/`toArray()` non-leakage.
- [ ] `HORIZON_ALLOW_LIST` env var drives the production gate;
      empty list = no access (the current safe default).
- [ ] Agent token issuance accepts an opt-in fingerprint flag;
      enabled tokens bind to first observed `IP + User-Agent`
      and 401 on subsequent mismatch + dispatch
      `agent.auth.failure`.
- [ ] Manual sync endpoints (4 routes), repo sync-all, website
      probe, webhook retry are all rate-limited per-user with
      sensible limits.
- [ ] `docs/security.md` lists every secret + handling + test
      reference, with a pre-deploy sign-off checklist.
- [ ] Pint clean. `php artisan test` green. `npm run build`
      clean.

## Files touched
- `database/migrations/2026_06_*_add_fingerprint_to_agent_tokens_table.php` — created
- `app/Models/AgentToken.php` — fillable + cast additions
- `app/Domain/Docker/Actions/IssueAgentTokenAction.php` — accept fingerprint flag
- `app/Domain/Docker/Actions/RotateAgentTokenAction.php` — preserve flag, reset hash
- `app/Http/Middleware/AuthenticateAgent.php` — fingerprint check branch
- `app/Providers/HorizonServiceProvider.php` — read allow_list config
- `config/horizon.php` — `allow_list` key
- `.env.example` — `HORIZON_ALLOW_LIST=`
- `routes/web.php` — throttle middleware on manual sync routes + webhook retry + probe
- `resources/js/Components/Hosts/AgentTokenPanel.vue` — fingerprint checkbox + form binding
- `docs/security.md` — created
- `tests/Feature/Security/GithubConnectionSecretTest.php` — created
- `tests/Feature/Security/UserPasswordSecretTest.php` — created
- `tests/Feature/Security/AgentTokenSecretTest.php` — created
- `tests/Feature/Security/WebhookSignatureAuditTest.php` — created
- `tests/Feature/Security/HorizonGateTest.php` — created
- `tests/Feature/Agent/AgentFingerprintTest.php` — created
- `tests/Feature/Security/ManualSyncRateLimitTest.php` — created

## Work log
Dated notes as work progresses.

### 2026-06-24
- Drafted from `_template.md`. Audit found no plaintext secrets;
  spec scope shifted from "fix leaks" to "lock invariants + close
  the four §16 gaps (Horizon allow-list, agent fingerprinting,
  rate-limit coverage, sign-off doc)".
- Branch `spec/039-security-audit` cut off main.
- Tracking issue #115.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **Fingerprint first-binding race.** Two concurrent first
  requests from the same agent could both see `null` hash + both
  write. The second write wins; first request's response is
  unaffected. Acceptable race; document.
- **Rate-limit user key.** Laravel's default `throttle:N,1`
  buckets by IP for unauthenticated routes, by user for
  authenticated ones. All the spec 039 throttled routes are
  inside the `auth + verified` group, so per-user is the
  default — no custom limiter needed.
- **`HORIZON_ALLOW_LIST` parsing.** Comma-separated with
  trimmed values. Empty value = empty list (no access in
  production). A future polish could move to a database table +
  Settings UI; phase-9 keeps env-driven.
