<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\NotificationChannelDriver;
use App\Domain\Notifications\Contracts\NotificationPayload;
use App\Models\AlertNotificationChannel;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class EmailChannelDriver implements NotificationChannelDriver
{
    public function send(AlertNotificationChannel $channel, NotificationPayload $payload): void
    {
        $to = $channel->config['to'] ?? null;

        if (! is_string($to) || $to === '') {
            throw new InvalidArgumentException(
                "Email channel {$channel->id} has no `to` address configured.",
            );
        }

        Mail::to($to)->send($payload->toMail());
    }
}
