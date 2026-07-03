<?php

namespace App\Domain\Analytics\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Spec 046 — after a user saves new health-score weights, reassert
 * scores across only *their* projects. Prevents a save from
 * fan-outing across every active project the sweep would touch.
 * The 5-minute `RecomputeAllProjectHealthScoresJob` sweep is still
 * the safety net for drift.
 */
class RecomputeUserProjectHealthScoresJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        Project::query()
            ->where('owner_user_id', $this->userId)
            ->select('id')
            ->chunkById(100, function ($projects): void {
                foreach ($projects as $project) {
                    RecomputeProjectHealthScoreJob::dispatch($project->id);
                }
            });
    }
}
