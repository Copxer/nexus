<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Container> */
class ContainerFactory extends Factory
{
    protected $model = Container::class;

    public function definition(): array
    {
        return [
            'host_id' => Host::factory(),
            'project_id' => null,
            'container_id' => Str::random(12),
            'name' => 'svc-'.fake()->word(),
            'image' => 'nginx',
            'image_tag' => 'latest',
            'status' => 'running',
            'state' => 'running',
            'health_status' => null,
            'ports' => [],
            'labels' => [],
            'cpu_percent' => null,
            'memory_usage_mb' => null,
            'memory_limit_mb' => null,
            'memory_percent' => null,
            'network_rx_bytes' => null,
            'network_tx_bytes' => null,
            'block_read_bytes' => null,
            'block_write_bytes' => null,
            'started_at' => now()->subHours(2),
            'finished_at' => null,
            'last_seen_at' => now(),
        ];
    }
}
