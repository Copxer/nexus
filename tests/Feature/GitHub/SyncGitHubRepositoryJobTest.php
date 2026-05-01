<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Domain\GitHub\Jobs\SyncRepositoryIssuesJob;
use App\Domain\GitHub\Jobs\SyncRepositoryPullRequestsJob;
use App\Domain\GitHub\Jobs\SyncRepositoryWorkflowRunsJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_handle_preserves_last_synced_at_on_failure(): void
    {
        // `last_synced_at` is the contract Settings surfaces as
        // "Last sync N min ago" — a failed run must NOT bump it,
        // otherwise a repo that's never synced successfully would
        // misleadingly read as recently synced.
        $context = $this->setUpProjectWithConnection();
        $priorSync = now()->subHours(3);
        $context['repository']->forceFill(['last_synced_at' => $priorSync])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => 'Server error'],
                500,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->sync_status);
        $this->assertEquals(
            $priorSync->toIso8601String(),
            $repo->last_synced_at->toIso8601String(),
        );
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

    public function test_handle_dispatches_all_three_child_syncs_on_happy_path(): void
    {
        Queue::fake();
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response([
                'id' => 1234,
                'default_branch' => 'main',
                'visibility' => 'public',
                'pushed_at' => '2026-04-29T00:00:00Z',
                'html_url' => 'https://github.com/octocat/hello-world',
            ]),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        Queue::assertPushed(
            SyncRepositoryIssuesJob::class,
            fn (SyncRepositoryIssuesJob $job) => $job->repositoryId === $context['repository']->id,
        );
        Queue::assertPushed(
            SyncRepositoryPullRequestsJob::class,
            fn (SyncRepositoryPullRequestsJob $job) => $job->repositoryId === $context['repository']->id,
        );
        Queue::assertPushed(
            SyncRepositoryWorkflowRunsJob::class,
            fn (SyncRepositoryWorkflowRunsJob $job) => $job->repositoryId === $context['repository']->id,
        );
    }

    public function test_handle_does_not_dispatch_child_syncs_on_failure(): void
    {
        Queue::fake();
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        Queue::assertNotPushed(SyncRepositoryIssuesJob::class);
        Queue::assertNotPushed(SyncRepositoryPullRequestsJob::class);
        Queue::assertNotPushed(SyncRepositoryWorkflowRunsJob::class);
    }

    public function test_handle_persists_sync_error_and_failed_at_on_api_failure(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => 'Not Found'],
                404,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->sync_status);
        $this->assertNotNull($repo->sync_error);
        $this->assertStringContainsString('404', $repo->sync_error);
        $this->assertNotNull($repo->sync_failed_at);
    }

    public function test_handle_persists_sync_error_when_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncGitHubRepositoryJob($repository->id))->handle();

        $repo = $repository->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->sync_status);
        $this->assertNotNull($repo->sync_error);
        $this->assertStringContainsString('connection', strtolower($repo->sync_error));
        $this->assertNotNull($repo->sync_failed_at);
    }

    public function test_handle_clears_sync_error_after_successful_resync(): void
    {
        $context = $this->setUpProjectWithConnection();

        // Seed a previous failure so we can verify it's cleared.
        $context['repository']->forceFill([
            'sync_status' => RepositorySyncStatus::Failed->value,
            'sync_error' => 'GitHub request failed: HTTP 500 Server error',
            'sync_failed_at' => now()->subMinutes(10),
        ])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response([
                'id' => 1234,
                'default_branch' => 'main',
                'visibility' => 'public',
                'pushed_at' => '2026-04-29T00:00:00Z',
                'html_url' => 'https://github.com/octocat/hello-world',
            ]),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->sync_status);
        $this->assertNull($repo->sync_error);
        $this->assertNull($repo->sync_failed_at);
    }

    public function test_handle_truncates_long_sync_error_messages(): void
    {
        $context = $this->setUpProjectWithConnection();

        $longMessage = str_repeat('x', 800);

        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response(
                ['message' => $longMessage],
                500,
            ),
        ]);

        (new SyncGitHubRepositoryJob($context['repository']->id))->handle();

        $repo = $context['repository']->fresh();
        $this->assertNotNull($repo->sync_error);
        // Str::limit($x, 500, '…') yields exactly 500 chars + the '…'
        // ellipsis when truncation kicked in.
        $this->assertSame(501, mb_strlen($repo->sync_error));
        $this->assertStringEndsWith('…', $repo->sync_error);
    }
}
