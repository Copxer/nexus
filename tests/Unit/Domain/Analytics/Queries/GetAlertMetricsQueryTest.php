<?php

namespace Tests\Unit\Domain\Analytics\Queries;

use App\Domain\Analytics\Queries\GetAlertMetricsQuery;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetAlertMetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function from30Days(): Carbon
    {
        return now()->startOfDay()->subDays(29);
    }

    public function test_empty_state_returns_muted_mttr_and_zero_frequency(): void
    {
        $user = User::factory()->create();

        $result = app(GetAlertMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(0, $result['frequency']['total']);
        $this->assertCount(30, $result['frequency']['sparkline']);
        $this->assertNull($result['mttr']['seconds']);
        $this->assertNull($result['mttr']['label']);
        $this->assertSame('muted', $result['mttr']['status']);
    }

    public function test_counts_alerts_triggered_in_window(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->count(3)->create([
            'project_id' => $project->id,
            'triggered_at' => now()->subDays(2),
        ]);

        $result = app(GetAlertMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(3, $result['frequency']['total']);
    }

    public function test_computes_mttr_as_average_seconds_for_resolved_alerts_in_window(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        // Two resolved alerts: 5 min + 15 min → avg 10 min = 600s.
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'triggered_at' => now()->subHours(2),
            'resolved_at' => now()->subHours(2)->addMinutes(5),
        ]);
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'triggered_at' => now()->subHour(),
            'resolved_at' => now()->subHour()->addMinutes(15),
        ]);

        $result = app(GetAlertMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(600, $result['mttr']['seconds']);
        $this->assertSame('10m', $result['mttr']['label']);
        $this->assertSame('warning', $result['mttr']['status']);
    }

    public function test_unresolved_alerts_do_not_contribute_to_mttr(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'status' => AlertStatus::Open->value,
            'triggered_at' => now()->subHour(),
            'resolved_at' => null,
        ]);

        $result = app(GetAlertMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(1, $result['frequency']['total']);
        $this->assertNull($result['mttr']['seconds']);
    }

    public function test_humanize_label_handles_seconds_minutes_and_hours(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        // Single resolved alert at 1h 5m 30s = 3930s.
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'triggered_at' => now()->subHours(2),
            'resolved_at' => now()->subHours(2)->addSeconds(3930),
        ]);

        $result = app(GetAlertMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(3930, $result['mttr']['seconds']);
        // Once we're past 60 min, the format drops seconds and uses
        // hours + minutes — "1h 5m" reads better than "65m 30s" in
        // the dashboard chip.
        $this->assertSame('1h 5m', $result['mttr']['label']);
        $this->assertSame('danger', $result['mttr']['status']);
    }

    public function test_cross_user_isolation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $projectB = Project::factory()->create(['owner_user_id' => $b->id]);
        Alert::factory()->count(5)->create([
            'project_id' => $projectB->id,
            'triggered_at' => now()->subDay(),
        ]);

        $result = app(GetAlertMetricsQuery::class)->execute($a, $this->from30Days());

        $this->assertSame(0, $result['frequency']['total']);
    }
}
