<?php

namespace Tests\Feature\Listeners;

use App\Domain\Analytics\Jobs\RecomputeProjectHealthScoreJob;
use App\Events\ActivityEventCreated;
use App\Listeners\RecomputeProjectHealthOnActivity;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecomputeProjectHealthOnActivityTest extends TestCase
{
    use RefreshDatabase;

    private function fire(ActivityEvent $activity): void
    {
        $listener = app(RecomputeProjectHealthOnActivity::class);
        $listener->handle(new ActivityEventCreated($activity));
    }

    public function test_alert_triggered_queues_a_recompute_for_the_alert_s_project(): void
    {
        Bus::fake();
        $project = Project::factory()->create();
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        $activity = ActivityEvent::factory()->create([
            'source' => 'alerts',
            'event_type' => 'alert.triggered',
            'metadata' => ['alert_id' => $alert->id],
            'repository_id' => null,
        ]);

        $this->fire($activity);

        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_website_down_queues_a_recompute(): void
    {
        Bus::fake();
        $project = Project::factory()->create();
        $website = Website::factory()->create(['project_id' => $project->id]);

        $activity = ActivityEvent::factory()->create([
            'source' => 'monitoring',
            'event_type' => 'website.down',
            'metadata' => ['website_id' => $website->id],
            'repository_id' => null,
        ]);

        $this->fire($activity);

        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_host_offline_queues_a_recompute(): void
    {
        Bus::fake();
        $project = Project::factory()->create();
        $host = Host::factory()->create(['project_id' => $project->id]);

        $activity = ActivityEvent::factory()->create([
            'source' => 'hosts',
            'event_type' => 'host.offline',
            'metadata' => ['host_id' => $host->id],
            'repository_id' => null,
        ]);

        $this->fire($activity);

        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_workflow_failed_uses_repository_path(): void
    {
        Bus::fake();
        $project = Project::factory()->create();
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $activity = ActivityEvent::factory()->create([
            'source' => 'github',
            'event_type' => 'workflow.failed',
            'repository_id' => $repo->id,
            'metadata' => [],
        ]);

        $this->fire($activity);

        Bus::assertDispatched(
            RecomputeProjectHealthScoreJob::class,
            fn (RecomputeProjectHealthScoreJob $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_non_score_moving_event_is_ignored(): void
    {
        Bus::fake();
        $project = Project::factory()->create();
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        // `release.published` exists per spec 020 but doesn't move
        // the score per §14.2 — the listener whitelist must skip it.
        $activity = ActivityEvent::factory()->create([
            'source' => 'github',
            'event_type' => 'release.published',
            'repository_id' => $repo->id,
            'metadata' => [],
        ]);

        $this->fire($activity);

        Bus::assertNothingDispatched();
    }

    public function test_orphan_alert_metadata_is_silently_skipped(): void
    {
        // alert.triggered with no resolvable alert_id (eg. the row
        // was deleted before this listener ran). The listener must
        // not throw and must not queue a job.
        Bus::fake();
        $activity = ActivityEvent::factory()->create([
            'source' => 'alerts',
            'event_type' => 'alert.triggered',
            'metadata' => ['alert_id' => 999_999],
            'repository_id' => null,
        ]);

        $this->fire($activity);

        Bus::assertNothingDispatched();
    }

    public function test_listener_is_auto_discovered_and_fires_on_real_event_dispatch(): void
    {
        // Sanity check that Laravel 11+'s listener auto-discovery
        // actually wires this listener up — without it the rest of
        // the file is unit-testing a class that nothing invokes.
        Bus::fake();
        $project = Project::factory()->create();
        $alert = Alert::factory()->create(['project_id' => $project->id]);
        $activity = ActivityEvent::factory()->create([
            'source' => 'alerts',
            'event_type' => 'alert.triggered',
            'metadata' => ['alert_id' => $alert->id],
            'repository_id' => null,
        ]);

        ActivityEventCreated::dispatch($activity);

        Bus::assertDispatched(RecomputeProjectHealthScoreJob::class);
    }
}
