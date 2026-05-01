<?php

namespace App\Http\Controllers\Monitoring;

use App\Domain\Monitoring\Queries\GetWebsitePerformanceSummaryQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreWebsiteRequest;
use App\Http\Requests\Monitoring\UpdateWebsiteRequest;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resourceful CRUD for website monitors (spec 023). The page family
 * lives at `/monitoring/websites/*` so phase-6 hosts can sit beside
 * it as `/monitoring/hosts/*`.
 *
 * The cross-project `index` is the default view; per-project filtering
 * lives behind a `?project_id=N` query string. Multi-tenant team
 * scoping arrives uniformly when teams ship.
 */
class WebsiteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Website::class);

        $user = $request->user();

        $websites = Website::query()
            ->with('project:id,slug,name,color,icon,owner_user_id')
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->orderBy('name')
            ->get()
            ->map(fn (Website $website) => $this->transform($website));

        return Inertia::render('Monitoring/Websites/Index', [
            'websites' => $websites,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('viewAny', Website::class);

        $user = $request->user();

        // Pre-select via `?project_id=N` so the link from a project
        // page lands on the right project. If the project ID resolves
        // to one the user owns we authorise create against it; if it
        // resolves to a foreign project (or doesn't resolve at all),
        // we drop the preselect rather than 403'ing the form — the
        // user still needs to see the page to pick one of their own
        // projects, and `store` will re-authorise at submit time.
        $preselectId = $request->integer('project_id') ?: null;
        $preselect = $preselectId !== null
            ? Project::query()->where('owner_user_id', $user->id)->find($preselectId)
            : null;

        return Inertia::render('Monitoring/Websites/Create', [
            'projects' => $this->ownedProjects($user->id),
            'preselectedProjectId' => $preselect?->id,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreWebsiteRequest $request): RedirectResponse
    {
        // Belt-and-suspenders: the form request already authorised this,
        // but re-authorising via the policy after validation guards
        // against a stale prop slipping past (mirrors the
        // `RepositoryController::store` pattern).
        $this->authorize('create', [Website::class, $request->resolvedProject()]);

        $website = Website::query()->create($request->validated());

        return redirect()
            ->route('monitoring.websites.show', $website)
            ->with('status', "Monitor created for {$website->name}.");
    }

    public function show(
        Request $request,
        Website $website,
        GetWebsitePerformanceSummaryQuery $summaryQuery,
    ): Response {
        $this->authorize('view', $website);

        $website->loadMissing('project:id,slug,name,color,icon,owner_user_id');

        $checks = $website->checks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($check) => [
                'id' => $check->id,
                'status' => $check->status?->value,
                'http_status_code' => $check->http_status_code,
                'response_time_ms' => $check->response_time_ms,
                'error_message' => $check->error_message,
                'checked_at' => $check->checked_at?->diffForHumans(),
                'checked_at_iso' => $check->checked_at?->toIso8601String(),
            ])
            ->all();

        // Spec 024 — uptime % over 24h / 7d / 30d windows + last
        // incident timestamp. Three count queries; cheap at phase-1
        // scale, no caching layer.
        $rawSummary = $summaryQuery->execute($website);
        $summary = [
            'uptime_24h' => $rawSummary['uptime_24h'],
            'uptime_7d' => $rawSummary['uptime_7d'],
            'uptime_30d' => $rawSummary['uptime_30d'],
            'last_incident_at' => $rawSummary['last_incident_at']?->diffForHumans(),
        ];

        return Inertia::render('Monitoring/Websites/Show', [
            'website' => $this->transform($website),
            'checks' => $checks,
            'summary' => $summary,
            'canUpdate' => $request->user()?->can('update', $website) ?? false,
            'canDelete' => $request->user()?->can('delete', $website) ?? false,
            'canProbe' => $request->user()?->can('probe', $website) ?? false,
        ]);
    }

    public function edit(Request $request, Website $website): Response
    {
        $this->authorize('update', $website);

        return Inertia::render('Monitoring/Websites/Edit', [
            'website' => $this->transform($website),
            'projects' => $this->ownedProjects($request->user()->id),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateWebsiteRequest $request, Website $website): RedirectResponse
    {
        $website->update($request->validated());

        return redirect()
            ->route('monitoring.websites.show', $website)
            ->with('status', 'Monitor updated.');
    }

    public function destroy(Website $website): RedirectResponse
    {
        $this->authorize('delete', $website);

        $website->delete();

        return redirect()
            ->route('monitoring.websites.index')
            ->with('status', 'Monitor deleted.');
    }

    /**
     * Single source of truth for the website JSON shape. Centralised so
     * Index/Show/Edit don't drift on field set.
     */
    private function transform(Website $website): array
    {
        return [
            'id' => $website->id,
            'name' => $website->name,
            'url' => $website->url,
            'method' => $website->method,
            'expected_status_code' => $website->expected_status_code,
            'timeout_ms' => $website->timeout_ms,
            'check_interval_seconds' => $website->check_interval_seconds,
            'status' => $website->status?->value,
            'last_checked_at' => $website->last_checked_at?->diffForHumans(),
            'last_success_at' => $website->last_success_at?->diffForHumans(),
            'last_failure_at' => $website->last_failure_at?->diffForHumans(),
            'project' => $website->project ? [
                'id' => $website->project->id,
                'slug' => $website->project->slug,
                'name' => $website->project->name,
                'color' => $website->project->color,
                'icon' => $website->project->icon,
            ] : null,
        ];
    }

    /**
     * Project dropdown payload — only projects the user owns. Cheap
     * query; called from `create` + `edit`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ownedProjects(int $userId): array
    {
        return Project::query()
            ->where('owner_user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'color' => $project->color,
            ])
            ->all();
    }

    /**
     * Static option lists for the Create/Edit forms. Kept on the
     * server so the Vue layer doesn't drift from the validation rules.
     */
    private function formOptions(): array
    {
        return [
            'methods' => [
                ['value' => 'GET', 'label' => 'GET'],
                ['value' => 'HEAD', 'label' => 'HEAD'],
                ['value' => 'POST', 'label' => 'POST'],
            ],
            'common_intervals' => [
                ['value' => 60, 'label' => 'Every minute'],
                ['value' => 300, 'label' => 'Every 5 minutes'],
                ['value' => 900, 'label' => 'Every 15 minutes'],
                ['value' => 3600, 'label' => 'Hourly'],
            ],
        ];
    }
}
