<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction;
use App\Domain\GitHub\Actions\SyncRepositoryPullRequestsAction;
use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubConnection;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryPullRequestsActionTest extends TestCase
{
    use RefreshDatabase;

    private function setUpRepository(): array
    {
        $user = User::factory()->create();
        $connection = GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
        ]);

        return ['repository' => $repository, 'connection' => $connection];
    }

    public function test_inserts_pull_requests_with_derived_state(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response([
                [
                    'id' => 1,
                    'number' => 1,
                    'title' => 'Open PR',
                    'state' => 'open',
                    'user' => ['login' => 'alice'],
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/a'],
                    'merged' => false,
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
                [
                    'id' => 2,
                    'number' => 2,
                    'title' => 'Merged PR',
                    'state' => 'closed',
                    'merged_at' => '2026-04-20T00:00:00Z',
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/b'],
                    'created_at' => '2026-04-05T00:00:00Z',
                    'updated_at' => '2026-04-20T00:00:00Z',
                ],
                [
                    'id' => 3,
                    'number' => 3,
                    'title' => 'Closed PR',
                    'state' => 'closed',
                    'merged' => false,
                    'merged_at' => null,
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/c'],
                    'created_at' => '2026-04-10T00:00:00Z',
                    'updated_at' => '2026-04-21T00:00:00Z',
                ],
            ]),
        ]);

        $action = new SyncRepositoryPullRequestsAction(new NormalizeGitHubPullRequestAction);
        $count = $action->execute(
            $context['repository'],
            new GitHubClient($context['connection']),
        );

        $this->assertSame(3, $count);
        $this->assertSame(3, GithubPullRequest::query()->count());
        $this->assertDatabaseHas('github_pull_requests', [
            'github_id' => 1,
            'state' => 'open',
        ]);
        $this->assertDatabaseHas('github_pull_requests', [
            'github_id' => 2,
            'state' => 'merged',
            'merged' => true,
        ]);
        $this->assertDatabaseHas('github_pull_requests', [
            'github_id' => 3,
            'state' => 'closed',
            'merged' => false,
        ]);
    }

    public function test_upserts_on_repeated_sync(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::sequence()
                ->push([[
                    'id' => 1,
                    'number' => 1,
                    'title' => 'Original title',
                    'state' => 'open',
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/a'],
                    'comments' => 0,
                ]])
                ->push([[
                    'id' => 1,
                    'number' => 1,
                    'title' => 'Updated title',
                    'state' => 'closed',
                    'merged_at' => '2026-04-22T00:00:00Z',
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/a'],
                    'comments' => 5,
                ]]),
        ]);

        $action = new SyncRepositoryPullRequestsAction(new NormalizeGitHubPullRequestAction);
        $action->execute($context['repository'], new GitHubClient($context['connection']));
        $action->execute($context['repository'], new GitHubClient($context['connection']));

        $this->assertSame(1, GithubPullRequest::query()->count());
        $this->assertDatabaseHas('github_pull_requests', [
            'github_id' => 1,
            'title' => 'Updated title',
            'state' => 'merged',
            'comments_count' => 5,
        ]);
    }

    public function test_raises_unauthorized_exception_on_401(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        $action = new SyncRepositoryPullRequestsAction(new NormalizeGitHubPullRequestAction);

        try {
            $action->execute($context['repository'], new GitHubClient($context['connection']));
            $this->fail('Expected GitHubApiException');
        } catch (GitHubApiException $e) {
            $this->assertTrue($e->isUnauthorized());
        }
    }

    public function test_does_not_send_since_param(): void
    {
        // Unlike the issues sync, /pulls does not support `since` —
        // verify we never send it (otherwise GitHub silently ignores it
        // and we look like we're filtering when we're not).
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response([]),
        ]);

        $action = new SyncRepositoryPullRequestsAction(new NormalizeGitHubPullRequestAction);
        $action->execute($context['repository'], new GitHubClient($context['connection']));

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'since='));
    }
}
