<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GithubRepositoryImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithConnection(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        return ['user' => $user, 'project' => $project];
    }

    public function test_index_renders_the_picker_with_github_repos(): void
    {
        $context = $this->ownerWithConnection();

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                [
                    'id' => 1,
                    'full_name' => 'octocat/hello-world',
                    'description' => 'Test repo',
                    'language' => 'Ruby',
                    'private' => false,
                    'stargazers_count' => 100,
                    'forks_count' => 10,
                    'pushed_at' => '2026-04-29T00:00:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world',
                ],
                [
                    'id' => 2,
                    'full_name' => 'octocat/spoon-knife',
                    'description' => 'Another test repo',
                    'language' => 'JavaScript',
                    'private' => true,
                    'stargazers_count' => 50,
                    'forks_count' => 5,
                    'pushed_at' => '2026-04-28T00:00:00Z',
                    'html_url' => 'https://github.com/octocat/spoon-knife',
                ],
            ]),
        ]);

        $this->actingAs($context['user'])
            ->get(route('projects.repositories.import.index', $context['project']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Repositories/Import')
                    ->where('project.slug', $context['project']->slug)
                    ->has('repositories', 2)
                    ->where('repositories.0.full_name', 'octocat/hello-world')
                    ->where('repositories.1.private', true)
            );
    }

    public function test_index_marks_already_linked_repos(): void
    {
        $context = $this->ownerWithConnection();
        Repository::factory()->create([
            'project_id' => $context['project']->id,
            'full_name' => 'octocat/hello-world',
        ]);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                [
                    'id' => 1,
                    'full_name' => 'octocat/hello-world',
                    'description' => 'Test repo',
                    'private' => false,
                    'stargazers_count' => 100,
                    'forks_count' => 10,
                    'pushed_at' => '2026-04-29T00:00:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world',
                ],
            ]),
        ]);

        $this->actingAs($context['user'])
            ->get(route('projects.repositories.import.index', $context['project']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('repositories.0.is_already_linked', true)
                    ->where('repositories.0.linked_to_this_project', true)
            );
    }

    public function test_index_redirects_to_settings_when_user_has_no_connection(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->get(route('projects.repositories.import.index', $project))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error');
    }

    public function test_index_is_403_for_non_owner(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($other)
            ->get(route('projects.repositories.import.index', $project))
            ->assertForbidden();
    }

    public function test_store_dispatches_the_sync_job_for_owner(): void
    {
        Queue::fake();
        $context = $this->ownerWithConnection();

        $response = $this->actingAs($context['user'])->post(
            route('projects.repositories.import.store', $context['project']),
            ['full_name' => 'octocat/hello-world'],
        );

        $response->assertRedirect(route('projects.show', $context['project']));
        $this->assertDatabaseHas('repositories', [
            'full_name' => 'octocat/hello-world',
            'project_id' => $context['project']->id,
        ]);
        Queue::assertPushed(SyncGitHubRepositoryJob::class);
    }

    public function test_store_rejects_repo_already_linked_to_another_project(): void
    {
        $context = $this->ownerWithConnection();
        $otherProject = Project::factory()->create([
            'owner_user_id' => $context['user']->id,
        ]);
        Repository::factory()->create([
            'project_id' => $otherProject->id,
            'full_name' => 'octocat/hello-world',
        ]);

        $this->actingAs($context['user'])
            ->from(route('projects.show', $context['project']))
            ->post(
                route('projects.repositories.import.store', $context['project']),
                ['full_name' => 'octocat/hello-world'],
            )
            ->assertRedirect(route('projects.show', $context['project']))
            ->assertSessionHasErrors('full_name');
    }

    public function test_store_rejects_garbage_full_name(): void
    {
        $context = $this->ownerWithConnection();

        $this->actingAs($context['user'])
            ->from(route('projects.show', $context['project']))
            ->post(
                route('projects.repositories.import.store', $context['project']),
                ['full_name' => 'not a repo at all'],
            )
            ->assertSessionHasErrors('full_name');
    }

    public function test_store_is_403_for_non_owner(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($other)
            ->post(
                route('projects.repositories.import.store', $project),
                ['full_name' => 'octocat/hello-world'],
            )
            ->assertForbidden();
    }
}
