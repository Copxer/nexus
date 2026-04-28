<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    /**
     * Render the Overview dashboard with mock KPI data.
     *
     * Shape mirrors roadmap §8.1.1 with two phase-0 additions per card:
     *   - `sparkline`: 12-point series the frontend renders inline.
     *   - `status`:    one of success | warning | danger | info — drives the badge tone.
     *
     * Values are intentionally hardcoded; the data contract is the
     * point of this spec, not the wiring.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Overview', [
            'dashboard' => [
                'projects' => [
                    'active' => 12,
                    'new_this_week' => 2,
                    'sparkline' => [4, 5, 6, 6, 7, 8, 9, 10, 11, 11, 12, 12],
                    'status' => 'success',
                ],
                'deployments' => [
                    'successful_24h' => 24,
                    'change_percent' => 18,
                    'sparkline' => [12, 14, 13, 16, 18, 20, 19, 22, 21, 23, 24, 24],
                    'status' => 'success',
                ],
                'services' => [
                    'running' => 47,
                    'health_percent' => 100,
                    'sparkline' => [44, 45, 45, 46, 46, 47, 47, 47, 47, 47, 47, 47],
                    'status' => 'success',
                ],
                'hosts' => [
                    'online' => 128,
                    'new' => 4,
                    'sparkline' => [120, 121, 122, 123, 124, 124, 125, 126, 126, 127, 128, 128],
                    'status' => 'info',
                ],
                'alerts' => [
                    'active' => 3,
                    'critical' => 1,
                    'sparkline' => [1, 0, 1, 2, 1, 1, 2, 2, 3, 2, 3, 3],
                    'status' => 'danger',
                ],
                'uptime' => [
                    'overall' => 99.98,
                    'change' => 0.01,
                    'sparkline' => [99.92, 99.93, 99.95, 99.94, 99.96, 99.97, 99.96, 99.97, 99.98, 99.98, 99.97, 99.98],
                    'status' => 'success',
                ],
            ],
        ]);
    }
}
