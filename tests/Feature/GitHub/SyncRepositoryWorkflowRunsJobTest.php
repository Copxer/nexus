<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubWorkflowRunAction;
use App\Domain\GitHub\Actions\SyncRepositoryWorkflowRunsAction;
use App\Domain\GitHub\Jobs\SyncRepositoryWorkflowRunsJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryWorkflowRunsJobTest extends TestCase
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
            'workflow_runs_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        return ['user' => $user, 'repository' => $repository];
    }

    private function action(): SyncRepositoryWorkflowRunsAction
    {
        return new SyncRepositoryWorkflowRunsAction(new NormalizeGitHubWorkflowRunAction);
    }

    public function test_handle_syncs_runs_and_flips_status_to_synced(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response([
                'total_count' => 1,
                'workflow_runs' => [
                    [
                        'id' => 1,
                        'run_number' => 1,
                        'name' => 'CI',
                        'event' => 'push',
                        'status' => 'completed',
                        'conclusion' => 'success',
                        'head_branch' => 'main',
                        'head_sha' => 'a'.str_repeat('1', 39),
                        'html_url' => 'https://github.com/o/r/actions/runs/1',
                        'run_started_at' => '2026-04-29T12:00:00Z',
                        'updated_at' => '2026-04-29T12:08:00Z',
                    ],
                ],
            ]),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->workflow_runs_sync_status);
        $this->assertNotNull($repo->workflow_runs_synced_at);
        $this->assertSame(1, WorkflowRun::query()->count());
    }

    public function test_handle_marks_failed_and_expires_connection_on_401(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->workflow_runs_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertSame('', $connection->access_token);
        $this->assertFalse($connection->isAccessTokenValid());
    }

    public function test_handle_marks_failed_on_generic_error(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response(
                ['message' => 'Server error'],
                500,
            ),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->workflow_runs_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertNotSame('', $connection->access_token);
        $this->assertTrue($connection->isAccessTokenValid());
    }

    public function test_handle_preserves_workflow_runs_synced_at_on_failure(): void
    {
        $context = $this->setUpProjectWithConnection();
        $priorSync = now()->subHours(3);
        $context['repository']->forceFill(['workflow_runs_synced_at' => $priorSync])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->workflow_runs_sync_status);
        $this->assertEquals(
            $priorSync->toIso8601String(),
            $repo->workflow_runs_synced_at->toIso8601String(),
        );
    }

    public function test_handle_persists_sync_error_and_failed_at_on_api_failure(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response(
                ['message' => 'Not Found'],
                404,
            ),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->workflow_runs_sync_status);
        $this->assertNotNull($repo->workflow_runs_sync_error);
        $this->assertStringContainsString('404', $repo->workflow_runs_sync_error);
        $this->assertNotNull($repo->workflow_runs_sync_failed_at);
    }

    public function test_handle_clears_sync_error_after_successful_resync(): void
    {
        $context = $this->setUpProjectWithConnection();

        $context['repository']->forceFill([
            'workflow_runs_sync_status' => RepositorySyncStatus::Failed->value,
            'workflow_runs_sync_error' => 'Stale failure message',
            'workflow_runs_sync_failed_at' => now()->subMinutes(10),
        ])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response([
                'total_count' => 0,
                'workflow_runs' => [],
            ]),
        ]);

        (new SyncRepositoryWorkflowRunsJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->workflow_runs_sync_status);
        $this->assertNull($repo->workflow_runs_sync_error);
        $this->assertNull($repo->workflow_runs_sync_failed_at);
    }

    public function test_handle_marks_failed_when_owner_has_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'workflow_runs_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncRepositoryWorkflowRunsJob($repository->id))->handle($this->action());

        $repo = $repository->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->workflow_runs_sync_status);
        $this->assertNotNull($repo->workflow_runs_sync_error);
        $this->assertStringContainsString('connection', strtolower($repo->workflow_runs_sync_error));
    }

    public function test_handle_is_a_no_op_when_repository_is_missing(): void
    {
        (new SyncRepositoryWorkflowRunsJob(999_999))->handle($this->action());

        $this->assertSame(0, Repository::query()->count());
        $this->assertSame(0, WorkflowRun::query()->count());
    }
}
