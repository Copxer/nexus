<?php

namespace Tests\Unit\Domain\Analytics\Jobs;

use App\Domain\Analytics\Actions\RefreshProjectHealthScoreAction;
use App\Domain\Analytics\Jobs\RecomputeProjectHealthScoreJob;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeProjectHealthScoreJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_refresh_action_for_the_project(): void
    {
        $project = Project::factory()->create(['health_score' => null]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        (new RecomputeProjectHealthScoreJob($project->id))->handle(app(RefreshProjectHealthScoreAction::class));

        $this->assertSame(70, $project->fresh()->health_score);
    }

    public function test_silently_skips_when_project_was_deleted(): void
    {
        // Dispatch references the id by value, so a delete between
        // dispatch and handle leaves the job with a stale id. The
        // `find` should no-op without throwing.
        $job = new RecomputeProjectHealthScoreJob(999_999);

        $job->handle(app(RefreshProjectHealthScoreAction::class));

        // No assertion needed beyond "did not throw".
        $this->assertTrue(true);
    }

    public function test_unique_id_keys_on_project_id(): void
    {
        $job = new RecomputeProjectHealthScoreJob(42);

        $this->assertSame('42', $job->uniqueId());
    }
}
