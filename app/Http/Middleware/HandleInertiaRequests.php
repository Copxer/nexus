<?php

namespace App\Http\Middleware;

use App\Domain\Activity\Queries\RecentActivityForUserQuery;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Surfaces `->with('status', …)` and `->with('error', …)`
            // flashes from controllers (e.g. the OAuth callback) so the
            // page layout can render a banner. Without this, controllers
            // that flash silently look broken to the user.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Right-rail activity feed (spec 018). Inertia evaluates this
            // closure on every render (it's not a `LazyProp`); the
            // `$request->user()` guard is what spares anonymous requests
            // from running the query. Authenticated pages pay one indexed
            // `whereHas` per render, capped at 20 rows. Spec 019 will move
            // realtime updates onto Reverb; the page-load read stays here.
            'activity' => [
                'recent' => fn () => $request->user()
                    ? app(RecentActivityForUserQuery::class)
                        ->handle($request->user(), RecentActivityForUserQuery::RAIL_LIMIT)
                    : [],
            ],
        ];
    }
}
