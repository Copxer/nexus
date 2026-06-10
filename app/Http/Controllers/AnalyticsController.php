<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Queries\GetAlertMetricsQuery;
use App\Domain\Analytics\Queries\GetContainerResourceUsageQuery;
use App\Domain\Analytics\Queries\GetDeploymentMetricsQuery;
use App\Domain\Analytics\Queries\GetWebsiteMetricsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/analytics` (spec 034). Single-action invokable.
 *
 * The page is a snapshot view, not realtime — the user refreshes (or
 * changes the range) to see updated aggregates. No Echo subscription
 * intentional: live-pulse on multi-day aggregates churns for no UX
 * value. The `?range=7d|30d|90d` filter is the only query param; it
 * lives in the URL so the active selection survives a refresh and a
 * direct link. Default is 30d.
 *
 * Each Query class scopes strictly by `$user->id`. Nothing on this
 * page falls back to a global-scoped read — cross-tenant isolation is
 * a Phase 8 acceptance criterion.
 */
class AnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        GetDeploymentMetricsQuery $deployments,
        GetAlertMetricsQuery $alerts,
        GetWebsiteMetricsQuery $websites,
        GetContainerResourceUsageQuery $containers,
    ): Response {
        $validated = $request->validate([
            'range' => 'sometimes|in:7d,30d,90d',
        ]);
        $range = $validated['range'] ?? '30d';
        $user = $request->user();

        $from = $this->fromForRange($range);

        return Inertia::render('Analytics/Index', [
            'filters' => ['range' => $range],
            'metrics' => [
                'deployments' => $deployments->execute($user, $from),
                'alerts' => $alerts->execute($user, $from),
                'websites' => $websites->execute($user, $from),
                'containers' => $containers->execute($user, $from),
            ],
        ]);
    }

    private function fromForRange(string $range): Carbon
    {
        $days = match ($range) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
        };

        return now()->startOfDay()->subDays($days - 1);
    }
}
