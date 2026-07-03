<?php

namespace App\Enums;

/**
 * Delivery channel kind (spec 042).
 *
 * `email`   — Laravel Mail via the configured MAIL_* driver.
 * `slack`   — HTTP POST to an incoming-webhook URL, Block Kit payload.
 * `webhook` — Generic HTTP POST of the AlertNotificationPayload DTO,
 *             optionally signed via HMAC-SHA-256 when the channel's
 *             config carries a `signing_secret`.
 */
enum NotificationChannelKind: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
            self::Webhook => 'Generic webhook',
        };
    }
}
