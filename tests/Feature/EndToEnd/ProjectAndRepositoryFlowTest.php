<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\GitHub\Actions\ImportRepositoryAction;
use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 040 — end-to-end: verified user creates a project, imports
 * a repository, sync runs against GitHub (Http::fake), repository
 * lands in synced state with the real metadata.
 *
 * Pins the contract that:
 *   - `POST /projects` creates a project owned by the caller.
 *   - `ImportRepositoryAction` creates a Repository in
 *     `pending` state + dispatches `SyncGitHubRepositoryJob`.
 *   - The job updates the Repository to `synced` with fields
 *     from GitHub's `/repos/{owner}/{repo}` response.
 *
 * Network mocked via `Http::fake` — every other action runs through
 * the real container resolution + queue dispatch (Bus::fake captures
 * the dispatch; we run the job inline to assert the terminal state).
 */
class ProjectAndRepositoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_through_repository_sync(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();

        // GitHub connection so the sync job has a token to use.
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'spec040',
            'access_token' => 'gho_test_token',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);

        // 1. User creates a project.
        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Spec 040 Project',
            'description' => 'End-to-end demo project',
            'status' => 'active',
            'priority' => 'medium',
            'environment' => 'production',
            'color' => 'cyan',
            'icon' => 'HeartPulse',
        ])->assertRedirect();

        $project = Project::query()
            ->where('owner_user_id', $user->id)
            ->where('name', 'Spec 040 Project')
            ->firstOrFail();

        // 2. Import a repository — controller surface is gated by an
        //    OAuth picker UI; the action below is what that picker
        //    posts into. Calling the action directly skips the
        //    picker but exercises the rest of the chain.
        $repo = app(ImportRepositoryAction::class)->execute(
            $project,
            'octocat/hello-world',
        );

        $this->assertSame($project->id, $repo->project_id);
        $this->assertSame('octocat/hello-world', $repo->full_name);
        Bus::assertDispatched(
            SyncGitHubRepositoryJob::class,
            fn (SyncGitHubRepositoryJob $job) => $job->repositoryId === $repo->id,
        );

        // 3. Run the sync job inline against a faked GitHub API.
        Http::fake([
            'api.github.com/repos/octocat/hello-world' => Http::response([
                'id' => 12345,
                'default_branch' => 'main',
                'visibility' => 'public',
                'language' => 'PHP',
                'description' => 'Hello world',
                'html_url' => 'https://github.com/octocat/hello-world',
                'stargazers_count' => 1337,
                'forks_count' => 42,
                'open_issues_count' => 3,
                'pushed_at' => now()->subHour()->toIso8601String(),
            ]),
        ]);

        (new SyncGitHubRepositoryJob($repo->id))->handle();

        $synced = Repository::query()->find($repo->id);
        $this->assertSame('synced', $synced->sync_status->value);
        $this->assertSame('PHP', $synced->language);
        $this->assertSame(1337, $synced->stars_count);
        $this->assertNotNull($synced->last_synced_at);
    }
}
