<?php

namespace App\Domain\Activity\Actions;

use App\Enums\ActivitySeverity;
use App\Models\ActivityEvent;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Single entry point for inserting an `activity_events` row. All
 * webhook handlers (and, eventually, non-webhook origins like deploy
 * pipelines or alerts) funnel through here so spec 019 can hook
 * broadcasting once and have it cover every source.
 *
 * Mandatory fields: `event_type`, `title`, `occurred_at`. Everything
 * else is optional.
 */
class CreateActivityEventAction
{
    /**
     * @param  array{
     *     event_type: string,
     *     title: string,
     *     occurred_at: Carbon,
     *     repository_id?: int|null,
     *     actor_login?: string|null,
     *     source?: string,
     *     severity?: ActivitySeverity|string,
     *     description?: string|null,
     *     metadata?: array<string, mixed>,
     * }  $attrs
     */
    public function execute(array $attrs): ActivityEvent
    {
        $eventType = trim($attrs['event_type'] ?? '');
        $title = trim($attrs['title'] ?? '');
        $occurredAt = $attrs['occurred_at'] ?? null;

        if ($eventType === '') {
            throw new InvalidArgumentException('event_type is required.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('title is required.');
        }
        if (! $occurredAt instanceof Carbon) {
            throw new InvalidArgumentException('occurred_at must be a Carbon instance.');
        }

        $severity = $attrs['severity'] ?? ActivitySeverity::Info;
        if ($severity instanceof ActivitySeverity) {
            $severity = $severity->value;
        }

        return ActivityEvent::query()->create([
            'repository_id' => $attrs['repository_id'] ?? null,
            'actor_login' => $attrs['actor_login'] ?? null,
            'source' => $attrs['source'] ?? 'github',
            'event_type' => $eventType,
            'severity' => $severity,
            'title' => $title,
            'description' => $attrs['description'] ?? null,
            'metadata' => $attrs['metadata'] ?? [],
            'occurred_at' => $occurredAt,
        ]);
    }
}
