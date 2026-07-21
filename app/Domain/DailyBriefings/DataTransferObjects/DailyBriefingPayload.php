<?php

namespace App\Domain\DailyBriefings\DataTransferObjects;

use App\Domain\Notifications\Contracts\NotificationPayload;
use App\Mail\DailyBriefingMail;
use App\Models\DailyBriefing;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

final class DailyBriefingPayload implements NotificationPayload
{
    /**
     * @param  array<int, string>  $highlights
     * @param  array<int, string>  $risks
     */
    public function __construct(
        public readonly int $briefingId,
        public readonly string $briefingDate,
        public readonly string $summary,
        public readonly array $highlights,
        public readonly array $risks,
        public readonly string $link,
    ) {}

    public static function fromBriefing(DailyBriefing $briefing): self
    {
        return new self(
            briefingId: $briefing->id,
            briefingDate: $briefing->briefing_date->toDateString(),
            summary: $briefing->summary ?? '',
            highlights: $briefing->highlights ?? [],
            risks: $briefing->risks ?? [],
            link: URL::route('daily-briefings.index'),
        );
    }

    public function title(): string
    {
        return 'Daily briefing for '.$this->briefingDate;
    }

    public function message(): ?string
    {
        return $this->summary;
    }

    public function link(): string
    {
        return $this->link;
    }

    public function event(): string
    {
        return 'daily_briefing.generated';
    }

    public function toMail(): Mailable
    {
        return new DailyBriefingMail($this);
    }

    /** @return array<string, mixed> */
    public function toSlackPayload(): array
    {
        return [
            'text' => $this->title(),
            'blocks' => array_values(array_filter([
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $this->title(),
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $this->summary,
                    ],
                ],
                $this->highlights === [] ? null : [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Highlights*\n".$this->bullets($this->highlights),
                    ],
                ],
                $this->risks === [] ? null : [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Risks*\n".$this->bullets($this->risks),
                    ],
                ],
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
            ])),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event(),
            'briefing_id' => $this->briefingId,
            'briefing_date' => $this->briefingDate,
            'title' => $this->title(),
            'summary' => $this->summary,
            'highlights' => $this->highlights,
            'risks' => $this->risks,
            'link' => $this->link,
        ];
    }

    /** @param array<int, string> $items */
    private function bullets(array $items): string
    {
        return collect($items)
            ->map(fn (string $item): string => '- '.$item)
            ->implode("\n");
    }
}
