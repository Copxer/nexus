<?php

namespace Database\Factories;

use App\Enums\HostConnectionType;
use App\Enums\HostStatus;
use App\Models\Host;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Host> */
class HostFactory extends Factory
{
    protected $model = Host::class;

    public function definition(): array
    {
        $name = fake()->unique()->domainWord().'-'.fake()->randomNumber(2, true);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'provider' => 'self-hosted',
            'endpoint_url' => null,
            'connection_type' => HostConnectionType::Agent->value,
            'status' => HostStatus::Pending->value,
            'last_seen_at' => null,
            'cpu_count' => null,
            'memory_total_mb' => null,
            'disk_total_gb' => null,
            'os' => null,
            'docker_version' => null,
            'metadata' => null,
            'archived_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status' => HostStatus::Online->value,
            'last_seen_at' => now(),
            'cpu_count' => 4,
            'memory_total_mb' => 8192,
            'disk_total_gb' => 100,
            'os' => 'Ubuntu 24.04',
            'docker_version' => '26.1.0',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => HostStatus::Archived->value,
            'archived_at' => now(),
        ]);
    }
}
