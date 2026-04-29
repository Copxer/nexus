<?php

namespace App\Domain\Activity\Queries;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\User;

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
        // TODO(future): broaden the predicate when system-emitted events
        // (deployments, websites, hosts) start landing without a repository.
        // Today every spec-017 webhook event carries a repository_id, so
        // the EXISTS subquery against repositories→projects is watertight
        // and rows with repository_id IS NULL are filtered out for every
        // user — they don't leak across users, but they also don't show.
        return ActivityEvent::query()
            ->with('repository:id,full_name')
            ->whereHas('repository.project', function ($q) use ($user) {
                $q->where('owner_user_id', $user->id);
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityEvent $event) => ActivityEventPresenter::present($event))
            ->all();
    }
}
