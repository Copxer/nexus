---
spec: ai-pr-risk-score-and-project-health-explanation
phase: 10
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-21
updated: 2026-07-21
---

# 045 — AI PR risk score + project health explanation

## Goal

Phase 10 adds an operator-facing AI layer over two existing decision
surfaces: pull requests and project health. Every incoming PR should get a
bounded LLM-derived risk tag that helps the operator decide what to review
first, and each project health-score card should explain in plain language
why the score moved.

The roadmap only names this as "AI PR risk score" and "AI project health
explanation." The Phase 10 README narrows the slice: score PRs on webhook
arrival, surface the tag on the Work Items queue + PR drawer, and add a
natural-language "why" overlay to Phase 8 health-score cards. This spec
keeps that scope narrow: explain existing Nexus signals, do not build a
general AI assistant.

Roadmap refs: §Phase 10 Future Features ("AI PR risk score", "AI project
health explanation"), §3.2 Signal Over Noise, §8.4 GitHub Issues & Pull
Requests, §14.2 Health Score, Phase 10 README LLM dependencies, spec 042
delivery layer, spec 044 shared LLM client/config, spec 046 user-tunable
health-score weights.

## Scope

**In scope:**

- **Environment gate + shared LLM config.** The feature is inert unless
  `AI_FEATURES_ENABLED=true` and `services.llm.*` is configured. Reuse the
  provider-swappable `LlmClient` contract from spec 044 when present rather
  than creating a second AI client.

- **Migration: `pull_request_risk_assessments`.** Persist one current
  assessment per PR plus enough audit context to explain what happened.
  Columns:
  - `id`, `github_pull_request_id` (FK -> `github_pull_requests`, cascade
    delete)
  - `status` (`pending` | `scored` | `failed` | `skipped`)
  - `risk_level` (`low` | `medium` | `high` | `critical`, nullable until
    scored)
  - `risk_score` (unsigned tiny integer 0-100, nullable until scored)
  - `summary` (text, nullable; 1-2 operator-facing sentences)
  - `reasons` (JSON array of short bullets tied to concrete PR signals)
  - `recommended_actions` (JSON array, default empty)
  - `input_snapshot` (JSON; bounded non-secret context sent to the LLM)
  - `prompt_version` (string, e.g. `pr-risk-v1`)
  - `model` (nullable string)
  - `assessed_at`, `failed_at` (nullable timestamps)
  - `error_message` (nullable text)
  - Timestamps.
  - Unique index on `github_pull_request_id` so webhook reprocessing updates
    the current score rather than creating duplicate badges.

- **Migration: `project_health_explanations`.** Persist the latest AI
  explanation for each project health score. Columns:
  - `id`, `project_id` (FK -> `projects`, cascade delete)
  - `status` (`pending` | `explained` | `failed` | `skipped`)
  - `health_score` (unsigned tiny integer captured at explanation time)
  - `health_band` (nullable string matching the existing backend band)
  - `summary` (text; concise explanation)
  - `drivers` (JSON array of scored factors such as alerts, deployments,
    website checks, host/container state, GitHub sync state)
  - `recommended_actions` (JSON array, default empty)
  - `input_snapshot` (JSON; bounded source facts)
  - `prompt_version` (string, e.g. `project-health-explanation-v1`)
  - `model` (nullable string)
  - `explained_at`, `failed_at` (nullable timestamps)
  - `error_message` (nullable text)
  - Timestamps.
  - Unique index on `project_id` for a latest-explanation lookup.

- **Models + enums.** Add `PullRequestRiskAssessment` and
  `ProjectHealthExplanation` models with casts for JSON, enum-like status
  fields, immutable timestamps, and relationships from `GithubPullRequest`
  and `Project`.

- **PR risk input query.** `GetPullRequestRiskInputQuery` builds a bounded
  snapshot for one `GithubPullRequest`. Include existing deterministic facts
  only:
  - PR title/body preview, draft state, mergeability, review/check status,
    additions, deletions, changed files, comments/review comments, age, stale
    state, base/head branches, labels/author when available.
  - Recent workflow/deployment failures for the repository/branch.
  - Open critical/high project alerts and recent health-score band.
  - Repository/project priority signals already stored in Nexus.
  - Links/IDs needed for UI deep links, but no tokens, webhook secrets, raw
    logs, or full patch content.

- **PR risk generation action.** `GeneratePullRequestRiskAssessmentAction`
  builds prompt `pr-risk-v1`, calls the shared `LlmClient`, validates output,
  persists the row, and fails closed. Output contract:
  - `risk_level`: one of `low`, `medium`, `high`, `critical`.
  - `risk_score`: integer 0-100.
  - `summary`: 1-2 short sentences.
  - `reasons`: 1-5 bullets, each tied to a field in `input_snapshot`.
  - `recommended_actions`: 0-4 bullets.

- **Webhook scoring hook.** On GitHub `pull_request` webhook actions that can
  materially change risk (`opened`, `reopened`, `synchronize`,
  `ready_for_review`, `edited`, `review_requested`), dispatch
  `GeneratePullRequestRiskAssessmentJob` after the local PR row is updated.
  The job is unique by PR ID and may be retried on transient LLM errors.

- **Manual backfill command.** Add an artisan command or queued job to score
  currently open PRs for a user/project/repository when AI is enabled. This
  prevents the feature from only applying to PRs that arrive after deploy.

- **Work Items queue UI.** Surface the risk tag in `/work-items` for PR rows:
  badge color by `risk_level`, tooltip/inline text for `summary`, and a
  loading/failed state when assessment is pending or failed. Existing issue
  rows remain unchanged.

- **PR drawer/detail UI.** Add a compact risk panel to the PR detail drawer or
  repository PR list details, depending on the current UI shape. Show score,
  summary, reasons, recommended actions, `assessed_at`, and a "Regenerate"
  action gated by the AI feature flag.

- **Project health explanation query.** `GetProjectHealthExplanationInputQuery`
  builds a bounded snapshot matching the current `ComputeProjectHealthScoreAction`
  inputs after spec 046 weights are applied. Include score, band, active alert
  counts by severity/source, failed deployments/workflows, website/host/container
  health, GitHub sync failures, and recent score delta when available.

- **Project health explanation action.** `GenerateProjectHealthExplanationAction`
  builds prompt `project-health-explanation-v1`, validates output, persists
  the latest explanation, and fails closed. It should explain the existing
  score, not invent a competing score.

- **Health-score integration.** After a project health score is refreshed,
  enqueue explanation generation when the score/band changed materially or the
  previous explanation is stale. Rate-limit by project so a noisy alert or
  monitor cannot trigger repeated LLM calls.

- **Overview health-score overlay.** Add a "Why?" affordance to the Phase 8
  health-score card / risky-projects panel. The overlay shows the latest
  summary, drivers, recommended actions, and timestamp. If no explanation
  exists yet, show a deterministic fallback based on the current score inputs
  and offer a gated regenerate action when AI is enabled.

- **Notifications through spec 042.** When a PR scores `high` or `critical`,
  optionally send a notification through the user's verified spec 042 channels.
  Keep this conservative: one notification per PR per material risk-level
  increase. No notification for unchanged `low`/`medium` scores.

- **Prompt safety + privacy.** Send bounded snapshots, never raw diffs,
  secrets, webhook URLs, access tokens, full PR bodies, full logs, or private
  environment values. Store `input_snapshot` for auditability; do not store
  provider request headers or secrets.

- **Docs.** Update `docs/env.production.example` only if shared AI env keys
  are missing. Extend `docs/security/operator-checklist.md` with PR-risk and
  health-explanation prompt-safety, webhook-trigger, notification, regenerate,
  and rate-limit expectations.

- **Tests.**
  - `GetPullRequestRiskInputQueryTest` — scopes the PR to the user's projects,
    includes bounded facts, excludes other users' data and sensitive fields.
  - `GeneratePullRequestRiskAssessmentActionTest` — happy path, output
    validation, failed LLM call, disabled AI gate, row update on regenerated
    score.
  - `GeneratePullRequestRiskAssessmentJobTest` — unique by PR, fail-closed
    handling, no dispatch when AI is disabled.
  - `PullRequestWebhookRiskDispatchTest` — risk job dispatches after relevant
    webhook actions and skips irrelevant actions.
  - `PullRequestRiskBackfillCommandTest` — backfills only open scoped PRs and
    respects the AI gate.
  - `WorkItemsRiskBadgeTest` — Work Items payload exposes PR risk state and
    renders only for PR rows.
  - `GetProjectHealthExplanationInputQueryTest` — mirrors current health-score
    drivers, scopes to owned projects, caps sampled entities.
  - `GenerateProjectHealthExplanationActionTest` — persists explanation,
    validates/sanitizes output, fails closed on client error.
  - `ProjectHealthExplanationRefreshTest` — generation enqueues after material
    health-score changes and respects stale/rate-limit guards.
  - `ProjectHealthExplanationUiTest` — Overview payload exposes the latest
    explanation and the overlay handles present, pending, and failed states.

**Out of scope:**

- **AI code review.** This spec does not read diffs or suggest line-level code
  changes. It scores operational/review risk from metadata Nexus already owns.
- **Merge blocking / automation.** Risk tags inform humans; they do not block
  merges, approve PRs, request changes, or write GitHub comments.
- **Training a custom risk model.** Prompted LLM analysis over bounded Nexus
  signals is enough for v1.
- **Embeddings or semantic repository indexing.** No vector database, codebase
  search, or repository-content ingestion.
- **Incident summaries.** Roadmap lists AI incident summary separately. Health
  explanations may mention active incidents/alerts but do not generate
  postmortems.
- **A general AI assistant/chat UI.** No free-form prompt box or natural
  language querying over Nexus data.
- **Per-team/multi-tenant routing.** The current product shape remains
  per-user/single-tenant.
- **Discord/PagerDuty-specific AI notifications.** Spec 042 channels are reused;
  new channel drivers are their own specs.

## Plan

1. **Confirm scope before workflow start.** Keep this spec `not-started` until
   the user confirms the draft scope, then open the single spec issue/branch
   per the Nexus workflow.
2. **Migrations + models.** Create `pull_request_risk_assessments` and
   `project_health_explanations`, model relationships, factories, and status
   enums/value helpers.
3. **Input queries.** Build PR-risk and health-explanation snapshot queries
   with strict ownership scoping, deterministic fields, sample caps, and
   sensitive-field exclusion.
4. **Generation actions.** Add prompt builders, LLM calls, response validation,
   sanitization, persistence, and fail-closed behavior for both AI outputs.
5. **Jobs + triggers.** Wire PR webhook actions to PR-risk jobs; wire material
   health-score refreshes to health-explanation jobs with rate-limit/staleness
   guards.
6. **Backfill + regenerate.** Add a scoped backfill path for existing open PRs
   and a guarded regenerate action for PR risk/health explanation.
7. **UI surfaces.** Add Work Items risk badges, PR detail risk panel, and
   Overview/project-health "Why?" overlay using existing visual language.
8. **Notifications.** Send spec 042 notifications only for new high/critical PR
   risk or material risk-level increases.
9. **Docs.** Update env/security operator docs for the AI gate, prompt-safety,
   trigger, notification, and throttle posture.
10. **Tests + verification.** Cover the tests listed above, run Pint,
    `php artisan test`, `npm run build`, self-review, PR.

## Acceptance criteria

- [x] Feature stays inert unless `AI_FEATURES_ENABLED=true` and shared LLM
      config is present.
- [x] Relevant incoming GitHub PR webhook actions enqueue at most one risk
      assessment job per PR after the local PR row is updated.
- [x] Every assessed PR persists `risk_level`, `risk_score`, summary, reasons,
      input snapshot, prompt version, model, and assessment timestamp.
- [x] Work Items queue surfaces risk badges for PR rows without affecting issue
      rows.
- [x] PR detail/drawer UI shows score, summary, reasons, recommended actions,
      assessment timestamp, pending state, failed state, and gated regenerate.
- [x] Existing open PRs can be backfilled through a scoped command/job without
      scoring closed PRs or other users' data.
- [x] Project health explanations describe the existing Phase 8/spec 046 health
      score; they do not compute or display a competing score.
- [x] Overview/project health UI includes a natural-language "why" overlay with
      drivers, recommended actions, timestamp, pending state, and failed state.
- [x] High/critical PR risk creates at most one spec 042 notification per PR per
      material risk-level increase.
- [x] LLM input excludes secrets, webhook URLs, access tokens, raw diffs, raw
      logs, and full PR bodies.
- [x] Failed LLM calls persist failed status/error context and do not fabricate
      risk/explanation text.
- [x] Tests cover query scoping, generation validation/failure, webhook dispatch,
      backfill, UI payloads, notification guardrails, and health-score refresh
      triggers.
- [x] Pint clean, tests green, build clean, CI green on the eventual spec PR.

## Files touched

List of created/modified files. Fill in as work progresses.

- `specs/phase-10-innovation/045-ai-pr-risk-score-and-project-health-explanation.md` — created, draft spec.
- `database/migrations/2026_07_21_150000_create_pull_request_risk_assessments_table.php` — adds one-current-row PR risk assessment persistence.
- `database/migrations/2026_07_21_150001_create_project_health_explanations_table.php` — adds one-current-row project health explanation persistence.
- `app/Models/PullRequestRiskAssessment.php` — PR risk assessment model, casts, and PR relationship.
- `app/Models/ProjectHealthExplanation.php` — project health explanation model, casts, and project relationship.
- `app/Enums/PullRequestRiskAssessmentStatus.php` — PR risk assessment lifecycle enum.
- `app/Enums/PullRequestRiskLevel.php` — PR risk level enum.
- `app/Enums/ProjectHealthExplanationStatus.php` — project health explanation lifecycle enum.
- `database/factories/PullRequestRiskAssessmentFactory.php` — PR risk assessment factory states.
- `database/factories/ProjectHealthExplanationFactory.php` — project health explanation factory states.
- `tests/Feature/AiInsights/AiInsightPersistenceTest.php` — focused persistence, casts, uniqueness, relationship, and cascade tests.
- `app/Models/GithubPullRequest.php` — adds current risk assessment relationship.
- `app/Models/Project.php` — adds current health explanation relationship.
- `app/Domain/AiInsights/Queries/GetPullRequestRiskInputQuery.php` — builds scoped, bounded PR-risk input snapshots from existing Nexus metadata.
- `app/Domain/AiInsights/Queries/GetProjectHealthExplanationInputQuery.php` — builds scoped, bounded project-health explanation snapshots from existing score drivers.
- `tests/Feature/AiInsights/GetPullRequestRiskInputQueryTest.php` — covers PR ownership scoping, bounded samples, and sensitive-field exclusion.
- `tests/Feature/AiInsights/GetProjectHealthExplanationInputQueryTest.php` — covers project ownership scoping, health-driver samples/caps, and sensitive-field exclusion.
- `app/Domain/AiInsights/Actions/GeneratePullRequestRiskAssessmentAction.php` — builds `pr-risk-v1` prompts, validates/sanitizes LLM output, persists scored or failed PR risk rows, and reuses the current row on regeneration.
- `app/Domain/AiInsights/Actions/GenerateProjectHealthExplanationAction.php` — builds `project-health-explanation-v1` prompts, validates/sanitizes LLM output, persists explained or failed health explanation rows, and reuses the current row on regeneration.
- `tests/Feature/AiInsights/GeneratePullRequestRiskAssessmentActionTest.php` — covers PR risk generation prompt, sanitization, failed client, disabled AI gate, invalid output, and regeneration row updates.
- `tests/Feature/AiInsights/GenerateProjectHealthExplanationActionTest.php` — covers project health explanation prompt, sanitization, failed client, disabled AI gate, invalid output, and regeneration row updates.
- `app/Domain/AiInsights/Jobs/GeneratePullRequestRiskAssessmentJob.php` — queues PR risk assessment generation with AI gating, uniqueness, pending state, pending-row skip when AI is disabled, and terminal failure recovery.
- `app/Domain/AiInsights/Jobs/GenerateProjectHealthExplanationJob.php` — queues project health explanation generation with AI gating, uniqueness, pending state, pending-row skip when AI is disabled, and terminal failure recovery.
- `app/Domain/GitHub/WebhookHandlers/PullRequestWebhookHandler.php` — dispatches PR risk assessment jobs after material `pull_request` webhook actions update the local PR row.
- `app/Domain/Analytics/Actions/RefreshProjectHealthScoreAction.php` — dispatches health explanation jobs after material score/band changes or stale explanations, guarded by AI gate and rate limit.
- `tests/Feature/AiInsights/GeneratePullRequestRiskAssessmentJobTest.php` — covers PR risk job uniqueness, AI gate, snapshot/generation path, and failed-handler recovery.
- `tests/Feature/AiInsights/GenerateProjectHealthExplanationJobTest.php` — covers health explanation job uniqueness, AI gate, snapshot/generation path, and failed-handler recovery.
- `tests/Feature/GitHub/Webhooks/PullRequestWebhookHandlerTest.php` — covers material PR webhook risk dispatch and disabled-AI skip behavior.
- `tests/Unit/Domain/Analytics/RefreshProjectHealthScoreActionTest.php` — covers health explanation dispatch on material changes, stale explanations, rate limiting, and disabled-AI skip behavior.
- `app/Console/Commands/BackfillPullRequestRiskAssessmentsCommand.php` — queues scoped PR risk assessment jobs for currently open PRs when AI is enabled.
- `tests/Feature/AiInsights/PullRequestRiskBackfillCommandTest.php` — covers open-only/user/project/repository scoping, other-user exclusion, AI gate, and explicit-scope guard for the backfill command.
- `app/Domain/GitHub/Queries/WorkItemsForUserQuery.php` — includes current PR risk assessment payload only on pull request rows.
- `app/Http/Controllers/WorkItemController.php` — exposes the AI regenerate gate to the Work Items page.
- `app/Http/Controllers/PullRequestRiskRegenerationController.php` — queues manual PR risk regeneration for project owners when AI insights are enabled.
- `routes/web.php` — adds the throttled Work Items PR-risk regenerate route.
- `resources/js/Pages/WorkItems/Index.vue` — renders PR-only risk badge and inline risk panel with summary, reasons, actions, timestamp, pending/failed state, and gated regenerate action.
- `tests/Feature/GitHub/WorkItemControllerTest.php` — covers Work Items PR-risk payload boundaries and regenerate authorization/AI gate behavior.
- `app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php` — includes the latest project health explanation payload on risky-project rows.
- `app/Http/Controllers/OverviewController.php` — exposes the gated Overview health-explanation regenerate affordance flag.
- `app/Http/Controllers/ProjectHealthExplanationRegenerationController.php` — queues manual health-explanation regeneration for project owners when AI insights are enabled.
- `routes/web.php` — adds the throttled Overview project-health explanation regenerate route.
- `resources/js/Components/Dashboard/RiskyProjects.vue` — renders the “Why?” health explanation affordance with summary, drivers, recommended actions, timestamps, pending/failed/no-explanation states, and gated regenerate.
- `resources/js/Pages/Overview.vue` — passes the health-explanation regenerate gate into the Risky Projects panel.
- `resources/js/types/index.d.ts` — adds the project health explanation payload type on `RiskyProjectRow`.
- `tests/Feature/Dashboard/GetOverviewDashboardQueryTest.php` — covers Overview risky-project health explanation payload and cross-user isolation.
- `tests/Feature/Dashboard/ProjectHealthExplanationUiTest.php` — covers Overview Inertia payload and health-explanation regenerate gate/authorization behavior.
- `app/Domain/AiInsights/Actions/GeneratePullRequestRiskAssessmentAction.php` — dispatches conservative spec 042 notifications for material increases to high/critical PR risk.
- `app/Enums/AlertSource.php` — clarifies that GitHub alert `source_id` can point at repositories or pull requests depending on alert type.
- `database/migrations/2026_05_27_080000_create_alerts_table.php` — clarifies the historical alerts-table comment for GitHub repo/PR-scoped alerts.
- `tests/Feature/AiInsights/GeneratePullRequestRiskAssessmentActionTest.php` — covers high/critical notification guardrails, quiet unchanged low/medium scores, and resolved historical alert boundaries.
- `docs/security/operator-checklist.md` — documents Spec 045 prompt-safety, webhook-trigger, notification, regenerate, and rate-limit expectations.

## Work log

### 2026-07-21

- Drafted from `_template.md`. Kept `status: not-started` because Nexus workflow
  requires user scope confirmation before opening the GitHub issue/branch.
- Assumed spec 044's shared `LlmClient`/`services.llm.*` config is available and
  should be reused rather than duplicated.
- Assumed PR risk scoring uses bounded Nexus metadata, not raw diffs or code
  review, to keep prompt size and privacy risk controlled.
- Assumed project health explanations explain the existing Phase 8/spec 046
  score rather than creating a separate AI score.
- Started workflow on branch
  `spec/045-ai-pr-risk-score-and-project-health-explanation` with GitHub issue
  #134.
- Implemented the first reviewable backend foundation slice: migrations, models,
  enums, factories, current-row relationships, and focused persistence tests for
  PR risk assessments and project health explanations. Added a health explanation
  lifecycle `status` column so later pending/failed UI and job slices do not infer
  state from nullable timestamps.
- Implemented the second reviewable backend slice: `GetPullRequestRiskInputQuery`
  and `GetProjectHealthExplanationInputQuery`, with ownership scoping, deterministic
  bounded facts, sample caps, and focused tests proving snapshots exclude secrets,
  webhook URLs, access tokens, raw logs, raw diffs, and full PR bodies.
- Implemented the third reviewable backend slice: `GeneratePullRequestRiskAssessmentAction`
  and `GenerateProjectHealthExplanationAction`, reusing the shared `LlmClient` and
  `services.llm.enabled` gate, building versioned prompts from bounded snapshots,
  validating/sanitizing structured JSON output, persisting scored/explained rows,
  failing closed with error context, and updating the existing current row on regeneration.
- Implemented the fourth reviewable backend slice: queued PR risk and project health
  explanation jobs, PR webhook dispatch after material pull request actions update the
  local row, and health-score refresh dispatch after material score/band changes or stale
  explanations. The dispatch paths respect the shared AI gate, use uniqueness/rate-limit
  guards, and mark pending rows failed on terminal job failure so rows do not stay stuck.
- Implemented the fifth reviewable backend slice: `ai-insights:backfill-pr-risk` queues
  PR risk assessment jobs for explicitly scoped user/project/repository backfills, only
  when AI is enabled and only for open PRs. Deferred UI-triggered regenerate endpoints to
  the UI surface slice because the current route/controller patterns are tied to existing
  authenticated UI pages and there is not yet a PR detail or Overview explanation surface
  for those actions.
- Implemented the sixth reviewable UI slice: Work Items now receives current PR risk
  assessment data only for pull request rows, renders a PR-only risk badge and inline risk
  panel with score, summary, reasons, recommended actions, assessed/failed timestamp, and
  pending/failed states, and exposes a throttled manual regenerate action for project
  owners only when AI insights are enabled. Kept issue rows unchanged and deferred the
  Overview project-health overlay, notifications, and docs.
- Implemented the seventh reviewable UI slice: Overview risky-project rows now carry the
  latest project health explanation payload, render a natural-language “Why?” affordance
  with summary, drivers, recommended actions, timestamp, pending/failed/no-explanation
  states, and expose a throttled manual regenerate action for project owners when AI
  insights are enabled. Kept PR risk notifications and docs deferred.
- Implemented the final planned small work unit: PR risk assessments now create conservative
  spec 042 notifications only for material increases to `high` or `critical`, with one alert
  per PR per risk level and no notifications for unchanged `low`/`medium`. Updated the
  operator security checklist for prompt safety, webhook trigger, notification, regenerate,
  and rate-limit expectations. Env docs needed no new keys because Spec 045 reuses the
  shared Spec 044 LLM gate/config.
- Fixed fresh review findings: removed PR `body_preview` from LLM input snapshots entirely,
  aligned PR-risk and health-explanation jobs to the existing fail-closed/no queue-retry
  contract, marked existing pending rows skipped when the AI gate is disabled at execution
  time, and let spec 042 active-alert idempotency handle PR-risk notification duplicates so
  resolved historical alerts do not suppress later material increases.
- PR #135 was squash-merged into `main` with CI green. Issue #134 closed as completed,
  and the spec/tracker bookkeeping was updated to `done`.

## Open questions / blockers

- None for v1. Follow-up product iterations can add dedicated PR-risk notification
  preferences, a dedicated PR detail route, historical risk versions, or health-score
  history if those become necessary.
