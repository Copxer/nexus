<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction;
use App\Domain\GitHub\Actions\SyncRepositoryPullRequestsAction;
use App\Domain\GitHub\Jobs\SyncRepositoryPullRequestsJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryPullRequestsJobTest extends TestCase
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
            'prs_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        return ['user' => $user, 'repository' => $repository];
    }

    private function action(): SyncRepositoryPullRequestsAction
    {
        return new SyncRepositoryPullRequestsAction(new NormalizeGitHubPullRequestAction);
    }

    public function test_handle_syncs_prs_and_flips_status_to_synced(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response([
                [
                    'id' => 1,
                    'number' => 1,
                    'title' => 'First PR',
                    'state' => 'open',
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/a'],
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
            ]),
        ]);

        (new SyncRepositoryPullRequestsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->prs_sync_status);
        $this->assertNotNull($repo->prs_synced_at);
        $this->assertSame(1, GithubPullRequest::query()->count());
    }

    public function test_handle_marks_failed_and_expires_connection_on_401(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        (new SyncRepositoryPullRequestsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->prs_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertSame('', $connection->access_token);
        $this->assertFalse($connection->isAccessTokenValid());
    }

    public function test_handle_marks_failed_on_generic_error(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncRepositoryPullRequestsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->prs_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertNotSame('', $connection->access_token);
        $this->assertTrue($connection->isAccessTokenValid());
    }

    public function test_handle_preserves_prs_synced_at_on_failure(): void
    {
        $context = $this->setUpProjectWithConnection();
        $priorSync = now()->subHours(3);
        $context['repository']->forceFill(['prs_synced_at' => $priorSync])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/pulls*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncRepositoryPullRequestsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->prs_sync_status);
        $this->assertEquals(
            $priorSync->toIso8601String(),
            $repo->prs_synced_at->toIso8601String(),
        );
    }

    public function test_handle_marks_failed_when_owner_has_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'prs_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncRepositoryPullRequestsJob($repository->id))->handle($this->action());

        $this->assertSame(RepositorySyncStatus::Failed, $repository->fresh()->prs_sync_status);
    }

    public function test_handle_is_a_no_op_when_repository_is_missing(): void
    {
        (new SyncRepositoryPullRequestsJob(999_999))->handle($this->action());

        $this->assertSame(0, Repository::query()->count());
        $this->assertSame(0, GithubPullRequest::query()->count());
    }
}
