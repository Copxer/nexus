<?php

namespace App\Domain\DailyBriefings\Queries;

use App\Enums\AlertStatus;
use App\Enums\GithubIssueState;
use App\Enums\GithubPullRequestState;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Container;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GetDailyBriefingInputQuery
{
    public const TOP_PROJECTS_LIMIT = 5;

    public const WORK_ITEMS_LIMIT = 10;

    public const ALERTS_LIMIT = 10;

    public const ACTIVITY_EVENTS_LIMIT = 10;

    /**
     * @param  array<int, int>|null  $includeProjectIds
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        CarbonInterface|string $briefingDate,
        ?string $timezone = null,
        ?array $includeProjectIds = null,
    ): array {
        $timezone = $timezone ?: config('app.timezone', 'UTC');
        $localStart = CarbonImmutable::parse($briefingDate, $timezone)->startOfDay();
        $localEnd = $localStart->addDay();
        $utcStart = $localStart->setTimezone('UTC');
        $utcEnd = $localEnd->setTimezone('UTC');
        $projectIds = $this->projectIdsFor($user, $includeProjectIds);

        return [
            'window' => [
                'briefing_date' => $localStart->toDateString(),
                'timezone' => $timezone,
                'starts_at_utc' => $utcStart->toIso8601String(),
                'ends_at_utc' => $utcEnd->toIso8601String(),
            ],
            'projects' => $this->projects($projectIds),
            'github' => $this->github($projectIds, $utcStart, $utcEnd),
            'deployments' => $this->deployments($projectIds, $utcStart, $utcEnd),
            'alerts' => $this->alerts($projectIds, $utcStart, $utcEnd),
            'monitoring' => $this->monitoring($projectIds, $utcStart, $utcEnd),
            'health' => $this->health($projectIds),
            'activity' => $this->activity($projectIds, $utcStart, $utcEnd),
        ];
    }

    /**
     * @param  array<int, int>|null  $includeProjectIds
     * @return array<int, int>
     */
    private function projectIdsFor(User $user, ?array $includeProjectIds): array
    {
        $query = Project::query()->where('owner_user_id', $user->id);

        if ($includeProjectIds !== null && $includeProjectIds !== []) {
            $query->whereIn('id', $includeProjectIds);
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function projects(array $projectIds): array
    {
        $sample = Project::query()
            ->whereIn('id', $projectIds)
            ->orderByRaw('health_score IS NULL')
            ->orderBy('health_score')
            ->orderByDesc('last_activity_at')
            ->orderBy('id')
            ->limit(self::TOP_PROJECTS_LIMIT)
            ->get(['id', 'name', 'slug', 'health_score', 'last_activity_at'])
            ->map(fn (Project $project): array => $this->projectPayload($project))
            ->all();

        return [
            'total' => count($projectIds),
            'sample' => $sample,
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function github(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        $issueBase = GithubIssue::query()
            ->join('repositories', 'github_issues.repository_id', '=', 'repositories.id')
            ->whereIn('repositories.project_id', $projectIds);

        $prBase = GithubPullRequest::query()
            ->join('repositories', 'github_pull_requests.repository_id', '=', 'repositories.id')
            ->whereIn('repositories.project_id', $projectIds);

        $items = collect()
            ->merge($this->issueSamples($projectIds, 'opened', 'created_at_github', $utcStart, $utcEnd))
            ->merge($this->issueSamples($projectIds, 'closed', 'closed_at_github', $utcStart, $utcEnd))
            ->merge($this->pullRequestSamples($projectIds, 'opened', 'created_at_github', $utcStart, $utcEnd))
            ->merge($this->pullRequestSamples($projectIds, 'merged', 'merged_at', $utcStart, $utcEnd))
            ->merge($this->pullRequestSamples($projectIds, 'closed', 'closed_at_github', $utcStart, $utcEnd, false))
            ->sortByDesc('occurred_at')
            ->take(self::WORK_ITEMS_LIMIT)
            ->values()
            ->all();

        return [
            'issues' => [
                'opened' => (clone $issueBase)->where('github_issues.created_at_github', '>=', $utcStart)->where('github_issues.created_at_github', '<', $utcEnd)->count('github_issues.id'),
                'closed' => (clone $issueBase)->where('github_issues.state', GithubIssueState::Closed->value)->where('github_issues.closed_at_github', '>=', $utcStart)->where('github_issues.closed_at_github', '<', $utcEnd)->count('github_issues.id'),
            ],
            'pull_requests' => [
                'opened' => (clone $prBase)->where('github_pull_requests.created_at_github', '>=', $utcStart)->where('github_pull_requests.created_at_github', '<', $utcEnd)->count('github_pull_requests.id'),
                'merged' => (clone $prBase)->where('github_pull_requests.merged', true)->where('github_pull_requests.merged_at', '>=', $utcStart)->where('github_pull_requests.merged_at', '<', $utcEnd)->count('github_pull_requests.id'),
                'closed' => (clone $prBase)->where('github_pull_requests.state', GithubPullRequestState::Closed->value)->where('github_pull_requests.closed_at_github', '>=', $utcStart)->where('github_pull_requests.closed_at_github', '<', $utcEnd)->count('github_pull_requests.id'),
            ],
            'work_items' => $items,
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, array<string, mixed>>
     */
    private function issueSamples(array $projectIds, string $event, string $timestampColumn, CarbonInterface $utcStart, CarbonInterface $utcEnd): Collection
    {
        return GithubIssue::query()
            ->join('repositories', 'github_issues.repository_id', '=', 'repositories.id')
            ->join('projects', 'repositories.project_id', '=', 'projects.id')
            ->whereIn('repositories.project_id', $projectIds)
            ->whereNotNull("github_issues.{$timestampColumn}")
            ->where("github_issues.{$timestampColumn}", '>=', $utcStart)
            ->where("github_issues.{$timestampColumn}", '<', $utcEnd)
            ->orderByDesc("github_issues.{$timestampColumn}")
            ->limit(self::WORK_ITEMS_LIMIT)
            ->get([
                'github_issues.id',
                'github_issues.number',
                'github_issues.title',
                'github_issues.state',
                "github_issues.{$timestampColumn} as occurred_at",
                'projects.id as project_id',
                'projects.name as project_name',
            ])
            ->map(fn ($row): array => [
                'kind' => 'issue',
                'event' => $event,
                'id' => (int) $row->id,
                'number' => (int) $row->number,
                'title' => $row->title,
                'state' => $row->state instanceof GithubIssueState ? $row->state->value : $row->state,
                'occurred_at' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name,
            ]);
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, array<string, mixed>>
     */
    private function pullRequestSamples(array $projectIds, string $event, string $timestampColumn, CarbonInterface $utcStart, CarbonInterface $utcEnd, ?bool $merged = null): Collection
    {
        $query = GithubPullRequest::query()
            ->join('repositories', 'github_pull_requests.repository_id', '=', 'repositories.id')
            ->join('projects', 'repositories.project_id', '=', 'projects.id')
            ->whereIn('repositories.project_id', $projectIds)
            ->whereNotNull("github_pull_requests.{$timestampColumn}")
            ->where("github_pull_requests.{$timestampColumn}", '>=', $utcStart)
            ->where("github_pull_requests.{$timestampColumn}", '<', $utcEnd);

        if ($merged !== null) {
            $query->where('github_pull_requests.merged', $merged);
        }

        return $query
            ->orderByDesc("github_pull_requests.{$timestampColumn}")
            ->limit(self::WORK_ITEMS_LIMIT)
            ->get([
                'github_pull_requests.id',
                'github_pull_requests.number',
                'github_pull_requests.title',
                'github_pull_requests.state',
                "github_pull_requests.{$timestampColumn} as occurred_at",
                'projects.id as project_id',
                'projects.name as project_name',
            ])
            ->map(fn ($row): array => [
                'kind' => 'pull_request',
                'event' => $event,
                'id' => (int) $row->id,
                'number' => (int) $row->number,
                'title' => $row->title,
                'state' => $row->state instanceof GithubPullRequestState ? $row->state->value : $row->state,
                'occurred_at' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name,
            ]);
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function deployments(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        $base = WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->join('projects', 'repositories.project_id', '=', 'projects.id')
            ->whereIn('repositories.project_id', $projectIds)
            ->where('workflow_runs.status', WorkflowRunStatus::Completed->value)
            ->where('workflow_runs.run_completed_at', '>=', $utcStart)
            ->where('workflow_runs.run_completed_at', '<', $utcEnd);

        $failedConclusions = [
            WorkflowRunConclusion::Failure->value,
            WorkflowRunConclusion::TimedOut->value,
            WorkflowRunConclusion::ActionRequired->value,
        ];

        return [
            'successful' => (clone $base)->where('workflow_runs.conclusion', WorkflowRunConclusion::Success->value)->count('workflow_runs.id'),
            'failed' => (clone $base)->whereIn('workflow_runs.conclusion', $failedConclusions)->count('workflow_runs.id'),
            'failed_workflows' => (clone $base)
                ->whereIn('workflow_runs.conclusion', $failedConclusions)
                ->orderByDesc('workflow_runs.run_completed_at')
                ->limit(self::WORK_ITEMS_LIMIT)
                ->get([
                    'workflow_runs.id',
                    'workflow_runs.name',
                    'workflow_runs.conclusion',
                    'workflow_runs.run_completed_at',
                    'projects.id as project_id',
                    'projects.name as project_name',
                ])
                ->map(fn (WorkflowRun $run): array => [
                    'id' => $run->id,
                    'name' => $run->name,
                    'conclusion' => $run->conclusion instanceof WorkflowRunConclusion ? $run->conclusion->value : $run->conclusion,
                    'completed_at' => $run->run_completed_at?->toIso8601String(),
                    'project_id' => (int) $run->project_id,
                    'project_name' => $run->project_name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function alerts(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        $triggeredBase = Alert::query()
            ->join('projects', 'alerts.project_id', '=', 'projects.id')
            ->whereIn('alerts.project_id', $projectIds)
            ->where('alerts.triggered_at', '>=', $utcStart)
            ->where('alerts.triggered_at', '<', $utcEnd);

        $resolvedBase = Alert::query()
            ->whereIn('alerts.project_id', $projectIds)
            ->where('alerts.status', AlertStatus::Resolved->value)
            ->where('alerts.resolved_at', '>=', $utcStart)
            ->where('alerts.resolved_at', '<', $utcEnd);

        return [
            'triggered' => (clone $triggeredBase)->count('alerts.id'),
            'resolved' => (clone $resolvedBase)->count('alerts.id'),
            'groups' => (clone $triggeredBase)
                ->selectRaw('alerts.severity, alerts.source, alerts.project_id, projects.name as project_name, COUNT(alerts.id) as total')
                ->groupBy('alerts.severity', 'alerts.source', 'alerts.project_id', 'projects.name')
                ->orderByDesc('total')
                ->orderBy('alerts.project_id')
                ->get()
                ->map(fn ($row): array => [
                    'severity' => $row->severity,
                    'source' => $row->source,
                    'project_id' => (int) $row->project_id,
                    'project_name' => $row->project_name,
                    'total' => (int) $row->total,
                ])
                ->all(),
            'sample' => (clone $triggeredBase)
                ->orderByDesc('alerts.triggered_at')
                ->limit(self::ALERTS_LIMIT)
                ->get([
                    'alerts.id',
                    'alerts.title',
                    'alerts.severity',
                    'alerts.source',
                    'alerts.status',
                    'alerts.triggered_at',
                    'projects.id as project_id',
                    'projects.name as project_name',
                ])
                ->map(fn (Alert $alert): array => [
                    'id' => $alert->id,
                    'title' => $alert->title,
                    'severity' => $alert->severity->value,
                    'source' => $alert->source->value,
                    'status' => $alert->status->value,
                    'triggered_at' => $alert->triggered_at?->toIso8601String(),
                    'project_id' => (int) $alert->project_id,
                    'project_name' => $alert->project_name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function monitoring(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        $websiteChecks = WebsiteCheck::query()
            ->join('websites', 'website_checks.website_id', '=', 'websites.id')
            ->join('projects', 'websites.project_id', '=', 'projects.id')
            ->whereIn('websites.project_id', $projectIds)
            ->where('website_checks.checked_at', '>=', $utcStart)
            ->where('website_checks.checked_at', '<', $utcEnd);

        return [
            'website_checks' => [
                'total' => (clone $websiteChecks)->count('website_checks.id'),
                'by_status' => (clone $websiteChecks)
                    ->selectRaw('website_checks.status, COUNT(website_checks.id) as total')
                    ->groupBy('website_checks.status')
                    ->pluck('total', 'status')
                    ->map(fn ($total): int => (int) $total)
                    ->all(),
                'problem_sample' => (clone $websiteChecks)
                    ->whereIn('website_checks.status', [
                        WebsiteCheckStatus::Down->value,
                        WebsiteCheckStatus::Slow->value,
                        WebsiteCheckStatus::Error->value,
                    ])
                    ->orderByDesc('website_checks.checked_at')
                    ->limit(self::WORK_ITEMS_LIMIT)
                    ->get([
                        'website_checks.id',
                        'website_checks.status',
                        'website_checks.response_time_ms',
                        'website_checks.checked_at',
                        'websites.name as website_name',
                        'projects.id as project_id',
                        'projects.name as project_name',
                    ])
                    ->map(fn (WebsiteCheck $check): array => [
                        'id' => $check->id,
                        'website_name' => $check->website_name,
                        'status' => $check->status->value,
                        'response_time_ms' => $check->response_time_ms,
                        'checked_at' => $check->checked_at?->toIso8601String(),
                        'project_id' => (int) $check->project_id,
                        'project_name' => $check->project_name,
                    ])
                    ->all(),
            ],
            'hosts' => $this->hostHealth($projectIds),
            'containers' => $this->containerHealth($projectIds),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function hostHealth(array $projectIds): array
    {
        return [
            'by_status' => Host::query()
                ->whereIn('project_id', $projectIds)
                ->selectRaw('status, COUNT(id) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'problem_sample' => Host::query()
                ->join('projects', 'hosts.project_id', '=', 'projects.id')
                ->whereIn('hosts.project_id', $projectIds)
                ->whereIn('hosts.status', ['offline', 'degraded'])
                ->orderByDesc('hosts.last_seen_at')
                ->limit(self::TOP_PROJECTS_LIMIT)
                ->get([
                    'hosts.id',
                    'hosts.name',
                    'hosts.status',
                    'hosts.last_seen_at',
                    'projects.id as project_id',
                    'projects.name as project_name',
                ])
                ->map(fn (Host $host): array => [
                    'id' => $host->id,
                    'name' => $host->name,
                    'status' => $host->status->value,
                    'last_seen_at' => $host->last_seen_at?->toIso8601String(),
                    'project_id' => (int) $host->project_id,
                    'project_name' => $host->project_name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function containerHealth(array $projectIds): array
    {
        return [
            'by_health_status' => Container::query()
                ->whereIn('project_id', $projectIds)
                ->selectRaw('COALESCE(health_status, status) as health_key, COUNT(id) as total')
                ->groupBy('health_key')
                ->pluck('total', 'health_key')
                ->map(fn ($total): int => (int) $total)
                ->all(),
            'problem_sample' => Container::query()
                ->join('projects', 'containers.project_id', '=', 'projects.id')
                ->whereIn('containers.project_id', $projectIds)
                ->where(function ($query): void {
                    $query->whereNotNull('containers.health_status')
                        ->where('containers.health_status', '!=', 'healthy')
                        ->orWhereIn('containers.status', ['exited', 'dead', 'restarting']);
                })
                ->orderByDesc('containers.last_seen_at')
                ->limit(self::TOP_PROJECTS_LIMIT)
                ->get([
                    'containers.id',
                    'containers.name',
                    'containers.status',
                    'containers.health_status',
                    'containers.last_seen_at',
                    'projects.id as project_id',
                    'projects.name as project_name',
                ])
                ->map(fn (Container $container): array => [
                    'id' => $container->id,
                    'name' => $container->name,
                    'status' => $container->status,
                    'health_status' => $container->health_status,
                    'last_seen_at' => $container->last_seen_at?->toIso8601String(),
                    'project_id' => (int) $container->project_id,
                    'project_name' => $container->project_name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function health(array $projectIds): array
    {
        return [
            'deltas' => [],
            'worst_projects' => Project::query()
                ->whereIn('id', $projectIds)
                ->orderByRaw('health_score IS NULL')
                ->orderBy('health_score')
                ->orderBy('id')
                ->limit(self::TOP_PROJECTS_LIMIT)
                ->get(['id', 'name', 'slug', 'health_score', 'last_activity_at'])
                ->map(fn (Project $project): array => $this->projectPayload($project))
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    private function activity(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        $websiteIds = Website::query()->whereIn('project_id', $projectIds)->pluck('id')->all();
        $hostIds = Host::query()->whereIn('project_id', $projectIds)->pluck('id')->all();
        $alertIds = Alert::query()->whereIn('project_id', $projectIds)->pluck('id')->all();

        $base = ActivityEvent::query()
            ->leftJoin('repositories', 'activity_events.repository_id', '=', 'repositories.id')
            ->leftJoin('projects as repo_projects', 'repositories.project_id', '=', 'repo_projects.id')
            ->where('activity_events.occurred_at', '>=', $utcStart)
            ->where('activity_events.occurred_at', '<', $utcEnd)
            ->where(function ($query) use ($projectIds, $websiteIds, $hostIds, $alertIds): void {
                $query->whereIn('repositories.project_id', $projectIds)
                    ->orWhere(function ($inner) use ($projectIds): void {
                        $inner->where('activity_events.source', 'monitoring')
                            ->whereIn('activity_events.metadata->project_id', $projectIds);
                    })
                    ->orWhere(function ($inner) use ($websiteIds): void {
                        $inner->where('activity_events.source', 'monitoring')
                            ->whereIn('activity_events.metadata->website_id', $websiteIds);
                    })
                    ->orWhere(function ($inner) use ($projectIds): void {
                        $inner->where('activity_events.source', 'hosts')
                            ->whereIn('activity_events.metadata->project_id', $projectIds);
                    })
                    ->orWhere(function ($inner) use ($hostIds): void {
                        $inner->where('activity_events.source', 'hosts')
                            ->whereIn('activity_events.metadata->host_id', $hostIds);
                    })
                    ->orWhere(function ($inner) use ($projectIds): void {
                        $inner->where('activity_events.source', 'alerts')
                            ->whereIn('activity_events.metadata->project_id', $projectIds);
                    })
                    ->orWhere(function ($inner) use ($alertIds): void {
                        $inner->where('activity_events.source', 'alerts')
                            ->whereIn('activity_events.metadata->alert_id', $alertIds);
                    });
            });

        return [
            'total' => (clone $base)->count('activity_events.id'),
            'by_project' => $this->activityByProject($projectIds, $utcStart, $utcEnd),
            'top_events' => (clone $base)
                ->with('repository:id,full_name,project_id')
                ->orderByDesc('activity_events.occurred_at')
                ->orderByDesc('activity_events.id')
                ->limit(self::ACTIVITY_EVENTS_LIMIT)
                ->get(['activity_events.*'])
                ->map(fn (ActivityEvent $event): array => [
                    'id' => $event->id,
                    'source' => $event->source,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity->value,
                    'title' => $event->title,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'project_id' => $event->repository?->project_id ?? $event->metadata['project_id'] ?? null,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, array{project_id: int, project_name: string, total: int}>
     */
    private function activityByProject(array $projectIds, CarbonInterface $utcStart, CarbonInterface $utcEnd): array
    {
        return Project::query()
            ->whereIn('projects.id', $projectIds)
            ->get(['id', 'name'])
            ->map(function (Project $project) use ($utcStart, $utcEnd): array {
                $total = ActivityEvent::query()
                    ->where('occurred_at', '>=', $utcStart)
                    ->where('occurred_at', '<', $utcEnd)
                    ->where(function ($query) use ($project): void {
                        $websiteIds = Website::query()->where('project_id', $project->id)->pluck('id')->all();
                        $hostIds = Host::query()->where('project_id', $project->id)->pluck('id')->all();
                        $alertIds = Alert::query()->where('project_id', $project->id)->pluck('id')->all();

                        $query->whereHas('repository', fn ($inner) => $inner->where('project_id', $project->id))
                            ->orWhere('metadata->project_id', $project->id)
                            ->orWhereIn('metadata->website_id', $websiteIds)
                            ->orWhereIn('metadata->host_id', $hostIds)
                            ->orWhereIn('metadata->alert_id', $alertIds);
                    })
                    ->count();

                return [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'total' => $total,
                ];
            })
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'health_score' => $project->health_score,
            'last_activity_at' => $project->last_activity_at?->toIso8601String(),
        ];
    }
}
