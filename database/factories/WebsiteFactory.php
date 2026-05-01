<?php

namespace Database\Factories;

use App\Enums\WebsiteStatus;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'method' => 'GET',
            'expected_status_code' => 200,
            'timeout_ms' => 10_000,
            'check_interval_seconds' => 300,
            'status' => WebsiteStatus::Pending->value,
            'last_checked_at' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
        ];
    }
}
