<?php

namespace Database\Seeders;

use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Spec 040 — seed 2 hosts so the Docker pages aren't empty on a
 * fresh `db:seed`. One host is online with containers + recent
 * metric snapshots (the happy path); one is offline (past the
 * heartbeat window) so the Hosts page shows both states without
 * needing a running agent.
 */
class HostSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->oldest('id')->first();

        if ($project === null) {
            return;
        }

        // Online host with 5 containers + a thin metric history.
        $online = Host::factory()->online()->create([
            'project_id' => $project->id,
            'name' => 'edge-1',
            'slug' => 'edge-1',
        ]);

        HostMetricSnapshot::factory()
            ->count(6)
            ->create(['host_id' => $online->id])
            ->each(function (HostMetricSnapshot $snapshot, int $i): void {
                $snapshot->forceFill(['recorded_at' => now()->subMinutes($i * 5)])->save();
            });

        $containerNames = ['nginx-edge', 'api-gateway', 'redis-cache', 'postgres-primary', 'worker'];
        foreach ($containerNames as $name) {
            $container = Container::factory()->create([
                'host_id' => $online->id,
                'project_id' => $project->id,
                'name' => $name,
                'health_status' => 'healthy',
            ]);

            ContainerMetricSnapshot::factory()
                ->count(3)
                ->create(['container_id' => $container->id])
                ->each(function (ContainerMetricSnapshot $snapshot, int $i): void {
                    $snapshot->forceFill(['recorded_at' => now()->subMinutes($i * 5)])->save();
                });
        }

        // Offline host — past the 120s heartbeat threshold so spec 029's
        // DetectOfflineHostsJob would mark it offline on its next tick.
        // We set the state directly so the demo doesn't depend on the
        // scheduler running locally.
        Host::factory()->offline()->create([
            'project_id' => $project->id,
            'name' => 'edge-2',
            'slug' => 'edge-2',
        ]);
    }
}
