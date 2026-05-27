<?php

namespace App\Domain\Activity\Queries;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\User;
use App\Models\Website;

/**
 * Read-side query for the activity feed shown in the AppLayout right rail
 * and on the dedicated `/activity` page (spec 018).
 *
 * Returns events scoped to the user's owned domain — across four sources:
 *   1. Repository-scoped events (spec 017 webhooks, spec 020 deployments)
 *      via `repository → project → owner_user_id`.
 *   2. Monitoring-scoped events (spec 024 — `source: monitoring`,
 *      `metadata.website_id`) via the user's websites.
 *   3. Hosts-scoped events (spec 029 — `source: hosts`,
 *      `metadata.host_id`) via the user's hosts.
 *   4. Alerts-scoped events (spec 030 — `source: alerts`,
 *      `metadata.alert_id`) via the user's alerts.
 *
 * Each non-repo branch pre-resolves the relevant id list so the JSON
 * predicate stays cheap (no JSON join per row); cross-DB JSON-extract
 * syntax matches on MySQL and SQLite.
 *
 * Output shape matches the existing TS `ActivityEvent` type
 * (`resources/js/types/index.d.ts`) so the same `ActivityFeedItem.vue`
 * component renders both this real data and the spec-007 mock fixtures
 * still used elsewhere on the Overview page.
 */
class RecentActivityForUserQuery
{
    /**
     * Default cap for the right-rail feed (one shared Inertia prop).
     */
    public const RAIL_LIMIT = 20;

    /**
     * Default cap for the dedicated `/activity` page.
     */
    public const PAGE_LIMIT = 100;

    /**
     * @return array<int, array{
     *     id: string,
     *     type: string,
     *     severity: string,
     *     title: string,
     *     source: string,
     *     occurred_at: string,
     *     metadata?: string,
     * }>
     */
    public function handle(User $user, int $limit = self::RAIL_LIMIT): array
    {
        $userWebsiteIds = Website::query()
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->pluck('id')
            ->all();

        $userHostIds = Host::query()
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->pluck('id')
            ->all();

        $userAlertIds = Alert::query()
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->pluck('id')
            ->all();

        return ActivityEvent::query()
            ->with('repository:id,full_name')
            ->where(function ($q) use ($user, $userWebsiteIds, $userHostIds, $userAlertIds) {
                $q->whereHas('repository.project', function ($inner) use ($user) {
                    $inner->where('owner_user_id', $user->id);
                });

                if (! empty($userWebsiteIds)) {
                    $q->orWhere(function ($inner) use ($userWebsiteIds) {
                        $inner->where('source', 'monitoring')
                            ->whereIn('metadata->website_id', $userWebsiteIds);
                    });
                }

                if (! empty($userHostIds)) {
                    $q->orWhere(function ($inner) use ($userHostIds) {
                        $inner->where('source', 'hosts')
                            ->whereIn('metadata->host_id', $userHostIds);
                    });
                }

                if (! empty($userAlertIds)) {
                    $q->orWhere(function ($inner) use ($userAlertIds) {
                        $inner->where('source', 'alerts')
                            ->whereIn('metadata->alert_id', $userAlertIds);
                    });
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityEvent $event) => ActivityEventPresenter::present($event))
            ->all();
    }
}
