<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepositorySyncControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_dispatch_a_re_sync(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
        ]);

        $this->actingAs($owner)
            ->from(route('repositories.show', $repository->full_name))
            ->post(route('repositories.sync', $repository->full_name))
            ->assertRedirect(route('repositories.show', $repository->full_name))
            ->assertSessionHas('status');

        // Status flipped synchronously before the job ran — the next render
        // will paint the button disabled and stop double-click spam.
        $this->assertSame(
            'syncing',
            $repository->fresh()->sync_status->value,
        );

        Queue::assertPushed(
            SyncGitHubRepositoryJob::class,
            fn (SyncGitHubRepositoryJob $job) => $job->repositoryId === $repository->id,
        );
    }

    public function test_already_synced_repository_can_be_re_synced(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
            'sync_status' => 'synced',
        ]);

        $this->actingAs($owner)
            ->from(route('repositories.show', $repository->full_name))
            ->post(route('repositories.sync', $repository->full_name))
            ->assertRedirect(route('repositories.show', $repository->full_name));

        $this->assertSame('syncing', $repository->fresh()->sync_status->value);
        Queue::assertPushed(SyncGitHubRepositoryJob::class);
    }

    public function test_non_owner_is_forbidden(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
        ]);

        $this->actingAs($other)
            ->post(route('repositories.sync', $repository->full_name))
            ->assertForbidden();

        Queue::assertNotPushed(SyncGitHubRepositoryJob::class);
    }

    public function test_dispatch_clears_stale_sync_errors_across_all_four_flows(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
            'sync_status' => 'failed',
            'sync_error' => 'GitHub request failed: HTTP 500',
            'sync_failed_at' => now()->subMinutes(5),
            'issues_sync_status' => 'failed',
            'issues_sync_error' => 'Issues sync errored',
            'issues_sync_failed_at' => now()->subMinutes(5),
            'prs_sync_status' => 'failed',
            'prs_sync_error' => 'PRs sync errored',
            'prs_sync_failed_at' => now()->subMinutes(5),
            'workflow_runs_sync_status' => 'failed',
            'workflow_runs_sync_error' => 'Workflow runs sync errored',
            'workflow_runs_sync_failed_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($owner)
            ->from(route('repositories.show', $repository->full_name))
            ->post(route('repositories.sync', $repository->full_name))
            ->assertRedirect(route('repositories.show', $repository->full_name));

        // The metadata job re-runs all three child syncs on success, so
        // the controller clears all eight error columns up-front. Otherwise
        // the page would briefly show "Syncing…" at the top while stale
        // red error alerts persisted on the per-tab views.
        $repo = $repository->fresh();
        $this->assertNull($repo->sync_error);
        $this->assertNull($repo->sync_failed_at);
        $this->assertNull($repo->issues_sync_error);
        $this->assertNull($repo->issues_sync_failed_at);
        $this->assertNull($repo->prs_sync_error);
        $this->assertNull($repo->prs_sync_failed_at);
        $this->assertNull($repo->workflow_runs_sync_error);
        $this->assertNull($repo->workflow_runs_sync_failed_at);
    }

    public function test_unknown_repository_returns_404(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($owner)
            ->post(route('repositories.sync', 'nope/missing'))
            ->assertNotFound();
    }
}
