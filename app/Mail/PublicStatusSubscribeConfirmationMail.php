<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class PublicStatusSubscribeConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly PublicStatusSubscriber $subscriber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirm your subscription to {$this->project->name} status",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.public-status.subscribe-confirmation',
            with: [
                'projectName' => $this->project->name,
                'confirmUrl' => URL::to(
                    "/status/{$this->project->slug}/confirm/{$this->subscriber->confirmation_token}",
                ),
                'statusUrl' => URL::to("/status/{$this->project->slug}"),
            ],
        );
    }
}
