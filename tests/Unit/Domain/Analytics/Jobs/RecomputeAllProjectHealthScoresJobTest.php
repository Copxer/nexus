<?php

namespace Tests\Unit\Domain\Analytics\Jobs;

use App\Domain\Analytics\Jobs\RecomputeAllProjectHealthScoresJob;
use App\Domain\Analytics\Jobs\RecomputeProjectHealthScoreJob;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecomputeAllProjectHealthScoresJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_one_per_recently_active_project(): void
    {
        Bus::fake();
        $a = Project::factory()->create([
            'last_activity_at' => Carbon::now()->subDay(),
            'health_score' => 80,
        ]);
        $b = Project::factory()->create([
            'last_activity_at' => Carbon::now()->subDays(3),
            'health_score' => 60,
        ]);

        (new RecomputeAllProjectHealthScoresJob)->handle();

        Bus::assertDispatchedTimes(RecomputeProjectHealthScoreJob::class, 2);
        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $a->id,
        );
        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $b->id,
        );
    }

    public function test_dispatches_for_unscored_project_even_if_sleeping(): void
    {
        // A project with no `last_activity_at` (never touched) still
        // needs an initial score — the `OR health_score IS NULL`
        // branch covers the first-run sweep after this spec lands.
        Bus::fake();
        $unscored = Project::factory()->create([
            'last_activity_at' => Carbon::now()->subMonths(2),
            'health_score' => null,
        ]);

        (new RecomputeAllProjectHealthScoresJob)->handle();

        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $unscored->id,
        );
    }

    public function test_skips_sleeping_projects_that_already_have_a_score(): void
    {
        Bus::fake();
        Project::factory()->create([
            'last_activity_at' => Carbon::now()->subDays(30),
            'health_score' => 100,
        ]);

        (new RecomputeAllProjectHealthScoresJob)->handle();

        Bus::assertNothingDispatched();
    }
}
