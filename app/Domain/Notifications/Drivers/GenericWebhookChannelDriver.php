<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\NotificationChannelDriver;
use App\Domain\Notifications\Contracts\NotificationPayload;
use App\Models\AlertNotificationChannel;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class GenericWebhookChannelDriver implements NotificationChannelDriver
{
    public function send(AlertNotificationChannel $channel, NotificationPayload $payload): void
    {
        $url = $channel->config['url'] ?? null;
        $signingSecret = $channel->config['signing_secret'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException(
                "Webhook channel {$channel->id} has no `url` configured.",
            );
        }

        $body = json_encode($payload->toArray(), JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException(
                "Failed to encode payload for webhook channel {$channel->id}.",
            );
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Nexus-Control-Center/1.0',
        ];

        if (is_string($signingSecret) && $signingSecret !== '') {
            $headers['X-Nexus-Signature'] = 'sha256='.hash_hmac('sha256', $body, $signingSecret);
        }

        $response = Http::timeout(5)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Webhook responded {$response->status()}: ".$response->body(),
            );
        }
    }
}
