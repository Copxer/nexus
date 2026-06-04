<?php

namespace App\Domain\Analytics\Jobs;

use App\Domain\Analytics\Actions\RefreshProjectHealthScoreAction;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Per-project recompute (spec 033). Dispatched by the activity
 * listener on whitelisted transition events and by the every-5-min
 * sweep job for active projects.
 *
 * `ShouldBeUnique` (uniqueFor 60s) and `WithoutOverlapping` keyed on
 * the project id collapse burst recomputes — eg. a website that
 * flips down then up then down again inside a second produces three
 * dispatch attempts but only one (or two) actual recomputes,
 * spaced by the lock window.
 *
 * Quietly skips if the project was deleted between dispatch and
 * handle (the eager `find` returns null).
 */
class RecomputeProjectHealthScoreJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Cache the lock for 60 s — bursts collapse onto the first run. */
    public int $uniqueFor = 60;

    public function __construct(public readonly int $projectId) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->projectId))
            ->releaseAfter(10)
            ->expireAfter(60)];
    }

    public function handle(RefreshProjectHealthScoreAction $refresh): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project === null) {
            return;
        }

        $refresh->execute($project);
    }
}
