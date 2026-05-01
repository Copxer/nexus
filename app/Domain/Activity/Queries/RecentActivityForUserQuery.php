<?php

namespace App\Domain\Activity\Queries;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Models\Website;

/**
 * Read-side query for the activity feed shown in the AppLayout right rail
 * and on the dedicated `/activity` page (spec 018).
 *
 * Returns events scoped to repositories owned by the user's projects —
 * cross-user isolation today is single-tenant (one user) but the query is
 * written so multi-tenant scoping (spec ???) only changes the inner
 * `whereHas` predicate.
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
        // Two scoping paths land in the same feed:
        //   1. Repository-scoped events (spec 017's webhook handlers
        //      and deployments — `repository_id` resolves through the
        //      project's owner).
        //   2. Monitoring-scoped events (spec 024 — `source: monitoring`,
        //      `metadata.website_id` resolves through the website's
        //      project's owner). These rows have `repository_id` null.
        //
        // The user's website ids are pre-resolved into a list once so
        // the JSON predicate stays cheap (no JSON join per row); cross-
        // DB JSON-extract syntax is the same shape on MySQL and SQLite.
        $userWebsiteIds = Website::query()
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->pluck('id')
            ->all();

        return ActivityEvent::query()
            ->with('repository:id,full_name')
            ->where(function ($q) use ($user, $userWebsiteIds) {
                $q->whereHas('repository.project', function ($inner) use ($user) {
                    $inner->where('owner_user_id', $user->id);
                });

                if (! empty($userWebsiteIds)) {
                    $q->orWhere(function ($inner) use ($userWebsiteIds) {
                        $inner->where('source', 'monitoring')
                            ->whereIn('metadata->website_id', $userWebsiteIds);
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
