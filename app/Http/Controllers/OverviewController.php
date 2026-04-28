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

            // Mock activity events covering the §8.10 vocabulary. Pre-formatted
            // `occurred_at` strings keep the UI server-rendered (no client
            // time math). Real events ship with phase 2/3/4 integrations.
            'recentActivity' => [
                [
                    'id' => 'evt-001',
                    'type' => 'deployment.succeeded',
                    'severity' => 'success',
                    'title' => 'Deployed nexus-api v2.14.3 to production',
                    'source' => 'nexus-api',
                    'occurred_at' => '2 min ago',
                    'metadata' => 'us-east',
                ],
                [
                    'id' => 'evt-002',
                    'type' => 'pull_request.merged',
                    'severity' => 'info',
                    'title' => 'PR #142 — Switch session driver to Redis cluster',
                    'source' => 'nexus-web',
                    'occurred_at' => '14 min ago',
                ],
                [
                    'id' => 'evt-003',
                    'type' => 'alert.triggered',
                    'severity' => 'danger',
                    'title' => 'CPU sustained > 90% on prod-api-02 for 5 min',
                    'source' => 'monitoring',
                    'occurred_at' => '38 min ago',
                    'metadata' => 'critical',
                ],
                [
                    'id' => 'evt-004',
                    'type' => 'workflow.failed',
                    'severity' => 'danger',
                    'title' => 'CI failed — flaky test on nexus-mail#main',
                    'source' => 'nexus-mail',
                    'occurred_at' => '52 min ago',
                ],
                [
                    'id' => 'evt-005',
                    'type' => 'pull_request.review_requested',
                    'severity' => 'info',
                    'title' => 'Review requested on PR #218 — Billing webhook hardening',
                    'source' => 'nexus-api',
                    'occurred_at' => '1 h ago',
                ],
                [
                    'id' => 'evt-006',
                    'type' => 'website.recovered',
                    'severity' => 'success',
                    'title' => 'status.nexus.io recovered after 3 m 12 s outage',
                    'source' => 'monitoring',
                    'occurred_at' => '2 h ago',
                ],
                [
                    'id' => 'evt-007',
                    'type' => 'container.unhealthy',
                    'severity' => 'warning',
                    'title' => 'billing-worker container restarted after OOM',
                    'source' => 'prod-api-02',
                    'occurred_at' => '3 h ago',
                ],
                [
                    'id' => 'evt-008',
                    'type' => 'issue.created',
                    'severity' => 'muted',
                    'title' => 'Login flow rejects valid 2FA codes intermittently',
                    'source' => 'nexus-web',
                    'occurred_at' => '4 h ago',
                ],
                [
                    'id' => 'evt-009',
                    'type' => 'host.recovered',
                    'severity' => 'success',
                    'title' => 'edge-eu-01 back online after scheduled maintenance',
                    'source' => 'edge-eu-01',
                    'occurred_at' => '6 h ago',
                ],
            ],

            // 7×6 mock heatmap. Outer index = day-of-week (Sun..Sat), inner =
            // 4-hour bucket (12 AM, 4 AM, 8 AM, 12 PM, 4 PM, 8 PM). Rhythm:
            // quieter overnight + weekends, busier weekday mid-day.
            'activityHeatmap' => [
                [1, 0, 1, 3, 2, 1], // Sun
                [2, 1, 4, 7, 6, 3], // Mon
                [1, 1, 5, 9, 8, 4], // Tue
                [2, 1, 6, 10, 9, 5], // Wed
                [2, 1, 5, 9, 7, 4], // Thu
                [1, 0, 4, 6, 5, 2], // Fri
                [0, 0, 1, 2, 1, 1], // Sat
            ],
        ]);
    }
}
