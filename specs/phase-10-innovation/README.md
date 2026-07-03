# Phase 10 — Future Innovation

Source: [roadmap §Phase 10](../../nexus_control_center_roadmap.md#phase-10--future-innovation).

## Phase goal
Phases 0–9 shipped a complete operator-facing product: auth, projects,
GitHub integration, webhooks + activity feed, deployments + CI/CD,
website monitoring, Docker host agent, alerts engine, analytics +
health scores, and production polish. Phase 10 pushes past
"complete" into "innovative." The roadmap lists 16 candidate features;
Phase 10 slices six of them into shippable specs and defers the rest
to future phases that need their own brainstorming before they can be
scoped as anything but a wishlist.

Phase 10 closes when notifications reach operators outside the app,
the command palette works, LLM-powered signal shows up on the
Overview + PR pages, health-score weights are user-tunable + the
alert engine gets its second half, and a public status page is
live.

## Tasks

| # | Task | Status |
|---|------|--------|
| 042 | `AlertNotificationService` — email + Slack + generic webhook channels, per-user routing preferences (severity, source, channel), rate-limit + dedupe, delivery observability | 🟢 |
| 043 | Global command palette — `Cmd+K` fuzzy search across routes + entities (projects, repos, alerts, hosts, websites) via a shared indexer, keyboard-only navigation, recent actions | 🟢 |
| 044 | AI daily briefing — LLM-generated morning digest ("yesterday: X new issues, Y merged PRs, Z alerts, N things that look off"), delivered via spec 042, per-user opt-in + delivery time | ⬜ |
| 045 | AI PR risk score + project health explanation — LLM-scored PR risk tag on webhook arrival, natural-language "why" overlay on Phase 8 health-score card | ⬜ |
| 046 | User-tunable health-score weights + metric-driven alert rules — settings page for Phase 8's formula, §6.8 specification pattern for alert evaluators (queue backlog trend, deploy frequency drop, uptime slope) | ⬜ |
| 047 | Public status page generator — unauthenticated `/status/{slug}` aggregating monitoring uptime + system alerts + subscribe form; per-project toggle in Settings | ⬜ |

## Acceptance criteria (phase-level)
- [ ] Operators receive alerts via at least one channel outside the
      app (email, Slack, generic webhook). Per-user routing decides
      which severities / sources fire which channel.
- [ ] `Cmd+K` opens a palette from any page; fuzzy-searches routes +
      user-scoped entities; keyboard-only navigation works.
- [ ] A morning digest arrives on schedule (per-user configurable
      hour + timezone) summarizing yesterday's activity. Delivery
      rides on spec 042.
- [ ] Every incoming PR gets an LLM-derived risk tag surfaced on the
      Work Items queue + PR drawer. Each project health-score card
      carries a natural-language "why" explanation.
- [ ] Health-score weights are editable per-user (falls back to
      defaults). At least two metric-driven `AlertRule` evaluators
      ship (queue backlog trend + one other).
- [ ] Public `/status/{slug}` renders monitoring uptime + open
      system alerts for opted-in projects. Rate-limited, cacheable.
- [ ] Pint clean, tests green, build clean. CI green for each spec PR.

## Scope notes

### Deferred to Phase 11+ (each is its own multi-spec initiative)

These roadmap items warrant a brainstorming session before they can
land in a phase README:

- **Custom dashboard builder** — user-configurable widget layout
  persistence. Needs a layout format + a drag-drop UI + a
  per-widget config schema. Multi-spec effort.
- **Widget marketplace** — plugin runtime with sandboxing +
  installation + versioning. Enormous surface; needs a threat model.
- **Native mobile app** — separate codebase; requires an API layer
  the Inertia-first app deliberately doesn't have.
- **Kubernetes support** — K8s-API-based agent (not the Phase 6
  Docker-agent shape). Multi-spec: manifest, ingestion, UI.
- **Cloud provider integrations** — AWS/GCP/Azure resource
  discovery. Each provider is its own spec; API surfaces don't
  overlap.
- **Synthetic browser checks + screenshot monitoring** — headless
  Chromium infra. Runs somewhere with GPU/memory constraints the
  Phase 5 HTTP probe doesn't have.
- **Dedicated incident management** — PagerDuty-lite (on-call
  rotations, escalation policies, acks). Overlaps with spec 042 but
  is a distinct product surface.
- **Team reports** — weekly PDF/HTML digest. Depends on spec 042's
  delivery channel; adds a heavy template rendering surface.

### LLM dependencies (specs 044 + 045)

Both AI specs require an LLM API key + a config knob. The reference
provider is Anthropic (matches the surrounding codebase's AI usage);
implementations should keep the provider swappable via a
`config('services.llm.*')` block. Each spec ships behind an
`AI_FEATURES_ENABLED=true` env gate so operators can decide.

### AlertNotificationService is the keystone

Spec 042 unblocks specs 044 (delivers the daily briefing), 045
(delivers the PR risk score notification), and 047 (delivers the
public status page subscribe-form notifications). Ship it first.

### What NOT to scope into Phase 10

- **Complete rewrite of Alerts UI.** Phase 7 delivered it; Phase 10
  extends the delivery layer, not the storage / display.
- **Multi-tenant migration.** Still deferred; each spec here
  assumes the current single-tenant / per-user shape.
- **Real-time delivery to mobile push.** Requires the mobile app,
  which is Phase 11+.
