<?php

namespace Database\Factories;

use App\Enums\NotificationChannelKind;
use App\Models\AlertNotificationChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertNotificationChannel> */
class AlertNotificationChannelFactory extends Factory
{
    protected $model = AlertNotificationChannel::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => NotificationChannelKind::Email->value,
            'name' => 'Ops email',
            'config' => ['to' => 'ops@example.com'],
            'enabled' => true,
            'verified_at' => now(),
        ];
    }

    public function email(?string $to = null): static
    {
        return $this->state(fn () => [
            'kind' => NotificationChannelKind::Email->value,
            'name' => 'Ops email',
            'config' => ['to' => $to ?? 'ops@example.com'],
        ]);
    }

    public function slack(?string $webhookUrl = null): static
    {
        return $this->state(fn () => [
            'kind' => NotificationChannelKind::Slack->value,
            'name' => 'Ops Slack',
            'config' => [
                'webhook_url' => $webhookUrl ?? 'https://hooks.slack.com/services/T00/B00/XXX',
            ],
        ]);
    }

    public function webhook(?string $url = null, ?string $signingSecret = null): static
    {
        return $this->state(fn () => [
            'kind' => NotificationChannelKind::Webhook->value,
            'name' => 'PagerDuty bridge',
            'config' => array_filter([
                'url' => $url ?? 'https://ops.example.com/nexus-hook',
                'signing_secret' => $signingSecret,
            ]),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'enabled' => false,
        ]);
    }
}
