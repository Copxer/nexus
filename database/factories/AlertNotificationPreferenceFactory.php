<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertNotificationPreference> */
class AlertNotificationPreferenceFactory extends Factory
{
    protected $model = AlertNotificationPreference::class;

    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'channel_id' => AlertNotificationChannel::factory()->for($user),
            'min_severity' => AlertSeverity::Warning->value,
            'sources' => null,
            'enabled' => true,
            'notify_on_resolve' => false,
            'rate_limit_per_hour' => null,
        ];
    }

    public function criticalOnly(): static
    {
        return $this->state(fn () => [
            'min_severity' => AlertSeverity::Critical->value,
            'notify_on_resolve' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'enabled' => false,
        ]);
    }
}
