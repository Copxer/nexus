<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\Queries\RecentActivityForProjectQuery;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
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

    public function test_includes_monitoring_events_for_the_projects_websites(): void
    {
        $context = $this->setUpProject();
        $website = Website::factory()->create(['project_id' => $context['project']->id]);

        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'monitoring',
            'event_type' => 'website.down',
            'title' => 'site down',
            'metadata' => ['website_id' => $website->id],
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertCount(1, $rows);
        $this->assertSame('site down', $rows[0]['title']);
    }

    public function test_includes_host_events_for_the_projects_hosts(): void
    {
        $context = $this->setUpProject();
        $host = Host::factory()->create(['project_id' => $context['project']->id]);

        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'hosts',
            'event_type' => 'host.offline',
            'title' => 'host offline',
            'metadata' => ['host_id' => $host->id],
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertCount(1, $rows);
        $this->assertSame('host offline', $rows[0]['title']);
    }

    public function test_includes_alert_events_for_the_projects_alerts(): void
    {
        $context = $this->setUpProject();
        $alert = Alert::factory()->create(['project_id' => $context['project']->id]);

        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'alerts',
            'event_type' => 'alert.triggered',
            'title' => 'alert fired',
            'metadata' => ['alert_id' => $alert->id],
        ]);

        $rows = (new RecentActivityForProjectQuery)->handle($context['project']);

        $this->assertCount(1, $rows);
        $this->assertSame('alert fired', $rows[0]['title']);
    }

    public function test_does_not_leak_other_projects_non_repo_events(): void
    {
        $context = $this->setUpProject();
        $other = Project::factory()->create();
        $othersWebsite = Website::factory()->create(['project_id' => $other->id]);
        $othersHost = Host::factory()->create(['project_id' => $other->id]);
        $othersAlert = Alert::factory()->create(['project_id' => $other->id]);

        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'monitoring',
            'metadata' => ['website_id' => $othersWebsite->id],
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'hosts',
            'metadata' => ['host_id' => $othersHost->id],
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => null,
            'source' => 'alerts',
            'metadata' => ['alert_id' => $othersAlert->id],
        ]);

        $this->assertSame([], (new RecentActivityForProjectQuery)->handle($context['project']));
    }
}
