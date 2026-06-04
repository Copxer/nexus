<?php

namespace App\Domain\Analytics\Jobs;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Scheduled sweep (spec 033). Every 5 minutes, fans out one
 * `RecomputeProjectHealthScoreJob` per active project. "Active" =
 * has activity in the last 7 days OR has never been scored
 * (first-run sweep after the spec ships).
 *
 * Inactive projects with a stored score are skipped — their signals
 * aren't moving and Phase 8 doesn't surface them on Overview's
 * risky-projects band. The activity listener still recomputes them
 * the instant a relevant transition lands; this job just keeps the
 * scheduled tax small.
 */
class RecomputeAllProjectHealthScoresJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = Carbon::now()->subDays(7);

        Project::query()
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_activity_at', '>', $cutoff)
                    ->orWhereNull('health_score');
            })
            ->select('id')
            ->chunkById(100, function ($projects): void {
                foreach ($projects as $project) {
                    RecomputeProjectHealthScoreJob::dispatch($project->id);
                }
            });
    }
}
