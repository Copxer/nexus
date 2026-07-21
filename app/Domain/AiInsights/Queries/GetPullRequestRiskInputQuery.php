<?php

namespace App\Domain\AiInsights\Queries;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\HealthScoreBand;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Alert;
use App\Models\GithubPullRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class GetPullRequestRiskInputQuery
{
    public const BODY_PREVIEW_LIMIT = 500;

    public const FAILED_WORKFLOWS_LIMIT = 5;

    public const ALERTS_LIMIT = 5;

    /** @return array<string, mixed>|null */
    public function execute(User $user, GithubPullRequest $pullRequest): ?array
    {
        $pullRequest = GithubPullRequest::query()
            ->with('repository.project')
            ->whereKey($pullRequest->id)
            ->whereHas('repository.project', fn ($query) => $query->where('owner_user_id', $user->id))
            ->first();

        if (! $pullRequest?->repository?->project) {
            return null;
        }

        $repository = $pullRequest->repository;
        $project = $repository->project;

        return [
            'snapshot_version' => 'pr-risk-input-v1',
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'priority' => $project->priority?->value,
                'health_score' => $project->health_score,
                'health_band' => $project->health_score === null
                    ? null
                    : HealthScoreBand::fromScore($project->health_score)->value,
            ],
            'repository' => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
                'default_branch' => $repository->default_branch,
                'visibility' => $repository->visibility,
                'language' => $repository->language,
                'sync_status' => $repository->sync_status?->value,
                'prs_sync_status' => $repository->prs_sync_status?->value,
                'workflow_runs_sync_status' => $repository->workflow_runs_sync_status?->value,
            ],
            'pull_request' => $this->pullRequestFacts($pullRequest, $repository),
            'recent_failed_workflows' => $this->recentFailedWorkflows($repository, $pullRequest->head_branch),
            'active_alerts' => $this->activeAlerts($project->id),
        ];
    }

    /** @return array<string, mixed> */
    private function pullRequestFacts(GithubPullRequest $pullRequest, Repository $repository): array
    {
        $createdAt = $pullRequest->created_at_github;

        return [
            'id' => $pullRequest->id,
            'number' => $pullRequest->number,
            'title' => $this->sanitizeText($pullRequest->title),
            'body_preview' => Str::limit($this->sanitizeText($pullRequest->body_preview), self::BODY_PREVIEW_LIMIT, ''),
            'state' => $pullRequest->state?->value,
            'author_login' => $pullRequest->author_login,
            'base_branch' => $pullRequest->base_branch,
            'head_branch' => $pullRequest->head_branch,
            'draft' => $pullRequest->draft,
            'merged' => $pullRequest->merged,
            'additions' => $pullRequest->additions,
            'deletions' => $pullRequest->deletions,
            'changed_files' => $pullRequest->changed_files,
            'comments_count' => $pullRequest->comments_count,
            'review_comments_count' => $pullRequest->review_comments_count,
            'age_days' => $createdAt === null ? null : (int) $createdAt->diffInDays(CarbonImmutable::now('UTC')),
            'stale' => $pullRequest->updated_at_github !== null
                && $pullRequest->updated_at_github->lt(CarbonImmutable::now('UTC')->subDays(7)),
            'created_at' => $pullRequest->created_at_github?->toIso8601String(),
            'updated_at' => $pullRequest->updated_at_github?->toIso8601String(),
            'html_url' => "https://github.com/{$repository->full_name}/pull/{$pullRequest->number}",
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentFailedWorkflows(Repository $repository, ?string $branch): array
    {
        $failedConclusions = [
            WorkflowRunConclusion::Failure->value,
            WorkflowRunConclusion::TimedOut->value,
            WorkflowRunConclusion::ActionRequired->value,
        ];

        return WorkflowRun::query()
            ->where('repository_id', $repository->id)
            ->where('status', WorkflowRunStatus::Completed->value)
            ->whereIn('conclusion', $failedConclusions)
            ->when($branch !== null && $branch !== '', fn ($query) => $query->where('head_branch', $branch))
            ->orderByDesc('run_completed_at')
            ->orderByDesc('id')
            ->limit(self::FAILED_WORKFLOWS_LIMIT)
            ->get(['id', 'run_number', 'name', 'event', 'conclusion', 'head_branch', 'run_completed_at'])
            ->map(fn (WorkflowRun $run): array => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'name' => $run->name,
                'event' => $run->event,
                'conclusion' => $run->conclusion?->value,
                'head_branch' => $run->head_branch,
                'completed_at' => $run->run_completed_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function activeAlerts(int $projectId): array
    {
        return Alert::query()
            ->where('project_id', $projectId)
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
            ->whereIn('severity', [AlertSeverity::Critical->value, AlertSeverity::Warning->value])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(self::ALERTS_LIMIT)
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
            ->all();
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\b[A-Z0-9_]*(TOKEN|SECRET|PASSWORD|KEY)[A-Z0-9_]*\s*[:=]\s*[^\s,;]+/i', '[redacted]', $value);
    }
}
