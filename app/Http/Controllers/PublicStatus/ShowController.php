<?php

namespace App\Http\Controllers\PublicStatus;

use App\Domain\PublicStatus\Queries\GetPublicStatusPageQuery;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec 047 — public `/status/{project:slug}` page. Unauthenticated,
 * 404 for projects that haven't flipped the opt-in switch.
 *
 * Cached via `GetPublicStatusPageQuery` (60s TTL, invalidated on
 * alert transitions via listener).
 */
class ShowController extends Controller
{
    public function __invoke(Project $project, GetPublicStatusPageQuery $query): Response
    {
        abort_unless($project->public_status_enabled, 404);

        return Inertia::render('Status/Show', [
            'status' => $query->execute($project)->toArray(),
        ]);
    }
}
