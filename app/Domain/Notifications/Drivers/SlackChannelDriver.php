<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\NotificationChannelDriver;
use App\Domain\Notifications\Contracts\NotificationPayload;
use App\Models\AlertNotificationChannel;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class SlackChannelDriver implements NotificationChannelDriver
{
    public function send(AlertNotificationChannel $channel, NotificationPayload $payload): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;

        if (! is_string($webhookUrl) || $webhookUrl === '') {
            throw new InvalidArgumentException(
                "Slack channel {$channel->id} has no `webhook_url` configured.",
            );
        }

        $response = Http::timeout(5)
            ->acceptJson()
            ->asJson()
            ->post($webhookUrl, $payload->toSlackPayload());

        if (! $response->successful()) {
            throw new RuntimeException(
                "Slack webhook responded {$response->status()}: ".$response->body(),
            );
        }
    }
}
