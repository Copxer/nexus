<?php

namespace App\Mail;

use App\Domain\Notifications\DataTransferObjects\AlertNotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly AlertNotificationPayload $payload) {}

    public function envelope(): Envelope
    {
        $prefix = $this->payload->event === 'alert.resolved'
            ? '[Nexus resolved]'
            : '[Nexus '.$this->payload->severity.']';

        return new Envelope(
            subject: $prefix.' '.$this->payload->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alert-notification',
            with: ['payload' => $this->payload],
        );
    }
}
