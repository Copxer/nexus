<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContainerMetricSnapshot> */
class ContainerMetricSnapshotFactory extends Factory
{
    protected $model = ContainerMetricSnapshot::class;

    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'cpu_percent' => fake()->randomFloat(1, 0, 100),
            'memory_usage_mb' => fake()->numberBetween(64, 4096),
            'memory_limit_mb' => 4096,
            'memory_percent' => fake()->randomFloat(1, 0, 100),
            'network_rx_bytes' => fake()->numberBetween(0, 1_000_000_000),
            'network_tx_bytes' => fake()->numberBetween(0, 1_000_000_000),
            'block_read_bytes' => fake()->numberBetween(0, 1_000_000_000),
            'block_write_bytes' => fake()->numberBetween(0, 1_000_000_000),
            'recorded_at' => now(),
        ];
    }
}
