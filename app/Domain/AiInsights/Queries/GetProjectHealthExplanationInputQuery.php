<?php

namespace App\Domain\AiInsights\Queries;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\HealthScoreBand;
use App\Enums\HostStatus;
use App\Enums\WebsiteStatus;
use App\Enums\WorkflowRunConclusion;
use App\Models\Alert;
use App\Models\Container;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Models\WorkflowRun;
use Carbon\CarbonImmutable;

class GetProjectHealthExplanationInputQuery
{
    public const SAMPLE_LIMIT = 5;

    /** @return array<string, mixed>|null */
    public function execute(User $user, Project $project): ?array
    {
        $project = Project::query()
            ->whereKey($project->id)
            ->where('owner_user_id', $user->id)
            ->first();

        if (! $project) {
            return null;
        }

        return [
            'snapshot_version' => 'project-health-explanation-input-v1',
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status?->value,
                'priority' => $project->priority?->value,
                'environment' => $project->environment,
                'health_score' => $project->health_score,
                'health_band' => $project->health_score === null
                    ? null
                    : HealthScoreBand::fromScore($project->health_score)->value,
                'last_activity_at' => $project->last_activity_at?->toIso8601String(),
            ],
            'drivers' => [
                'alerts' => $this->alerts($project->id),
                'deployments' => $this->deployments($project->id),
                'websites' => $this->websites($project->id),
                'hosts' => $this->hosts($project->id),
                'containers' => $this->containers($project->id),
                'github_sync' => $this->githubSync($project->id),
            ],
            'health_delta' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function alerts(int $projectId): array
    {
        $activeStatuses = [AlertStatus::Open->value, AlertStatus::Acknowledged->value];
        $base = Alert::query()
            ->where('project_id', $projectId)
            ->whereIn('status', $activeStatuses);

        return [
            'active_total' => (clone $base)->count(),
            'active_by_severity' => (clone $base)
                ->selectRaw('severity, COUNT(id) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'active_by_source' => (clone $base)
                ->selectRaw('source, COUNT(id) as total')
                ->groupBy('source')
                ->pluck('total', 'source')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'sample' => (clone $base)
                ->whereIn('severity', [AlertSeverity::Critical->value, AlertSeverity::Warning->value])
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get(['id', 'source', 'type', 'severity', 'status', 'title', 'triggered_at', 'last_seen_at'])
                ->map(fn (Alert $alert): array => [
                    'id' => $alert->id,
                    'source' => $alert->source?->value,
                    'type' => $alert->type,
                    'severity' => $alert->severity?->value,
                    'status' => $alert->status?->value,
                    'title' => $this->sanitizeText($alert->title),
                    'triggered_at' => $alert->triggered_at?->toIso8601String(),
                    'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function deployments(int $projectId): array
    {
        $since = CarbonImmutable::now('UTC')->subDay();

        $failed = WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->where('repositories.project_id', $projectId)
            ->whereColumn('workflow_runs.head_branch', 'repositories.default_branch')
            ->where('workflow_runs.conclusion', WorkflowRunConclusion::Failure->value)
            ->where('workflow_runs.run_completed_at', '>', $since);

        return [
            'failed_default_branch_last_24h' => (clone $failed)->count('workflow_runs.id'),
            'failed_workflows_sample' => (clone $failed)
                ->orderByDesc('workflow_runs.run_completed_at')
                ->orderByDesc('workflow_runs.id')
                ->limit(self::SAMPLE_LIMIT)
                ->get([
                    'workflow_runs.id',
                    'workflow_runs.run_number',
                    'workflow_runs.name',
                    'workflow_runs.conclusion',
                    'workflow_runs.head_branch',
                    'workflow_runs.run_completed_at',
                    'repositories.full_name as repository_full_name',
                ])
                ->map(fn (WorkflowRun $run): array => [
                    'id' => $run->id,
                    'run_number' => $run->run_number,
                    'name' => $run->name,
                    'conclusion' => $run->conclusion?->value,
                    'head_branch' => $run->head_branch,
                    'completed_at' => $run->run_completed_at?->toIso8601String(),
                    'repository_full_name' => $run->repository_full_name,
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function websites(int $projectId): array
    {
        return [
            'by_status' => Website::query()
                ->where('project_id', $projectId)
                ->selectRaw('status, COUNT(id) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'problem_sample' => Website::query()
                ->where('project_id', $projectId)
                ->whereIn('status', [WebsiteStatus::Down->value, WebsiteStatus::Error->value, WebsiteStatus::Slow->value])
                ->orderByDesc('last_failure_at')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get(['id', 'name', 'status', 'last_checked_at', 'last_failure_at'])
                ->map(fn (Website $website): array => [
                    'id' => $website->id,
                    'name' => $this->sanitizeText($website->name),
                    'status' => $website->status?->value,
                    'last_checked_at' => $website->last_checked_at?->toIso8601String(),
                    'last_failure_at' => $website->last_failure_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function hosts(int $projectId): array
    {
        return [
            'by_status' => Host::query()
                ->where('project_id', $projectId)
                ->whereNull('archived_at')
                ->selectRaw('status, COUNT(id) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'offline_count' => Host::query()
                ->where('project_id', $projectId)
                ->whereNull('archived_at')
                ->where('status', HostStatus::Offline->value)
                ->count(),
            'problem_sample' => Host::query()
                ->where('project_id', $projectId)
                ->whereNull('archived_at')
                ->whereIn('status', [HostStatus::Offline->value, HostStatus::Degraded->value])
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get(['id', 'name', 'status', 'last_seen_at'])
                ->map(fn (Host $host): array => [
                    'id' => $host->id,
                    'name' => $this->sanitizeText($host->name),
                    'status' => $host->status?->value,
                    'last_seen_at' => $host->last_seen_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function containers(int $projectId): array
    {
        return [
            'by_health_status' => Container::query()
                ->where('project_id', $projectId)
                ->selectRaw('COALESCE(health_status, status) as health_key, COUNT(id) as total')
                ->groupBy('health_key')
                ->pluck('total', 'health_key')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'unhealthy_count' => Container::query()
                ->where('project_id', $projectId)
                ->where('health_status', 'unhealthy')
                ->count(),
            'problem_sample' => Container::query()
                ->where('project_id', $projectId)
                ->where(function ($query): void {
                    $query->where('health_status', 'unhealthy')
                        ->orWhereIn('status', ['exited', 'dead', 'restarting']);
                })
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get(['id', 'name', 'status', 'health_status', 'last_seen_at'])
                ->map(fn (Container $container): array => [
                    'id' => $container->id,
                    'name' => $this->sanitizeText($container->name),
                    'status' => $container->status,
                    'health_status' => $container->health_status,
                    'last_seen_at' => $container->last_seen_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function githubSync(int $projectId): array
    {
        $repositories = Repository::query()
            ->where('project_id', $projectId);

        return [
            'repositories_total' => (clone $repositories)->count(),
            'failed_repositories_sample' => (clone $repositories)
                ->where(function ($query): void {
                    $query->whereNotNull('sync_failed_at')
                        ->orWhereNotNull('issues_sync_failed_at')
                        ->orWhereNotNull('prs_sync_failed_at')
                        ->orWhereNotNull('workflow_runs_sync_failed_at');
                })
                ->orderByDesc('sync_failed_at')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get([
                    'id',
                    'full_name',
                    'sync_status',
                    'issues_sync_status',
                    'prs_sync_status',
                    'workflow_runs_sync_status',
                    'sync_failed_at',
                    'issues_sync_failed_at',
                    'prs_sync_failed_at',
                    'workflow_runs_sync_failed_at',
                ])
                ->map(fn ($repository): array => [
                    'id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'sync_status' => $repository->sync_status?->value,
                    'issues_sync_status' => $repository->issues_sync_status?->value,
                    'prs_sync_status' => $repository->prs_sync_status?->value,
                    'workflow_runs_sync_status' => $repository->workflow_runs_sync_status?->value,
                    'sync_failed_at' => $repository->sync_failed_at?->toIso8601String(),
                    'issues_sync_failed_at' => $repository->issues_sync_failed_at?->toIso8601String(),
                    'prs_sync_failed_at' => $repository->prs_sync_failed_at?->toIso8601String(),
                    'workflow_runs_sync_failed_at' => $repository->workflow_runs_sync_failed_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\b[A-Z0-9_]*(TOKEN|SECRET|PASSWORD|KEY)[A-Z0-9_]*\s*[:=]\s*[^\s,;]+/i', '[redacted]', $value);
    }
}
