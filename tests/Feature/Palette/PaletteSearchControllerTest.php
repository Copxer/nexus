<?php

namespace Tests\Feature\Palette;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\GithubIssueState;
use App\Enums\GithubPullRequestState;
use App\Models\Alert;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Spec 043 — async server-side palette search endpoint. Scopes results
 * to the auth user's projects; system alerts (spec 038, no project)
 * are included since the operator still needs to reach them.
 */
class PaletteSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('*');
    }

    public function test_returns_matching_work_items_and_alerts_scoped_to_owner(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        GithubIssue::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Ship the palette',
            'number' => 42,
            'state' => GithubIssueState::Open->value,
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Unrelated ticket',
            'number' => 7,
            'state' => GithubIssueState::Open->value,
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'title' => 'palette perf regression',
            'type' => 'system.perf',
            'severity' => AlertSeverity::Warning->value,
            'source' => AlertSource::System->value,
            'status' => AlertStatus::Open->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('palette.search', ['q' => 'palette']));

        $response->assertOk();
        $body = $response->json();

        $this->assertCount(1, $body['workItems']);
        $this->assertSame('issue', $body['workItems'][0]['kind']);
        $this->assertStringContainsString('#42', $body['workItems'][0]['label']);

        $this->assertCount(1, $body['alerts']);
        $this->assertStringContainsString('palette perf regression', $body['alerts'][0]['label']);
    }

    public function test_excludes_work_items_and_alerts_from_other_users_projects(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerProject = Project::factory()->create(['owner_user_id' => $stranger->id]);
        $strangerRepo = Repository::factory()->create(['project_id' => $strangerProject->id]);

        GithubIssue::factory()->create([
            'repository_id' => $strangerRepo->id,
            'title' => 'secret palette work',
            'state' => GithubIssueState::Open->value,
        ]);
        Alert::factory()->create([
            'project_id' => $strangerProject->id,
            'title' => 'stranger palette alert',
            'type' => 'website.down',
            'source' => AlertSource::Website->value,
            'status' => AlertStatus::Open->value,
        ]);

        $response = $this->actingAs($owner)->getJson(route('palette.search', ['q' => 'palette']));

        $response->assertOk();
        $this->assertCount(0, $response->json('workItems'));
        $this->assertCount(0, $response->json('alerts'));
    }

    public function test_system_alerts_with_no_project_are_included(): void
    {
        $user = User::factory()->create();
        Alert::factory()->create([
            'project_id' => null,
            'title' => 'Queue backlog exceeded',
            'type' => 'queue.backlog',
            'severity' => AlertSeverity::Critical->value,
            'source' => AlertSource::System->value,
            'status' => AlertStatus::Open->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('palette.search', ['q' => 'backlog']));

        $response->assertOk();
        $alerts = $response->json('alerts');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Queue backlog exceeded', $alerts[0]['label']);
    }

    public function test_empty_and_single_char_queries_return_empty_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('palette.search', ['q' => '']))
            ->assertOk()
            ->assertExactJson(['workItems' => [], 'alerts' => []]);

        $this->actingAs($user)
            ->getJson(route('palette.search', ['q' => 'a']))
            ->assertOk()
            ->assertExactJson(['workItems' => [], 'alerts' => []]);
    }

    public function test_matches_pull_request_titles(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        GithubPullRequest::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'feat: palette entity search',
            'number' => 88,
            'state' => GithubPullRequestState::Open->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('palette.search', ['q' => 'palette']));

        $response->assertOk();
        $workItems = $response->json('workItems');
        $this->assertNotEmpty($workItems);
        $this->assertTrue(collect($workItems)->contains(fn ($row) => $row['kind'] === 'pull_request'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->getJson(route('palette.search', ['q' => 'anything']))
            ->assertUnauthorized();
    }

    public function test_endpoint_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)
                ->getJson(route('palette.search', ['q' => 'ping']))
                ->assertOk();
        }

        $this->actingAs($user)
            ->getJson(route('palette.search', ['q' => 'ping']))
            ->assertStatus(429);
    }
}
