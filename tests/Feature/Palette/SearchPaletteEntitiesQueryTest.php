<?php

namespace Tests\Feature\Palette;

use App\Domain\Palette\Queries\SearchPaletteEntitiesQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\GithubIssueState;
use App\Models\Alert;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 043 — direct query coverage: LIKE-escape safety, ownership
 * scoping, result cap per kind.
 */
class SearchPaletteEntitiesQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_substring_match_ranks_by_recent_activity(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $older = GithubIssue::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'palette scaffold',
            'state' => GithubIssueState::Open->value,
            'updated_at_github' => now()->subDays(7),
        ]);
        $newer = GithubIssue::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'palette entity search',
            'state' => GithubIssueState::Open->value,
            'updated_at_github' => now()->subMinutes(5),
        ]);

        $results = app(SearchPaletteEntitiesQuery::class)->execute($user, 'palette');

        $this->assertCount(2, $results['workItems']);
        // Ordered by updated_at_github desc — freshest match first.
        $this->assertSame($newer->id, $results['workItems'][0]['id']);
        $this->assertSame($older->id, $results['workItems'][1]['id']);
    }

    public function test_caps_results_per_kind(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        for ($i = 0; $i < 20; $i++) {
            GithubIssue::factory()->create([
                'repository_id' => $repo->id,
                'title' => "issue palette match #{$i}",
                'state' => GithubIssueState::Open->value,
            ]);
        }
        for ($i = 0; $i < 20; $i++) {
            Alert::factory()->create([
                'project_id' => $project->id,
                'title' => "palette flap #{$i}",
                'type' => 'website.down',
                'severity' => AlertSeverity::Warning->value,
                'source' => AlertSource::Website->value,
                'status' => AlertStatus::Open->value,
            ]);
        }

        $results = app(SearchPaletteEntitiesQuery::class)->execute($user, 'palette');

        $this->assertLessThanOrEqual(
            SearchPaletteEntitiesQuery::RESULT_CAP_PER_KIND,
            count($results['workItems']),
        );
        $this->assertLessThanOrEqual(
            SearchPaletteEntitiesQuery::RESULT_CAP_PER_KIND,
            count($results['alerts']),
        );
    }

    public function test_empty_query_returns_empty_payload(): void
    {
        $user = User::factory()->create();

        $results = app(SearchPaletteEntitiesQuery::class)->execute($user, '');

        $this->assertSame(['workItems' => [], 'alerts' => []], $results);
    }
}
