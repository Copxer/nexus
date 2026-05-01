<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Queries\GetMonitoringUptimeKpiQuery;
use App\Enums\WebsiteCheckStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetMonitoringUptimeKpiQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create(['project_id' => $project->id]);
    }

    public function test_empty_state_returns_null_overall_and_muted_status(): void
    {
        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertNull($kpi['overall']);
        $this->assertSame(0.0, $kpi['change']);
        $this->assertSame('muted', $kpi['status']);
        $this->assertCount(12, $kpi['sparkline']);
        // Empty days default to 100.0 so a fresh account doesn't read
        // as "everything was down."
        $this->assertSame(array_fill(0, 12, 100.0), $kpi['sparkline']);
    }

    public function test_volume_weighted_rate_across_all_websites(): void
    {
        $w1 = $this->makeWebsite();
        $w2 = $this->makeWebsite();

        // Website 1: 9 successful, 1 failed (90%).
        WebsiteCheck::factory()->count(9)->create([
            'website_id' => $w1->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $w1->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        // Website 2: 1 successful, 0 failed (100%).
        WebsiteCheck::factory()->create([
            'website_id' => $w2->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        // Volume-weighted: 10 / 11 = 90.91 (B-style averaging — busy
        // website with a failure dominates the quiet 100% website).
        $this->assertSame(90.91, $kpi['overall']);
    }

    public function test_slow_counts_as_successful(): void
    {
        $website = $this->makeWebsite();

        WebsiteCheck::factory()->count(2)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->count(2)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Slow->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(100.0, $kpi['overall']);
    }

    public function test_change_compares_to_previous_24h_window(): void
    {
        $website = $this->makeWebsite();

        // Previous 24h: 1 success, 1 failure → 50%.
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHours(36),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHours(36),
        ]);

        // Current 24h: 4 success, 1 failure → 80%.
        WebsiteCheck::factory()->count(4)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(80.0, $kpi['overall']);
        $this->assertSame(30.0, $kpi['change']);
    }

    public function test_change_is_zero_when_either_window_is_empty(): void
    {
        $website = $this->makeWebsite();

        // Only current window has data.
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(100.0, $kpi['overall']);
        $this->assertSame(0.0, $kpi['change']);
    }

    public function test_status_threshold_at_99_is_success(): void
    {
        $website = $this->makeWebsite();

        // Exactly 99% — 99 successes, 1 failure.
        WebsiteCheck::factory()->count(99)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(99.0, $kpi['overall']);
        $this->assertSame('success', $kpi['status']);
    }

    public function test_status_threshold_at_95_is_warning(): void
    {
        $website = $this->makeWebsite();

        // Exactly 95% — 19 successes, 1 failure.
        WebsiteCheck::factory()->count(19)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(95.0, $kpi['overall']);
        $this->assertSame('warning', $kpi['status']);
    }

    public function test_status_below_95_is_danger(): void
    {
        $website = $this->makeWebsite();

        // 90% — 9 successes, 1 failure.
        WebsiteCheck::factory()->count(9)->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subHour(),
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->subHour(),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(90.0, $kpi['overall']);
        $this->assertSame('danger', $kpi['status']);
    }

    public function test_sparkline_includes_today_at_last_index(): void
    {
        $website = $this->makeWebsite();

        // Today: 1 success.
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->startOfDay()->addHours(10),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        $this->assertSame(100.0, $kpi['sparkline'][11]);
        // Days 0..10 are empty → defaulted to 100.0.
        $this->assertSame(array_fill(0, 11, 100.0), array_slice($kpi['sparkline'], 0, 11));
    }

    public function test_sparkline_empty_day_defaults_to_100(): void
    {
        $website = $this->makeWebsite();

        // 5 days ago: 1 failure.
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Down->value,
            'checked_at' => now()->startOfDay()->subDays(5)->addHours(10),
        ]);

        $kpi = (new GetMonitoringUptimeKpiQuery)->execute();

        // Today (index 11) is empty → 100.
        $this->assertSame(100.0, $kpi['sparkline'][11]);
        // 5 days ago (index 6 = 11 - 5) had only the failure → 0.0.
        $this->assertSame(0.0, $kpi['sparkline'][6]);
    }
}
