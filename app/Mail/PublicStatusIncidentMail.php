<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class PublicStatusIncidentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly PublicStatusSubscriber $subscriber,
        public readonly Alert $alert,
        public readonly string $event, // 'triggered' | 'resolved'
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->event === 'resolved'
            ? "[{$this->project->name} · resolved]"
            : "[{$this->project->name} · ".ucfirst($this->alert->severity->value).']';

        return new Envelope(
            subject: $prefix.' '.$this->alert->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.public-status.incident',
            with: [
                'projectName' => $this->project->name,
                'event' => $this->event,
                'alertTitle' => $this->alert->title,
                'alertSeverity' => $this->alert->severity->value,
                'alertDescription' => $this->alert->description,
                'triggeredAt' => $this->alert->triggered_at,
                'resolvedAt' => $this->alert->resolved_at,
                'statusUrl' => URL::to("/status/{$this->project->slug}"),
                'unsubscribeUrl' => URL::to(
                    "/status/subscribers/unsubscribe/{$this->subscriber->unsubscribe_token}",
                ),
            ],
        );
    }
}
