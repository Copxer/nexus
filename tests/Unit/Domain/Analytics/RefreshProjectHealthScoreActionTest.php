<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\AiInsights\Jobs\GenerateProjectHealthExplanationJob;
use App\Domain\Analytics\Actions\RefreshProjectHealthScoreAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\HealthScoreBand;
use App\Events\HealthScoreUpdated;
use App\Models\Alert;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshProjectHealthScoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_persists_the_score_and_dispatches(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => false]);
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
        Queue::fake();
        config(['services.llm.enabled' => false]);
        $project = Project::factory()->create(['health_score' => 100]);
        $originalUpdatedAt = $project->updated_at;

        $score = app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertSame(100, $score);
        $this->assertEquals($originalUpdatedAt, $project->fresh()->updated_at, 'no spurious updated_at bump');
        Event::assertNotDispatched(HealthScoreUpdated::class);
        Queue::assertNotPushed(GenerateProjectHealthExplanationJob::class);
    }

    public function test_score_drop_persists_and_broadcasts_with_correct_band(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => false]);
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

    public function test_material_score_change_dispatches_health_explanation_after_score_is_persisted_when_ai_is_enabled(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'health_score' => 100,
        ]);
        ProjectHealthExplanation::factory()->explained()->for($project)->create([
            'health_score' => 100,
            'health_band' => HealthScoreBand::Healthy->value,
            'updated_at' => now()->subHour(),
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);

        app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertSame(85, $project->fresh()->health_score);
        Queue::assertPushed(
            GenerateProjectHealthExplanationJob::class,
            fn (GenerateProjectHealthExplanationJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_stale_health_explanation_dispatches_even_when_score_is_unchanged(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $project = Project::factory()->create(['health_score' => 100]);
        ProjectHealthExplanation::factory()->explained()->for($project)->create([
            'health_score' => 100,
            'health_band' => HealthScoreBand::Healthy->value,
            'explained_at' => now()->subDays(8),
            'updated_at' => now()->subHour(),
        ]);
        $originalUpdatedAt = $project->updated_at;

        app(RefreshProjectHealthScoreAction::class)->execute($project);

        $this->assertEquals($originalUpdatedAt, $project->fresh()->updated_at, 'unchanged score still avoids project churn');
        Event::assertNotDispatched(HealthScoreUpdated::class);
        Queue::assertPushed(GenerateProjectHealthExplanationJob::class);
    }

    public function test_health_explanation_dispatch_respects_recent_row_rate_limit(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $project = Project::factory()->create(['health_score' => 100]);
        ProjectHealthExplanation::factory()->explained()->for($project)->create([
            'health_score' => 100,
            'health_band' => HealthScoreBand::Healthy->value,
            'explained_at' => now()->subDays(8),
            'updated_at' => now()->subMinutes(10),
        ]);

        app(RefreshProjectHealthScoreAction::class)->execute($project);

        Queue::assertNotPushed(GenerateProjectHealthExplanationJob::class);
    }

    public function test_health_explanation_dispatch_is_skipped_when_ai_is_disabled(): void
    {
        Event::fake([HealthScoreUpdated::class]);
        Queue::fake();
        config(['services.llm.enabled' => false]);
        $project = Project::factory()->create(['health_score' => 100]);
        ProjectHealthExplanation::factory()->explained()->for($project)->create([
            'explained_at' => now()->subDays(8),
            'updated_at' => now()->subHour(),
        ]);

        app(RefreshProjectHealthScoreAction::class)->execute($project);

        Queue::assertNotPushed(GenerateProjectHealthExplanationJob::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
