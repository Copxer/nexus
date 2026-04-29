<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\Queries\GetOverviewDashboardQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Overview dashboard. Delegates the read entirely to
 * `GetOverviewDashboardQuery` (roadmap §10.2). The query is responsible
 * for distinguishing real DB-backed slices from clearly-marked mock
 * placeholders.
 */
class OverviewController extends Controller
{
    public function __invoke(GetOverviewDashboardQuery $query): Response
    {
        return Inertia::render('Overview', $query->handle());
    }
}
