<?php

namespace Database\Factories;

use App\Enums\AlertDeliveryStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertDelivery> */
class AlertDeliveryFactory extends Factory
{
    protected $model = AlertDelivery::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'channel_id' => AlertNotificationChannel::factory(),
            'status' => AlertDeliveryStatus::Pending->value,
            'attempts' => 0,
            'last_attempt_at' => null,
            'sent_at' => null,
            'error_message' => null,
            'payload' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => AlertDeliveryStatus::Sent->value,
            'attempts' => 1,
            'last_attempt_at' => now(),
            'sent_at' => now(),
        ]);
    }

    public function failed(?string $error = null): static
    {
        return $this->state(fn () => [
            'status' => AlertDeliveryStatus::Failed->value,
            'attempts' => 3,
            'last_attempt_at' => now(),
            'error_message' => $error ?? 'HTTP 500 from remote endpoint',
        ]);
    }

    public function skipped(string $reason = 'rate_limited'): static
    {
        return $this->state(fn () => [
            'status' => AlertDeliveryStatus::Skipped->value,
            'error_message' => $reason,
        ]);
    }
}
