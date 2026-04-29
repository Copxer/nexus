<?php

namespace App\Domain\Activity\Actions;

use App\Enums\ActivitySeverity;
use App\Events\ActivityEventCreated;
use App\Models\ActivityEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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

        $activityEvent = ActivityEvent::query()->create([
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

        // Spec 019 — fan out to subscribers via Reverb. The event resolves
        // its broadcast channel from the row's repository → project →
        // owner_user_id. System-emitted events (no repository) no-op the
        // broadcast.
        //
        // The dispatch is wrapped: `ShouldBroadcastNow` runs synchronously
        // through the broadcaster, so a Reverb outage would otherwise throw
        // out of the webhook handler and trigger a job retry — which would
        // re-insert the row (we have no idempotency key). Broadcasts are
        // best-effort; the page-load read in spec 018 covers the gap until
        // the next event.
        try {
            ActivityEventCreated::dispatch($activityEvent);
        } catch (Throwable $e) {
            Log::warning('ActivityEventCreated broadcast failed', [
                'activity_event_id' => $activityEvent->id,
                'event_type' => $activityEvent->event_type,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $activityEvent;
    }
}
