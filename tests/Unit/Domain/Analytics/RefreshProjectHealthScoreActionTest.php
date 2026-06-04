<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\Actions\RefreshProjectHealthScoreAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Events\HealthScoreUpdated;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RefreshProjectHealthScoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_persists_the_score_and_dispatches(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'health_score' => null,
        ]);

        $score = app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertSame(100, $score);
        $this->assertSame(100, $project->fresh()->health_score);

        Event::assertDispatched(
            HealthScoreUpdated::class,
            fn (HealthScoreUpdated $event): bool => $event->projectId === $project->id
                && $event->ownerUserId === $owner->id
                && $event->score === 100
                && $event->band === 'healthy',
        );
    }

    public function test_unchanged_score_is_a_full_noop(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        $project = Project::factory()->create(['health_score' => 100]);
        $originalUpdatedAt = $project->updated_at;

        $score = app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertSame(100, $score);
        $this->assertEquals($originalUpdatedAt, $project->fresh()->updated_at, 'no spurious updated_at bump');
        Event::assertNotDispatched(HealthScoreUpdated::class);
    }

    public function test_score_drop_persists_and_broadcasts_with_correct_band(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'health_score' => 100,
        ]);

        // 2 critical alerts (-60) + 1 warning (-15) = 25 → critical band.
        Alert::factory()->count(2)->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);

        $score = app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertSame(25, $score);
        $this->assertSame(25, $project->fresh()->health_score);

        Event::assertDispatched(
            HealthScoreUpdated::class,
            fn (HealthScoreUpdated $event): bool => $event->projectId === $project->id
                && $event->score === 25
                && $event->band === 'critical',
        );
    }
}
