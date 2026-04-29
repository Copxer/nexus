<?php

namespace Tests\Feature\GitHub;

use App\Enums\GithubIssueState;
use App\Enums\GithubPullRequestState;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WorkItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUserWithItems(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus/web',
        ]);

        $openIssue = GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'number' => 1,
            'title' => 'Open issue',
            'state' => GithubIssueState::Open->value,
            'updated_at_github' => now()->subHours(5),
        ]);
        $closedIssue = GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'number' => 2,
            'title' => 'Closed issue',
            'state' => GithubIssueState::Closed->value,
            'updated_at_github' => now()->subHours(10),
        ]);
        $openPr = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'number' => 3,
            'title' => 'Open PR',
            'state' => GithubPullRequestState::Open->value,
            'merged' => false,
            'updated_at_github' => now()->subHours(2),
        ]);
        $mergedPr = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'number' => 4,
            'title' => 'Merged PR',
            'state' => GithubPullRequestState::Merged->value,
            'merged' => true,
            'updated_at_github' => now()->subHours(1),
        ]);

        return compact('user', 'repository', 'openIssue', 'closedIssue', 'openPr', 'mergedPr');
    }

    public function test_index_renders_for_a_verified_user(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('WorkItems/Index')
                    ->has('items')
                    ->has('repositories', 1)
                    ->has('filters')
                    ->where('filters.kind', 'all')
                    ->where('filters.state', 'open')
            );
    }

    public function test_default_filter_returns_only_open_items(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 2) // open issue + open PR
                    ->where('items.0.state', 'open')
                    ->where('items.1.state', 'open')
            );
    }

    public function test_kind_filter_pulls_narrows_to_pull_requests(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['kind' => 'pulls', 'state' => 'all']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 2) // open + merged PRs
                    ->where('items.0.kind', 'pull_request')
                    ->where('items.1.kind', 'pull_request')
            );
    }

    public function test_kind_filter_issues_narrows_to_issues(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['kind' => 'issues', 'state' => 'all']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 2)
                    ->where('items.0.kind', 'issue')
                    ->where('items.1.kind', 'issue')
            );
    }

    public function test_state_filter_merged_returns_only_prs(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['state' => 'merged']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 1)
                    ->where('items.0.kind', 'pull_request')
                    ->where('items.0.state', 'merged')
            );
    }

    public function test_search_filters_by_title_substring(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['state' => 'all', 'q' => 'Merged']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 1)
                    ->where('items.0.title', 'Merged PR')
            );
    }

    public function test_search_filters_by_pound_number(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['state' => 'all', 'q' => '#3']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 1)
                    ->where('items.0.number', 3)
            );
    }

    public function test_user_does_not_see_items_from_other_users_repos(): void
    {
        $context = $this->setUpUserWithItems();

        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $otherProject = Project::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherRepo = Repository::factory()->create([
            'project_id' => $otherProject->id,
            'full_name' => 'other/repo',
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $otherRepo->id,
            'state' => GithubIssueState::Open->value,
        ]);

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['state' => 'all']))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 4) // only context user's 4 items
            );
    }

    public function test_repository_filter_scopes_to_one_repo(): void
    {
        $context = $this->setUpUserWithItems();
        $secondRepo = Repository::factory()->create([
            'project_id' => $context['repository']->project_id,
            'full_name' => 'nexus/api',
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $secondRepo->id,
            'state' => GithubIssueState::Open->value,
        ]);

        $this->actingAs($context['user'])
            ->get(route('work-items.index', [
                'state' => 'all',
                'repository_id' => $context['repository']->id,
            ]))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('items', 4) // only original repo's items
            );
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $context = $this->setUpUserWithItems();

        $this->actingAs($context['user'])
            ->get(route('work-items.index', ['kind' => 'garbage']))
            ->assertSessionHasErrors('kind');
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('work-items.index'))->assertRedirect(route('login'));
    }
}
