<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Domain\Notifications\Services\AlertNotificationService;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Spec 042 — same-fingerprint alerts within DEDUPE_WINDOW_MINUTES
 * skip delivery. Fingerprint = `(source, source_id, type)`.
 */
class AlertNotificationDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_fingerprint_alert_within_window_lands_skipped_with_deduped(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $channel = AlertNotificationChannel::factory()->slack()->for($user)->create();

        AlertNotificationPreference::factory()
            ->for($user)
            ->for($channel, 'channel')
            ->create(['min_severity' => AlertSeverity::Warning->value]);

        // First alert lights up delivery normally.
        $firstAlert = Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 42,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
        ]);
        AlertDelivery::factory()
            ->for($firstAlert)
            ->for($channel, 'channel')
            ->sent()
            ->create();

        // Second alert with the same fingerprint arrives 30s later.
        $secondAlert = Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 42,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
        ]);

        app(AlertNotificationService::class)->dispatchFor($secondAlert);

        $skipped = AlertDelivery::query()
            ->where('alert_id', $secondAlert->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame(AlertDeliveryStatus::Skipped, $skipped->status);
        $this->assertSame('deduped', $skipped->error_message);
        Bus::assertNotDispatched(DispatchAlertNotificationJob::class);
    }
}
