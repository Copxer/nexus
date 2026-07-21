<?php

namespace Tests\Feature\Dashboard;

use App\Domain\AiInsights\Jobs\GenerateProjectHealthExplanationJob;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectHealthExplanationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_exposes_health_explanation_and_regenerate_gate(): void
    {
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'health_score' => 44,
        ]);
        ProjectHealthExplanation::factory()->explained()->create([
            'project_id' => $project->id,
            'summary' => 'Failed deployments and open alerts lowered confidence.',
            'drivers' => ['1 failed deployment', '2 open alerts'],
            'recommended_actions' => ['Inspect the failed workflow first'],
        ]);

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Overview')
                    ->where('canRegenerateProjectHealthExplanation', true)
                    ->where('dashboard.riskyProjects.0.health_explanation.status', 'explained')
                    ->where('dashboard.riskyProjects.0.health_explanation.summary', 'Failed deployments and open alerts lowered confidence.')
                    ->where('dashboard.riskyProjects.0.health_explanation.drivers.0', '1 failed deployment')
                    ->where('dashboard.riskyProjects.0.health_explanation.recommended_actions.0', 'Inspect the failed workflow first')
            );
    }

    public function test_regenerate_marks_project_health_explanation_pending_and_dispatches_job_when_ai_is_enabled(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'health_score' => 38,
        ]);
        ProjectHealthExplanation::factory()->explained()->create([
            'project_id' => $project->id,
            'error_message' => 'Old error',
            'failed_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->from(route('overview'))
            ->post(route('overview.projects.health-explanation.regenerate', $project))
            ->assertRedirect(route('overview'))
            ->assertSessionHas('status', 'Project health explanation queued.');

        $this->assertDatabaseHas('project_health_explanations', [
            'project_id' => $project->id,
            'status' => ProjectHealthExplanationStatus::Pending->value,
            'health_score' => 38,
            'failed_at' => null,
            'error_message' => null,
        ]);
        Queue::assertPushed(
            GenerateProjectHealthExplanationJob::class,
            fn (GenerateProjectHealthExplanationJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_regenerate_is_rejected_when_ai_is_disabled(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('overview'))
            ->post(route('overview.projects.health-explanation.regenerate', $project))
            ->assertRedirect(route('overview'))
            ->assertSessionHasErrors('health_explanation');

        $this->assertDatabaseMissing('project_health_explanations', [
            'project_id' => $project->id,
        ]);
        Queue::assertNotPushed(GenerateProjectHealthExplanationJob::class);
    }

    public function test_regenerate_is_forbidden_for_other_users_projects(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($other)
            ->post(route('overview.projects.health-explanation.regenerate', $project))
            ->assertForbidden();

        Queue::assertNotPushed(GenerateProjectHealthExplanationJob::class);
    }
}
