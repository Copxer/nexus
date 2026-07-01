<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 040 — end-to-end: a website monitor running its probe
 * gets a failure result → website flips `up` → `down` + a
 * `website.down` alert opens. The next probe succeeds →
 * website flips back + alert auto-resolves.
 *
 * Pins the contract that:
 *   - `RecordWebsiteCheckAction` updates the parent website on
 *     status transitions.
 *   - The first failure (or up→down transition) promotes into
 *     an open alert.
 *   - The recovery transition auto-resolves the alert.
 *
 * Skips the scheduled `RunWebsiteProbeAction` because that does
 * real HTTP (network-less CI would flake). The recorder is the
 * actual contract between "probe ran" and "alert state moved".
 */
class WebsiteProbeAndAlertFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_probe_failure_opens_alert_then_recovery_resolves(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        // Website starts up — we want to exercise the transition
        // path (up → down opens an alert), not the cold-start path.
        $website = Website::factory()->create([
            'project_id' => $project->id,
            'name' => 'Spec 040 monitor',
            'url' => 'https://spec040.example.com',
            'status' => WebsiteStatus::Up->value,
            'last_checked_at' => now()->subMinutes(5),
            'last_success_at' => now()->subMinutes(5),
        ]);

        $recorder = app(RecordWebsiteCheckAction::class);

        // 1. Failed probe lands → website flips down + alert opens.
        $recorder->execute($website, new WebsiteProbeResult(
            status: WebsiteCheckStatus::Down,
            httpStatusCode: 503,
            responseTimeMs: 1234,
            errorMessage: null,
        ));

        $afterFailure = $website->fresh();
        $this->assertSame(WebsiteStatus::Down, $afterFailure->status);

        $alert = Alert::query()
            ->where('source', AlertSource::Website->value)
            ->where('type', 'website.down')
            ->where('source_id', $website->id)
            ->firstOrFail();
        $this->assertSame(AlertStatus::Open, $alert->status);

        // 2. Recovery probe lands → website flips up + alert resolves.
        $recorder->execute($website->fresh(), new WebsiteProbeResult(
            status: WebsiteCheckStatus::Up,
            httpStatusCode: 200,
            responseTimeMs: 95,
            errorMessage: null,
        ));

        $afterRecovery = $website->fresh();
        $this->assertSame(WebsiteStatus::Up, $afterRecovery->status);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
