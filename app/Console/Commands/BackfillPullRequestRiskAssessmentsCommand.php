<?php

namespace App\Console\Commands;

use App\Domain\AiInsights\Jobs\GeneratePullRequestRiskAssessmentJob;
use App\Enums\GithubPullRequestState;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillPullRequestRiskAssessmentsCommand extends Command
{
    protected $signature = 'ai-insights:backfill-pr-risk
        {--user= : User id or email to scope the backfill}
        {--project= : Project id or slug to scope the backfill}
        {--repository= : Repository id or full name to scope the backfill}';

    protected $description = 'Queue AI PR risk assessment generation for scoped open pull requests.';

    public function handle(): int
    {
        if (! $this->option('user') && ! $this->option('project') && ! $this->option('repository')) {
            $this->error('Provide at least one scope option: --user, --project, or --repository.');

            return self::FAILURE;
        }

        if (! config('services.llm.enabled', false)) {
            $this->info('AI features are disabled; no PR risk backfill jobs were queued.');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if ($user === false) {
            return self::FAILURE;
        }

        $project = $this->resolveProject($user);
        if ($project === false) {
            return self::FAILURE;
        }

        $repository = $this->resolveRepository($user, $project);
        if ($repository === false) {
            return self::FAILURE;
        }

        $queued = 0;

        $this->pullRequestQuery($user, $project, $repository)
            ->chunkById(100, function ($pullRequests) use (&$queued): void {
                foreach ($pullRequests as $pullRequest) {
                    GeneratePullRequestRiskAssessmentJob::dispatch($pullRequest->id);
                    $queued++;
                }
            }, 'github_pull_requests.id', 'id');

        $this->info("Queued {$queued} PR risk assessment backfill job(s).");

        return self::SUCCESS;
    }

    private function resolveUser(): User|false|null
    {
        $value = $this->option('user');
        if (! $value) {
            return null;
        }

        $user = User::query()
            ->where('id', $value)
            ->orWhere('email', $value)
            ->first();

        if (! $user) {
            $this->error('User scope not found.');

            return false;
        }

        return $user;
    }

    private function resolveProject(?User $user): Project|false|null
    {
        $value = $this->option('project');
        if (! $value) {
            return null;
        }

        $project = Project::query()
            ->when($user, fn (Builder $query) => $query->where('owner_user_id', $user->id))
            ->where(function (Builder $query) use ($value): void {
                $query->where('id', $value)
                    ->orWhere('slug', $value);
            })
            ->first();

        if (! $project) {
            $this->error('Project scope not found.');

            return false;
        }

        return $project;
    }

    private function resolveRepository(?User $user, ?Project $project): Repository|false|null
    {
        $value = $this->option('repository');
        if (! $value) {
            return null;
        }

        $repository = Repository::query()
            ->where(function (Builder $query) use ($value): void {
                $query->where('id', $value)
                    ->orWhere('full_name', $value);
            })
            ->when($project, fn (Builder $query) => $query->where('project_id', $project->id))
            ->when($user, fn (Builder $query) => $query->whereHas('project', fn (Builder $projectQuery) => $projectQuery->where('owner_user_id', $user->id)))
            ->first();

        if (! $repository) {
            $this->error('Repository scope not found.');

            return false;
        }

        return $repository;
    }

    /** @return Builder<GithubPullRequest> */
    private function pullRequestQuery(?User $user, ?Project $project, ?Repository $repository): Builder
    {
        return GithubPullRequest::query()
            ->select('github_pull_requests.*')
            ->where('state', GithubPullRequestState::Open->value)
            ->whereNull('closed_at_github')
            ->whereNull('merged_at')
            ->whereHas('repository.project', function (Builder $query) use ($user): void {
                if ($user) {
                    $query->where('owner_user_id', $user->id);
                }
            })
            ->when($project, fn (Builder $query) => $query->whereHas('repository', fn (Builder $repositoryQuery) => $repositoryQuery->where('project_id', $project->id)))
            ->when($repository, fn (Builder $query) => $query->where('repository_id', $repository->id))
            ->orderBy('github_pull_requests.id');
    }
}
