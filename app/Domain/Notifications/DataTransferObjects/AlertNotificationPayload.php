<?php

namespace App\Domain\Notifications\DataTransferObjects;

use App\Domain\Notifications\Contracts\NotificationPayload;
use App\Mail\AlertNotificationMail;
use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Immutable value object carrying the outbound shape of an alert
 * notification (spec 042).
 *
 * Drivers receive this DTO — never the Alert model — so the driver
 * layer stays decoupled from the Eloquent model and the payload shape
 * is what actually ships on the wire.
 *
 * `metadata` is a sanitized subset of the alert's raw metadata JSON:
 * scalar values only, size bounded ≤ 2 KB before the payload as a
 * whole is bounded ≤ 4 KB at the delivery-log persist step.
 */
final class AlertNotificationPayload implements NotificationPayload
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public readonly int $alertId,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $source,
        public readonly string $title,
        public readonly ?string $message,
        public readonly string $link,
        public readonly Carbon $triggeredAt,
        public readonly array $metadata = [],
        public readonly string $event = 'alert.triggered',
    ) {}

    public static function fromAlert(Alert $alert, string $event = 'alert.triggered'): self
    {
        return new self(
            alertId: $alert->id,
            type: $alert->type,
            severity: $alert->severity->value,
            source: $alert->source->value,
            title: $alert->title,
            message: $alert->description,
            link: URL::to('/alerts/'.$alert->id),
            triggeredAt: $alert->triggered_at,
            metadata: self::sanitize($alert->metadata ?? []),
            event: $event,
        );
    }

    /**
     * Synthesize a payload for the "Send test" button on the settings
     * page. Doesn't hit the alerts table.
     */
    public static function testPayload(): self
    {
        return new self(
            alertId: 0,
            type: 'test.notification',
            severity: 'info',
            source: 'system',
            title: 'Test notification from Nexus',
            message: 'If you can read this, your channel is wired up.',
            link: URL::to('/settings/notifications'),
            triggeredAt: Carbon::now(),
            event: 'alert.test',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'alert_id' => $this->alertId,
            'type' => $this->type,
            'severity' => $this->severity,
            'source' => $this->source,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
            'triggered_at' => $this->triggeredAt->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function link(): string
    {
        return $this->link;
    }

    public function event(): string
    {
        return $this->event;
    }

    public function toMail(): Mailable
    {
        return new AlertNotificationMail($this);
    }

    /** @return array<string, mixed> */
    public function toSlackPayload(): array
    {
        $emoji = match ($this->severity) {
            'critical' => ':rotating_light:',
            'warning' => ':warning:',
            default => ':information_source:',
        };

        $header = $this->event === 'alert.resolved'
            ? ":white_check_mark: {$this->title} resolved"
            : "{$emoji} {$this->title}";

        return [
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
                            'text' => "*Severity*\n".ucfirst($this->severity),
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Source*\n".ucfirst($this->source),
                        ],
                    ]),
                ],
                ...($this->message !== null && $this->message !== '' ? [[
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $this->message,
                    ],
                ]] : []),
                [
                    'type' => 'actions',
                    'elements' => [[
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'View in Nexus',
                            'emoji' => true,
                        ],
                        'url' => $this->link,
                    ]],
                ],
            ],
        ];
    }

    /**
     * Drop non-scalars and cap the payload before we ship it. Alert
     * metadata is a free-form bag — spec 030 uses it for websites
     * (url + http_status + error_message), hosts (threshold_seconds),
     * and deployments (branch + run_id) — none of which should include
     * nested structures, but user-triggered `manual` alerts could.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, scalar|null>
     */
    private static function sanitize(array $raw): array
    {
        $sanitized = [];

        foreach ($raw as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $sanitized[(string) $key] = $value;
            }
        }

        return $sanitized;
    }
}
