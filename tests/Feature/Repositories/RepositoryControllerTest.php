<?php

namespace Tests\Feature\Repositories;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Enums\GithubIssueState;
use App\Enums\GithubPullRequestState;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RepositoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_lists_all_repositories(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Repository::factory()->count(3)->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->get(route('repositories.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Repositories/Index')
                    ->has('repositories', 3)
            );
    }

    public function test_show_renders_a_repository_for_a_verified_user(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-web',
        ]);

        $this->actingAs($user)
            ->get(route('repositories.show', $repo))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Repositories/Show')
                    ->where('repository.full_name', 'nexus-org/nexus-web')
                    ->where('canDelete', true)
                    ->where('canSync', true)
                    ->has('issues')
                    ->has('issuesSync')
            );
    }

    public function test_show_returns_issues_in_the_payload(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-web',
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $repo->id,
            'number' => 42,
            'title' => 'Login bug',
            'state' => GithubIssueState::Open->value,
        ]);

        $this->actingAs($user)
            ->get(route('repositories.show', $repo))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.number', 42)
                    ->where('issues.0.title', 'Login bug')
                    ->where('issues.0.state', 'open')
            );
    }

    public function test_show_returns_pull_requests_in_the_payload(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-web',
        ]);
        GithubPullRequest::factory()->create([
            'repository_id' => $repo->id,
            'number' => 7,
            'title' => 'Refactor auth',
            'state' => GithubPullRequestState::Open->value,
            'base_branch' => 'main',
            'head_branch' => 'topic/auth',
            'merged' => false,
        ]);

        $this->actingAs($user)
            ->get(route('repositories.show', $repo))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('pullRequests', 1)
                    ->where('pullRequests.0.number', 7)
                    ->where('pullRequests.0.title', 'Refactor auth')
                    ->where('pullRequests.0.state', 'open')
                    ->where('pullRequests.0.base_branch', 'main')
                    ->where('pullRequests.0.head_branch', 'topic/auth')
                    ->has('pullRequestsSync')
            );
    }

    public function test_show_exposes_sync_errors_in_inertia_props(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-web',
            'sync_status' => 'failed',
            'sync_error' => 'GitHub request failed: HTTP 404 Not Found',
            'sync_failed_at' => now()->subMinutes(3),
            'issues_sync_status' => 'failed',
            'issues_sync_error' => 'Issues sync failed',
            'issues_sync_failed_at' => now()->subMinutes(3),
            'prs_sync_status' => 'failed',
            'prs_sync_error' => 'PRs sync failed',
            'prs_sync_failed_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($user)
            ->get(route('repositories.show', $repo))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('repository.sync_error', 'GitHub request failed: HTTP 404 Not Found')
                    ->where('repository.sync_status', 'failed')
                    ->has('repository.sync_failed_at')
                    ->where('issuesSync.error', 'Issues sync failed')
                    ->has('issuesSync.failed_at')
                    ->where('pullRequestsSync.error', 'PRs sync failed')
                    ->has('pullRequestsSync.failed_at')
            );
    }

    public function test_show_hides_run_sync_button_for_non_owner(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-web',
        ]);

        $this->actingAs($other)
            ->get(route('repositories.show', $repo))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('canSync', false)
                    ->where('canDelete', false)
            );
    }

    public function test_store_links_via_a_full_github_url(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('repositories.store'), [
            'project_id' => $project->id,
            'repository' => 'https://github.com/nexus-org/nexus-api',
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('repositories', [
            'project_id' => $project->id,
            'full_name' => 'nexus-org/nexus-api',
            'sync_status' => 'pending',
        ]);

        $linked = Repository::query()
            ->where('full_name', 'nexus-org/nexus-api')
            ->firstOrFail();

        Queue::assertPushed(
            SyncGitHubRepositoryJob::class,
            fn (SyncGitHubRepositoryJob $job) => $job->repositoryId === $linked->id,
        );
    }

    public function test_store_links_via_owner_slash_name(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)->post(route('repositories.store'), [
            'project_id' => $project->id,
            'repository' => 'nexus-labs/edge-cache',
        ]);

        $this->assertDatabaseHas('repositories', [
            'project_id' => $project->id,
            'full_name' => 'nexus-labs/edge-cache',
        ]);

        Queue::assertPushed(SyncGitHubRepositoryJob::class);
    }

    public function test_store_rejects_garbage_input(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->post(route('repositories.store'), [
                'project_id' => $project->id,
                'repository' => 'not a repo',
            ])
            ->assertSessionHasErrors('repository');

        $this->assertSame(0, Repository::query()->count());
    }

    public function test_store_blocks_linking_an_already_taken_full_name(): void
    {
        $user = $this->verifiedUser();
        $a = Project::factory()->create(['owner_user_id' => $user->id]);
        $b = Project::factory()->create(['owner_user_id' => $user->id]);
        Repository::factory()->create([
            'project_id' => $a->id,
            'full_name' => 'nexus-org/nexus-web',
        ]);

        $response = $this->actingAs($user)
            ->from(route('projects.show', $b))
            ->post(route('repositories.store'), [
                'project_id' => $b->id,
                'repository' => 'nexus-org/nexus-web',
            ]);

        $response->assertSessionHasErrors('repository');
        // Still only one row exists with this full_name.
        $this->assertSame(
            1,
            Repository::query()
                ->where('full_name', 'nexus-org/nexus-web')
                ->count(),
        );
    }

    public function test_destroy_unlinks_for_project_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->delete(route('repositories.destroy', $repo))
            ->assertRedirect(route('projects.show', $project));

        $this->assertNull(Repository::query()->find($repo->id));
    }
}
