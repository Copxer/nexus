<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\Queries\GetOverviewDashboardQuery;
use App\Enums\AlertSeverity;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetOverviewDashboardQueryTest extends TestCase
{
    use RefreshDatabase;

    private ?User $defaultUser = null;

    /**
     * Spec 035 — `handle()` accepts a User for the riskyProjects
     * scope. Tests that don't care about the user-scoped slice can
     * use this helper to get a stable, no-fuss owner.
     */
    private function defaultUser(): User
    {
        return $this->defaultUser ??= User::factory()->create();
    }

    public function test_handle_returns_the_expected_top_level_shape(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

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

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(2, $payload['dashboard']['projects']['active']);
        $this->assertSame('success', $payload['dashboard']['projects']['status']);
    }

    public function test_projects_kpi_status_is_muted_when_no_active_projects(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['projects']['active']);
        $this->assertSame('muted', $payload['dashboard']['projects']['status']);
    }

    public function test_hosts_kpi_returns_zero_state_with_no_hosts(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['hosts']['online']);
        $this->assertSame(0, $payload['dashboard']['hosts']['offline']);
        $this->assertSame(0, $payload['dashboard']['hosts']['new']);
        $this->assertSame('muted', $payload['dashboard']['hosts']['status']);
        $this->assertSame([], $payload['dashboard']['hosts']['cards']);
    }

    public function test_hosts_kpi_counts_real_online_and_offline_hosts(): void
    {
        $project = Project::factory()->create();
        Host::factory()->online()->count(3)->create(['project_id' => $project->id]);
        Host::factory()->offline()->create(['project_id' => $project->id]);
        Host::factory()->archived()->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(3, $payload['dashboard']['hosts']['online']);
        $this->assertSame(1, $payload['dashboard']['hosts']['offline']);
        // Mixed online + offline → warning.
        $this->assertSame('warning', $payload['dashboard']['hosts']['status']);
    }

    public function test_hosts_kpi_status_is_success_when_no_hosts_offline(): void
    {
        $project = Project::factory()->create();
        Host::factory()->online()->count(2)->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame('success', $payload['dashboard']['hosts']['status']);
    }

    public function test_hosts_kpi_status_is_danger_when_every_host_is_offline(): void
    {
        $project = Project::factory()->create();
        Host::factory()->offline()->count(2)->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame('danger', $payload['dashboard']['hosts']['status']);
    }

    public function test_hosts_cards_order_offline_first_then_online_then_pending_then_name(): void
    {
        $project = Project::factory()->create();
        Host::factory()->online()->create(['project_id' => $project->id, 'name' => 'b-online']);
        Host::factory()->offline()->create(['project_id' => $project->id, 'name' => 'c-offline']);
        Host::factory()->create(['project_id' => $project->id, 'name' => 'a-pending']);
        Host::factory()->online()->create(['project_id' => $project->id, 'name' => 'a-online']);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());
        $names = array_column($payload['dashboard']['hosts']['cards'], 'name');

        $this->assertSame(
            ['c-offline', 'a-online', 'b-online', 'a-pending'],
            $names,
        );
    }

    public function test_hosts_cards_cap_at_six(): void
    {
        $project = Project::factory()->create();
        Host::factory()->online()->count(10)->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertCount(6, $payload['dashboard']['hosts']['cards']);
    }

    public function test_hosts_cards_exclude_archived_hosts(): void
    {
        $project = Project::factory()->create();
        Host::factory()->online()->create(['project_id' => $project->id, 'name' => 'live-host']);
        Host::factory()->archived()->create(['project_id' => $project->id, 'name' => 'archived-host']);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());
        $names = array_column($payload['dashboard']['hosts']['cards'], 'name');

        $this->assertSame(['live-host'], $names);
    }

    public function test_hosts_cards_carry_latest_snapshot_metrics(): void
    {
        $project = Project::factory()->create();
        $host = Host::factory()->online()->create(['project_id' => $project->id]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'cpu_percent' => 42.5,
            'memory_used_mb' => 500,
            'memory_total_mb' => 1000,
            'recorded_at' => now()->subMinute(),
        ]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'cpu_percent' => 18.0,
            'memory_used_mb' => 200,
            'memory_total_mb' => 1000,
            'recorded_at' => now(),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());
        $card = $payload['dashboard']['hosts']['cards'][0];

        // Latest snapshot wins.
        $this->assertSame(18.0, $card['cpu_percent']);
        // PHP-side `round()` keeps a float; this is direct PHP assertion
        // (not JSON round-trip), so the trailing `.0` survives.
        $this->assertSame(20.0, $card['memory_percent']); // 200/1000 = 20.0%
    }

    public function test_hosts_new_count_includes_hosts_created_in_the_last_week(): void
    {
        $project = Project::factory()->create();
        Host::factory()->create([
            'project_id' => $project->id,
            'created_at' => now()->subDays(2),
        ]);
        Host::factory()->create([
            'project_id' => $project->id,
            'created_at' => now()->subDays(10),
        ]);
        // Archived hosts are excluded from `new` (no longer "part of the fleet").
        Host::factory()->archived()->create([
            'project_id' => $project->id,
            'created_at' => now()->subDays(1),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(1, $payload['dashboard']['hosts']['new']);
    }

    public function test_sparklines_are_zero_padded_to_twelve_entries(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

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
            ->handle($this->defaultUser())['dashboard']['projects']['sparkline'];

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
            ->handle($this->defaultUser())['dashboard']['projects']['sparkline'];

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

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertCount(4, $payload['dashboard']['topRepositories']);
        $this->assertSame('owner/repo-0', $payload['dashboard']['topRepositories'][0]['name']);
        $this->assertSame(900, $payload['dashboard']['topRepositories'][0]['commits']);
        $this->assertSame(1.0, $payload['dashboard']['topRepositories'][0]['share']);
        $this->assertSame('owner/repo-3', $payload['dashboard']['topRepositories'][3]['name']);
        $this->assertSame(0.333, $payload['dashboard']['topRepositories'][3]['share']);
    }

    public function test_top_repositories_is_empty_with_no_repositories(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame([], $payload['dashboard']['topRepositories']);
    }

    public function test_mock_kpis_remain_consistent_with_phase_0_values(): void
    {
        // Deployments graduated to a real query in spec 022, uptime in
        // 025, hosts in 029, alerts in 032. The only mocked slice left
        // is `services` — pin it to the phase-0 fixture value.
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(47, $payload['dashboard']['services']['running']);

        $this->assertCount(7, $payload['activityHeatmap']);
        $this->assertCount(6, $payload['activityHeatmap'][0]);
    }

    public function test_alerts_kpi_returns_zero_state_with_no_alerts(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['alerts']['active']);
        $this->assertSame(0, $payload['dashboard']['alerts']['critical']);
        $this->assertSame('success', $payload['dashboard']['alerts']['status']);
        $this->assertCount(12, $payload['dashboard']['alerts']['sparkline']);
    }

    public function test_alerts_kpi_active_counts_open_plus_acknowledged(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->create(['project_id' => $project->id]); // open
        Alert::factory()->acknowledged()->create(['project_id' => $project->id]);
        Alert::factory()->resolved()->create(['project_id' => $project->id]);
        Alert::factory()->muted()->create(['project_id' => $project->id]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(2, $payload['dashboard']['alerts']['active']);
    }

    public function test_alerts_kpi_status_is_warning_when_active_but_no_critical(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(1, $payload['dashboard']['alerts']['active']);
        $this->assertSame(0, $payload['dashboard']['alerts']['critical']);
        $this->assertSame('warning', $payload['dashboard']['alerts']['status']);
    }

    public function test_alerts_kpi_status_is_danger_when_critical_is_open_or_acknowledged(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(1, $payload['dashboard']['alerts']['critical']);
        $this->assertSame('danger', $payload['dashboard']['alerts']['status']);
    }

    public function test_alerts_kpi_sparkline_keys_on_triggered_at_not_created_at(): void
    {
        $project = Project::factory()->create();
        // created_at today, triggered_at 5 days ago — the sparkline must
        // bucket on triggered_at.
        Alert::factory()->create([
            'project_id' => $project->id,
            'triggered_at' => now()->subDays(5),
            'created_at' => now(),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());
        $sparkline = $payload['dashboard']['alerts']['sparkline'];

        $this->assertCount(12, $sparkline);
        // 12-day window, today at index 11; 5 days ago is index 6.
        $this->assertSame(1, $sparkline[6]);
        $this->assertSame(0, $sparkline[11]);
    }

    public function test_uptime_kpi_is_wired_to_the_real_query(): void
    {
        // No checks anywhere → muted + null overall (matches the
        // GetMonitoringUptimeKpiQuery contract verified in its own test).
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertNull($payload['dashboard']['uptime']['overall']);
        $this->assertSame('muted', $payload['dashboard']['uptime']['status']);
        $this->assertCount(12, $payload['dashboard']['uptime']['sparkline']);
    }

    // ────────────────────────────────────────────────────────────────
    // Spec 022 — Deployments KPI (real query against `workflow_runs`).
    // ────────────────────────────────────────────────────────────────

    private function setUpRepository(): Repository
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Repository::factory()->create(['project_id' => $project->id]);
    }

    public function test_deployments_kpi_returns_zero_state_with_no_runs(): void
    {
        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['deployments']['successful_24h']);
        $this->assertNull($payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame(0, $payload['dashboard']['deployments']['change_percent']);
        $this->assertSame('muted', $payload['dashboard']['deployments']['status']);
        $this->assertCount(12, $payload['dashboard']['deployments']['sparkline']);
    }

    public function test_deployments_kpi_counts_successful_runs_in_24h_window(): void
    {
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->count(3)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(3, $payload['dashboard']['deployments']['successful_24h']);
        $this->assertSame(100, $payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame('success', $payload['dashboard']['deployments']['status']);
    }

    public function test_deployments_kpi_excludes_runs_outside_24h_window(): void
    {
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            // Just outside the 24h window.
            'run_completed_at' => now()->subHours(25),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['deployments']['successful_24h']);
    }

    public function test_deployments_kpi_change_percent_compares_to_previous_24h(): void
    {
        $repository = $this->setUpRepository();

        // Previous window (-48h..-24h): 2 successes.
        WorkflowRun::factory()->count(2)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(36),
        ]);
        // Current window: 4 successes — 100% growth.
        WorkflowRun::factory()->count(4)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(4, $payload['dashboard']['deployments']['successful_24h']);
        $this->assertSame(100, $payload['dashboard']['deployments']['change_percent']);
    }

    public function test_deployments_kpi_change_percent_caps_at_999_with_zero_previous(): void
    {
        $repository = $this->setUpRepository();

        // No prior-window successes; 12 in the current window. Without
        // the cap this would render as +∞%.
        WorkflowRun::factory()->count(12)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertLessThanOrEqual(999, $payload['dashboard']['deployments']['change_percent']);
    }

    public function test_deployments_kpi_status_threshold_at_95_is_success(): void
    {
        // Exactly at the 95% boundary — `>=` semantics mean this lands
        // in the success band, not warning.
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->count(19)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'failure',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(95, $payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame('success', $payload['dashboard']['deployments']['status']);
    }

    public function test_deployments_kpi_status_threshold_at_80_is_warning(): void
    {
        // Exactly at the 80% boundary — `>=` semantics mean this lands
        // in the warning band, not danger.
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->count(8)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);
        WorkflowRun::factory()->count(2)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'failure',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(80, $payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame('warning', $payload['dashboard']['deployments']['status']);
    }

    public function test_deployments_kpi_change_percent_floors_at_negative_100(): void
    {
        // Previous window: 3 successes. Current window: 0 successes.
        // (0 - 3) / max(3, 1) * 100 = -100 — already at the floor; this
        // test pins the lower-bound clamp behavior.
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->count(3)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(36),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(0, $payload['dashboard']['deployments']['successful_24h']);
        $this->assertSame(-100, $payload['dashboard']['deployments']['change_percent']);
    }

    public function test_deployments_kpi_status_threshold_warning(): void
    {
        $repository = $this->setUpRepository();

        // 17 successes / 20 completed = 85% → warning band.
        WorkflowRun::factory()->count(17)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);
        WorkflowRun::factory()->count(3)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'failure',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(85, $payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame('warning', $payload['dashboard']['deployments']['status']);
    }

    public function test_deployments_kpi_status_threshold_danger(): void
    {
        $repository = $this->setUpRepository();

        // 1 success / 2 completed = 50% → danger band.
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->subHours(2),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'failure',
            'run_completed_at' => now()->subHours(2),
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($this->defaultUser());

        $this->assertSame(50, $payload['dashboard']['deployments']['success_rate_24h']);
        $this->assertSame('danger', $payload['dashboard']['deployments']['status']);
    }

    public function test_deployments_kpi_sparkline_counts_daily_completed_runs(): void
    {
        $repository = $this->setUpRepository();

        // 1 today, 2 three days ago, 1 eleven days ago.
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_completed_at' => now()->startOfDay()->addHours(10),
        ]);
        WorkflowRun::factory()->count(2)->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'failure',
            'run_completed_at' => now()->startOfDay()->subDays(3)->addHours(10),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'completed',
            'conclusion' => 'cancelled',
            'run_completed_at' => now()->startOfDay()->subDays(11)->addHours(10),
        ]);

        $sparkline = (new GetOverviewDashboardQuery)
            ->handle($this->defaultUser())['dashboard']['deployments']['sparkline'];

        $this->assertCount(12, $sparkline);
        // Oldest at index 0 (11 days ago), today at index 11.
        $this->assertSame(1, $sparkline[0]);
        $this->assertSame(2, $sparkline[8]);
        $this->assertSame(1, $sparkline[11]);
        $this->assertSame(4, array_sum($sparkline));
    }

    public function test_deployments_kpi_sparkline_excludes_in_progress_runs(): void
    {
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => 'in_progress',
            'conclusion' => null,
            'run_completed_at' => null,
        ]);

        $sparkline = (new GetOverviewDashboardQuery)
            ->handle($this->defaultUser())['dashboard']['deployments']['sparkline'];

        $this->assertSame(array_fill(0, 12, 0), $sparkline);
    }

    // ────────────────────────────────────────────────────────────────
    // fix/activity-heatmap — real `activity_events` aggregate.
    // ────────────────────────────────────────────────────────────────

    public function test_activity_heatmap_is_all_zeros_with_no_events(): void
    {
        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(array_fill(0, 7, array_fill(0, 6, 0)), $heatmap);
    }

    public function test_activity_heatmap_buckets_event_by_day_and_hour(): void
    {
        $repository = $this->setUpRepository();

        // Wednesday at 14:30 → day=3, hour=14, bucket=3 (12:00–16:00).
        ActivityEvent::factory()->create([
            'repository_id' => $repository->id,
            'occurred_at' => Carbon::parse('2026-04-29 14:30:00', 'UTC'), // 2026-04-29 was a Wed
        ]);

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(1, $heatmap[3][3]);
        // Every other cell is zero.
        $this->assertSame(1, array_sum(array_map('array_sum', $heatmap)));
    }

    public function test_activity_heatmap_accumulates_multiple_events_in_same_bucket(): void
    {
        $repository = $this->setUpRepository();

        // Three events all on Monday at 09:00–10:30 → day=1, bucket=2 (08:00–12:00).
        foreach (['2026-04-27 09:00:00', '2026-04-27 09:45:00', '2026-04-27 10:30:00'] as $iso) {
            ActivityEvent::factory()->create([
                'repository_id' => $repository->id,
                'occurred_at' => Carbon::parse($iso, 'UTC'), // 2026-04-27 was a Mon
            ]);
        }

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(3, $heatmap[1][2]);
    }

    public function test_activity_heatmap_excludes_events_older_than_90_days(): void
    {
        $repository = $this->setUpRepository();

        ActivityEvent::factory()->create([
            'repository_id' => $repository->id,
            'occurred_at' => now()->subDays(91)->startOfDay()->addHours(10),
        ]);

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(array_fill(0, 7, array_fill(0, 6, 0)), $heatmap);
    }

    public function test_activity_heatmap_includes_events_inside_12_week_window(): void
    {
        // Spec 035 — window is exactly 12 weeks (84 days). An 83-day-
        // old event should land inside; the 91-day-outside boundary
        // is covered by its sibling test below.
        $repository = $this->setUpRepository();

        ActivityEvent::factory()->create([
            'repository_id' => $repository->id,
            'occurred_at' => now()->subDays(83)->startOfDay()->addHours(10),
        ]);

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(1, array_sum(array_map('array_sum', $heatmap)));
    }

    public function test_activity_heatmap_excludes_events_outside_12_week_window(): void
    {
        // Spec 035 — events older than 84 days must not contribute.
        // 91 days is well past the boundary.
        $repository = $this->setUpRepository();

        ActivityEvent::factory()->create([
            'repository_id' => $repository->id,
            'occurred_at' => now()->subDays(91)->startOfDay()->addHours(10),
        ]);

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertSame(0, array_sum(array_map('array_sum', $heatmap)));
    }

    public function test_activity_heatmap_returns_seven_by_six_grid(): void
    {
        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        $this->assertCount(7, $heatmap);
        foreach ($heatmap as $day) {
            $this->assertCount(6, $day);
        }
    }

    /**
     * Pin the four-hour bucket boundary contract: 00:00 → bucket 0,
     * 03:59 → bucket 0, 04:00 → bucket 1, 23:59 → bucket 5. A boundary
     * regression here would silently misbucket a chunk of every
     * account's events.
     */
    public function test_activity_heatmap_bucket_boundary_contract(): void
    {
        $repository = $this->setUpRepository();

        // 2026-04-26 was a Sunday → day-of-week index 0. Seed one event
        // per boundary so each (time, expected bucket) row asserts in
        // isolation against the heatmap's grand total.
        $cases = [
            '00:00:00' => 0,
            '03:59:59' => 0,
            '04:00:00' => 1,
            '11:59:59' => 2,
            '12:00:00' => 3,
            '23:59:59' => 5,
        ];

        foreach (array_keys($cases) as $time) {
            ActivityEvent::factory()->create([
                'repository_id' => $repository->id,
                'occurred_at' => Carbon::parse("2026-04-26 {$time}", 'UTC'),
            ]);
        }

        $heatmap = (new GetOverviewDashboardQuery)->handle($this->defaultUser())['activityHeatmap'];

        // Aggregate the expected counts per bucket from the case map.
        $expected = array_fill(0, 6, 0);
        foreach ($cases as $bucket) {
            $expected[$bucket]++;
        }

        $this->assertSame($expected, $heatmap[0]);
        // Other day rows are untouched.
        $this->assertSame(count($cases), array_sum(array_map('array_sum', $heatmap)));
    }

    // ─── Spec 035 — riskyProjects slice ─────────────────────────────

    public function test_risky_projects_orders_by_health_score_ascending(): void
    {
        $owner = User::factory()->create();
        $healthy = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Healthy',
            'health_score' => 95,
        ]);
        $degraded = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Degraded',
            'health_score' => 55,
        ]);
        $critical = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Critical',
            'health_score' => 15,
        ]);

        $payload = (new GetOverviewDashboardQuery)->handle($owner);
        $rows = $payload['dashboard']['riskyProjects'];

        $this->assertCount(3, $rows);
        $this->assertSame('Critical', $rows[0]['name']);
        $this->assertSame('Degraded', $rows[1]['name']);
        $this->assertSame('Healthy', $rows[2]['name']);
    }

    public function test_risky_projects_places_unscored_projects_last(): void
    {
        $owner = User::factory()->create();
        // An unscored project must NOT displace a critically-scored
        // one — that would defeat the panel's purpose. The
        // `health_score IS NULL` raw clause is the guard.
        Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Unscored',
            'health_score' => null,
        ]);
        Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Critical',
            'health_score' => 20,
        ]);

        $rows = (new GetOverviewDashboardQuery)
            ->handle($owner)['dashboard']['riskyProjects'];

        $this->assertSame('Critical', $rows[0]['name']);
        $this->assertSame('Unscored', $rows[1]['name']);
    }

    public function test_risky_projects_caps_at_six_rows(): void
    {
        // ProjectFactory uses `fake()->unique()` over an 8-name pool,
        // which can't satisfy 10 unique creates even when we override
        // `name` because the unique generator is consumed inside
        // `definition()` before the override applies. Reset the
        // faker unique state per-iteration so each call gets a fresh
        // pool — keeps the test honest about the cap boundary.
        $owner = User::factory()->create();
        for ($i = 1; $i <= 10; $i++) {
            fake()->unique(reset: true);
            Project::factory()->create([
                'owner_user_id' => $owner->id,
                'health_score' => 30,
            ]);
        }

        $rows = (new GetOverviewDashboardQuery)
            ->handle($owner)['dashboard']['riskyProjects'];

        $this->assertCount(6, $rows);
    }

    public function test_risky_projects_is_empty_when_user_has_no_projects(): void
    {
        $owner = User::factory()->create();

        $rows = (new GetOverviewDashboardQuery)
            ->handle($owner)['dashboard']['riskyProjects'];

        $this->assertSame([], $rows);
    }

    public function test_risky_projects_is_cross_user_isolated(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        for ($i = 1; $i <= 3; $i++) {
            Project::factory()->create([
                'owner_user_id' => $b->id,
                'name' => "Bee {$i}",
                'health_score' => 20,
            ]);
        }

        $rows = (new GetOverviewDashboardQuery)
            ->handle($a)['dashboard']['riskyProjects'];

        $this->assertSame([], $rows, "A's payload must not include B's projects");
    }

    public function test_risky_projects_row_carries_expected_shape(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Edge Cache',
            'health_score' => 45,
            'color' => 'cyan',
            'icon' => 'HeartPulse',
        ]);

        $row = (new GetOverviewDashboardQuery)
            ->handle($owner)['dashboard']['riskyProjects'][0];

        $this->assertSame($project->id, $row['id']);
        $this->assertSame($project->slug, $row['slug']);
        $this->assertSame('Edge Cache', $row['name']);
        $this->assertSame('cyan', $row['color']);
        $this->assertSame('HeartPulse', $row['icon']);
        $this->assertSame(45, $row['health_score']);
        $this->assertSame('warning', $row['health_band']);
        $this->assertArrayHasKey('last_activity_at', $row);
    }
}
