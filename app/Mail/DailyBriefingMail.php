<?php

namespace App\Mail;

use App\Domain\DailyBriefings\DataTransferObjects\DailyBriefingPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyBriefingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DailyBriefingPayload $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Nexus briefing] '.$this->payload->briefingDate,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-briefing',
            with: ['payload' => $this->payload],
        );
    }
}
