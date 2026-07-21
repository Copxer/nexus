<?php

namespace Database\Factories;

use App\Models\DailyBriefingPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyBriefingPreference> */
class DailyBriefingPreferenceFactory extends Factory
{
    protected $model = DailyBriefingPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'enabled' => false,
            'delivery_time' => '08:00:00',
            'timezone' => config('app.timezone', 'UTC'),
            'channel_id' => null,
            'include_projects' => null,
            'last_sent_for_date' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => [
            'enabled' => true,
        ]);
    }
}
