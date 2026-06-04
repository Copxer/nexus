<?php

namespace App\Domain\Analytics\Actions;

use App\Enums\HealthScoreBand;
use App\Events\HealthScoreUpdated;
use App\Models\Project;

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
    public function __construct(
        private readonly ComputeProjectHealthScoreAction $compute,
    ) {}

    public function execute(Project $project): int
    {
        $newScore = $this->compute->execute($project);

        if ($project->health_score === $newScore) {
            return $newScore;
        }

        $project->forceFill(['health_score' => $newScore])->save();

        $band = HealthScoreBand::fromScore($newScore);

        HealthScoreUpdated::dispatch(
            $project->id,
            $project->owner_user_id,
            $newScore,
            $band->value,
        );

        return $newScore;
    }
}
