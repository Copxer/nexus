<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AiInsights\Jobs\GeneratePullRequestRiskAssessmentJob;
use App\Enums\GithubPullRequestState;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PullRequestRiskBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_only_open_pull_requests_for_the_scoped_user(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $other = User::factory()->create();
        $openPullRequest = $this->pullRequestForUser($owner, GithubPullRequestState::Open);
        $closedPullRequest = $this->pullRequestForUser($owner, GithubPullRequestState::Closed);
        $mergedPullRequest = $this->pullRequestForUser($owner, GithubPullRequestState::Merged);
        $otherPullRequest = $this->pullRequestForUser($other, GithubPullRequestState::Open);

        $this->artisan('ai-insights:backfill-pr-risk', ['--user' => 'owner@example.com'])
            ->expectsOutput('Queued 1 PR risk assessment backfill job(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => $job->pullRequestId === $openPullRequest->id,
        );
        Queue::assertNotPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => in_array($job->pullRequestId, [
                $closedPullRequest->id,
                $mergedPullRequest->id,
                $otherPullRequest->id,
            ], true),
        );
    }

    public function test_repository_scope_is_constrained_by_the_user_scope(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $repository = $this->repositoryForUser($owner, ['full_name' => 'octocat/hello-world']);
        $openPullRequest = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubPullRequestState::Open->value,
            'closed_at_github' => null,
            'merged_at' => null,
        ]);
        $otherPullRequest = $this->pullRequestForUser($other, GithubPullRequestState::Open);

        $this->artisan('ai-insights:backfill-pr-risk', [
            '--user' => (string) $owner->id,
            '--repository' => 'octocat/hello-world',
        ])
            ->expectsOutput('Queued 1 PR risk assessment backfill job(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => $job->pullRequestId === $openPullRequest->id,
        );
        Queue::assertNotPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => $job->pullRequestId === $otherPullRequest->id,
        );
    }

    public function test_project_scope_does_not_include_other_projects_for_the_same_user(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id, 'slug' => 'api']);
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $scopedPullRequest = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubPullRequestState::Open->value,
            'closed_at_github' => null,
            'merged_at' => null,
        ]);
        $otherProjectPullRequest = $this->pullRequestForUser($owner, GithubPullRequestState::Open);

        $this->artisan('ai-insights:backfill-pr-risk', [
            '--user' => (string) $owner->id,
            '--project' => 'api',
        ])
            ->expectsOutput('Queued 1 PR risk assessment backfill job(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => $job->pullRequestId === $scopedPullRequest->id,
        );
        Queue::assertNotPushed(
            GeneratePullRequestRiskAssessmentJob::class,
            fn (GeneratePullRequestRiskAssessmentJob $job): bool => $job->pullRequestId === $otherProjectPullRequest->id,
        );
    }

    public function test_ai_gate_prevents_backfill_dispatch(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => false]);
        $owner = User::factory()->create();
        $this->pullRequestForUser($owner, GithubPullRequestState::Open);

        $this->artisan('ai-insights:backfill-pr-risk', ['--user' => (string) $owner->id])
            ->expectsOutput('AI features are disabled; no PR risk backfill jobs were queued.')
            ->assertSuccessful();

        Queue::assertNotPushed(GeneratePullRequestRiskAssessmentJob::class);
    }

    public function test_requires_an_explicit_scope(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);

        $this->artisan('ai-insights:backfill-pr-risk')
            ->expectsOutput('Provide at least one scope option: --user, --project, or --repository.')
            ->assertFailed();

        Queue::assertNotPushed(GeneratePullRequestRiskAssessmentJob::class);
    }

    private function pullRequestForUser(User $user, GithubPullRequestState $state): GithubPullRequest
    {
        $isOpen = $state === GithubPullRequestState::Open;

        return GithubPullRequest::factory()->create([
            'repository_id' => $this->repositoryForUser($user)->id,
            'state' => $state->value,
            'closed_at_github' => $isOpen ? null : now(),
            'merged_at' => $state === GithubPullRequestState::Merged ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function repositoryForUser(User $user, array $attributes = []): Repository
    {
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        return Repository::factory()->create([
            ...$attributes,
            'project_id' => $project->id,
        ]);
    }
}
