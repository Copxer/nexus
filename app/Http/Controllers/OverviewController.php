<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\Queries\GetOverviewDashboardQuery;
use App\Domain\GitHub\Queries\WorkItemsForUserQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Overview dashboard. Mostly delegates the read to
 * `GetOverviewDashboardQuery` (roadmap §10.2) — that query now accepts
 * the authenticated user so the riskyProjects slice (spec 035) can
 * scope to owned projects. The remaining KPI slices (projects /
 * hosts / deployments / topRepositories / activity heatmap) stay
 * single-tenant until a dedicated tenancy spec.
 *
 * The Issues & PRs widget is the second user-scoped slice on this
 * page — `WorkItemsForUserQuery` lives in a different domain so we
 * run it here and merge the result.
 */
class OverviewController extends Controller
{
    /**
     * Cap for the Issues & PRs widget — keeps the card visually
     * consistent with the other 4-row stubs around it.
     */
    private const TOP_WORK_ITEMS_LIMIT = 4;

    public function __invoke(
        Request $request,
        GetOverviewDashboardQuery $query,
        WorkItemsForUserQuery $workItemsQuery,
    ): Response {
        $payload = $query->handle($request->user());

        // Spec 016 shipped the work-items query; this surfaces the top
        // N open items on the Overview's Issues & PRs widget so the
        // card reflects real activity instead of fixture data.
        $topWorkItems = array_slice(
            $workItemsQuery->execute($request->user(), [
                'kind' => 'all',
                'state' => 'open',
            ]),
            0,
            self::TOP_WORK_ITEMS_LIMIT,
        );

        return Inertia::render('Overview', array_merge($payload, [
            'topWorkItems' => $topWorkItems,
            'canRegenerateProjectHealthExplanation' => config('services.llm.enabled', false),
        ]));
    }
}
