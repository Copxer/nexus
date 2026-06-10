<?php

namespace Tests\Unit\Domain\Analytics\Queries;

use App\Domain\Analytics\Queries\GetContainerResourceUsageQuery;
use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetContainerResourceUsageQueryTest extends TestCase
{
    use RefreshDatabase;

    private function from30Days(): Carbon
    {
        return now()->startOfDay()->subDays(29);
    }

    public function test_user_without_hosts_returns_muted_shape(): void
    {
        $user = User::factory()->create();

        $result = app(GetContainerResourceUsageQuery::class)->execute($user, $this->from30Days());

        $this->assertNull($result['cpu']['percent']);
        $this->assertSame('muted', $result['cpu']['status']);
        $this->assertCount(30, $result['cpu']['sparkline']);
        $this->assertSame(array_fill(0, 30, null), $result['cpu']['sparkline']);
        $this->assertNull($result['memory']['percent']);
        $this->assertSame('muted', $result['memory']['status']);
    }

    public function test_averages_cpu_and_memory_across_user_snapshots(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $container = Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
        ]);

        // CPU avg = (10 + 30) / 2 = 20 → success band (<60).
        // Memory avg = (50 + 90) / 2 = 70 → warning band (<85).
        ContainerMetricSnapshot::factory()->create([
            'container_id' => $container->id,
            'cpu_percent' => 10.0,
            'memory_percent' => 50.0,
            'recorded_at' => now()->subDays(2),
        ]);
        ContainerMetricSnapshot::factory()->create([
            'container_id' => $container->id,
            'cpu_percent' => 30.0,
            'memory_percent' => 90.0,
            'recorded_at' => now()->subDays(2),
        ]);

        $result = app(GetContainerResourceUsageQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(20.0, $result['cpu']['percent']);
        $this->assertSame('success', $result['cpu']['status']);
        $this->assertSame(70.0, $result['memory']['percent']);
        $this->assertSame('warning', $result['memory']['status']);
    }

    public function test_snapshots_outside_window_are_excluded(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $container = Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
        ]);

        ContainerMetricSnapshot::factory()->create([
            'container_id' => $container->id,
            'cpu_percent' => 99.0,
            'memory_percent' => 99.0,
            'recorded_at' => now()->subDays(40), // outside 30d
        ]);

        $result = app(GetContainerResourceUsageQuery::class)->execute($user, $this->from30Days());

        $this->assertNull($result['cpu']['percent']);
    }

    public function test_cross_user_isolation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $projectB = Project::factory()->create(['owner_user_id' => $b->id]);
        $hostB = Host::factory()->create(['project_id' => $projectB->id]);
        $containerB = Container::factory()->create([
            'host_id' => $hostB->id,
            'project_id' => $projectB->id,
        ]);
        ContainerMetricSnapshot::factory()->count(5)->create([
            'container_id' => $containerB->id,
            'cpu_percent' => 95.0,
            'memory_percent' => 95.0,
            'recorded_at' => now()->subDay(),
        ]);

        $result = app(GetContainerResourceUsageQuery::class)->execute($a, $this->from30Days());

        $this->assertNull($result['cpu']['percent']);
        $this->assertNull($result['memory']['percent']);
    }
}
