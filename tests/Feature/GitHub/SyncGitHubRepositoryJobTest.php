<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncGitHubRepositoryJobTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProjectWithConnection(): array
    {
        $user = User::factory()->create();
        GithubConnection::query()->create([
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
            'sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        return ['user' => $user, 'repository' => $repository];
    }

    public function test_handle_updates_the_repository_metadata_on_happy_path(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response([
                'id' => 1234,
                'description' => 'Hello world from GitHub.',
                'default_branch' => 'develop',
                'visibility' => 'public',
                'language' => 'TypeScript',
                'stargazers_count' => 482,
                'forks_count' => 18,
                'open_issues_count' => 5,
                'pushed_at' => '2026-04-29T00:00:00Z',
                'html_url' => 'https://github.com/octocat/hello-world',
            ]),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->sync_status);
        $this->assertSame('TypeScript', $repo->language);
        $this->assertSame(482, $repo->stars_count);
        $this->assertSame('develop', $repo->default_branch);
        $this->assertNotNull($repo->last_synced_at);
        $this->assertNotNull($repo->last_pushed_at);
    }

    public function test_handle_marks_failed_and_expires_connection_on_401(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertSame('', $connection->access_token);
        $this->assertFalse($connection->isAccessTokenValid());
    }

    public function test_handle_marks_failed_on_generic_error(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => 'Server error'],
                500,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->sync_status);

        // 500 is not unauthorized — connection should NOT be expired.
        $connection = $context['user']->fresh()->githubConnection;
        $this->assertNotSame('', $connection->access_token);
        $this->assertTrue($connection->isAccessTokenValid());
    }

    public function test_handle_marks_failed_when_owner_has_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncGitHubRepositoryJob($repository->id))->handle();

        $this->assertSame(RepositorySyncStatus::Failed, $repository->fresh()->sync_status);
    }

    public function test_handle_is_a_no_op_when_repository_is_missing(): void
    {
        // No exception, no DB writes — the job just returns early.
        (new SyncGitHubRepositoryJob(999_999))->handle();

        $this->assertSame(0, Repository::query()->count());
    }
}
