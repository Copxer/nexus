---
spec: alert-rules-and-health-weights
phase: 10
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-02
updated: 2026-07-02
---

# 046 — User-tunable health-score weights + metric-driven `AlertRule` evaluators

## Goal

Two long-deferred deliverables that fit together because they share the
same "make hard-coded thresholds user-editable" shape.

**Slice A — health-score weights.** Spec 033 shipped
`ComputeProjectHealthScoreAction` with 8 hard-coded `DEDUCT_*`
constants (spec 033, Phase 8 README, deferred: "User-tunable
health-score weights"). Every project uses the same weights. An
operator who cares more about deployment stability than website
uptime has no way to say so. Spec 046 persists per-user override
values that fall back to the current constants when unset.

**Slice B — metric-driven `AlertRule` evaluators.** Roadmap §6.8
calls for a Specification Pattern for alerts. Today alerts fire from
Phase 7 transitions (a website goes down, a host goes offline, a
workflow fails) — not from **metric trends** over time. Spec 038
shipped `EvaluateSystemHealthJob` with hard-coded thresholds
(`SystemHealthThresholds::QUEUE_BACKLOG_WARNING = 100`, etc.). Users
can't add their own thresholds and can't watch trends the roadmap
lists as deferred: queue-backlog **trend** (not just current value),
deploy-frequency **drop**, uptime **slope**, deployment failure-rate
**percent**. Spec 046 ships an `alert_rules` table + strategy-pattern
evaluators + scheduled tick that runs them per user.

The two slices share plumbing: same "user-configurable knob"
Settings surface (`/settings/rules` with two tabs — Weights + Rules),
same throttled controller endpoints, and both ride on spec 042's
delivery layer (alerts fire → notifications).

Roadmap refs: §6.8 Specification Pattern for Alerts, §14.2 Health
Score, §Phase 8 deferred ("user-tunable health-score weights"),
§Phase 9 deferred ("metric-driven alert rules"),
§Phase 10 features ("AI PR risk score" → depends on this
foundation).

## Scope

**In scope:**

### Slice A — Health-score weights

- **Migration: `project_health_score_weight_overrides`.** One row
  per user; columns match the 8 `DEDUCT_*` constants
  (`deduct_alert_critical`, `deduct_alert_warning`,
  `deduct_deploy_failed`, `deduct_website_slow`,
  `deduct_website_down`, `deduct_host_offline`,
  `deduct_container_unhealthy`, `deduct_gh_sync_failed`) — nullable
  `unsignedTinyInteger` each so `null` = "use default." Enforce
  `unique(user_id)` — one override row per user.

- **`ProjectHealthScoreWeightOverride` model.** `HasOne` on
  `User`, casts every deduct column to `?int`. Zero magic —
  reading returns `int|null`.

- **`ComputeProjectHealthScoreAction` refactor.** Accept an optional
  `Weights` value object per call. When present, use its
  fields; when absent (or a field is null), fall back to the
  class's `DEDUCT_*` constants. **Backwards-compatible signature
  via method overloading behavior** — keep the current
  `execute(Project $project): int`; add a new
  `executeForUser(Project $project, User $user): int` that resolves
  the user's overrides and delegates. Existing callers stay green.

- **`Weights` value object.** Immutable, one nullable int per
  deduction. Its `resolvedFor(string $field): int` returns the
  override or the default. Keeps the fallback logic in one place.

- **Settings UI: `/settings/rules` → Weights tab.** Sliders (or
  number inputs) for each of the 8 fields, defaulting to the
  current constant value. Save flips a per-user override row.
  Reset button (per-field or per-page) clears an override back to
  the default.

- **Recompute-on-save.** Saving new weights enqueues
  `RecomputeAllProjectHealthScoresJob` scoped to the user's
  projects only — a single sweep reasserts the new formula
  without waiting for the next scheduled tick.

### Slice B — Metric-driven `AlertRule` evaluators

- **Migration: `alert_rules`.**
  - `id`, `user_id` (FK → users, cascade delete)
  - `name` (string, ≤ 120 chars — operator-facing)
  - `kind` (enum-string: `queue.backlog_trend`,
    `deploy_frequency_drop`, `uptime_slope`, `deploy_failure_rate`)
  - `severity` (enum: info | warning | critical — the fired
    alert's severity)
  - `config` (JSON — per-kind knobs; see below)
  - `enabled` (boolean, default true)
  - `last_evaluated_at`, `last_triggered_at` (nullable timestamps)
  - `cool_down_minutes` (unsignedInteger, default 30 — minimum
    interval between successive triggers so a stuck condition
    doesn't repeatedly re-alert)
  - Timestamps.

- **`AlertRuleKind` enum.** Four cases matching the migration.
  Each maps to a specific evaluator class.

- **`AlertRuleEvaluator` contract + 4 implementations** —
  Strategy pattern per §6.5.
  - `QueueBacklogTrendEvaluator` — compares queue backlog now vs.
    N minutes ago; triggers when the delta exceeds
    `config.threshold_delta`.
  - `DeployFrequencyDropEvaluator` — computes deploys/day over
    the last 7d vs. the previous 7d; triggers when the drop
    exceeds `config.drop_percent`.
  - `UptimeSlopeEvaluator` — computes the linear slope of a
    user's monitored websites' uptime over the trailing 24h;
    triggers when the negative slope exceeds
    `config.slope_threshold` percent-per-hour.
  - `DeployFailureRateEvaluator` — percent of the last N deploys
    that failed; triggers when it exceeds
    `config.failure_rate_percent`.
  - Each returns `AlertRuleEvaluation` (a small DTO):
    `{ triggered: bool, title: string, description: string, metadata: array }`.

- **`EvaluateAlertRulesJob`.** Scheduled every 5 minutes.
  Iterates enabled rules, checks `last_triggered_at +
  cool_down_minutes`, delegates to the evaluator, and — on
  `triggered: true` — dispatches `TriggerAlertAction` with
  `AlertSource::System`, `type = "rule.{kind}"`, `source_id =
  rule.id`. Spec 042's delivery layer takes over from there.
  Updates `last_evaluated_at` on every tick; updates
  `last_triggered_at` on a fresh trigger.

- **`AlertRuleTemplate` catalog.** A small static list of
  starter rules the "Add rule" UI seeds from. Operators pick a
  template ("Queue backlog trending up"), review + edit the
  thresholds, save. Reduces cold-start friction — an operator
  who's never written an alert rule shouldn't stare at a blank
  form.

- **Settings UI: `/settings/rules` → Rules tab.**
  - Add / edit / delete rules. Each rule row shows name, kind,
    severity, enabled toggle, last-evaluated-at,
    last-triggered-at.
  - "Add rule" dropdown lists templates → pre-fills the form.
  - Config knobs render per-kind (threshold + window controls
    picked dynamically based on `kind`).
  - Rate limit per §5 of the operator checklist:
    `store` 10/min, `update` 20/min, `destroy` 20/min.

- **Cross-references.**
  - Spec 042 delivery — every triggered rule alert fires the
    notification fan-out (same `AlertSource::System` code path
    as spec 038's self-checks).
  - Spec 043 palette — new commands: "Add alert rule", "Reset
    health-score weights" (both under `actions` group).
  - `docs/security/operator-checklist.md` §5 — extended with
    the new rule endpoints.

- **Tests.**
  - `ComputeProjectHealthScoreActionWeightsTest` — override + null
    fallback + per-user resolution.
  - `WeightOverridesControllerTest` — happy path + reset + guest
    401 + throttle.
  - `AlertRuleControllerTest` — CRUD + throttle + guest 401.
  - `QueueBacklogTrendEvaluatorTest`,
    `DeployFrequencyDropEvaluatorTest`,
    `UptimeSlopeEvaluatorTest`,
    `DeployFailureRateEvaluatorTest` — one focused test per
    evaluator: no-trigger baseline + triggering-condition.
  - `EvaluateAlertRulesJobTest` — dispatches
    `TriggerAlertAction` on evaluation truth; respects cool-down;
    disabled rules skipped; disabled/deleted users skipped.
  - `HealthScoreRecomputeOnWeightSaveTest` — saving weights
    dispatches `RecomputeAllProjectHealthScoresJob` for that
    user's projects only.

**Out of scope:**

- **Rule templates from the community.** A marketplace of shared
  rules is Phase 11+ (roadmap §Widget marketplace).
- **User-editable system-health thresholds** (spec 038's
  `SystemHealthThresholds` constants). Two separate systems (the
  built-in self-checks vs. user rules); overlapping them adds
  complexity without value. Defer.
- **Per-project weight overrides.** Weights are per-user global.
  Per-project × per-user is a Cartesian UI problem; if operators
  ask, ship as a follow-up with a project-picker on the Weights
  tab.
- **Rule scheduling override** (e.g. "evaluate every 1 min for
  this rule"). All rules share the 5-minute tick. Adds a queue
  key per rule; defer.
- **AI-suggested thresholds.** "Nexus thinks 300 is the right
  queue-backlog trigger for you" belongs in spec 045's AI polish
  work.
- **Metric graph inside the rule editor.** Nice UX but requires
  a per-kind history query; defer to a Phase 11 dashboard-builder
  spec if it's still valuable then.
- **Rule import/export.** Adds a serialization contract we don't
  need yet. Defer until users ask.
- **Alert-rule audit log.** Who edited what + when. `updated_at`
  covers the mechanical need; a proper audit trail is its own
  security spec.

## Plan

1. **Migrations.** `project_health_score_weight_overrides` +
   `alert_rules`. Foreign keys, cascade deletes.
2. **Slice A models + refactor.** `Weights` value object,
   `ProjectHealthScoreWeightOverride` model, refactor
   `ComputeProjectHealthScoreAction` to accept optional weights.
3. **Slice A controller + Vue tab.** `WeightOverridesController`
   with `index` + `update` + `reset` methods.
   `resources/js/Pages/Settings/Rules/Weights.vue`.
4. **Slice A recompute-on-save.** Hook
   `RecomputeAllProjectHealthScoresJob` dispatch into the
   controller's `update` method (fire-and-forget).
5. **Slice B models + evaluators.** `AlertRule` model,
   `AlertRuleKind` enum, `AlertRuleEvaluation` DTO,
   `AlertRuleEvaluator` interface, 4 evaluators + registry.
6. **Slice B scheduled job.** `EvaluateAlertRulesJob` + schedule
   registration in `routes/console.php`.
7. **Slice B controller + Vue tab.** `AlertRuleController` +
   `resources/js/Pages/Settings/Rules/Rules.vue`.
   `AlertRuleTemplate` catalog.
8. **Shared UI shell.** `resources/js/Pages/Settings/Rules/Index.vue`
   with tab strip (Weights / Rules).
9. **Palette + docs.** Palette commands added; operator checklist
   extended with the new throttled endpoints.
10. **Pint + suite + build + self-review + PR.**

## Acceptance criteria

- [ ] Per-user health-score weights persist through
      `project_health_score_weight_overrides`; `null` fields fall
      back to the `DEDUCT_*` defaults.
- [ ] `ComputeProjectHealthScoreAction::executeForUser` uses the
      overrides; existing `execute` stays defaults-only for
      backwards compat.
- [ ] Saving weights dispatches
      `RecomputeAllProjectHealthScoresJob` scoped to that user's
      projects.
- [ ] `alert_rules` table + 4 evaluator implementations shipped
      behind the `AlertRuleEvaluator` contract.
- [ ] `EvaluateAlertRulesJob` runs every 5 min, respects
      `cool_down_minutes`, dispatches `TriggerAlertAction` on
      truth, and stays quiet when a rule is disabled.
- [ ] Fired rule alerts flow through spec 042's delivery layer
      (email / Slack / webhook) with `AlertSource::System` and
      `type = "rule.{kind}"`.
- [ ] `/settings/rules` UI: Weights tab (sliders + reset), Rules
      tab (CRUD + template picker + per-kind config).
- [ ] All new endpoints throttled per §5 of the operator
      checklist.
- [ ] Every test in §Tests block green.
- [ ] Pint clean, `php artisan test` green, `npm run build`
      clean.

## Files touched

- `database/migrations/2026_07_02_*_create_project_health_score_weight_overrides_table.php` — created
- `database/migrations/2026_07_02_*_create_alert_rules_table.php` — created
- `app/Enums/AlertRuleKind.php` — created
- `app/Models/ProjectHealthScoreWeightOverride.php` — created
- `app/Models/AlertRule.php` — created
- `app/Domain/Analytics/DataTransferObjects/HealthScoreWeights.php` — created
- `app/Domain/Analytics/Actions/ComputeProjectHealthScoreAction.php` — extended
- `app/Domain/Alerts/Contracts/AlertRuleEvaluator.php` — created
- `app/Domain/Alerts/DataTransferObjects/AlertRuleEvaluation.php` — created
- `app/Domain/Alerts/Evaluators/QueueBacklogTrendEvaluator.php` — created
- `app/Domain/Alerts/Evaluators/DeployFrequencyDropEvaluator.php` — created
- `app/Domain/Alerts/Evaluators/UptimeSlopeEvaluator.php` — created
- `app/Domain/Alerts/Evaluators/DeployFailureRateEvaluator.php` — created
- `app/Domain/Alerts/Jobs/EvaluateAlertRulesJob.php` — created
- `app/Domain/Alerts/Support/AlertRuleTemplate.php` — created (static catalog)
- `app/Http/Controllers/Settings/WeightOverridesController.php` — created
- `app/Http/Controllers/Settings/AlertRuleController.php` — created
- `resources/js/Pages/Settings/Rules/Index.vue` — created (tab shell)
- `resources/js/Pages/Settings/Rules/Weights.vue` — created
- `resources/js/Pages/Settings/Rules/Rules.vue` — created
- `resources/js/Pages/Settings/Index.vue` — new "Rules & health weights" link
- `resources/js/lib/commands.ts` — palette entries for the new page
- `routes/web.php` — new routes (throttled)
- `routes/console.php` — `EvaluateAlertRulesJob` on the 5-min tick
- `docs/security/operator-checklist.md` — §5 extended
- `tests/Feature/Analytics/ComputeProjectHealthScoreActionWeightsTest.php` — created
- `tests/Feature/Settings/WeightOverridesControllerTest.php` — created
- `tests/Feature/Settings/AlertRuleControllerTest.php` — created
- `tests/Feature/Alerts/QueueBacklogTrendEvaluatorTest.php` — created
- `tests/Feature/Alerts/DeployFrequencyDropEvaluatorTest.php` — created
- `tests/Feature/Alerts/UptimeSlopeEvaluatorTest.php` — created
- `tests/Feature/Alerts/DeployFailureRateEvaluatorTest.php` — created
- `tests/Feature/Alerts/EvaluateAlertRulesJobTest.php` — created
- `tests/Feature/Analytics/HealthScoreRecomputeOnWeightSaveTest.php` — created

## Work log

Dated notes as work progresses.

### 2026-07-02
- Drafted from `_template.md`. Two slices sharing plumbing —
  both persist "hard-coded thresholds become user-editable"
  values, both surface under `/settings/rules`, both ride on
  spec 042's delivery.
- Kept `execute` signature stable on
  `ComputeProjectHealthScoreAction` + added
  `executeForUser` — every existing caller (spec 033 sweep,
  spec 035 sweep, listener) stays green without touching them.
- Rule evaluators are strategy-pattern (§6.5) mirroring spec 042
  driver design — one class per kind, a shared contract, a
  registry that hands the right one to the scheduled job.
- Cool-down at rule level (30-minute default). Bypassing "an
  ongoing outage keeps re-alerting" without needing global
  dedupe like spec 042's Slack-storm-guard.

## Open questions / blockers

- **Weight range.** UI slider bounds: 0–100 per field feels
  right (matches the 100-baseline). A user could set every
  weight to 100 and immediately drop every project to 0 on any
  signal — that's the operator's call, not a UX guard. Leave the
  hard clamp in the action's `max(0, min(100, $score))`.
- **Deploy-frequency evaluator baseline.** Needs a 14-day
  window (7-day current vs. 7-day previous). Repositories synced
  less than 14 days ago don't have enough history — return
  `triggered: false` in that case rather than a spurious
  low-freq trigger.
- **Uptime-slope math.** Simple linear regression on the last
  24h of `website_checks` per website, averaged across the
  user's monitors. Alternative: piecewise using the last N-min
  buckets. Ship the simpler linear-fit first; move to buckets
  if the noise floor is too high.
- **Cold-start ergonomics.** A user opening `/settings/rules`
  with no rules and no overrides sees two empty tabs. The
  Rules tab surfaces `AlertRuleTemplate` picker; the Weights
  tab shows all defaults + a "Save" button. Both feel
  something's there even at zero-state.
- **Rule kind naming.** `queue.backlog_trend` vs.
  `queue_backlog_trend` — kept dots to match the existing
  `type` conventions on alerts (`website.down`, `host.offline`,
  `workflow.failed`) so the Deliveries tab reads consistently.

### 2026-07-02 (branch)
- Branch `spec/046-alert-rules-and-health-weights` cut off main.
- Tracking issue #127.
- Self-review caught pre-push:
  - **Recompute-on-save was calling the global sweep** instead
    of a per-user job — spec §Plan explicitly asked for per-user
    scoping. Shipped a small `RecomputeUserProjectHealthScoresJob`
    (20 lines) and wired the controller to use it.
  - **Cool-down check ran after the evaluator** — wasteful on
    chatty rules paying SQL-join cost every 5-min tick. Moved
    the `isInCoolDown()` guard to the top of `evaluateOne`;
    `last_evaluated_at` still advances so the UI reads
    consistently.
- Test consolidation deliberate — 9 files listed in the spec ↔
  4 files shipped (`AlertRuleEvaluatorsTest` covers all four
  evaluators with shared fixtures; `RulesControllerTest`
  absorbs the weights-recompute happy path). Coverage matches
  §Tests; the file-count trim keeps the boilerplate down.
