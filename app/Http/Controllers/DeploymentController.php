<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Queries\DeploymentTimelineQuery;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/deployments` — cross-repo workflow run timeline (spec 021).
 *
 * Filters live in the query string so a filtered view is shareable
 * and survives reload. Validated here before being passed into
 * `DeploymentTimelineQuery`. Status / conclusion enums are validated
 * via `Rule::in($enum::values())` so adding a new case to the PHP
 * enum is the only edit required.
 *
 * Visible scope: phase-1 ties everything to a single owner user.
 * Repos under the user's own projects are the only ones surfaced.
 */
class DeploymentController extends Controller
{
    public function index(Request $request, DeploymentTimelineQuery $query): Response
    {
        $user = $request->user();
        $statusValues = array_map(fn (WorkflowRunStatus $s) => $s->value, WorkflowRunStatus::cases());
        $conclusionValues = array_map(fn (WorkflowRunConclusion $c) => $c->value, WorkflowRunConclusion::cases());

        $validated = $request->validate([
            // Existence checks scope to the user's own projects/repos —
            // foreign ids 422 instead of silently returning empty rows.
            // No exposure to non-owners that way.
            'project_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('projects', 'id')->where('owner_user_id', $user->id),
            ],
            'repository_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('repositories', 'id')->whereIn(
                    'project_id',
                    Project::query()->where('owner_user_id', $user->id)->pluck('id'),
                ),
            ],
            'status' => 'sometimes|nullable|in:'.implode(',', $statusValues),
            'conclusion' => 'sometimes|nullable|in:'.implode(',', $conclusionValues),
            'branch' => 'sometimes|nullable|string|max:255',
        ]);

        $filters = [
            'project_id' => $validated['project_id'] ?? null,
            'repository_id' => $validated['repository_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'conclusion' => $validated['conclusion'] ?? null,
            'branch' => $validated['branch'] ?? null,
        ];

        $deployments = $query->execute($user, $filters);

        // Filter dropdowns. Pulled inline (cheap) rather than via a
        // shared Inertia prop because this page is the only consumer
        // and the shape (project_id parent → repository child) is
        // page-specific.
        $projects = Project::query()
            ->where('owner_user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'color' => $project->color,
            ])
            ->all();

        $repositories = Repository::query()
            ->whereIn(
                'project_id',
                Project::query()->where('owner_user_id', $user->id)->pluck('id'),
            )
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'project_id'])
            ->map(fn (Repository $repo) => [
                'id' => $repo->id,
                'full_name' => $repo->full_name,
                'project_id' => $repo->project_id,
            ])
            ->all();

        return Inertia::render('Deployments/Index', [
            'deployments' => $deployments,
            'filters' => $filters,
            'filterOptions' => [
                'projects' => $projects,
                'repositories' => $repositories,
            ],
        ]);
    }
}
