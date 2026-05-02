# Phase 6 — Docker Host Agent MVP

Source: [roadmap §19 Phase 6](../../nexus_control_center_roadmap.md), §8.7 Docker Hosts.

## Phase goal
Stand up Docker host + container monitoring end-to-end via a pull-from-agent model. By the end of phase 6, a user can register a host under a project, mint an agent token, run a small reference script on the host that posts telemetry to `/agent/telemetry`, and see the host + its containers with live CPU/memory on the Hosts page. Hosts that go silent past their timeout flip to offline and emit an activity event; a recovery does the same. The Overview Hosts KPI is fed by real data.

## Tasks

| # | Task | Status |
|---|------|--------|
| 026 | Hosts + agent tokens scaffolding (CRUD + token rotation) | 🟢 |
| 027 | Telemetry ingestion endpoint + reference agent script | 🟢 |
| 028 | Hosts UI (index + show + project Hosts tab) | ⬜ |
| 029 | Host offline detection + activity events + Overview KPI wiring | ⬜ |

## Acceptance criteria (phase-level)
- [ ] User can create / edit / archive a host under a project.
- [ ] User can mint an agent token (shown once) and rotate it.
- [ ] Agent endpoint accepts signed telemetry, rejects invalid tokens, and is rate-limited.
- [ ] Host + container snapshots persist with CPU / memory / disk / network fields.
- [ ] Hosts index + detail pages render real data with empty/loading/error states.
- [ ] Host that hasn't reported within its timeout flips to `offline` and emits a `host.offline` activity event; recovery emits `host.recovered`.
- [ ] Overview Hosts KPI reflects real online/offline counts (no mocks).
- [ ] Sidebar `Hosts` entry is enabled and routes to the hosts listing.
- [ ] Pint + tests + build clean. CI green for every spec PR.

## Scope notes
- Alerts (the dedicated `alerts` table + acknowledge/resolve flow) are Phase 7. Phase 6 surfaces host issues as activity events only.
- Container ↔ deployment correlation, Kubernetes, and cloud-provider integrations are out of scope.
- The reference agent in 027 is a small Node script documenting the contract, not a production-ready Go binary.
