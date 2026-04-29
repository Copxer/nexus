<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(): GitHubClient
    {
        $user = User::factory()->create();
        $connection = GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'connected_at' => now(),
        ]);

        return new GitHubClient($connection);
    }

    public function test_list_repositories_returns_parsed_payload(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'octocat/hello-world'],
                ['id' => 2, 'full_name' => 'octocat/spoon-knife'],
            ]),
        ]);

        $repos = $this->client()->listRepositories();

        $this->assertCount(2, $repos);
        $this->assertSame('octocat/hello-world', $repos[0]['full_name']);
    }

    public function test_list_repositories_throws_unauthorized_on_401(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        try {
            $this->client()->listRepositories();
            $this->fail('Expected GitHubApiException.');
        } catch (GitHubApiException $e) {
            $this->assertTrue($e->isUnauthorized());
            $this->assertSame(401, $e->statusCode);
        }
    }

    public function test_list_repositories_detects_rate_limit(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response(
                ['message' => 'API rate limit exceeded for user.'],
                403,
            ),
        ]);

        try {
            $this->client()->listRepositories();
            $this->fail('Expected GitHubApiException.');
        } catch (GitHubApiException $e) {
            $this->assertTrue($e->wasRateLimited());
            $this->assertSame(403, $e->statusCode);
        }
    }

    public function test_fetch_repository_returns_repo_metadata(): void
    {
        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response([
                'id' => 1,
                'full_name' => 'octocat/hello-world',
                'description' => 'My first GitHub repo',
                'default_branch' => 'main',
                'language' => 'Ruby',
                'stargazers_count' => 1234,
                'forks_count' => 56,
                'open_issues_count' => 7,
                'pushed_at' => '2024-12-01T00:00:00Z',
            ]),
        ]);

        $repo = $this->client()->fetchRepository('octocat/hello-world');

        $this->assertSame('octocat/hello-world', $repo['full_name']);
        $this->assertSame(1234, $repo['stargazers_count']);
    }

    public function test_fetch_repository_throws_on_404(): void
    {
        Http::fake([
            'api.github.com/repos/octocat/missing' => Http::response(
                ['message' => 'Not Found'],
                404,
            ),
        ]);

        $this->expectException(GitHubApiException::class);
        $this->client()->fetchRepository('octocat/missing');
    }

    public function test_request_pins_api_version_and_user_agent_headers(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response([]),
        ]);

        $this->client()->listRepositories();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-GitHub-Api-Version', '2022-11-28')
                && $request->hasHeader('Accept', 'application/vnd.github+json')
                && $request->hasHeader('User-Agent', 'Nexus-Control-Center');
        });
    }
}
