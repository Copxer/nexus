<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Queries\GetWebsitePerformanceSummaryQuery;
use App\Enums\WebsiteCheckStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetWebsitePerformanceSummaryQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create(['project_id' => $project->id]);
    }

    public function test_empty_window_returns_null_for_each_uptime(): void
    {
        $website = $this->makeWebsite();

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertNull($summary['uptime_24h']);
        $this->assertNull($summary['uptime_7d']);
        $this->assertNull($summary['uptime_30d']);
        $this->assertNull($summary['last_incident_at']);
    }

    public function test_all_up_returns_100_percent(): void
    {
        $website = $this->makeWebsite();

        WebsiteCheck::factory()->count(3)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHours(2),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertSame(100.0, $summary['uptime_24h']);
        $this->assertNull($summary['last_incident_at']);
    }

    public function test_slow_counts_as_successful(): void
    {
        $website = $this->makeWebsite();

        WebsiteCheck::factory()->count(2)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHours(2),
        ]);
        WebsiteCheck::factory()->count(2)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Slow->value,
            'checked_at' => now()->subHours(3),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertSame(100.0, $summary['uptime_24h']);
    }

    public function test_mixed_checks_compute_correct_percentage(): void
    {
        $website = $this->makeWebsite();

        // 4 successful (up), 1 failed (down) → 80%.
        WebsiteCheck::factory()->count(4)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHours(2),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHours(2),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertSame(80.0, $summary['uptime_24h']);
    }

    public function test_window_excludes_old_checks(): void
    {
        $website = $this->makeWebsite();

        // In the 7d window but not the 24h window.
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subDays(3),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertNull($summary['uptime_24h']); // empty 24h window
        $this->assertSame(100.0, $summary['uptime_7d']);
        $this->assertSame(100.0, $summary['uptime_30d']);
    }

    public function test_last_incident_returns_most_recent_failed_check(): void
    {
        $website = $this->makeWebsite();

        $oldFailure = Carbon::parse('2026-04-01 10:00:00');
        $recentFailure = Carbon::parse('2026-04-15 14:00:00');

        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => $oldFailure,
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Error->value,
            'checked_at' => $recentFailure,
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertNotNull($summary['last_incident_at']);
        $this->assertSame(
            $recentFailure->toIso8601String(),
            $summary['last_incident_at']->toIso8601String(),
        );
    }

    public function test_last_incident_ignores_successful_checks(): void
    {
        $website = $this->makeWebsite();

        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Slow->value,
            'checked_at' => now()->subMinutes(30),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertNull($summary['last_incident_at']);
    }

    public function test_query_scopes_to_one_website(): void
    {
        $website = $this->makeWebsite();
        $other = $this->makeWebsite();

        WebsiteCheck::factory()->count(3)->create([
            'website_id' => $other->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        $summary = (new GetWebsitePerformanceSummaryQuery)->execute($website);

        $this->assertNull($summary['uptime_24h']);
        $this->assertNull($summary['last_incident_at']);
    }
}
