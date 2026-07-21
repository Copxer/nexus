<?php

namespace Database\Factories;

use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyBriefing> */
class DailyBriefingFactory extends Factory
{
    protected $model = DailyBriefing::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'briefing_date' => today()->subDay()->toDateString(),
            'is_test' => false,
            'status' => DailyBriefingStatus::Pending->value,
            'input_snapshot' => null,
            'summary' => null,
            'highlights' => null,
            'risks' => null,
            'prompt_version' => 'daily-briefing-v1',
            'generated_at' => null,
            'delivered_at' => null,
            'error_message' => null,
        ];
    }

    public function generated(): static
    {
        return $this->state(fn () => [
            'status' => DailyBriefingStatus::Generated->value,
            'input_snapshot' => ['counts' => ['alerts' => 2]],
            'summary' => 'Yesterday was mostly quiet with two alerts to review.',
            'highlights' => ['Two alerts triggered', 'One deployment succeeded'],
            'risks' => ['Billing API health score dropped'],
            'generated_at' => now(),
        ]);
    }
}
