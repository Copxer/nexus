<?php

namespace Tests\Feature\Notifications;

use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Spec 042 — triggering an alert fans out via
 * `AlertNotificationService::dispatchFor` and enqueues one
 * `DispatchAlertNotificationJob` per matching preference. Preferences
 * whose `min_severity` filters the alert out (or whose `sources` list
 * doesn't include it) are silently skipped.
 */
class TriggerAlertActionNotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_triggering_an_alert_enqueues_one_job_per_matching_preference(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $slack = AlertNotificationChannel::factory()->slack()->for($user)->create();
        $webhook = AlertNotificationChannel::factory()->webhook()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($slack, 'channel')
            ->create(['min_severity' => AlertSeverity::Warning->value]);

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($webhook, 'channel')
            ->create(['min_severity' => AlertSeverity::Warning->value]);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        Bus::assertDispatchedTimes(DispatchAlertNotificationJob::class, 2);
    }

    public function test_preference_severity_filter_skips_below_threshold(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $slack = AlertNotificationChannel::factory()->slack()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($slack, 'channel')
            ->create(['min_severity' => AlertSeverity::Critical->value]);

        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Warning,
            'title' => 'Slow site',
        ]);

        Bus::assertNotDispatched(DispatchAlertNotificationJob::class);
    }

    public function test_preference_source_filter_narrows_dispatch(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $slack = AlertNotificationChannel::factory()->slack()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($slack, 'channel')
            ->create([
                'min_severity' => AlertSeverity::Warning->value,
                'sources' => [AlertSource::Docker->value],
            ]);

        // Website alert → source filter excludes it.
        app(TriggerAlertAction::class)->execute([
            'project_id' => $project->id,
            'source' => AlertSource::Website,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical,
            'title' => 'Site down',
        ]);

        Bus::assertNotDispatched(DispatchAlertNotificationJob::class);
    }
}
