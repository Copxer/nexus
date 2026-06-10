<?php

namespace Tests\Unit\Domain\Analytics\Queries;

use App\Domain\Analytics\Queries\GetWebsiteMetricsQuery;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetWebsiteMetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function from30Days(): Carbon
    {
        return now()->startOfDay()->subDays(29);
    }

    public function test_empty_state_returns_muted_status_and_neutral_sparklines(): void
    {
        $user = User::factory()->create();

        $result = app(GetWebsiteMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertNull($result['uptime']['percent']);
        $this->assertSame('muted', $result['uptime']['status']);
        $this->assertSame(array_fill(0, 30, 100.0), $result['uptime']['sparkline']);
        $this->assertNull($result['response_time']['avg_ms']);
        $this->assertSame('muted', $result['response_time']['status']);
        $this->assertSame(array_fill(0, 30, null), $result['response_time']['sparkline']);
    }

    public function test_uptime_is_volume_weighted_across_user_websites(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $site = Website::factory()->create(['project_id' => $project->id]);

        // 9 up + 1 down = 90% — below the 95% warning floor.
        WebsiteCheck::factory()->count(9)->create([
            'website_id' => $site->id,
            'status' => 'up',
            'response_time_ms' => 200,
            'checked_at' => now()->subDays(2),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $site->id,
            'status' => 'down',
            'response_time_ms' => null,
            'checked_at' => now()->subDays(2),
        ]);

        $result = app(GetWebsiteMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(90.0, $result['uptime']['percent']);
        $this->assertSame('danger', $result['uptime']['status']);
    }

    public function test_response_time_avg_uses_only_up_status_checks(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $site = Website::factory()->create(['project_id' => $project->id]);

        // Avg of up checks: (100 + 300) / 2 = 200ms.
        // The down + slow checks must not pull the average up.
        WebsiteCheck::factory()->create([
            'website_id' => $site->id,
            'status' => 'up',
            'response_time_ms' => 100,
            'checked_at' => now()->subDay(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $site->id,
            'status' => 'up',
            'response_time_ms' => 300,
            'checked_at' => now()->subDay(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $site->id,
            'status' => 'down',
            'response_time_ms' => 5000, // ignored
            'checked_at' => now()->subDay(),
        ]);

        $result = app(GetWebsiteMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(200, $result['response_time']['avg_ms']);
        $this->assertSame('success', $result['response_time']['status']);
    }

    public function test_cross_user_isolation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $projectB = Project::factory()->create(['owner_user_id' => $b->id]);
        $siteB = Website::factory()->create(['project_id' => $projectB->id]);
        WebsiteCheck::factory()->count(5)->create([
            'website_id' => $siteB->id,
            'status' => 'down',
            'checked_at' => now()->subDay(),
        ]);

        $result = app(GetWebsiteMetricsQuery::class)->execute($a, $this->from30Days());

        $this->assertNull($result['uptime']['percent']);
        $this->assertNull($result['response_time']['avg_ms']);
    }
}
