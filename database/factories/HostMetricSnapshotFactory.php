<?php

namespace Database\Factories;

use App\Models\Host;
use App\Models\HostMetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HostMetricSnapshot> */
class HostMetricSnapshotFactory extends Factory
{
    protected $model = HostMetricSnapshot::class;

    public function definition(): array
    {
        return [
            'host_id' => Host::factory(),
            'cpu_percent' => fake()->randomFloat(1, 5, 95),
            'memory_used_mb' => fake()->numberBetween(1024, 16384),
            'memory_total_mb' => 16384,
            'disk_used_gb' => fake()->numberBetween(20, 200),
            'disk_total_gb' => 256,
            'load_average' => fake()->randomFloat(2, 0, 4),
            'network_rx_bytes' => fake()->numberBetween(1_000_000, 10_000_000_000),
            'network_tx_bytes' => fake()->numberBetween(1_000_000, 10_000_000_000),
            'recorded_at' => now(),
        ];
    }
}
