<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\Queries\RecentActivityForProjectQuery;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentActivityForProjectQueryTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);

        return ['project' => $project, 'repository' => $repository];
    }

    public function test_returns_only_events_from_the_projects_repositories(): void
    {
        $context = $this->setUpProject();

        // Sibling project — must NOT leak.
        $sibling = Project::factory()->create();
        $siblingRepo = Repository::factory()->create(['project_id' => $sibling->id]);

        ActivityEvent::factory()->create([
            'repository_id' => $context['repository']->id,
            'title' => 'mine',
            'occurred_at' => now(),
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => $siblingRepo->id,
            'title' => 'sibling',
            'occurred_at' => now(),
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertCount(1, $rows);
        $this->assertSame('mine', $rows[0]['title']);
    }

    public function test_orders_by_occurred_at_desc_with_id_tie_break(): void
    {
        $context = $this->setUpProject();

        ActivityEvent::factory()->create([
            'repository_id' => $context['repository']->id,
            'title' => 'oldest',
            'occurred_at' => now()->subHours(3),
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => $context['repository']->id,
            'title' => 'newest',
            'occurred_at' => now()->subMinutes(5),
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => $context['repository']->id,
            'title' => 'middle',
            'occurred_at' => now()->subHour(),
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertSame(['newest', 'middle', 'oldest'], array_column($rows, 'title'));
    }

    public function test_caps_at_tab_limit_by_default(): void
    {
        $context = $this->setUpProject();

        ActivityEvent::factory()
            ->count(RecentActivityForProjectQuery::TAB_LIMIT + 5)
            ->create([
                'repository_id' => $context['repository']->id,
                'occurred_at' => now(),
            ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertCount(RecentActivityForProjectQuery::TAB_LIMIT, $rows);
    }

    public function test_explicit_limit_overrides_default(): void
    {
        $context = $this->setUpProject();

        ActivityEvent::factory()->count(15)->create([
            'repository_id' => $context['repository']->id,
            'occurred_at' => now(),
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project'], 5);

        $this->assertCount(5, $rows);
    }
}
