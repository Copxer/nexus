<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookDelivery> */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'github_delivery_id' => Str::uuid()->toString(),
            'event' => 'issues',
            'action' => 'opened',
            'repository_full_name' => 'octocat/hello-world',
            'payload_json' => ['action' => 'opened'],
            'signature' => 'sha256='.fake()->sha256(),
            'status' => WebhookDeliveryStatus::Received->value,
            'error_message' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}
