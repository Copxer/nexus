<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubWorkflowRunAction;
use App\Domain\GitHub\Actions\SyncRepositoryWorkflowRunsAction;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryWorkflowRunsActionTest extends TestCase
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

    private function action(): SyncRepositoryWorkflowRunsAction
    {
        return new SyncRepositoryWorkflowRunsAction(new NormalizeGitHubWorkflowRunAction);
    }

    public function test_inserts_workflow_runs_from_payload(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response([
                'total_count' => 2,
                'workflow_runs' => [
                    [
                        'id' => 1,
                        'run_number' => 10,
                        'name' => 'CI',
                        'event' => 'push',
                        'status' => 'completed',
                        'conclusion' => 'success',
                        'head_branch' => 'main',
                        'head_sha' => 'a'.str_repeat('1', 39),
                        'actor' => ['login' => 'alice'],
                        'html_url' => 'https://github.com/o/r/actions/runs/1',
                        'run_started_at' => '2026-04-29T12:00:00Z',
                        'updated_at' => '2026-04-29T12:08:00Z',
                    ],
                    [
                        'id' => 2,
                        'run_number' => 11,
                        'name' => 'Deploy',
                        'event' => 'workflow_dispatch',
                        'status' => 'in_progress',
                        'conclusion' => null,
                        'head_branch' => 'main',
                        'head_sha' => 'b'.str_repeat('2', 39),
                        'html_url' => 'https://github.com/o/r/actions/runs/2',
                        'run_started_at' => '2026-04-29T13:00:00Z',
                        'updated_at' => '2026-04-29T13:01:00Z',
                    ],
                ],
            ]),
        ]);

        $count = $this->action()->execute(
            $context['repository'],
            new GitHubClient($context['connection']),
        );

        $this->assertSame(2, $count);
        $this->assertSame(2, WorkflowRun::query()->count());

        $first = WorkflowRun::query()->where('github_id', 1)->first();
        $this->assertSame('CI', $first->name);
        $this->assertSame('completed', $first->status->value);
        $this->assertSame('success', $first->conclusion->value);

        $second = WorkflowRun::query()->where('github_id', 2)->first();
        $this->assertSame('in_progress', $second->status->value);
        $this->assertNull($second->conclusion);
    }

    public function test_upsert_is_idempotent_on_replay(): void
    {
        $context = $this->setUpRepository();

        $payload = Http::response([
            'total_count' => 1,
            'workflow_runs' => [
                [
                    'id' => 42,
                    'run_number' => 1,
                    'name' => 'CI',
                    'event' => 'push',
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'head_branch' => 'main',
                    'head_sha' => 'a'.str_repeat('1', 39),
                    'html_url' => 'https://github.com/o/r/actions/runs/42',
                    'run_started_at' => '2026-04-29T12:00:00Z',
                    'updated_at' => '2026-04-29T12:08:00Z',
                ],
            ],
        ]);

        Http::fake(['api.github.com/repos/octocat/hello-world/actions/runs*' => $payload]);

        $this->action()->execute($context['repository'], new GitHubClient($context['connection']));
        $this->action()->execute($context['repository'], new GitHubClient($context['connection']));

        $this->assertSame(1, WorkflowRun::query()->where('github_id', 42)->count());
    }

    public function test_skips_malformed_entries(): void
    {
        $context = $this->setUpRepository();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/actions/runs*' => Http::response([
                'total_count' => 3,
                'workflow_runs' => [
                    [
                        'id' => 1,
                        'head_sha' => 'a'.str_repeat('1', 39),
                        'status' => 'completed',
                        'conclusion' => 'success',
                        'html_url' => 'https://github.com/o/r/actions/runs/1',
                    ],
                    // Missing head_sha — should be dropped by the normalizer.
                    ['id' => 2, 'status' => 'completed'],
                    // Not an array — should be filtered upstream.
                    'not-an-object',
                ],
            ]),
        ]);

        $count = $this->action()->execute(
            $context['repository'],
            new GitHubClient($context['connection']),
        );

        $this->assertSame(1, $count);
        $this->assertSame(1, WorkflowRun::query()->count());
    }
}
