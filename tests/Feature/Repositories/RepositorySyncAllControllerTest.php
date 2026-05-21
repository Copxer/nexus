<?php

namespace Tests\Feature\Repositories;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RepositorySyncAllControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_it_queues_a_sync_job_for_each_of_the_users_repositories(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repositories = Repository::factory()->count(3)->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)->post(route('repositories.sync-all'));

        $response->assertRedirect();
        foreach ($repositories as $repository) {
            Queue::assertPushed(
                SyncGitHubRepositoryJob::class,
                fn (SyncGitHubRepositoryJob $job) => $job->repositoryId === $repository->id,
            );
        }
        Queue::assertPushed(SyncGitHubRepositoryJob::class, 3);
    }

    public function test_it_does_not_sync_repositories_owned_by_other_users(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $otherProject = Project::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherRepository = Repository::factory()->create([
            'project_id' => $otherProject->id,
            'sync_status' => 'pending',
        ]);

        $this->actingAs($user)->post(route('repositories.sync-all'));

        Queue::assertNotPushed(SyncGitHubRepositoryJob::class);
        // The bulk status update is keyed to the user's own repos —
        // a sibling's row must be left exactly as it was.
        $this->assertSame('pending', $otherRepository->fresh()->sync_status->value);
    }

    public function test_it_flips_sync_status_to_syncing_and_clears_errors(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'sync_status' => 'failed',
            'sync_error' => 'GitHub request failed: HTTP 404 Not Found',
            'sync_failed_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($user)->post(route('repositories.sync-all'));

        $repository->refresh();
        $this->assertSame('syncing', $repository->sync_status->value);
        $this->assertNull($repository->sync_error);
        $this->assertNull($repository->sync_failed_at);
    }

    public function test_it_reports_when_there_is_nothing_to_sync(): void
    {
        Queue::fake();
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->post(route('repositories.sync-all'));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'No repositories to sync.');
        Queue::assertNotPushed(SyncGitHubRepositoryJob::class);
    }

    public function test_guests_cannot_trigger_a_global_sync(): void
    {
        Queue::fake();

        $this->post(route('repositories.sync-all'))->assertRedirect(route('login'));

        Queue::assertNotPushed(SyncGitHubRepositoryJob::class);
    }
}
