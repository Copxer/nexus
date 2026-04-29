<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Queries\WorkItemsForUserQuery;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/work-items` — unified queue across the user's GitHub issues + PRs.
 *
 * Filters live in the query string so the URL is shareable / bookmarkable
 * without any client-side state. Validated here before being passed into
 * `WorkItemsForUserQuery`.
 *
 * Visible scope: phase-1 ties everything to a single owner user, so the
 * page only ever surfaces items from repos under the user's own projects.
 */
class WorkItemController extends Controller
{
    public function __invoke(Request $request, WorkItemsForUserQuery $query): Response
    {
        $validated = $request->validate([
            'kind' => 'sometimes|in:issues,pulls,all',
            'state' => 'sometimes|in:open,closed,merged,all',
            'repository_id' => 'sometimes|nullable|integer',
            'q' => 'sometimes|nullable|string|max:255',
        ]);

        $filters = [
            'kind' => $validated['kind'] ?? 'all',
            'state' => $validated['state'] ?? 'open',
            'repository_id' => $validated['repository_id'] ?? null,
            'q' => $validated['q'] ?? null,
        ];

        $user = $request->user();
        $items = $query->execute($user, $filters);

        // Repositories dropdown — only the ones under the user's own
        // projects (matches the query's visibility rule). Cheap query;
        // displayed inline next to the kind/state filters.
        $repositories = Repository::query()
            ->whereIn(
                'project_id',
                Project::query()->where('owner_user_id', $user->id)->pluck('id'),
            )
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Repository $repo) => [
                'id' => $repo->id,
                'full_name' => $repo->full_name,
            ])
            ->all();

        return Inertia::render('WorkItems/Index', [
            'items' => $items,
            'repositories' => $repositories,
            'filters' => $filters,
        ]);
    }
}
