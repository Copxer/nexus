<?php

namespace Tests\Feature;

use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_renders_for_a_verified_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertStatus(200);
    }

    public function test_overview_carries_the_mock_dashboard_payload(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Overview')
                    ->has('dashboard', fn (AssertableInertia $dashboard) => $dashboard
                        ->has('projects.active')
                        ->has('projects.sparkline')
                        // No seeded data in this test → projects.status is 'muted'.
                        ->where('projects.status', 'muted')
                        ->has('deployments.successful_24h')
                        ->has('services.running')
                        ->has('hosts.online')
                        ->has('alerts.active')
                        ->where('alerts.status', 'danger')
                        ->has('uptime.overall')
                        ->has('topRepositories')
                    )
                    ->has('topWorkItems')
            );
    }

    public function test_overview_topworkitems_pulls_from_user_repos_only(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'mine/repo',
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'number' => 7,
            'title' => 'Mine',
            'state' => 'open',
        ]);

        // Sibling user's open issue must NOT leak into the widget.
        $other = User::factory()->create(['email_verified_at' => now()]);
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $otherRepo = Repository::factory()->create([
            'project_id' => $otherProject->id,
            'full_name' => 'other/repo',
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $otherRepo->id,
            'number' => 1,
            'title' => 'Sibling',
            'state' => 'open',
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('topWorkItems', 1)
                    ->where('topWorkItems.0.title', 'Mine')
            );
    }

    public function test_overview_carries_activity_heatmap_payload(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        // Overview no longer ships its own activity slice — the rail
        // consumes the shared `activity.recent` prop populated by
        // `HandleInertiaRequests` (specs 018/019). Heatmap stays here
        // until phase 3's heatmap aggregate replaces the mock.
        $this->actingAs($user)
            ->get('/overview')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Overview')
                    ->missing('recentActivity')
                    ->has('activityHeatmap', 7)
                    ->has('activityHeatmap.0', 6)
            );
    }
}
