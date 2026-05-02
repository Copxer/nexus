<?php

namespace App\Http\Controllers;

use App\Domain\Activity\Queries\RecentActivityForProjectQuery;
use App\Domain\GitHub\Queries\DeploymentTimelineQuery;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Host;
use App\Models\Project;
use App\Models\Website;
use App\Support\ProjectPalette;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(
        Request $request,
        Project $project,
        RecentActivityForProjectQuery $activityQuery,
        DeploymentTimelineQuery $deploymentsQuery,
    ): Response {
        $this->authorize('view', $project);

        $project->loadMissing('owner:id,name,email');
        $project->loadMissing(['repositories' => fn ($q) => $q->latest('last_pushed_at')->latest()]);

        // Per-project Activity tab — feed events from this project's
        // repositories. Reuses `<ActivityFeed>` so we don't drift from
        // the right-rail / `/activity` page renderers.
        $projectActivity = $activityQuery->handle($project);

        // Per-project Deployments tab — recent workflow runs across the
        // project's repos. Powered by the cross-repo
        // `DeploymentTimelineQuery` filtered to `project_id`. The tab's
        // "View all" CTA links out to `/deployments?project_id={id}`
        // for the wider view + filters, so we only need the first 10
        // here — the underlying query caps at 100 and the Vue tab
        // already slices to 10, but slicing here keeps ~70 KB off the
        // wire for a tab that may never open.
        $projectDeployments = array_slice(
            $deploymentsQuery->execute(
                $request->user(),
                ['project_id' => $project->id],
            ),
            0,
            10,
        );

        // Per-project Monitoring tab (spec 023) — website monitors
        // belonging to this project. Cap at 20 inline — the cross-repo
        // monitor list at `/monitoring/websites` is the wider view.
        // Phase-1 single-tenant: any monitor under the project is
        // visible to its owner; cross-tenant scoping arrives uniformly
        // when teams ship.
        $projectMonitors = Website::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Website $website) => [
                'id' => $website->id,
                'name' => $website->name,
                'url' => $website->url,
                'method' => $website->method,
                'status' => $website->status?->value,
                'last_checked_at' => $website->last_checked_at?->diffForHumans(),
            ])
            ->all();

        // Per-project Hosts tab (spec 026 + 027) — Docker hosts
        // registered under this project. Same 20-row cap pattern as the
        // monitors list; the wider view lives at `/monitoring/hosts`.
        $projectHosts = Host::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->with('activeAgentToken')
            ->limit(20)
            ->get()
            ->map(fn (Host $host) => [
                'id' => $host->id,
                'name' => $host->name,
                'slug' => $host->slug,
                'provider' => $host->provider,
                'connection_type' => $host->connection_type?->value,
                'status' => $host->status?->value,
                'last_seen_at' => $host->last_seen_at?->diffForHumans(),
                'has_active_token' => $host->activeAgentToken !== null,
            ])
            ->all();

        return Inertia::render('Projects/Show', [
            'project' => $this->transform($project),
            'canUpdate' => $request->user()?->can('update', $project) ?? false,
            'canDelete' => $request->user()?->can('delete', $project) ?? false,
            'hasGithubConnection' => $request->user()?->githubConnection !== null,
            'repositories' => $project->repositories->map(fn ($repo) => [
                'id' => $repo->id,
                'owner' => $repo->owner,
                'name' => $repo->name,
                'full_name' => $repo->full_name,
                'html_url' => $repo->html_url,
                'default_branch' => $repo->default_branch,
                'visibility' => $repo->visibility,
                'language' => $repo->language,
                'open_issues_count' => $repo->open_issues_count,
                'open_prs_count' => $repo->open_prs_count,
                'last_pushed_at' => $repo->last_pushed_at?->diffForHumans(),
                'sync_status' => $repo->sync_status?->value,
            ])->all(),
            'projectActivity' => $projectActivity,
            'projectDeployments' => $projectDeployments,
            'projectMonitors' => $projectMonitors,
            'projectHosts' => $projectHosts,
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

            'colors' => ProjectPalette::COLORS,
            'icons' => ProjectPalette::ICONS,
        ];
    }
}
