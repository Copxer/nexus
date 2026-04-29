<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\ImportRepositoryAction;
use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Enums\RepositorySyncStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportRepositoryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_pending_repository_and_dispatches_the_sync_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $repo = app(ImportRepositoryAction::class)->execute($project, 'octocat/hello-world');

        $this->assertSame('octocat/hello-world', $repo->full_name);
        $this->assertSame($project->id, $repo->project_id);
        $this->assertSame(RepositorySyncStatus::Pending, $repo->sync_status);

        Queue::assertPushed(
            SyncGitHubRepositoryJob::class,
            fn ($job) => $job->repositoryId === $repo->id,
        );
    }

    public function test_is_idempotent_on_re_import(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $first = app(ImportRepositoryAction::class)->execute($project, 'octocat/hello-world');
        $second = app(ImportRepositoryAction::class)->execute($project, 'octocat/hello-world');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            Repository::query()
                ->where('full_name', 'octocat/hello-world')
                ->count(),
        );

        // Both calls dispatched a sync — second one's a manual refresh path.
        Queue::assertPushed(SyncGitHubRepositoryJob::class, 2);
    }

    public function test_accepts_full_github_url_via_the_underlying_parser(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $repo = app(ImportRepositoryAction::class)->execute(
            $project,
            'https://github.com/nexus-org/nexus-api',
        );

        $this->assertSame('nexus-org/nexus-api', $repo->full_name);
        Queue::assertPushed(SyncGitHubRepositoryJob::class);
    }
}
