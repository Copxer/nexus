<?php

namespace App\Http\Controllers\Monitoring;

use App\Domain\Docker\Actions\ArchiveHostAction;
use App\Domain\Docker\Actions\CreateHostAction;
use App\Domain\Docker\Actions\UpdateHostAction;
use App\Enums\HostConnectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreHostRequest;
use App\Http\Requests\Monitoring\UpdateHostRequest;
use App\Models\Host;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resourceful CRUD for Docker hosts (spec 026). Lives at
 * `/monitoring/hosts/*` — sibling to `/monitoring/websites/*` so a
 * future "Monitoring" landing page can wrap both.
 *
 * `destroy` doesn't actually delete: it routes through
 * `ArchiveHostAction` so the host's telemetry history is preserved
 * and any agent token is revoked in the same transaction.
 */
class HostController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Host::class);

        $user = $request->user();

        $hosts = Host::query()
            ->with(['project:id,slug,name,color,icon,owner_user_id', 'activeAgentToken'])
            ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
            ->orderBy('name')
            ->get()
            ->map(fn (Host $host) => $this->transform($host));

        return Inertia::render('Monitoring/Hosts/Index', [
            'hosts' => $hosts,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('viewAny', Host::class);

        $user = $request->user();

        $preselectId = $request->integer('project_id') ?: null;
        $preselect = $preselectId !== null
            ? Project::query()->where('owner_user_id', $user->id)->find($preselectId)
            : null;

        return Inertia::render('Monitoring/Hosts/Create', [
            'projects' => $this->ownedProjects($user->id),
            'preselectedProjectId' => $preselect?->id,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreHostRequest $request, CreateHostAction $createHost): RedirectResponse
    {
        $this->authorize('create', [Host::class, $request->resolvedProject()]);

        $host = $createHost->execute(
            $request->resolvedProject(),
            $request->validated(),
        );

        return redirect()
            ->route('monitoring.hosts.show', $host)
            ->with('status', "Host “{$host->name}” created. Mint an agent token to bring it online.");
    }

    public function show(Request $request, Host $host): Response
    {
        $this->authorize('view', $host);

        $host->loadMissing([
            'project:id,slug,name,color,icon,owner_user_id',
            'activeAgentToken',
        ]);

        return Inertia::render('Monitoring/Hosts/Show', [
            'host' => $this->transform($host),
            'canUpdate' => $request->user()?->can('update', $host) ?? false,
            'canDelete' => $request->user()?->can('delete', $host) ?? false,
            'canManageTokens' => $request->user()?->can('manageTokens', $host) ?? false,
        ]);
    }

    public function edit(Request $request, Host $host): Response
    {
        $this->authorize('update', $host);

        $host->loadMissing('project:id,slug,name,color,icon,owner_user_id');

        return Inertia::render('Monitoring/Hosts/Edit', [
            'host' => $this->transform($host),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateHostRequest $request, Host $host, UpdateHostAction $updateHost): RedirectResponse
    {
        $updateHost->execute($host, $request->validated());

        return redirect()
            ->route('monitoring.hosts.show', $host)
            ->with('status', 'Host updated.');
    }

    public function destroy(Host $host, ArchiveHostAction $archive): RedirectResponse
    {
        $this->authorize('delete', $host);

        $archive->execute($host);

        return redirect()
            ->route('monitoring.hosts.index')
            ->with('status', "Host “{$host->name}” archived. Existing agent tokens have been revoked.");
    }

    /**
     * Single source of truth for the host JSON shape. Centralised so
     * Index/Show/Edit don't drift on field set.
     */
    private function transform(Host $host): array
    {
        $activeToken = $host->activeAgentToken;

        return [
            'id' => $host->id,
            'name' => $host->name,
            'slug' => $host->slug,
            'provider' => $host->provider,
            'endpoint_url' => $host->endpoint_url,
            'connection_type' => $host->connection_type?->value,
            'status' => $host->status?->value,
            'last_seen_at' => $host->last_seen_at?->diffForHumans(),
            'last_seen_at_iso' => $host->last_seen_at?->toIso8601String(),
            'cpu_count' => $host->cpu_count,
            'memory_total_mb' => $host->memory_total_mb,
            'disk_total_gb' => $host->disk_total_gb,
            'os' => $host->os,
            'docker_version' => $host->docker_version,
            'archived_at' => $host->archived_at?->toIso8601String(),
            'project' => $host->project ? [
                'id' => $host->project->id,
                'slug' => $host->project->slug,
                'name' => $host->project->name,
                'color' => $host->project->color,
                'icon' => $host->project->icon,
            ] : null,
            // The plaintext token never travels through `transform()` —
            // it only exists in the session flash. We surface only
            // metadata about the active token so the UI can label
            // "active token: agent v0.2 — last seen 2h ago".
            'active_agent_token' => $activeToken ? [
                'id' => $activeToken->id,
                'name' => $activeToken->name,
                'last_used_at' => $activeToken->last_used_at?->diffForHumans(),
                'created_at' => $activeToken->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Project dropdown payload — only projects the user owns.
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

    private function formOptions(): array
    {
        return [
            'connection_types' => collect(HostConnectionType::cases())->map(fn (HostConnectionType $case) => [
                'value' => $case->value,
                'label' => match ($case) {
                    HostConnectionType::Agent => 'Agent (push)',
                    HostConnectionType::Ssh => 'SSH (coming soon)',
                    HostConnectionType::DockerApi => 'Docker API (coming soon)',
                    HostConnectionType::Manual => 'Manual / inventory only',
                },
                'enabled' => $case === HostConnectionType::Agent || $case === HostConnectionType::Manual,
            ])->all(),
        ];
    }
}
