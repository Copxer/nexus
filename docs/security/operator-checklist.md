# Nexus security pre-deploy checklist

Spec 039 introduced this document as the operator's sign-off before
a Nexus deployment goes live. Every row maps a secret or hardening
control to its storage shape, the test that pins the contract, and
a checkbox to tick.

A future polish spec can convert this into a runtime self-check
(`php artisan nexus:audit`); today it's a manual review.

## 1. Secrets at rest

| Secret | Storage | Cast / hash | Hidden in serialization | Test |  |
|--------|---------|-------------|-------------------------|------|---|
| GitHub access token | `github_connections.access_token` | `encrypted` (Laravel APP_KEY) | yes | [`GithubConnectionSecretTest`](../../tests/Feature/Security/GithubConnectionSecretTest.php) | ☐ |
| GitHub refresh token | `github_connections.refresh_token` | `encrypted` | yes | [`GithubConnectionSecretTest`](../../tests/Feature/Security/GithubConnectionSecretTest.php) | ☐ |
| User password | `users.password` | `hashed` (bcrypt) | yes | [`UserPasswordSecretTest`](../../tests/Feature/Security/UserPasswordSecretTest.php) | ☐ |
| Agent bearer token | `agent_tokens.hashed_token` | SHA-256 hash; plaintext shown once at issuance, never persisted | yes | [`AgentTokenSecretTest`](../../tests/Feature/Security/AgentTokenSecretTest.php) | ☐ |
| Webhook signature audit | `github_webhook_deliveries.signature` | Raw `X-Hub-Signature-256` header stored verbatim for forensic replay (NOT a secret to protect; the secret is in env) | n/a | [`WebhookSignatureAuditTest`](../../tests/Feature/Security/WebhookSignatureAuditTest.php) | ☐ |
| GitHub webhook secret | `config('services.github.webhook_secret')` ← env `GITHUB_WEBHOOK_SECRET` | env-only; never persisted | env | [`WebhookSignatureAuditTest`](../../tests/Feature/Security/WebhookSignatureAuditTest.php) | ☐ |

**Sign-off:** No plaintext secrets land in the database. The encrypted
casts decrypt only when accessed via the model; `$hidden` keeps every
secret out of `toArray()` / Inertia / JSON responses.

## 2. Webhook signature verification

- HMAC-SHA-256 (`sha256=<digest>` shape, matches GitHub's spec).
- `hash_equals` for timing-safe comparison.
- Fails closed on missing secret (empty config = always reject) and
  missing header.
- Raw body is verified, not parsed JSON — no encoding drift.
- 401 on invalid signature; no row written to
  `github_webhook_deliveries`.

**Sign-off ☐:** Tested in
[`WebhookSignatureAuditTest`](../../tests/Feature/Security/WebhookSignatureAuditTest.php)
+ the existing
[`VerifyGitHubWebhookSignatureActionTest`](../../tests/Feature/GitHub/Webhooks/VerifyGitHubWebhookSignatureActionTest.php).

## 3. Horizon dashboard allow-list

- Production gate: `HORIZON_ALLOW_LIST` env var (comma-separated
  emails) consulted by `HorizonServiceProvider::gate()`.
- Empty list = no access (fail closed).
- Local + testing env: any verified user passes (zero friction
  for solo dev).

**Sign-off ☐:** Confirm `HORIZON_ALLOW_LIST` is set in the production
`.env` before the dashboard becomes reachable. Tested in
[`HorizonGateTest`](../../tests/Feature/Security/HorizonGateTest.php).

## 4. Agent telemetry endpoint

- Bearer-token auth via SHA-256 hash lookup (no plaintext in DB).
- Per-token rate limit: 60 req/min, 429 with `Retry-After` header
  when exceeded.
- Rejects on revoked tokens, archived hosts, missing/invalid
  bearer.
- Opt-in fingerprint binding (spec 039): when enabled, the first
  successful request binds `fingerprint_hash = sha256(ip + '|' +
  user_agent)`. Subsequent requests with a different fingerprint
  return 401 + dispatch `agent.auth.failure` activity event.
- Token rotation preserves the opt-in flag; resets the hash for
  re-binding.

**Sign-off ☐:** Tested in
[`AuthenticateAgentMiddlewareTest`](../../tests/Feature/Agent/AuthenticateAgentMiddlewareTest.php)
+ [`AgentFingerprintTest`](../../tests/Feature/Agent/AgentFingerprintTest.php)
+ [`AgentTokenSecretTest`](../../tests/Feature/Security/AgentTokenSecretTest.php).

## 5. Rate-limit coverage on authenticated endpoints

Per-user limits via Laravel's `throttle:N,1` middleware:

| Endpoint | Limit |
|----------|-------|
| `POST /repositories/{repo}/sync` | 10/min |
| `POST /repositories/{repo}/issues/sync` | 10/min |
| `POST /repositories/{repo}/pulls/sync` | 10/min |
| `POST /repositories/{repo}/workflow-runs/sync` | 10/min |
| `POST /repositories/sync-all` | 2/min (bigger fan-out) |
| `POST /monitoring/websites/{website}/probe` | 20/min |
| `POST /settings/webhook-deliveries/{delivery}/retry` | 30/min |
| `POST /verify-email/*` | 6/min (existing) |
| `POST /email/verification-notification` | 6/min (existing) |

**Sign-off ☐:** Tested in
[`ManualSyncRateLimitTest`](../../tests/Feature/Security/ManualSyncRateLimitTest.php).

## 6. Operational constraints

- **`HORIZON_ALLOW_LIST` email format.** Comma-separated, no
  embedded commas. RFC 5321 permits commas inside quoted local-
  parts (`"a,b"@example.com`) but they'd split the list — keep
  operator emails plain.
- **Agent fingerprint binding under proxies.** The middleware
  computes the fingerprint from `$request->ip()` + User-Agent.
  `bootstrap/app.php` trusts only loopback proxies, so an agent
  ingressing through a CDN / load balancer that forwards
  `X-Forwarded-For` would bind to the **proxy's** IP, not the
  agent's. Today agents talk directly to the server, so this
  isn't a problem; if production routing changes, expand the
  `trustProxies` list before turning fingerprinting on for
  production tokens.

## 7. Deferred to follow-ups

Documented for visibility; not blockers for spec 039 sign-off:

- **Multi-tenant team permissions** (§16.2) — phase-10 migration.
- **IP allowlist for agents** (§16.5: "later") — fingerprinting
  is the lighter-weight phase-9 step.
- **Audit log table** for security events — activity events
  cover `agent.auth.failure`; a dedicated table is its own polish
  spec.
- **`AlertNotificationService`** — email / Slack / webhook
  notifications, deferred from Phase 7.
- **Two-factor auth** — phase-2 feature, not phase-9 polish.
- **Webhook secret rotation** — single-value today; rotation
  needs a "list of acceptable secrets" mode.
- **Webhook endpoint IP rate-limiting** — GitHub's IP range is
  wide + changes; signature verification is the actual gate.

## Pre-deploy checklist summary

- [ ] §1: All `$hidden` + `encrypted` / `hashed` casts in place.
- [ ] §2: Webhook signature verification fails closed.
- [ ] §3: `HORIZON_ALLOW_LIST` populated for production env.
- [ ] §4: Agent fingerprinting opt-in available on token issuance.
- [ ] §5: Manual sync / probe / retry endpoints all throttled.
- [ ] Run the suite: `php artisan test --filter=Security` passes.
