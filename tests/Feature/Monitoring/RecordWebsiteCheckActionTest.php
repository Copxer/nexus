<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\ActivitySeverity;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Events\ActivityEventCreated;
use App\Events\WebsiteCheckRecorded;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecordWebsiteCheckActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): RecordWebsiteCheckAction
    {
        return new RecordWebsiteCheckAction(new CreateActivityEventAction);
    }

    private function makeWebsite(WebsiteStatus $status = WebsiteStatus::Pending): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create([
            'project_id' => $project->id,
            'status' => $status->value,
        ]);
    }

    public function test_persists_a_check_row_and_returns_it(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Up,
            httpStatusCode: 200,
            responseTimeMs: 142,
            errorMessage: null,
        );

        Event::fake([ActivityEventCreated::class]);

        $check = $this->action()->execute($website, $result);

        $this->assertInstanceOf(WebsiteCheck::class, $check);
        $this->assertSame(1, WebsiteCheck::query()->count());
        $this->assertSame(WebsiteCheckStatus::Up, $check->status);
        $this->assertSame(200, $check->http_status_code);
        $this->assertSame(142, $check->response_time_ms);
    }

    public function test_up_result_updates_status_and_last_success_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Up,
            httpStatusCode: 200,
            responseTimeMs: 100,
            errorMessage: null,
        );

        $this->action()->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Up, $website->status);
        $this->assertNotNull($website->last_checked_at);
        $this->assertNotNull($website->last_success_at);
        $this->assertNull($website->last_failure_at);
    }

    public function test_slow_result_counts_as_success_for_last_success_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Slow,
            httpStatusCode: 200,
            responseTimeMs: 4_200,
            errorMessage: null,
        );

        $this->action()->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Slow, $website->status);
        $this->assertNotNull($website->last_success_at);
        $this->assertNull($website->last_failure_at);
    }

    public function test_down_result_updates_status_and_last_failure_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Down,
            httpStatusCode: 503,
            responseTimeMs: 220,
            errorMessage: 'HTTP 503: Service Unavailable',
        );

        $this->action()->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Down, $website->status);
        $this->assertNotNull($website->last_checked_at);
        $this->assertNull($website->last_success_at);
        $this->assertNotNull($website->last_failure_at);
    }

    public function test_error_result_updates_status_and_last_failure_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Error,
            httpStatusCode: null,
            responseTimeMs: null,
            errorMessage: 'Connection timed out after 10000ms',
        );

        $this->action()->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Error, $website->status);
        $this->assertNotNull($website->last_failure_at);
        $this->assertNull($website->last_success_at);
    }

    public function test_subsequent_check_does_not_clobber_prior_success_timestamp(): void
    {
        // A successful run, then a failure: last_success_at must be
        // preserved (it's "last successful probe", not "last probe
        // when status was Up"). The same rule applies in reverse.
        $website = $this->makeWebsite();

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 100, null),
        );
        $firstSuccess = $website->fresh()->last_success_at;

        // Sleep so the timestamps differ enough to compare reliably.
        sleep(1);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Down, 500, 150, 'HTTP 500'),
        );

        $fresh = $website->fresh();
        $this->assertEquals($firstSuccess->toIso8601String(), $fresh->last_success_at->toIso8601String());
        $this->assertNotNull($fresh->last_failure_at);
        $this->assertSame(WebsiteStatus::Down, $fresh->status);
    }

    // ────────────────────────────────────────────────────────────────
    // Spec 024 — activity events on category transitions.
    // ────────────────────────────────────────────────────────────────

    public function test_pending_to_healthy_does_not_emit_an_activity_event(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Pending);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 100, null),
        );

        $this->assertSame(0, ActivityEvent::query()->count());
        Event::assertNotDispatched(ActivityEventCreated::class);
    }

    public function test_pending_to_failed_emits_incident_event(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Pending);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Down, 503, 220, 'HTTP 503: Service Unavailable'),
        );

        $this->assertSame(1, ActivityEvent::query()->count());
        $event = ActivityEvent::query()->first();
        $this->assertSame('website.down', $event->event_type);
        $this->assertSame(ActivitySeverity::Danger, $event->severity);
        $this->assertSame('monitoring', $event->source);
        $this->assertSame($website->id, $event->metadata['website_id']);
    }

    public function test_healthy_to_failed_emits_incident_event(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Up);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Error, null, null, 'Connection timed out'),
        );

        $event = ActivityEvent::query()->firstOrFail();
        $this->assertSame('website.down', $event->event_type);
        $this->assertSame(ActivitySeverity::Danger, $event->severity);
        $this->assertStringContainsString($website->name, $event->title);
    }

    public function test_failed_to_healthy_emits_recovery_event(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Down);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 95, null),
        );

        $event = ActivityEvent::query()->firstOrFail();
        $this->assertSame('website.up', $event->event_type);
        $this->assertSame(ActivitySeverity::Success, $event->severity);
        $this->assertSame('monitoring', $event->source);
    }

    public function test_steady_state_healthy_emits_nothing(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Up);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 100, null),
        );

        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_steady_state_failed_emits_nothing(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Down);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Down, 500, 200, 'HTTP 500'),
        );

        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_up_to_slow_emits_nothing_steady_state_within_healthy(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $website = $this->makeWebsite(WebsiteStatus::Up);

        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Slow, 200, 4_200, null),
        );

        $this->assertSame(0, ActivityEvent::query()->count());
    }

    // ────────────────────────────────────────────────────────────────
    // Spec 025 — WebsiteCheckRecorded broadcasts on every persisted check.
    // ────────────────────────────────────────────────────────────────

    public function test_dispatches_website_check_recorded_on_every_persisted_check(): void
    {
        Event::fake([WebsiteCheckRecorded::class]);
        $website = $this->makeWebsite(WebsiteStatus::Up);

        // Steady-state run (Up → Up). No transition activity, but the
        // realtime pulse must still fire.
        $check = $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 100, null),
        );

        Event::assertDispatched(
            WebsiteCheckRecorded::class,
            fn (WebsiteCheckRecorded $event) => $event->checkId === $check->id
                && $event->websiteId === $website->id
                && $event->ownerUserId === $website->project->owner_user_id,
        );
    }

    public function test_dispatches_website_check_recorded_on_transitions_too(): void
    {
        Event::fake([WebsiteCheckRecorded::class]);
        $website = $this->makeWebsite(WebsiteStatus::Up);

        // Healthy → Failed transition.
        $this->action()->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Down, 500, 200, 'HTTP 500'),
        );

        Event::assertDispatched(WebsiteCheckRecorded::class);
    }
}
