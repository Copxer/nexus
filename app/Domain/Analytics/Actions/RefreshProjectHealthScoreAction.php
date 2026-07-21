<?php

namespace App\Domain\Analytics\Actions;

use App\Domain\AiInsights\Jobs\GenerateProjectHealthExplanationJob;
use App\Enums\HealthScoreBand;
use App\Events\HealthScoreUpdated;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\User;

/**
 * Persist a project's health score and broadcast the change (spec
 * 033). Wraps `ComputeProjectHealthScoreAction` with diff-and-write:
 * if the freshly-computed score matches the stored one, the action
 * is a no-op — no DB write, no `updated_at` churn, no broadcast.
 *
 * On a real change (including the null → first-score transition),
 * the action persists the new score and dispatches
 * `HealthScoreUpdated` on `users.{ownerUserId}.dashboard` so
 * Overview reacts without a manual reload.
 *
 * Returns the new score so callers can log the before/after.
 */
class RefreshProjectHealthScoreAction
{
    private const MATERIAL_SCORE_DELTA = 5;

    private const EXPLANATION_STALE_AFTER_DAYS = 7;

    private const EXPLANATION_RATE_LIMIT_MINUTES = 30;

    public function __construct(
        private readonly ComputeProjectHealthScoreAction $compute,
    ) {}

    public function execute(Project $project): int
    {
        // Spec 046 — pass the project owner's weight overrides through
        // to the compute action. Owner is null in a race with a user
        // deletion; fall back to the defaults path in that case.
        $owner = $project->owner_user_id !== null
            ? User::query()->find($project->owner_user_id)
            : null;

        $newScore = $owner !== null
            ? $this->compute->executeForUser($project, $owner)
            : $this->compute->execute($project);

        $oldScore = $project->health_score;
        $oldBand = $oldScore === null ? null : HealthScoreBand::fromScore($oldScore);
        $newBand = HealthScoreBand::fromScore($newScore);

        $shouldDispatchHealthExplanation = $this->shouldDispatchHealthExplanation($project, $oldScore, $newScore, $oldBand, $newBand);

        if ($oldScore === $newScore) {
            if ($shouldDispatchHealthExplanation) {
                GenerateProjectHealthExplanationJob::dispatch($project->id);
            }

            return $newScore;
        }

        $project->forceFill(['health_score' => $newScore])->save();

        if ($shouldDispatchHealthExplanation) {
            GenerateProjectHealthExplanationJob::dispatch($project->id);
        }

        HealthScoreUpdated::dispatch(
            $project->id,
            $project->owner_user_id,
            $newScore,
            $newBand->value,
        );

        return $newScore;
    }

    private function shouldDispatchHealthExplanation(
        Project $project,
        ?int $oldScore,
        int $newScore,
        ?HealthScoreBand $oldBand,
        HealthScoreBand $newBand,
    ): bool {
        if (! config('services.llm.enabled', false)) {
            return false;
        }

        $explanation = ProjectHealthExplanation::query()
            ->where('project_id', $project->id)
            ->first();

        if ($this->rateLimited($explanation)) {
            return false;
        }

        if ($explanation === null || $explanation->explained_at === null) {
            return true;
        }

        if ($oldBand !== null && $oldBand !== $newBand) {
            return true;
        }

        if ($oldScore !== null && abs($oldScore - $newScore) >= self::MATERIAL_SCORE_DELTA) {
            return true;
        }

        return $explanation->explained_at->lte(now()->subDays(self::EXPLANATION_STALE_AFTER_DAYS));
    }

    private function rateLimited(?ProjectHealthExplanation $explanation): bool
    {
        if ($explanation === null) {
            return false;
        }

        return $explanation->updated_at !== null
            && $explanation->updated_at->gt(now()->subMinutes(self::EXPLANATION_RATE_LIMIT_MINUTES));
    }
}
