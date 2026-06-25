---
spec: core-flow-test-coverage
phase: 9
status: in-progress   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-06-25
updated: 2026-06-25
---

# 040 — Core-flow test coverage + believable seeder

## Goal
The audit pass found ~125 test files but **no test that chains
multiple domains together end-to-end**. Every test pins a single
action (TriggerAlertAction, RecordWebsiteCheckAction, etc.); a
refactor that breaks the contract between "webhook arrives" and
"alert opens" wouldn't trip anything. Phase 9's acceptance text is
explicit: every critical flow needs at least one end-to-end test
pinning the contract.

The seeder is similarly thin. `php artisan db:seed` produces
projects + repos but no alerts, hosts, websites, work items, or
webhook deliveries — a new user lands on an Overview that screams
"this app is empty." Spec 040 fixes both gaps in one pass: 5 end-
to-end tests pinning the §Phase 9 flows + a richer seeder that
produces a screenshot-ready demo dataset.

Three concrete shifts:

1. **5 end-to-end test files** under `tests/Feature/EndToEnd/`,
   each chaining the actions a real user/agent/webhook would
   exercise. They use the actual controllers + jobs + actions —
   no mocks except for outbound network (Http::fake for
   GitHub, the probe action for websites).
2. **3 helper methods on `tests/TestCase.php`** — `verifiedUser`,
   `signedGitHubWebhook`, `projectWithRepository` — to cut
   boilerplate across the new tests + replace ~30 lines of
   per-file scaffolding scattered across existing suites.
3. **Seeder pass** — new `AlertSeeder` / `WebsiteSeeder` /
   `HostSeeder` + extension of `RepositorySeeder` to seed issues
   + PRs + workflow runs. `DatabaseSeeder` wires them up. After
   `php artisan db:seed` the dashboard shows real-looking
   alerts, hosts, websites, and a populated work-items page.

Roadmap refs: §Phase 9 acceptance criteria ("every critical flow
has an end-to-end test"), §24 Testing Strategy, §13 demo /
onboarding requirements.

## Scope

**In scope:**

- **`tests/Feature/EndToEnd/RegistrationAndOverviewTest.php`** —
  guest registers via `POST /register` → unverified user redirect →
  signed `GET /verify-email/{id}/{hash}` → email-verified → lands
  on `/overview`. Asserts the journey, not individual steps.
- **`tests/Feature/EndToEnd/ProjectAndRepositoryFlowTest.php`** —
  verified user creates a project (`POST /projects`) → imports a
  repository (via the GitHub import endpoint, with `Http::fake`
  responding to `/repos/{owner}/{repo}`) → asserts the repository
  row + project linkage. A second leg of the test runs the
  issues + PRs sync jobs synchronously (action calls, not via the
  queue) → asserts the issue + PR rows landed.
- **`tests/Feature/EndToEnd/WebhookToAlertFlowTest.php`** —
  signed GitHub `workflow_run` webhook with
  `conclusion: failure` + `head_branch == default_branch` hits
  `POST /webhooks/github` → `ProcessGitHubWebhookJob` runs
  synchronously (via `Bus::dispatchSync` or direct action call) →
  `WorkflowRun` row updated → `Alert` opens with
  `source: deployment`. Then the user `POST
  /alerts/{alert}/resolve` → asserts the alert flips to
  resolved + the activity event fires.
- **`tests/Feature/EndToEnd/AgentTelemetryAndRecoveryFlowTest.php`** —
  agent issues telemetry → host flips `pending` → `online` +
  snapshot inserted. Then `DetectOfflineHostsJob` runs against a
  host that hasn't sent telemetry past the heartbeat → host
  flips `online` → `offline` + `host.offline` alert opens. Then a
  fresh telemetry tick arrives → host flips `offline` → `online`
  again + the alert auto-resolves.
- **`tests/Feature/EndToEnd/WebsiteProbeAndAlertFlowTest.php`** —
  user creates a website → `RecordWebsiteCheckAction` runs with
  a failed probe result (stub the `RunWebsiteProbeAction`) →
  asserts a `website.down` alert opens. A second probe succeeds
  → alert auto-resolves.

- **`tests/TestCase.php` helpers** — replace scattered per-file
  scaffolding. Three additions, no over-engineering:
  - `protected function verifiedUser(array $attrs = []): User`
  - `protected function signedGitHubWebhook(string $event,
    array $payload, string $delivery = null): array` — returns
    the `[headers, raw_body]` tuple for a signed webhook request.
  - `protected function projectWithRepository(User $owner,
    array $repoAttrs = []): array` — returns `[$project,
    $repository]`. Used by the project+repo flow + the
    webhook+alert flow (which needs a repo to map the
    `workflow_run` to).

- **Seeders.**
  - `database/seeders/AlertSeeder.php` — new. Seeds 4 alerts:
    one open critical website-down, one acknowledged warning
    workflow-failed, one resolved host-offline (recent
    history), one muted website-slow. Hooks into the
    `RepositorySeeder`-created projects.
  - `database/seeders/WebsiteSeeder.php` — new. Seeds 3
    websites across the existing projects: one happy
    (last_status `up`), one slow (`slow`), one down (`down`).
    Each website gets a small check history (last 20 minutes)
    so the spec-025 uptime KPI shows real numbers, not 100%.
  - `database/seeders/HostSeeder.php` — new. Seeds 2 hosts:
    one online with 5 containers + recent metric snapshots,
    one offline (past the heartbeat threshold). Demonstrates
    both states without needing a running agent.
  - `database/seeders/RepositorySeeder.php` — extended. For
    each existing seeded repo, add 5 GithubIssue rows (mix of
    open / closed) + 3 GithubPullRequest rows (mix of open /
    merged) + 4 WorkflowRun rows (mix of success / failure on
    default branch). Drives the Work Items page + Deployments
    timeline.
  - `DatabaseSeeder` — call the four new seeders in dependency
    order (Project → Repository → Issue+PR+WorkflowRun → Host
    + Container → Website + Check → Alert).

- **Tests for the seeders** —
  `tests/Feature/Seeders/DemoSeedSmokeTest.php`. Runs
  `Artisan::call('db:seed', ['--force' => true])` against a
  fresh test DB, then asserts: `Project::count() >= 4`,
  `Repository::count() >= 8`, `Alert::query()->open()->exists()`,
  `Host::query()->online()->exists()`, `Website::count() >= 3`,
  `GithubIssue::count() >= 40`. Catches seeder regressions
  without pinning every row.

**Out of scope:**

- **Browser-level tests** — no Dusk, no Playwright. The
  end-to-end tests are HTTP-level via Laravel's `TestCase`;
  they assert DB state + response shape, not pixel layouts.
- **JS unit tests** — Vitest stays deferred (separate chore PR
  outside Phase 9's scope per the phase README).
- **Performance benchmarks** — `tests/runtime` budget stays
  the same. New tests must finish in < 1s each; the suite
  total should stay under 90s on CI.
- **Multi-user / team coverage** — single-tenant flows only;
  multi-team end-to-end is Phase 10 work.
- **Failure-mode coverage of every error branch** — each
  end-to-end test pins the **happy path** (the contract that
  matters). Specific error branches are already covered by
  the unit-level tests; spec 040 doesn't re-test them at the
  end-to-end altitude.
- **Demo seed images / fixture assets** — the seeder
  populates relational data only. Project icons / colors
  use the existing palette enum; no image assets shipped.

## Plan

1. **Helpers first.** `tests/TestCase.php` gets the three
   helpers. Bare-bones implementations; convert one existing
   test file (eg. `AuthenticateAgentMiddlewareTest`) to use
   them as a smoke test of the helper API, then leave the
   rest unchanged for this spec.

2. **Seeders.** Build the four seeders + extend the repo
   seeder. Run `php artisan db:seed` against a fresh local DB,
   walk the dashboard manually, confirm: Overview, Alerts,
   Hosts, Websites, Work Items, Activity all show data.

3. **End-to-end tests.** Write one file at a time, in the
   order listed in **In scope**. Each test file should:
   - Use `RefreshDatabase`.
   - Use the new helpers where applicable.
   - Stub outbound network (`Http::fake()` for GitHub,
     container-bind `RunWebsiteProbeAction` for the probe).
   - Chain the actual production controllers / jobs / actions —
     no shortcuts that skip middleware or skip the queue
     boundary (use `Bus::dispatchSync` to run queued jobs
     inline).
   - End with explicit DB-state assertions on the final row(s)
     that mattered (`assertSame(AlertStatus::Open,
     $alert->fresh()->status)` etc.).

4. **Seeder smoke test** last. Pins the seeder against the
   seeders that drive it.

5. **Pint clean, suite green, build clean. Self-review with
   `superpowers:code-reviewer`. PR. Watch CI. Pause for
   merge.**

## Acceptance criteria
- [ ] Five end-to-end test files exist under
      `tests/Feature/EndToEnd/`, one per flow. Each chains the
      production actions + asserts the final terminal state.
- [ ] `tests/TestCase.php` exposes `verifiedUser`,
      `signedGitHubWebhook`, `projectWithRepository` helpers.
- [ ] `php artisan db:seed` against a fresh DB produces:
      ≥ 4 projects, ≥ 8 repositories with linked issues + PRs
      + workflow runs, ≥ 2 hosts with metric snapshots,
      ≥ 3 websites with check history, ≥ 4 alerts in mixed
      states.
- [ ] `DemoSeedSmokeTest` runs `db:seed` and asserts those
      counts.
- [ ] Full suite runtime stays under 90s on CI (current ≈ 60s,
      headroom for 5 new feature tests is ~30s).
- [ ] Pint clean. `php artisan test` green. `npm run build`
      clean.

## Files touched
- `tests/TestCase.php` — add 3 helper methods.
- `tests/Feature/EndToEnd/RegistrationAndOverviewTest.php` — created.
- `tests/Feature/EndToEnd/ProjectAndRepositoryFlowTest.php` — created.
- `tests/Feature/EndToEnd/WebhookToAlertFlowTest.php` — created.
- `tests/Feature/EndToEnd/AgentTelemetryAndRecoveryFlowTest.php` — created.
- `tests/Feature/EndToEnd/WebsiteProbeAndAlertFlowTest.php` — created.
- `tests/Feature/Seeders/DemoSeedSmokeTest.php` — created.
- `database/seeders/AlertSeeder.php` — created.
- `database/seeders/WebsiteSeeder.php` — created.
- `database/seeders/HostSeeder.php` — created.
- `database/seeders/RepositorySeeder.php` — extended with issue
  / PR / workflow-run seeding.
- `database/seeders/DatabaseSeeder.php` — wire up the new
  seeders.

## Work log
Dated notes as work progresses.

### 2026-06-25
- Drafted from `_template.md`. Research surfaced: zero
  existing end-to-end tests; seeder produces empty Alerts /
  Hosts / Websites / Work Items pages. Spec 040 closes both
  gaps in one pass to keep Phase 9 momentum.
- Branch `spec/040-core-flow-test-coverage` cut off main.
- Tracking issue #118.
- Scope shipped as drafted (no late edits requested).

## Open questions / blockers

- **Queue boundary in end-to-end tests.** Two options:
  (a) `Bus::dispatchSync` to run jobs inline, asserting on
  the persisted state after each step. (b) `Bus::fake()` and
  then call the job's `handle()` directly. Picked (a) because
  the spec's intent is "pin the actual production contract"
  and dispatchSync exercises the dispatch path. Mock the
  outbound network (`Http::fake` for GitHub, container-bind
  for the probe), not the queue.
- **Seeder idempotency.** The new seeders use Eloquent
  `create()`, not `updateOrCreate()` — they assume a fresh
  DB. `php artisan migrate:fresh --seed` is the supported
  invocation. Running `db:seed` twice will duplicate rows
  (acceptable for phase 1; idempotent seeders is its own
  polish spec).
- **Demo seeder vs. test fixtures.** Considered keeping the
  seeded demo data separate from the test fixture data.
  Decided to share — the same `AlertSeeder` runs in both
  contexts so a regression in the seeder is caught by
  `DemoSeedSmokeTest`. The end-to-end tests don't use the
  seeder (each builds its own minimal state via factories);
  the seeder smoke test is the one that proves the demo
  works.
