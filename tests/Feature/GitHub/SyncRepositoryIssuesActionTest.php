<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use App\Domain\GitHub\Actions\SyncRepositoryIssuesAction;
use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubConnection;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryIssuesActionTest extends TestCase
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

    public function test_inserts_issues_and_drops_pull_requests(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([
                [
                    'id' => 1,
                    'number' => 1,
                    'title' => 'First issue',
                    'state' => 'open',
                    'user' => ['login' => 'alice'],
                    'comments' => 3,
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
                [
                    'id' => 2,
                    'number' => 2,
                    'title' => 'Pull request masquerading as issue',
                    'state' => 'open',
                    'pull_request' => ['url' => 'https://api.github.com/...'],
                ],
                [
                    'id' => 3,
                    'number' => 3,
                    'title' => 'Closed issue',
                    'state' => 'closed',
                    'closed_at' => '2026-04-20T00:00:00Z',
                    'created_at' => '2026-04-05T00:00:00Z',
                    'updated_at' => '2026-04-20T00:00:00Z',
                ],
            ]),
        ]);

        $action = new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
        $count = $action->execute(
            $context['repository'],
            new GitHubClient($context['connection']),
        );

        $this->assertSame(2, $count);
        $this->assertSame(2, GithubIssue::query()->count());
        $this->assertDatabaseHas('github_issues', [
            'repository_id' => $context['repository']->id,
            'github_id' => 1,
            'title' => 'First issue',
            'state' => 'open',
        ]);
        $this->assertDatabaseMissing('github_issues', ['github_id' => 2]);
        $this->assertDatabaseHas('github_issues', [
            'github_id' => 3,
            'state' => 'closed',
        ]);
    }

    public function test_upserts_on_repeated_sync(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::sequence()
                ->push([
                    [
                        'id' => 1,
                        'number' => 1,
                        'title' => 'Original title',
                        'state' => 'open',
                        'comments' => 0,
                    ],
                ])
                ->push([
                    [
                        'id' => 1,
                        'number' => 1,
                        'title' => 'Updated title',
                        'state' => 'closed',
                        'comments' => 5,
                    ],
                ]),
        ]);

        $action = new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
        $action->execute($context['repository'], new GitHubClient($context['connection']));
        $action->execute($context['repository'], new GitHubClient($context['connection']));

        $this->assertSame(1, GithubIssue::query()->count());
        $this->assertDatabaseHas('github_issues', [
            'github_id' => 1,
            'title' => 'Updated title',
            'state' => 'closed',
            'comments_count' => 5,
        ]);
    }

    public function test_sends_since_query_when_repository_has_been_synced_before(): void
    {
        $context = $this->setUpRepository();
        $previous = now()->subHour();
        $context['repository']->forceFill(['issues_synced_at' => $previous])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([]),
        ]);

        $action = new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
        $action->execute($context['repository']->fresh(), new GitHubClient($context['connection']));

        Http::assertSent(function ($request) use ($previous) {
            return str_contains($request->url(), 'since=')
                && str_contains(rawurldecode($request->url()), $previous->toIso8601String());
        });
    }

    public function test_does_not_send_since_on_first_sync(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([]),
        ]);

        $action = new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
        $action->execute($context['repository'], new GitHubClient($context['connection']));

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'since='));
    }

    public function test_raises_unauthorized_exception_on_401(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        $action = new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);

        try {
            $action->execute($context['repository'], new GitHubClient($context['connection']));
            $this->fail('Expected GitHubApiException');
        } catch (GitHubApiException $e) {
            $this->assertTrue($e->isUnauthorized());
        }
    }
}
