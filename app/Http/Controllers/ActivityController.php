<?php

namespace App\Http\Controllers;

use App\Domain\Activity\Queries\RecentActivityForUserQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dedicated activity feed page (`/activity`). Renders the same
 * `<ActivityFeed>` component used by the AppLayout right rail, but with
 * a wider cap (last 100 events). Real-time arrives in spec 019; this
 * spec ships page-load fresh.
 */
class ActivityController extends Controller
{
    public function index(
        Request $request,
        RecentActivityForUserQuery $query,
    ): Response {
        $events = $query->handle(
            $request->user(),
            RecentActivityForUserQuery::PAGE_LIMIT,
        );

        return Inertia::render('Activity/Index', [
            'events' => $events,
        ]);
    }
}
