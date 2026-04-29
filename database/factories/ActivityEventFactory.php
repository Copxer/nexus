<?php

namespace Database\Factories;

use App\Enums\ActivitySeverity;
use App\Models\ActivityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityEvent> */
class ActivityEventFactory extends Factory
{
    protected $model = ActivityEvent::class;

    public function definition(): array
    {
        return [
            'repository_id' => null,
            'actor_login' => fake()->userName(),
            'source' => 'github',
            'event_type' => 'issue.created',
            'severity' => ActivitySeverity::Info->value,
            'title' => fake()->sentence(6),
            'description' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }
}
