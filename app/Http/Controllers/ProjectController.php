<?php

namespace App\Http\Controllers;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->with('owner:id,name,email')
            ->orderByRaw('last_activity_at IS NULL')
            ->latest('last_activity_at')
            ->latest()
            ->get()
            ->map(fn (Project $project) => $this->transform($project));

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('Projects/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = new Project($request->validated());
        $project->owner_user_id = $request->user()->id;
        $project->last_activity_at = now();
        $project->save();

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Project created.');
    }

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $project->loadMissing('owner:id,name,email');

        return Inertia::render('Projects/Show', [
            'project' => $this->transform($project),
            'canUpdate' => request()->user()?->can('update', $project) ?? false,
            'canDelete' => request()->user()?->can('delete', $project) ?? false,
        ]);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Edit', [
            'project' => $this->transform($project),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->fill($request->validated());
        $project->last_activity_at = now();
        $project->save();

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('status', 'Project deleted.');
    }

    /**
     * Project shape returned to the page. Centralised here so Index/Show/
     * Edit all read the same field set without each duplicating the
     * Eloquent → array transform.
     */
    private function transform(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->status?->value,
            'priority' => $project->priority?->value,
            'environment' => $project->environment,
            'color' => $project->color,
            'icon' => $project->icon,
            'health_score' => $project->health_score,
            'last_activity_at' => $project->last_activity_at?->diffForHumans(),
            'owner' => $project->owner ? [
                'id' => $project->owner->id,
                'name' => $project->owner->name,
                'initials' => $this->initialsFor($project->owner->name),
            ] : null,
        ];
    }

    private function initialsFor(?string $name): string
    {
        if ($name === null || $name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(
            fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice($parts, 0, 2),
        );

        return implode('', $letters) ?: '?';
    }

    /**
     * Static option lists shipped to the Create/Edit forms. Kept on the
     * server so the Vue layer doesn't drift from the validation rules.
     */
    private function formOptions(): array
    {
        return [
            'statuses' => array_map(fn (ProjectStatus $s) => [
                'value' => $s->value,
                'label' => ucfirst($s->value),
                'tone' => $s->badgeTone(),
            ], ProjectStatus::cases()),

            'priorities' => array_map(fn (ProjectPriority $p) => [
                'value' => $p->value,
                'label' => ucfirst($p->value),
                'tone' => $p->badgeTone(),
            ], ProjectPriority::cases()),

            'colors' => ['cyan', 'blue', 'purple', 'magenta', 'success', 'warning'],

            'icons' => [
                'FolderKanban', 'Rocket', 'GitBranch', 'Server',
                'Globe', 'BarChart3', 'Bell', 'Activity',
                'HeartPulse', 'Cpu', 'Database', 'Cloud',
            ],
        ];
    }
}
