<?php

namespace App\Domain\Notifications\Contracts;

use Illuminate\Mail\Mailable;

interface NotificationPayload
{
    public function title(): string;

    public function message(): ?string;

    public function link(): string;

    public function event(): string;

    public function toMail(): Mailable;

    /** @return array<string, mixed> */
    public function toSlackPayload(): array;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
