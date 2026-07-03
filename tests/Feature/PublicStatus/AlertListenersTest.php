<?php

namespace Tests\Feature\PublicStatus;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\PublicStatus\Jobs\NotifyStatusSubscribersJob;
use App\Domain\PublicStatus\Queries\GetPublicStatusPageQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spec 047 — listener behavior: enqueue notification jobs on alert
 * transitions AND flush the cached status snapshot.
 */
class AlertListenersTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_triggered_on_opted_in_project_enqueues_notification_job(): void
    {
        Bus::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        Bus::assertDispatched(
            NotifyStatusSubscribersJob::class,
            fn (NotifyStatusSubscribersJob $job) => $job->event === 'triggered',
        );
    }

    public function test_alert_triggered_on_disabled_project_does_not_enqueue(): void
    {
        Bus::fake();

        $project = Project::factory()->create(['public_status_enabled' => false]);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        Bus::assertNotDispatched(NotifyStatusSubscribersJob::class);
    }

    public function test_alert_resolved_enqueues_with_resolved_event(): void
    {
        Bus::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 42,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        app(ResolveAlertAction::class)->execute([
            'source' => AlertSource::Website,
            'source_id' => 42,
            'type' => 'website.down',
        ]);

        Bus::assertDispatched(
            NotifyStatusSubscribersJob::class,
            fn (NotifyStatusSubscribersJob $job) => $job->event === 'resolved',
        );
    }

    public function test_cache_is_forgotten_on_alert_transition(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $cacheKey = GetPublicStatusPageQuery::cacheKey($project->id);

        Cache::put($cacheKey, ['stale'], 60);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        $this->assertFalse(Cache::has($cacheKey));
    }
}
