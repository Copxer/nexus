<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\Queries\GetOverviewDashboardQuery;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetOverviewDashboardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_returns_the_expected_top_level_shape(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertArrayHasKey('dashboard', $payload);
        $this->assertArrayHasKey('activityHeatmap', $payload);
        $this->assertArrayNotHasKey('recentActivity', $payload);

        foreach (['projects', 'deployments', 'services', 'hosts', 'alerts', 'uptime', 'topRepositories'] as $key) {
            $this->assertArrayHasKey($key, $payload['dashboard']);
        }
    }

    public function test_projects_kpi_reflects_live_active_count(): void
    {
        $owner = User::factory()->create();
        Project::factory()->count(2)->create([
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);
        Project::factory()->create([
            'owner_user_id' => $owner->id,
            'status' => 'archived',
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertSame(2, $payload['dashboard']['projects']['active']);
        $this->assertSame('success', $payload['dashboard']['projects']['status']);
    }

    public function test_projects_kpi_status_is_muted_when_no_active_projects(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertSame(0, $payload['dashboard']['projects']['active']);
        $this->assertSame('muted', $payload['dashboard']['projects']['status']);
    }

    public function test_hosts_kpi_proxies_repository_count(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        Repository::factory()->count(5)->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertSame(5, $payload['dashboard']['hosts']['online']);
        $this->assertSame('info', $payload['dashboard']['hosts']['status']);
    }

    public function test_sparklines_are_zero_padded_to_twelve_entries(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertCount(12, $payload['dashboard']['projects']['sparkline']);
        $this->assertCount(12, $payload['dashboard']['hosts']['sparkline']);
        $this->assertSame(
            array_fill(0, 12, 0),
            $payload['dashboard']['projects']['sparkline'],
        );
    }

    public function test_sparkline_lands_today_at_the_last_index(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create([
            'owner_user_id' => $owner->id,
            'created_at' => now(),
        ]);

        $sparkline = (new GetOverviewDashboardQuery)
            ->handle()['dashboard']['projects']['sparkline'];

        // 12 days, oldest at index 0, today at index 11. The newly created
        // project should land in the final bucket and only there.
        $this->assertSame(1, $sparkline[11]);
        $this->assertSame(0, array_sum(array_slice($sparkline, 0, 11)));
    }

    public function test_sparkline_lands_oldest_day_at_index_zero(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create([
            'owner_user_id' => $owner->id,
            'created_at' => now()->startOfDay()->subDays(11),
        ]);

        $sparkline = (new GetOverviewDashboardQuery)
            ->handle()['dashboard']['projects']['sparkline'];

        // A row stamped exactly 11 days ago lands at the start of the
        // 12-day window (index 0).
        $this->assertSame(1, $sparkline[0]);
        $this->assertSame(0, array_sum(array_slice($sparkline, 1)));
    }

    public function test_top_repositories_orders_by_stars_desc_and_caps_at_default_limit(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        // 6 repos with descending star counts; only the top 4 should land.
        // Pin `last_pushed_at` so the secondary ordering tie-breaker is
        // deterministic if any factory defaults later collide.
        foreach ([900, 700, 500, 300, 100, 50] as $i => $stars) {
            Repository::factory()->create([
                'project_id' => $project->id,
                'full_name' => "owner/repo-{$i}",
                'stars_count' => $stars,
                'last_pushed_at' => now()->subMinutes($i),
            ]);
        }

        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertCount(4, $payload['dashboard']['topRepositories']);
        $this->assertSame('owner/repo-0', $payload['dashboard']['topRepositories'][0]['name']);
        $this->assertSame(900, $payload['dashboard']['topRepositories'][0]['commits']);
        $this->assertSame(1.0, $payload['dashboard']['topRepositories'][0]['share']);
        $this->assertSame('owner/repo-3', $payload['dashboard']['topRepositories'][3]['name']);
        $this->assertSame(0.333, $payload['dashboard']['topRepositories'][3]['share']);
    }

    public function test_top_repositories_is_empty_with_no_repositories(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertSame([], $payload['dashboard']['topRepositories']);
    }

    public function test_mock_kpis_remain_consistent_with_phase_0_values(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle();

        $this->assertSame(24, $payload['dashboard']['deployments']['successful_24h']);
        $this->assertSame(47, $payload['dashboard']['services']['running']);
        $this->assertSame(3, $payload['dashboard']['alerts']['active']);
        $this->assertSame('danger', $payload['dashboard']['alerts']['status']);
        $this->assertSame(99.98, $payload['dashboard']['uptime']['overall']);

        $this->assertCount(7, $payload['activityHeatmap']);
        $this->assertCount(6, $payload['activityHeatmap'][0]);
    }
}
