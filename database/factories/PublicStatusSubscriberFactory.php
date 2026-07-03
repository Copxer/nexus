<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicStatusSubscriber> */
class PublicStatusSubscriberFactory extends Factory
{
    protected $model = PublicStatusSubscriber::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'email' => fake()->unique()->safeEmail(),
            'confirmation_token' => PublicStatusSubscriber::freshToken(),
            'unsubscribe_token' => PublicStatusSubscriber::freshToken(),
            'confirmed_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['confirmed_at' => now()]);
    }
}
