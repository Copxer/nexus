<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use App\Domain\GitHub\Actions\SyncRepositoryIssuesAction;
use App\Domain\GitHub\Jobs\SyncRepositoryIssuesJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryIssuesJobTest extends TestCase
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
            'issues_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        return ['user' => $user, 'repository' => $repository];
    }

    private function action(): SyncRepositoryIssuesAction
    {
        return new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
    }

    public function test_handle_syncs_issues_and_flips_status_to_synced(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([
                [
                    'id' => 1,
                    'number' => 1,
                    'title' => 'First',
                    'state' => 'open',
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
            ]),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->issues_sync_status);
        $this->assertNotNull($repo->issues_synced_at);
        $this->assertSame(1, GithubIssue::query()->count());
    }

    public function test_handle_marks_failed_and_expires_connection_on_401(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->issues_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertSame('', $connection->access_token);
        $this->assertFalse($connection->isAccessTokenValid());
    }

    public function test_handle_marks_failed_on_generic_error(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->issues_sync_status);

        // 500 is not unauthorized — connection stays valid.
        $connection = $context['user']->fresh()->githubConnection;
        $this->assertNotSame('', $connection->access_token);
        $this->assertTrue($connection->isAccessTokenValid());
    }

    public function test_handle_preserves_issues_synced_at_on_failure(): void
    {
        $context = $this->setUpProjectWithConnection();
        $priorSync = now()->subHours(3);
        $context['repository']->forceFill(['issues_synced_at' => $priorSync])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->issues_sync_status);
        $this->assertEquals(
            $priorSync->toIso8601String(),
            $repo->issues_synced_at->toIso8601String(),
        );
    }

    public function test_handle_marks_failed_when_owner_has_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'issues_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncRepositoryIssuesJob($repository->id))->handle($this->action());

        $this->assertSame(RepositorySyncStatus::Failed, $repository->fresh()->issues_sync_status);
    }

    public function test_handle_is_a_no_op_when_repository_is_missing(): void
    {
        (new SyncRepositoryIssuesJob(999_999))->handle($this->action());

        $this->assertSame(0, Repository::query()->count());
        $this->assertSame(0, GithubIssue::query()->count());
    }
}
