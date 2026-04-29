<?php

namespace Tests\Feature\Repositories;

use App\Domain\Repositories\Actions\LinkRepositoryToProjectAction;
use App\Enums\RepositorySyncStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LinkRepositoryToProjectActionTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);

        return Project::factory()->create(['owner_user_id' => $owner->id]);
    }

    public function test_parses_full_https_url(): void
    {
        $action = new LinkRepositoryToProjectAction;

        $this->assertSame(
            ['nexus-org', 'nexus-web'],
            $action->parse('https://github.com/nexus-org/nexus-web'),
        );
    }

    public function test_parses_url_with_git_suffix_and_trailing_slash(): void
    {
        $action = new LinkRepositoryToProjectAction;

        $this->assertSame(
            ['nexus-org', 'nexus-api'],
            $action->parse('https://github.com/nexus-org/nexus-api.git/'),
        );
    }

    public function test_parses_bare_owner_slash_name(): void
    {
        $action = new LinkRepositoryToProjectAction;

        $this->assertSame(
            ['nexus-labs', 'edge-cache'],
            $action->parse('nexus-labs/edge-cache'),
        );
    }

    public function test_throws_on_garbage_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LinkRepositoryToProjectAction)->parse('not a repo at all');
    }

    public function test_execute_creates_a_pending_repository(): void
    {
        $project = $this->project();

        $repo = (new LinkRepositoryToProjectAction)
            ->execute($project, 'nexus-org/nexus-web');

        $this->assertSame('nexus-org/nexus-web', $repo->full_name);
        $this->assertSame($project->id, $repo->project_id);
        $this->assertSame(RepositorySyncStatus::Pending, $repo->sync_status);
        $this->assertNull($repo->last_synced_at);
    }

    public function test_execute_is_idempotent_on_same_project(): void
    {
        $project = $this->project();
        $action = new LinkRepositoryToProjectAction;

        $first = $action->execute($project, 'nexus-org/nexus-web');
        $second = $action->execute($project, 'nexus-org/nexus-web');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            Repository::query()
                ->where('full_name', 'nexus-org/nexus-web')
                ->count(),
        );
    }
}
