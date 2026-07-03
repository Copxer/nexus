<?php

namespace App\Domain\PublicStatus\Listeners;

use App\Domain\PublicStatus\Queries\GetPublicStatusPageQuery;
use App\Events\AlertResolved;
use App\Events\AlertTriggered;
use App\Models\Alert;
use Illuminate\Support\Facades\Cache;

/**
 * Spec 047 — flush the cached public status snapshot on alert
 * transitions so the next public visitor sees fresh data.
 * `WebsiteCheckRecorded` was considered but firing this every 60s
 * for every monitor would nullify the cache TTL entirely; incident
 * transitions are the visible-to-viewers events worth invalidating on.
 */
class InvalidatePublicStatusCacheListener
{
    public function handle(AlertTriggered|AlertResolved $event): void
    {
        $projectId = Alert::query()
            ->where('id', $event->alertId)
            ->value('project_id');

        if ($projectId === null) {
            return;
        }

        Cache::forget(GetPublicStatusPageQuery::cacheKey($projectId));
    }
}
