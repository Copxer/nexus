<?php

namespace App\Domain\Activity;

use App\Models\ActivityEvent;
use BackedEnum;

/**
 * Single source of truth for mapping an `ActivityEvent` row to the
 * JSON shape the frontend expects (`resources/js/types/index.d.ts`
 * `ActivityEvent` interface).
 *
 * Used by:
 *   - `RecentActivityForUserQuery` (page-load reads, including the shared
 *     `activity.recent` Inertia prop the AppLayout rail consumes).
 *   - `App\Events\ActivityEventCreated::broadcastWith()` (real-time).
 *
 * Keeping the mapping here avoids the call-site drift the spec-018
 * code-reviewer flagged.
 */
class ActivityEventPresenter
{
    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     severity: string,
     *     title: string,
     *     source: string,
     *     occurred_at: string,
     *     metadata?: string,
     * }
     */
    public static function present(ActivityEvent $event): array
    {
        $payload = [
            'id' => 'evt-'.$event->id,
            'type' => $event->event_type,
            'severity' => $event->severity instanceof BackedEnum
                ? $event->severity->value
                : (string) $event->severity,
            'title' => $event->title,
            // Prefer the linked repository's full_name (e.g.
            // `nexus-org/nexus-web`) since the rail item uses it as the
            // small "from" label. Fall back to the row's `source` column
            // (`github`/`docker`/...) for system-emitted events.
            'source' => $event->repository?->full_name ?? $event->source,
            'occurred_at' => $event->occurred_at?->diffForHumans() ?? '',
        ];

        // Surface a single human-readable metadata pill if the row has
        // one. We pick the first known key so the renderer never sees a
        // raw JSON blob.
        $metadata = $event->metadata ?? [];
        foreach (['region', 'actor_login', 'environment'] as $key) {
            if (! empty($metadata[$key])) {
                $payload['metadata'] = (string) $metadata[$key];
                break;
            }
        }

        return $payload;
    }
}
