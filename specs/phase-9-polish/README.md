# Phase 9 — Polish & Production Readiness

Source: [roadmap §Phase 9](../../nexus_control_center_roadmap.md), §16
Security Requirements, §17 Observability for Nexus Itself, §18 Error
Handling, §26 Production Deployment Notes.

## Phase goal
Prepare Nexus for daily use. Phases 0–8 built the feature surface;
Phase 9 hardens it. Skeleton loading + empty / error states across
every page so the app never looks broken. Job retry / backoff +
rate-limit surfacing so transient errors self-heal. A "Nexus is
healthy" self-monitoring slice so the operator sees their own
queue / GitHub-rate / webhook backlog. Token-encryption audit +
Horizon production allow-list + agent fingerprinting so the surface
area is small. Core-flow test coverage so regressions on the
critical paths catch themselves in CI. Installation +
deployment + backup docs so a new operator can stand the app up.

Phase 9 closes when the app feels stable for daily use, errors are
visible and actionable, and a fresh operator can deploy it from the
docs alone.

## Tasks

| # | Task | Status |
|---|------|--------|
| 036 | UX polish — skeleton loading, empty / error states, reduced-motion, light-mode toggle, responsive edge cases | ⬜ |
| 037 | Reliability hardening — job retry/backoff, rate-limit response handling, webhook retry UI | ⬜ |
| 038 | Nexus self-monitoring — internal alerts (queue / GitHub-rate / webhook / agent failures) + observability slice | ⬜ |
| 039 | Security audit — token encryption pass, Horizon production allow-list, agent fingerprinting opt-in, rate-limit coverage | ⬜ |
| 040 | Core-flow test coverage — auth → project → repo sync → webhook → alert → resolve, plus seeding-quality fixtures | ⬜ |
| 041 | Production docs — installation guide, deployment playbook (supervisor / systemd), backup strategy, `.env.production.example` | ⬜ |

## Acceptance criteria (phase-level)
- [ ] Every page renders a skeleton state during initial load and
      an explicit empty state when its data source is empty.
- [ ] Inertia-level error boundaries catch + surface unhandled
      exceptions; users see an actionable error UI, not a stack
      trace.
- [ ] `prefers-reduced-motion: reduce` disables every Tailwind
      `animate-*` + `transition` we own. Light-mode toggle persists
      per user across sessions.
- [ ] Job retries follow the §18 matrix (rate-limit → respect
      reset; timeout → next-interval; webhook job → 3 retries
      then `failed`). Failed deliveries surface in the UI.
- [ ] Nexus exposes its own health: queue backlog, GitHub-rate
      remaining, webhook delivery success rate, agent ingestion
      success rate. Each fires an internal alert past threshold.
- [ ] Every secret (GitHub token, webhook secret, agent token, app
      key) is encrypted at rest or hashed irreversibly. Audit log
      proves it. Horizon production dashboard is allow-listed.
- [ ] Every critical flow (registration → project create → repo
      link → webhook ingest → alert trigger → alert resolve) has at
      least one end-to-end feature test pinning the contract.
- [ ] A new operator can stand Nexus up from `docs/installation.md`
      + `docs/deployment.md` alone, with all worker / scheduler /
      reverb processes supervised.
- [ ] Pint clean, tests green, build clean. CI green for each spec PR.

## Scope notes
- **Big deferrals stay deferred.** `AlertNotificationService`
  (email / Slack / webhook), metric-driven `AlertRule` evaluators
  (roadmap §6.8), multi-team / multi-tenant migration, GitHub PR
  review-status sync, analytics CSV export, user-tunable
  health-score weights — none of these land in Phase 9. They're
  follow-on features, not "polish".
- **Vitest + JS unit tests** are a separate chore PR, not phase-
  gated. Phase 9 can land without them.
- **Caching layer** (dashboard payload Redis cache) — flagged as
  Phase 9 polish in spec 012, but Phase 8's analytics queries are
  fast enough at phase-1 scale. Defer to a perf-driven follow-up
  rather than bundling into 037.
- **Horizon dashboard theming** — Horizon ships its own Tailwind.
  Theming it to match Nexus is its own polish spec, post-9.
- Each spec ships its own CI-green PR; the phase closes when 041
  merges.
