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

    public function test_unknown_repository_returns_404(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($owner)
            ->post(route('repositories.sync', 'nope/missing'))
            ->assertNotFound();
    }
}
