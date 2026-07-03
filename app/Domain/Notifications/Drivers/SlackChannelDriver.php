<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\NotificationChannelDriver;
use App\Domain\Notifications\DataTransferObjects\AlertNotificationPayload;
use App\Models\AlertNotificationChannel;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class SlackChannelDriver implements NotificationChannelDriver
{
    public function send(AlertNotificationChannel $channel, AlertNotificationPayload $payload): void
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
            ->post($webhookUrl, $this->buildBlockKitPayload($payload));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Slack webhook responded {$response->status()}: ".$response->body(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlockKitPayload(AlertNotificationPayload $payload): array
    {
        $emoji = match ($payload->severity) {
            'critical' => ':rotating_light:',
            'warning' => ':warning:',
            default => ':information_source:',
        };

        $header = $payload->event === 'alert.resolved'
            ? ":white_check_mark: {$payload->title} resolved"
            : "{$emoji} {$payload->title}";

        return [
            // Slack shows this in notifications / previews when Block Kit isn't renderable.
            'text' => $header,
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $header,
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => array_filter([
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Severity*\n".ucfirst($payload->severity),
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Source*\n".ucfirst($payload->source),
                        ],
                    ]),
                ],
                ...($payload->message !== null && $payload->message !== '' ? [[
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $payload->message,
                    ],
                ]] : []),
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'View in Nexus',
                                'emoji' => true,
                            ],
                            'url' => $payload->link,
                        ],
                    ],
                ],
            ],
        ];
    }
}
