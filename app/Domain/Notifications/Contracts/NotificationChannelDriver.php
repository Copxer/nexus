<?php

namespace App\Domain\Notifications\Contracts;

use App\Models\AlertNotificationChannel;

/**
 * §6.5 Strategy pattern — one implementation per channel kind.
 *
 * Drivers throw on failure so the caller (`DispatchAlertNotificationJob`)
 * can wrap the exception in the retry / dead-letter loop. Return type
 * is void; drivers surface no delivery id — the observable outcome is
 * the `AlertDelivery` row the job persists after the driver returns.
 */
interface NotificationChannelDriver
{
    /**
     * @throws \Throwable when the channel refuses / errors out.
     */
    public function send(AlertNotificationChannel $channel, NotificationPayload $payload): void;
}
